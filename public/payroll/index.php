<?php

require_once __DIR__ . '/../../app/middleware/role.php';

requireRole([
    'Admin',
    'Manager'
]);

require_once __DIR__ . '/../../app/config/database.php';

date_default_timezone_set('Asia/Manila');

$pageTitle = 'Payroll';
$currentPage = 'payroll';


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function employeeRedirect(string $view = 'employees'): never
{
    $allowedViews = [
        'employees',
        'process',
        'history'
    ];

    if (!in_array($view, $allowedViews, true)) {
        $view = 'employees';
    }

    header('Location: /payroll/?view=' . urlencode($view));
    exit;
}


function employeeFlash(string $type, string $message): void
{
    $key = $type === 'success'
        ? 'employee_success'
        : 'employee_error';

    $_SESSION[$key] = $message;
}


function cleanEmployeeOptional(mixed $value): ?string
{
    $value = trim((string) $value);

    return $value === '' ? null : $value;
}


function employeeDisplayName(array $row): string
{
    $parts = [];

    foreach (['first_name', 'middle_name', 'last_name', 'suffix'] as $field) {
        $value = trim((string) ($row[$field] ?? ''));

        if ($value !== '') {
            $parts[] = $value;
        }
    }

    if ($parts) {
        return implode(' ', $parts);
    }

    foreach (['full_name', 'username', 'email'] as $fallback) {
        $value = trim((string) ($row[$fallback] ?? ''));

        if ($value !== '') {
            return $value;
        }
    }

    return 'User #' . (int) ($row['id'] ?? 0);
}




function employeeTextLength(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}


function validEmployeeDate(?string $date): bool
{
    if ($date === null || $date === '') {
        return true;
    }

    $parsed = DateTime::createFromFormat('Y-m-d', $date);

    return $parsed !== false
        && $parsed->format('Y-m-d') === $date
        && $date <= date('Y-m-d');
}


function formatEmployeeTimestamp(?string $timestamp): string
{
    if (!$timestamp) {
        return '—';
    }

    try {
        $utc = new DateTimeZone('UTC');
        $manila = new DateTimeZone('Asia/Manila');

        $date = new DateTime($timestamp, $utc);
        $date->setTimezone($manila);

        return $date->format('M d, Y');
    } catch (Throwable) {
        return $timestamp;
    }
}


function generateEmployeeCode(PDO $pdo): string
{
    $number = 1;

    while (true) {
        $code = 'EMP-' . str_pad((string) $number, 4, '0', STR_PAD_LEFT);

        $check = $pdo->prepare("
            SELECT 1
            FROM employees
            WHERE employee_code = ?
            LIMIT 1
        ");

        $check->execute([$code]);

        if (!$check->fetchColumn()) {
            return $code;
        }

        $number++;
    }
}


function writeEmployeeLog(
    PDO $pdo,
    string $action,
    int $employeeId,
    string $details
): void {
    $statement = $pdo->prepare("
        INSERT INTO system_logs (
            user_id,
            action,
            module,
            record_type,
            record_id,
            details
        )
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $statement->execute([
        $_SESSION['user_id'] ?? null,
        $action,
        'Payroll',
        'Employee',
        $employeeId,
        $details
    ]);
}


function writePayrollLog(
    PDO $pdo,
    string $action,
    int $payrollId,
    string $details
): void {
    $statement = $pdo->prepare("
        INSERT INTO system_logs (
            user_id,
            action,
            module,
            record_type,
            record_id,
            details
        )
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $statement->execute([
        $_SESSION['user_id'] ?? null,
        $action,
        'Payroll',
        'Payroll',
        $payrollId,
        $details
    ]);
}


/*
|--------------------------------------------------------------------------
| PAYROLL RULES
|--------------------------------------------------------------------------
*/

/* Hours worked may not exceed 16 per calendar day in the payroll period. */
const PAYROLL_MAX_HOURS_PER_DAY = 16;


/*
 * Voiding is added by tools/migrate_payroll_void.php. Until that migration
 * has run on a computer, payroll works as before and voiding stays hidden.
 */
function payrollHasVoidColumns(PDO $pdo): bool
{
    static $hasColumns = null;

    if ($hasColumns === null) {
        $names = array_column(
            $pdo->query('PRAGMA table_info(payroll)')->fetchAll(),
            'name'
        );

        $hasColumns =
            in_array('status', $names, true) &&
            in_array('voided_by', $names, true) &&
            in_array('voided_at', $names, true) &&
            in_array('void_reason', $names, true);
    }

    return $hasColumns;
}


/* Number of calendar days in a period, counting both the start and end day. */
function payrollPeriodDays(string $periodStart, string $periodEnd): int
{
    $start = new DateTimeImmutable($periodStart);
    $end = new DateTimeImmutable($periodEnd);

    return (int) $start->diff($end)->days + 1;
}


function payrollRecordCode(int $payrollId): string
{
    return 'PAY-' . str_pad((string) $payrollId, 5, '0', STR_PAD_LEFT);
}


/* "Oct 01, 2026", or "—" when a stored date is empty or invalid. */
function payrollDateLabel(?string $date): string
{
    $timestamp = strtotime((string) $date);

    return $timestamp
        ? date('M d, Y', $timestamp)
        : '—';
}


/*
|--------------------------------------------------------------------------
| LOAD USER ACCOUNTS
|--------------------------------------------------------------------------
|
| Linking an employee to a user account is optional (employees.user_id may
| be NULL for staff who do not sign in to CompAcc). One account can be linked
| to at most one employee.
| We intentionally read SELECT * here so this page does not assume optional
| user columns that may differ between versions of the project.
|
*/

$userStatement = $pdo->query("
    SELECT *
    FROM users
    ORDER BY id ASC
");

$userAccounts = $userStatement->fetchAll();

$userMap = [];

foreach ($userAccounts as $user) {
    $userId = (int) ($user['id'] ?? 0);

    if ($userId <= 0) {
        continue;
    }

    $userMap[$userId] = $user;
}


/*
|--------------------------------------------------------------------------
| HANDLE POST REQUESTS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if (
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $submittedToken)
    ) {
        employeeFlash('error', 'Invalid request. Please try again.');
        employeeRedirect();
    }


    /*
    |--------------------------------------------------------------------------
    | ADD / UPDATE EMPLOYEE
    |--------------------------------------------------------------------------
    */

    if (in_array($action, ['add_employee', 'update_employee'], true)) {
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $userId = (int) ($_POST['user_id'] ?? 0);

        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $middleName = cleanEmployeeOptional($_POST['middle_name'] ?? null);
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        $suffix = cleanEmployeeOptional($_POST['suffix'] ?? null);

        $position = trim((string) ($_POST['position'] ?? ''));
        $payType = 'Hourly';

        $payRateInput = trim((string) ($_POST['pay_rate'] ?? ''));
        $payRate = is_numeric($payRateInput)
            ? round((float) $payRateInput, 2)
            : -1;

        $dateHired = cleanEmployeeOptional($_POST['date_hired'] ?? null);
        $status = trim((string) ($_POST['status'] ?? 'Active'));


        if ($action === 'update_employee' && $employeeId <= 0) {
            employeeFlash('error', 'Invalid employee.');
            employeeRedirect();
        }

        /* The user account is optional: 0 / empty means "no login". */
        if ($userId > 0 && !isset($userMap[$userId])) {
            employeeFlash('error', 'Please select a valid user account.');
            employeeRedirect();
        }

        $linkedUserId = $userId > 0
            ? $userId
            : null;

        if ($firstName === '' || $lastName === '') {
            employeeFlash('error', 'First name and last name are required.');
            employeeRedirect();
        }

        if (
            employeeTextLength($firstName) > 100 ||
            employeeTextLength($lastName) > 100 ||
            ($middleName !== null && employeeTextLength($middleName) > 100) ||
            ($suffix !== null && employeeTextLength($suffix) > 30)
        ) {
            employeeFlash('error', 'One or more employee name fields are too long.');
            employeeRedirect();
        }

        if ($position === '') {
            employeeFlash('error', 'Position is required.');
            employeeRedirect();
        }

        if (employeeTextLength($position) > 100) {
            employeeFlash('error', 'Position must not exceed 100 characters.');
            employeeRedirect();
        }

        if ($payRate < 0) {
            employeeFlash('error', 'Hourly pay rate must be zero or greater.');
            employeeRedirect();
        }

        if (!validEmployeeDate($dateHired)) {
            employeeFlash('error', 'Please enter a valid date hired that is not in the future.');
            employeeRedirect();
        }

        if (!in_array($status, ['Active', 'Inactive'], true)) {
            employeeFlash('error', 'Invalid employee status.');
            employeeRedirect();
        }


        /*
        |--------------------------------------------------------------------------
        | ONE USER ACCOUNT = ONE EMPLOYEE
        |--------------------------------------------------------------------------
        */

        if ($linkedUserId !== null) {

            $duplicateUser = $pdo->prepare("
                SELECT id
                FROM employees
                WHERE user_id = ?
                  AND id != ?
                LIMIT 1
            ");

            $duplicateUser->execute([
                $linkedUserId,
                $action === 'update_employee' ? $employeeId : 0
            ]);

            if ($duplicateUser->fetch()) {
                employeeFlash(
                    'error',
                    'That user account is already connected to another employee.'
                );

                employeeRedirect();
            }
        }


        try {
            $pdo->beginTransaction();

            if ($action === 'add_employee') {
                $employeeCode = generateEmployeeCode($pdo);

                $statement = $pdo->prepare("
                    INSERT INTO employees (
                        employee_code,
                        user_id,
                        first_name,
                        middle_name,
                        last_name,
                        suffix,
                        position,
                        pay_type,
                        pay_rate,
                        date_hired,
                        status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $statement->execute([
                    $employeeCode,
                    $linkedUserId,
                    $firstName,
                    $middleName,
                    $lastName,
                    $suffix,
                    $position,
                    $payType,
                    $payRate,
                    $dateHired,
                    $status
                ]);

                $employeeId = (int) $pdo->lastInsertId();

                writeEmployeeLog(
                    $pdo,
                    'ADD_EMPLOYEE',
                    $employeeId,
                    'Added employee ' . $employeeCode . ' - ' . $firstName . ' ' . $lastName
                );

                $message = 'Employee added successfully.';
            } else {
                $existing = $pdo->prepare("
                    SELECT employee_code
                    FROM employees
                    WHERE id = ?
                    LIMIT 1
                ");

                $existing->execute([$employeeId]);
                $existingEmployee = $existing->fetch();

                if (!$existingEmployee) {
                    throw new RuntimeException('Employee not found.');
                }

                $statement = $pdo->prepare("
                    UPDATE employees
                    SET
                        user_id = ?,
                        first_name = ?,
                        middle_name = ?,
                        last_name = ?,
                        suffix = ?,
                        position = ?,
                        pay_type = ?,
                        pay_rate = ?,
                        date_hired = ?,
                        status = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");

                $statement->execute([
                    $linkedUserId,
                    $firstName,
                    $middleName,
                    $lastName,
                    $suffix,
                    $position,
                    $payType,
                    $payRate,
                    $dateHired,
                    $status,
                    $employeeId
                ]);

                writeEmployeeLog(
                    $pdo,
                    'UPDATE_EMPLOYEE',
                    $employeeId,
                    'Updated employee ' . $existingEmployee['employee_code']
                );

                $message = 'Employee updated successfully.';
            }

            $pdo->commit();
            employeeFlash('success', $message);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            employeeFlash(
                'error',
                'Unable to save employee: ' . $error->getMessage()
            );
        }

        employeeRedirect();
    }


    /*
    |--------------------------------------------------------------------------
    | ACTIVATE / DEACTIVATE
    |--------------------------------------------------------------------------
    */

    if ($action === 'toggle_employee') {
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $newStatus = trim((string) ($_POST['new_status'] ?? ''));

        if (
            $employeeId <= 0 ||
            !in_array($newStatus, ['Active', 'Inactive'], true)
        ) {
            employeeFlash('error', 'Invalid employee status request.');
            employeeRedirect();
        }

        try {
            $pdo->beginTransaction();

            $employeeStatement = $pdo->prepare("
                SELECT
                    employee_code,
                    first_name,
                    last_name
                FROM employees
                WHERE id = ?
                LIMIT 1
            ");

            $employeeStatement->execute([$employeeId]);
            $employee = $employeeStatement->fetch();

            if (!$employee) {
                throw new RuntimeException('Employee not found.');
            }

            $update = $pdo->prepare("
                UPDATE employees
                SET
                    status = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");

            $update->execute([
                $newStatus,
                $employeeId
            ]);

            writeEmployeeLog(
                $pdo,
                $newStatus === 'Active'
                    ? 'ACTIVATE_EMPLOYEE'
                    : 'DEACTIVATE_EMPLOYEE',
                $employeeId,
                $newStatus . ' employee ' . $employee['employee_code']
            );

            $pdo->commit();

            employeeFlash(
                'success',
                'Employee status updated successfully.'
            );
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            employeeFlash(
                'error',
                'Unable to update employee status: ' . $error->getMessage()
            );
        }

        employeeRedirect();
    }




    /*
    |--------------------------------------------------------------------------
    | PROCESS PAYROLL
    |--------------------------------------------------------------------------
    */

    if ($action === 'process_payroll') {
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $periodStart = trim((string) ($_POST['period_start'] ?? ''));
        $periodEnd = trim((string) ($_POST['period_end'] ?? ''));

        $hoursWorkedInput = trim((string) ($_POST['hours_worked'] ?? ''));
        $hoursWorked = is_numeric($hoursWorkedInput)
            ? round((float) $hoursWorkedInput, 2)
            : -1;

        $deductionsInput = trim((string) ($_POST['deductions'] ?? ''));
        $deductions = $deductionsInput === ''
            ? 0.0
            : (
                is_numeric($deductionsInput)
                    ? round((float) $deductionsInput, 2)
                    : -1
            );

        $deductionNotes = cleanEmployeeOptional(
            $_POST['deduction_notes'] ?? null
        );

        $processedBy = (int) ($_SESSION['user_id'] ?? 0);


        if ($employeeId <= 0) {
            employeeFlash(
                'error',
                'Please select an employee.'
            );

            employeeRedirect('process');
        }

        /* Both dates are required (validEmployeeDate() accepts empty values). */
        if (
            $periodStart === '' ||
            $periodEnd === '' ||
            !validEmployeeDate($periodStart) ||
            !validEmployeeDate($periodEnd)
        ) {
            employeeFlash(
                'error',
                'Please enter a valid payroll period start and end date that are not in the future.'
            );

            employeeRedirect('process');
        }

        if ($periodStart > $periodEnd) {
            employeeFlash(
                'error',
                'Payroll period end cannot be before the start date.'
            );

            employeeRedirect('process');
        }

        $periodDays =
            payrollPeriodDays($periodStart, $periodEnd);

        $maxHours =
            $periodDays * PAYROLL_MAX_HOURS_PER_DAY;

        if (
            $hoursWorked <= 0 ||
            $hoursWorked > $maxHours
        ) {
            employeeFlash(
                'error',
                'Hours worked must be more than 0 and at most '
                    . number_format($maxHours)
                    . ' for this period ('
                    . PAYROLL_MAX_HOURS_PER_DAY
                    . ' hours × '
                    . $periodDays
                    . ($periodDays === 1 ? ' day' : ' days')
                    . ').'
            );

            employeeRedirect('process');
        }

        if ($deductions < 0) {
            employeeFlash(
                'error',
                'Deductions must be zero or greater.'
            );

            employeeRedirect('process');
        }

        if (
            $deductionNotes !== null &&
            employeeTextLength($deductionNotes) > 500
        ) {
            employeeFlash(
                'error',
                'Deduction notes must not exceed 500 characters.'
            );

            employeeRedirect('process');
        }

        if (
            $processedBy <= 0 ||
            !isset($userMap[$processedBy])
        ) {
            employeeFlash(
                'error',
                'Unable to identify the account processing payroll.'
            );

            employeeRedirect('process');
        }


        try {
            $pdo->beginTransaction();

            $employeeStatement = $pdo->prepare("
                SELECT
                    id,
                    employee_code,
                    first_name,
                    middle_name,
                    last_name,
                    suffix,
                    position,
                    pay_type,
                    pay_rate,
                    status
                FROM employees
                WHERE id = ?
                LIMIT 1
            ");

            $employeeStatement->execute([
                $employeeId
            ]);

            $employee = $employeeStatement->fetch();

            if (!$employee) {
                throw new RuntimeException(
                    'Employee not found.'
                );
            }

            if ($employee['status'] !== 'Active') {
                throw new RuntimeException(
                    'Payroll can only be processed for active employees.'
                );
            }

            if ($employee['pay_type'] !== 'Hourly') {
                throw new RuntimeException(
                    'This payroll screen currently supports hourly employees only.'
                );
            }

            $payRate = round(
                (float) $employee['pay_rate'],
                2
            );

            if ($payRate < 0) {
                throw new RuntimeException(
                    'Employee pay rate is invalid.'
                );
            }

            $grossPay = round(
                $hoursWorked * $payRate,
                2
            );

            if ($deductions > $grossPay) {
                throw new RuntimeException(
                    'Deductions cannot exceed gross pay.'
                );
            }

            $netPay = round(
                $grossPay - $deductions,
                2
            );


            /*
            |--------------------------------------------------------------------------
            | PREVENT PAYING THE SAME DAYS TWICE
            |--------------------------------------------------------------------------
            |
            | Two periods overlap when one starts on or before the other ends
            | and ends on or after the other starts. Voided records do not count,
            | so a period can be processed again after a mistake is voided.
            |
            */

            $overlapStatement = $pdo->prepare("
                SELECT
                    id,
                    period_start,
                    period_end
                FROM payroll
                WHERE employee_id = ?
                  AND period_start <= ?
                  AND period_end >= ?
                  " . (payrollHasVoidColumns($pdo) ? "AND status = 'Processed'" : '') . "
                ORDER BY period_start ASC
                LIMIT 1
            ");

            $overlapStatement->execute([
                $employeeId,
                $periodEnd,
                $periodStart
            ]);

            $overlap = $overlapStatement->fetch();

            if ($overlap) {
                throw new RuntimeException(
                    payrollRecordCode((int) $overlap['id'])
                    . ' already covers '
                    . payrollDateLabel($overlap['period_start'])
                    . ' to '
                    . payrollDateLabel($overlap['period_end'])
                    . ', which overlaps this period.'
                    . (payrollHasVoidColumns($pdo)
                        ? ' If it was a mistake, an Admin can void it first.'
                        : '')
                );
            }


            $insert = $pdo->prepare("
                INSERT INTO payroll (
                    employee_id,
                    period_start,
                    period_end,
                    hours_worked,
                    gross_pay,
                    deductions,
                    deduction_notes,
                    net_pay,
                    processed_by
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $insert->execute([
                $employeeId,
                $periodStart,
                $periodEnd,
                $hoursWorked,
                $grossPay,
                $deductions,
                $deductionNotes,
                $netPay,
                $processedBy
            ]);

            $payrollId = (int) $pdo->lastInsertId();
            $employeeName = employeeDisplayName($employee);

            writePayrollLog(
                $pdo,
                'PROCESS_PAYROLL',
                $payrollId,
                'Processed payroll for '
                    . $employee['employee_code']
                    . ' - '
                    . $employeeName
                    . ' for '
                    . $periodStart
                    . ' to '
                    . $periodEnd
                    . '; hours '
                    . number_format($hoursWorked, 2)
                    . '; gross PHP '
                    . number_format($grossPay, 2, '.', '')
                    . '; deductions PHP '
                    . number_format($deductions, 2, '.', '')
                    . '; net PHP '
                    . number_format($netPay, 2, '.', '')
            );

            $pdo->commit();

            employeeFlash(
                'success',
                'Payroll processed successfully for '
                    . $employeeName
                    . '. Net pay: ₱'
                    . number_format($netPay, 2)
            );
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            employeeFlash(
                'error',
                'Unable to process payroll: '
                    . $error->getMessage()
            );
        }

        employeeRedirect('process');
    }


    /*
    |--------------------------------------------------------------------------
    | VOID PAYROLL (Admin only)
    |--------------------------------------------------------------------------
    |
    | For payroll processed by mistake. The record stays in Payroll History,
    | marked Voided with a reason, and no longer counts in payroll totals,
    | Reports, the Financial Summary or the Dashboard.
    |
    */

    if ($action === 'void_payroll') {
        $payrollId = (int) ($_POST['payroll_id'] ?? 0);
        $voidReason = trim((string) ($_POST['void_reason'] ?? ''));

        if (($_SESSION['role'] ?? '') !== 'Admin') {
            employeeFlash('error', 'Only an Admin can void payroll.');
            employeeRedirect('history');
        }

        if (!payrollHasVoidColumns($pdo)) {
            employeeFlash(
                'error',
                'Voiding is not set up on this computer yet. Run tools/migrate_payroll_void.php first.'
            );

            employeeRedirect('history');
        }

        if ($payrollId <= 0) {
            employeeFlash('error', 'Invalid payroll record.');
            employeeRedirect('history');
        }

        if (
            $voidReason === '' ||
            employeeTextLength($voidReason) > 255
        ) {
            employeeFlash(
                'error',
                'Enter a reason for voiding (up to 255 characters).'
            );

            employeeRedirect('history');
        }

        try {
            $pdo->beginTransaction();

            $recordStatement = $pdo->prepare("
                SELECT
                    pr.id,
                    pr.period_start,
                    pr.period_end,
                    pr.gross_pay,
                    pr.net_pay,
                    pr.status,
                    e.employee_code,
                    e.first_name,
                    e.middle_name,
                    e.last_name,
                    e.suffix
                FROM payroll pr
                INNER JOIN employees e
                    ON e.id = pr.employee_id
                WHERE pr.id = ?
                LIMIT 1
            ");

            $recordStatement->execute([
                $payrollId
            ]);

            $record = $recordStatement->fetch();

            if (!$record) {
                throw new RuntimeException('Payroll record not found.');
            }

            if ($record['status'] !== 'Processed') {
                throw new RuntimeException('This payroll record is already voided.');
            }

            $void = $pdo->prepare("
                UPDATE payroll
                SET
                    status = 'Voided',
                    voided_by = ?,
                    voided_at = CURRENT_TIMESTAMP,
                    void_reason = ?
                WHERE id = ?
                  AND status = 'Processed'
            ");

            $void->execute([
                (int) $_SESSION['user_id'],
                $voidReason,
                $payrollId
            ]);

            if ($void->rowCount() !== 1) {
                throw new RuntimeException('This payroll record is already voided.');
            }

            writePayrollLog(
                $pdo,
                'VOID_PAYROLL',
                $payrollId,
                'Voided '
                    . payrollRecordCode($payrollId)
                    . ' for '
                    . $record['employee_code']
                    . ' - '
                    . employeeDisplayName($record)
                    . ' ('
                    . $record['period_start']
                    . ' to '
                    . $record['period_end']
                    . '; gross PHP '
                    . number_format((float) $record['gross_pay'], 2, '.', '')
                    . '; net PHP '
                    . number_format((float) $record['net_pay'], 2, '.', '')
                    . '). Reason: '
                    . $voidReason
            );

            $pdo->commit();

            employeeFlash(
                'success',
                payrollRecordCode($payrollId)
                    . ' was voided. It no longer counts in payroll totals or reports.'
            );
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            employeeFlash(
                'error',
                'Unable to void payroll: '
                    . $error->getMessage()
            );
        }

        employeeRedirect('history');
    }

    employeeFlash('error', 'Unknown employee action.');
    employeeRedirect();
}


/*
|--------------------------------------------------------------------------
| FLASH
|--------------------------------------------------------------------------
*/

$employeeSuccess = (string) ($_SESSION['employee_success'] ?? '');
$employeeError = (string) ($_SESSION['employee_error'] ?? '');

unset(
    $_SESSION['employee_success'],
    $_SESSION['employee_error']
);


/*
|--------------------------------------------------------------------------
| LOAD EMPLOYEES
|--------------------------------------------------------------------------
*/

$employeeStatement = $pdo->query("
    SELECT
        id,
        employee_code,
        user_id,
        first_name,
        middle_name,
        last_name,
        suffix,
        position,
        pay_type,
        pay_rate,
        date_hired,
        status,
        created_at,
        updated_at
    FROM employees
    ORDER BY
        CASE WHEN status = 'Active' THEN 0 ELSE 1 END,
        last_name ASC,
        first_name ASC
");

$employees = $employeeStatement->fetchAll();


/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/

$totalEmployees = count($employees);
$activeEmployees = 0;
$inactiveEmployees = 0;
$totalHourlyRate = 0.0;
$positions = [];

foreach ($employees as $employee) {
    if ($employee['status'] === 'Active') {
        $activeEmployees++;
    } else {
        $inactiveEmployees++;
    }

    $totalHourlyRate += (float) $employee['pay_rate'];

    $position = trim((string) $employee['position']);

    if ($position !== '') {
        $positions[strtolower($position)] = true;
    }
}

$averageHourlyRate = $totalEmployees > 0
    ? $totalHourlyRate / $totalEmployees
    : 0;


/*
|--------------------------------------------------------------------------
| USED USER ACCOUNTS
|--------------------------------------------------------------------------
*/

$employeeByUser = [];

foreach ($employees as $employee) {
    if ($employee['user_id'] === null) {
        continue;
    }

    $employeeByUser[(int) $employee['user_id']] = (int) $employee['id'];
}


/*
|--------------------------------------------------------------------------
| EDIT DATA
|--------------------------------------------------------------------------
*/

$employeeEditData = [];

foreach ($employees as $employee) {
    $employeeEditData[(int) $employee['id']] = [
        'id' => (int) $employee['id'],
        'employee_code' => (string) $employee['employee_code'],
        'user_id' => $employee['user_id'] !== null
            ? (int) $employee['user_id']
            : '',
        'first_name' => (string) $employee['first_name'],
        'middle_name' => (string) ($employee['middle_name'] ?? ''),
        'last_name' => (string) $employee['last_name'],
        'suffix' => (string) ($employee['suffix'] ?? ''),
        'position' => (string) $employee['position'],
        'pay_rate' => number_format((float) $employee['pay_rate'], 2, '.', ''),
        'date_hired' => (string) ($employee['date_hired'] ?? ''),
        'status' => (string) $employee['status']
    ];
}

$userAutofillData = [];

foreach ($userMap as $userId => $user) {
    $userAutofillData[$userId] = [
        'first_name' => (string) ($user['first_name'] ?? ''),
        'middle_name' => (string) ($user['middle_name'] ?? ''),
        'last_name' => (string) ($user['last_name'] ?? ''),
        'suffix' => (string) ($user['suffix'] ?? '')
    ];
}




/*
|--------------------------------------------------------------------------
| PAYROLL PROCESSING DATA
|--------------------------------------------------------------------------
*/

$currentView = trim(
    (string) ($_GET['view'] ?? 'employees')
);

if (
    !in_array(
        $currentView,
        ['employees', 'process', 'history'],
        true
    )
) {
    $currentView = 'employees';
}


$activeEmployeesForPayroll = [];

foreach ($employees as $employee) {
    if (
        $employee['status'] === 'Active' &&
        $employee['pay_type'] === 'Hourly'
    ) {
        $activeEmployeesForPayroll[] = $employee;
    }
}


$payrollVoidReady =
    payrollHasVoidColumns($pdo);

/* Voided payroll never counts in totals. */
$payrollCountedCondition =
    $payrollVoidReady
        ? "status = 'Processed'"
        : '1 = 1';

$canVoidPayroll =
    $payrollVoidReady &&
    ($_SESSION['role'] ?? '') === 'Admin';

$payrollSummaryStatement = $pdo->query("
    SELECT
        COUNT(*) AS total_records,
        COALESCE(SUM(gross_pay), 0) AS total_gross,
        COALESCE(SUM(deductions), 0) AS total_deductions,
        COALESCE(SUM(net_pay), 0) AS total_net
    FROM payroll
    WHERE {$payrollCountedCondition}
");

$payrollSummary = $payrollSummaryStatement->fetch() ?: [];

$totalPayrollRecords = (int) (
    $payrollSummary['total_records'] ?? 0
);

$totalGrossPayroll = (float) (
    $payrollSummary['total_gross'] ?? 0
);

$totalPayrollDeductions = (float) (
    $payrollSummary['total_deductions'] ?? 0
);

$totalNetPayroll = (float) (
    $payrollSummary['total_net'] ?? 0
);


$currentMonthStart = date('Y-m-01');
$currentMonthEnd = date('Y-m-d');

$currentMonthStatement = $pdo->prepare("
    SELECT
        COUNT(*) AS payroll_count,
        COALESCE(SUM(net_pay), 0) AS net_total
    FROM payroll
    WHERE period_end BETWEEN ? AND ?
      AND {$payrollCountedCondition}
");

$currentMonthStatement->execute([
    $currentMonthStart,
    $currentMonthEnd
]);

$currentMonthPayroll = $currentMonthStatement->fetch() ?: [];

$currentMonthPayrollCount = (int) (
    $currentMonthPayroll['payroll_count'] ?? 0
);

$currentMonthNetPayroll = (float) (
    $currentMonthPayroll['net_total'] ?? 0
);


$currentProcessor = $userMap[
    (int) ($_SESSION['user_id'] ?? 0)
] ?? [];

$currentProcessorName = $currentProcessor
    ? employeeDisplayName($currentProcessor)
    : 'Current User';


$payrollEmployeeData = [];

foreach ($activeEmployeesForPayroll as $employee) {
    $payrollEmployeeData[(int) $employee['id']] = [
        'id' => (int) $employee['id'],
        'employee_code' => (string) $employee['employee_code'],
        'name' => employeeDisplayName($employee),
        'position' => (string) $employee['position'],
        'pay_rate' => number_format(
            (float) $employee['pay_rate'],
            2,
            '.',
            ''
        )
    ];
}




/*
|--------------------------------------------------------------------------
| PAYROLL HISTORY DATA
|--------------------------------------------------------------------------
|
| History filters are intentionally based on the saved payroll record.
| Date filters use period_end so the selected range represents the payroll
| periods being reviewed rather than the browser/display date.
|
*/

$historySearch = trim((string) ($_GET['history_search'] ?? ''));
$historyEmployeeId = (int) ($_GET['history_employee'] ?? 0);
$historyDateFrom = trim((string) ($_GET['history_from'] ?? ''));
$historyDateTo = trim((string) ($_GET['history_to'] ?? ''));

if ($historyDateFrom !== '' && !validEmployeeDate($historyDateFrom)) {
    $historyDateFrom = '';
}

if ($historyDateTo !== '' && !validEmployeeDate($historyDateTo)) {
    $historyDateTo = '';
}

if (
    $historyDateFrom !== '' &&
    $historyDateTo !== '' &&
    $historyDateFrom > $historyDateTo
) {
    [$historyDateFrom, $historyDateTo] = [
        $historyDateTo,
        $historyDateFrom
    ];
}

$historyWhere = [];
$historyParameters = [];

if ($historyEmployeeId > 0) {
    $historyWhere[] = 'pr.employee_id = ?';
    $historyParameters[] = $historyEmployeeId;
}

if ($historyDateFrom !== '') {
    $historyWhere[] = 'pr.period_end >= ?';
    $historyParameters[] = $historyDateFrom;
}

if ($historyDateTo !== '') {
    $historyWhere[] = 'pr.period_end <= ?';
    $historyParameters[] = $historyDateTo;
}

if ($historySearch !== '') {
    $historyWhere[] = "(
        LOWER(e.employee_code) LIKE ?
        OR LOWER(e.first_name) LIKE ?
        OR LOWER(COALESCE(e.middle_name, '')) LIKE ?
        OR LOWER(e.last_name) LIKE ?
        OR LOWER(COALESCE(e.suffix, '')) LIKE ?
        OR LOWER(e.position) LIKE ?
    )";

    $historyLike = '%' . strtolower($historySearch) . '%';

    for ($historySearchIndex = 0; $historySearchIndex < 6; $historySearchIndex++) {
        $historyParameters[] = $historyLike;
    }
}

$historySql = "
    SELECT
        pr.id,
        pr.employee_id,
        pr.period_start,
        pr.period_end,
        pr.hours_worked,
        pr.gross_pay,
        pr.deductions,
        pr.deduction_notes,
        pr.net_pay,
        pr.processed_by,
        pr.created_at,

        e.employee_code,
        e.first_name,
        e.middle_name,
        e.last_name,
        e.suffix,
        e.position,
        e.pay_type,
        e.status AS employee_status,

        " . ($payrollVoidReady
            ? 'pr.status AS payroll_status, pr.voided_by, pr.voided_at, pr.void_reason'
            : "'Processed' AS payroll_status, NULL AS voided_by, NULL AS voided_at, NULL AS void_reason") . "
    FROM payroll pr
    INNER JOIN employees e
        ON e.id = pr.employee_id
";

if ($historyWhere) {
    $historySql .= "
        WHERE " . implode(' AND ', $historyWhere);
}

$historySql .= "
    ORDER BY
        pr.period_end DESC,
        pr.created_at DESC,
        pr.id DESC
";

$historyStatement = $pdo->prepare($historySql);
$historyStatement->execute($historyParameters);

$payrollHistory = $historyStatement->fetchAll();

$historyRecordCount = count($payrollHistory);
$historyVoidedCount = 0;
$historyGrossTotal = 0.0;
$historyDeductionTotal = 0.0;
$historyNetTotal = 0.0;

foreach ($payrollHistory as $historyRecord) {
    /* Voided records are listed but do not count in the totals. */
    if ($historyRecord['payroll_status'] === 'Voided') {
        $historyVoidedCount++;
        continue;
    }

    $historyGrossTotal += (float) $historyRecord['gross_pay'];
    $historyDeductionTotal += (float) $historyRecord['deductions'];
    $historyNetTotal += (float) $historyRecord['net_pay'];
}


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

<link rel="stylesheet" href="/assets/css/payroll.css?v=20261008">

<div class="payroll-page">

    <div class="payroll-top">
        <div>
            <div class="payroll-eyebrow">WORKFORCE & PAYROLL</div>
            <h2>Payroll Management</h2>
            <p>Manage employees and process hourly payroll from one workspace.</p>
        </div>

        <?php if ($currentView === 'employees'): ?>
            <button
                type="button"
                class="payroll-primary-button"
                id="openAddEmployee"
            >
                <span class="material-symbols-rounded">person_add</span>
                Add Employee
            </button>

        <?php elseif ($currentView === 'process'): ?>
            <div class="payroll-processor-chip">
                <span class="material-symbols-rounded">verified_user</span>
                <div>
                    <small>Processing as</small>
                    <strong><?= htmlspecialchars($currentProcessorName) ?></strong>
                </div>
            </div>

        <?php else: ?>
            <div class="payroll-processor-chip">
                <span class="material-symbols-rounded">history</span>
                <div>
                    <small>Payroll Records</small>
                    <strong><?= $totalPayrollRecords ?></strong>
                </div>
            </div>
        <?php endif; ?>
    </div>


    <nav class="payroll-tabs" aria-label="Payroll sections">
        <a
            href="/payroll/?view=employees"
            class="<?= $currentView === 'employees' ? 'active' : '' ?>"
        >
            <span class="material-symbols-rounded">groups</span>
            Employees
        </a>

        <a
            href="/payroll/?view=process"
            class="<?= $currentView === 'process' ? 'active' : '' ?>"
        >
            <span class="material-symbols-rounded">payments</span>
            Process Payroll
        </a>

        <a
            href="/payroll/?view=history"
            class="<?= $currentView === 'history' ? 'active' : '' ?>"
        >
            <span class="material-symbols-rounded">history</span>
            Payroll History
        </a>
    </nav>


    <?php if ($employeeSuccess !== ''): ?>
        <div class="payroll-alert success">
            <span class="material-symbols-rounded">check_circle</span>
            <?= htmlspecialchars($employeeSuccess) ?>
        </div>
    <?php endif; ?>


    <?php if ($employeeError !== ''): ?>
        <div class="payroll-alert error">
            <span class="material-symbols-rounded">error</span>
            <?= htmlspecialchars($employeeError) ?>
        </div>
    <?php endif; ?>


    <div
        class="payroll-view"
        <?= $currentView !== 'employees' ? 'hidden' : '' ?>
    >
    <div class="payroll-stats">

        <div class="payroll-stat-card">
            <div class="payroll-stat-icon">
                <span class="material-symbols-rounded">groups</span>
            </div>
            <div>
                <span>Total Employees</span>
                <strong><?= $totalEmployees ?></strong>
            </div>
        </div>

        <div class="payroll-stat-card">
            <div class="payroll-stat-icon">
                <span class="material-symbols-rounded">verified_user</span>
            </div>
            <div>
                <span>Active Employees</span>
                <strong><?= $activeEmployees ?></strong>
            </div>
        </div>

        <div class="payroll-stat-card">
            <div class="payroll-stat-icon">
                <span class="material-symbols-rounded">work</span>
            </div>
            <div>
                <span>Positions</span>
                <strong><?= count($positions) ?></strong>
            </div>
        </div>

        <div class="payroll-stat-card">
            <div class="payroll-stat-icon">
                <span class="material-symbols-rounded">payments</span>
            </div>
            <div>
                <span>Average Hourly Rate</span>
                <strong>₱<?= number_format($averageHourlyRate, 2) ?></strong>
            </div>
        </div>

    </div>


    <section class="payroll-card">

        <div class="payroll-card-header">

            <div>
                <h3>Employee Directory</h3>
                <p>
                    <span id="employeeVisibleCount"><?= $totalEmployees ?></span>
                    <span id="employeeCountLabel"><?= $totalEmployees === 1 ? 'employee' : 'employees' ?></span>
                </p>
            </div>

            <div class="payroll-toolbar">

                <select
                    id="employeeStatusFilter"
                    class="payroll-filter-select"
                    aria-label="Filter employees by status"
                >
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>

                <div class="payroll-search">
                    <span class="material-symbols-rounded">search</span>
                    <input
                        type="text"
                        id="employeeSearch"
                        placeholder="Search employee, code or position..."
                        autocomplete="off"
                    >
                </div>

            </div>

        </div>


        <div class="payroll-table-wrap">

            <table class="payroll-table">

                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Linked Account</th>
                        <th>Position</th>
                        <th>Pay Type</th>
                        <th>Hourly Rate</th>
                        <th>Date Hired</th>
                        <th>Status</th>
                        <th>Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($employees)): ?>

                        <tr>
                            <td colspan="9" class="payroll-empty-cell">
                                <div class="payroll-empty">
                                    <span class="material-symbols-rounded">badge</span>
                                    <strong>No employees yet</strong>
                                    <p>Add your first employee to prepare the Payroll module.</p>
                                </div>
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($employees as $employee): ?>
                            <?php
                            $employeeId = (int) $employee['id'];
                            $linkedUser = $employee['user_id'] !== null
                                ? ($userMap[(int) $employee['user_id']] ?? [])
                                : [];

                            $linkedAccountName = match (true) {
                                $employee['user_id'] === null => 'No login',
                                (bool) $linkedUser => employeeDisplayName($linkedUser),
                                default => 'User #' . (int) $employee['user_id']
                            };

                            $employeeName = employeeDisplayName($employee);

                            $searchText = strtolower(implode(' ', [
                                $employee['employee_code'],
                                $employeeName,
                                $employee['position'],
                                $linkedAccountName
                            ]));
                            ?>

                            <tr
                                class="employee-row"
                                data-search="<?= htmlspecialchars($searchText) ?>"
                                data-status="<?= htmlspecialchars(strtolower($employee['status'])) ?>"
                            >

                                <td>
                                    <div class="payroll-employee-identity">
                                        <div class="payroll-avatar">
                                            <?= htmlspecialchars(strtoupper(substr($employee['first_name'], 0, 1))) ?>
                                        </div>

                                        <div>
                                            <strong><?= htmlspecialchars($employeeName) ?></strong>
                                            <small><?= htmlspecialchars($employee['employee_code']) ?></small>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <div class="payroll-account">
                                        <span><?= htmlspecialchars($linkedAccountName) ?></span>

                                        <?php if (!empty($linkedUser['role'])): ?>
                                            <small><?= htmlspecialchars((string) $linkedUser['role']) ?></small>
                                        <?php elseif ($employee['user_id'] === null): ?>
                                            <small>Staff without a POS account</small>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td>
                                    <?= htmlspecialchars($employee['position']) ?>
                                </td>

                                <td>
                                    <span class="payroll-pay-type">
                                        <?= htmlspecialchars($employee['pay_type']) ?>
                                    </span>
                                </td>

                                <td>
                                    <strong class="payroll-money">
                                        ₱<?= number_format((float) $employee['pay_rate'], 2) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?= $employee['date_hired']
                                        ? htmlspecialchars(date('M d, Y', strtotime($employee['date_hired'])))
                                        : '<span class="payroll-muted">Not specified</span>' ?>
                                </td>

                                <td>
                                    <span class="payroll-status <?= htmlspecialchars(strtolower($employee['status'])) ?>">
                                        <?= htmlspecialchars($employee['status']) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="payroll-muted">
                                        <?= htmlspecialchars(formatEmployeeTimestamp($employee['updated_at'])) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="payroll-actions">

                                        <button
                                            type="button"
                                            class="payroll-icon-button edit-employee-button"
                                            data-employee-id="<?= $employeeId ?>"
                                            title="Edit employee"
                                            aria-label="Edit <?= htmlspecialchars($employeeName) ?>"
                                        >
                                            <span class="material-symbols-rounded">edit</span>
                                        </button>

                                        <form
                                            method="post"
                                            class="payroll-inline-form"
                                            id="employeeStatusForm-<?= $employeeId ?>"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="toggle_employee"
                                            >

                                            <input
                                                type="hidden"
                                                name="employee_id"
                                                value="<?= $employeeId ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="new_status"
                                                value="<?= $employee['status'] === 'Active' ? 'Inactive' : 'Active' ?>"
                                            >

                                            <button
                                                type="button"
                                                class="payroll-icon-button"
                                                title="<?= $employee['status'] === 'Active' ? 'Deactivate' : 'Activate' ?> employee"
                                                aria-label="<?= $employee['status'] === 'Active' ? 'Deactivate' : 'Activate' ?> <?= htmlspecialchars($employeeName) ?>"
                                                data-confirm
                                                data-confirm-title="<?= $employee['status'] === 'Active'
                                                    ? 'Deactivate employee?'
                                                    : 'Activate employee?' ?>"
                                                data-confirm-message="<?= htmlspecialchars(
                                                    $employee['status'] === 'Active'
                                                        ? $employeeName . ' will be marked Inactive and can no longer be selected for new payroll. Existing payroll history will remain.'
                                                        : $employeeName . ' will be marked Active and can be selected for payroll again.'
                                                ) ?>"
                                                data-confirm-label="<?= $employee['status'] === 'Active' ? 'Deactivate' : 'Activate' ?>"
                                                data-confirm-icon="<?= $employee['status'] === 'Active' ? 'person_off' : 'person_check' ?>"
                                                data-confirm-form="employeeStatusForm-<?= $employeeId ?>"
                                            >
                                                <span class="material-symbols-rounded">
                                                    <?= $employee['status'] === 'Active'
                                                        ? 'person_off'
                                                        : 'person_check' ?>
                                                </span>
                                            </button>
                                        </form>

                                    </div>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    <tr id="filteredEmployeeEmpty" hidden>
                        <td colspan="9" class="payroll-empty-cell">
                            <div class="payroll-empty compact">
                                <span class="material-symbols-rounded">search_off</span>
                                <strong>No matching employees</strong>
                                <p>Try another search or status filter.</p>
                            </div>
                        </td>
                    </tr>

                </tbody>

            </table>

        </div>

    </section>
    </div>


    <div
        class="payroll-view"
        <?= $currentView !== 'process' ? 'hidden' : '' ?>
    >

        <div class="payroll-stats">

            <div class="payroll-stat-card">
                <div class="payroll-stat-icon">
                    <span class="material-symbols-rounded">person_check</span>
                </div>
                <div>
                    <span>Active Employees</span>
                    <strong><?= count($activeEmployeesForPayroll) ?></strong>
                </div>
            </div>

            <div class="payroll-stat-card">
                <div class="payroll-stat-icon">
                    <span class="material-symbols-rounded">receipt_long</span>
                </div>
                <div>
                    <span>Payroll Records</span>
                    <strong><?= $totalPayrollRecords ?></strong>
                </div>
            </div>

            <div class="payroll-stat-card">
                <div class="payroll-stat-icon">
                    <span class="material-symbols-rounded">account_balance_wallet</span>
                </div>
                <div>
                    <span>Total Gross Payroll</span>
                    <strong>₱<?= number_format($totalGrossPayroll, 2) ?></strong>
                </div>
            </div>

            <div class="payroll-stat-card">
                <div class="payroll-stat-icon">
                    <span class="material-symbols-rounded">payments</span>
                </div>
                <div>
                    <span>Total Net Payroll</span>
                    <strong>₱<?= number_format($totalNetPayroll, 2) ?></strong>
                </div>
            </div>

        </div>


        <div class="payroll-process-layout">

            <section class="payroll-card payroll-process-card">

                <div class="payroll-card-header">
                    <div>
                        <h3>Process Payroll</h3>
                        <p>
                            Calculate and save payroll for one active hourly employee.
                        </p>
                    </div>

                    <div class="payroll-period-chip">
                        <span class="material-symbols-rounded">calendar_month</span>
                        <?= htmlspecialchars(date('M Y')) ?>
                    </div>
                </div>


                <?php if (empty($activeEmployeesForPayroll)): ?>

                    <div class="payroll-empty">
                        <span class="material-symbols-rounded">person_off</span>
                        <strong>No active hourly employees</strong>
                        <p>
                            Add or activate an employee before processing payroll.
                        </p>
                    </div>

                <?php else: ?>

                    <form
                        method="post"
                        class="payroll-process-form"
                        id="payrollProcessForm"
                    >
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="process_payroll"
                        >


                        <div class="payroll-process-section">

                            <div class="payroll-section-heading">
                                <div>
                                    <span class="material-symbols-rounded">badge</span>
                                    <div>
                                        <h4>Employee</h4>
                                        <p>Select the employee whose payroll will be processed.</p>
                                    </div>
                                </div>
                            </div>


                            <div class="payroll-form-grid">

                                <div class="payroll-field full">
                                    <label for="payrollEmployeeId">
                                        Employee <span>*</span>
                                    </label>

                                    <select
                                        id="payrollEmployeeId"
                                        name="employee_id"
                                        required
                                    >
                                        <option value="">Select an active employee</option>

                                        <?php foreach ($activeEmployeesForPayroll as $employee): ?>
                                            <option
                                                value="<?= (int) $employee['id'] ?>"
                                                data-pay-rate="<?= htmlspecialchars(number_format((float) $employee['pay_rate'], 2, '.', '')) ?>"
                                                data-employee-name="<?= htmlspecialchars(employeeDisplayName($employee)) ?>"
                                                data-employee-code="<?= htmlspecialchars($employee['employee_code']) ?>"
                                                data-position="<?= htmlspecialchars($employee['position']) ?>"
                                            >
                                                <?= htmlspecialchars($employee['employee_code']) ?>
                                                — <?= htmlspecialchars(employeeDisplayName($employee)) ?>
                                                (<?= htmlspecialchars($employee['position']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                            </div>

                            <div class="payroll-selected-employee" id="payrollSelectedEmployee" hidden>
                                <div class="payroll-selected-avatar" id="payrollSelectedAvatar">E</div>

                                <div>
                                    <strong id="payrollSelectedName">Employee</strong>
                                    <span id="payrollSelectedMeta">Select an employee</span>
                                </div>

                                <div class="payroll-selected-rate">
                                    <small>Hourly Rate</small>
                                    <strong id="payrollSelectedRate">₱0.00</strong>
                                </div>
                            </div>

                        </div>


                        <div class="payroll-process-section">

                            <div class="payroll-section-heading">
                                <div>
                                    <span class="material-symbols-rounded">date_range</span>
                                    <div>
                                        <h4>Payroll Period</h4>
                                        <p>Choose the covered work period and hours rendered.</p>
                                    </div>
                                </div>
                            </div>


                            <div class="payroll-form-grid payroll-process-grid">

                                <div class="payroll-field">
                                    <label for="payrollPeriodStart">
                                        Period Start <span>*</span>
                                    </label>

                                    <input
                                        type="date"
                                        id="payrollPeriodStart"
                                        name="period_start"
                                        max="<?= htmlspecialchars(date('Y-m-d')) ?>"
                                        required
                                    >
                                </div>


                                <div class="payroll-field">
                                    <label for="payrollPeriodEnd">
                                        Period End <span>*</span>
                                    </label>

                                    <input
                                        type="date"
                                        id="payrollPeriodEnd"
                                        name="period_end"
                                        max="<?= htmlspecialchars(date('Y-m-d')) ?>"
                                        required
                                    >
                                </div>


                                <div class="payroll-field">
                                    <label for="payrollHoursWorked">
                                        Hours Worked <span>*</span>
                                    </label>

                                    <input
                                        type="number"
                                        id="payrollHoursWorked"
                                        name="hours_worked"
                                        min="0.01"
                                        step="0.01"
                                        value="0"
                                        required
                                    >

                                    <small
                                        class="payroll-field-help"
                                        id="payrollHoursHelp"
                                    >
                                        Up to <?= PAYROLL_MAX_HOURS_PER_DAY ?> hours per day in the period.
                                    </small>
                                </div>


                                <div class="payroll-field">
                                    <label for="payrollHourlyRate">
                                        Hourly Rate
                                    </label>

                                    <div class="payroll-money-input">
                                        <span>₱</span>

                                        <input
                                            type="text"
                                            id="payrollHourlyRate"
                                            value="0.00"
                                            readonly
                                            tabindex="-1"
                                        >
                                    </div>
                                </div>

                            </div>

                        </div>


                        <div class="payroll-process-section">

                            <div class="payroll-section-heading">
                                <div>
                                    <span class="material-symbols-rounded">calculate</span>
                                    <div>
                                        <h4>Pay Calculation</h4>
                                        <p>
                                            Gross and net pay are previewed here and recalculated securely when saved.
                                        </p>
                                    </div>
                                </div>
                            </div>


                            <div class="payroll-form-grid payroll-process-grid">

                                <div class="payroll-field">
                                    <label for="payrollGrossPay">
                                        Gross Pay
                                    </label>

                                    <div class="payroll-money-input">
                                        <span>₱</span>

                                        <input
                                            type="text"
                                            id="payrollGrossPay"
                                            value="0.00"
                                            readonly
                                            tabindex="-1"
                                        >
                                    </div>
                                </div>


                                <div class="payroll-field">
                                    <label for="payrollDeductions">
                                        Deductions
                                    </label>

                                    <div class="payroll-money-input">
                                        <span>₱</span>

                                        <input
                                            type="number"
                                            id="payrollDeductions"
                                            name="deductions"
                                            min="0"
                                            step="0.01"
                                            value="0.00"
                                        >
                                    </div>
                                </div>


                                <div class="payroll-field full">
                                    <label for="payrollDeductionNotes">
                                        Deduction Notes
                                    </label>

                                    <textarea
                                        id="payrollDeductionNotes"
                                        name="deduction_notes"
                                        rows="3"
                                        maxlength="500"
                                        placeholder="Optional explanation for deductions..."
                                    ></textarea>

                                    <small class="payroll-field-help">
                                        Example: cash advance, attendance adjustment, or other approved deduction.
                                    </small>
                                </div>

                            </div>

                        </div>


                        <div class="payroll-process-footer">

                            <div class="payroll-net-preview">
                                <span>NET PAY</span>
                                <strong id="payrollNetPay">₱0.00</strong>
                                <small>
                                    Gross pay minus deductions
                                </small>
                            </div>


                            <button
                                type="submit"
                                class="payroll-primary-button payroll-process-submit"
                                id="payrollProcessButton"
                            >
                                <span class="material-symbols-rounded">payments</span>
                                <span id="payrollProcessButtonLabel">Process Payroll</span>
                            </button>

                        </div>

                    </form>

                <?php endif; ?>

            </section>


            <aside class="payroll-side-column">

                <section class="payroll-card payroll-info-card">
                    <div class="payroll-card-header">
                        <div>
                            <h3>Payroll Formula</h3>
                            <p>Current hourly payroll calculation.</p>
                        </div>
                    </div>

                    <div class="payroll-formula">
                        <div>
                            <span>Gross Pay</span>
                            <strong>Hours Worked × Hourly Rate</strong>
                        </div>

                        <span class="material-symbols-rounded payroll-formula-arrow">
                            south
                        </span>

                        <div>
                            <span>Net Pay</span>
                            <strong>Gross Pay − Deductions</strong>
                        </div>
                    </div>
                </section>


                <section class="payroll-card payroll-info-card">
                    <div class="payroll-card-header">
                        <div>
                            <h3>Current Month</h3>
                            <p><?= htmlspecialchars(date('F Y')) ?></p>
                        </div>
                    </div>

                    <div class="payroll-mini-summary">
                        <div>
                            <span>Payrolls Processed</span>
                            <strong><?= $currentMonthPayrollCount ?></strong>
                        </div>

                        <div>
                            <span>Net Payroll</span>
                            <strong>₱<?= number_format($currentMonthNetPayroll, 2) ?></strong>
                        </div>

                        <div>
                            <span>Processed By</span>
                            <strong><?= htmlspecialchars($currentProcessorName) ?></strong>
                        </div>
                    </div>
                </section>


                <div class="payroll-security-note">
                    <span class="material-symbols-rounded">shield</span>

                    <div>
                        <strong>Server-validated payroll</strong>
                        <p>
                            Hourly rate, gross pay and net pay are recalculated from the database before the record is saved.
                        </p>
                    </div>
                </div>

            </aside>

        </div>

    </div>


    <!-- =====================================================
         PAYROLL HISTORY VIEW
    ====================================================== -->

    <div
        class="payroll-view"
        <?= $currentView !== 'history' ? 'hidden' : '' ?>
    >

        <div class="payroll-history-stats">

            <div class="payroll-stat-card">
                <div class="payroll-stat-icon">
                    <span class="material-symbols-rounded">receipt_long</span>
                </div>
                <div>
                    <span>History Records</span>
                    <strong><?= $historyRecordCount ?></strong>
                </div>
            </div>

            <div class="payroll-stat-card">
                <div class="payroll-stat-icon">
                    <span class="material-symbols-rounded">account_balance_wallet</span>
                </div>
                <div>
                    <span>Gross Pay</span>
                    <strong>₱<?= number_format($historyGrossTotal, 2) ?></strong>
                </div>
            </div>

            <div class="payroll-stat-card">
                <div class="payroll-stat-icon">
                    <span class="material-symbols-rounded">remove_circle</span>
                </div>
                <div>
                    <span>Deductions</span>
                    <strong>₱<?= number_format($historyDeductionTotal, 2) ?></strong>
                </div>
            </div>

            <div class="payroll-stat-card">
                <div class="payroll-stat-icon">
                    <span class="material-symbols-rounded">payments</span>
                </div>
                <div>
                    <span>Net Pay</span>
                    <strong>₱<?= number_format($historyNetTotal, 2) ?></strong>
                </div>
            </div>

        </div>


        <section class="payroll-card payroll-history-card">

            <div class="payroll-card-header payroll-history-header">
                <div>
                    <h3>Payroll History</h3>
                    <p>
                        Review processed payroll records, periods, deductions and processors.
                    </p>
                </div>
            </div>


            <form
                method="get"
                class="payroll-history-filters"
            >
                <input
                    type="hidden"
                    name="view"
                    value="history"
                >

                <div class="payroll-field payroll-history-search-field">
                    <label for="historySearch">
                        Search
                    </label>

                    <div class="payroll-search payroll-history-search">
                        <span class="material-symbols-rounded">search</span>

                        <input
                            type="text"
                            id="historySearch"
                            name="history_search"
                            value="<?= htmlspecialchars($historySearch) ?>"
                            placeholder="Employee, code or position..."
                            autocomplete="off"
                        >
                    </div>
                </div>


                <div class="payroll-field">
                    <label for="historyEmployee">
                        Employee
                    </label>

                    <select
                        id="historyEmployee"
                        name="history_employee"
                    >
                        <option value="">All Employees</option>

                        <?php foreach ($employees as $employee): ?>
                            <option
                                value="<?= (int) $employee['id'] ?>"
                                <?= $historyEmployeeId === (int) $employee['id'] ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($employee['employee_code']) ?>
                                — <?= htmlspecialchars(employeeDisplayName($employee)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>


                <div class="payroll-field">
                    <label for="historyFrom">
                        Period From
                    </label>

                    <input
                        type="date"
                        id="historyFrom"
                        name="history_from"
                        value="<?= htmlspecialchars($historyDateFrom) ?>"
                        max="<?= htmlspecialchars(date('Y-m-d')) ?>"
                    >
                </div>


                <div class="payroll-field">
                    <label for="historyTo">
                        Period To
                    </label>

                    <input
                        type="date"
                        id="historyTo"
                        name="history_to"
                        value="<?= htmlspecialchars($historyDateTo) ?>"
                        max="<?= htmlspecialchars(date('Y-m-d')) ?>"
                    >
                </div>


                <div class="payroll-history-filter-actions">

                    <button
                        type="submit"
                        class="payroll-primary-button"
                    >
                        <span class="material-symbols-rounded">filter_alt</span>
                        Apply
                    </button>

                    <a
                        href="/payroll/?view=history"
                        class="payroll-secondary-button payroll-button-link"
                    >
                        Clear
                    </a>

                </div>

            </form>


            <div class="payroll-history-results-bar">

                <div>
                    <span class="material-symbols-rounded">history</span>

                    <span>
                        Showing
                        <strong><?= $historyRecordCount ?></strong>
                        <?= $historyRecordCount === 1 ? 'record' : 'records' ?>
                        <?php if ($historyVoidedCount > 0): ?>
                            · <?= $historyVoidedCount ?> voided (not counted in totals)
                        <?php endif; ?>
                        <?php if (!$payrollVoidReady && ($_SESSION['role'] ?? '') === 'Admin'): ?>
                            · Voiding is off until tools/migrate_payroll_void.php is run on this computer
                        <?php endif; ?>
                    </span>
                </div>

                <?php if (
                    $historySearch !== '' ||
                    $historyEmployeeId > 0 ||
                    $historyDateFrom !== '' ||
                    $historyDateTo !== ''
                ): ?>
                    <span class="payroll-history-filtered-badge">
                        Filtered results
                    </span>
                <?php endif; ?>

            </div>


            <div class="payroll-table-wrap">

                <table class="payroll-table payroll-history-table">

                    <thead>
                        <tr>
                            <th>Payroll</th>
                            <th>Employee</th>
                            <th>Position</th>
                            <th>Payroll Period</th>
                            <th>Hours / Rate</th>
                            <th>Gross Pay</th>
                            <th>Deductions</th>
                            <th>Net Pay</th>
                            <th>Processed By</th>
                            <th>Processed</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (empty($payrollHistory)): ?>

                            <tr>
                                <td
                                    colspan="11"
                                    class="payroll-empty-cell"
                                >
                                    <div class="payroll-empty">
                                        <span class="material-symbols-rounded">history</span>

                                        <strong>No payroll records found</strong>

                                        <p>
                                            Process payroll first or change the history filters.
                                        </p>
                                    </div>
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($payrollHistory as $historyRecord): ?>
                                <?php
                                $historyEmployeeName = employeeDisplayName($historyRecord);

                                $historyIsVoided =
                                    $historyRecord['payroll_status'] === 'Voided';

                                $historyVoider = $historyIsVoided
                                    ? ($userMap[(int) $historyRecord['voided_by']] ?? [])
                                    : [];

                                $historyHours = (float) $historyRecord['hours_worked'];
                                $historyGross = (float) $historyRecord['gross_pay'];

                                /*
                                 * The payroll table does not store a historical hourly-rate
                                 * snapshot. Use the saved gross pay / saved hours to show the
                                 * effective rate at processing time instead of the employee's
                                 * current rate, which may have changed later.
                                 */
                                $historyEffectiveRate = $historyHours > 0
                                    ? $historyGross / $historyHours
                                    : null;

                                $processor = $userMap[
                                    (int) $historyRecord['processed_by']
                                ] ?? [];

                                $processorName = $processor
                                    ? employeeDisplayName($processor)
                                    : 'User #' . (int) $historyRecord['processed_by'];

                                try {
                                    $processedUtc = new DateTimeZone('UTC');
                                    $processedManila = new DateTimeZone('Asia/Manila');

                                    $processedDate = new DateTime(
                                        (string) $historyRecord['created_at'],
                                        $processedUtc
                                    );

                                    $processedDate->setTimezone($processedManila);

                                    $processedDateLabel = $processedDate->format('M d, Y');
                                    $processedTimeLabel = $processedDate->format('g:i A');
                                } catch (Throwable) {
                                    $processedDateLabel = (string) $historyRecord['created_at'];
                                    $processedTimeLabel = '';
                                }
                                ?>

                                <tr class="<?= $historyIsVoided ? 'payroll-row-voided' : '' ?>">

                                    <td>
                                        <div class="payroll-history-id">
                                            <strong>
                                                <?= htmlspecialchars(
                                                    payrollRecordCode((int) $historyRecord['id'])
                                                ) ?>
                                            </strong>

                                            <small>
                                                #<?= (int) $historyRecord['id'] ?>
                                            </small>
                                        </div>
                                    </td>


                                    <td>
                                        <div class="payroll-employee-identity payroll-history-employee">

                                            <div class="payroll-avatar">
                                                <?= htmlspecialchars(
                                                    strtoupper(
                                                        substr(
                                                            (string) $historyRecord['first_name'],
                                                            0,
                                                            1
                                                        )
                                                    )
                                                ) ?>
                                            </div>

                                            <div>
                                                <strong>
                                                    <?= htmlspecialchars($historyEmployeeName) ?>
                                                </strong>

                                                <small>
                                                    <?= htmlspecialchars($historyRecord['employee_code']) ?>
                                                    · <?= htmlspecialchars($historyRecord['employee_status']) ?>
                                                </small>
                                            </div>

                                        </div>
                                    </td>


                                    <td>
                                        <?= htmlspecialchars($historyRecord['position']) ?>
                                    </td>


                                    <td>
                                        <div class="payroll-history-period">
                                            <strong>
                                                <?= htmlspecialchars(
                                                    payrollDateLabel($historyRecord['period_start'])
                                                ) ?>
                                            </strong>

                                            <span>to</span>

                                            <strong>
                                                <?= htmlspecialchars(
                                                    payrollDateLabel($historyRecord['period_end'])
                                                ) ?>
                                            </strong>
                                        </div>
                                    </td>


                                    <td>
                                        <div class="payroll-history-hours">
                                            <strong>
                                                <?= number_format($historyHours, 2) ?> hrs
                                            </strong>

                                            <small>
                                                <?php if ($historyEffectiveRate !== null): ?>
                                                    ₱<?= number_format($historyEffectiveRate, 2) ?>/hr
                                                <?php else: ?>
                                                    Rate unavailable
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </td>


                                    <td>
                                        <strong class="payroll-money">
                                            ₱<?= number_format(
                                                (float) $historyRecord['gross_pay'],
                                                2
                                            ) ?>
                                        </strong>
                                    </td>


                                    <td>
                                        <div class="payroll-history-deduction">
                                            <strong>
                                                ₱<?= number_format(
                                                    (float) $historyRecord['deductions'],
                                                    2
                                                ) ?>
                                            </strong>

                                            <?php if (!empty($historyRecord['deduction_notes'])): ?>
                                                <small title="<?= htmlspecialchars($historyRecord['deduction_notes']) ?>">
                                                    <?= htmlspecialchars($historyRecord['deduction_notes']) ?>
                                                </small>
                                            <?php else: ?>
                                                <small>No notes</small>
                                            <?php endif; ?>
                                        </div>
                                    </td>


                                    <td>
                                        <strong class="payroll-history-net">
                                            ₱<?= number_format(
                                                (float) $historyRecord['net_pay'],
                                                2
                                            ) ?>
                                        </strong>
                                    </td>


                                    <td>
                                        <div class="payroll-account">
                                            <span>
                                                <?= htmlspecialchars($processorName) ?>
                                            </span>

                                            <?php if (!empty($processor['role'])): ?>
                                                <small>
                                                    <?= htmlspecialchars((string) $processor['role']) ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </td>


                                    <td>
                                        <div class="payroll-history-date">
                                            <strong>
                                                <?= htmlspecialchars($processedDateLabel) ?>
                                            </strong>

                                            <?php if ($processedTimeLabel !== ''): ?>
                                                <small>
                                                    <?= htmlspecialchars($processedTimeLabel) ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </td>


                                    <td>
                                        <div class="payroll-history-status">

                                            <?php if ($historyIsVoided): ?>

                                                <span class="payroll-status voided">Voided</span>

                                                <small>
                                                    <?= htmlspecialchars(
                                                        ($historyVoider
                                                            ? employeeDisplayName($historyVoider)
                                                            : 'User #' . (int) $historyRecord['voided_by'])
                                                        . ' · '
                                                        . formatEmployeeTimestamp($historyRecord['voided_at'])
                                                    ) ?>
                                                </small>

                                                <small
                                                    class="payroll-void-reason"
                                                    title="<?= htmlspecialchars((string) $historyRecord['void_reason']) ?>"
                                                >
                                                    <?= htmlspecialchars((string) $historyRecord['void_reason']) ?>
                                                </small>

                                            <?php else: ?>

                                                <span class="payroll-status active">Processed</span>

                                                <?php if ($canVoidPayroll): ?>
                                                    <button
                                                        type="button"
                                                        class="payroll-void-button"
                                                        data-void-payroll="<?= (int) $historyRecord['id'] ?>"
                                                        data-void-code="<?= htmlspecialchars(payrollRecordCode((int) $historyRecord['id'])) ?>"
                                                        data-void-summary="<?= htmlspecialchars(
                                                            $historyEmployeeName
                                                            . ' · '
                                                            . payrollDateLabel($historyRecord['period_start'])
                                                            . ' to '
                                                            . payrollDateLabel($historyRecord['period_end'])
                                                            . ' · Net ₱'
                                                            . number_format((float) $historyRecord['net_pay'], 2)
                                                        ) ?>"
                                                    >
                                                        <span class="material-symbols-rounded">block</span>
                                                        Void
                                                    </button>
                                                <?php endif; ?>

                                            <?php endif; ?>

                                        </div>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    </div>

</div>


<!-- =========================================================
     ADD / EDIT EMPLOYEE MODAL
========================================================= -->

<div class="payroll-modal" id="employeeModal" hidden>

    <button
        type="button"
        class="payroll-modal-backdrop"
        data-close-employee-modal
        aria-label="Close employee form"
    ></button>


    <div
        class="payroll-modal-card"
        role="dialog"
        aria-modal="true"
        aria-labelledby="employeeModalTitle"
    >

        <div class="payroll-modal-header">

            <div>
                <div class="payroll-modal-eyebrow">EMPLOYEE RECORD</div>
                <h3 id="employeeModalTitle">Add Employee</h3>
                <p id="employeeModalDescription">
                    Add an employee and their hourly rate. Linking a login is optional.
                </p>
            </div>

            <button
                type="button"
                class="payroll-modal-close"
                data-close-employee-modal
                aria-label="Close"
            >
                <span class="material-symbols-rounded">close</span>
            </button>

        </div>


        <form method="post" class="payroll-form" id="employeeForm">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
            >

            <input
                type="hidden"
                name="action"
                id="employeeFormAction"
                value="add_employee"
            >

            <input
                type="hidden"
                name="employee_id"
                id="employeeId"
                value=""
            >


            <div class="payroll-form-grid">

                <div class="payroll-field full">
                    <label for="employeeUserId">
                        User Account
                    </label>

                    <select
                        id="employeeUserId"
                        name="user_id"
                    >
                        <option value="">No login — staff without a POS account</option>

                        <?php foreach ($userMap as $userId => $user): ?>
                            <?php
                            $linkedEmployeeId = $employeeByUser[$userId] ?? null;
                            $accountName = employeeDisplayName($user);
                            $roleLabel = trim((string) ($user['role'] ?? ''));
                            ?>

                            <option
                                value="<?= $userId ?>"
                                data-linked-employee="<?= $linkedEmployeeId !== null ? (int) $linkedEmployeeId : '' ?>"
                            >
                                <?= htmlspecialchars($accountName) ?>
                                <?= $roleLabel !== '' ? ' — ' . htmlspecialchars($roleLabel) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <small class="payroll-field-help">
                        Optional. Link a CompAcc login if this employee signs in to the system
                        (one login per employee). Choosing one fills in the name below.
                    </small>
                </div>


                <div class="payroll-field">
                    <label for="employeeFirstName">
                        First Name <span>*</span>
                    </label>

                    <input
                        type="text"
                        id="employeeFirstName"
                        name="first_name"
                        maxlength="100"
                        required
                        autocomplete="given-name"
                    >
                </div>


                <div class="payroll-field">
                    <label for="employeeMiddleName">
                        Middle Name
                    </label>

                    <input
                        type="text"
                        id="employeeMiddleName"
                        name="middle_name"
                        maxlength="100"
                        autocomplete="additional-name"
                    >
                </div>


                <div class="payroll-field">
                    <label for="employeeLastName">
                        Last Name <span>*</span>
                    </label>

                    <input
                        type="text"
                        id="employeeLastName"
                        name="last_name"
                        maxlength="100"
                        required
                        autocomplete="family-name"
                    >
                </div>


                <div class="payroll-field">
                    <label for="employeeSuffix">
                        Suffix
                    </label>

                    <input
                        type="text"
                        id="employeeSuffix"
                        name="suffix"
                        maxlength="30"
                        placeholder="Jr., Sr., III..."
                    >
                </div>


                <div class="payroll-field">
                    <label for="employeePosition">
                        Position <span>*</span>
                    </label>

                    <input
                        type="text"
                        id="employeePosition"
                        name="position"
                        maxlength="100"
                        required
                        placeholder="e.g. Cashier"
                    >
                </div>


                <div class="payroll-field">
                    <label for="employeePayType">
                        Pay Type
                    </label>

                    <input
                        type="text"
                        id="employeePayType"
                        value="Hourly"
                        readonly
                    >

                    <small class="payroll-field-help">
                        Current payroll schema is configured for hours worked.
                    </small>
                </div>


                <div class="payroll-field">
                    <label for="employeePayRate">
                        Hourly Pay Rate <span>*</span>
                    </label>

                    <div class="payroll-money-input">
                        <span>₱</span>

                        <input
                            type="number"
                            id="employeePayRate"
                            name="pay_rate"
                            min="0"
                            step="0.01"
                            required
                            placeholder="0.00"
                        >
                    </div>
                </div>


                <div class="payroll-field">
                    <label for="employeeDateHired">
                        Date Hired
                    </label>

                    <input
                        type="date"
                        id="employeeDateHired"
                        name="date_hired"
                        max="<?= htmlspecialchars(date('Y-m-d')) ?>"
                    >
                </div>


                <div class="payroll-field">
                    <label for="employeeStatus">
                        Status <span>*</span>
                    </label>

                    <select
                        id="employeeStatus"
                        name="status"
                        required
                    >
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>

            </div>


            <div class="payroll-modal-footer">

                <button
                    type="button"
                    class="payroll-secondary-button"
                    data-close-employee-modal
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="payroll-primary-button"
                    id="employeeSubmitButton"
                >
                    <span class="material-symbols-rounded">save</span>
                    <span id="employeeSubmitLabel">Save Employee</span>
                </button>

            </div>

        </form>

    </div>

</div>


<?php if ($canVoidPayroll): ?>

<!-- =========================================================
     VOID PAYROLL MODAL (Admin only)
========================================================= -->

<div class="payroll-modal" id="voidPayrollModal" hidden>

    <button
        type="button"
        class="payroll-modal-backdrop"
        data-close-void-modal
        aria-label="Close void payroll"
    ></button>


    <div
        class="payroll-modal-card payroll-void-card"
        role="dialog"
        aria-modal="true"
        aria-labelledby="voidPayrollTitle"
    >

        <div class="payroll-modal-header">

            <div>
                <div class="payroll-modal-eyebrow">VOID PAYROLL</div>
                <h3 id="voidPayrollTitle">Void payroll?</h3>
                <p id="voidPayrollSummary"></p>
            </div>

            <button
                type="button"
                class="payroll-modal-close"
                data-close-void-modal
                aria-label="Close"
            >
                <span class="material-symbols-rounded">close</span>
            </button>

        </div>


        <form method="post" class="payroll-form" id="voidPayrollForm">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="void_payroll"
            >

            <input
                type="hidden"
                name="payroll_id"
                id="voidPayrollId"
                value=""
            >


            <div class="payroll-field full">

                <label for="voidPayrollReason">
                    Reason <span>*</span>
                </label>

                <textarea
                    id="voidPayrollReason"
                    name="void_reason"
                    rows="3"
                    maxlength="255"
                    required
                    placeholder="Example: Wrong hours entered (40 instead of 4)"
                ></textarea>

                <small class="payroll-field-help">
                    The record stays in Payroll History marked Voided and stops counting in
                    payroll totals, Reports, the Financial Summary and the Dashboard.
                    This cannot be undone. You can process the period again afterwards.
                </small>

            </div>


            <div class="payroll-modal-footer">

                <button
                    type="button"
                    class="payroll-secondary-button"
                    data-close-void-modal
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="payroll-primary-button payroll-danger-button"
                >
                    <span class="material-symbols-rounded">block</span>
                    Void Payroll
                </button>

            </div>

        </form>

    </div>

</div>

<?php endif; ?>


<script>
const employeeEditData = <?= json_encode(
    $employeeEditData,
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
) ?>;

const userAutofillData = <?= json_encode(
    $userAutofillData,
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
) ?>;

const employeeModal =
    document.getElementById('employeeModal');

const employeeForm =
    document.getElementById('employeeForm');

const employeeModalTitle =
    document.getElementById('employeeModalTitle');

const employeeModalDescription =
    document.getElementById('employeeModalDescription');

const employeeFormAction =
    document.getElementById('employeeFormAction');

const employeeIdInput =
    document.getElementById('employeeId');

const employeeUserId =
    document.getElementById('employeeUserId');

const employeeFirstName =
    document.getElementById('employeeFirstName');

const employeeMiddleName =
    document.getElementById('employeeMiddleName');

const employeeLastName =
    document.getElementById('employeeLastName');

const employeeSuffix =
    document.getElementById('employeeSuffix');

const employeePosition =
    document.getElementById('employeePosition');

const employeePayRate =
    document.getElementById('employeePayRate');

const employeeDateHired =
    document.getElementById('employeeDateHired');

const employeeStatus =
    document.getElementById('employeeStatus');

const employeeSubmitButton =
    document.getElementById('employeeSubmitButton');

const employeeSubmitLabel =
    document.getElementById('employeeSubmitLabel');


function setAvailableUserAccounts(currentEmployeeId = null) {
    Array.from(employeeUserId.options).forEach((option) => {
        if (!option.value) {
            option.disabled = false;
            return;
        }

        const linkedEmployee =
            option.dataset.linkedEmployee
                ? Number(option.dataset.linkedEmployee)
                : null;

        option.disabled =
            linkedEmployee !== null &&
            linkedEmployee !== Number(currentEmployeeId);
    });
}


function autofillEmployeeFromAccount() {
    const userId = employeeUserId.value;

    if (!userId || !userAutofillData[userId]) {
        return;
    }

    const user = userAutofillData[userId];

    employeeFirstName.value =
        user.first_name || '';

    employeeMiddleName.value =
        user.middle_name || '';

    employeeLastName.value =
        user.last_name || '';

    employeeSuffix.value =
        user.suffix || '';
}


function openEmployeeModal(mode, employee = null) {
    employeeForm.reset();

    employeeSubmitButton.disabled = false;

    if (mode === 'edit' && employee) {
        employeeModalTitle.textContent =
            'Edit Employee';

        employeeModalDescription.textContent =
            'Update employee information and payroll settings.';

        employeeFormAction.value =
            'update_employee';

        employeeIdInput.value =
            employee.id;

        setAvailableUserAccounts(employee.id);

        employeeUserId.value =
            employee.user_id || '';

        employeeFirstName.value =
            employee.first_name;

        employeeMiddleName.value =
            employee.middle_name;

        employeeLastName.value =
            employee.last_name;

        employeeSuffix.value =
            employee.suffix;

        employeePosition.value =
            employee.position;

        employeePayRate.value =
            employee.pay_rate;

        employeeDateHired.value =
            employee.date_hired;

        employeeStatus.value =
            employee.status;

        employeeSubmitLabel.textContent =
            'Update Employee';
    } else {
        employeeModalTitle.textContent =
            'Add Employee';

        employeeModalDescription.textContent =
            'Add an employee and their hourly rate. Linking a login is optional.';

        employeeFormAction.value =
            'add_employee';

        employeeIdInput.value =
            '';

        setAvailableUserAccounts(null);

        employeeStatus.value =
            'Active';

        employeeSubmitLabel.textContent =
            'Save Employee';
    }

    employeeModal.hidden = false;

    document.body.classList.add(
        'payroll-modal-open'
    );

    window.setTimeout(() => {
        employeeUserId.focus();
    }, 50);
}


function closeEmployeeModal() {
    employeeModal.hidden = true;

    document.body.classList.remove(
        'payroll-modal-open'
    );
}


const openAddEmployeeButton =
    document.getElementById('openAddEmployee');

if (openAddEmployeeButton) {
    openAddEmployeeButton.addEventListener(
        'click',
        () => {
            openEmployeeModal('add');
        }
    );
}


document
    .querySelectorAll('.edit-employee-button')
    .forEach((button) => {
        button.addEventListener('click', () => {
            const employee =
                employeeEditData[
                    button.dataset.employeeId
                ];

            if (employee) {
                openEmployeeModal(
                    'edit',
                    employee
                );
            }
        });
    });


document
    .querySelectorAll('[data-close-employee-modal]')
    .forEach((button) => {
        button.addEventListener(
            'click',
            closeEmployeeModal
        );
    });


employeeUserId.addEventListener(
    'change',
    autofillEmployeeFromAccount
);


/*
 * Employee status buttons use the global confirmation modal through
 * data-confirm-form (handled by /assets/js/ui.js). No page handler needed.
 */


employeeForm.addEventListener(
    'submit',
    (event) => {
        const adding =
            employeeFormAction.value === 'add_employee';

        const employeeName =
            `${employeeFirstName.value.trim()} ${employeeLastName.value.trim()}`.trim();

        /* Ask first; the form is sent again after the user confirms. */
        if (
            window.UA?.confirmSubmit &&
            !UA.confirmSubmit(event, {
                title: adding
                    ? `Add ${employeeName}?`
                    : `Save changes to ${employeeName}?`,
                message: adding
                    ? 'This employee will be added to the payroll list.'
                    : 'The employee details and pay rate will be updated. Payroll that was already processed keeps its amounts.',
                label: adding
                    ? 'Add Employee'
                    : 'Save Changes',
                icon: adding
                    ? 'person_add'
                    : 'badge'
            })
        ) {
            return;
        }

        employeeSubmitButton.disabled = true;

        employeeSubmitLabel.textContent =
            employeeFormAction.value === 'add_employee'
                ? 'Saving...'
                : 'Updating...';
    }
);


document.addEventListener(
    'keydown',
    (event) => {
        if (
            event.key === 'Escape' &&
            !employeeModal.hidden &&
            !window.UA?.isConfirmOpen()
        ) {
            closeEmployeeModal();
        }
    }
);


/*
|--------------------------------------------------------------------------
| SEARCH / FILTER
|--------------------------------------------------------------------------
*/

const employeeSearch =
    document.getElementById('employeeSearch');

const employeeStatusFilter =
    document.getElementById('employeeStatusFilter');

const employeeRows =
    Array.from(
        document.querySelectorAll('.employee-row')
    );

const filteredEmployeeEmpty =
    document.getElementById('filteredEmployeeEmpty');

const employeeVisibleCount =
    document.getElementById('employeeVisibleCount');

const employeeCountLabel =
    document.getElementById('employeeCountLabel');


function filterEmployees() {
    const query =
        employeeSearch.value
            .trim()
            .toLowerCase();

    const status =
        employeeStatusFilter.value;

    let visible = 0;

    employeeRows.forEach((row) => {
        const matchesQuery =
            query === '' ||
            row.dataset.search.includes(query);

        const matchesStatus =
            status === '' ||
            row.dataset.status === status;

        const show =
            matchesQuery &&
            matchesStatus;

        row.hidden =
            !show;

        if (show) {
            visible++;
        }
    });

    filteredEmployeeEmpty.hidden =
        employeeRows.length === 0 ||
        visible > 0;

    employeeVisibleCount.textContent =
        visible;

    employeeCountLabel.textContent =
        visible === 1
            ? 'employee'
            : 'employees';
}


employeeSearch.addEventListener(
    'input',
    filterEmployees
);

employeeStatusFilter.addEventListener(
    'change',
    filterEmployees
);


/*
|--------------------------------------------------------------------------
| PROCESS PAYROLL PREVIEW
|--------------------------------------------------------------------------
*/

const payrollProcessForm =
    document.getElementById('payrollProcessForm');

const payrollEmployeeId =
    document.getElementById('payrollEmployeeId');

const payrollPeriodStart =
    document.getElementById('payrollPeriodStart');

const payrollPeriodEnd =
    document.getElementById('payrollPeriodEnd');

const payrollHoursWorked =
    document.getElementById('payrollHoursWorked');

const payrollHourlyRate =
    document.getElementById('payrollHourlyRate');

const payrollGrossPay =
    document.getElementById('payrollGrossPay');

const payrollDeductions =
    document.getElementById('payrollDeductions');

const payrollNetPay =
    document.getElementById('payrollNetPay');

const payrollSelectedEmployee =
    document.getElementById('payrollSelectedEmployee');

const payrollSelectedAvatar =
    document.getElementById('payrollSelectedAvatar');

const payrollSelectedName =
    document.getElementById('payrollSelectedName');

const payrollSelectedMeta =
    document.getElementById('payrollSelectedMeta');

const payrollSelectedRate =
    document.getElementById('payrollSelectedRate');

const payrollProcessButton =
    document.getElementById('payrollProcessButton');

const payrollProcessButtonLabel =
    document.getElementById('payrollProcessButtonLabel');


function payrollNumber(value) {
    const parsed = Number.parseFloat(value);

    return Number.isFinite(parsed)
        ? parsed
        : 0;
}


function payrollMoney(value) {
    return payrollNumber(value).toLocaleString(
        'en-PH',
        {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }
    );
}


function updatePayrollEmployeePreview() {
    if (!payrollEmployeeId) {
        return;
    }

    const option =
        payrollEmployeeId.options[
            payrollEmployeeId.selectedIndex
        ];

    if (
        !option ||
        !option.value
    ) {
        payrollHourlyRate.value =
            '0.00';

        payrollSelectedEmployee.hidden =
            true;

        updatePayrollCalculation();

        return;
    }

    const rate =
        payrollNumber(
            option.dataset.payRate
        );

    const name =
        option.dataset.employeeName ||
        'Employee';

    const code =
        option.dataset.employeeCode ||
        '';

    const position =
        option.dataset.position ||
        '';

    payrollHourlyRate.value =
        payrollMoney(rate);

    payrollSelectedName.textContent =
        name;

    payrollSelectedMeta.textContent =
        [code, position]
            .filter(Boolean)
            .join(' • ');

    payrollSelectedRate.textContent =
        '₱' + payrollMoney(rate);

    payrollSelectedAvatar.textContent =
        name.trim().charAt(0).toUpperCase() ||
        'E';

    payrollSelectedEmployee.hidden =
        false;

    updatePayrollCalculation();
}


function updatePayrollCalculation() {
    if (
        !payrollEmployeeId ||
        !payrollHoursWorked ||
        !payrollDeductions
    ) {
        return;
    }

    const option =
        payrollEmployeeId.options[
            payrollEmployeeId.selectedIndex
        ];

    const rate =
        option && option.value
            ? payrollNumber(
                option.dataset.payRate
            )
            : 0;

    const hours =
        Math.max(
            0,
            payrollNumber(
                payrollHoursWorked.value
            )
        );

    const deductions =
        Math.max(
            0,
            payrollNumber(
                payrollDeductions.value
            )
        );

    const gross =
        Math.round(
            (hours * rate + Number.EPSILON)
            * 100
        ) / 100;

    const net =
        Math.round(
            (gross - deductions + Number.EPSILON)
            * 100
        ) / 100;

    payrollGrossPay.value =
        payrollMoney(gross);

    payrollNetPay.textContent =
        '₱' + payrollMoney(net);

    payrollNetPay.classList.toggle(
        'negative',
        net < 0
    );

    if (deductions > gross) {
        payrollDeductions.setCustomValidity(
            'Deductions cannot exceed gross pay.'
        );
    } else {
        payrollDeductions.setCustomValidity('');
    }

    updatePayrollHoursLimit();
}


/*
 * Same rule as the server: hours must be more than 0 and at most
 * 16 per calendar day in the payroll period.
 */
const PAYROLL_MAX_HOURS_PER_DAY =
    <?= PAYROLL_MAX_HOURS_PER_DAY ?>;

const payrollHoursHelp =
    document.getElementById('payrollHoursHelp');


function updatePayrollHoursLimit() {
    if (
        !payrollHoursWorked ||
        !payrollPeriodStart ||
        !payrollPeriodEnd
    ) {
        return;
    }

    const start =
        payrollPeriodStart.value;

    const end =
        payrollPeriodEnd.value;

    let maxHours =
        null;

    if (
        start &&
        end &&
        end >= start
    ) {
        const days =
            Math.round(
                (
                    Date.parse(end + 'T00:00:00Z')
                    - Date.parse(start + 'T00:00:00Z')
                ) / 86400000
            ) + 1;

        maxHours =
            days * PAYROLL_MAX_HOURS_PER_DAY;

        payrollHoursWorked.max =
            String(maxHours);

        payrollHoursHelp.textContent =
            `Up to ${maxHours} hours for this period `
            + `(${PAYROLL_MAX_HOURS_PER_DAY} h × ${days} ${days === 1 ? 'day' : 'days'}).`;

    } else {

        payrollHoursWorked.removeAttribute('max');

        payrollHoursHelp.textContent =
            `Up to ${PAYROLL_MAX_HOURS_PER_DAY} hours per day in the period.`;
    }

    const hours =
        payrollNumber(
            payrollHoursWorked.value
        );

    if (hours <= 0) {
        payrollHoursWorked.setCustomValidity(
            'Enter the hours worked (more than 0).'
        );
    } else if (
        maxHours !== null &&
        hours > maxHours
    ) {
        payrollHoursWorked.setCustomValidity(
            `Hours worked can be at most ${maxHours} for this period.`
        );
    } else {
        payrollHoursWorked.setCustomValidity('');
    }
}


function validatePayrollPeriod() {
    if (
        !payrollPeriodStart ||
        !payrollPeriodEnd
    ) {
        return;
    }

    payrollPeriodStart.setCustomValidity('');
    payrollPeriodEnd.setCustomValidity('');

    if (
        payrollPeriodStart.value &&
        payrollPeriodEnd.value &&
        payrollPeriodEnd.value <
            payrollPeriodStart.value
    ) {
        payrollPeriodEnd.setCustomValidity(
            'Period end cannot be before period start.'
        );
    }

    payrollPeriodEnd.min =
        payrollPeriodStart.value || '';

    updatePayrollHoursLimit();
}


if (payrollEmployeeId) {
    payrollEmployeeId.addEventListener(
        'change',
        updatePayrollEmployeePreview
    );
}


if (payrollHoursWorked) {
    payrollHoursWorked.addEventListener(
        'input',
        updatePayrollCalculation
    );
}


if (payrollDeductions) {
    payrollDeductions.addEventListener(
        'input',
        updatePayrollCalculation
    );
}


if (payrollPeriodStart) {
    payrollPeriodStart.addEventListener(
        'change',
        validatePayrollPeriod
    );
}


if (payrollPeriodEnd) {
    payrollPeriodEnd.addEventListener(
        'change',
        validatePayrollPeriod
    );
}


if (payrollProcessForm) {
    payrollProcessForm.addEventListener(
        'submit',
        (event) => {
            /*
             * Always stop the native submit. The form is submitted from
             * the global confirmation modal after the user confirms.
             */
            event.preventDefault();

            validatePayrollPeriod();
            updatePayrollCalculation();

            if (
                !payrollProcessForm.checkValidity()
            ) {
                payrollProcessForm.reportValidity();
                return;
            }

            if (payrollProcessButton?.disabled) {
                return;
            }

            const employeeOption =
                payrollEmployeeId.options[
                    payrollEmployeeId.selectedIndex
                ];

            const employeeName =
                employeeOption?.dataset.employeeName ||
                'this employee';

            const gross =
                payrollGrossPay.value || '0.00';

            const net =
                payrollNetPay.textContent || '₱0.00';

            window.UA.confirm({
                title: 'Process payroll?',
                message:
                    'Gross pay ₱' + gross
                    + ' · Net pay ' + net
                    + '. This will create a payroll record for '
                    + employeeName + '.',
                label: 'Process Payroll',
                icon: 'payments',
                onConfirm: () => {
                    window.UA.setLoading(
                        payrollProcessButton,
                        true
                    );

                    if (payrollProcessButtonLabel) {
                        payrollProcessButtonLabel.textContent =
                            'Processing...';
                    }

                    payrollProcessForm.submit();
                }
            });
        }
    );

    updatePayrollEmployeePreview();
    validatePayrollPeriod();
}


/*
|--------------------------------------------------------------------------
| VOID PAYROLL (Admin only)
|--------------------------------------------------------------------------
*/

const voidPayrollModal =
    document.getElementById('voidPayrollModal');

if (voidPayrollModal) {

    const voidPayrollId =
        document.getElementById('voidPayrollId');

    const voidPayrollTitle =
        document.getElementById('voidPayrollTitle');

    const voidPayrollSummary =
        document.getElementById('voidPayrollSummary');

    const voidPayrollReason =
        document.getElementById('voidPayrollReason');

    let voidReturnFocus =
        null;


    const openVoidPayroll = (button) => {
        voidReturnFocus =
            button;

        voidPayrollId.value =
            button.dataset.voidPayroll;

        voidPayrollTitle.textContent =
            `Void ${button.dataset.voidCode}?`;

        voidPayrollSummary.textContent =
            button.dataset.voidSummary;

        voidPayrollReason.value =
            '';

        voidPayrollModal.hidden =
            false;

        document.body.classList.add(
            'payroll-modal-open'
        );

        window.setTimeout(
            () => voidPayrollReason.focus(),
            50
        );
    };


    const closeVoidPayroll = () => {
        voidPayrollModal.hidden =
            true;

        document.body.classList.remove(
            'payroll-modal-open'
        );

        voidReturnFocus?.focus();
    };


    document
        .querySelectorAll('[data-void-payroll]')
        .forEach((button) => {
            button.addEventListener(
                'click',
                () => openVoidPayroll(button)
            );
        });

    voidPayrollModal
        .querySelectorAll('[data-close-void-modal]')
        .forEach((button) => {
            button.addEventListener(
                'click',
                closeVoidPayroll
            );
        });

    document.addEventListener(
        'keydown',
        (event) => {
            if (
                event.key === 'Escape' &&
                !voidPayrollModal.hidden &&
                !window.UA?.isConfirmOpen()
            ) {
                closeVoidPayroll();
            }
        }
    );
}

</script>

<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';

?>
