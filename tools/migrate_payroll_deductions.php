<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/helpers/payroll.php';

/**
 * CompAcc POS - Payroll Deductions Migration
 *
 * Purpose:
 * - Creates the table payroll_deductions: the deduction lines of each
 *   payroll (SSS, PhilHealth, Pag-IBIG, withholding tax and other lines such
 *   as a cash advance), with the amount the system suggested. The payslip
 *   shows these lines. payroll.deductions keeps the total, so Reports and
 *   the Financial Summary do not change.
 * - Saves the starting contribution rates and the BIR tax table in settings
 *   (payroll_* keys). An Admin keeps them up to date in Settings -> Payroll
 *   Deductions. Existing values are never overwritten.
 * - First run only: a payroll processed before this update that has
 *   deductions gets one "Deductions" line with that same amount, so its
 *   payslip shows it.
 *
 * Safe to run more than once: anything already there is kept.
 * Makes a backup of storage/database/pos.sqlite first.
 *
 * Run once from the project root:
 *   C:\php\php.exe tools\migrate_payroll_deductions.php
 */

function payrollDeductionsTableExists(PDO $pdo): bool
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'payroll_deductions'"
    )->fetchColumn() > 0;
}

function payrollMissingSettings(PDO $pdo): array
{
    $saved = $pdo->query("SELECT setting_key FROM settings")->fetchAll(PDO::FETCH_COLUMN);

    return array_diff_key(payrollDefaultSettings(), array_flip($saved));
}

$dbPath = __DIR__ . '/../storage/database/pos.sqlite';
$backupDirectory = __DIR__ . '/../storage/backups';

if (!is_file($dbPath)) {
    fwrite(STDERR, "Database not found: {$dbPath}" . PHP_EOL);
    exit(1);
}

$needsTable = !payrollDeductionsTableExists($pdo);
$missingSettings = payrollMissingSettings($pdo);

if (!$needsTable && $missingSettings === []) {
    echo "Nothing to do: payroll_deductions and the payroll deduction settings already exist." . PHP_EOL;
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
    . '/pos-before-payroll-deductions-'
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

    if ($needsTable) {
        $pdo->exec(<<<'SQL'
            CREATE TABLE payroll_deductions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                payroll_id INTEGER NOT NULL,
                deduction_type TEXT NOT NULL
                    CHECK (deduction_type IN ('SSS', 'PhilHealth', 'Pag-IBIG', 'Withholding Tax', 'Other')),
                label TEXT NOT NULL,
                amount REAL NOT NULL CHECK (amount >= 0),
                suggested_amount REAL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (payroll_id)
                    REFERENCES payroll(id)
                    ON DELETE CASCADE
            )
        SQL);

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_payroll_deductions_payroll ON payroll_deductions(payroll_id)');

        echo "Created table payroll_deductions." . PHP_EOL;

        /* Older payroll: one line with the amount it already deducted. */
        $older = $pdo->exec(
            "INSERT INTO payroll_deductions (payroll_id, deduction_type, label, amount, suggested_amount, sort_order)
             SELECT id, 'Other', 'Deductions', deductions, NULL, 1
             FROM payroll
             WHERE deductions > 0"
        );

        echo "Added a \"Deductions\" line to {$older} older payroll record(s)." . PHP_EOL;
    }

    if ($missingSettings !== []) {
        $insert = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)'
        );

        foreach ($missingSettings as $key => $value) {
            $insert->execute([$key, $value, 'Payroll deductions (Settings -> Payroll Deductions)']);
        }

        echo 'Saved ' . count($missingSettings) . ' payroll deduction setting(s) with their starting values.' . PHP_EOL;
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

$integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();

echo PHP_EOL . 'Integrity check: ' . $integrity . PHP_EOL;
echo 'Done. Check the starting rates in Settings -> Payroll Deductions.' . PHP_EOL;
