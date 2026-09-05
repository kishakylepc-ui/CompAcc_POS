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