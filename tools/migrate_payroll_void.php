<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/config/database.php';

/**
 * CompAcc POS - Payroll Void Migration
 *
 * Purpose:
 * - Let an Admin void a payroll record that was processed by mistake.
 * - Adds four columns to payroll:
 *     status       'Processed' (default for every existing record) or 'Voided'
 *     voided_by    user who voided it
 *     voided_at    when it was voided (UTC)
 *     void_reason  why it was voided
 * - Existing payroll records keep all their data and become 'Processed'.
 * - Voided records stay in Payroll History but no longer count in totals,
 *   Reports, the Financial Summary or the Dashboard.
 *
 * Safe to run more than once: columns that already exist are skipped.
 *
 * Run once from the project root:
 *   C:\php\php.exe tools\migrate_payroll_void.php
 */

function payrollColumnExists(PDO $pdo, string $column): bool
{
    $columns = $pdo->query('PRAGMA table_info(payroll)')->fetchAll();

    foreach ($columns as $existing) {
        if ((string) $existing['name'] === $column) {
            return true;
        }
    }

    return false;
}

$dbPath = __DIR__ . '/../storage/database/pos.sqlite';
$backupDirectory = __DIR__ . '/../storage/backups';

if (!is_file($dbPath)) {
    fwrite(STDERR, "Database not found: {$dbPath}" . PHP_EOL);
    exit(1);
}

echo "CompAcc POS - Payroll Void Migration" . PHP_EOL;
echo "====================================" . PHP_EOL;

$columnDefinitions = [
    'status' => "TEXT NOT NULL DEFAULT 'Processed' CHECK (status IN ('Processed', 'Voided'))",
    'voided_by' => 'INTEGER REFERENCES users(id)',
    'voided_at' => 'TEXT',
    'void_reason' => 'TEXT'
];

$missing = array_values(array_filter(
    array_keys($columnDefinitions),
    static fn (string $column): bool => !payrollColumnExists($pdo, $column)
));

if ($missing === []) {
    echo "Nothing to do: the payroll void columns already exist." . PHP_EOL;
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
    . '/pos-before-payroll-void-'
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

    foreach ($missing as $column) {
        $pdo->exec(
            "ALTER TABLE payroll ADD COLUMN {$column} "
            . $columnDefinitions[$column]
        );

        echo "Added column: payroll.{$column}" . PHP_EOL;
    }

    $pdo->commit();

    $count = (int) $pdo->query('SELECT COUNT(*) FROM payroll')->fetchColumn();

    echo PHP_EOL . "Done. {$count} existing payroll record(s) are marked Processed." . PHP_EOL;

} catch (Throwable $error) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . PHP_EOL);
    fwrite(STDERR, 'The database was not changed. Backup: ' . $backupPath . PHP_EOL);
    exit(1);
}
