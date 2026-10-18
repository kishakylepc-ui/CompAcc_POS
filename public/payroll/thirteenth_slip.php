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
| 13TH MONTH PAY SLIP
|--------------------------------------------------------------------------
|
| Printable slip of one 13th month payment: the basic pay earned in the
| year, the 13th month pay due (1/12), what was paid before and this
| payment. Uses the payslip paper style. Opened from Payroll -> 13th Month.
| Read-only.
|
*/

function slipSetting(PDO $pdo, string $key, string $default = ''): string
{
    $statement = $pdo->prepare("
        SELECT setting_value
        FROM settings
        WHERE setting_key = ?
        LIMIT 1
    ");

    $statement->execute([$key]);

    $value = $statement->fetchColumn();

    return $value === false || $value === null ? $default : (string) $value;
}

function slipName(?string $first, ?string $middle, ?string $last, ?string $suffix): string
{
    return trim(implode(' ', array_filter(
        [$first, $middle, $last, $suffix],
        static fn ($part) => trim((string) $part) !== ''
    )));
}

function slipDate(?string $date): string
{
    $parsed = $date ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;

    return $parsed ? $parsed->format('M d, Y') : '—';
}

/* UTC from the database -> Asia/Manila. */
function slipTimestamp(?string $utcDate): string
{
    if ($utcDate === null || $utcDate === '') {
        return '—';
    }

    $date = new DateTime($utcDate, new DateTimeZone('UTC'));
    $date->setTimezone(new DateTimeZone('Asia/Manila'));

    return $date->format('M d, Y · g:i A');
}

function slipMoney(float $amount): string
{
    return '₱' . number_format($amount, 2);
}


$paymentId = (int) ($_GET['id'] ?? 0);

$payment = false;

if (payrollThirteenthReady($pdo)) {
    $statement = $pdo->prepare("
        SELECT
            t.*,
            e.employee_code,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix,
            e.position,
            u.first_name AS processor_first_name,
            u.last_name AS processor_last_name,
            v.first_name AS voider_first_name,
            v.last_name AS voider_last_name
        FROM thirteenth_month_payments t
        INNER JOIN employees e
            ON e.id = t.employee_id
        LEFT JOIN users u
            ON u.id = t.processed_by
        LEFT JOIN users v
            ON v.id = t.voided_by
        WHERE t.id = ?
        LIMIT 1
    ");

    $statement->execute([$paymentId]);
    $payment = $statement->fetch();
}

if (!$payment) {
    header('Location: /payroll/thirteenth.php');
    exit;
}

$isVoided = $payment['status'] === 'Voided';
$amount = (float) $payment['amount'];
$due = (float) $payment['due_amount'];
$paidBefore = (float) $payment['paid_before'];
$leftAfter = max(0.0, round($due - $paidBefore - $amount, 2));

$employeeName = slipName($payment['first_name'], $payment['middle_name'], $payment['last_name'], $payment['suffix']);
$processorName = slipName($payment['processor_first_name'], null, $payment['processor_last_name'], null) ?: '—';
$voiderName = slipName($payment['voider_first_name'], null, $payment['voider_last_name'], null);
$slipCode = '13M-' . str_pad((string) $paymentId, 5, '0', STR_PAD_LEFT);

$businessName = trim(slipSetting($pdo, 'business_name', 'Underground Apparel')) ?: 'Underground Apparel';
$businessAddress = trim(slipSetting($pdo, 'business_address'));
$businessContact = trim(slipSetting($pdo, 'business_contact'));

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
        13th Month Pay - <?= htmlspecialchars($slipCode) ?>
    </title>

    <!-- Same light / dark choice as the other pages. The paper and
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
        href="/assets/css/payslip.css?v=20261018-3"
    >

    <link
        rel="stylesheet"
        href="/assets/css/theme-light.css?v=20261018-13m2"
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
            href="/payroll/thirteenth.php?year=<?= (int) $payment['year'] ?>"
            class="payslip-action secondary"
        >
            <span class="material-symbols-rounded">arrow_back</span>
            Back to 13th Month
        </a>

        <button
            type="button"
            class="payslip-action primary"
            onclick="window.print()"
        >
            <span class="material-symbols-rounded">print</span>
            Print Slip
        </button>

    </div>


    <article class="payslip-paper<?= $isVoided ? ' voided' : '' ?>">

        <?php if ($isVoided): ?>
            <div class="payslip-void-stamp" aria-label="Voided payment">VOIDED</div>
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
                <span>13TH MONTH PAY</span>
                <strong><?= htmlspecialchars($slipCode) ?></strong>
            </div>

        </header>


        <section class="payslip-details">

            <div>
                <span>Employee</span>
                <strong><?= htmlspecialchars($employeeName) ?></strong>
                <small><?= htmlspecialchars($payment['employee_code'] . ' · ' . $payment['position']) ?></small>
            </div>

            <div>
                <span>For the Year</span>
                <strong><?= (int) $payment['year'] ?></strong>
            </div>

            <div>
                <span>Paid On</span>
                <strong><?= htmlspecialchars(slipDate($payment['paid_on'])) ?></strong>
                <small>recorded by <?= htmlspecialchars($processorName) ?></small>
            </div>

        </section>


        <section class="payslip-section">

            <h2>Computation</h2>

            <div class="payslip-row">
                <span>
                    Basic pay earned in <?= (int) $payment['year'] ?>
                    <small>processed payroll up to this payment</small>
                </span>
                <strong><?= slipMoney((float) $payment['basic_pay_total']) ?></strong>
            </div>

            <div class="payslip-row">
                <span>
                    13th month pay
                    <small>basic pay ÷ 12</small>
                </span>
                <strong><?= slipMoney($due) ?></strong>
            </div>

            <?php if ($paidBefore > 0): ?>
                <div class="payslip-row">
                    <span>Paid before</span>
                    <strong>-<?= slipMoney($paidBefore) ?></strong>
                </div>
            <?php endif; ?>

            <div class="payslip-row total">
                <span>Still to pay after this payment</span>
                <strong><?= slipMoney($leftAfter) ?></strong>
            </div>

        </section>


        <section class="payslip-net">
            <span>AMOUNT PAID</span>
            <strong><?= slipMoney($amount) ?></strong>
        </section>


        <section class="payslip-section payslip-employer">
            <p class="payslip-employer-note">
                Tax-free up to ₱<?= number_format(PAYROLL_THIRTEENTH_TAX_FREE) ?>
                (13th month pay and other benefits in the year); no SSS, PhilHealth or
                Pag-IBIG is deducted from it.
            </p>
        </section>


        <?php if (!empty($payment['notes'])): ?>
            <section class="payslip-notes">
                <span>Notes</span>
                <p><?= nl2br(htmlspecialchars((string) $payment['notes'])) ?></p>
            </section>
        <?php endif; ?>


        <?php if ($isVoided): ?>
            <section class="payslip-notes voided">
                <span>Voided</span>
                <p>
                    <?= htmlspecialchars(slipTimestamp($payment['voided_at'] ?? null)) ?>
                    <?= $voiderName !== '' ? '· ' . htmlspecialchars($voiderName) : '' ?>
                    — <?= htmlspecialchars((string) ($payment['void_reason'] ?? '')) ?>
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
