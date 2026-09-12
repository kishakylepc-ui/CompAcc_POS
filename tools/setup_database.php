<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/config/database.php';

echo "Setting up CompAcc POS database..." . PHP_EOL;

try {
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | USERS
    |--------------------------------------------------------------------------
    */

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS users (\n            id INTEGER PRIMARY KEY AUTOINCREMENT,\n\n            username TEXT NOT NULL UNIQUE,\n            password TEXT NOT NULL,\n\n            first_name TEXT NOT NULL,\n            middle_name TEXT,\n            last_name TEXT NOT NULL,\n            suffix TEXT,\n\n            role TEXT NOT NULL\n                CHECK (role IN ('Admin', 'Manager', 'Cashier')),\n\n            status TEXT NOT NULL DEFAULT 'Active'\n                CHECK (status IN ('Active', 'Inactive')),\n\n            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP\n        )\n    ");

    /*
    |--------------------------------------------------------------------------
    | SETTINGS
    |--------------------------------------------------------------------------
    */

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS settings (\n            id INTEGER PRIMARY KEY AUTOINCREMENT,\n            setting_key TEXT NOT NULL UNIQUE,\n            setting_value TEXT NOT NULL,\n            description TEXT,\n            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP\n        )\n    ");

    /*
    |--------------------------------------------------------------------------
    | TAX RATES
    |--------------------------------------------------------------------------
    */

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS tax_rates (\n            id INTEGER PRIMARY KEY AUTOINCREMENT,\n            name TEXT NOT NULL,\n            rate REAL NOT NULL CHECK (rate >= 0),\n            is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),\n            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP\n        )\n    ");

    /*
    |--------------------------------------------------------------------------
    | CATEGORIES
    |--------------------------------------------------------------------------
    */

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS categories (\n            id INTEGER PRIMARY KEY AUTOINCREMENT,\n            name TEXT NOT NULL UNIQUE,\n            description TEXT,\n            status TEXT NOT NULL DEFAULT 'Active'\n                CHECK (status IN ('Active', 'Inactive')),\n            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP\n        )\n    ");

    /*
    |--------------------------------------------------------------------------
    | SUPPLIERS
    |--------------------------------------------------------------------------
    */

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS suppliers (\n            id INTEGER PRIMARY KEY AUTOINCREMENT,\n            supplier_name TEXT NOT NULL,\n            contact_person TEXT,\n            phone TEXT,\n            email TEXT,\n            address TEXT,\n            status TEXT NOT NULL DEFAULT 'Active'\n                CHECK (status IN ('Active', 'Inactive')),\n            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP\n        )\n    ");

    /*
    |--------------------------------------------------------------------------
    | PRODUCTS
    |--------------------------------------------------------------------------
    |
    | products.stock_quantity is kept as a cached total for compatibility.
    | product_variants.stock_quantity is the authoritative stock source.
    |
    */

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS products (\n            id INTEGER PRIMARY KEY AUTOINCREMENT,\n            barcode TEXT NOT NULL UNIQUE,\n            product_name TEXT NOT NULL,\n            category_id INTEGER,\n            cost_price REAL NOT NULL DEFAULT 0 CHECK (cost_price >= 0),\n            selling_price REAL NOT NULL DEFAULT 0 CHECK (selling_price >= 0),\n            stock_quantity INTEGER NOT NULL DEFAULT 0 CHECK (stock_quantity >= 0),\n            reorder_level INTEGER NOT NULL DEFAULT 10 CHECK (reorder_level >= 0),\n            expiration_date TEXT,\n            status TEXT NOT NULL DEFAULT 'Active'\n                CHECK (status IN ('Active', 'Inactive')),\n            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n\n            FOREIGN KEY (category_id)\n                REFERENCES categories(id)\n                ON DELETE SET NULL\n        )\n    ");

    /*
    |--------------------------------------------------------------------------
    | PRODUCT VARIANTS
    |--------------------------------------------------------------------------
    */

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS product_variants (\n            id INTEGER PRIMARY KEY AUTOINCREMENT,\n            product_id INTEGER NOT NULL,\n\n            color TEXT NOT NULL DEFAULT 'Default',\n            color_hex TEXT,\n            size TEXT NOT NULL,\n\n            sku TEXT NOT NULL UNIQUE,\n            barcode TEXT NOT NULL UNIQUE,\n\n            stock_quantity INTEGER NOT NULL DEFAULT 0\n                CHECK (stock_quantity >= 0),\n\n            status TEXT NOT NULL DEFAULT 'Active'\n                CHECK (status IN ('Active', 'Inactive')),\n\n            image_path TEXT,\n\n            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n\n            UNIQUE (product_id, color, size),\n\n            FOREIGN KEY (product_id)\n                REFERENCES products(id)\n                ON DELETE CASCADE\n        )\n    ");

    /*
    |--------------------------------------------------------------------------
    | PRODUCT SUPPLIERS
    |--------------------------------------------------------------------------
    */

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS product_suppliers (\n            id INTEGER PRIMARY KEY AUTOINCREMENT,\n            product_id INTEGER NOT NULL,\n            supplier_id INTEGER NOT NULL,\n            supplier_price REAL NOT NULL DEFAULT 0 CHECK (supplier_price >= 0),\n            is_primary INTEGER NOT NULL DEFAULT 0 CHECK (is_primary IN (0, 1)),\n            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n\n            UNIQUE (product_id, supplier_id),\n\n            FOREIGN KEY (product_id)\n                REFERENCES products(id)\n                ON DELETE CASCADE,\n\n            FOREIGN KEY (supplier_id)\n                REFERENCES suppliers(id)\n                ON DELETE CASCADE\n        )\n    ");

    /*
    |--------------------------------------------------------------------------
    | SALES
    |--------------------------------------------------------------------------
    */

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS sales (\n            id INTEGER PRIMARY KEY AUTOINCREMENT,\n            transaction_no TEXT NOT NULL UNIQUE,\n            cashier_id INTEGER NOT NULL,\n\n            subtotal REAL NOT NULL DEFAULT 0 CHECK (subtotal >= 0),\n            tax_rate REAL NOT NULL DEFAULT 0 CHECK (tax_rate >= 0),\n            tax_amount REAL NOT NULL DEFAULT 0 CHECK (tax_amount >= 0),\n\n            discount_type TEXT NOT NULL DEFAULT 'None'\n                CHECK (discount_type IN ('None', 'PWD', 'Senior')),\n\n            discount_percent REAL NOT NULL DEFAULT 0 CHECK (discount_percent >= 0),\n            discount_amount REAL NOT NULL DEFAULT 0 CHECK (discount_amount >= 0),\n            discount_customer_name TEXT,\n            discount_id_number TEXT,\n\n            total_amount REAL NOT NULL DEFAULT 0 CHECK (total_amount >= 0),\n\n            payment_method TEXT NOT NULL\n                CHECK (payment_method IN ('Cash', 'GCash', 'Maya', 'MariBank')),\n\n            payment_reference TEXT,\n            amount_tendered REAL NOT NULL DEFAULT 0 CHECK (amount_tendered >= 0),\n            change_amount REAL NOT NULL DEFAULT 0 CHECK (change_amount >= 0),\n\n            status TEXT NOT NULL DEFAULT 'Completed'\n                CHECK (status IN ('Completed', 'Voided')),\n\n            voided_by INTEGER,\n            void_reason TEXT,\n            voided_at TEXT,\n\n            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n\n            FOREIGN KEY (cashier_id) REFERENCES users(id),\n            FOREIGN KEY (voided_by) REFERENCES users(id)\n        )\n    ");

    /*
    |--------------------------------------------------------------------------
    | SALE ITEMS
    |--------------------------------------------------------------------------
    |
    | variant_id is nullable so legacy sales can still be retained.
    | New POS sales should always provide a variant_id.
    |
    */

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS sale_items (\n            id INTEGER PRIMARY KEY AUTOINCREMENT,\n            sale_id INTEGER NOT NULL,\n            product_id INTEGER NOT NULL,\n            variant_id INTEGER,\n\n            color TEXT,\n            size TEXT,\n            variant_sku TEXT,\n\n            barcode TEXT NOT NULL,\n            product_name TEXT NOT NULL,\n\n            quantity INTEGER NOT NULL CHECK (quantity > 0),\n            unit_price REAL NOT NULL CHECK (unit_price >= 0),\n            line_total REAL NOT NULL CHECK (line_total >= 0),\n\n            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n\n            FOREIGN KEY (sale_id)\n                REFERENCES sales(id)\n                ON DELETE CASCADE,\n\n            FOREIGN KEY (product_id)\n                REFERENCES products(id),\n\n            FOREIGN KEY (variant_id)\n                REFERENCES product_variants(id)\n                ON DELETE SET NULL\n        )\n    ");

    /*
    |--------------------------------------------------------------------------
    | STOCK RECEIPTS
    |--------------------------------------------------------------------------
    */

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS stock_receipts (\n            id INTEGER PRIMARY KEY AUTOINCREMENT,\n            receipt_no TEXT NOT NULL UNIQUE,\n            supplier_id INTEGER NOT NULL,\n            received_by INTEGER NOT NULL,\n            total_cost REAL NOT NULL DEFAULT 0 CHECK (total_cost >= 0),\n            notes TEXT,\n            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n\n            FOREIGN KEY (supplier_id) REFERENCES suppliers(id),\n            FOREIGN KEY (received_by) REFERENCES users(id)\n        )\n    ");

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS stock_receipt_items (\n            id INTEGER PRIMARY KEY AUTOINCREMENT,\n            stock_receipt_id INTEGER NOT NULL,\n            product_id INTEGER NOT NULL,\n            variant_id INTEGER NOT NULL,\n            quantity INTEGER NOT NULL CHECK (quantity > 0),\n            unit_cost REAL NOT NULL DEFAULT 0 CHECK (unit_cost >= 0),\n            line_total REAL NOT NULL DEFAULT 0 CHECK (line_total >= 0),\n            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n\n            FOREIGN KEY (stock_receipt_id)\n                REFERENCES stock_receipts(id)\n                ON DELETE CASCADE,\n\n            FOREIGN KEY (product_id) REFERENCES products(id),\n            FOREIGN KEY (variant_id) REFERENCES product_variants(id)\n        )\n    ");

    /*
    |--------------------------------------------------------------------------
    | INVENTORY LOGS
    |--------------------------------------------------------------------------
    |
    | For new variant-based activity, previous_stock/new_stock represent the
    | selected variant's stock before and after the movement.
    |
    */

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS inventory_logs (\n            id INTEGER PRIMARY KEY AUTOINCREMENT,\n            product_id INTEGER NOT NULL,\n            variant_id INTEGER,\n\n            user_id INTEGER,\n            supplier_id INTEGER,\n            sale_id INTEGER,\n            stock_receipt_id INTEGER,\n\n            action TEXT NOT NULL\n                CHECK (action IN (\n                    'Initial Stock',\n                    'Restock',\n                    'Sale',\n                    'Adjustment',\n                    'Void Return',\n                    'Damaged',\n                    'Expired'\n                )),\n\n            color TEXT,\n            size TEXT,\n\n            quantity_change INTEGER NOT NULL,\n            previous_stock INTEGER NOT NULL,\n            new_stock INTEGER NOT NULL,\n            notes TEXT,\n            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n\n            FOREIGN KEY (product_id) REFERENCES products(id),\n\n            FOREIGN KEY (variant_id)\n                REFERENCES product_variants(id)\n                ON DELETE SET NULL,\n\n            FOREIGN KEY (user_id) REFERENCES users(id),\n            FOREIGN KEY (supplier_id) REFERENCES suppliers(id),\n            FOREIGN KEY (sale_id) REFERENCES sales(id),\n\n            FOREIGN KEY (stock_receipt_id)\n                REFERENCES stock_receipts(id)\n                ON DELETE SET NULL\n        )\n    ");

    /*
    |--------------------------------------------------------------------------
    | EMPLOYEES
    |--------------------------------------------------------------------------
    */

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS employees (\n            id INTEGER PRIMARY KEY AUTOINCREMENT,\n            employee_code TEXT NOT NULL UNIQUE,\n            user_id INTEGER UNIQUE,\n            first_name TEXT NOT NULL,\n            middle_name TEXT,\n            last_name TEXT NOT NULL,\n            suffix TEXT,\n            position TEXT NOT NULL,\n\n            pay_type TEXT NOT NULL DEFAULT 'Hourly'\n                CHECK (pay_type IN ('Hourly', 'Monthly')),\n\n            pay_rate REAL NOT NULL DEFAULT 0 CHECK (pay_rate >= 0),\n            date_hired TEXT,\n\n            status TEXT NOT NULL DEFAULT 'Active'\n                CHECK (status IN ('Active', 'Inactive')),\n\n            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n\n            FOREIGN KEY (user_id)\n                REFERENCES users(id)\n                ON DELETE SET NULL\n        )\n    ");

    /*
    |--------------------------------------------------------------------------
    | PAYROLL
    |--------------------------------------------------------------------------
    */

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS payroll (\n            id INTEGER PRIMARY KEY AUTOINCREMENT,\n            employee_id INTEGER NOT NULL,\n            period_start TEXT NOT NULL,\n            period_end TEXT NOT NULL,\n            hours_worked REAL NOT NULL DEFAULT 0 CHECK (hours_worked >= 0),\n            gross_pay REAL NOT NULL DEFAULT 0 CHECK (gross_pay >= 0),\n            deductions REAL NOT NULL DEFAULT 0 CHECK (deductions >= 0),\n            deduction_notes TEXT,\n            net_pay REAL NOT NULL DEFAULT 0 CHECK (net_pay >= 0),\n            processed_by INTEGER NOT NULL,\n            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n\n            FOREIGN KEY (employee_id) REFERENCES employees(id),\n            FOREIGN KEY (processed_by) REFERENCES users(id)\n        )\n    ");

    /*
    |--------------------------------------------------------------------------
    | EXPENSES / LOSSES
    |--------------------------------------------------------------------------
    */

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS expenses (\n            id INTEGER PRIMARY KEY AUTOINCREMENT,\n            expense_type TEXT NOT NULL\n                CHECK (expense_type IN ('Expense', 'Loss')),\n            category TEXT,\n            description TEXT NOT NULL,\n            amount REAL NOT NULL CHECK (amount >= 0),\n            expense_date TEXT NOT NULL DEFAULT CURRENT_DATE,\n            recorded_by INTEGER NOT NULL,\n            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n\n            FOREIGN KEY (recorded_by) REFERENCES users(id)\n        )\n    ");

    /*
    |--------------------------------------------------------------------------
    | SYSTEM LOGS
    |--------------------------------------------------------------------------
    */

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS system_logs (\n            id INTEGER PRIMARY KEY AUTOINCREMENT,\n            user_id INTEGER,\n            action TEXT NOT NULL,\n            module TEXT NOT NULL,\n            record_type TEXT,\n            record_id INTEGER,\n            details TEXT,\n            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n\n            FOREIGN KEY (user_id)\n                REFERENCES users(id)\n                ON DELETE SET NULL\n        )\n    ");

    /*
    |--------------------------------------------------------------------------
    | INDEXES
    |--------------------------------------------------------------------------
    */

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_products_product_code ON products(product_code)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_products_barcode ON products(barcode)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_products_name ON products(product_name)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_product_variants_product_id ON product_variants(product_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_product_variants_status ON product_variants(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sales_transaction_no ON sales(transaction_no)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sales_cashier ON sales(cashier_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sales_created_at ON sales(created_at)");
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
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_system_logs_user ON system_logs(user_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_system_logs_created ON system_logs(created_at)");

    /*
    |--------------------------------------------------------------------------
    | STOCK CACHE TRIGGERS
    |--------------------------------------------------------------------------
    */

    $pdo->exec("\n        CREATE TRIGGER IF NOT EXISTS trg_variant_stock_after_insert\n        AFTER INSERT ON product_variants\n        BEGIN\n            UPDATE products\n            SET\n                stock_quantity = (\n                    SELECT COALESCE(SUM(stock_quantity), 0)\n                    FROM product_variants\n                    WHERE product_id = NEW.product_id\n                ),\n                updated_at = CURRENT_TIMESTAMP\n            WHERE id = NEW.product_id;\n        END\n    ");

    $pdo->exec("\n        CREATE TRIGGER IF NOT EXISTS trg_variant_stock_after_update\n        AFTER UPDATE OF stock_quantity, product_id ON product_variants\n        BEGIN\n            UPDATE products\n            SET\n                stock_quantity = (\n                    SELECT COALESCE(SUM(stock_quantity), 0)\n                    FROM product_variants\n                    WHERE product_id = OLD.product_id\n                ),\n                updated_at = CURRENT_TIMESTAMP\n            WHERE id = OLD.product_id;\n\n            UPDATE products\n            SET\n                stock_quantity = (\n                    SELECT COALESCE(SUM(stock_quantity), 0)\n                    FROM product_variants\n                    WHERE product_id = NEW.product_id\n                ),\n                updated_at = CURRENT_TIMESTAMP\n            WHERE id = NEW.product_id;\n        END\n    ");

    $pdo->exec("\n        CREATE TRIGGER IF NOT EXISTS trg_variant_stock_after_delete\n        AFTER DELETE ON product_variants\n        BEGIN\n            UPDATE products\n            SET\n                stock_quantity = (\n                    SELECT COALESCE(SUM(stock_quantity), 0)\n                    FROM product_variants\n                    WHERE product_id = OLD.product_id\n                ),\n                updated_at = CURRENT_TIMESTAMP\n            WHERE id = OLD.product_id;\n        END\n    ");

    /*
    |--------------------------------------------------------------------------
    | DEFAULT TAX RATES
    |--------------------------------------------------------------------------
    */

    $taxRates = [
        ['VAT 12%', 12],
        ['VAT 16%', 16],
        ['VAT 20%', 20]
    ];

    $checkTax = $pdo->prepare("SELECT id FROM tax_rates WHERE rate = ? LIMIT 1");
    $insertTax = $pdo->prepare("INSERT INTO tax_rates (name, rate) VALUES (?, ?)");

    foreach ($taxRates as $tax) {
        $checkTax->execute([$tax[1]]);

        if (!$checkTax->fetch()) {
            $insertTax->execute([$tax[0], $tax[1]]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DEFAULT SETTINGS
    |--------------------------------------------------------------------------
    */

    $settings = [
        ['store_name', 'CompAcc POS', 'Name displayed on the system and receipt'],
        ['default_tax_rate', '12', 'Default tax percentage'],
        ['pwd_discount', '20', 'PWD discount percentage'],
        ['senior_discount', '20', 'Senior Citizen discount percentage'],
        ['low_stock_threshold', '10', 'Legacy fallback low-stock threshold'],
        ['reorder_sales_window_days', '30', 'Recent sales window used for each variant demand calculation'],
        ['reorder_lead_time_days', '7', 'Default supplier lead time used by variant reorder point calculations'],
        ['reorder_safety_days', '3', 'Additional days of variant demand used as safety stock'],
        ['reorder_target_days', '30', 'Desired days of stock coverage after a variant is restocked'],
        ['variant_reorder_fallback', '5', 'Fallback reorder point for a variant with no recent sales history'],
        ['variant_target_stock_fallback', '15', 'Fallback target stock for a variant with no recent sales history'],
        ['expiration_warning_days', '30', 'Number of days before expiration warning'],
        ['gcash_qr', '', 'GCash QR image path'],
        ['maya_qr', '', 'Maya QR image path'],
        ['maribank_qr', '', 'MariBank QR image path']
    ];

    $insertSetting = $pdo->prepare("\n        INSERT OR IGNORE INTO settings (\n            setting_key,\n            setting_value,\n            description\n        )\n        VALUES (?, ?, ?)\n    ");

    foreach ($settings as $setting) {
        $insertSetting->execute($setting);
    }

    /*
    |--------------------------------------------------------------------------
    | DEFAULT UNDERGROUND APPAREL CATEGORIES
    |--------------------------------------------------------------------------
    */

    $categories = [
        'T-Shirts',
        'Shirts',
        'Hoodies & Sweatshirts',
        'Jackets',
        'Pants',
        'Shorts',
        'Caps & Headwear',
        'Bags',
        'Accessories',
        'Other'
    ];

    $insertCategory = $pdo->prepare("INSERT OR IGNORE INTO categories (name) VALUES (?)");

    foreach ($categories as $category) {
        $insertCategory->execute([$category]);
    }

    /*
    |--------------------------------------------------------------------------
    | DEFAULT ACCOUNTS
    |--------------------------------------------------------------------------
    */

    $accounts = [
        ['admin', 'admin123', 'System', null, 'Administrator', null, 'Admin'],
        ['manager', 'manager123', 'Store', null, 'Manager', null, 'Manager'],
        ['cashier', 'cashier123', 'Store', null, 'Cashier', null, 'Cashier']
    ];

    $checkUser = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");

    $insertUser = $pdo->prepare("\n        INSERT INTO users (\n            username,\n            password,\n            first_name,\n            middle_name,\n            last_name,\n            suffix,\n            role,\n            status\n        )\n        VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')\n    ");

    foreach ($accounts as $account) {
        $checkUser->execute([$account[0]]);

        if (!$checkUser->fetch()) {
            $insertUser->execute([
                $account[0],
                password_hash($account[1], PASSWORD_DEFAULT),
                $account[2],
                $account[3],
                $account[4],
                $account[5],
                $account[6]
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SYNC PRODUCT STOCK CACHE
    |--------------------------------------------------------------------------
    */

    $pdo->exec("\n        UPDATE products\n        SET stock_quantity = (\n            SELECT COALESCE(SUM(pv.stock_quantity), 0)\n            FROM product_variants pv\n            WHERE pv.product_id = products.id\n        )\n    ");

    $foreignKeyProblems = $pdo->query('PRAGMA foreign_key_check')->fetchAll();

    if ($foreignKeyProblems !== []) {
        throw new RuntimeException('Foreign key validation failed during database setup.');
    }

    $pdo->commit();

    echo PHP_EOL;
    echo "============================================" . PHP_EOL;
    echo "COMPACC POS DATABASE SETUP COMPLETE" . PHP_EOL;
    echo "============================================" . PHP_EOL;
    echo PHP_EOL;

    echo "Core inventory model:" . PHP_EOL;
    echo "- products" . PHP_EOL;
    echo "- product_variants (authoritative stock)" . PHP_EOL;
    echo "- product_suppliers" . PHP_EOL;
    echo "- stock_receipts" . PHP_EOL;
    echo "- stock_receipt_items" . PHP_EOL;
    echo "- inventory_logs" . PHP_EOL;
    echo PHP_EOL;

    echo "Other tables:" . PHP_EOL;
    echo "- users" . PHP_EOL;
    echo "- settings" . PHP_EOL;
    echo "- tax_rates" . PHP_EOL;
    echo "- categories" . PHP_EOL;
    echo "- suppliers" . PHP_EOL;
    echo "- sales" . PHP_EOL;
    echo "- sale_items" . PHP_EOL;
    echo "- employees" . PHP_EOL;
    echo "- payroll" . PHP_EOL;
    echo "- expenses" . PHP_EOL;
    echo "- system_logs" . PHP_EOL;
    echo PHP_EOL;

    echo "Default Accounts:" . PHP_EOL;
    echo "Admin   : admin / admin123" . PHP_EOL;
    echo "Manager : manager / manager123" . PHP_EOL;
    echo "Cashier : cashier / cashier123" . PHP_EOL;
    echo PHP_EOL;

    echo "Database:" . PHP_EOL;
    echo "storage/database/pos.sqlite" . PHP_EOL;

} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo PHP_EOL;
    echo "DATABASE SETUP FAILED" . PHP_EOL;
    echo $error->getMessage() . PHP_EOL;
}
