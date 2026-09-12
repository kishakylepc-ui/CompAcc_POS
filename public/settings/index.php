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


/*
|--------------------------------------------------------------------------
| GET SETTING
|--------------------------------------------------------------------------
*/

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
        $value === false ||
        $value === null
    ) {

        return $default;
    }


    return (string) $value;
}


/*
|--------------------------------------------------------------------------
| SAVE SETTING
|--------------------------------------------------------------------------
*/

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


function settingsFormatRate(float $rate): string
{
    return rtrim(
        rtrim(
            number_format($rate, 2, '.', ''),
            '0'
        ),
        '.'
    );
}


/*
|--------------------------------------------------------------------------
| DELETE OLD QR FILE
|--------------------------------------------------------------------------
*/

function deleteOldQrFile(
    string $publicDirectory,
    string $relativePath
): void {

    $relativePath =
        trim($relativePath);


    if ($relativePath === '') {
        return;
    }


    /*
     * Only delete files that belong to our
     * payment QR directory.
     */

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


    if (
        is_file($fullPath)
    ) {

        @unlink($fullPath);
    }
}


/*
|--------------------------------------------------------------------------
| UPLOAD QR IMAGE
|--------------------------------------------------------------------------
*/

function uploadQrImage(
    array $file,
    string $paymentMethod,
    string $uploadDirectory
): string {

    /*
    |--------------------------------------------------------------------------
    | BASIC UPLOAD CHECK
    |--------------------------------------------------------------------------
    */

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


    /*
    |--------------------------------------------------------------------------
    | FILE SIZE
    |--------------------------------------------------------------------------
    |
    | Maximum: 5 MB
    |
    */

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


    /*
    |--------------------------------------------------------------------------
    | CONFIRM IT IS AN IMAGE
    |--------------------------------------------------------------------------
    */

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


    /*
    |--------------------------------------------------------------------------
    | ALLOWED IMAGE TYPES
    |--------------------------------------------------------------------------
    */

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


    /*
    |--------------------------------------------------------------------------
    | CREATE DIRECTORY
    |--------------------------------------------------------------------------
    */

    if (
        !is_dir(
            $uploadDirectory
        )
    ) {

        if (
            !mkdir(
                $uploadDirectory,
                0775,
                true
            ) &&
            !is_dir(
                $uploadDirectory
            )
        ) {

            throw new RuntimeException(
                'Unable to create the QR image directory.'
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | SAFE FILE NAME
    |--------------------------------------------------------------------------
    */

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


    /*
    |--------------------------------------------------------------------------
    | MOVE UPLOAD
    |--------------------------------------------------------------------------
    */

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


    /*
    |--------------------------------------------------------------------------
    | RETURN BROWSER PATH
    |--------------------------------------------------------------------------
    */

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
| ALLOWED PAYMENT METHODS
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
| HANDLE POST REQUESTS
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    /*
    |--------------------------------------------------------------------------
    | CSRF
    |--------------------------------------------------------------------------
    */

    $submittedToken =
        $_POST['csrf_token']
        ?? '';


    if (
        empty(
            $_SESSION['csrf_token']
        ) ||
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
            || !is_numeric(
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


        if (
            $taxRate < 0
            || $taxRate > 100
        ) {

            settingsFlash(
                'error',
                'VAT rate must be between 0% and 100%.'
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

            /*
            |--------------------------------------------------------------------------
            | UPLOAD FIRST
            |--------------------------------------------------------------------------
            */

            $newQrPath =
                uploadQrImage(
                    $_FILES[
                        'qr_image'
                    ],
                    $method,
                    $qrUploadDirectory
                );


            /*
            |--------------------------------------------------------------------------
            | SAVE PATH TO SQLITE
            |--------------------------------------------------------------------------
            */

            $pdo->beginTransaction();


            saveSettingValue(
                $pdo,
                $settingKey,
                $newQrPath
            );


            /*
            |--------------------------------------------------------------------------
            | SYSTEM LOG
            |--------------------------------------------------------------------------
            */

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


            /*
            |--------------------------------------------------------------------------
            | REMOVE OLD IMAGE AFTER SUCCESS
            |--------------------------------------------------------------------------
            */

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


            /*
             * Remove the newly uploaded file if
             * database saving failed.
             */

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
    ] ?? null;


$settingsError =
    $_SESSION[
        'settings_error'
    ] ?? null;


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
| LOAD CURRENT QR VALUES
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


/*
|--------------------------------------------------------------------------
| LOAD SALES / TAX SETTINGS
|--------------------------------------------------------------------------
*/

$currentTaxRate = 12.0;


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
        && $candidate <= 100
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
    href="/assets/css/settings.css"
>


<style>
.tax-settings-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.25fr) minmax(240px, .75fr);
    gap: 18px;
    align-items: stretch;
}

.tax-setting-panel,
.tax-current-panel {
    padding: 18px;
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 12px;
    background: rgba(255, 255, 255, .025);
}

.tax-setting-panel label {
    display: block;
    margin-bottom: 7px;
    color: rgba(255, 255, 255, .82);
    font-size: 11px;
    font-weight: 600;
}

.tax-setting-panel > p {
    margin: 0 0 14px;
    color: rgba(255, 255, 255, .42);
    font-size: 10px;
    line-height: 1.6;
}

.tax-rate-field {
    position: relative;
    max-width: 240px;
}

.tax-rate-field input {
    width: 100%;
    height: 44px;
    padding: 0 44px 0 12px;
    border: 1px solid rgba(255, 255, 255, .13);
    border-radius: 8px;
    outline: none;
    background: #111214;
    color: #fff;
    font-family: "Poppins", sans-serif;
    font-size: 13px;
}

.tax-rate-field input:focus {
    border-color: rgba(208, 173, 123, .72);
    box-shadow: 0 0 0 3px rgba(208, 173, 123, .08);
}

.tax-rate-field span {
    position: absolute;
    top: 50%;
    right: 13px;
    transform: translateY(-50%);
    color: rgba(255, 255, 255, .46);
    font-size: 12px;
    pointer-events: none;
}

.tax-save-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 14px;
}

.tax-save-row .settings-primary-button {
    width: auto;
    min-width: 150px;
}

.tax-current-panel {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    text-align: center;
}

.tax-current-panel .material-symbols-rounded {
    margin-bottom: 8px;
    color: rgba(208, 173, 123, .9);
    font-size: 28px;
}

.tax-current-panel span {
    color: rgba(255, 255, 255, .42);
    font-size: 9px;
    letter-spacing: .12em;
    text-transform: uppercase;
}

.tax-current-panel strong {
    margin-top: 5px;
    color: #fff;
    font-size: 26px;
    font-weight: 600;
    letter-spacing: -.5px;
}

.tax-current-panel small {
    margin-top: 4px;
    color: rgba(255, 255, 255, .34);
    font-size: 9px;
}

@media (max-width: 760px) {
    .tax-settings-layout {
        grid-template-columns: 1fr;
    }

    .tax-rate-field {
        max-width: none;
    }
}
</style>


<div class="settings-page">


    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <div class="settings-page-header">

        <div>

            <div class="settings-eyebrow">
                SYSTEM CONFIGURATION
            </div>

            <h2>
                Settings
            </h2>

            <p>
                Manage administrator-controlled
                system settings.
            </p>

        </div>

    </div>



    <!-- =====================================================
         MESSAGES
    ====================================================== -->

    <?php if (
        $settingsSuccess
    ): ?>

        <div class="settings-alert success">

            <span class="material-symbols-rounded">
                check_circle
            </span>

            <?= htmlspecialchars(
                $settingsSuccess
            ) ?>

        </div>

    <?php endif; ?>


    <?php if (
        $settingsError
    ): ?>

        <div class="settings-alert error">

            <span class="material-symbols-rounded">
                error
            </span>

            <?= htmlspecialchars(
                $settingsError
            ) ?>

        </div>

    <?php endif; ?>



    <!-- =====================================================
         SALES & TAX SETTINGS
    ====================================================== -->

    <div class="settings-card">

        <div class="settings-card-header">

            <div>

                <div class="settings-card-title">

                    <span class="material-symbols-rounded">
                        percent
                    </span>

                    <h3>
                        Sales & Tax
                    </h3>

                </div>

                <p>
                    Configure the VAT rate used automatically
                    by every POS transaction.
                </p>

            </div>

            <div class="admin-only-badge">

                <span class="material-symbols-rounded">
                    admin_panel_settings
                </span>

                Admin Only

            </div>

        </div>

        <div class="settings-info">

            <span class="material-symbols-rounded">
                lock
            </span>

            <div>

                <strong>
                    POS tax is controlled here
                </strong>

                <p>
                    Cashiers and managers can see the configured
                    VAT rate in POS, but they cannot change it there.
                </p>

            </div>

        </div>

        <div class="tax-settings-layout">

            <form
                method="POST"
                action="/settings/"
                class="tax-setting-panel"
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

                <label for="taxRateSetting">
                    VAT Rate
                </label>

                <p>
                    Enter the percentage that CompAcc should
                    apply to new sales.
                </p>

                <div class="tax-rate-field">

                    <input
                        type="number"
                        id="taxRateSetting"
                        name="tax_rate"
                        min="0"
                        max="100"
                        step="0.01"
                        value="<?= htmlspecialchars(
                            settingsFormatRate(
                                $currentTaxRate
                            )
                        ) ?>"
                        required
                    >

                    <span>%</span>

                </div>

                <div class="tax-save-row">

                    <button
                        type="submit"
                        class="settings-primary-button"
                    >

                        <span class="material-symbols-rounded">
                            save
                        </span>

                        Save Tax Rate

                    </button>

                </div>

            </form>

            <div class="tax-current-panel">

                <span class="material-symbols-rounded">
                    receipt_long
                </span>

                <span>
                    Current POS VAT
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

        </div>

    </div>



    <!-- =====================================================
         PAYMENT QR SETTINGS
    ====================================================== -->

    <div class="settings-card">


        <div class="settings-card-header">

            <div>

                <div class="settings-card-title">

                    <span class="material-symbols-rounded">
                        qr_code_2
                    </span>

                    <h3>
                        Payment QR Settings
                    </h3>

                </div>


                <p>
                    Upload the QR images displayed
                    during cashless POS transactions.
                </p>

            </div>


            <div class="admin-only-badge">

                <span class="material-symbols-rounded">
                    admin_panel_settings
                </span>

                Admin Only

            </div>

        </div>



        <!-- =================================================
             INFORMATION
        ================================================== -->

        <div class="settings-info">

            <span class="material-symbols-rounded">
                info
            </span>

            <div>

                <strong>
                    Display-only QR payments
                </strong>

                <p>
                    These QR images are only shown to the
                    cashier and customer during payment.
                    CompAcc does not connect directly to
                    GCash, Maya, or MariBank.
                </p>

            </div>

        </div>



        <!-- =================================================
             QR GRID
        ================================================== -->

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


                <div class="payment-qr-card">


                    <!-- =====================================
                         PAYMENT HEADER
                    ====================================== -->

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


                        <div>

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

                                <?= $hasQr
                                    ? 'Configured'
                                    : 'Not configured'
                                ?>

                            </span>

                        </div>

                    </div>



                    <!-- =====================================
                         QR PREVIEW
                    ====================================== -->

                    <div class="payment-qr-preview">


                        <?php if (
                            $hasQr
                        ): ?>

                            <img
                                src="<?= htmlspecialchars(
                                    $qrPath
                                ) ?>"
                                alt="<?= htmlspecialchars(
                                    $payment[
                                        'label'
                                    ]
                                ) ?> QR Code"
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
                                    Upload an image below.
                                </small>

                            </div>


                        <?php endif; ?>


                    </div>



                    <!-- =====================================
                         UPLOAD FORM
                    ====================================== -->

                    <form
                        method="POST"
                        action="/settings/"
                        enctype="multipart/form-data"
                        class="qr-upload-form"
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


                        <label
                            class="qr-file-field"
                        >

                            <span class="material-symbols-rounded">
                                upload
                            </span>

                            <span
                                class="qr-file-text"
                                data-file-label
                            >

                                Choose QR image

                            </span>


                            <input
                                type="file"
                                name="qr_image"
                                accept="
                                    image/png,
                                    image/jpeg,
                                    image/webp
                                "
                                required
                            >

                        </label>


                        <small class="qr-upload-note">
                            PNG, JPG or WebP · Maximum 5 MB
                        </small>


                        <button
                            type="submit"
                            class="settings-primary-button"
                        >

                            <span class="material-symbols-rounded">
                                <?= $hasQr
                                    ? 'sync'
                                    : 'upload'
                                ?>
                            </span>

                            <?= $hasQr
                                ? 'Replace QR'
                                : 'Upload QR'
                            ?>

                        </button>

                    </form>



                    <!-- =====================================
                         REMOVE
                    ====================================== -->

                    <?php if (
                        $hasQr
                    ): ?>


                        <form
                            method="POST"
                            action="/settings/"
                            class="remove-qr-form"
                            onsubmit="
                                return confirm(
                                    'Remove this <?= htmlspecialchars(
                                        $payment[
                                            'label'
                                        ]
                                    ) ?> QR image?'
                                );
                            "
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
                                type="submit"
                                class="settings-remove-button"
                            >

                                <span class="material-symbols-rounded">
                                    delete
                                </span>

                                Remove QR

                            </button>

                        </form>


                    <?php endif; ?>


                </div>


            <?php endforeach; ?>


        </div>


    </div>

</div>



<script>

/* =========================================================
   FILE NAME DISPLAY
========================================================= */

document
    .querySelectorAll(
        '.qr-file-field input[type="file"]'
    )
    .forEach(
        input => {

            input.addEventListener(
                'change',
                () => {

                    const label =
                        input
                            .closest(
                                '.qr-file-field'
                            )
                            .querySelector(
                                '[data-file-label]'
                            );


                    if (
                        input.files &&
                        input.files.length > 0
                    ) {

                        label.textContent =
                            input.files[0].name;

                    } else {

                        label.textContent =
                            'Choose QR image';

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