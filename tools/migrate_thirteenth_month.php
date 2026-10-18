<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/config/database.php';

/**
 * CompAcc POS - 13th Month Pay Migration
 *
 * Purpose:
 * - Creates the table thirteenth_month_payments: each 13th month payment
 *   (employee, year, amount, date paid, who recorded it) with what it was
 *   based on at that moment (basic pay earned in the year, 13th month due,
 *   paid before). A payment made by mistake is voided by an Admin with a
 *   reason, never deleted.
 * - Nothing else changes. 13th month pay is worked out from the processed
 *   payroll already in the database (Payroll -> 13th Month).
 *
 * Safe to run more than once: anything already there is kept.
 * Makes a backup of storage/database/pos.sqlite first.
 *
 * Run once from the project root:
 *   C:\php\php.exe tools\migrate_thirteenth_month.php
 */

$dbPath = __DIR__ . '/../storage/database/pos.sqlite';
$backupDirectory = __DIR__ . '/../storage/backups';

if (!is_file($dbPath)) {
    fwrite(STDERR, "Database not found: {$dbPath}" . PHP_EOL);
    exit(1);
}

$exists =
    (int) $pdo->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'thirteenth_month_payments'"
    )->fetchColumn() > 0;

if ($exists) {
    echo "Nothing to do: thirteenth_month_payments already exists." . PHP_EOL;
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
    . '/pos-before-thirteenth-month-'
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

    $pdo->exec(<<<'SQL'
        CREATE TABLE thirteenth_month_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id INTEGER NOT NULL,
            year INTEGER NOT NULL CHECK (year BETWEEN 2000 AND 2100),
            basic_pay_total REAL NOT NULL DEFAULT 0 CHECK (basic_pay_total >= 0),
            due_amount REAL NOT NULL DEFAULT 0 CHECK (due_amount >= 0),
            paid_before REAL NOT NULL DEFAULT 0 CHECK (paid_before >= 0),
            amount REAL NOT NULL CHECK (amount > 0),
            paid_on TEXT NOT NULL,
            notes TEXT,
            processed_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status TEXT NOT NULL DEFAULT 'Paid'
                CHECK (status IN ('Paid', 'Voided')),
            voided_by INTEGER,
            voided_at TEXT,
            void_reason TEXT,
            FOREIGN KEY (employee_id) REFERENCES employees(id),
            FOREIGN KEY (processed_by) REFERENCES users(id),
            FOREIGN KEY (voided_by) REFERENCES users(id)
        )
    SQL);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_thirteenth_month_employee_year ON thirteenth_month_payments(employee_id, year)');

    echo "Created table thirteenth_month_payments." . PHP_EOL;

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
echo 'Done. Open Payroll -> 13th Month.' . PHP_EOL;
