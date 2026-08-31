<?php

require_once __DIR__ . '/../app/config/database.php';

echo "Setting up CompAcc POS database..." . PHP_EOL;

try {

    $pdo->beginTransaction();


    /*
    |--------------------------------------------------------------------------
    | USERS
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            username TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,

            first_name TEXT NOT NULL,
            middle_name TEXT,
            last_name TEXT NOT NULL,
            suffix TEXT,

            role TEXT NOT NULL
                CHECK (
                    role IN (
                        'Admin',
                        'Manager',
                        'Cashier'
                    )
                ),

            status TEXT NOT NULL DEFAULT 'Active'
                CHECK (
                    status IN (
                        'Active',
                        'Inactive'
                    )
                ),

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");


    /*
    |--------------------------------------------------------------------------
    | SETTINGS
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            setting_key TEXT NOT NULL UNIQUE,
            setting_value TEXT NOT NULL,

            description TEXT,

            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");


    /*
    |--------------------------------------------------------------------------
    | TAX RATES
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tax_rates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            name TEXT NOT NULL,

            rate REAL NOT NULL
                CHECK (rate >= 0),

            is_active INTEGER NOT NULL DEFAULT 1
                CHECK (is_active IN (0, 1)),

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");


    /*
    |--------------------------------------------------------------------------
    | CATEGORIES
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            name TEXT NOT NULL UNIQUE,

            description TEXT,

            status TEXT NOT NULL DEFAULT 'Active'
                CHECK (
                    status IN (
                        'Active',
                        'Inactive'
                    )
                ),

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");


    /*
    |--------------------------------------------------------------------------
    | SUPPLIERS
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS suppliers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            supplier_name TEXT NOT NULL,

            contact_person TEXT,

            phone TEXT,
            email TEXT,
            address TEXT,

            status TEXT NOT NULL DEFAULT 'Active'
                CHECK (
                    status IN (
                        'Active',
                        'Inactive'
                    )
                ),

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");


    /*
    |--------------------------------------------------------------------------
    | PRODUCTS
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            barcode TEXT NOT NULL UNIQUE,

            product_name TEXT NOT NULL,

            category_id INTEGER,

            cost_price REAL NOT NULL DEFAULT 0
                CHECK (cost_price >= 0),

            selling_price REAL NOT NULL DEFAULT 0
                CHECK (selling_price >= 0),

            stock_quantity INTEGER NOT NULL DEFAULT 0
                CHECK (stock_quantity >= 0),

            reorder_level INTEGER NOT NULL DEFAULT 10
                CHECK (reorder_level >= 0),

            expiration_date TEXT,

            status TEXT NOT NULL DEFAULT 'Active'
                CHECK (
                    status IN (
                        'Active',
                        'Inactive'
                    )
                ),

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (category_id)
                REFERENCES categories(id)
                ON DELETE SET NULL
        )
    ");


    /*
    |--------------------------------------------------------------------------
    | PRODUCT SUPPLIERS
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_suppliers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            product_id INTEGER NOT NULL,
            supplier_id INTEGER NOT NULL,

            supplier_price REAL NOT NULL DEFAULT 0
                CHECK (supplier_price >= 0),

            is_primary INTEGER NOT NULL DEFAULT 0
                CHECK (is_primary IN (0, 1)),

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            UNIQUE (
                product_id,
                supplier_id
            ),

            FOREIGN KEY (product_id)
                REFERENCES products(id)
                ON DELETE CASCADE,

            FOREIGN KEY (supplier_id)
                REFERENCES suppliers(id)
                ON DELETE CASCADE
        )
    ");


    /*
    |--------------------------------------------------------------------------
    | SALES
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sales (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            transaction_no TEXT NOT NULL UNIQUE,

            cashier_id INTEGER NOT NULL,

            subtotal REAL NOT NULL DEFAULT 0
                CHECK (subtotal >= 0),

            tax_rate REAL NOT NULL DEFAULT 0
                CHECK (tax_rate >= 0),

            tax_amount REAL NOT NULL DEFAULT 0
                CHECK (tax_amount >= 0),

            discount_type TEXT NOT NULL DEFAULT 'None'
                CHECK (
                    discount_type IN (
                        'None',
                        'PWD',
                        'Senior'
                    )
                ),

            discount_percent REAL NOT NULL DEFAULT 0
                CHECK (discount_percent >= 0),

            discount_amount REAL NOT NULL DEFAULT 0
                CHECK (discount_amount >= 0),

            discount_customer_name TEXT,
            discount_id_number TEXT,

            total_amount REAL NOT NULL DEFAULT 0
                CHECK (total_amount >= 0),

            payment_method TEXT NOT NULL
                CHECK (
                    payment_method IN (
                        'Cash',
                        'GCash',
                        'Maya',
                        'MariBank'
                    )
                ),

            payment_reference TEXT,

            amount_tendered REAL NOT NULL DEFAULT 0
                CHECK (amount_tendered >= 0),

            change_amount REAL NOT NULL DEFAULT 0
                CHECK (change_amount >= 0),

            status TEXT NOT NULL DEFAULT 'Completed'
                CHECK (
                    status IN (
                        'Completed',
                        'Voided'
                    )
                ),

            voided_by INTEGER,
            void_reason TEXT,
            voided_at TEXT,

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (cashier_id)
                REFERENCES users(id),

            FOREIGN KEY (voided_by)
                REFERENCES users(id)
        )
    ");


    /*
    |--------------------------------------------------------------------------
    | SALE ITEMS
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sale_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            sale_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,

            barcode TEXT NOT NULL,
            product_name TEXT NOT NULL,

            quantity INTEGER NOT NULL
                CHECK (quantity > 0),

            unit_price REAL NOT NULL
                CHECK (unit_price >= 0),

            line_total REAL NOT NULL
                CHECK (line_total >= 0),

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (sale_id)
                REFERENCES sales(id)
                ON DELETE CASCADE,

            FOREIGN KEY (product_id)
                REFERENCES products(id)
        )
    ");


    /*
    |--------------------------------------------------------------------------
    | INVENTORY LOGS
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inventory_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            product_id INTEGER NOT NULL,

            user_id INTEGER,
            supplier_id INTEGER,
            sale_id INTEGER,

            action TEXT NOT NULL
                CHECK (
                    action IN (
                        'Initial Stock',
                        'Restock',
                        'Sale',
                        'Adjustment',
                        'Void Return',
                        'Damaged',
                        'Expired'
                    )
                ),

            quantity_change INTEGER NOT NULL,

            previous_stock INTEGER NOT NULL,
            new_stock INTEGER NOT NULL,

            notes TEXT,

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (product_id)
                REFERENCES products(id),

            FOREIGN KEY (user_id)
                REFERENCES users(id),

            FOREIGN KEY (supplier_id)
                REFERENCES suppliers(id),

            FOREIGN KEY (sale_id)
                REFERENCES sales(id)
        )
    ");


    /*
    |--------------------------------------------------------------------------
    | EMPLOYEES
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
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
                CHECK (
                    pay_type IN (
                        'Hourly',
                        'Monthly'
                    )
                ),

            pay_rate REAL NOT NULL DEFAULT 0
                CHECK (pay_rate >= 0),

            date_hired TEXT,

            status TEXT NOT NULL DEFAULT 'Active'
                CHECK (
                    status IN (
                        'Active',
                        'Inactive'
                    )
                ),

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (user_id)
                REFERENCES users(id)
                ON DELETE SET NULL
        )
    ");


    /*
    |--------------------------------------------------------------------------
    | PAYROLL
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payroll (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            employee_id INTEGER NOT NULL,

            period_start TEXT NOT NULL,
            period_end TEXT NOT NULL,

            hours_worked REAL NOT NULL DEFAULT 0
                CHECK (hours_worked >= 0),

            gross_pay REAL NOT NULL DEFAULT 0
                CHECK (gross_pay >= 0),

            deductions REAL NOT NULL DEFAULT 0
                CHECK (deductions >= 0),

            deduction_notes TEXT,

            net_pay REAL NOT NULL DEFAULT 0
                CHECK (net_pay >= 0),

            processed_by INTEGER NOT NULL,

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (employee_id)
                REFERENCES employees(id),

            FOREIGN KEY (processed_by)
                REFERENCES users(id)
        )
    ");


    /*
    |--------------------------------------------------------------------------
    | EXPENSES / LOSSES
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS expenses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            expense_type TEXT NOT NULL
                CHECK (
                    expense_type IN (
                        'Expense',
                        'Loss'
                    )
                ),

            category TEXT,

            description TEXT NOT NULL,

            amount REAL NOT NULL
                CHECK (amount >= 0),

            expense_date TEXT NOT NULL DEFAULT CURRENT_DATE,

            recorded_by INTEGER NOT NULL,

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (recorded_by)
                REFERENCES users(id)
        )
    ");


    /*
    |--------------------------------------------------------------------------
    | SYSTEM LOGS
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
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
    ");


    /*
    |--------------------------------------------------------------------------
    | INDEXES
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_products_barcode
        ON products(barcode)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_products_name
        ON products(product_name)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_sales_transaction_no
        ON sales(transaction_no)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_sales_cashier
        ON sales(cashier_id)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_sales_created_at
        ON sales(created_at)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_sale_items_sale
        ON sale_items(sale_id)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_sale_items_product
        ON sale_items(product_id)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_inventory_product
        ON inventory_logs(product_id)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_system_logs_user
        ON system_logs(user_id)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_system_logs_created
        ON system_logs(created_at)
    ");


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

    $checkTax = $pdo->prepare("
        SELECT id
        FROM tax_rates
        WHERE rate = ?
        LIMIT 1
    ");

    $insertTax = $pdo->prepare("
        INSERT INTO tax_rates (
            name,
            rate
        )
        VALUES (?, ?)
    ");

    foreach ($taxRates as $tax) {

        $checkTax->execute([
            $tax[1]
        ]);

        if (!$checkTax->fetch()) {

            $insertTax->execute([
                $tax[0],
                $tax[1]
            ]);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | DEFAULT SETTINGS
    |--------------------------------------------------------------------------
    */

    $settings = [

        [
            'store_name',
            'CompAcc POS',
            'Name displayed on the system and receipt'
        ],

        [
            'default_tax_rate',
            '12',
            'Default tax percentage'
        ],

        [
            'pwd_discount',
            '20',
            'PWD discount percentage'
        ],

        [
            'senior_discount',
            '20',
            'Senior Citizen discount percentage'
        ],

        [
            'low_stock_threshold',
            '10',
            'Default low stock warning quantity'
        ],

        [
            'expiration_warning_days',
            '30',
            'Number of days before expiration warning'
        ],

        [
            'gcash_qr',
            '',
            'GCash QR image path'
        ],

        [
            'maya_qr',
            '',
            'Maya QR image path'
        ],

        [
            'maribank_qr',
            '',
            'MariBank QR image path'
        ]
    ];


    $insertSetting = $pdo->prepare("
        INSERT OR IGNORE INTO settings (
            setting_key,
            setting_value,
            description
        )
        VALUES (?, ?, ?)
    ");


    foreach ($settings as $setting) {

        $insertSetting->execute(
            $setting
        );
    }


    /*
    |--------------------------------------------------------------------------
    | DEFAULT CATEGORIES
    |--------------------------------------------------------------------------
    */

    $categories = [
        'General',
        'Food',
        'Beverages',
        'Household',
        'Personal Care',
        'Others'
    ];


    $insertCategory = $pdo->prepare("
        INSERT OR IGNORE INTO categories (
            name
        )
        VALUES (?)
    ");


    foreach ($categories as $category) {

        $insertCategory->execute([
            $category
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | DEFAULT ACCOUNTS
    |--------------------------------------------------------------------------
    */

    $accounts = [

        [
            'admin',
            'admin123',
            'System',
            null,
            'Administrator',
            null,
            'Admin'
        ],

        [
            'manager',
            'manager123',
            'Store',
            null,
            'Manager',
            null,
            'Manager'
        ],

        [
            'cashier',
            'cashier123',
            'Store',
            null,
            'Cashier',
            null,
            'Cashier'
        ]
    ];


    $checkUser = $pdo->prepare("
        SELECT id
        FROM users
        WHERE username = ?
        LIMIT 1
    ");


    $insertUser = $pdo->prepare("
        INSERT INTO users (
            username,
            password,
            first_name,
            middle_name,
            last_name,
            suffix,
            role,
            status
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')
    ");


    foreach ($accounts as $account) {

        $checkUser->execute([
            $account[0]
        ]);

        if (!$checkUser->fetch()) {

            $hashedPassword =
                password_hash(
                    $account[1],
                    PASSWORD_DEFAULT
                );


            $insertUser->execute([
                $account[0],
                $hashedPassword,
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
    | COMPLETE
    |--------------------------------------------------------------------------
    */

    $pdo->commit();


    echo PHP_EOL;

    echo "============================================" . PHP_EOL;
    echo "COMPACC POS DATABASE SETUP COMPLETE" . PHP_EOL;
    echo "============================================" . PHP_EOL;

    echo PHP_EOL;

    echo "Tables:" . PHP_EOL;

    echo "- users" . PHP_EOL;
    echo "- settings" . PHP_EOL;
    echo "- tax_rates" . PHP_EOL;
    echo "- categories" . PHP_EOL;
    echo "- suppliers" . PHP_EOL;
    echo "- products" . PHP_EOL;
    echo "- product_suppliers" . PHP_EOL;
    echo "- sales" . PHP_EOL;
    echo "- sale_items" . PHP_EOL;
    echo "- inventory_logs" . PHP_EOL;
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

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo PHP_EOL;
    echo "DATABASE SETUP FAILED" . PHP_EOL;
    echo $e->getMessage() . PHP_EOL;
}