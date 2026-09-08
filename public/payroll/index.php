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
| LOAD USER ACCOUNTS
|--------------------------------------------------------------------------
|
| employees.user_id is NOT NULL in the current CompAcc database.
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

        if ($userId <= 0 || !isset($userMap[$userId])) {
            employeeFlash('error', 'Please select a valid user account.');
            employeeRedirect();
        }

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

        $duplicateUser = $pdo->prepare("
            SELECT id
            FROM employees
            WHERE user_id = ?
              AND id != ?
            LIMIT 1
        ");

        $duplicateUser->execute([
            $userId,
            $action === 'update_employee' ? $employeeId : 0
        ]);

        if ($duplicateUser->fetch()) {
            employeeFlash(
                'error',
                'That user account is already connected to another employee.'
            );

            employeeRedirect();
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
                    $userId,
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
                    $userId,
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

        if (
            !validEmployeeDate($periodStart) ||
            !validEmployeeDate($periodEnd)
        ) {
            employeeFlash(
                'error',
                'Please enter a valid payroll period that is not in the future.'
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

        if ($hoursWorked < 0) {
            employeeFlash(
                'error',
                'Hours worked must be zero or greater.'
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
            | PREVENT EXACT DUPLICATE PERIOD
            |--------------------------------------------------------------------------
            */

            $duplicateStatement = $pdo->prepare("
                SELECT id
                FROM payroll
                WHERE employee_id = ?
                  AND period_start = ?
                  AND period_end = ?
                LIMIT 1
            ");

            $duplicateStatement->execute([
                $employeeId,
                $periodStart,
                $periodEnd
            ]);

            if ($duplicateStatement->fetch()) {
                throw new RuntimeException(
                    'Payroll for this employee and exact period has already been processed.'
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
        'user_id' => (int) $employee['user_id'],
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


$payrollSummaryStatement = $pdo->query("
    SELECT
        COUNT(*) AS total_records,
        COALESCE(SUM(gross_pay), 0) AS total_gross,
        COALESCE(SUM(deductions), 0) AS total_deductions,
        COALESCE(SUM(net_pay), 0) AS total_net
    FROM payroll
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
        e.status AS employee_status
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
$historyGrossTotal = 0.0;
$historyDeductionTotal = 0.0;
$historyNetTotal = 0.0;

foreach ($payrollHistory as $historyRecord) {
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

<link rel="stylesheet" href="/assets/css/payroll.css">

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
                            $linkedUser = $userMap[(int) $employee['user_id']] ?? [];
                            $linkedAccountName = $linkedUser
                                ? employeeDisplayName($linkedUser)
                                : 'User #' . (int) $employee['user_id'];

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

                                        <form method="post" class="payroll-inline-form">
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
                                                type="submit"
                                                class="payroll-icon-button"
                                                title="<?= $employee['status'] === 'Active' ? 'Deactivate' : 'Activate' ?> employee"
                                                data-confirm="<?= $employee['status'] === 'Active'
                                                    ? 'Deactivate this employee? Existing payroll history will remain.'
                                                    : 'Activate this employee?' ?>"
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
                                        min="0"
                                        step="0.01"
                                        value="0"
                                        required
                                    >
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
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (empty($payrollHistory)): ?>

                            <tr>
                                <td
                                    colspan="10"
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

                                <tr>

                                    <td>
                                        <div class="payroll-history-id">
                                            <strong>
                                                PAY-<?= str_pad(
                                                    (string) $historyRecord['id'],
                                                    5,
                                                    '0',
                                                    STR_PAD_LEFT
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
                                                    date(
                                                        'M d, Y',
                                                        strtotime($historyRecord['period_start'])
                                                    )
                                                ) ?>
                                            </strong>

                                            <span>to</span>

                                            <strong>
                                                <?= htmlspecialchars(
                                                    date(
                                                        'M d, Y',
                                                        strtotime($historyRecord['period_end'])
                                                    )
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
                    Link a system account and configure payroll information.
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
                        User Account <span>*</span>
                    </label>

                    <select
                        id="employeeUserId"
                        name="user_id"
                        required
                    >
                        <option value="">Select a user account</option>

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
                        Each employee must be connected to one CompAcc user account.
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
            employee.user_id;

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
            'Link a system account and configure payroll information.';

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


document
    .querySelectorAll('[data-confirm]')
    .forEach((button) => {
        button.addEventListener(
            'click',
            (event) => {
                if (
                    !window.confirm(
                        button.dataset.confirm
                    )
                ) {
                    event.preventDefault();
                }
            }
        );
    });


employeeForm.addEventListener(
    'submit',
    () => {
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
            !employeeModal.hidden
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
            validatePayrollPeriod();
            updatePayrollCalculation();

            if (
                !payrollProcessForm.checkValidity()
            ) {
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

            const confirmed =
                window.confirm(
                    'Process payroll for '
                    + employeeName
                    + '?\n\nGross Pay: ₱'
                    + gross
                    + '\nNet Pay: '
                    + net
                    + '\n\nThis will create a payroll record.'
                );

            if (!confirmed) {
                event.preventDefault();
                return;
            }

            if (payrollProcessButton) {
                payrollProcessButton.disabled =
                    true;
            }

            if (payrollProcessButtonLabel) {
                payrollProcessButtonLabel.textContent =
                    'Processing...';
            }
        }
    );

    updatePayrollEmployeePreview();
    validatePayrollPeriod();
}

</script>

<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';

?>
