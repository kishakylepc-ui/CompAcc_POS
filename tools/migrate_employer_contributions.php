<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/helpers/payroll.php';

/**
 * CompAcc POS - Employer Contributions Migration
 *
 * Purpose:
 * - Creates the table payroll_employer_shares: what the shop pays ON TOP of
 *   each payroll (SSS employer share, SSS EC, PhilHealth and Pag-IBIG
 *   employer shares). It is never deducted from the employee.
 * - Adds payroll.employer_contributions (the total of those lines), counted
 *   as a payroll cost in Expenses and the Financial Summary. Payroll
 *   processed before this update keeps 0 (nothing is guessed for it).
 * - Saves the starting employer rates in settings (payroll_* keys). An Admin
 *   keeps them up to date in Settings -> Payroll Deductions. Existing values
 *   are never overwritten.
 *
 * Safe to run more than once: anything already there is kept.
 * Makes a backup of storage/database/pos.sqlite first.
 *
 * Run once from the project root:
 *   C:\php\php.exe tools\migrate_employer_contributions.php
 */

$dbPath = __DIR__ . '/../storage/database/pos.sqlite';
$backupDirectory = __DIR__ . '/../storage/backups';

if (!is_file($dbPath)) {
    fwrite(STDERR, "Database not found: {$dbPath}" . PHP_EOL);
    exit(1);
}

$needsTable =
    (int) $pdo->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'payroll_employer_shares'"
    )->fetchColumn() === 0;

$needsColumn = !in_array(
    'employer_contributions',
    array_column($pdo->query('PRAGMA table_info(payroll)')->fetchAll(), 'name'),
    true
);

$savedKeys = $pdo->query('SELECT setting_key FROM settings')->fetchAll(PDO::FETCH_COLUMN);
$missingSettings = array_diff_key(payrollEmployerDefaultSettings(), array_flip($savedKeys));

if (!$needsTable && !$needsColumn && $missingSettings === []) {
    echo "Nothing to do: payroll_employer_shares, payroll.employer_contributions and the employer rates already exist." . PHP_EOL;
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
    . '/pos-before-employer-contributions-'
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
            CREATE TABLE payroll_employer_shares (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                payroll_id INTEGER NOT NULL,
                share_type TEXT NOT NULL
                    CHECK (share_type IN ('SSS', 'SSS EC', 'PhilHealth', 'Pag-IBIG')),
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

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_payroll_employer_shares_payroll ON payroll_employer_shares(payroll_id)');

        echo "Created table payroll_employer_shares." . PHP_EOL;
    }

    if ($needsColumn) {
        $pdo->exec('ALTER TABLE payroll ADD COLUMN employer_contributions REAL NOT NULL DEFAULT 0 CHECK (employer_contributions >= 0)');

        echo "Added column payroll.employer_contributions (0 for payroll processed before this update)." . PHP_EOL;
    }

    if ($missingSettings !== []) {
        $insert = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)'
        );

        foreach ($missingSettings as $key => $value) {
            $insert->execute([$key, $value, 'Employer contributions (Settings -> Payroll Deductions)']);
        }

        echo 'Saved ' . count($missingSettings) . ' employer rate setting(s) with their starting values.' . PHP_EOL;
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
echo 'Done. Check the employer rates in Settings -> Payroll Deductions.' . PHP_EOL;
