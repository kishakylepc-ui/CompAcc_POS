<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/config/database.php';

/**
 * CompAcc POS - New Database Setup
 *
 * Purpose:
 * - Create every table, index and trigger for a NEW, EMPTY database, in the
 *   same shape as a live database that has had every migration applied
 *   (product codes, user contact fields, payroll void columns).
 * - Add the default settings and Underground Apparel categories.
 * - Create ONE Admin account with a password you choose while it runs.
 *   Create Manager and Cashier accounts afterwards in Accounts.
 *
 * Safety:
 * - Refuses to run when the database already has tables, so it can never
 *   change a database that holds real data.
 * - Everything is created in one transaction: if anything fails, nothing
 *   is created.
 *
 * Do NOT run the tools/migrate_*.php scripts after this: a database created
 * here already includes them (they would only report "Nothing to do").
 *
 * Run from the project root on a new computer (no pos.sqlite yet):
 *   C:\php\php.exe tools\setup_database.php
 */

function setupFail(string $message): never
{
    fwrite(STDERR, PHP_EOL . 'DATABASE SETUP STOPPED' . PHP_EOL . $message . PHP_EOL);
    exit(1);
}

function setupPrompt(string $label): string
{
    echo $label;

    $line = fgets(STDIN);

    return $line === false
        ? ''
        : rtrim($line, "\r\n");
}

echo 'CompAcc POS - New Database Setup' . PHP_EOL;
echo '================================' . PHP_EOL;

/*
|--------------------------------------------------------------------------
| ONLY FOR AN EMPTY DATABASE
|--------------------------------------------------------------------------
*/

$existingTables = $pdo
    ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
    ->fetchAll(PDO::FETCH_COLUMN);

if ($existingTables !== []) {
    setupFail(
        'This database already has tables (' . implode(', ', $existingTables) . ').' . PHP_EOL
        . 'setup_database.php only sets up a NEW, EMPTY database, so nothing was changed.' . PHP_EOL
        . 'If this computer already has data, keep using it. For a fresh start, ask a' . PHP_EOL
        . 'teammate before moving storage/database/pos.sqlite anywhere.'
    );
}

/*
|--------------------------------------------------------------------------
| ADMIN PASSWORD (asked before anything is created)
|--------------------------------------------------------------------------
*/

echo PHP_EOL . 'An Admin account named "admin" will be created.' . PHP_EOL;

$adminPassword = setupPrompt('Choose its password (at least 6 characters): ');

if (strlen($adminPassword) < 6) {
    setupFail('The password must have at least 6 characters. Nothing was created.');
}

if (setupPrompt('Type the same password again: ') !== $adminPassword) {
    setupFail('The two passwords do not match. Nothing was created.');
}

echo PHP_EOL . 'Creating the database...' . PHP_EOL;

try {
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | USERS
    |--------------------------------------------------------------------------
    | email / contact_number: Settings > My Account
    | (same as tools/migrate_user_contact_fields.php).
    */

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            first_name TEXT NOT NULL,
            middle_name TEXT,
            last_name TEXT NOT NULL,
            suffix TEXT,
            role TEXT NOT NULL
                CHECK (role IN ('Admin', 'Manager', 'Cashier')),
            status TEXT NOT NULL DEFAULT 'Active'
                CHECK (status IN ('Active', 'Inactive')),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            email TEXT,
            contact_number TEXT
        )
    SQL);

    /*
    |--------------------------------------------------------------------------
    | SETTINGS
    |--------------------------------------------------------------------------
    */

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            setting_key TEXT NOT NULL UNIQUE,
            setting_value TEXT NOT NULL,
            description TEXT,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    SQL);

    /*
    |--------------------------------------------------------------------------
    | TAX RATES (old presets; VAT now comes from settings)
    |--------------------------------------------------------------------------
    */

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS tax_rates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            rate REAL NOT NULL CHECK (rate >= 0),
            is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    SQL);

    /*
    |--------------------------------------------------------------------------
    | CATEGORIES
    |--------------------------------------------------------------------------
    */

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            description TEXT,
            status TEXT NOT NULL DEFAULT 'Active'
                CHECK (status IN ('Active', 'Inactive')),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    SQL);

    /*
    |--------------------------------------------------------------------------
    | SUPPLIERS
    |--------------------------------------------------------------------------
    */

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS suppliers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            supplier_name TEXT NOT NULL,
            contact_person TEXT,
            phone TEXT,
            email TEXT,
            address TEXT,
            status TEXT NOT NULL DEFAULT 'Active'
                CHECK (status IN ('Active', 'Inactive')),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    SQL);

    /*
    |--------------------------------------------------------------------------
    | PRODUCTS
    |--------------------------------------------------------------------------
    | product_code: parent style code (UA-0001), required by the triggers
    | below (same as tools/migrate_product_codes.php).
    | stock_quantity is a cached total kept in sync by the variant triggers;
    | product_variants.stock_quantity is the real stock.
    */

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            barcode TEXT NOT NULL UNIQUE,
            product_name TEXT NOT NULL,
            category_id INTEGER,
            cost_price REAL NOT NULL DEFAULT 0 CHECK (cost_price >= 0),
            selling_price REAL NOT NULL DEFAULT 0 CHECK (selling_price >= 0),
            stock_quantity INTEGER NOT NULL DEFAULT 0 CHECK (stock_quantity >= 0),
            reorder_level INTEGER NOT NULL DEFAULT 10 CHECK (reorder_level >= 0),
            expiration_date TEXT,
            status TEXT NOT NULL DEFAULT 'Active'
                CHECK (status IN ('Active', 'Inactive')),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            product_code TEXT,
            FOREIGN KEY (category_id)
                REFERENCES categories(id)
                ON DELETE SET NULL
        )
    SQL);

    /*
    |--------------------------------------------------------------------------
    | PRODUCT VARIANTS (color + size; the real stock)
    |--------------------------------------------------------------------------
    */

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS product_variants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            color TEXT NOT NULL DEFAULT 'Default',
            color_hex TEXT,
            size TEXT NOT NULL,
            sku TEXT NOT NULL UNIQUE,
            barcode TEXT NOT NULL UNIQUE,
            stock_quantity INTEGER NOT NULL DEFAULT 0
                CHECK (stock_quantity >= 0),
            status TEXT NOT NULL DEFAULT 'Active'
                CHECK (status IN ('Active', 'Inactive')),
            image_path TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (product_id, color, size),
            FOREIGN KEY (product_id)
                REFERENCES products(id)
                ON DELETE CASCADE
        )
    SQL);

    /*
    |--------------------------------------------------------------------------
    | PRODUCT SUPPLIERS
    |--------------------------------------------------------------------------
    */

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS product_suppliers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            supplier_id INTEGER NOT NULL,
            supplier_price REAL NOT NULL DEFAULT 0 CHECK (supplier_price >= 0),
            is_primary INTEGER NOT NULL DEFAULT 0 CHECK (is_primary IN (0, 1)),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (product_id, supplier_id),
            FOREIGN KEY (product_id)
                REFERENCES products(id)
                ON DELETE CASCADE,
            FOREIGN KEY (supplier_id)
                REFERENCES suppliers(id)
                ON DELETE CASCADE
        )
    SQL);

    /*
    |--------------------------------------------------------------------------
    | SALES
    |--------------------------------------------------------------------------
    */

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS sales (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            transaction_no TEXT NOT NULL UNIQUE,
            cashier_id INTEGER NOT NULL,
            subtotal REAL NOT NULL DEFAULT 0 CHECK (subtotal >= 0),
            tax_rate REAL NOT NULL DEFAULT 0 CHECK (tax_rate >= 0),
            tax_amount REAL NOT NULL DEFAULT 0 CHECK (tax_amount >= 0),
            discount_type TEXT NOT NULL DEFAULT 'None'
                CHECK (discount_type IN ('None', 'PWD', 'Senior')),
            discount_percent REAL NOT NULL DEFAULT 0 CHECK (discount_percent >= 0),
            discount_amount REAL NOT NULL DEFAULT 0 CHECK (discount_amount >= 0),
            discount_customer_name TEXT,
            discount_id_number TEXT,
            total_amount REAL NOT NULL DEFAULT 0 CHECK (total_amount >= 0),
            payment_method TEXT NOT NULL
                CHECK (payment_method IN ('Cash', 'GCash', 'Maya', 'MariBank')),
            payment_reference TEXT,
            amount_tendered REAL NOT NULL DEFAULT 0 CHECK (amount_tendered >= 0),
            change_amount REAL NOT NULL DEFAULT 0 CHECK (change_amount >= 0),
            status TEXT NOT NULL DEFAULT 'Completed'
                CHECK (status IN ('Completed', 'Voided')),
            voided_by INTEGER,
            void_reason TEXT,
            voided_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (cashier_id) REFERENCES users(id),
            FOREIGN KEY (voided_by) REFERENCES users(id)
        )
    SQL);

    /*
    |--------------------------------------------------------------------------
    | SALE ITEMS
    |--------------------------------------------------------------------------
    | variant_id is nullable so very old sales can be kept; new POS sales
    | always store it.
    */

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS sale_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            variant_id INTEGER,
            color TEXT,
            size TEXT,
            variant_sku TEXT,
            barcode TEXT NOT NULL,
            product_name TEXT NOT NULL,
            quantity INTEGER NOT NULL CHECK (quantity > 0),
            unit_price REAL NOT NULL CHECK (unit_price >= 0),
            line_total REAL NOT NULL CHECK (line_total >= 0),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (sale_id)
                REFERENCES sales(id)
                ON DELETE CASCADE,
            FOREIGN KEY (product_id)
                REFERENCES products(id),
            FOREIGN KEY (variant_id)
                REFERENCES product_variants(id)
                ON DELETE SET NULL
        )
    SQL);

    /*
    |--------------------------------------------------------------------------
    | STOCK RECEIPTS (supplier deliveries)
    |--------------------------------------------------------------------------
    */

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS stock_receipts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            receipt_no TEXT NOT NULL UNIQUE,
            supplier_id INTEGER NOT NULL,
            received_by INTEGER NOT NULL,
            total_cost REAL NOT NULL DEFAULT 0 CHECK (total_cost >= 0),
            notes TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
            FOREIGN KEY (received_by) REFERENCES users(id)
        )
    SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS stock_receipt_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            stock_receipt_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            variant_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL CHECK (quantity > 0),
            unit_cost REAL NOT NULL DEFAULT 0 CHECK (unit_cost >= 0),
            line_total REAL NOT NULL DEFAULT 0 CHECK (line_total >= 0),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (stock_receipt_id)
                REFERENCES stock_receipts(id)
                ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id),
            FOREIGN KEY (variant_id) REFERENCES product_variants(id)
        )
    SQL);

    /*
    |--------------------------------------------------------------------------
    | INVENTORY LOGS
    |--------------------------------------------------------------------------
    | previous_stock / new_stock are the variant's stock before and after.
    */

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS inventory_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            variant_id INTEGER,
            user_id INTEGER,
            supplier_id INTEGER,
            sale_id INTEGER,
            stock_receipt_id INTEGER,
            action TEXT NOT NULL
                CHECK (action IN (
                    'Initial Stock',
                    'Restock',
                    'Sale',
                    'Adjustment',
                    'Void Return',
                    'Damaged',
                    'Expired'
                )),
            color TEXT,
            size TEXT,
            quantity_change INTEGER NOT NULL,
            previous_stock INTEGER NOT NULL,
            new_stock INTEGER NOT NULL,
            notes TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id),
            FOREIGN KEY (variant_id)
                REFERENCES product_variants(id)
                ON DELETE SET NULL,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
            FOREIGN KEY (sale_id) REFERENCES sales(id),
            FOREIGN KEY (stock_receipt_id)
                REFERENCES stock_receipts(id)
                ON DELETE SET NULL
        )
    SQL);

    /*
    |--------------------------------------------------------------------------
    | EMPLOYEES (a POS login is optional)
    |--------------------------------------------------------------------------
    */

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS employees (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_code TEXT NOT NULL UNIQUE,
            user_id INTEGER UNIQUE,
            first_name TEXT NOT NULL,
            middle_name TEXT,
            last_name TEXT NOT NULL,
            suffix TEXT,
            position TEXT NOT NULL,
            pay_type TEXT NOT NULL DEFAULT 'Hourly'
                CHECK (pay_type IN ('Hourly', 'Monthly')),
            pay_rate REAL NOT NULL DEFAULT 0 CHECK (pay_rate >= 0),
            date_hired TEXT,
            status TEXT NOT NULL DEFAULT 'Active'
                CHECK (status IN ('Active', 'Inactive')),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id)
                REFERENCES users(id)
                ON DELETE SET NULL
        )
    SQL);

    /*
    |--------------------------------------------------------------------------
    | PAYROLL
    |--------------------------------------------------------------------------
    | status / voided_by / voided_at / void_reason: an Admin can void a
    | payroll processed by mistake (same as tools/migrate_payroll_void.php).
    */

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS payroll (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id INTEGER NOT NULL,
            period_start TEXT NOT NULL,
            period_end TEXT NOT NULL,
            hours_worked REAL NOT NULL DEFAULT 0 CHECK (hours_worked >= 0),
            gross_pay REAL NOT NULL DEFAULT 0 CHECK (gross_pay >= 0),
            deductions REAL NOT NULL DEFAULT 0 CHECK (deductions >= 0),
            deduction_notes TEXT,
            net_pay REAL NOT NULL DEFAULT 0 CHECK (net_pay >= 0),
            processed_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status TEXT NOT NULL DEFAULT 'Processed'
                CHECK (status IN ('Processed', 'Voided')),
            voided_by INTEGER REFERENCES users(id),
            voided_at TEXT,
            void_reason TEXT,
            FOREIGN KEY (employee_id) REFERENCES employees(id),
            FOREIGN KEY (processed_by) REFERENCES users(id)
        )
    SQL);

    /*
    |--------------------------------------------------------------------------
    | EXPENSES / LOSSES
    |--------------------------------------------------------------------------
    */

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS expenses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            expense_type TEXT NOT NULL
                CHECK (expense_type IN ('Expense', 'Loss')),
            category TEXT,
            description TEXT NOT NULL,
            amount REAL NOT NULL CHECK (amount >= 0),
            expense_date TEXT NOT NULL DEFAULT CURRENT_DATE,
            recorded_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (recorded_by) REFERENCES users(id)
        )
    SQL);

    /*
    |--------------------------------------------------------------------------
    | SYSTEM LOGS
    |--------------------------------------------------------------------------
    */

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS system_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT NOT NULL,
            module TEXT NOT NULL,
            record_type TEXT,
            record_id INTEGER,
            details TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id)
                REFERENCES users(id)
                ON DELETE SET NULL
        )
    SQL);

    /*
    |--------------------------------------------------------------------------
    | INDEXES
    |--------------------------------------------------------------------------
    */

    $indexes = [
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_products_product_code ON products(product_code)',
        'CREATE INDEX IF NOT EXISTS idx_products_barcode ON products(barcode)',
        'CREATE INDEX IF NOT EXISTS idx_products_name ON products(product_name)',
        'CREATE INDEX IF NOT EXISTS idx_product_variants_product_id ON product_variants(product_id)',
        'CREATE INDEX IF NOT EXISTS idx_product_variants_status ON product_variants(status)',
        'CREATE INDEX IF NOT EXISTS idx_sales_transaction_no ON sales(transaction_no)',
        'CREATE INDEX IF NOT EXISTS idx_sales_cashier ON sales(cashier_id)',
        'CREATE INDEX IF NOT EXISTS idx_sales_created_at ON sales(created_at)',
        'CREATE INDEX IF NOT EXISTS idx_sale_items_sale ON sale_items(sale_id)',
        'CREATE INDEX IF NOT EXISTS idx_sale_items_product ON sale_items(product_id)',
        'CREATE INDEX IF NOT EXISTS idx_sale_items_variant ON sale_items(variant_id)',
        'CREATE INDEX IF NOT EXISTS idx_inventory_product ON inventory_logs(product_id)',
        'CREATE INDEX IF NOT EXISTS idx_inventory_variant ON inventory_logs(variant_id)',
        'CREATE INDEX IF NOT EXISTS idx_inventory_receipt ON inventory_logs(stock_receipt_id)',
        'CREATE INDEX IF NOT EXISTS idx_stock_receipts_supplier ON stock_receipts(supplier_id)',
        'CREATE INDEX IF NOT EXISTS idx_stock_receipts_created ON stock_receipts(created_at)',
        'CREATE INDEX IF NOT EXISTS idx_stock_receipt_items_receipt ON stock_receipt_items(stock_receipt_id)',
        'CREATE INDEX IF NOT EXISTS idx_stock_receipt_items_product ON stock_receipt_items(product_id)',
        'CREATE INDEX IF NOT EXISTS idx_stock_receipt_items_variant ON stock_receipt_items(variant_id)',
        'CREATE INDEX IF NOT EXISTS idx_system_logs_user ON system_logs(user_id)',
        'CREATE INDEX IF NOT EXISTS idx_system_logs_created ON system_logs(created_at)'
    ];

    foreach ($indexes as $indexSql) {
        $pdo->exec($indexSql);
    }

    /*
    |--------------------------------------------------------------------------
    | PRODUCT CODE IS REQUIRED
    |--------------------------------------------------------------------------
    */

    $pdo->exec(<<<'SQL'
        CREATE TRIGGER IF NOT EXISTS trg_products_product_code_insert
        BEFORE INSERT ON products
        WHEN NEW.product_code IS NULL OR TRIM(NEW.product_code) = ''
        BEGIN
            SELECT RAISE(ABORT, 'Product code is required.');
        END
    SQL);

    $pdo->exec(<<<'SQL'
        CREATE TRIGGER IF NOT EXISTS trg_products_product_code_update
        BEFORE UPDATE OF product_code ON products
        WHEN NEW.product_code IS NULL OR TRIM(NEW.product_code) = ''
        BEGIN
            SELECT RAISE(ABORT, 'Product code is required.');
        END
    SQL);

    /*
    |--------------------------------------------------------------------------
    | STOCK CACHE TRIGGERS
    |--------------------------------------------------------------------------
    | Keep products.stock_quantity = the sum of its variants' stock.
    */

    $pdo->exec(<<<'SQL'
        CREATE TRIGGER IF NOT EXISTS trg_variant_stock_after_insert
        AFTER INSERT ON product_variants
        BEGIN
            UPDATE products
            SET
                stock_quantity = (
                    SELECT COALESCE(SUM(stock_quantity), 0)
                    FROM product_variants
                    WHERE product_id = NEW.product_id
                ),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = NEW.product_id;
        END
    SQL);

    $pdo->exec(<<<'SQL'
        CREATE TRIGGER IF NOT EXISTS trg_variant_stock_after_update
        AFTER UPDATE OF stock_quantity, product_id ON product_variants
        BEGIN
            UPDATE products
            SET
                stock_quantity = (
                    SELECT COALESCE(SUM(stock_quantity), 0)
                    FROM product_variants
                    WHERE product_id = OLD.product_id
                ),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = OLD.product_id;

            UPDATE products
            SET
                stock_quantity = (
                    SELECT COALESCE(SUM(stock_quantity), 0)
                    FROM product_variants
                    WHERE product_id = NEW.product_id
                ),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = NEW.product_id;
        END
    SQL);

    $pdo->exec(<<<'SQL'
        CREATE TRIGGER IF NOT EXISTS trg_variant_stock_after_delete
        AFTER DELETE ON product_variants
        BEGIN
            UPDATE products
            SET
                stock_quantity = (
                    SELECT COALESCE(SUM(stock_quantity), 0)
                    FROM product_variants
                    WHERE product_id = OLD.product_id
                ),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = OLD.product_id;
        END
    SQL);

    /*
    |--------------------------------------------------------------------------
    | DEFAULT TAX RATES
    |--------------------------------------------------------------------------
    */

    $insertTax = $pdo->prepare('INSERT INTO tax_rates (name, rate) VALUES (?, ?)');

    foreach ([['VAT 12%', 12], ['VAT 16%', 16], ['VAT 20%', 20]] as $tax) {
        $insertTax->execute($tax);
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

    $insertSetting = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)'
    );

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

    $insertCategory = $pdo->prepare('INSERT INTO categories (name) VALUES (?)');

    foreach ($categories as $category) {
        $insertCategory->execute([$category]);
    }

    /*
    |--------------------------------------------------------------------------
    | ADMIN ACCOUNT (password chosen above; never a built-in default)
    |--------------------------------------------------------------------------
    */

    $pdo->prepare(<<<'SQL'
        INSERT INTO users (
            username,
            password,
            first_name,
            last_name,
            role,
            status
        )
        VALUES ('admin', ?, 'System', 'Administrator', 'Admin', 'Active')
    SQL)->execute([
        password_hash($adminPassword, PASSWORD_DEFAULT)
    ]);

    if ($pdo->query('PRAGMA foreign_key_check')->fetchAll() !== []) {
        throw new RuntimeException('Foreign key validation failed during database setup.');
    }

    $pdo->commit();

} catch (Throwable $error) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    setupFail($error->getMessage() . PHP_EOL . 'Nothing was created.');
}

echo PHP_EOL;
echo '============================================' . PHP_EOL;
echo 'COMPACC POS DATABASE SETUP COMPLETE' . PHP_EOL;
echo '============================================' . PHP_EOL;
echo PHP_EOL;
echo 'Database: storage/database/pos.sqlite' . PHP_EOL;
echo 'Admin account: admin (the password you just chose)' . PHP_EOL;
echo PHP_EOL;
echo 'Next steps:' . PHP_EOL;
echo '1. Start the app: C:\php\php.exe -S localhost:8000 -t public' . PHP_EOL;
echo '2. Sign in as admin, then create Manager and Cashier accounts in Accounts.' . PHP_EOL;
echo '3. Do not run the tools\migrate_*.php scripts: this database already includes them.' . PHP_EOL;
