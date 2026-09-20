<?php

require_once __DIR__
    . '/../../app/middleware/role.php';

requireRole([
    'Admin'
]);

require_once __DIR__
    . '/../../app/config/database.php';


$pageTitle = 'Settings';
$currentPage = 'settings';


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
    header('Location: /settings/');
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
    href="/assets/css/settings.css?v=20260920"
>


<div class="settings-page">


    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <section class="settings-hero">

        <div class="settings-hero-copy">

            <div class="settings-eyebrow">
                SYSTEM CONFIGURATION
            </div>

            <h2>
                Settings
            </h2>

            <p>
                Configure the sales rules and payment QR
                assets used throughout UA POS.
            </p>

        </div>


        <div class="settings-hero-badge">

            <span class="material-symbols-rounded">
                admin_panel_settings
            </span>

            Administrator Access

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
            href="/profile/"
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

    <section class="settings-card">

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

    <section class="settings-card">

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

    <section class="settings-card">

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

    <section class="settings-card">

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

    <section class="settings-card">

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


</div>



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


<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';

?>
