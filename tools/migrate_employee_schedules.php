<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/config/database.php';

/**
 * CompAcc POS - Employee Work Schedules Migration
 *
 * Purpose:
 * - Adds the work schedule to employees, so Process Payroll can fill in the
 *   hours by itself (work days x hours per day - absences - lateness):
 *     employment_type  Full-time or Part-time (existing employees: Full-time)
 *     hours_per_day    hours on a normal work day (existing employees: 8)
 *     rest_day         weekly rest day, 0 = Sunday ... 6 = Saturday
 *                      (existing employees: Sunday)
 *   Check each employee in Payroll -> Employees -> Edit afterwards.
 * - Adds to payroll what the hours were based on, for the payslip:
 *     scheduled_hours, days_absent, hours_late
 *   (empty for payroll processed before this update).
 *
 * Safe to run more than once: anything already there is kept.
 * Makes a backup of storage/database/pos.sqlite first.
 *
 * Run once from the project root:
 *   C:\php\php.exe tools\migrate_employee_schedules.php
 */

function scheduleColumns(PDO $pdo, string $table): array
{
    return array_column(
        $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(),
        'name'
    );
}

$dbPath = __DIR__ . '/../storage/database/pos.sqlite';
$backupDirectory = __DIR__ . '/../storage/backups';

if (!is_file($dbPath)) {
    fwrite(STDERR, "Database not found: {$dbPath}" . PHP_EOL);
    exit(1);
}

$employeeColumns = scheduleColumns($pdo, 'employees');
$payrollColumns = scheduleColumns($pdo, 'payroll');

$employeeChanges = array_filter([
    'employment_type' => "ALTER TABLE employees ADD COLUMN employment_type TEXT NOT NULL DEFAULT 'Full-time' CHECK (employment_type IN ('Full-time', 'Part-time'))",
    'hours_per_day' => 'ALTER TABLE employees ADD COLUMN hours_per_day REAL NOT NULL DEFAULT 8 CHECK (hours_per_day > 0 AND hours_per_day <= 16)',
    'rest_day' => 'ALTER TABLE employees ADD COLUMN rest_day INTEGER NOT NULL DEFAULT 0 CHECK (rest_day BETWEEN 0 AND 6)'
], static fn ($sql, $column) => !in_array($column, $employeeColumns, true), ARRAY_FILTER_USE_BOTH);

$payrollChanges = array_filter([
    'scheduled_hours' => 'ALTER TABLE payroll ADD COLUMN scheduled_hours REAL',
    'days_absent' => 'ALTER TABLE payroll ADD COLUMN days_absent REAL',
    'hours_late' => 'ALTER TABLE payroll ADD COLUMN hours_late REAL'
], static fn ($sql, $column) => !in_array($column, $payrollColumns, true), ARRAY_FILTER_USE_BOTH);

if ($employeeChanges === [] && $payrollChanges === []) {
    echo "Nothing to do: the work schedule columns already exist." . PHP_EOL;
    exit(0);
}

if (
    !is_dir($backupDirectory)
    && !mkdir($backupDirectory, 0775, true)
    && !is_dir($backupDirectory)
) {
    fwrite(STDERR, "Unable to create backup directory: {$backupDirectory}" . PHP_EOL);
    exit(1);
}

$backupPath =
    $backupDirectory
    . '/pos-before-employee-schedules-'
    . date('Ymd-His')
    . '.sqlite';

if (!copy($dbPath, $backupPath)) {
    fwrite(STDERR, "Unable to create database backup." . PHP_EOL);
    exit(1);
}

echo "Backup created:" . PHP_EOL;
echo $backupPath . PHP_EOL . PHP_EOL;

try {
    $pdo->exec('PRAGMA busy_timeout = 5000;');

    $pdo->beginTransaction();

    foreach ($employeeChanges + $payrollChanges as $column => $sql) {
        $pdo->exec($sql);
        echo "Added column {$column}." . PHP_EOL;
    }

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, PHP_EOL . 'Migration failed, nothing was changed: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Backup kept at: ' . $backupPath . PHP_EOL);
    exit(1);
}

$employees = (int) $pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn();

echo PHP_EOL . 'Integrity check: ' . $pdo->query('PRAGMA integrity_check')->fetchColumn() . PHP_EOL;
echo "Done. {$employees} employee(s) start as Full-time, 8 hours a day, rest day Sunday:" . PHP_EOL;
echo 'check each one in Payroll -> Employees -> Edit.' . PHP_EOL;
