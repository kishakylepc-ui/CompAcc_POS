<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/config/database.php';

/**
 * CompAcc POS - Product Code Migration
 *
 * Purpose:
 * - Add a parent-level product/style code (UA-0001, UA-0002, ...).
 * - Stop treating products.barcode as a sellable barcode.
 * - Keep products.barcode only as a legacy compatibility field.
 * - Preserve all variant SKUs/barcodes and historical sale-item barcodes.
 *
 * Run once from the project root:
 *   C:\php\php.exe tools\migrate_product_codes.php
 */

function productCodeColumnExists(PDO $pdo): bool
{
    $columns = $pdo->query('PRAGMA table_info(products)')->fetchAll();

    foreach ($columns as $column) {
        if ((string) $column['name'] === 'product_code') {
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
    . '/pos-before-product-codes-'
    . date('Ymd-His')
    . '.sqlite';

if (!copy($dbPath, $backupPath)) {
    fwrite(STDERR, "Unable to create database backup." . PHP_EOL);
    exit(1);
}

echo "CompAcc POS - Product Code Migration" . PHP_EOL;
echo "====================================" . PHP_EOL;
echo "Backup created:" . PHP_EOL;
echo $backupPath . PHP_EOL . PHP_EOL;

try {
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');

    $pdo->beginTransaction();

    if (!productCodeColumnExists($pdo)) {
        $pdo->exec("
            ALTER TABLE products
            ADD COLUMN product_code TEXT
        ");
    }

    /*
    |--------------------------------------------------------------------------
    | BACKFILL PRODUCT CODES
    |--------------------------------------------------------------------------
    |
    | Product codes identify the parent/style.
    | Variant barcodes remain the only sellable barcodes.
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
        UPDATE products
        SET product_code = printf('UA-%04d', id)
        WHERE product_code IS NULL
           OR TRIM(product_code) = ''
    ");

    /*
    |--------------------------------------------------------------------------
    | LEGACY products.barcode COMPATIBILITY
    |--------------------------------------------------------------------------
    |
    | Existing reports or older code may still read products.barcode.
    | Mirror the parent product code there, but new UI/code does not call it
    | a barcode and POS does not use it as a sellable barcode.
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
        UPDATE products
        SET barcode = product_code
        WHERE product_code IS NOT NULL
          AND TRIM(product_code) != ''
    ");

    $pdo->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS idx_products_product_code
        ON products(product_code)
    ");

    /*
    |--------------------------------------------------------------------------
    | ENFORCE A NON-EMPTY PRODUCT CODE ON THE MIGRATED DATABASE
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS trg_products_product_code_insert
        BEFORE INSERT ON products
        WHEN NEW.product_code IS NULL OR TRIM(NEW.product_code) = ''
        BEGIN
            SELECT RAISE(ABORT, 'Product code is required.');
        END
    ");

    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS trg_products_product_code_update
        BEFORE UPDATE OF product_code ON products
        WHEN NEW.product_code IS NULL OR TRIM(NEW.product_code) = ''
        BEGIN
            SELECT RAISE(ABORT, 'Product code is required.');
        END
    ");

    $pdo->commit();

    $products = $pdo->query("
        SELECT id, product_code
        FROM products
        ORDER BY id
    ")->fetchAll();

    echo "Migration completed successfully." . PHP_EOL . PHP_EOL;
    echo "Parent products now use Product / Style Codes:" . PHP_EOL;

    foreach ($products as $product) {
        echo '- Product #' . (int) $product['id']
            . ' => ' . (string) $product['product_code']
            . PHP_EOL;
    }

    echo PHP_EOL;
    echo "Identifier rule:" . PHP_EOL;
    echo "- Product Code = identifies the parent/style" . PHP_EOL;
    echo "- Variant SKU = identifies color/size in readable form" . PHP_EOL;
    echo "- Variant Barcode = the only sellable barcode" . PHP_EOL;
    echo PHP_EOL;
    echo "Historical sale item barcodes were not changed." . PHP_EOL;

} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, PHP_EOL . "MIGRATION FAILED" . PHP_EOL);
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
