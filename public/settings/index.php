<?php

require_once __DIR__
    . '/../../app/middleware/role.php';

/*
| Everyone can open Settings for their own account (My Account tab).
| The system tabs and every system action below stay Admin-only.
*/
requireRole([
    'Admin',
    'Manager',
    'Cashier'
]);

require_once __DIR__
    . '/../../app/config/database.php';


$pageTitle = 'Settings';
$currentPage = 'settings';


$isSettingsAdmin =
    ($_SESSION['role'] ?? '') === 'Admin';


/*
| Tabs: key => [label, icon]. System tabs exist only for Admins.
*/
$settingsTabs = [
    'account' => ['My Account', 'person']
];

if ($isSettingsAdmin) {
    $settingsTabs += [
        'tax' => ['Sales & Tax', 'percent'],
        'business' => ['Business & Receipt', 'storefront'],
        'inventory' => ['Inventory & Reorder', 'inventory_2'],
        'qr' => ['Payment QR', 'qr_code_2'],
        'backup' => ['Backup & System', 'database']
    ];
}


$settingsTab =
    (string) ($_GET['tab'] ?? '');

if (!isset($settingsTabs[$settingsTab])) {
    $settingsTab =
        $isSettingsAdmin
            ? 'tax'
            : 'account';
}


/*
| The tab to return to after a save. Set by the POST handler below.
*/
$settingsReturnTab =
    $settingsTab;


/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {

    $_SESSION['csrf_token'] =
        bin2hex(
            random_bytes(32)
        );
}


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function settingsRedirect(): never
{
    global $settingsReturnTab;

    header(
        'Location: /settings/?tab='
        . rawurlencode((string) $settingsReturnTab)
    );
    exit;
}


function settingsFlash(
    string $type,
    string $message
): void {

    if ($type === 'success') {

        $_SESSION['settings_success'] =
            $message;

        return;
    }


    $_SESSION['settings_error'] =
        $message;
}


/*
| users.email and users.contact_number are added by
| tools/migrate_user_contact_fields.php. Until it has run on a computer,
| My Account shows those fields as unavailable instead of failing.
*/
function settingsUserHasContactColumns(
    PDO $pdo
): bool {

    static $hasColumns = null;

    if ($hasColumns === null) {

        $names =
            array_column(
                $pdo->query('PRAGMA table_info(users)')->fetchAll(),
                'name'
            );

        $hasColumns =
            in_array('email', $names, true) &&
            in_array('contact_number', $names, true);
    }

    return $hasColumns;
}


/*
| Database timestamps are UTC (CURRENT_TIMESTAMP); show them in PH time.
*/
function settingsLocalTime(
    ?string $utc,
    string $format = 'M d, Y · g:i A'
): string {

    if ($utc === null || trim($utc) === '') {
        return '—';
    }

    try {

        $date =
            new DateTime(
                $utc,
                new DateTimeZone('UTC')
            );

        $date->setTimezone(
            new DateTimeZone('Asia/Manila')
        );

        return $date->format($format);

    } catch (Throwable $error) {

        return $utc;
    }
}


/*
| COMPLETE_SALE -> Complete Sale
*/
function settingsActionLabel(
    string $action
): string {

    return ucwords(
        strtolower(
            str_replace('_', ' ', $action)
        )
    );
}


function getSettingValue(
    PDO $pdo,
    string $key,
    string $default = ''
): string {

    $statement =
        $pdo->prepare("
            SELECT setting_value
            FROM settings
            WHERE setting_key = ?
            LIMIT 1
        ");


    $statement->execute([
        $key
    ]);


    $value =
        $statement->fetchColumn();


    if (
        $value === false
        ||
        $value === null
    ) {

        return $default;
    }


    return (string) $value;
}


function saveSettingValue(
    PDO $pdo,
    string $key,
    string $value
): void {

    $check =
        $pdo->prepare("
            SELECT COUNT(*)
            FROM settings
            WHERE setting_key = ?
        ");


    $check->execute([
        $key
    ]);


    $exists =
        (int) $check->fetchColumn()
        > 0;


    if ($exists) {

        $update =
            $pdo->prepare("
                UPDATE settings
                SET setting_value = ?
                WHERE setting_key = ?
            ");


        $update->execute([
            $value,
            $key
        ]);


        return;
    }


    $insert =
        $pdo->prepare("
            INSERT INTO settings (
                setting_key,
                setting_value
            )
            VALUES (?, ?)
        ");


    $insert->execute([
        $key,
        $value
    ]);
}


function settingsFormatRate(
    float $rate
): string {

    return rtrim(
        rtrim(
            number_format(
                $rate,
                2,
                '.',
                ''
            ),
            '0'
        ),
        '.'
    );
}


function deleteOldQrFile(
    string $publicDirectory,
    string $relativePath
): void {

    $relativePath =
        trim(
            $relativePath
        );


    if ($relativePath === '') {
        return;
    }


    if (
        !str_starts_with(
            $relativePath,
            '/assets/images/payment_qr/'
        )
    ) {

        return;
    }


    $fullPath =
        $publicDirectory
        . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relativePath
        );


    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}


function uploadQrImage(
    array $file,
    string $paymentMethod,
    string $uploadDirectory
): string {

    if (
        !isset(
            $file['error'],
            $file['tmp_name'],
            $file['size']
        )
    ) {

        throw new RuntimeException(
            'Invalid uploaded file.'
        );
    }


    if (
        $file['error']
        !== UPLOAD_ERR_OK
    ) {

        throw new RuntimeException(
            'The QR image could not be uploaded.'
        );
    }


    $maximumFileSize =
        5 * 1024 * 1024;


    if (
        (int) $file['size']
        > $maximumFileSize
    ) {

        throw new RuntimeException(
            'QR image must be 5 MB or smaller.'
        );
    }


    $imageInfo =
        @getimagesize(
            $file['tmp_name']
        );


    if ($imageInfo === false) {

        throw new RuntimeException(
            'The uploaded file is not a valid image.'
        );
    }


    $imageType =
        $imageInfo[2]
        ?? null;


    $allowedTypes = [

        IMAGETYPE_PNG =>
            'png',

        IMAGETYPE_JPEG =>
            'jpg',

        IMAGETYPE_WEBP =>
            'webp'

    ];


    if (
        !isset(
            $allowedTypes[
                $imageType
            ]
        )
    ) {

        throw new RuntimeException(
            'Only PNG, JPG, and WebP images are allowed.'
        );
    }


    $extension =
        $allowedTypes[
            $imageType
        ];


    if (!is_dir($uploadDirectory)) {

        if (
            !mkdir(
                $uploadDirectory,
                0775,
                true
            )
            &&
            !is_dir(
                $uploadDirectory
            )
        ) {

            throw new RuntimeException(
                'Unable to create the QR image directory.'
            );
        }
    }


    $safeMethod =
        strtolower(
            preg_replace(
                '/[^a-zA-Z0-9_-]/',
                '',
                $paymentMethod
            )
        );


    $fileName =
        $safeMethod
        . '-qr-'
        . date('YmdHis')
        . '-'
        . bin2hex(
            random_bytes(3)
        )
        . '.'
        . $extension;


    $destination =
        $uploadDirectory
        . DIRECTORY_SEPARATOR
        . $fileName;


    if (
        !move_uploaded_file(
            $file['tmp_name'],
            $destination
        )
    ) {

        throw new RuntimeException(
            'Unable to save the QR image.'
        );
    }


    return
        '/assets/images/payment_qr/'
        . $fileName;
}


/*
|--------------------------------------------------------------------------
| DIRECTORIES
|--------------------------------------------------------------------------
*/

$publicDirectory =
    realpath(
        __DIR__ . '/..'
    );


if ($publicDirectory === false) {

    throw new RuntimeException(
        'Unable to locate the public directory.'
    );
}


$qrUploadDirectory =
    $publicDirectory
    . DIRECTORY_SEPARATOR
    . 'assets'
    . DIRECTORY_SEPARATOR
    . 'images'
    . DIRECTORY_SEPARATOR
    . 'payment_qr';


/*
|--------------------------------------------------------------------------
| PAYMENT METHODS
|--------------------------------------------------------------------------
*/

$paymentMethods = [

    'gcash' => [
        'label' =>
            'GCash',

        'setting_key' =>
            'gcash_qr',

        'icon' =>
            'qr_code_2'
    ],

    'maya' => [
        'label' =>
            'Maya',

        'setting_key' =>
            'maya_qr',

        'icon' =>
            'qr_code'
    ],

    'maribank' => [
        'label' =>
            'MariBank',

        'setting_key' =>
            'maribank_qr',

        'icon' =>
            'account_balance'
    ]

];


/*
|--------------------------------------------------------------------------
| DATABASE BACKUP DIRECTORY
|--------------------------------------------------------------------------
*/

$backupDirectory =
    dirname(
        __DIR__,
        2
    )
    . DIRECTORY_SEPARATOR
    . 'storage'
    . DIRECTORY_SEPARATOR
    . 'backups';


if (
    !is_dir(
        $backupDirectory
    )
) {

    @mkdir(
        $backupDirectory,
        0775,
        true
    );
}


/*
|--------------------------------------------------------------------------
| DOWNLOAD DATABASE BACKUP
|--------------------------------------------------------------------------
*/

if (
    isset(
        $_GET[
            'download_backup'
        ]
    )
) {

    if (!$isSettingsAdmin) {

        http_response_code(
            403
        );

        exit(
            'Only an Admin can download database backups.'
        );
    }


    $requestedBackup =
        basename(
            (string) $_GET[
                'download_backup'
            ]
        );


    if (
        !preg_match(
            '/^pos-backup-\d{8}-\d{6}-[A-F0-9]{4}\.sqlite$/',
            $requestedBackup
        )
    ) {

        http_response_code(
            400
        );

        exit(
            'Invalid backup file.'
        );
    }


    $backupPath =
        $backupDirectory
        . DIRECTORY_SEPARATOR
        . $requestedBackup;


    if (
        !is_file(
            $backupPath
        )
    ) {

        http_response_code(
            404
        );

        exit(
            'Backup file not found.'
        );
    }


    header(
        'Content-Type: application/octet-stream'
    );


    header(
        'Content-Disposition: attachment; filename="'
        . $requestedBackup
        . '"'
    );


    header(
        'Content-Length: '
        . filesize(
            $backupPath
        )
    );


    readfile(
        $backupPath
    );


    exit;
}


/*
|--------------------------------------------------------------------------
| HANDLE POST REQUESTS
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    $submittedToken =
        $_POST['csrf_token']
        ?? '';


    if (
        empty(
            $_SESSION['csrf_token']
        )
        ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $submittedToken
        )
    ) {

        settingsFlash(
            'error',
            'Invalid request. Please try again.'
        );


        settingsRedirect();
    }


    $action =
        trim(
            $_POST['action']
            ?? ''
        );


    $actionTabs = [
        'update_profile' => 'account',
        'change_password' => 'account',
        'update_tax_rate' => 'tax',
        'update_business_settings' => 'business',
        'update_inventory_settings' => 'inventory',
        'upload_payment_qr' => 'qr',
        'remove_payment_qr' => 'qr',
        'create_database_backup' => 'backup'
    ];

    $settingsReturnTab =
        $actionTabs[$action]
        ?? $settingsTab;


    /*
    | Only the My Account actions are open to every role. The server checks
    | this again here because hiding a tab does not stop a direct request.
    */
    if (
        !$isSettingsAdmin &&
        !in_array($action, ['update_profile', 'change_password'], true)
    ) {

        $settingsReturnTab =
            'account';

        settingsFlash(
            'error',
            'Only an Admin can change system settings.'
        );

        settingsRedirect();
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE OWN ACCOUNT (name, username, contact details)
    |--------------------------------------------------------------------------
    | Moved here from the old My Account page (/profile/). Same rules and
    | the same UPDATE_OWN_PROFILE system log, plus optional email and
    | contact number when the database has those columns.
    */

    if (
        $action ===
        'update_profile'
    ) {

        $accountUserId =
            (int) ($_SESSION['user_id'] ?? 0);

        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $middleName = trim((string) ($_POST['middle_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        $suffix = trim((string) ($_POST['suffix'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $contactNumber = trim((string) ($_POST['contact_number'] ?? ''));

        $hasContactColumns =
            settingsUserHasContactColumns($pdo);


        if (
            $firstName === '' ||
            $lastName === '' ||
            $username === ''
        ) {
            settingsFlash('error', 'First name, last name, and username are required.');
            settingsRedirect();
        }

        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            settingsFlash('error', 'Username must be 3–50 characters and may use letters, numbers, dots, underscores, or hyphens.');
            settingsRedirect();
        }

        if (
            $email !== '' &&
            (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL))
        ) {
            settingsFlash('error', 'Enter a valid email address, for example name@example.com.');
            settingsRedirect();
        }

        if (
            $contactNumber !== '' &&
            (
                !preg_match('/^[0-9+()\-\s]{7,20}$/', $contactNumber) ||
                strlen(preg_replace('/\D/', '', $contactNumber)) < 7
            )
        ) {
            settingsFlash('error', 'Enter a valid contact number, for example 0917 123 4567.');
            settingsRedirect();
        }


        $usernameCheck =
            $pdo->prepare("
                SELECT id
                FROM users
                WHERE username = ?
                  AND id <> ?
                LIMIT 1
            ");

        $usernameCheck->execute([
            $username,
            $accountUserId
        ]);

        if ($usernameCheck->fetch()) {
            settingsFlash('error', 'That username is already being used.');
            settingsRedirect();
        }


        try {

            $pdo->beginTransaction();

            $columns = [
                'username' => $username,
                'first_name' => $firstName,
                'middle_name' => $middleName !== '' ? $middleName : null,
                'last_name' => $lastName,
                'suffix' => $suffix !== '' ? $suffix : null
            ];

            if ($hasContactColumns) {
                $columns['email'] = $email !== '' ? $email : null;
                $columns['contact_number'] = $contactNumber !== '' ? $contactNumber : null;
            }

            $assignments =
                implode(
                    ', ',
                    array_map(
                        static fn (string $column): string => $column . ' = ?',
                        array_keys($columns)
                    )
                );

            $update =
                $pdo->prepare("
                    UPDATE users
                    SET {$assignments},
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");

            $update->execute([
                ...array_values($columns),
                $accountUserId
            ]);


            $pdo->prepare("
                INSERT INTO system_logs (
                    user_id,
                    action,
                    module,
                    record_type,
                    record_id,
                    details
                )
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([
                $accountUserId,
                'UPDATE_OWN_PROFILE',
                'Profile',
                'User',
                $accountUserId,
                $hasContactColumns
                    ? 'Updated personal profile, username and contact details.'
                    : 'Updated personal profile and username.'
            ]);


            $pdo->commit();


            $_SESSION['full_name'] =
                implode(
                    ' ',
                    array_filter(
                        [$firstName, $middleName, $lastName, $suffix],
                        static fn (string $value): bool => $value !== ''
                    )
                );

            if (array_key_exists('username', $_SESSION)) {
                $_SESSION['username'] = $username;
            }

            settingsFlash('success', 'Your account information has been updated.');

        } catch (Throwable $error) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('[settings] ' . $error->getMessage());

            settingsFlash('error', 'Your account could not be saved. Please try again.');
        }


        settingsRedirect();
    }


    /*
    |--------------------------------------------------------------------------
    | CHANGE OWN PASSWORD
    |--------------------------------------------------------------------------
    | Moved here from the old My Account page. Same rules and the same
    | CHANGE_OWN_PASSWORD system log.
    */

    if (
        $action ===
        'change_password'
    ) {

        $accountUserId =
            (int) ($_SESSION['user_id'] ?? 0);

        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');


        if (
            $currentPassword === '' ||
            $newPassword === '' ||
            $confirmPassword === ''
        ) {
            settingsFlash('error', 'Complete all password fields.');
            settingsRedirect();
        }

        if (strlen($newPassword) < 6) {
            settingsFlash('error', 'New password must contain at least 6 characters.');
            settingsRedirect();
        }

        if ($newPassword !== $confirmPassword) {
            settingsFlash('error', 'New password and confirmation do not match.');
            settingsRedirect();
        }


        $passwordStatement =
            $pdo->prepare("
                SELECT password
                FROM users
                WHERE id = ?
                LIMIT 1
            ");

        $passwordStatement->execute([
            $accountUserId
        ]);

        $storedPassword =
            (string) ($passwordStatement->fetchColumn() ?: '');


        if (
            $storedPassword === '' ||
            !password_verify($currentPassword, $storedPassword)
        ) {
            settingsFlash('error', 'Current password is incorrect.');
            settingsRedirect();
        }

        if (password_verify($newPassword, $storedPassword)) {
            settingsFlash('error', 'New password must be different from your current password.');
            settingsRedirect();
        }


        try {

            $pdo->beginTransaction();

            $pdo->prepare("
                UPDATE users
                SET password = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([
                password_hash($newPassword, PASSWORD_DEFAULT),
                $accountUserId
            ]);

            $pdo->prepare("
                INSERT INTO system_logs (
                    user_id,
                    action,
                    module,
                    record_type,
                    record_id,
                    details
                )
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([
                $accountUserId,
                'CHANGE_OWN_PASSWORD',
                'Profile',
                'User',
                $accountUserId,
                'Changed own account password.'
            ]);

            $pdo->commit();

            settingsFlash('success', 'Your password has been changed successfully.');

        } catch (Throwable $error) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('[settings] ' . $error->getMessage());

            settingsFlash('error', 'Your password could not be changed. Please try again.');
        }


        settingsRedirect();
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE VAT RATE
    |--------------------------------------------------------------------------
    */

    if (
        $action ===
        'update_tax_rate'
    ) {

        $rawTaxRate =
            trim(
                (string) (
                    $_POST['tax_rate']
                    ?? ''
                )
            );


        if (
            $rawTaxRate === ''
            ||
            !is_numeric(
                $rawTaxRate
            )
        ) {

            settingsFlash(
                'error',
                'Enter a valid VAT rate.'
            );


            settingsRedirect();
        }


        $taxRate =
            round(
                (float) $rawTaxRate,
                2
            );


        $allowedTaxRates = [
            12.0,
            16.0,
            20.0
        ];


        if (
            !in_array(
                $taxRate,
                $allowedTaxRates,
                true
            )
        ) {

            settingsFlash(
                'error',
                'Choose one of the supported VAT rates: 12%, 16%, or 20%.'
            );


            settingsRedirect();
        }


        try {

            $pdo->beginTransaction();


            saveSettingValue(
                $pdo,
                'default_tax_rate',
                settingsFormatRate(
                    $taxRate
                )
            );


            $log =
                $pdo->prepare("
                    INSERT INTO system_logs (
                        user_id,
                        action,
                        module,
                        details
                    )
                    VALUES (?, ?, ?, ?)
                ");


            $log->execute([

                $_SESSION[
                    'user_id'
                ],

                'UPDATE_TAX_RATE',

                'Settings',

                'Default VAT rate changed to '
                . settingsFormatRate(
                    $taxRate
                )
                . '%.'

            ]);


            $pdo->commit();


            settingsFlash(
                'success',
                'VAT rate updated to '
                . settingsFormatRate(
                    $taxRate
                )
                . '%.'
            );


        } catch (
            Throwable $error
        ) {

            if (
                $pdo->inTransaction()
            ) {

                $pdo->rollBack();
            }


            settingsFlash(
                'error',
                $error->getMessage()
            );
        }


        settingsRedirect();
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE BUSINESS / RECEIPT SETTINGS
    |--------------------------------------------------------------------------
    */

    if (
        $action ===
        'update_business_settings'
    ) {

        $businessName =
            trim(
                (string) (
                    $_POST[
                        'business_name'
                    ]
                    ?? ''
                )
            );


        $businessAddress =
            trim(
                (string) (
                    $_POST[
                        'business_address'
                    ]
                    ?? ''
                )
            );


        $businessContact =
            trim(
                (string) (
                    $_POST[
                        'business_contact'
                    ]
                    ?? ''
                )
            );


        $businessEmail =
            trim(
                (string) (
                    $_POST[
                        'business_email'
                    ]
                    ?? ''
                )
            );


        $receiptFooter =
            trim(
                (string) (
                    $_POST[
                        'receipt_footer_message'
                    ]
                    ?? ''
                )
            );


        $receiptShowCashier =
            isset(
                $_POST[
                    'receipt_show_cashier'
                ]
            )
                ? '1'
                : '0';


        if (
            $businessName === ''
        ) {

            settingsFlash(
                'error',
                'Business name is required.'
            );


            settingsRedirect();
        }


        if (
            strlen(
                $businessName
            )
            > 120
        ) {

            settingsFlash(
                'error',
                'Business name is too long.'
            );


            settingsRedirect();
        }


        if (
            $businessEmail !== ''
            &&
            !filter_var(
                $businessEmail,
                FILTER_VALIDATE_EMAIL
            )
        ) {

            settingsFlash(
                'error',
                'Enter a valid business email address.'
            );


            settingsRedirect();
        }


        if (
            strlen(
                $receiptFooter
            )
            > 180
        ) {

            settingsFlash(
                'error',
                'Receipt footer message must be 180 characters or fewer.'
            );


            settingsRedirect();
        }


        try {

            $pdo->beginTransaction();


            $businessSettings = [

                'business_name' =>
                    $businessName,

                'business_address' =>
                    $businessAddress,

                'business_contact' =>
                    $businessContact,

                'business_email' =>
                    $businessEmail,

                'receipt_footer_message' =>
                    $receiptFooter,

                'receipt_show_cashier' =>
                    $receiptShowCashier

            ];


            foreach (
                $businessSettings
                as $key =>
                $value
            ) {

                saveSettingValue(
                    $pdo,
                    $key,
                    $value
                );
            }


            $log =
                $pdo->prepare("
                    INSERT INTO system_logs (
                        user_id,
                        action,
                        module,
                        details
                    )
                    VALUES (?, ?, ?, ?)
                ");


            $log->execute([

                $_SESSION[
                    'user_id'
                ],

                'UPDATE_BUSINESS_SETTINGS',

                'Settings',

                'Business and receipt settings were updated.'

            ]);


            $pdo->commit();


            settingsFlash(
                'success',
                'Business and receipt settings updated.'
            );


        } catch (
            Throwable $error
        ) {

            if (
                $pdo->inTransaction()
            ) {

                $pdo->rollBack();
            }


            settingsFlash(
                'error',
                $error->getMessage()
            );
        }


        settingsRedirect();
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE INVENTORY / REORDER SETTINGS
    |--------------------------------------------------------------------------
    */

    if (
        $action ===
        'update_inventory_settings'
    ) {

        $inventoryValues = [

            'low_stock_threshold' =>
                (int) (
                    $_POST[
                        'low_stock_threshold'
                    ]
                    ?? 10
                ),

            'reorder_sales_window_days' =>
                (int) (
                    $_POST[
                        'reorder_sales_window_days'
                    ]
                    ?? 30
                ),

            'reorder_lead_time_days' =>
                (int) (
                    $_POST[
                        'reorder_lead_time_days'
                    ]
                    ?? 7
                ),

            'reorder_safety_days' =>
                (int) (
                    $_POST[
                        'reorder_safety_days'
                    ]
                    ?? 3
                ),

            'reorder_target_days' =>
                (int) (
                    $_POST[
                        'reorder_target_days'
                    ]
                    ?? 30
                ),

            'variant_reorder_fallback' =>
                (int) (
                    $_POST[
                        'variant_reorder_fallback'
                    ]
                    ?? 5
                ),

            'variant_target_stock_fallback' =>
                (int) (
                    $_POST[
                        'variant_target_stock_fallback'
                    ]
                    ?? 15
                )

        ];


        $valid =
            $inventoryValues[
                'low_stock_threshold'
            ] >= 0
            &&
            $inventoryValues[
                'low_stock_threshold'
            ] <= 9999
            &&
            $inventoryValues[
                'reorder_sales_window_days'
            ] >= 1
            &&
            $inventoryValues[
                'reorder_sales_window_days'
            ] <= 365
            &&
            $inventoryValues[
                'reorder_lead_time_days'
            ] >= 1
            &&
            $inventoryValues[
                'reorder_lead_time_days'
            ] <= 90
            &&
            $inventoryValues[
                'reorder_safety_days'
            ] >= 0
            &&
            $inventoryValues[
                'reorder_safety_days'
            ] <= 90
            &&
            $inventoryValues[
                'reorder_target_days'
            ] >= 1
            &&
            $inventoryValues[
                'reorder_target_days'
            ] <= 365
            &&
            $inventoryValues[
                'variant_reorder_fallback'
            ] >= 1
            &&
            $inventoryValues[
                'variant_reorder_fallback'
            ] <= 9999
            &&
            $inventoryValues[
                'variant_target_stock_fallback'
            ] >= 1
            &&
            $inventoryValues[
                'variant_target_stock_fallback'
            ] <= 9999;


        if (!$valid) {

            settingsFlash(
                'error',
                'One or more inventory settings are outside the allowed range.'
            );


            settingsRedirect();
        }


        if (
            $inventoryValues[
                'reorder_target_days'
            ]
            <
            (
                $inventoryValues[
                    'reorder_lead_time_days'
                ]
                +
                $inventoryValues[
                    'reorder_safety_days'
                ]
            )
        ) {

            settingsFlash(
                'error',
                'Target stock days must be at least the lead time plus safety days.'
            );


            settingsRedirect();
        }


        if (
            $inventoryValues[
                'variant_target_stock_fallback'
            ]
            <
            $inventoryValues[
                'variant_reorder_fallback'
            ]
        ) {

            settingsFlash(
                'error',
                'Fallback target stock cannot be lower than the fallback reorder point.'
            );


            settingsRedirect();
        }


        try {

            $pdo->beginTransaction();


            foreach (
                $inventoryValues
                as $key =>
                $value
            ) {

                saveSettingValue(
                    $pdo,
                    $key,
                    (string) $value
                );
            }


            $log =
                $pdo->prepare("
                    INSERT INTO system_logs (
                        user_id,
                        action,
                        module,
                        details
                    )
                    VALUES (?, ?, ?, ?)
                ");


            $log->execute([

                $_SESSION[
                    'user_id'
                ],

                'UPDATE_INVENTORY_SETTINGS',

                'Settings',

                'Inventory reorder and stock planning settings were updated.'

            ]);


            $pdo->commit();


            settingsFlash(
                'success',
                'Inventory and reorder settings updated.'
            );


        } catch (
            Throwable $error
        ) {

            if (
                $pdo->inTransaction()
            ) {

                $pdo->rollBack();
            }


            settingsFlash(
                'error',
                $error->getMessage()
            );
        }


        settingsRedirect();
    }


    /*
    |--------------------------------------------------------------------------
    | CREATE SQLITE BACKUP
    |--------------------------------------------------------------------------
    */

    if (
        $action ===
        'create_database_backup'
    ) {

        try {

            if (
                !isset(
                    $dbPath
                )
                ||
                !is_file(
                    $dbPath
                )
            ) {

                throw new RuntimeException(
                    'The SQLite database file could not be located.'
                );
            }


            if (
                !is_dir(
                    $backupDirectory
                )
                &&
                !mkdir(
                    $backupDirectory,
                    0775,
                    true
                )
                &&
                !is_dir(
                    $backupDirectory
                )
            ) {

                throw new RuntimeException(
                    'Unable to create the backup directory.'
                );
            }


            $now =
                new DateTimeImmutable(
                    'now',
                    new DateTimeZone(
                        'Asia/Manila'
                    )
                );


            $backupFileName =
                'pos-backup-'
                . $now->format(
                    'Ymd-His'
                )
                . '-'
                . strtoupper(
                    bin2hex(
                        random_bytes(2)
                    )
                )
                . '.sqlite';


            $backupPath =
                $backupDirectory
                . DIRECTORY_SEPARATOR
                . $backupFileName;


            if (
                class_exists(
                    'SQLite3'
                )
            ) {

                $sourceDatabase =
                    new SQLite3(
                        $dbPath,
                        SQLITE3_OPEN_READONLY
                    );


                $backupDatabase =
                    new SQLite3(
                        $backupPath
                    );


                $backupResult =
                    $sourceDatabase->backup(
                        $backupDatabase
                    );


                $backupDatabase->close();
                $sourceDatabase->close();


                if (!$backupResult) {

                    throw new RuntimeException(
                        'SQLite could not create the backup.'
                    );
                }


            } else {

                if (
                    !copy(
                        $dbPath,
                        $backupPath
                    )
                ) {

                    throw new RuntimeException(
                        'Unable to create the database backup.'
                    );
                }
            }


            $log =
                $pdo->prepare("
                    INSERT INTO system_logs (
                        user_id,
                        action,
                        module,
                        details
                    )
                    VALUES (?, ?, ?, ?)
                ");


            $log->execute([

                $_SESSION[
                    'user_id'
                ],

                'CREATE_DATABASE_BACKUP',

                'Settings',

                'Created database backup '
                . $backupFileName
                . '.'

            ]);


            settingsFlash(
                'success',
                'Database backup created successfully.'
            );


        } catch (
            Throwable $error
        ) {

            settingsFlash(
                'error',
                $error->getMessage()
            );
        }


        settingsRedirect();
    }


    /*
    |--------------------------------------------------------------------------
    | UPLOAD / REPLACE QR
    |--------------------------------------------------------------------------
    */

    if (
        $action ===
        'upload_payment_qr'
    ) {

        $method =
            strtolower(
                trim(
                    $_POST[
                        'payment_method'
                    ]
                    ?? ''
                )
            );


        if (
            !isset(
                $paymentMethods[
                    $method
                ]
            )
        ) {

            settingsFlash(
                'error',
                'Invalid payment method.'
            );


            settingsRedirect();
        }


        if (
            !isset(
                $_FILES['qr_image']
            )
        ) {

            settingsFlash(
                'error',
                'Please select a QR image.'
            );


            settingsRedirect();
        }


        $payment =
            $paymentMethods[
                $method
            ];


        $settingKey =
            $payment[
                'setting_key'
            ];


        $oldQrPath =
            getSettingValue(
                $pdo,
                $settingKey
            );


        try {

            $newQrPath =
                uploadQrImage(
                    $_FILES[
                        'qr_image'
                    ],
                    $method,
                    $qrUploadDirectory
                );


            $pdo->beginTransaction();


            saveSettingValue(
                $pdo,
                $settingKey,
                $newQrPath
            );


            $log =
                $pdo->prepare("
                    INSERT INTO system_logs (
                        user_id,
                        action,
                        module,
                        details
                    )
                    VALUES (?, ?, ?, ?)
                ");


            $log->execute([

                $_SESSION[
                    'user_id'
                ],

                'UPDATE_PAYMENT_QR',

                'Settings',

                $payment[
                    'label'
                ]
                . ' QR image was uploaded or replaced.'

            ]);


            $pdo->commit();


            if (
                $oldQrPath !==
                $newQrPath
            ) {

                deleteOldQrFile(
                    $publicDirectory,
                    $oldQrPath
                );
            }


            settingsFlash(
                'success',
                $payment[
                    'label'
                ]
                . ' QR image updated successfully.'
            );


        } catch (
            Throwable $error
        ) {

            if (
                $pdo->inTransaction()
            ) {

                $pdo->rollBack();
            }


            if (
                isset($newQrPath)
            ) {

                deleteOldQrFile(
                    $publicDirectory,
                    $newQrPath
                );
            }


            settingsFlash(
                'error',
                $error->getMessage()
            );
        }


        settingsRedirect();
    }


    /*
    |--------------------------------------------------------------------------
    | REMOVE QR
    |--------------------------------------------------------------------------
    */

    if (
        $action ===
        'remove_payment_qr'
    ) {

        $method =
            strtolower(
                trim(
                    $_POST[
                        'payment_method'
                    ]
                    ?? ''
                )
            );


        if (
            !isset(
                $paymentMethods[
                    $method
                ]
            )
        ) {

            settingsFlash(
                'error',
                'Invalid payment method.'
            );


            settingsRedirect();
        }


        $payment =
            $paymentMethods[
                $method
            ];


        $settingKey =
            $payment[
                'setting_key'
            ];


        $oldQrPath =
            getSettingValue(
                $pdo,
                $settingKey
            );


        try {

            $pdo->beginTransaction();


            saveSettingValue(
                $pdo,
                $settingKey,
                ''
            );


            $log =
                $pdo->prepare("
                    INSERT INTO system_logs (
                        user_id,
                        action,
                        module,
                        details
                    )
                    VALUES (?, ?, ?, ?)
                ");


            $log->execute([

                $_SESSION[
                    'user_id'
                ],

                'REMOVE_PAYMENT_QR',

                'Settings',

                $payment[
                    'label'
                ]
                . ' QR image was removed.'

            ]);


            $pdo->commit();


            deleteOldQrFile(
                $publicDirectory,
                $oldQrPath
            );


            settingsFlash(
                'success',
                $payment[
                    'label'
                ]
                . ' QR image removed.'
            );


        } catch (
            Throwable $error
        ) {

            if (
                $pdo->inTransaction()
            ) {

                $pdo->rollBack();
            }


            settingsFlash(
                'error',
                $error->getMessage()
            );
        }


        settingsRedirect();
    }
}


/*
|--------------------------------------------------------------------------
| FLASH MESSAGES
|--------------------------------------------------------------------------
*/

$settingsSuccess =
    $_SESSION[
        'settings_success'
    ]
    ?? null;


$settingsError =
    $_SESSION[
        'settings_error'
    ]
    ?? null;


unset(
    $_SESSION[
        'settings_success'
    ],
    $_SESSION[
        'settings_error'
    ]
);


/*
|--------------------------------------------------------------------------
| CURRENT QR VALUES
|--------------------------------------------------------------------------
*/

$currentQrCodes = [];


foreach (
    $paymentMethods
    as $method =>
    $payment
) {

    $currentQrCodes[
        $method
    ] =
        trim(
            getSettingValue(
                $pdo,
                $payment[
                    'setting_key'
                ]
            )
        );
}


$configuredQrCount =
    count(
        array_filter(
            $currentQrCodes,
            static fn (
                string $path
            ): bool =>
                trim(
                    $path
                ) !== ''
        )
    );


$totalQrMethods =
    count(
        $paymentMethods
    );


/*
|--------------------------------------------------------------------------
| SALES / TAX SETTINGS
|--------------------------------------------------------------------------
*/

$currentTaxRate =
    12.0;


$currentTaxValue =
    getSettingValue(
        $pdo,
        'default_tax_rate',
        '12'
    );


if (is_numeric($currentTaxValue)) {

    $candidate =
        (float) $currentTaxValue;


    if (
        $candidate >= 0
        &&
        $candidate <= 100
    ) {

        $currentTaxRate =
            round(
                $candidate,
                2
            );
    }
}


/*
|--------------------------------------------------------------------------
| BUSINESS / RECEIPT SETTINGS
|--------------------------------------------------------------------------
*/

$businessName =
    getSettingValue(
        $pdo,
        'business_name',
        'Underground Apparel'
    );


$businessAddress =
    getSettingValue(
        $pdo,
        'business_address',
        ''
    );


$businessContact =
    getSettingValue(
        $pdo,
        'business_contact',
        ''
    );


$businessEmail =
    getSettingValue(
        $pdo,
        'business_email',
        ''
    );


$receiptFooterMessage =
    getSettingValue(
        $pdo,
        'receipt_footer_message',
        'Thank you for shopping with us.'
    );


$receiptShowCashier =
    getSettingValue(
        $pdo,
        'receipt_show_cashier',
        '1'
    ) !== '0';


/*
|--------------------------------------------------------------------------
| INVENTORY / REORDER SETTINGS
|--------------------------------------------------------------------------
*/

$inventorySettings = [

    'low_stock_threshold' =>
        max(
            0,
            (int) getSettingValue(
                $pdo,
                'low_stock_threshold',
                '10'
            )
        ),

    'reorder_sales_window_days' =>
        max(
            1,
            (int) getSettingValue(
                $pdo,
                'reorder_sales_window_days',
                '30'
            )
        ),

    'reorder_lead_time_days' =>
        max(
            1,
            (int) getSettingValue(
                $pdo,
                'reorder_lead_time_days',
                '7'
            )
        ),

    'reorder_safety_days' =>
        max(
            0,
            (int) getSettingValue(
                $pdo,
                'reorder_safety_days',
                '3'
            )
        ),

    'reorder_target_days' =>
        max(
            1,
            (int) getSettingValue(
                $pdo,
                'reorder_target_days',
                '30'
            )
        ),

    'variant_reorder_fallback' =>
        max(
            1,
            (int) getSettingValue(
                $pdo,
                'variant_reorder_fallback',
                '5'
            )
        ),

    'variant_target_stock_fallback' =>
        max(
            1,
            (int) getSettingValue(
                $pdo,
                'variant_target_stock_fallback',
                '15'
            )
        )

];


/*
|--------------------------------------------------------------------------
| DATABASE / SYSTEM INFORMATION
|--------------------------------------------------------------------------
*/

$sqliteVersion =
    (string) $pdo
        ->query(
            'SELECT sqlite_version()'
        )
        ->fetchColumn();


$databaseSizeBytes =
    (
        isset(
            $dbPath
        )
        &&
        is_file(
            $dbPath
        )
    )
        ? (int) filesize(
            $dbPath
        )
        : 0;


$databaseSizeMb =
    $databaseSizeBytes > 0
        ? number_format(
            $databaseSizeBytes
            / 1024
            / 1024,
            2
        )
        : '0.00';


$backupFiles = [];


if (
    is_dir(
        $backupDirectory
    )
) {

    $backupMatches =
        glob(
            $backupDirectory
            . DIRECTORY_SEPARATOR
            . 'pos-backup-*.sqlite'
        )
        ?: [];


    usort(
        $backupMatches,
        static function (
            string $a,
            string $b
        ): int {

            return
                filemtime(
                    $b
                )
                <=>
                filemtime(
                    $a
                );
        }
    );


    foreach (
        array_slice(
            $backupMatches,
            0,
            8
        )
        as $backupPath
    ) {

        $modified =
            new DateTimeImmutable(
                '@'
                . filemtime(
                    $backupPath
                )
            );


        $modified =
            $modified->setTimezone(
                new DateTimeZone(
                    'Asia/Manila'
                )
            );


        $backupFiles[] = [

            'name' =>
                basename(
                    $backupPath
                ),

            'size' =>
                number_format(
                    filesize(
                        $backupPath
                    )
                    / 1024
                    / 1024,
                    2
                ),

            'date' =>
                $modified->format(
                    'M d, Y · g:i A'
                )

        ];
    }
}


$latestBackup =
    $backupFiles[0]
    ?? null;


/*
|--------------------------------------------------------------------------
| MY ACCOUNT
|--------------------------------------------------------------------------
| The signed-in user, their login / password history and recent actions.
| History comes from system_logs, which every module already writes.
*/

$accountUserId =
    (int) ($_SESSION['user_id'] ?? 0);

$hasContactColumns =
    settingsUserHasContactColumns($pdo);


$accountStatement =
    $pdo->prepare("
        SELECT *
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

$accountStatement->execute([
    $accountUserId
]);

$account =
    $accountStatement->fetch();


if (!$account) {

    session_destroy();

    header('Location: /login.php');
    exit;
}


$accountDisplayName =
    implode(
        ' ',
        array_filter(
            [
                trim((string) $account['first_name']),
                trim((string) ($account['middle_name'] ?? '')),
                trim((string) $account['last_name']),
                trim((string) ($account['suffix'] ?? ''))
            ],
            static fn (string $value): bool => $value !== ''
        )
    );


/*
| Logins: the newest is this session, the one before it is the previous
| sign-in (useful for noticing a login you did not make).
*/
$loginStatement =
    $pdo->prepare("
        SELECT created_at
        FROM system_logs
        WHERE user_id = ?
          AND action = 'LOGIN'
        ORDER BY created_at DESC, id DESC
        LIMIT 2
    ");

$loginStatement->execute([
    $accountUserId
]);

$accountLogins =
    $loginStatement->fetchAll(PDO::FETCH_COLUMN);


/*
| Password changes: by the user here, or by an Admin in Accounts.
*/
$passwordChangeStatement =
    $pdo->prepare("
        SELECT MAX(created_at)
        FROM system_logs
        WHERE (action = 'CHANGE_OWN_PASSWORD' AND user_id = ?)
           OR (
                action = 'UPDATE_ACCOUNT'
                AND record_id = ?
                AND details LIKE '%password changed%'
           )
    ");

$passwordChangeStatement->execute([
    $accountUserId,
    $accountUserId
]);

$accountPasswordChanged =
    $passwordChangeStatement->fetchColumn() ?: null;


/*
| Last update: by the user here, or by an Admin in Accounts. Older edits
| did not touch users.updated_at, so the logs are checked as well.
*/
$accountUpdateStatement =
    $pdo->prepare("
        SELECT MAX(created_at)
        FROM system_logs
        WHERE (action IN ('UPDATE_OWN_PROFILE', 'CHANGE_OWN_PASSWORD') AND user_id = ?)
           OR (action = 'UPDATE_ACCOUNT' AND record_id = ?)
    ");

$accountUpdateStatement->execute([
    $accountUserId,
    $accountUserId
]);

$accountLastUpdated =
    max(
        (string) ($accountUpdateStatement->fetchColumn() ?: ''),
        (string) ($account['updated_at'] ?? '')
    ) ?: null;


$activityStatement =
    $pdo->prepare("
        SELECT
            created_at,
            action,
            module,
            details
        FROM system_logs
        WHERE user_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT 10
    ");

$activityStatement->execute([
    $accountUserId
]);

$accountActivity =
    $activityStatement->fetchAll();


/*
|--------------------------------------------------------------------------
| PAGE LAYOUT
|--------------------------------------------------------------------------
*/

require_once __DIR__
    . '/../../app/views/partials/header.php';


require_once __DIR__
    . '/../../app/views/partials/sidebar.php';

?>

<link
    rel="stylesheet"
    href="/assets/css/settings.css?v=20261008-account"
>

<link
    rel="stylesheet"
    href="/assets/css/profile.css?v=20261008-account"
>


<div class="settings-page">


    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <section class="settings-hero">

        <div class="settings-hero-copy">

            <div class="settings-eyebrow">
                <?= $isSettingsAdmin ? 'SYSTEM CONFIGURATION' : 'ACCOUNT SETTINGS' ?>
            </div>

            <h2>
                Settings
            </h2>

            <p>
                <?= $isSettingsAdmin
                    ? 'Manage your account and configure the sales rules, receipts, stock planning and payment QR codes used throughout UA POS.'
                    : 'Manage your account information, contact details and password.' ?>
            </p>

        </div>


        <div class="settings-hero-badge">

            <span class="material-symbols-rounded">
                <?= $isSettingsAdmin ? 'admin_panel_settings' : 'badge' ?>
            </span>

            <?= htmlspecialchars(
                $isSettingsAdmin
                    ? 'Administrator Access'
                    : ($_SESSION['role'] ?? '') . ' Access'
            ) ?>

        </div>

    </section>



    <!-- =====================================================
         MESSAGES
    ====================================================== -->

    <?php if ($settingsSuccess): ?>

        <div class="settings-alert success">

            <span class="material-symbols-rounded">
                check_circle
            </span>

            <span>
                <?= htmlspecialchars(
                    $settingsSuccess
                ) ?>
            </span>

        </div>

    <?php endif; ?>


    <?php if ($settingsError): ?>

        <div class="settings-alert error">

            <span class="material-symbols-rounded">
                error
            </span>

            <span>
                <?= htmlspecialchars(
                    $settingsError
                ) ?>
            </span>

        </div>

    <?php endif; ?>



    <!-- =====================================================
         TABS (Admins only; other roles only have My Account)
    ====================================================== -->

    <?php if (count($settingsTabs) > 1): ?>

        <nav
            class="settings-tabs"
            aria-label="Settings sections"
        >

            <?php foreach ($settingsTabs as $tabKey => [$tabLabel, $tabIcon]): ?>

                <a
                    href="?tab=<?= $tabKey ?>"
                    class="<?= $settingsTab === $tabKey ? 'active' : '' ?>"
                    <?= $settingsTab === $tabKey ? 'aria-current="page"' : '' ?>
                >

                    <span class="material-symbols-rounded" aria-hidden="true">
                        <?= $tabIcon ?>
                    </span>

                    <?= htmlspecialchars($tabLabel) ?>

                </a>

            <?php endforeach; ?>

        </nav>

    <?php endif; ?>



    <!-- =====================================================
         MY ACCOUNT (every role)
    ====================================================== -->

    <?php if ($settingsTab === 'account'): ?>

        <section class="account-summary">

            <div class="profile-avatar">
                <?= htmlspecialchars(
                    strtoupper(
                        substr(
                            $account['first_name'] ?: $account['username'],
                            0,
                            1
                        )
                    )
                ) ?>
            </div>

            <div class="account-summary-copy">

                <strong>
                    <?= htmlspecialchars(
                        $accountDisplayName !== ''
                            ? $accountDisplayName
                            : $account['username']
                    ) ?>
                </strong>

                <div class="profile-meta">

                    <span>
                        <span class="material-symbols-rounded" aria-hidden="true">alternate_email</span>
                        <?= htmlspecialchars($account['username']) ?>
                    </span>

                    <span>
                        <span class="material-symbols-rounded" aria-hidden="true">badge</span>
                        <?= htmlspecialchars($account['role']) ?>
                    </span>

                    <span>
                        <span class="material-symbols-rounded" aria-hidden="true">verified_user</span>
                        <?= htmlspecialchars($account['status']) ?>
                    </span>

                </div>

            </div>

        </section>


        <section class="profile-grid">


            <!-- Personal, login and contact information -->

            <div class="profile-card">

                <div class="profile-card-header">

                    <span class="material-symbols-rounded">
                        person
                    </span>

                    <div>

                        <h3>
                            Personal & Login Information
                        </h3>

                        <p>
                            Your name, the username you sign in with,
                            and how to reach you.
                        </p>

                    </div>

                </div>


                <form
                    method="POST"
                    action="/settings/?tab=account"
                    class="profile-form"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="update_profile"
                    >


                    <div class="profile-form-grid">

                        <div class="profile-field">
                            <label for="profileFirstName">First Name</label>
                            <input
                                type="text"
                                id="profileFirstName"
                                name="first_name"
                                value="<?= htmlspecialchars($account['first_name']) ?>"
                                maxlength="100"
                                required
                            >
                        </div>

                        <div class="profile-field">
                            <label for="profileMiddleName">Middle Name</label>
                            <input
                                type="text"
                                id="profileMiddleName"
                                name="middle_name"
                                value="<?= htmlspecialchars((string) ($account['middle_name'] ?? '')) ?>"
                                maxlength="100"
                                placeholder="Optional"
                            >
                        </div>

                        <div class="profile-field">
                            <label for="profileLastName">Last Name</label>
                            <input
                                type="text"
                                id="profileLastName"
                                name="last_name"
                                value="<?= htmlspecialchars($account['last_name']) ?>"
                                maxlength="100"
                                required
                            >
                        </div>

                        <div class="profile-field">
                            <label for="profileSuffix">Suffix</label>
                            <input
                                type="text"
                                id="profileSuffix"
                                name="suffix"
                                value="<?= htmlspecialchars((string) ($account['suffix'] ?? '')) ?>"
                                maxlength="20"
                                placeholder="Optional"
                            >
                        </div>

                        <div class="profile-field profile-field-wide">
                            <label for="profileUsername">Username</label>
                            <input
                                type="text"
                                id="profileUsername"
                                name="username"
                                value="<?= htmlspecialchars($account['username']) ?>"
                                maxlength="50"
                                autocomplete="username"
                                required
                            >
                            <small>
                                3–50 characters. Letters, numbers, dots,
                                underscores, and hyphens are allowed.
                            </small>
                        </div>

                        <div class="profile-field">
                            <label for="profileEmail">Email</label>
                            <input
                                type="email"
                                id="profileEmail"
                                name="email"
                                value="<?= htmlspecialchars((string) ($account['email'] ?? '')) ?>"
                                maxlength="254"
                                autocomplete="email"
                                placeholder="<?= $hasContactColumns ? 'Optional' : 'Not available yet' ?>"
                                <?= $hasContactColumns ? '' : 'disabled' ?>
                            >
                        </div>

                        <div class="profile-field">
                            <label for="profileContactNumber">Contact Number</label>
                            <input
                                type="tel"
                                id="profileContactNumber"
                                name="contact_number"
                                value="<?= htmlspecialchars((string) ($account['contact_number'] ?? '')) ?>"
                                maxlength="20"
                                autocomplete="tel"
                                placeholder="<?= $hasContactColumns ? 'e.g. 0917 123 4567' : 'Not available yet' ?>"
                                <?= $hasContactColumns ? '' : 'disabled' ?>
                            >
                        </div>

                        <?php if (!$hasContactColumns): ?>

                            <small class="profile-field-wide account-contact-note">
                                <?= $isSettingsAdmin
                                    ? 'Email and contact number need a one-time database update on this computer: run tools\migrate_user_contact_fields.php.'
                                    : 'Email and contact number will be available after an Admin updates the system.' ?>
                            </small>

                        <?php endif; ?>

                    </div>


                    <button
                        type="submit"
                        class="profile-primary-button"
                    >

                        <span class="material-symbols-rounded">
                            save
                        </span>

                        Save Account

                    </button>

                </form>

            </div>


            <!-- Change password -->

            <div class="profile-card">

                <div class="profile-card-header">

                    <span class="material-symbols-rounded">
                        password
                    </span>

                    <div>

                        <h3>
                            Change Password
                        </h3>

                        <p>
                            Confirm your current password before
                            creating a new one.
                        </p>

                    </div>

                </div>


                <form
                    method="POST"
                    action="/settings/?tab=account"
                    class="profile-form"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="change_password"
                    >

                    <?php foreach (
                        [
                            ['currentPassword', 'current_password', 'Current Password', 'current-password', ''],
                            ['newPassword', 'new_password', 'New Password', 'new-password', 'Use at least 6 characters.'],
                            ['confirmPassword', 'confirm_password', 'Confirm New Password', 'new-password', '']
                        ]
                        as [$fieldId, $fieldName, $fieldLabel, $fieldAutocomplete, $fieldHelp]
                    ): ?>

                        <div class="profile-field">

                            <label for="<?= $fieldId ?>">
                                <?= $fieldLabel ?>
                            </label>

                            <div class="profile-password-field">

                                <input
                                    type="password"
                                    id="<?= $fieldId ?>"
                                    name="<?= $fieldName ?>"
                                    <?= $fieldName !== 'current_password' ? 'minlength="6"' : '' ?>
                                    autocomplete="<?= $fieldAutocomplete ?>"
                                    required
                                >

                                <button
                                    type="button"
                                    class="profile-password-toggle"
                                    data-password-target="<?= $fieldId ?>"
                                    aria-label="Show <?= strtolower($fieldLabel) ?>"
                                >
                                    <span class="material-symbols-rounded">
                                        visibility
                                    </span>
                                </button>

                            </div>

                            <?php if ($fieldHelp !== ''): ?>
                                <small><?= $fieldHelp ?></small>
                            <?php endif; ?>

                        </div>

                    <?php endforeach; ?>


                    <button
                        type="submit"
                        class="profile-primary-button"
                    >

                        <span class="material-symbols-rounded">
                            lock_reset
                        </span>

                        Change Password

                    </button>

                </form>

            </div>


            <!-- Recent activity -->

            <div class="profile-card">

                <div class="profile-card-header">

                    <span class="material-symbols-rounded">
                        history
                    </span>

                    <div>

                        <h3>
                            Recent Activity
                        </h3>

                        <p>
                            Your last 10 recorded actions.
                            <?php if ($isSettingsAdmin): ?>
                                <a href="/logs/" class="account-logs-link">Open System Logs</a>
                            <?php endif; ?>
                        </p>

                    </div>

                </div>


                <?php if ($accountActivity === []): ?>

                    <p class="account-activity-empty">
                        No activity recorded yet.
                    </p>

                <?php else: ?>

                    <ol class="account-activity">

                        <?php foreach ($accountActivity as $entry): ?>

                            <li>

                                <div class="account-activity-head">

                                    <strong>
                                        <?= htmlspecialchars(settingsActionLabel((string) $entry['action'])) ?>
                                    </strong>

                                    <time>
                                        <?= htmlspecialchars(settingsLocalTime($entry['created_at'])) ?>
                                    </time>

                                </div>

                                <p>
                                    <span class="account-activity-module">
                                        <?= htmlspecialchars((string) $entry['module']) ?>
                                    </span>

                                    <?= htmlspecialchars((string) ($entry['details'] ?? '')) ?>
                                </p>

                            </li>

                        <?php endforeach; ?>

                    </ol>

                <?php endif; ?>

            </div>


            <!-- Login and account history -->

            <div class="profile-card">

                <div class="profile-card-header">

                    <span class="material-symbols-rounded">
                        manage_history
                    </span>

                    <div>

                        <h3>
                            Login & Account Activity
                        </h3>

                        <p>
                            If a login here is not yours, change
                            your password right away.
                        </p>

                    </div>

                </div>


                <dl class="account-facts">

                    <div>
                        <dt>Signed in this session</dt>
                        <dd><?= htmlspecialchars(settingsLocalTime($accountLogins[0] ?? null)) ?></dd>
                    </div>

                    <div>
                        <dt>Previous login</dt>
                        <dd><?= htmlspecialchars(isset($accountLogins[1]) ? settingsLocalTime($accountLogins[1]) : 'No earlier login recorded') ?></dd>
                    </div>

                    <div>
                        <dt>Password last changed</dt>
                        <dd><?= htmlspecialchars($accountPasswordChanged ? settingsLocalTime($accountPasswordChanged) : 'Not changed since the account was created') ?></dd>
                    </div>

                    <div>
                        <dt>Account created</dt>
                        <dd><?= htmlspecialchars(settingsLocalTime($account['created_at'] ?? null, 'M d, Y')) ?></dd>
                    </div>

                    <div>
                        <dt>Details last updated</dt>
                        <dd><?= htmlspecialchars($accountLastUpdated ? settingsLocalTime($accountLastUpdated) : 'Never') ?></dd>
                    </div>

                </dl>

            </div>


        </section>

    <?php endif; ?>



    <?php if ($isSettingsAdmin && $settingsTab !== 'account'): ?>

    <!-- =====================================================
         CONFIGURATION OVERVIEW
    ====================================================== -->

    <section class="settings-overview">

        <article class="settings-overview-card">

            <div class="settings-overview-icon">

                <span class="material-symbols-rounded">
                    percent
                </span>

            </div>

            <div>

                <span>
                    Current VAT
                </span>

                <strong>
                    <?= htmlspecialchars(
                        settingsFormatRate(
                            $currentTaxRate
                        )
                    ) ?>%
                </strong>

                <small>
                    Applied automatically at checkout
                </small>

            </div>

        </article>


        <article class="settings-overview-card">

            <div class="settings-overview-icon">

                <span class="material-symbols-rounded">
                    qr_code_2
                </span>

            </div>

            <div>

                <span>
                    QR Methods Ready
                </span>

                <strong>
                    <?= $configuredQrCount ?>
                    / <?= $totalQrMethods ?>
                </strong>

                <small>
                    GCash, Maya and MariBank
                </small>

            </div>

        </article>


        <article class="settings-overview-card">

            <div class="settings-overview-icon">

                <span class="material-symbols-rounded">
                    lock
                </span>

            </div>

            <div>

                <span>
                    Configuration Access
                </span>

                <strong>
                    Admin Only
                </strong>

                <small>
                    Protected system-wide settings
                </small>

            </div>

        </article>


        <a
            href="?tab=account"
            class="settings-overview-card settings-overview-link"
        >

            <div class="settings-overview-icon">

                <span class="material-symbols-rounded">
                    person
                </span>

            </div>

            <div>

                <span>
                    Personal Settings
                </span>

                <strong>
                    My Account
                </strong>

                <small>
                    Profile, username and password
                </small>

            </div>

        </a>

    </section>



    <!-- =====================================================
         SALES & TAX
    ====================================================== -->

    <section class="settings-card" data-settings-tab="tax"<?= $settingsTab === 'tax' ? '' : ' hidden' ?>>

        <div class="settings-card-header">

            <div>

                <div class="settings-card-title">

                    <span class="material-symbols-rounded">
                        receipt_long
                    </span>

                    <div>

                        <h3>
                            Sales & Tax
                        </h3>

                        <p>
                            Configure the VAT percentage used
                            by every new POS transaction.
                        </p>

                    </div>

                </div>

            </div>


            <div class="admin-only-badge">

                <span class="material-symbols-rounded">
                    shield_lock
                </span>

                System Controlled

            </div>

        </div>


        <div class="settings-card-body">

            <div class="settings-info">

                <span class="material-symbols-rounded">
                    info
                </span>

                <div>

                    <strong>
                        One VAT rate for the entire sales terminal
                    </strong>

                    <p>
                        The configured rate is read automatically
                        by POS. Cashiers and managers cannot set
                        a different rate during checkout.
                    </p>

                </div>

            </div>


            <div class="tax-settings-layout">

                <form
                    method="POST"
                    action="/settings/"
                    class="tax-setting-panel"
                    id="taxSettingsForm"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars(
                            $_SESSION[
                                'csrf_token'
                            ]
                        ) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="update_tax_rate"
                    >


                    <div class="settings-field-heading">

                        <label for="taxRateSetting">
                            VAT Rate
                        </label>

                        <span>
                            Supported: 12%, 16%, 20%
                        </span>

                    </div>


                    <p>
                        Choose the VAT percentage UA POS should
                        apply when calculating new sales.
                    </p>


                    <div class="tax-rate-presets">

                        <span class="tax-presets-label">
                            Quick options
                        </span>

                        <div class="tax-preset-buttons">

                            <?php foreach ([12, 16, 20] as $presetRate): ?>

                                <button
                                    type="button"
                                    class="tax-preset-button <?= (float) $currentTaxRate === (float) $presetRate ? 'active' : '' ?>"
                                    data-tax-preset="<?= $presetRate ?>"
                                >
                                    <?= $presetRate ?>%
                                </button>

                            <?php endforeach; ?>

                        </div>

                    </div>


                    <div class="tax-rate-field">

                        <input
                            type="number"
                            id="taxRateSetting"
                            name="tax_rate"
                            min="0"
                            max="100"
                            step="1"
                            value="<?= htmlspecialchars(
                                settingsFormatRate(
                                    $currentTaxRate
                                )
                            ) ?>"
                            data-original-tax-rate="<?= htmlspecialchars(
                                settingsFormatRate(
                                    $currentTaxRate
                                )
                            ) ?>"
                            readonly
                            required
                        >

                        <span>%</span>

                    </div>


                    <div class="tax-save-row">

                        <button
                            type="submit"
                            class="settings-primary-button"
                            id="saveTaxButton"
                        >

                            <span class="material-symbols-rounded">
                                save
                            </span>

                            Save VAT Rate

                        </button>


                        <span
                            class="tax-change-note"
                            id="taxChangeNote"
                            hidden
                        >
                            Unsaved change
                        </span>

                    </div>

                </form>


                <div class="tax-current-panel">

                    <div class="tax-current-icon">

                        <span class="material-symbols-rounded">
                            point_of_sale
                        </span>

                    </div>

                    <span>
                        CURRENT POS VAT
                    </span>

                    <strong>
                        <?= htmlspecialchars(
                            settingsFormatRate(
                                $currentTaxRate
                            )
                        ) ?>%
                    </strong>

                    <small>
                        Automatically used at checkout
                    </small>

                </div>

            </div>

        </div>

    </section>



    <!-- =====================================================
         BUSINESS & RECEIPT
    ====================================================== -->

    <section class="settings-card" data-settings-tab="business"<?= $settingsTab === 'business' ? '' : ' hidden' ?>>

        <div class="settings-card-header">

            <div class="settings-card-title">

                <span class="material-symbols-rounded">
                    storefront
                </span>

                <div>

                    <h3>
                        Business & Receipt
                    </h3>

                    <p>
                        Manage the business information and receipt
                        preferences used by UA POS.
                    </p>

                </div>

            </div>


            <div class="admin-only-badge">

                <span class="material-symbols-rounded">
                    receipt
                </span>

                Receipt Settings

            </div>

        </div>


        <div class="settings-card-body">

            <form
                method="POST"
                action="/settings/"
                class="settings-form"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        $_SESSION[
                            'csrf_token'
                        ]
                    ) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="update_business_settings"
                >


                <div class="settings-form-grid two-column">

                    <div class="settings-field">

                        <label for="businessName">
                            Business Name
                        </label>

                        <input
                            type="text"
                            id="businessName"
                            name="business_name"
                            value="<?= htmlspecialchars(
                                $businessName
                            ) ?>"
                            maxlength="120"
                            required
                        >

                        <small>
                            Displayed on POS receipts.
                        </small>

                    </div>


                    <div class="settings-field">

                        <label for="businessContact">
                            Contact Number
                        </label>

                        <input
                            type="text"
                            id="businessContact"
                            name="business_contact"
                            value="<?= htmlspecialchars(
                                $businessContact
                            ) ?>"
                            maxlength="80"
                            placeholder="Optional"
                        >

                        <small>
                            Business contact information.
                        </small>

                    </div>


                    <div class="settings-field">

                        <label for="businessEmail">
                            Business Email
                        </label>

                        <input
                            type="email"
                            id="businessEmail"
                            name="business_email"
                            value="<?= htmlspecialchars(
                                $businessEmail
                            ) ?>"
                            maxlength="160"
                            placeholder="Optional"
                        >

                        <small>
                            Used as stored business information.
                        </small>

                    </div>


                    <div class="settings-field">

                        <label for="businessAddress">
                            Business Address
                        </label>

                        <input
                            type="text"
                            id="businessAddress"
                            name="business_address"
                            value="<?= htmlspecialchars(
                                $businessAddress
                            ) ?>"
                            maxlength="255"
                            placeholder="Optional"
                        >

                        <small>
                            Appears on the receipt when provided.
                        </small>

                    </div>


                    <div class="settings-field settings-field-wide">

                        <label for="receiptFooterMessage">
                            Receipt Footer Message
                        </label>

                        <input
                            type="text"
                            id="receiptFooterMessage"
                            name="receipt_footer_message"
                            value="<?= htmlspecialchars(
                                $receiptFooterMessage
                            ) ?>"
                            maxlength="180"
                            placeholder="Thank you for shopping with us."
                        >

                        <small>
                            Short message printed near the bottom of each receipt.
                        </small>

                    </div>


                    <label class="settings-toggle-row settings-field-wide">

                        <input
                            type="checkbox"
                            name="receipt_show_cashier"
                            value="1"
                            <?= $receiptShowCashier
                                ? 'checked'
                                : ''
                            ?>
                        >

                        <span class="settings-toggle-switch"></span>

                        <span class="settings-toggle-copy">

                            <strong>
                                Show cashier name on receipt
                            </strong>

                            <small>
                                Turn this off if you do not want the cashier's
                                name printed on customer receipts.
                            </small>

                        </span>

                    </label>

                </div>


                <div class="settings-form-actions">

                    <button
                        type="submit"
                        class="settings-primary-button settings-auto-width"
                    >

                        <span class="material-symbols-rounded">
                            save
                        </span>

                        Save Business Settings

                    </button>

                </div>

            </form>

        </div>

    </section>



    <!-- =====================================================
         INVENTORY & REORDER
    ====================================================== -->

    <section class="settings-card" data-settings-tab="inventory"<?= $settingsTab === 'inventory' ? '' : ' hidden' ?>>

        <div class="settings-card-header">

            <div class="settings-card-title">

                <span class="material-symbols-rounded">
                    inventory_2
                </span>

                <div>

                    <h3>
                        Inventory & Reorder
                    </h3>

                    <p>
                        Control the values used by automatic stock planning
                        and reorder recommendations.
                    </p>

                </div>

            </div>


            <div class="admin-only-badge">

                <span class="material-symbols-rounded">
                    monitoring
                </span>

                Automatic Reorder

            </div>

        </div>


        <div class="settings-card-body">

            <div class="settings-info">

                <span class="material-symbols-rounded">
                    calculate
                </span>

                <div>

                    <strong>
                        Reorder calculations use actual sales history
                    </strong>

                    <p>
                        When a variant has sales history, UA POS calculates
                        demand from the configured window, lead time and safety
                        days. Fallback stock values are used for variants that
                        do not yet have enough sales history.
                    </p>

                </div>

            </div>


            <form
                method="POST"
                action="/settings/"
                class="settings-form"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        $_SESSION[
                            'csrf_token'
                        ]
                    ) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="update_inventory_settings"
                >


                <div class="settings-form-grid inventory-grid">

                    <div class="settings-field">

                        <label for="salesWindowDays">
                            Sales History Window
                        </label>

                        <div class="settings-number-field">

                            <input
                                type="number"
                                id="salesWindowDays"
                                name="reorder_sales_window_days"
                                min="1"
                                max="365"
                                value="<?= $inventorySettings[
                                    'reorder_sales_window_days'
                                ] ?>"
                                required
                            >

                            <span>
                                days
                            </span>

                        </div>

                        <small>
                            Recent sales used to estimate demand.
                        </small>

                    </div>


                    <div class="settings-field">

                        <label for="leadTimeDays">
                            Supplier Lead Time
                        </label>

                        <div class="settings-number-field">

                            <input
                                type="number"
                                id="leadTimeDays"
                                name="reorder_lead_time_days"
                                min="1"
                                max="90"
                                value="<?= $inventorySettings[
                                    'reorder_lead_time_days'
                                ] ?>"
                                required
                            >

                            <span>
                                days
                            </span>

                        </div>

                        <small>
                            Expected wait before restocked items arrive.
                        </small>

                    </div>


                    <div class="settings-field">

                        <label for="safetyDays">
                            Safety Buffer
                        </label>

                        <div class="settings-number-field">

                            <input
                                type="number"
                                id="safetyDays"
                                name="reorder_safety_days"
                                min="0"
                                max="90"
                                value="<?= $inventorySettings[
                                    'reorder_safety_days'
                                ] ?>"
                                required
                            >

                            <span>
                                days
                            </span>

                        </div>

                        <small>
                            Extra demand buffer before stock reaches zero.
                        </small>

                    </div>


                    <div class="settings-field">

                        <label for="targetDays">
                            Target Stock Coverage
                        </label>

                        <div class="settings-number-field">

                            <input
                                type="number"
                                id="targetDays"
                                name="reorder_target_days"
                                min="1"
                                max="365"
                                value="<?= $inventorySettings[
                                    'reorder_target_days'
                                ] ?>"
                                required
                            >

                            <span>
                                days
                            </span>

                        </div>

                        <small>
                            Target number of sales days after restocking.
                        </small>

                    </div>


                    <div class="settings-field">

                        <label for="fallbackReorder">
                            Fallback Reorder Point
                        </label>

                        <div class="settings-number-field">

                            <input
                                type="number"
                                id="fallbackReorder"
                                name="variant_reorder_fallback"
                                min="1"
                                max="9999"
                                value="<?= $inventorySettings[
                                    'variant_reorder_fallback'
                                ] ?>"
                                required
                            >

                            <span>
                                pcs
                            </span>

                        </div>

                        <small>
                            Used for variants without sales history.
                        </small>

                    </div>


                    <div class="settings-field">

                        <label for="fallbackTarget">
                            Fallback Target Stock
                        </label>

                        <div class="settings-number-field">

                            <input
                                type="number"
                                id="fallbackTarget"
                                name="variant_target_stock_fallback"
                                min="1"
                                max="9999"
                                value="<?= $inventorySettings[
                                    'variant_target_stock_fallback'
                                ] ?>"
                                required
                            >

                            <span>
                                pcs
                            </span>

                        </div>

                        <small>
                            Target stock for a new or no-sales variant.
                        </small>

                    </div>


                    <div class="settings-field">

                        <label for="lowStockThreshold">
                            General Low-Stock Baseline
                        </label>

                        <div class="settings-number-field">

                            <input
                                type="number"
                                id="lowStockThreshold"
                                name="low_stock_threshold"
                                min="0"
                                max="9999"
                                value="<?= $inventorySettings[
                                    'low_stock_threshold'
                                ] ?>"
                                required
                            >

                            <span>
                                pcs
                            </span>

                        </div>

                        <small>
                            Retained as the general low-stock system baseline.
                        </small>

                    </div>

                </div>


                <div class="settings-form-actions">

                    <button
                        type="submit"
                        class="settings-primary-button settings-auto-width"
                    >

                        <span class="material-symbols-rounded">
                            save
                        </span>

                        Save Inventory Settings

                    </button>

                </div>

            </form>

        </div>

    </section>



    <!-- =====================================================
         PAYMENT QR SETTINGS
    ====================================================== -->

    <section class="settings-card" data-settings-tab="qr"<?= $settingsTab === 'qr' ? '' : ' hidden' ?>>

        <div class="settings-card-header">

            <div class="settings-card-title">

                <span class="material-symbols-rounded">
                    qr_code_2
                </span>

                <div>

                    <h3>
                        Payment QR Codes
                    </h3>

                    <p>
                        Manage the display-only QR images shown
                        during cashless POS transactions.
                    </p>

                </div>

            </div>


            <div class="settings-config-count">

                <span class="material-symbols-rounded">
                    verified
                </span>

                <?= $configuredQrCount ?>
                of
                <?= $totalQrMethods ?>
                configured

            </div>

        </div>


        <div class="settings-card-body">

            <div class="settings-info">

                <span class="material-symbols-rounded">
                    smartphone
                </span>

                <div>

                    <strong>
                        Display-only payment assistance
                    </strong>

                    <p>
                        UA POS only displays these QR images.
                        It does not connect directly to GCash,
                        Maya, or MariBank and does not verify
                        payment automatically.
                    </p>

                </div>

            </div>


            <div class="payment-qr-grid">


                <?php foreach (
                    $paymentMethods
                    as $method =>
                    $payment
                ): ?>


                    <?php

                    $qrPath =
                        $currentQrCodes[
                            $method
                        ];

                    $hasQr =
                        $qrPath !== '';

                    ?>


                    <article
                        class="payment-qr-card <?= $hasQr
                            ? 'configured'
                            : ''
                        ?>"
                    >


                        <div class="payment-qr-header">

                            <div class="payment-qr-icon">

                                <span class="material-symbols-rounded">
                                    <?= htmlspecialchars(
                                        $payment[
                                            'icon'
                                        ]
                                    ) ?>
                                </span>

                            </div>


                            <div class="payment-qr-heading-copy">

                                <h4>
                                    <?= htmlspecialchars(
                                        $payment[
                                            'label'
                                        ]
                                    ) ?>
                                </h4>


                                <span
                                    class="qr-status <?= $hasQr
                                        ? 'configured'
                                        : 'not-configured'
                                    ?>"
                                >

                                    <span class="qr-status-dot"></span>

                                    <?= $hasQr
                                        ? 'Ready for POS'
                                        : 'Not configured'
                                    ?>

                                </span>

                            </div>


                            <?php if ($hasQr): ?>

                                <span class="payment-ready-icon material-symbols-rounded">
                                    check_circle
                                </span>

                            <?php endif; ?>

                        </div>



                        <div
                            class="payment-qr-preview"
                            data-qr-preview
                        >


                            <?php if ($hasQr): ?>

                                <img
                                    src="<?= htmlspecialchars(
                                        $qrPath
                                    ) ?>"
                                    alt="<?= htmlspecialchars(
                                        $payment[
                                            'label'
                                        ]
                                    ) ?> QR Code"
                                    data-current-qr-image
                                >

                            <?php else: ?>

                                <div class="payment-qr-placeholder">

                                    <span class="material-symbols-rounded">
                                        qr_code_2
                                    </span>

                                    <strong>
                                        No QR Image
                                    </strong>

                                    <small>
                                        Upload a QR image to enable this option.
                                    </small>

                                </div>

                            <?php endif; ?>


                        </div>



                        <form
                            method="POST"
                            action="/settings/"
                            enctype="multipart/form-data"
                            class="qr-upload-form"
                            data-qr-upload-form
                        >

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars(
                                    $_SESSION[
                                        'csrf_token'
                                    ]
                                ) ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="upload_payment_qr"
                            >

                            <input
                                type="hidden"
                                name="payment_method"
                                value="<?= htmlspecialchars(
                                    $method
                                ) ?>"
                            >


                            <label class="qr-file-field">

                                <span class="material-symbols-rounded">
                                    upload_file
                                </span>


                                <span class="qr-file-copy">

                                    <strong
                                        class="qr-file-text"
                                        data-file-label
                                    >
                                        Choose QR image
                                    </strong>

                                    <small>
                                        PNG, JPG or WebP · Max 5 MB
                                    </small>

                                </span>


                                <input
                                    type="file"
                                    name="qr_image"
                                    accept="image/png,image/jpeg,image/webp"
                                    data-qr-file-input
                                    required
                                >

                            </label>


                            <div
                                class="qr-file-error"
                                data-file-error
                                hidden
                            ></div>


                            <button
                                type="submit"
                                class="settings-primary-button"
                                data-upload-button
                            >

                                <span class="material-symbols-rounded">
                                    <?= $hasQr
                                        ? 'sync'
                                        : 'upload'
                                    ?>
                                </span>

                                <span data-upload-button-text>
                                    <?= $hasQr
                                        ? 'Replace QR'
                                        : 'Upload QR'
                                    ?>
                                </span>

                            </button>

                        </form>



                        <?php if ($hasQr): ?>

                            <form
                                method="POST"
                                action="/settings/"
                                class="remove-qr-form"
                                id="removeQrForm-<?= htmlspecialchars(
                                    $method
                                ) ?>"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= htmlspecialchars(
                                        $_SESSION[
                                            'csrf_token'
                                        ]
                                    ) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="remove_payment_qr"
                                >

                                <input
                                    type="hidden"
                                    name="payment_method"
                                    value="<?= htmlspecialchars(
                                        $method
                                    ) ?>"
                                >


                                <button
                                    type="button"
                                    class="settings-remove-button"
                                    data-confirm
                                    data-confirm-title="Remove <?= htmlspecialchars(
                                        $payment[
                                            'label'
                                        ]
                                    ) ?> QR?"
                                    data-confirm-message="This QR image will no longer be available during <?= htmlspecialchars(
                                        $payment[
                                            'label'
                                        ]
                                    ) ?> payments."
                                    data-confirm-label="Remove QR"
                                    data-confirm-icon="delete"
                                    data-remove-qr-form="removeQrForm-<?= htmlspecialchars(
                                        $method
                                    ) ?>"
                                >

                                    <span class="material-symbols-rounded">
                                        delete
                                    </span>

                                    Remove QR

                                </button>

                            </form>

                        <?php endif; ?>


                    </article>


                <?php endforeach; ?>


            </div>

        </div>

    </section>


    <!-- =====================================================
         DATABASE & SYSTEM
    ====================================================== -->

    <section class="settings-card" data-settings-tab="backup"<?= $settingsTab === 'backup' ? '' : ' hidden' ?>>

        <div class="settings-card-header">

            <div class="settings-card-title">

                <span class="material-symbols-rounded">
                    database
                </span>

                <div>

                    <h3>
                        Database & Maintenance
                    </h3>

                    <p>
                        Create safe SQLite backups and review the
                        local system environment.
                    </p>

                </div>

            </div>


            <div class="admin-only-badge">

                <span class="material-symbols-rounded">
                    verified_user
                </span>

                Admin Only

            </div>

        </div>


        <div class="settings-card-body">

            <div class="maintenance-grid">


                <div class="maintenance-panel">

                    <div class="maintenance-panel-heading">

                        <div>

                            <span class="maintenance-kicker">
                                DATABASE BACKUP
                            </span>

                            <h4>
                                Protect the current POS data
                            </h4>

                        </div>

                        <span class="material-symbols-rounded">
                            backup
                        </span>

                    </div>


                    <p>
                        Create a SQLite backup before major updates,
                        migrations, or moving the database to another computer.
                    </p>


                    <form
                        method="POST"
                        action="/settings/"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars(
                                $_SESSION[
                                    'csrf_token'
                                ]
                            ) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="create_database_backup"
                        >


                        <button
                            type="submit"
                            class="settings-primary-button settings-auto-width"
                        >

                            <span class="material-symbols-rounded">
                                backup
                            </span>

                            Create Backup

                        </button>

                    </form>


                    <div class="latest-backup">

                        <span>
                            Latest Backup
                        </span>

                        <strong>
                            <?= htmlspecialchars(
                                $latestBackup[
                                    'date'
                                ]
                                ?? 'No backup created yet'
                            ) ?>
                        </strong>

                    </div>

                </div>



                <div class="maintenance-panel">

                    <div class="maintenance-panel-heading">

                        <div>

                            <span class="maintenance-kicker">
                                SYSTEM INFORMATION
                            </span>

                            <h4>
                                Local environment
                            </h4>

                        </div>

                        <span class="material-symbols-rounded">
                            info
                        </span>

                    </div>


                    <div class="system-info-list">

                        <div>
                            <span>Database</span>
                            <strong>SQLite <?= htmlspecialchars($sqliteVersion) ?></strong>
                        </div>

                        <div>
                            <span>Database Size</span>
                            <strong><?= htmlspecialchars($databaseSizeMb) ?> MB</strong>
                        </div>

                        <div>
                            <span>PHP</span>
                            <strong><?= htmlspecialchars(PHP_VERSION) ?></strong>
                        </div>

                        <div>
                            <span>Application Timezone</span>
                            <strong>Asia/Manila</strong>
                        </div>

                        <div>
                            <span>Backups Found</span>
                            <strong><?= count($backupFiles) ?></strong>
                        </div>

                    </div>

                </div>


            </div>


            <?php if (!empty($backupFiles)): ?>

                <div class="backup-history">

                    <div class="backup-history-heading">

                        <div>

                            <h4>
                                Recent Backups
                            </h4>

                            <p>
                                Backup files stay in
                                <code>storage/backups</code> and are not committed to Git.
                            </p>

                        </div>

                    </div>


                    <div class="backup-list">

                        <?php foreach ($backupFiles as $backup): ?>

                            <div class="backup-row">

                                <div class="backup-file">

                                    <span class="material-symbols-rounded">
                                        database
                                    </span>

                                    <div>

                                        <strong>
                                            <?= htmlspecialchars(
                                                $backup[
                                                    'name'
                                                ]
                                            ) ?>
                                        </strong>

                                        <span>
                                            <?= htmlspecialchars(
                                                $backup[
                                                    'date'
                                                ]
                                            ) ?>
                                            ·
                                            <?= htmlspecialchars(
                                                $backup[
                                                    'size'
                                                ]
                                            ) ?>
                                            MB
                                        </span>

                                    </div>

                                </div>


                                <a
                                    href="/settings/?download_backup=<?= urlencode(
                                        $backup[
                                            'name'
                                        ]
                                    ) ?>"
                                    class="settings-secondary-button"
                                >

                                    <span class="material-symbols-rounded">
                                        download
                                    </span>

                                    Download

                                </a>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

            <?php endif; ?>

        </div>

    </section>



    <!-- =====================================================
         SETTINGS NOTES
    ====================================================== -->

    <section class="settings-footer-note">

        <span class="material-symbols-rounded">
            history
        </span>

        <div>

            <strong>
                Configuration changes are audited
            </strong>

            <p>
                VAT, business, inventory, QR and database backup actions
                are recorded in System Logs for administrator review.
            </p>

        </div>

    </section>

    <?php endif; ?>


</div>



<?php if ($settingsTab === 'account'): ?>

<script>

/* Show / hide each password field. */
document
    .querySelectorAll('[data-password-target]')
    .forEach(button => {

        button.addEventListener('click', () => {

            const target =
                document.getElementById(button.dataset.passwordTarget);

            if (!target) {
                return;
            }

            const show =
                target.type === 'password';

            target.type =
                show ? 'text' : 'password';

            button.querySelector('.material-symbols-rounded').textContent =
                show ? 'visibility_off' : 'visibility';
        });
    });

</script>

<?php endif; ?>



<?php if ($isSettingsAdmin && $settingsTab !== 'account'): ?>

<script>

/* =========================================================
   TAX CHANGE INDICATOR
========================================================= */

const taxInput =
    document.getElementById(
        'taxRateSetting'
    );


const taxChangeNote =
    document.getElementById(
        'taxChangeNote'
    );


if (
    taxInput
    &&
    taxChangeNote
) {

    const originalTaxRate =
        Number(
            taxInput.dataset
                .originalTaxRate
        );


    function refreshTaxState() {

        const currentValue =
            Number(
                taxInput.value
            );


        const changed =
            Number.isFinite(
                currentValue
            )
            &&
            currentValue
            !== originalTaxRate;


        taxChangeNote.hidden =
            !changed;

    }


    function refreshTaxPresetState() {

        const currentValue =
            Number(
                taxInput.value
            );


        document
            .querySelectorAll(
                '[data-tax-preset]'
            )
            .forEach(
                button => {

                    const presetValue =
                        Number(
                            button.dataset
                                .taxPreset
                        );


                    button.classList.toggle(
                        'active',
                        Number.isFinite(
                            currentValue
                        )
                        &&
                        currentValue ===
                            presetValue
                    );

                }
            );

    }


    taxInput.addEventListener(
        'input',
        () => {

            refreshTaxState();
            refreshTaxPresetState();

        }
    );


    document
        .querySelectorAll(
            '[data-tax-preset]'
        )
        .forEach(
            button => {

                button.addEventListener(
                    'click',
                    () => {

                        taxInput.value =
                            button.dataset
                                .taxPreset;


                        refreshTaxState();
                        refreshTaxPresetState();


                        taxInput.focus();

                    }
                );

            }
        );


    refreshTaxPresetState();

}



/* =========================================================
   QR FILE SELECTION + LIVE PREVIEW
========================================================= */

document
    .querySelectorAll(
        '[data-qr-upload-form]'
    )
    .forEach(
        form => {


            const input =
                form.querySelector(
                    '[data-qr-file-input]'
                );


            const label =
                form.querySelector(
                    '[data-file-label]'
                );


            const errorBox =
                form.querySelector(
                    '[data-file-error]'
                );


            const uploadButtonText =
                form.querySelector(
                    '[data-upload-button-text]'
                );


            const card =
                form.closest(
                    '.payment-qr-card'
                );


            const preview =
                card.querySelector(
                    '[data-qr-preview]'
                );


            if (
                !input
                ||
                !label
                ||
                !errorBox
                ||
                !preview
            ) {
                return;
            }


            input.addEventListener(
                'change',
                () => {


                    errorBox.hidden =
                        true;


                    errorBox.textContent =
                        '';


                    if (
                        !input.files
                        ||
                        input.files.length === 0
                    ) {

                        label.textContent =
                            'Choose QR image';

                        return;
                    }


                    const file =
                        input.files[0];


                    const allowedTypes = [
                        'image/png',
                        'image/jpeg',
                        'image/webp'
                    ];


                    const maximumSize =
                        5 * 1024 * 1024;


                    if (
                        !allowedTypes.includes(
                            file.type
                        )
                    ) {

                        input.value =
                            '';


                        label.textContent =
                            'Choose QR image';


                        errorBox.textContent =
                            'Only PNG, JPG, and WebP images are allowed.';


                        errorBox.hidden =
                            false;


                        return;
                    }


                    if (
                        file.size
                        > maximumSize
                    ) {

                        input.value =
                            '';


                        label.textContent =
                            'Choose QR image';


                        errorBox.textContent =
                            'The selected image must be 5 MB or smaller.';


                        errorBox.hidden =
                            false;


                        return;
                    }


                    label.textContent =
                        file.name;


                    if (uploadButtonText) {

                        uploadButtonText.textContent =
                            card.classList.contains(
                                'configured'
                            )
                                ? 'Save Replacement'
                                : 'Upload QR';

                    }


                    const reader =
                        new FileReader();


                    reader.addEventListener(
                        'load',
                        () => {


                            preview.innerHTML =
                                '';


                            const image =
                                document.createElement(
                                    'img'
                                );


                            image.src =
                                reader.result;


                            image.alt =
                                'Selected QR image preview';


                            image.className =
                                'qr-selected-preview';


                            preview.appendChild(
                                image
                            );


                            preview.classList.add(
                                'pending-preview'
                            );

                        }
                    );


                    reader.readAsDataURL(
                        file
                    );

                }
            );


        }
    );



/* =========================================================
   GLOBAL CONFIRMATION MODAL - REMOVE QR
========================================================= */

let pendingRemoveQrForm =
    null;


document
    .querySelectorAll(
        '[data-remove-qr-form]'
    )
    .forEach(
        button => {

            button.addEventListener(
                'click',
                () => {

                    const formId =
                        button.dataset
                            .removeQrForm;


                    pendingRemoveQrForm =
                        document.getElementById(
                            formId
                        );

                }
            );

        }
    );


document.addEventListener(
    'DOMContentLoaded',
    () => {


        const systemConfirmSubmit =
            document.getElementById(
                'systemConfirmSubmit'
            );


        const systemConfirmCancel =
            document.getElementById(
                'systemConfirmCancel'
            );


        const systemConfirmClose =
            document.getElementById(
                'systemConfirmClose'
            );


        const systemConfirmBackdrop =
            document.getElementById(
                'systemConfirmBackdrop'
            );


        if (!systemConfirmSubmit) {
            return;
        }


        function resetPendingQrRemoval() {

            pendingRemoveQrForm =
                null;

        }


        systemConfirmSubmit.addEventListener(
            'click',
            () => {


                if (
                    !pendingRemoveQrForm
                ) {
                    return;
                }


                const form =
                    pendingRemoveQrForm;


                pendingRemoveQrForm =
                    null;


                form.submit();

            }
        );


        [
            systemConfirmCancel,
            systemConfirmClose,
            systemConfirmBackdrop
        ]
            .filter(Boolean)
            .forEach(
                element => {

                    element.addEventListener(
                        'click',
                        resetPendingQrRemoval
                    );

                }
            );


        document.addEventListener(
            'keydown',
            event => {


                if (
                    event.key ===
                    'Escape'
                ) {

                    resetPendingQrRemoval();

                }

            }
        );


    }
);

</script>

<?php endif; ?>


<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';

?>
