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

date_default_timezone_set('Asia/Manila');

$pageTitle = 'Payroll';
$currentPage = 'payroll';


/*
|--------------------------------------------------------------------------
| 13TH MONTH PAY
|--------------------------------------------------------------------------
|
| Payroll -> 13th Month. For each employee: the basic pay earned in the
| year (processed payroll, by period end), the 13th month pay due (1/12 of
| it), what was already paid and what is still to pay. A payment can be the
| whole amount or a part (e.g. half in June); each one is saved in
| thirteenth_month_payments with a printable slip. An Admin voids a payment
| made by mistake (never deleted). Rules: app/helpers/payroll.php.
|
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$thirteenthReady = payrollThirteenthReady($pdo);
$canVoidThirteenth = ($_SESSION['role'] ?? '') === 'Admin';


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function thirteenthRedirect(int $year = 0): never
{
    header('Location: /payroll/thirteenth.php' . ($year > 0 ? '?year=' . $year : ''));
    exit;
}

function thirteenthFlash(string $type, string $message): void
{
    $_SESSION[$type === 'success' ? 'thirteenth_success' : 'thirteenth_error'] = $message;
}

function thirteenthName(array $row, string $prefix = ''): string
{
    return trim(implode(' ', array_filter(
        [
            $row[$prefix . 'first_name'] ?? '',
            $row[$prefix . 'middle_name'] ?? '',
            $row[$prefix . 'last_name'] ?? '',
            $row[$prefix . 'suffix'] ?? ''
        ],
        static fn ($part) => trim((string) $part) !== ''
    )));
}

/* mb_strlen only when the mbstring extension is enabled (it is not on every PC). */
function thirteenthTextLength(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

function thirteenthMoney(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function thirteenthCode(int $paymentId): string
{
    return '13M-' . str_pad((string) $paymentId, 5, '0', STR_PAD_LEFT);
}

function thirteenthDate(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed ? $parsed->format('M d, Y') : $date;
}

function thirteenthLog(PDO $pdo, string $action, int $paymentId, string $details): void
{
    $pdo->prepare("
        INSERT INTO system_logs (
            user_id,
            action,
            module,
            record_type,
            record_id,
            details
        )
        VALUES (?, ?, 'Payroll', '13th Month Pay', ?, ?)
    ")->execute([
        $_SESSION['user_id'] ?? null,
        $action,
        $paymentId,
        $details
    ]);
}


/*
|--------------------------------------------------------------------------
| HANDLE POST REQUESTS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = trim((string) ($_POST['action'] ?? ''));
    $returnYear = (int) ($_POST['year'] ?? 0);
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if (
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $submittedToken)
    ) {
        thirteenthFlash('error', 'Your session expired. Refresh the page and try again.');
        thirteenthRedirect($returnYear);
    }

    if (!$thirteenthReady) {
        thirteenthFlash('error', '13th month pay needs a database update: run tools\\migrate_thirteenth_month.php first.');
        thirteenthRedirect();
    }


    /* RECORD A PAYMENT (all that is left, or a part of it) */

    if ($action === 'pay_thirteenth') {

        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $year = (int) ($_POST['year'] ?? 0);
        $amountInput = trim((string) ($_POST['amount'] ?? ''));
        $paidOn = trim((string) ($_POST['paid_on'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        $currentYear = (int) date('Y');
        $today = date('Y-m-d');
        $paidOnDate = DateTimeImmutable::createFromFormat('!Y-m-d', $paidOn);

        $error = null;

        if ($employeeId <= 0) {
            $error = 'Choose the employee.';
        } elseif ($year < 2000 || $year > $currentYear) {
            $error = 'Choose a valid year.';
        } elseif ($paidOnDate === false || $paidOnDate->format('Y-m-d') !== $paidOn) {
            $error = 'Enter the date it was paid.';
        } elseif ($paidOn > $today) {
            $error = 'The payment date cannot be in the future.';
        } elseif ($paidOn < $year . '-01-01') {
            $error = 'The payment date cannot be before ' . $year . '.';
        } elseif (thirteenthTextLength($notes) > 255) {
            $error = 'Keep the notes under 255 characters.';
        } elseif ($amountInput !== '' && (!is_numeric($amountInput) || (float) $amountInput <= 0)) {
            $error = 'Enter an amount greater than zero.';
        }

        if ($error !== null) {
            thirteenthFlash('error', $error);
            thirteenthRedirect($year);
        }

        try {
            $pdo->beginTransaction();

            $row = payrollThirteenthSummary($pdo, $year, $employeeId)[0] ?? null;

            if ($row === null) {
                throw new RuntimeException('This employee earned nothing in ' . $year . ', so there is no 13th month pay to record.');
            }

            $employeeName = thirteenthName($row);

            if ($row['left'] <= 0) {
                throw new RuntimeException('The ' . $year . ' 13th month pay of ' . $employeeName . ' is already paid in full.');
            }

            $amount = $amountInput === ''
                ? $row['left']
                : round((float) $amountInput, 2);

            if ($amount > $row['left'] + 0.004) {
                throw new RuntimeException(
                    'You can pay at most ' . thirteenthMoney($row['left']) . ' (what is still to pay for ' . $year . ').'
                );
            }

            $pdo->prepare("
                INSERT INTO thirteenth_month_payments (
                    employee_id,
                    year,
                    basic_pay_total,
                    due_amount,
                    paid_before,
                    amount,
                    paid_on,
                    notes,
                    processed_by
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $employeeId,
                $year,
                $row['basic_pay'],
                $row['due'],
                $row['paid'],
                $amount,
                $paidOn,
                $notes !== '' ? $notes : null,
                (int) $_SESSION['user_id']
            ]);

            $paymentId = (int) $pdo->lastInsertId();
            $stillLeft = max(0.0, round($row['left'] - $amount, 2));

            thirteenthLog(
                $pdo,
                'PAY_THIRTEENTH_MONTH',
                $paymentId,
                'Paid ' . thirteenthCode($paymentId) . ' to ' . $row['employee_code'] . ' - ' . $employeeName
                    . ': ' . $year . ' 13th month pay PHP ' . number_format($amount, 2, '.', '')
                    . ' on ' . $paidOn
                    . ' (basic pay PHP ' . number_format($row['basic_pay'], 2, '.', '')
                    . ', due PHP ' . number_format($row['due'], 2, '.', '')
                    . ', paid before PHP ' . number_format($row['paid'], 2, '.', '')
                    . ', still to pay PHP ' . number_format($stillLeft, 2, '.', '') . ')'
            );

            $pdo->commit();

            thirteenthFlash(
                'success',
                '13th month pay of ' . thirteenthMoney($amount) . ' recorded for ' . $employeeName
                    . ' (' . thirteenthCode($paymentId) . '). '
                    . ($stillLeft > 0
                        ? thirteenthMoney($stillLeft) . ' is still to pay for ' . $year . '.'
                        : 'The ' . $year . ' 13th month pay is now paid in full.')
            );

        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            thirteenthFlash('error', 'Unable to record the payment: ' . $error->getMessage());
        }

        thirteenthRedirect($year);
    }


    /* VOID A PAYMENT MADE BY MISTAKE (Admin only) */

    if ($action === 'void_thirteenth') {

        $paymentId = (int) ($_POST['payment_id'] ?? 0);
        $reason = trim((string) ($_POST['void_reason'] ?? ''));

        if (!$canVoidThirteenth) {
            thirteenthFlash('error', 'Only an Admin can void a 13th month payment.');
            thirteenthRedirect($returnYear);
        }

        if ($paymentId <= 0 || $reason === '' || thirteenthTextLength($reason) > 255) {
            thirteenthFlash('error', 'Enter a reason for voiding (up to 255 characters).');
            thirteenthRedirect($returnYear);
        }

        try {
            $pdo->beginTransaction();

            $payment = $pdo->prepare("
                SELECT
                    t.id,
                    t.year,
                    t.amount,
                    t.status,
                    e.employee_code,
                    e.first_name,
                    e.middle_name,
                    e.last_name,
                    e.suffix
                FROM thirteenth_month_payments t
                INNER JOIN employees e
                    ON e.id = t.employee_id
                WHERE t.id = ?
                LIMIT 1
            ");

            $payment->execute([$paymentId]);
            $record = $payment->fetch();

            if (!$record) {
                throw new RuntimeException('Payment not found.');
            }

            $returnYear = (int) $record['year'];

            $void = $pdo->prepare("
                UPDATE thirteenth_month_payments
                SET status = 'Voided',
                    voided_by = ?,
                    voided_at = CURRENT_TIMESTAMP,
                    void_reason = ?
                WHERE id = ?
                  AND status = 'Paid'
            ");

            $void->execute([(int) $_SESSION['user_id'], $reason, $paymentId]);

            if ($void->rowCount() !== 1) {
                throw new RuntimeException('This payment is already voided.');
            }

            thirteenthLog(
                $pdo,
                'VOID_THIRTEENTH_MONTH',
                $paymentId,
                'Voided ' . thirteenthCode($paymentId) . ' for ' . $record['employee_code'] . ' - ' . thirteenthName($record)
                    . ' (' . $record['year'] . ' 13th month pay PHP ' . number_format((float) $record['amount'], 2, '.', '')
                    . '). Reason: ' . $reason
            );

            $pdo->commit();

            thirteenthFlash(
                'success',
                thirteenthCode($paymentId) . ' was voided. It no longer counts, and its amount is "still to pay" again.'
            );

        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            thirteenthFlash('error', 'Unable to void the payment: ' . $error->getMessage());
        }

        thirteenthRedirect($returnYear);
    }


    thirteenthFlash('error', 'Unknown action.');
    thirteenthRedirect($returnYear);
}


/*
|--------------------------------------------------------------------------
| FLASH MESSAGES
|--------------------------------------------------------------------------
*/

$thirteenthSuccess = $_SESSION['thirteenth_success'] ?? '';
$thirteenthError = $_SESSION['thirteenth_error'] ?? '';

unset($_SESSION['thirteenth_success'], $_SESSION['thirteenth_error']);


/*
|--------------------------------------------------------------------------
| YEAR, EMPLOYEES AND PAYMENTS
|--------------------------------------------------------------------------
*/

$today = date('Y-m-d');
$currentYear = (int) date('Y');

$selectedYear = (int) ($_GET['year'] ?? $currentYear);

if ($selectedYear < 2000 || $selectedYear > $currentYear) {
    $selectedYear = $currentYear;
}

/* Years that have payroll or payments, plus this year (newest first). */
$years = [$currentYear => true];

foreach ($pdo->query("SELECT DISTINCT substr(period_end, 1, 4) FROM payroll")->fetchAll(PDO::FETCH_COLUMN) as $payrollYear) {
    if ((int) $payrollYear >= 2000 && (int) $payrollYear <= $currentYear) {
        $years[(int) $payrollYear] = true;
    }
}

$years[$selectedYear] = true;
krsort($years);

$thirteenthRows = [];
$thirteenthPayments = [];

if ($thirteenthReady) {

    $thirteenthRows = payrollThirteenthSummary($pdo, $selectedYear);

    $paymentStatement = $pdo->prepare("
        SELECT
            t.*,
            e.employee_code,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix,
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
        WHERE t.year = ?
        ORDER BY t.paid_on DESC, t.id DESC
    ");

    $paymentStatement->execute([$selectedYear]);
    $thirteenthPayments = $paymentStatement->fetchAll();
}

$thirteenthDueTotal = array_sum(array_column($thirteenthRows, 'due'));
$thirteenthPaidTotal = array_sum(array_column($thirteenthRows, 'paid'));
$thirteenthLeftTotal = array_sum(array_column($thirteenthRows, 'left'));


/*
|--------------------------------------------------------------------------
| LAYOUT
|--------------------------------------------------------------------------
*/

require_once __DIR__
    . '/../../app/views/partials/header.php';

require_once __DIR__
    . '/../../app/views/partials/sidebar.php';

?>

<link rel="stylesheet" href="/assets/css/payroll.css?v=20261018-13m2">

<div class="payroll-page">

    <div class="payroll-top">
        <div>
            <div class="payroll-eyebrow">WORKFORCE & PAYROLL</div>
            <h2>13th Month Pay</h2>
            <p>1/12 of the basic pay each employee earned in the year, paid by December 24.</p>
        </div>
    </div>


    <nav class="payroll-tabs" aria-label="Payroll sections">
        <a href="/payroll/?view=employees">
            <span class="material-symbols-rounded">groups</span>
            Employees
        </a>

        <a href="/payroll/?view=process">
            <span class="material-symbols-rounded">payments</span>
            Process Payroll
        </a>

        <a href="/payroll/?view=history">
            <span class="material-symbols-rounded">history</span>
            Payroll History
        </a>

        <a href="/payroll/thirteenth.php" class="active" aria-current="page">
            <span class="material-symbols-rounded">redeem</span>
            13th Month
        </a>
    </nav>


    <?php if ($thirteenthSuccess !== ''): ?>
        <div class="payroll-alert success">
            <span class="material-symbols-rounded">check_circle</span>
            <?= htmlspecialchars($thirteenthSuccess) ?>
        </div>
    <?php endif; ?>

    <?php if ($thirteenthError !== ''): ?>
        <div class="payroll-alert error">
            <span class="material-symbols-rounded">error</span>
            <?= htmlspecialchars($thirteenthError) ?>
        </div>
    <?php endif; ?>


<?php if (!$thirteenthReady): ?>

    <section class="payroll-card">
        <div class="payroll-card-header">
            <div>
                <h3>Database update needed</h3>
                <p>
                    Run <strong>C:\php\php.exe tools\migrate_thirteenth_month.php</strong> once
                    (it makes a backup first), then open this page again.
                </p>
            </div>
        </div>
    </section>

<?php else: ?>

    <div class="payroll-stats">

        <div class="payroll-stat-card">
            <div class="payroll-stat-icon">
                <span class="material-symbols-rounded">groups</span>
            </div>
            <div>
                <span>Employees · <?= $selectedYear ?></span>
                <strong><?= count($thirteenthRows) ?></strong>
            </div>
        </div>

        <div class="payroll-stat-card">
            <div class="payroll-stat-icon">
                <span class="material-symbols-rounded">redeem</span>
            </div>
            <div>
                <span>13th Month Due</span>
                <strong><?= thirteenthMoney($thirteenthDueTotal) ?></strong>
            </div>
        </div>

        <div class="payroll-stat-card">
            <div class="payroll-stat-icon">
                <span class="material-symbols-rounded">task_alt</span>
            </div>
            <div>
                <span>Paid</span>
                <strong><?= thirteenthMoney($thirteenthPaidTotal) ?></strong>
            </div>
        </div>

        <div class="payroll-stat-card">
            <div class="payroll-stat-icon">
                <span class="material-symbols-rounded">pending_actions</span>
            </div>
            <div>
                <span>Still to Pay</span>
                <strong><?= thirteenthMoney($thirteenthLeftTotal) ?></strong>
            </div>
        </div>

    </div>


    <section class="payroll-card payroll-thirteenth-card">

        <div class="payroll-card-header">
            <div>
                <h3>Employees · <?= $selectedYear ?></h3>
                <p>
                    Basic pay = gross pay of the processed payroll whose period ends in <?= $selectedYear ?>.
                    13th month pay = basic pay ÷ 12.
                </p>
            </div>

            <form method="get" class="payroll-thirteenth-year">
                <label for="thirteenthYear">Year</label>
                <select id="thirteenthYear" name="year" class="payroll-filter-select" onchange="this.form.submit()">
                    <?php foreach (array_keys($years) as $yearOption): ?>
                        <option value="<?= $yearOption ?>" <?= $yearOption === $selectedYear ? 'selected' : '' ?>><?= $yearOption ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="payroll-secondary-button">Show</button></noscript>
            </form>
        </div>

        <p class="payroll-thirteenth-note">
            <span class="material-symbols-rounded">info</span>
            <span>
                Due by <strong>December 24</strong>. It can be paid in parts (for example half in June).
                Tax-free up to ₱<?= number_format(PAYROLL_THIRTEENTH_TAX_FREE) ?>; no SSS, PhilHealth or
                Pag-IBIG is deducted. Employees who left still get theirs.
            </span>
        </p>

        <div class="payroll-table-wrap">
            <table class="payroll-table payroll-thirteenth-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Basic Pay Earned</th>
                        <th>13th Month (÷ 12)</th>
                        <th>Paid</th>
                        <th>Still to Pay</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>

                    <?php if ($thirteenthRows === []): ?>
                        <tr>
                            <td colspan="7" class="payroll-empty-cell">
                                <div class="payroll-empty">
                                    <span class="material-symbols-rounded">redeem</span>
                                    <strong>No processed payroll in <?= $selectedYear ?> yet</strong>
                                    <p>13th month pay is worked out from the payroll processed in the year.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($thirteenthRows as $row): ?>
                        <?php
                        $rowName = thirteenthName($row);

                        if ($row['left'] <= 0) {
                            [$statusClass, $statusText] = ['active', 'Paid in full'];
                        } elseif ($row['paid'] > 0) {
                            [$statusClass, $statusText] = ['partial', 'Partly paid'];
                        } else {
                            [$statusClass, $statusText] = ['inactive', 'Not paid'];
                        }
                        ?>
                        <tr>
                            <td>
                                <div class="payroll-thirteenth-employee">
                                    <strong><?= htmlspecialchars($rowName) ?></strong>
                                    <small>
                                        <?= htmlspecialchars($row['employee_code'] . ' · ' . $row['position']) ?>
                                        <?= $row['status'] !== 'Active' ? ' · ' . htmlspecialchars($row['status']) : '' ?>
                                    </small>
                                </div>
                            </td>
                            <td><span class="payroll-money"><?= thirteenthMoney($row['basic_pay']) ?></span></td>
                            <td>
                                <span class="payroll-money"><?= thirteenthMoney($row['due']) ?></span>
                                <?php if ($row['due'] > PAYROLL_THIRTEENTH_TAX_FREE): ?>
                                    <small class="payroll-thirteenth-warning">Above ₱<?= number_format(PAYROLL_THIRTEENTH_TAX_FREE) ?>: the extra is taxable</small>
                                <?php endif; ?>
                            </td>
                            <td><?= thirteenthMoney($row['paid']) ?></td>
                            <td>
                                <strong class="payroll-money"><?= thirteenthMoney($row['left']) ?></strong>
                                <?php if ($row['over'] > 0): ?>
                                    <small class="payroll-thirteenth-warning"><?= thirteenthMoney($row['over']) ?> paid above what is due</small>
                                <?php endif; ?>
                            </td>
                            <td><span class="payroll-status <?= $statusClass ?>"><?= $statusText ?></span></td>
                            <td>
                                <?php if ($row['left'] > 0): ?>
                                    <button
                                        type="button"
                                        class="payroll-secondary-button payroll-thirteenth-pay"
                                        data-pay-employee="<?= (int) $row['id'] ?>"
                                        data-pay-name="<?= htmlspecialchars($rowName) ?>"
                                        data-pay-due="<?= number_format($row['due'], 2, '.', '') ?>"
                                        data-pay-paid="<?= number_format($row['paid'], 2, '.', '') ?>"
                                        data-pay-left="<?= number_format($row['left'], 2, '.', '') ?>"
                                    >
                                        <span class="material-symbols-rounded">payments</span>
                                        Pay
                                    </button>
                                <?php else: ?>
                                    <span class="payroll-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                </tbody>
            </table>
        </div>

    </section>


    <section class="payroll-card payroll-thirteenth-card">

        <div class="payroll-card-header">
            <div>
                <h3>Payments · <?= $selectedYear ?></h3>
                <p>Every 13th month payment for <?= $selectedYear ?>, newest first. Voided payments stay in the list.</p>
            </div>
        </div>

        <div class="payroll-table-wrap">
            <table class="payroll-table payroll-thirteenth-table">
                <thead>
                    <tr>
                        <th>Paid On</th>
                        <th>Payment</th>
                        <th>Employee</th>
                        <th>Amount</th>
                        <th>Recorded By</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>

                    <?php if ($thirteenthPayments === []): ?>
                        <tr>
                            <td colspan="7" class="payroll-empty-cell">
                                <div class="payroll-empty">
                                    <span class="material-symbols-rounded">receipt_long</span>
                                    <strong>No 13th month payments for <?= $selectedYear ?> yet</strong>
                                    <p>Use “Pay” next to an employee above.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($thirteenthPayments as $payment): ?>
                        <?php
                        $paymentId = (int) $payment['id'];
                        $paymentVoided = $payment['status'] === 'Voided';
                        $paymentName = thirteenthName($payment);
                        ?>
                        <tr class="<?= $paymentVoided ? 'payroll-row-voided' : '' ?>">
                            <td><?= htmlspecialchars(thirteenthDate((string) $payment['paid_on'])) ?></td>
                            <td><strong class="payroll-thirteenth-code"><?= thirteenthCode($paymentId) ?></strong></td>
                            <td>
                                <div class="payroll-thirteenth-employee">
                                    <strong><?= htmlspecialchars($paymentName) ?></strong>
                                    <small><?= htmlspecialchars((string) $payment['employee_code']) ?></small>
                                </div>
                            </td>
                            <td><strong class="payroll-money"><?= thirteenthMoney((float) $payment['amount']) ?></strong></td>
                            <td><?= htmlspecialchars(thirteenthName($payment, 'processor_') ?: '—') ?></td>
                            <td>
                                <div class="payroll-history-status">
                                    <span class="payroll-status <?= $paymentVoided ? 'voided' : 'active' ?>">
                                        <?= $paymentVoided ? 'Voided' : 'Paid' ?>
                                    </span>
                                    <?php if ($paymentVoided): ?>
                                        <small>
                                            <?= htmlspecialchars(thirteenthName($payment, 'voider_')) ?>:
                                            <?= htmlspecialchars((string) $payment['void_reason']) ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="payroll-thirteenth-actions">
                                    <a
                                        class="payroll-payslip-link"
                                        href="/payroll/thirteenth_slip.php?id=<?= $paymentId ?>"
                                        title="Open the 13th month pay slip"
                                    >
                                        <span class="material-symbols-rounded">receipt_long</span>
                                        Slip
                                    </a>

                                    <?php if ($canVoidThirteenth && !$paymentVoided): ?>
                                        <button
                                            type="button"
                                            class="payroll-secondary-button payroll-thirteenth-void"
                                            data-void-payment="<?= $paymentId ?>"
                                            data-void-code="<?= thirteenthCode($paymentId) ?>"
                                            data-void-summary="<?= htmlspecialchars(
                                                thirteenthMoney((float) $payment['amount']) . ' paid to ' . $paymentName
                                                . ' on ' . thirteenthDate((string) $payment['paid_on'])
                                            ) ?>"
                                        >
                                            <span class="material-symbols-rounded">block</span>
                                            Void
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                </tbody>
            </table>
        </div>

    </section>

<?php endif; ?>

</div>


<?php if ($thirteenthReady): ?>

<!-- RECORD A PAYMENT -->

<div class="payroll-modal" id="thirteenthPayModal" hidden>

    <button type="button" class="payroll-modal-backdrop" data-close-thirteenth aria-label="Close"></button>

    <div class="payroll-modal-card payroll-void-card" role="dialog" aria-modal="true" aria-labelledby="thirteenthPayTitle">

        <div class="payroll-modal-header">
            <div>
                <div class="payroll-modal-eyebrow">13TH MONTH PAY · <?= $selectedYear ?></div>
                <h3 id="thirteenthPayTitle">Record payment</h3>
                <p id="thirteenthPaySummary"></p>
            </div>

            <button type="button" class="payroll-modal-close" data-close-thirteenth aria-label="Close">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>

        <form
            method="post"
            class="payroll-form"
            id="thirteenthPayForm"
            data-confirm-submit
            data-confirm-title="Record 13th month pay?"
            data-confirm-message="₱{amount} for {employee_label}, paid on {paid_on}. It counts as a payroll cost in Expenses and Reports."
            data-confirm-label="Record Payment"
            data-confirm-icon="redeem"
        >
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="pay_thirteenth">
            <input type="hidden" name="year" value="<?= $selectedYear ?>">
            <input type="hidden" name="employee_id" id="thirteenthEmployeeId" value="">
            <input type="hidden" name="employee_label" id="thirteenthEmployeeLabel" value="">

            <div class="payroll-form-grid">

                <div class="payroll-field">
                    <label for="thirteenthAmount">Amount <span>*</span></label>
                    <div class="payroll-money-input">
                        <span>₱</span>
                        <input type="number" id="thirteenthAmount" name="amount" min="0.01" step="0.01" required>
                    </div>
                    <small class="payroll-field-help">Filled in with what is still to pay. Lower it to pay a part now.</small>
                </div>

                <div class="payroll-field">
                    <label for="thirteenthPaidOn">Date Paid <span>*</span></label>
                    <input
                        type="date"
                        id="thirteenthPaidOn"
                        name="paid_on"
                        value="<?= $today ?>"
                        min="<?= $selectedYear ?>-01-01"
                        max="<?= $today ?>"
                        required
                    >
                </div>

                <div class="payroll-field full">
                    <label for="thirteenthNotes">Notes</label>
                    <textarea id="thirteenthNotes" name="notes" rows="2" maxlength="255" placeholder="Optional, e.g. first half"></textarea>
                </div>

            </div>

            <div class="payroll-modal-footer">
                <button type="button" class="payroll-secondary-button" data-close-thirteenth>Cancel</button>
                <button type="submit" class="payroll-primary-button">
                    <span class="material-symbols-rounded">redeem</span>
                    Record Payment
                </button>
            </div>
        </form>

    </div>

</div>


<?php if ($canVoidThirteenth): ?>

<!-- VOID A PAYMENT (Admin) -->

<div class="payroll-modal" id="thirteenthVoidModal" hidden>

    <button type="button" class="payroll-modal-backdrop" data-close-thirteenth aria-label="Close"></button>

    <div class="payroll-modal-card payroll-void-card" role="dialog" aria-modal="true" aria-labelledby="thirteenthVoidTitle">

        <div class="payroll-modal-header">
            <div>
                <div class="payroll-modal-eyebrow">VOID 13TH MONTH PAYMENT</div>
                <h3 id="thirteenthVoidTitle">Void payment?</h3>
                <p id="thirteenthVoidSummary"></p>
            </div>

            <button type="button" class="payroll-modal-close" data-close-thirteenth aria-label="Close">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>

        <form
            method="post"
            class="payroll-form"
            id="thirteenthVoidForm"
            data-confirm-submit
            data-confirm-title="Void this payment?"
            data-confirm-message="It stays in the list marked Voided and stops counting. This cannot be undone."
            data-confirm-label="Void Payment"
            data-confirm-icon="block"
        >
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="void_thirteenth">
            <input type="hidden" name="year" value="<?= $selectedYear ?>">
            <input type="hidden" name="payment_id" id="thirteenthVoidId" value="">

            <div class="payroll-field full">
                <label for="thirteenthVoidReason">Reason <span>*</span></label>
                <textarea id="thirteenthVoidReason" name="void_reason" rows="3" maxlength="255" required placeholder="Example: Recorded twice"></textarea>
                <small class="payroll-field-help">
                    The amount becomes "still to pay" again. Record the right payment afterwards.
                </small>
            </div>

            <div class="payroll-modal-footer">
                <button type="button" class="payroll-secondary-button" data-close-thirteenth>Cancel</button>
                <button type="submit" class="payroll-primary-button payroll-danger-button">
                    <span class="material-symbols-rounded">block</span>
                    Void Payment
                </button>
            </div>
        </form>

    </div>

</div>

<?php endif; ?>

<script>
(() => {
    const money = (value) =>
        '₱' + Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    let openModal = null;
    let returnFocus = null;

    const show = (modal, trigger, focusField) => {
        openModal = modal;
        returnFocus = trigger;
        modal.hidden = false;
        document.body.classList.add('payroll-modal-open');
        window.setTimeout(() => focusField?.focus(), 50);
    };

    const close = () => {
        if (!openModal) {
            return;
        }

        openModal.hidden = true;
        openModal = null;
        document.body.classList.remove('payroll-modal-open');
        returnFocus?.focus();
    };

    /* Record a payment: fill in what is still to pay. */
    const payModal = document.getElementById('thirteenthPayModal');
    const amount = document.getElementById('thirteenthAmount');

    document.querySelectorAll('[data-pay-employee]').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('thirteenthEmployeeId').value = button.dataset.payEmployee;
            document.getElementById('thirteenthEmployeeLabel').value = button.dataset.payName;
            document.getElementById('thirteenthPayTitle').textContent = 'Pay ' + button.dataset.payName;
            document.getElementById('thirteenthPaySummary').textContent =
                '13th month pay ' + money(button.dataset.payDue)
                + ' · already paid ' + money(button.dataset.payPaid)
                + ' · still to pay ' + money(button.dataset.payLeft);

            amount.value = button.dataset.payLeft;
            amount.max = button.dataset.payLeft;
            document.getElementById('thirteenthNotes').value = '';

            show(payModal, button, amount);
        });
    });

    /* Void a payment (Admin). */
    const voidModal = document.getElementById('thirteenthVoidModal');

    document.querySelectorAll('[data-void-payment]').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('thirteenthVoidId').value = button.dataset.voidPayment;
            document.getElementById('thirteenthVoidTitle').textContent = 'Void ' + button.dataset.voidCode + '?';
            document.getElementById('thirteenthVoidSummary').textContent = button.dataset.voidSummary;
            document.getElementById('thirteenthVoidReason').value = '';

            show(voidModal, button, document.getElementById('thirteenthVoidReason'));
        });
    });

    document.querySelectorAll('[data-close-thirteenth]').forEach((button) => {
        button.addEventListener('click', close);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && openModal && !window.UA?.isConfirmOpen()) {
            close();
        }
    });
})();
</script>

<?php endif; ?>

<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';

?>
