<?php

declare(strict_types=1);

require_once __DIR__
    . '/../../app/middleware/role.php';

requireRole([
    'Admin',
    'Manager'
]);

require_once __DIR__
    . '/../../app/config/database.php';

require_once __DIR__
    . '/../../app/helpers/payroll.php';


/*
|--------------------------------------------------------------------------
| PAYSLIP
|--------------------------------------------------------------------------
|
| Printable breakdown of one payroll: hours x rate = gross, every deduction
| line (SSS, PhilHealth, Pag-IBIG, withholding tax, other) and the net pay.
| Opened from Payroll -> History. Read-only.
|
*/

function payslipSetting(PDO $pdo, string $key, string $default = ''): string
{
    $statement = $pdo->prepare("
        SELECT setting_value
        FROM settings
        WHERE setting_key = ?
        LIMIT 1
    ");

    $statement->execute([$key]);

    $value = $statement->fetchColumn();

    return $value === false || $value === null
        ? $default
        : (string) $value;
}

function payslipName(?string $first, ?string $middle, ?string $last, ?string $suffix): string
{
    return trim(implode(' ', array_filter(
        [$first, $middle, $last, $suffix],
        static fn ($part) => trim((string) $part) !== ''
    )));
}

function payslipDate(?string $date): string
{
    $parsed = $date ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;

    return $parsed ? $parsed->format('M d, Y') : '—';
}

/* UTC from the database -> Asia/Manila. */
function payslipTimestamp(?string $utcDate): string
{
    if ($utcDate === null || $utcDate === '') {
        return '—';
    }

    $date = new DateTime($utcDate, new DateTimeZone('UTC'));
    $date->setTimezone(new DateTimeZone('Asia/Manila'));

    return $date->format('M d, Y · g:i A');
}

function payslipMoney(float $amount): string
{
    return '₱' . number_format($amount, 2);
}


$payrollId = (int) ($_GET['id'] ?? 0);

$payrollStatement = $pdo->prepare("
    SELECT
        p.*,
        e.employee_code,
        e.first_name,
        e.middle_name,
        e.last_name,
        e.suffix,
        e.position,
        u.first_name AS processor_first_name,
        u.last_name AS processor_last_name
    FROM payroll p
    INNER JOIN employees e
        ON e.id = p.employee_id
    LEFT JOIN users u
        ON u.id = p.processed_by
    WHERE p.id = ?
    LIMIT 1
");

$payrollStatement->execute([$payrollId]);

$payroll = $payrollStatement->fetch();

if (!$payroll) {
    header('Location: /payroll/?view=history');
    exit;
}


$isVoided = ($payroll['status'] ?? 'Processed') === 'Voided';
$voiderName = '';

if ($isVoided && !empty($payroll['voided_by'])) {
    $voider = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
    $voider->execute([(int) $payroll['voided_by']]);
    $voiderRow = $voider->fetch();
    $voiderName = $voiderRow ? payslipName($voiderRow['first_name'], null, $voiderRow['last_name'], null) : '';
}


/* Deduction lines (after the deductions migration); older payroll: one line. */
$lines = [];

if (payrollDeductionsReady($pdo)) {
    $lineStatement = $pdo->prepare("
        SELECT deduction_type, label, amount
        FROM payroll_deductions
        WHERE payroll_id = ?
          AND amount > 0
        ORDER BY sort_order ASC, id ASC
    ");

    $lineStatement->execute([$payrollId]);

    $lines = $lineStatement->fetchAll();
}

if ($lines === [] && (float) $payroll['deductions'] > 0) {
    $lines[] = [
        'deduction_type' => 'Other',
        'label' => 'Deductions',
        'amount' => $payroll['deductions']
    ];
}


$hours = (float) $payroll['hours_worked'];
$gross = (float) $payroll['gross_pay'];
$rate = $hours > 0 ? round($gross / $hours, 2) : 0.0;

$employeeName = payslipName($payroll['first_name'], $payroll['middle_name'], $payroll['last_name'], $payroll['suffix']);
$processorName = payslipName($payroll['processor_first_name'], null, $payroll['processor_last_name'], null) ?: '—';
$payslipCode = 'PAY-' . str_pad((string) $payrollId, 5, '0', STR_PAD_LEFT);

$businessName = trim(payslipSetting($pdo, 'business_name', 'Underground Apparel')) ?: 'Underground Apparel';
$businessAddress = trim(payslipSetting($pdo, 'business_address'));
$businessContact = trim(payslipSetting($pdo, 'business_contact'));

?>
<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Payslip - <?= htmlspecialchars($payslipCode) ?>
    </title>

    <!-- Same light / dark choice as the other pages. The payslip paper and
         printouts keep their colors either way. -->
    <script>
        try {
            if (window.localStorage.getItem('ua-theme') === 'light') {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        } catch (error) {
            /* Storage blocked: stay dark. */
        }
    </script>

    <link
        rel="stylesheet"
        href="/assets/css/payslip.css?v=20261018-1"
    >

    <link
        rel="stylesheet"
        href="/assets/css/theme-light.css?v=20261018-2"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,400,0,0"
        rel="stylesheet"
    >

</head>

<body>

<div class="payslip-page">

    <div class="payslip-actions no-print">

        <a
            href="/payroll/?view=history"
            class="payslip-action secondary"
        >
            <span class="material-symbols-rounded">arrow_back</span>
            Back to Payroll
        </a>

        <button
            type="button"
            class="payslip-action primary"
            onclick="window.print()"
        >
            <span class="material-symbols-rounded">print</span>
            Print Payslip
        </button>

    </div>


    <article class="payslip-paper<?= $isVoided ? ' voided' : '' ?>">

        <?php if ($isVoided): ?>
            <div class="payslip-void-stamp" aria-label="Voided payroll">VOIDED</div>
        <?php endif; ?>

        <header class="payslip-header">

            <div>
                <h1><?= htmlspecialchars($businessName) ?></h1>

                <?php if ($businessAddress !== ''): ?>
                    <p><?= htmlspecialchars($businessAddress) ?></p>
                <?php endif; ?>

                <?php if ($businessContact !== ''): ?>
                    <p><?= htmlspecialchars($businessContact) ?></p>
                <?php endif; ?>
            </div>

            <div class="payslip-title">
                <span>PAYSLIP</span>
                <strong><?= htmlspecialchars($payslipCode) ?></strong>
            </div>

        </header>


        <section class="payslip-details">

            <div>
                <span>Employee</span>
                <strong><?= htmlspecialchars($employeeName) ?></strong>
                <small><?= htmlspecialchars($payroll['employee_code'] . ' · ' . $payroll['position']) ?></small>
            </div>

            <div>
                <span>Pay Period</span>
                <strong><?= htmlspecialchars(payslipDate($payroll['period_start'])) ?> – <?= htmlspecialchars(payslipDate($payroll['period_end'])) ?></strong>
            </div>

            <div>
                <span>Processed</span>
                <strong><?= htmlspecialchars(payslipTimestamp($payroll['created_at'])) ?></strong>
                <small>by <?= htmlspecialchars($processorName) ?></small>
            </div>

        </section>


        <?php if (($payroll['scheduled_hours'] ?? null) !== null): ?>

            <!-- Attendance: what the hours were based on (work schedule). -->
            <section class="payslip-section">

                <h2>Attendance</h2>

                <div class="payslip-row">
                    <span>Scheduled hours</span>
                    <strong><?= number_format((float) $payroll['scheduled_hours'], 2) ?> hrs</strong>
                </div>

                <div class="payslip-row">
                    <span>Days absent</span>
                    <strong><?= htmlspecialchars(rtrim(rtrim(number_format((float) $payroll['days_absent'], 2, '.', ''), '0'), '.')) ?> <?= (float) $payroll['days_absent'] == 1.0 ? 'day' : 'days' ?></strong>
                </div>

                <div class="payslip-row">
                    <span>Late / undertime</span>
                    <strong><?= number_format((float) $payroll['hours_late'], 2) ?> hrs</strong>
                </div>

                <div class="payslip-row total">
                    <span>Hours paid</span>
                    <strong><?= number_format($hours, 2) ?> hrs</strong>
                </div>

            </section>

        <?php endif; ?>


        <section class="payslip-section">

            <h2>Earnings</h2>

            <div class="payslip-row">
                <span>
                    Hours worked
                    <small><?= number_format($hours, 2) ?> hrs × <?= payslipMoney($rate) ?></small>
                </span>
                <strong><?= payslipMoney($gross) ?></strong>
            </div>

            <div class="payslip-row total">
                <span>Gross Pay</span>
                <strong><?= payslipMoney($gross) ?></strong>
            </div>

        </section>


        <section class="payslip-section">

            <h2>Deductions</h2>

            <?php if ($lines === []): ?>

                <div class="payslip-row muted">
                    <span>No deductions</span>
                    <strong><?= payslipMoney(0) ?></strong>
                </div>

            <?php else: ?>

                <?php foreach ($lines as $line): ?>
                    <div class="payslip-row">
                        <span><?= htmlspecialchars((string) $line['label']) ?></span>
                        <strong>-<?= payslipMoney((float) $line['amount']) ?></strong>
                    </div>
                <?php endforeach; ?>

            <?php endif; ?>

            <div class="payslip-row total">
                <span>Total Deductions</span>
                <strong>-<?= payslipMoney((float) $payroll['deductions']) ?></strong>
            </div>

        </section>


        <section class="payslip-net">
            <span>NET PAY</span>
            <strong><?= payslipMoney((float) $payroll['net_pay']) ?></strong>
        </section>


        <?php if (!empty($payroll['deduction_notes'])): ?>
            <section class="payslip-notes">
                <span>Notes</span>
                <p><?= nl2br(htmlspecialchars((string) $payroll['deduction_notes'])) ?></p>
            </section>
        <?php endif; ?>


        <?php if ($isVoided): ?>
            <section class="payslip-notes voided">
                <span>Voided</span>
                <p>
                    <?= htmlspecialchars(payslipTimestamp($payroll['voided_at'] ?? null)) ?>
                    <?= $voiderName !== '' ? '· ' . htmlspecialchars($voiderName) : '' ?>
                    — <?= htmlspecialchars((string) ($payroll['void_reason'] ?? '')) ?>
                </p>
            </section>
        <?php endif; ?>


        <footer class="payslip-footer">

            <div class="payslip-sign">
                <span></span>
                <small>Received by (employee signature)</small>
            </div>

            <div class="payslip-sign">
                <span></span>
                <small>Released by</small>
            </div>

        </footer>

    </article>

</div>

</body>

</html>
