<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/config/database.php';

/**
 * CompAcc POS - Split Payments Migration
 *
 * Purpose:
 * - Let one sale be paid in two parts, for example 600 by GCash and the
 *   remaining 400 in cash.
 * - Creates the table sale_payments: one row per payment part
 *   (sale, method, amount, reference number).
 * - Every existing sale gets one row copied from its current payment method,
 *   so Reports can read every sale from the same place.
 * - The sales table is NOT changed. sales.payment_method keeps holding the
 *   method with the biggest amount, and amount_tendered / change_amount keep
 *   meaning "cash received" and "change".
 *
 * Safe to run more than once: an existing table is kept and only sales that
 * have no payment row yet are filled in.
 *
 * Run once from the project root:
 *   C:\php\php.exe tools\migrate_split_payments.php
 */

function splitPaymentsTableExists(PDO $pdo): bool
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'sale_payments'"
    );
    $statement->execute();

    return (int) $statement->fetchColumn() > 0;
}

function splitPaymentsMissingRows(PDO $pdo): int
{
    return (int) $pdo->query(
        'SELECT COUNT(*) FROM sales WHERE id NOT IN (SELECT sale_id FROM sale_payments)'
    )->fetchColumn();
}

$dbPath = __DIR__ . '/../storage/database/pos.sqlite';
$backupDirectory = __DIR__ . '/../storage/backups';

if (!is_file($dbPath)) {
    fwrite(STDERR, "Database not found: {$dbPath}" . PHP_EOL);
    exit(1);
}

echo "CompAcc POS - Split Payments Migration" . PHP_EOL;
echo "======================================" . PHP_EOL;

$tableExists = splitPaymentsTableExists($pdo);

if ($tableExists && splitPaymentsMissingRows($pdo) === 0) {
    echo "Nothing to do: sale_payments already exists and every sale has a payment row." . PHP_EOL;
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
    . '/pos-before-split-payments-'
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
        CREATE TABLE IF NOT EXISTS sale_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER NOT NULL,
            payment_method TEXT NOT NULL
                CHECK (payment_method IN ('Cash', 'GCash', 'Maya', 'MariBank')),
            amount REAL NOT NULL CHECK (amount >= 0),
            payment_reference TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (sale_id)
                REFERENCES sales(id)
                ON DELETE CASCADE
        )
    SQL);

    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_sale_payments_sale ON sale_payments(sale_id)'
    );

    if (!$tableExists) {
        echo "Created table: sale_payments" . PHP_EOL;
    }

    $filled = $pdo->exec(<<<'SQL'
        INSERT INTO sale_payments (
            sale_id,
            payment_method,
            amount,
            payment_reference,
            created_at
        )
        SELECT
            id,
            payment_method,
            total_amount,
            payment_reference,
            created_at
        FROM sales
        WHERE id NOT IN (SELECT sale_id FROM sale_payments)
    SQL);

    $salesCount = (int) $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn();
    $paymentSales = (int) $pdo->query(
        'SELECT COUNT(DISTINCT sale_id) FROM sale_payments'
    )->fetchColumn();

    if ($paymentSales !== $salesCount) {
        throw new RuntimeException(
            "Check failed: {$salesCount} sale(s) but {$paymentSales} have a payment row."
        );
    }

    $pdo->commit();

    echo "Copied the payment of {$filled} existing sale(s) into sale_payments." . PHP_EOL;
    echo PHP_EOL . "Done. {$salesCount} sale(s) checked; every sale has a payment row." . PHP_EOL;

} catch (Throwable $error) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . PHP_EOL);
    fwrite(STDERR, 'The database was not changed. Backup: ' . $backupPath . PHP_EOL);
    exit(1);
}
