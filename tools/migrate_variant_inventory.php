<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/config/database.php';

/**
 * CompAcc POS - Variant Inventory Foundation Migration
 *
 * Purpose:
 * - Keep product_variants.stock_quantity as the authoritative stock source.
 * - Keep products.stock_quantity as a synchronized cached total for compatibility.
 * - Add proper variant foreign keys to sale_items and inventory_logs.
 * - Add stock_receipts and stock_receipt_items for supplier restocking.
 * - Preserve legacy sale_items / inventory_logs that have NULL variant_id.
 *
 * Run once from the project root:
 *   C:\php\php.exe tools\migrate_variant_inventory.php
 */

function tableExists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare("\n        SELECT 1\n        FROM sqlite_master\n        WHERE type = 'table'\n          AND name = ?\n        LIMIT 1\n    ");

    $statement->execute([$table]);

    return (bool) $statement->fetchColumn();
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();

    foreach ($rows as $row) {
        if ((string) $row['name'] === $column) {
            return true;
        }
    }

    return false;
}

function foreignKeyExists(
    PDO $pdo,
    string $table,
    string $fromColumn,
    string $targetTable,
    string $targetColumn = 'id'
): bool {
    $rows = $pdo->query('PRAGMA foreign_key_list(' . $table . ')')->fetchAll();

    foreach ($rows as $row) {
        if (
            (string) $row['from'] === $fromColumn
            && (string) $row['table'] === $targetTable
            && (string) $row['to'] === $targetColumn
        ) {
            return true;
        }
    }

    return false;
}

function scalarInt(PDO $pdo, string $sql): int
{
    return (int) $pdo->query($sql)->fetchColumn();
}

function uniqueVariantBarcode(PDO $pdo, int $productId, string $preferred): string
{
    $candidate = trim($preferred);

    if ($candidate === '') {
        $candidate = 'PRODUCT-' . $productId;
    }

    $statement = $pdo->prepare("\n        SELECT 1\n        FROM product_variants\n        WHERE barcode = ?\n        LIMIT 1\n    ");

    $statement->execute([$candidate]);

    if (!$statement->fetchColumn()) {
        return $candidate;
    }

    $base = $candidate . '-V' . $productId;
    $candidate = $base;
    $counter = 1;

    while (true) {
        $statement->execute([$candidate]);

        if (!$statement->fetchColumn()) {
            return $candidate;
        }

        $candidate = $base . '-' . $counter;
        $counter++;
    }
}

function uniqueVariantSku(PDO $pdo, int $productId): string
{
    $base = 'PRODUCT-' . $productId . '-DEFAULT';
    $candidate = $base;
    $counter = 1;

    $statement = $pdo->prepare("\n        SELECT 1\n        FROM product_variants\n        WHERE sku = ?\n        LIMIT 1\n    ");

    while (true) {
        $statement->execute([$candidate]);

        if (!$statement->fetchColumn()) {
            return $candidate;
        }

        $candidate = $base . '-' . $counter;
        $counter++;
    }
}

$dbPath = __DIR__ . '/../storage/database/pos.sqlite';
$backupDirectory = __DIR__ . '/../storage/backups';

if (!is_file($dbPath)) {
    fwrite(STDERR, "Database not found: {$dbPath}" . PHP_EOL);
    exit(1);
}

if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0775, true) && !is_dir($backupDirectory)) {
    fwrite(STDERR, "Unable to create backup directory: {$backupDirectory}" . PHP_EOL);
    exit(1);
}

$backupPath = $backupDirectory
    . '/pos-before-variant-inventory-'
    . date('Ymd-His')
    . '.sqlite';

if (!copy($dbPath, $backupPath)) {
    fwrite(STDERR, "Unable to create database backup." . PHP_EOL);
    exit(1);
}

echo "CompAcc POS - Variant Inventory Foundation Migration" . PHP_EOL;
echo "====================================================" . PHP_EOL;
echo "Backup created:" . PHP_EOL;
echo $backupPath . PHP_EOL . PHP_EOL;

try {
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');

    if (!tableExists($pdo, 'products')) {
        throw new RuntimeException('products table is missing.');
    }

    if (!tableExists($pdo, 'product_variants')) {
        throw new RuntimeException(
            'product_variants table is missing. Run the current project database setup before this migration.'
        );
    }

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | 1. CREATE A DEFAULT VARIANT FOR ANY LEGACY PRODUCT WITHOUT VARIANTS
    |--------------------------------------------------------------------------
    */

    $legacyProducts = $pdo->query("\n        SELECT\n            p.id,\n            p.barcode,\n            p.stock_quantity,\n            p.status\n        FROM products p\n        WHERE NOT EXISTS (\n            SELECT 1\n            FROM product_variants pv\n            WHERE pv.product_id = p.id\n        )\n        ORDER BY p.id ASC\n    ")->fetchAll();

    $insertDefaultVariant = $pdo->prepare("\n        INSERT INTO product_variants (\n            product_id,\n            color,\n            color_hex,\n            size,\n            sku,\n            barcode,\n            stock_quantity,\n            status,\n            image_path\n        )\n        VALUES (?, 'Default', NULL, 'One Size', ?, ?, ?, ?, NULL)\n    ");

    foreach ($legacyProducts as $legacyProduct) {
        $productId = (int) $legacyProduct['id'];
        $sku = uniqueVariantSku($pdo, $productId);
        $barcode = uniqueVariantBarcode(
            $pdo,
            $productId,
            (string) $legacyProduct['barcode']
        );

        $insertDefaultVariant->execute([
            $productId,
            $sku,
            $barcode,
            max(0, (int) $legacyProduct['stock_quantity']),
            (string) $legacyProduct['status'] === 'Inactive' ? 'Inactive' : 'Active'
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. STOCK RECEIPTS
    |--------------------------------------------------------------------------
    */

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS stock_receipts (\n            id INTEGER PRIMARY KEY AUTOINCREMENT,\n\n            receipt_no TEXT NOT NULL UNIQUE,\n\n            supplier_id INTEGER NOT NULL,\n            received_by INTEGER NOT NULL,\n\n            total_cost REAL NOT NULL DEFAULT 0\n                CHECK (total_cost >= 0),\n\n            notes TEXT,\n\n            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n\n            FOREIGN KEY (supplier_id)\n                REFERENCES suppliers(id),\n\n            FOREIGN KEY (received_by)\n                REFERENCES users(id)\n        )\n    ");

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS stock_receipt_items (\n            id INTEGER PRIMARY KEY AUTOINCREMENT,\n\n            stock_receipt_id INTEGER NOT NULL,\n            product_id INTEGER NOT NULL,\n            variant_id INTEGER NOT NULL,\n\n            quantity INTEGER NOT NULL\n                CHECK (quantity > 0),\n\n            unit_cost REAL NOT NULL DEFAULT 0\n                CHECK (unit_cost >= 0),\n\n            line_total REAL NOT NULL DEFAULT 0\n                CHECK (line_total >= 0),\n\n            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n\n            FOREIGN KEY (stock_receipt_id)\n                REFERENCES stock_receipts(id)\n                ON DELETE CASCADE,\n\n            FOREIGN KEY (product_id)\n                REFERENCES products(id),\n\n            FOREIGN KEY (variant_id)\n                REFERENCES product_variants(id)\n        )\n    ");

    /*
    |--------------------------------------------------------------------------
    | 3. REBUILD SALE ITEMS WITH A REAL VARIANT FOREIGN KEY
    |--------------------------------------------------------------------------
    */

    $saleItemsNeedRebuild =
        !columnExists($pdo, 'sale_items', 'variant_id')
        || !foreignKeyExists($pdo, 'sale_items', 'variant_id', 'product_variants');

    if ($saleItemsNeedRebuild) {
        $pdo->exec('DROP TABLE IF EXISTS sale_items_variant_migration');

        $pdo->exec("\n            CREATE TABLE sale_items_variant_migration (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n\n                sale_id INTEGER NOT NULL,\n                product_id INTEGER NOT NULL,\n                variant_id INTEGER,\n\n                color TEXT,\n                size TEXT,\n                variant_sku TEXT,\n\n                barcode TEXT NOT NULL,\n                product_name TEXT NOT NULL,\n\n                quantity INTEGER NOT NULL\n                    CHECK (quantity > 0),\n\n                unit_price REAL NOT NULL\n                    CHECK (unit_price >= 0),\n\n                line_total REAL NOT NULL\n                    CHECK (line_total >= 0),\n\n                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n\n                FOREIGN KEY (sale_id)\n                    REFERENCES sales(id)\n                    ON DELETE CASCADE,\n\n                FOREIGN KEY (product_id)\n                    REFERENCES products(id),\n\n                FOREIGN KEY (variant_id)\n                    REFERENCES product_variants(id)\n                    ON DELETE SET NULL\n            )\n        ");

        $hasVariantId = columnExists($pdo, 'sale_items', 'variant_id');
        $hasColor = columnExists($pdo, 'sale_items', 'color');
        $hasSize = columnExists($pdo, 'sale_items', 'size');
        $hasVariantSku = columnExists($pdo, 'sale_items', 'variant_sku');

        $pdo->exec("\n            INSERT INTO sale_items_variant_migration (\n                id, sale_id, product_id, variant_id, color, size, variant_sku,\n                barcode, product_name, quantity, unit_price, line_total, created_at\n            )\n            SELECT\n                id,\n                sale_id,\n                product_id,\n                " . ($hasVariantId ? 'variant_id' : 'NULL') . ",\n                " . ($hasColor ? 'color' : 'NULL') . ",\n                " . ($hasSize ? 'size' : 'NULL') . ",\n                " . ($hasVariantSku ? 'variant_sku' : 'NULL') . ",\n                barcode,\n                product_name,\n                quantity,\n                unit_price,\n                line_total,\n                created_at\n            FROM sale_items\n            ORDER BY id ASC\n        ");

        $pdo->exec('DROP TABLE sale_items');
        $pdo->exec('ALTER TABLE sale_items_variant_migration RENAME TO sale_items');
    }

    /*
    |--------------------------------------------------------------------------
    | 4. REBUILD INVENTORY LOGS FOR VARIANT-LEVEL AUDIT + STOCK RECEIPT LINK
    |--------------------------------------------------------------------------
    */

    $inventoryLogsNeedRebuild =
        !columnExists($pdo, 'inventory_logs', 'variant_id')
        || !columnExists($pdo, 'inventory_logs', 'stock_receipt_id')
        || !foreignKeyExists($pdo, 'inventory_logs', 'variant_id', 'product_variants')
        || !foreignKeyExists($pdo, 'inventory_logs', 'stock_receipt_id', 'stock_receipts');

    if ($inventoryLogsNeedRebuild) {
        $pdo->exec('DROP TABLE IF EXISTS inventory_logs_variant_migration');

        $pdo->exec("\n            CREATE TABLE inventory_logs_variant_migration (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n\n                product_id INTEGER NOT NULL,\n                variant_id INTEGER,\n\n                user_id INTEGER,\n                supplier_id INTEGER,\n                sale_id INTEGER,\n                stock_receipt_id INTEGER,\n\n                action TEXT NOT NULL\n                    CHECK (\n                        action IN (\n                            'Initial Stock',\n                            'Restock',\n                            'Sale',\n                            'Adjustment',\n                            'Void Return',\n                            'Damaged',\n                            'Expired'\n                        )\n                    ),\n\n                color TEXT,\n                size TEXT,\n\n                quantity_change INTEGER NOT NULL,\n                previous_stock INTEGER NOT NULL,\n                new_stock INTEGER NOT NULL,\n\n                notes TEXT,\n\n                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n\n                FOREIGN KEY (product_id)\n                    REFERENCES products(id),\n\n                FOREIGN KEY (variant_id)\n                    REFERENCES product_variants(id)\n                    ON DELETE SET NULL,\n\n                FOREIGN KEY (user_id)\n                    REFERENCES users(id),\n\n                FOREIGN KEY (supplier_id)\n                    REFERENCES suppliers(id),\n\n                FOREIGN KEY (sale_id)\n                    REFERENCES sales(id),\n\n                FOREIGN KEY (stock_receipt_id)\n                    REFERENCES stock_receipts(id)\n                    ON DELETE SET NULL\n            )\n        ");

        $hasVariantId = columnExists($pdo, 'inventory_logs', 'variant_id');
        $hasColor = columnExists($pdo, 'inventory_logs', 'color');
        $hasSize = columnExists($pdo, 'inventory_logs', 'size');
        $hasStockReceiptId = columnExists($pdo, 'inventory_logs', 'stock_receipt_id');

        $pdo->exec("\n            INSERT INTO inventory_logs_variant_migration (\n                id, product_id, variant_id, user_id, supplier_id, sale_id,\n                stock_receipt_id, action, color, size, quantity_change,\n                previous_stock, new_stock, notes, created_at\n            )\n            SELECT\n                id,\n                product_id,\n                " . ($hasVariantId ? 'variant_id' : 'NULL') . ",\n                user_id,\n                supplier_id,\n                sale_id,\n                " . ($hasStockReceiptId ? 'stock_receipt_id' : 'NULL') . ",\n                action,\n                " . ($hasColor ? 'color' : 'NULL') . ",\n                " . ($hasSize ? 'size' : 'NULL') . ",\n                quantity_change,\n                previous_stock,\n                new_stock,\n                notes,\n                created_at\n            FROM inventory_logs\n            ORDER BY id ASC\n        ");

        $pdo->exec('DROP TABLE inventory_logs');
        $pdo->exec('ALTER TABLE inventory_logs_variant_migration RENAME TO inventory_logs');
    }

    /*
    |--------------------------------------------------------------------------
    | 5. INDEXES
    |--------------------------------------------------------------------------
    */

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_product_variants_product_id ON product_variants(product_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_product_variants_status ON product_variants(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sale_items_sale ON sale_items(sale_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sale_items_product ON sale_items(product_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sale_items_variant ON sale_items(variant_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_inventory_product ON inventory_logs(product_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_inventory_variant ON inventory_logs(variant_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_inventory_receipt ON inventory_logs(stock_receipt_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stock_receipts_supplier ON stock_receipts(supplier_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stock_receipts_created ON stock_receipts(created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stock_receipt_items_receipt ON stock_receipt_items(stock_receipt_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stock_receipt_items_product ON stock_receipt_items(product_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stock_receipt_items_variant ON stock_receipt_items(variant_id)");

    /*
    |--------------------------------------------------------------------------
    | 6. PRODUCT STOCK CACHE TRIGGERS
    |--------------------------------------------------------------------------
    |
    | product_variants.stock_quantity is the source of truth.
    | products.stock_quantity stays synchronized for current reports/UI.
    |
    */

    $pdo->exec('DROP TRIGGER IF EXISTS trg_variant_stock_after_insert');
    $pdo->exec('DROP TRIGGER IF EXISTS trg_variant_stock_after_update');
    $pdo->exec('DROP TRIGGER IF EXISTS trg_variant_stock_after_delete');

    $pdo->exec("\n        CREATE TRIGGER trg_variant_stock_after_insert\n        AFTER INSERT ON product_variants\n        BEGIN\n            UPDATE products\n            SET\n                stock_quantity = (\n                    SELECT COALESCE(SUM(stock_quantity), 0)\n                    FROM product_variants\n                    WHERE product_id = NEW.product_id\n                ),\n                updated_at = CURRENT_TIMESTAMP\n            WHERE id = NEW.product_id;\n        END\n    ");

    $pdo->exec("\n        CREATE TRIGGER trg_variant_stock_after_update\n        AFTER UPDATE OF stock_quantity, product_id ON product_variants\n        BEGIN\n            UPDATE products\n            SET\n                stock_quantity = (\n                    SELECT COALESCE(SUM(stock_quantity), 0)\n                    FROM product_variants\n                    WHERE product_id = OLD.product_id\n                ),\n                updated_at = CURRENT_TIMESTAMP\n            WHERE id = OLD.product_id;\n\n            UPDATE products\n            SET\n                stock_quantity = (\n                    SELECT COALESCE(SUM(stock_quantity), 0)\n                    FROM product_variants\n                    WHERE product_id = NEW.product_id\n                ),\n                updated_at = CURRENT_TIMESTAMP\n            WHERE id = NEW.product_id;\n        END\n    ");

    $pdo->exec("\n        CREATE TRIGGER trg_variant_stock_after_delete\n        AFTER DELETE ON product_variants\n        BEGIN\n            UPDATE products\n            SET\n                stock_quantity = (\n                    SELECT COALESCE(SUM(stock_quantity), 0)\n                    FROM product_variants\n                    WHERE product_id = OLD.product_id\n                ),\n                updated_at = CURRENT_TIMESTAMP\n            WHERE id = OLD.product_id;\n        END\n    ");

    /*
    |--------------------------------------------------------------------------
    | 7. RECALCULATE EVERY PRODUCT CACHE NOW
    |--------------------------------------------------------------------------
    */

    $pdo->exec("\n        UPDATE products\n        SET stock_quantity = (\n            SELECT COALESCE(SUM(pv.stock_quantity), 0)\n            FROM product_variants pv\n            WHERE pv.product_id = products.id\n        )\n    ");

    /*
    |--------------------------------------------------------------------------
    | 8. FOREIGN KEY CHECK
    |--------------------------------------------------------------------------
    */

    $foreignKeyProblems = $pdo->query('PRAGMA foreign_key_check')->fetchAll();

    if ($foreignKeyProblems !== []) {
        throw new RuntimeException(
            'Foreign key validation failed after migration. The transaction was rolled back.'
        );
    }

    $pdo->commit();

    $productCount = scalarInt($pdo, 'SELECT COUNT(*) FROM products');
    $variantCount = scalarInt($pdo, 'SELECT COUNT(*) FROM product_variants');
    $legacySaleItemCount = scalarInt($pdo, 'SELECT COUNT(*) FROM sale_items WHERE variant_id IS NULL');
    $legacyInventoryLogCount = scalarInt($pdo, 'SELECT COUNT(*) FROM inventory_logs WHERE variant_id IS NULL');

    echo "Migration completed successfully." . PHP_EOL . PHP_EOL;
    echo "Products: {$productCount}" . PHP_EOL;
    echo "Variants: {$variantCount}" . PHP_EOL;
    echo "Legacy sale items kept with NULL variant_id: {$legacySaleItemCount}" . PHP_EOL;
    echo "Legacy inventory logs kept with NULL variant_id: {$legacyInventoryLogCount}" . PHP_EOL;
    echo PHP_EOL;
    echo "New tables:" . PHP_EOL;
    echo "- stock_receipts" . PHP_EOL;
    echo "- stock_receipt_items" . PHP_EOL;
    echo PHP_EOL;
    echo "Stock rule:" . PHP_EOL;
    echo "- product_variants.stock_quantity = source of truth" . PHP_EOL;
    echo "- products.stock_quantity = synchronized cached total" . PHP_EOL;
    echo PHP_EOL;
    echo "Next: update Inventory restocking to create stock receipts." . PHP_EOL;

} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, PHP_EOL . "MIGRATION FAILED" . PHP_EOL);
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    fwrite(STDERR, PHP_EOL . "Your backup is still available at:" . PHP_EOL);
    fwrite(STDERR, $backupPath . PHP_EOL);
    fwrite(STDERR, PHP_EOL . "If SQLite reports that the database is locked, close DB Browser for SQLite and try again." . PHP_EOL);
    exit(1);
}
