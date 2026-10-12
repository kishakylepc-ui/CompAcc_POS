<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/config/database.php';

/**
 * CompAcc POS - Sale / Markdown / Clearance Prices Migration
 *
 * Purpose:
 * - Creates the table price_changes: every Sale, Markdown and Clearance
 *   price (product, color / size, regular price, new price, dates, reason,
 *   who set it, and whether it was stopped). It is also the price history.
 * - Adds two columns to sale_items:
 *     regular_price  the normal price at the time of the sale
 *     price_type     Regular, Sale or Clearance
 *   so receipts can show "was ₱1,499" and Reports can show how much the
 *   sale prices gave away. Older sale items stay Regular.
 * - Fills in sales.discount_amount for the few early PWD / Senior sales that
 *   only saved the percentage (discount = subtotal x percent, exactly what
 *   their totals already include). From now on Reports and receipts read the
 *   saved amount, because with sale prices it is no longer always 20 %.
 *
 * Safe to run more than once: anything already there is kept.
 * Makes a backup of storage/database/pos.sqlite first.
 *
 * Run once from the project root:
 *   C:\php\php.exe tools\migrate_price_changes.php
 */

function priceChangesTableExists(PDO $pdo): bool
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'price_changes'"
    )->fetchColumn() > 0;
}

function priceChangesSaleItemColumns(PDO $pdo): array
{
    return array_column(
        $pdo->query('PRAGMA table_info(sale_items)')->fetchAll(),
        'name'
    );
}

function priceChangesMissingDiscounts(PDO $pdo): int
{
    return (int) $pdo->query(
        'SELECT COUNT(*) FROM sales WHERE discount_percent > 0 AND discount_amount = 0'
    )->fetchColumn();
}

$dbPath = __DIR__ . '/../storage/database/pos.sqlite';
$backupDirectory = __DIR__ . '/../storage/backups';

if (!is_file($dbPath)) {
    fwrite(STDERR, "Database not found: {$dbPath}" . PHP_EOL);
    exit(1);
}

$columns = priceChangesSaleItemColumns($pdo);
$needsTable = !priceChangesTableExists($pdo);
$needsRegularPrice = !in_array('regular_price', $columns, true);
$needsPriceType = !in_array('price_type', $columns, true);

/* Only on the first run. Before sale prices existed a PWD / Senior discount
   was always subtotal x percent. After it, a sale can have a real 0 discount
   (the sale price was already lower), and that must never be changed. */
$missingDiscounts = ($needsRegularPrice || $needsPriceType)
    ? priceChangesMissingDiscounts($pdo)
    : 0;

if (!$needsTable && !$needsRegularPrice && !$needsPriceType && $missingDiscounts === 0) {
    echo "Nothing to do: price_changes and the sale_items price columns already exist." . PHP_EOL;
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
    . '/pos-before-price-changes-'
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
            CREATE TABLE price_changes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id INTEGER NOT NULL,
                variant_id INTEGER,
                change_type TEXT NOT NULL
                    CHECK (change_type IN ('Sale', 'Markdown', 'Clearance')),
                regular_price REAL NOT NULL CHECK (regular_price >= 0),
                new_price REAL NOT NULL CHECK (new_price >= 0),
                starts_on TEXT NOT NULL,
                ends_on TEXT,
                reason TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'Active'
                    CHECK (status IN ('Active', 'Stopped', 'Applied')),
                created_by INTEGER,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                stopped_by INTEGER,
                stopped_at TEXT,
                stop_reason TEXT,
                FOREIGN KEY (product_id)
                    REFERENCES products(id)
                    ON DELETE CASCADE,
                FOREIGN KEY (variant_id)
                    REFERENCES product_variants(id)
                    ON DELETE CASCADE,
                FOREIGN KEY (created_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL,
                FOREIGN KEY (stopped_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL
            )
        SQL);

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_price_changes_variant ON price_changes(variant_id, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_price_changes_product ON price_changes(product_id)');

        echo "Created table price_changes." . PHP_EOL;
    }

    if ($needsRegularPrice) {
        $pdo->exec('ALTER TABLE sale_items ADD COLUMN regular_price REAL');
        echo "Added sale_items.regular_price." . PHP_EOL;
    }

    if ($needsPriceType) {
        $pdo->exec("ALTER TABLE sale_items ADD COLUMN price_type TEXT NOT NULL DEFAULT 'Regular'");
        echo "Added sale_items.price_type (existing items: Regular)." . PHP_EOL;
    }

    if ($missingDiscounts > 0) {
        $pdo->exec(
            'UPDATE sales
             SET discount_amount = ROUND(subtotal * discount_percent / 100.0, 2)
             WHERE discount_percent > 0 AND discount_amount = 0'
        );
        echo "Filled in the discount amount of {$missingDiscounts} early PWD / Senior sale(s)." . PHP_EOL;
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
echo 'Done. Sale / Markdown / Clearance prices are ready (Inventory -> Prices).' . PHP_EOL;
