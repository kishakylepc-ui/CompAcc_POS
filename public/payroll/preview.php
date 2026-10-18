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
| PAYROLL PREVIEW (read-only JSON)
|--------------------------------------------------------------------------
|
| The Process Payroll form asks this page for the gross pay and the
| suggested deductions while the hours and dates are typed. Nothing is
| saved here; payroll/index.php works everything out again when the
| payroll is processed.
|
*/

header('Content-Type: application/json');


function payrollPreviewResponse(int $status, array $data): never
{
    http_response_code($status);

    echo json_encode($data);

    exit;
}


if (!payrollDeductionsReady($pdo)) {
    payrollPreviewResponse(409, [
        'ok' => false,
        'message' => 'Payroll deductions need a database update: run tools\\migrate_payroll_deductions.php.'
    ]);
}


$employeeId = (int) ($_GET['employee_id'] ?? 0);
$periodEnd = trim((string) ($_GET['period_end'] ?? ''));
$hoursInput = trim((string) ($_GET['hours'] ?? ''));

$hours = is_numeric($hoursInput)
    ? max(0.0, round((float) $hoursInput, 2))
    : 0.0;

$endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $periodEnd);

if ($employeeId <= 0 || $endDate === false || $endDate->format('Y-m-d') !== $periodEnd) {
    payrollPreviewResponse(422, [
        'ok' => false,
        'message' => 'Choose the employee and the period end date.'
    ]);
}


$employeeStatement = $pdo->prepare("
    SELECT pay_rate
    FROM employees
    WHERE id = ?
    LIMIT 1
");

$employeeStatement->execute([
    $employeeId
]);

$payRate = $employeeStatement->fetchColumn();

if ($payRate === false) {
    payrollPreviewResponse(404, [
        'ok' => false,
        'message' => 'Employee not found.'
    ]);
}


/* Same rounding as payroll/index.php when it saves. */
$grossPay = round($hours * round((float) $payRate, 2), 2);

$suggestion = payrollSuggestDeductions(
    $pdo,
    $employeeId,
    $periodEnd,
    $grossPay
);

payrollPreviewResponse(200, [
    'ok' => true,
    'gross' => $grossPay,
    'month_label' => $endDate->format('F Y'),
    'earlier_payrolls' => $suggestion['earlier_payrolls'],
    'gross_before' => $suggestion['gross_before'],
    'month_gross' => $suggestion['month_gross'],
    'lines' => array_values($suggestion['lines'])
]);
