<?php

require_once __DIR__
    . '/../../app/middleware/auth.php';

require_once __DIR__
    . '/../../app/config/database.php';


$pageTitle =
    'My Account';

$currentPage =
    '';


if (empty($_SESSION['csrf_token'])) {

    $_SESSION['csrf_token'] =
        bin2hex(
            random_bytes(32)
        );
}


function profileRedirect(): never
{
    header('Location: /profile/');
    exit;
}


function profileFlash(
    string $type,
    string $message
): void {

    $_SESSION[
        $type === 'success'
            ? 'profile_success'
            : 'profile_error'
    ] =
        $message;
}


function profileFullName(
    array $user
): string {

    $parts = [];


    foreach (
        [
            'first_name',
            'middle_name',
            'last_name',
            'suffix'
        ]
        as $field
    ) {

        $value =
            trim(
                (string) (
                    $user[
                        $field
                    ]
                    ?? ''
                )
            );


        if ($value !== '') {
            $parts[] = $value;
        }
    }


    return implode(
        ' ',
        $parts
    );
}


$currentUserId =
    (int) (
        $_SESSION[
            'user_id'
        ]
        ?? 0
    );


if ($currentUserId <= 0) {

    header(
        'Location: /login.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| HANDLE FORM ACTIONS
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    $submittedToken =
        $_POST[
            'csrf_token'
        ]
        ?? '';


    if (
        empty(
            $_SESSION[
                'csrf_token'
            ]
        )
        ||
        !hash_equals(
            $_SESSION[
                'csrf_token'
            ],
            $submittedToken
        )
    ) {

        profileFlash(
            'error',
            'Invalid request. Please try again.'
        );


        profileRedirect();
    }


    $action =
        trim(
            (string) (
                $_POST[
                    'action'
                ]
                ?? ''
            )
        );


    /*
    |--------------------------------------------------------------------------
    | UPDATE OWN PROFILE
    |--------------------------------------------------------------------------
    */

    if (
        $action ===
        'update_profile'
    ) {

        $firstName =
            trim(
                (string) (
                    $_POST[
                        'first_name'
                    ]
                    ?? ''
                )
            );


        $middleName =
            trim(
                (string) (
                    $_POST[
                        'middle_name'
                    ]
                    ?? ''
                )
            );


        $lastName =
            trim(
                (string) (
                    $_POST[
                        'last_name'
                    ]
                    ?? ''
                )
            );


        $suffix =
            trim(
                (string) (
                    $_POST[
                        'suffix'
                    ]
                    ?? ''
                )
            );


        $username =
            trim(
                (string) (
                    $_POST[
                        'username'
                    ]
                    ?? ''
                )
            );


        if (
            $firstName === ''
            ||
            $lastName === ''
            ||
            $username === ''
        ) {

            profileFlash(
                'error',
                'First name, last name, and username are required.'
            );


            profileRedirect();
        }


        if (
            !preg_match(
                '/^[A-Za-z0-9._-]{3,50}$/',
                $username
            )
        ) {

            profileFlash(
                'error',
                'Username must be 3–50 characters and may use letters, numbers, dots, underscores, or hyphens.'
            );


            profileRedirect();
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
            $currentUserId
        ]);


        if (
            $usernameCheck->fetch()
        ) {

            profileFlash(
                'error',
                'That username is already being used.'
            );


            profileRedirect();
        }


        try {

            $pdo->beginTransaction();


            $update =
                $pdo->prepare("
                    UPDATE users
                    SET
                        username = ?,
                        first_name = ?,
                        middle_name = ?,
                        last_name = ?,
                        suffix = ?
                    WHERE id = ?
                ");


            $update->execute([

                $username,

                $firstName,

                $middleName !== ''
                    ? $middleName
                    : null,

                $lastName,

                $suffix !== ''
                    ? $suffix
                    : null,

                $currentUserId

            ]);


            $log =
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
                ");


            $log->execute([

                $currentUserId,

                'UPDATE_OWN_PROFILE',

                'Profile',

                'User',

                $currentUserId,

                'Updated personal profile and username.'

            ]);


            $pdo->commit();


            $sessionNameParts = [

                $firstName,

                $middleName,

                $lastName,

                $suffix

            ];


            $_SESSION[
                'full_name'
            ] =
                implode(
                    ' ',
                    array_values(
                        array_filter(
                            $sessionNameParts,
                            static fn (
                                string $value
                            ): bool =>
                                trim(
                                    $value
                                ) !== ''
                        )
                    )
                );


            if (
                array_key_exists(
                    'username',
                    $_SESSION
                )
            ) {

                $_SESSION[
                    'username'
                ] =
                    $username;
            }


            profileFlash(
                'success',
                'Your account information has been updated.'
            );


        } catch (
            Throwable $error
        ) {

            if (
                $pdo->inTransaction()
            ) {
                $pdo->rollBack();
            }


            profileFlash(
                'error',
                $error->getMessage()
            );
        }


        profileRedirect();
    }


    /*
    |--------------------------------------------------------------------------
    | CHANGE OWN PASSWORD
    |--------------------------------------------------------------------------
    */

    if (
        $action ===
        'change_password'
    ) {

        $currentPassword =
            (string) (
                $_POST[
                    'current_password'
                ]
                ?? ''
            );


        $newPassword =
            (string) (
                $_POST[
                    'new_password'
                ]
                ?? ''
            );


        $confirmPassword =
            (string) (
                $_POST[
                    'confirm_password'
                ]
                ?? ''
            );


        if (
            $currentPassword === ''
            ||
            $newPassword === ''
            ||
            $confirmPassword === ''
        ) {

            profileFlash(
                'error',
                'Complete all password fields.'
            );


            profileRedirect();
        }


        if (
            strlen(
                $newPassword
            )
            < 6
        ) {

            profileFlash(
                'error',
                'New password must contain at least 6 characters.'
            );


            profileRedirect();
        }


        if (
            $newPassword !==
            $confirmPassword
        ) {

            profileFlash(
                'error',
                'New password and confirmation do not match.'
            );


            profileRedirect();
        }


        $passwordStatement =
            $pdo->prepare("
                SELECT password
                FROM users
                WHERE id = ?
                LIMIT 1
            ");


        $passwordStatement->execute([
            $currentUserId
        ]);


        $storedPassword =
            (string) (
                $passwordStatement
                    ->fetchColumn()
                ?: ''
            );


        if (
            $storedPassword === ''
            ||
            !password_verify(
                $currentPassword,
                $storedPassword
            )
        ) {

            profileFlash(
                'error',
                'Current password is incorrect.'
            );


            profileRedirect();
        }


        if (
            password_verify(
                $newPassword,
                $storedPassword
            )
        ) {

            profileFlash(
                'error',
                'New password must be different from your current password.'
            );


            profileRedirect();
        }


        try {

            $pdo->beginTransaction();


            $passwordUpdate =
                $pdo->prepare("
                    UPDATE users
                    SET password = ?
                    WHERE id = ?
                ");


            $passwordUpdate->execute([

                password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                ),

                $currentUserId

            ]);


            $log =
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
                ");


            $log->execute([

                $currentUserId,

                'CHANGE_OWN_PASSWORD',

                'Profile',

                'User',

                $currentUserId,

                'Changed own account password.'

            ]);


            $pdo->commit();


            profileFlash(
                'success',
                'Your password has been changed successfully.'
            );


        } catch (
            Throwable $error
        ) {

            if (
                $pdo->inTransaction()
            ) {
                $pdo->rollBack();
            }


            profileFlash(
                'error',
                $error->getMessage()
            );
        }


        profileRedirect();
    }
}


/*
|--------------------------------------------------------------------------
| LOAD CURRENT USER
|--------------------------------------------------------------------------
*/

$userStatement =
    $pdo->prepare("
        SELECT
            id,
            username,
            first_name,
            middle_name,
            last_name,
            suffix,
            role,
            status,
            created_at
        FROM users
        WHERE id = ?
        LIMIT 1
    ");


$userStatement->execute([
    $currentUserId
]);


$user =
    $userStatement->fetch();


if (!$user) {

    session_destroy();

    header(
        'Location: /login.php'
    );

    exit;
}


$profileSuccess =
    $_SESSION[
        'profile_success'
    ]
    ?? null;


$profileError =
    $_SESSION[
        'profile_error'
    ]
    ?? null;


unset(
    $_SESSION[
        'profile_success'
    ],
    $_SESSION[
        'profile_error'
    ]
);


$displayName =
    profileFullName(
        $user
    );


try {

    $createdDate =
        new DateTime(
            (string) $user[
                'created_at'
            ],
            new DateTimeZone(
                'UTC'
            )
        );


    $createdDate->setTimezone(
        new DateTimeZone(
            'Asia/Manila'
        )
    );


    $createdDisplay =
        $createdDate->format(
            'M d, Y'
        );


} catch (
    Throwable $error
) {

    $createdDisplay =
        (string) (
            $user[
                'created_at'
            ]
            ?? '—'
        );
}


require_once __DIR__
    . '/../../app/views/partials/header.php';


require_once __DIR__
    . '/../../app/views/partials/sidebar.php';

?>

<link
    rel="stylesheet"
    href="/assets/css/profile.css?v=20260920"
>


<div class="profile-page">


    <section class="profile-hero">

        <div class="profile-avatar">

            <?= htmlspecialchars(
                strtoupper(
                    substr(
                        $user[
                            'first_name'
                        ]
                        ?: $user[
                            'username'
                        ],
                        0,
                        1
                    )
                )
            ) ?>

        </div>


        <div class="profile-hero-copy">

            <div class="profile-eyebrow">
                PERSONAL SETTINGS
            </div>

            <h2>
                <?= htmlspecialchars(
                    $displayName !== ''
                        ? $displayName
                        : $user[
                            'username'
                        ]
                ) ?>
            </h2>

            <p>
                Manage your own profile, login username,
                and password.
            </p>


            <div class="profile-meta">

                <span>

                    <span class="material-symbols-rounded">
                        badge
                    </span>

                    <?= htmlspecialchars(
                        $user[
                            'role'
                        ]
                    ) ?>

                </span>


                <span>

                    <span class="material-symbols-rounded">
                        verified_user
                    </span>

                    <?= htmlspecialchars(
                        $user[
                            'status'
                        ]
                    ) ?>

                </span>


                <span>

                    <span class="material-symbols-rounded">
                        calendar_today
                    </span>

                    Account since
                    <?= htmlspecialchars(
                        $createdDisplay
                    ) ?>

                </span>

            </div>

        </div>

    </section>


    <?php if ($profileSuccess): ?>

        <div class="profile-alert success">

            <span class="material-symbols-rounded">
                check_circle
            </span>

            <?= htmlspecialchars(
                $profileSuccess
            ) ?>

        </div>

    <?php endif; ?>


    <?php if ($profileError): ?>

        <div class="profile-alert error">

            <span class="material-symbols-rounded">
                error
            </span>

            <?= htmlspecialchars(
                $profileError
            ) ?>

        </div>

    <?php endif; ?>


    <section class="profile-grid">


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
                        Update your name and the username
                        you use to sign in.
                    </p>

                </div>

            </div>


            <form
                method="POST"
                action="/profile/"
                class="profile-form"
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
                    value="update_profile"
                >


                <div class="profile-form-grid">

                    <div class="profile-field">

                        <label for="profileFirstName">
                            First Name
                        </label>

                        <input
                            type="text"
                            id="profileFirstName"
                            name="first_name"
                            value="<?= htmlspecialchars(
                                $user[
                                    'first_name'
                                ]
                            ) ?>"
                            required
                        >

                    </div>


                    <div class="profile-field">

                        <label for="profileMiddleName">
                            Middle Name
                        </label>

                        <input
                            type="text"
                            id="profileMiddleName"
                            name="middle_name"
                            value="<?= htmlspecialchars(
                                (string) (
                                    $user[
                                        'middle_name'
                                    ]
                                    ?? ''
                                )
                            ) ?>"
                            placeholder="Optional"
                        >

                    </div>


                    <div class="profile-field">

                        <label for="profileLastName">
                            Last Name
                        </label>

                        <input
                            type="text"
                            id="profileLastName"
                            name="last_name"
                            value="<?= htmlspecialchars(
                                $user[
                                    'last_name'
                                ]
                            ) ?>"
                            required
                        >

                    </div>


                    <div class="profile-field">

                        <label for="profileSuffix">
                            Suffix
                        </label>

                        <input
                            type="text"
                            id="profileSuffix"
                            name="suffix"
                            value="<?= htmlspecialchars(
                                (string) (
                                    $user[
                                        'suffix'
                                    ]
                                    ?? ''
                                )
                            ) ?>"
                            placeholder="Optional"
                        >

                    </div>


                    <div class="profile-field profile-field-wide">

                        <label for="profileUsername">
                            Username
                        </label>

                        <input
                            type="text"
                            id="profileUsername"
                            name="username"
                            value="<?= htmlspecialchars(
                                $user[
                                    'username'
                                ]
                            ) ?>"
                            maxlength="50"
                            autocomplete="username"
                            required
                        >

                        <small>
                            3–50 characters. Letters, numbers,
                            dots, underscores, and hyphens are allowed.
                        </small>

                    </div>

                </div>


                <div class="profile-readonly-grid">

                    <div>

                        <span>
                            Role
                        </span>

                        <strong>
                            <?= htmlspecialchars(
                                $user[
                                    'role'
                                ]
                            ) ?>
                        </strong>

                    </div>


                    <div>

                        <span>
                            Account Status
                        </span>

                        <strong>
                            <?= htmlspecialchars(
                                $user[
                                    'status'
                                ]
                            ) ?>
                        </strong>

                    </div>

                </div>


                <button
                    type="submit"
                    class="profile-primary-button"
                >

                    <span class="material-symbols-rounded">
                        save
                    </span>

                    Save Profile

                </button>

            </form>

        </div>



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
                action="/profile/"
                class="profile-form"
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
                    value="change_password"
                >


                <div class="profile-field">

                    <label for="currentPassword">
                        Current Password
                    </label>

                    <div class="profile-password-field">

                        <input
                            type="password"
                            id="currentPassword"
                            name="current_password"
                            autocomplete="current-password"
                            required
                        >

                        <button
                            type="button"
                            class="profile-password-toggle"
                            data-password-target="currentPassword"
                            aria-label="Show current password"
                        >

                            <span class="material-symbols-rounded">
                                visibility
                            </span>

                        </button>

                    </div>

                </div>


                <div class="profile-field">

                    <label for="newPassword">
                        New Password
                    </label>

                    <div class="profile-password-field">

                        <input
                            type="password"
                            id="newPassword"
                            name="new_password"
                            minlength="6"
                            autocomplete="new-password"
                            required
                        >

                        <button
                            type="button"
                            class="profile-password-toggle"
                            data-password-target="newPassword"
                            aria-label="Show new password"
                        >

                            <span class="material-symbols-rounded">
                                visibility
                            </span>

                        </button>

                    </div>

                    <small>
                        Use at least 6 characters.
                    </small>

                </div>


                <div class="profile-field">

                    <label for="confirmPassword">
                        Confirm New Password
                    </label>

                    <div class="profile-password-field">

                        <input
                            type="password"
                            id="confirmPassword"
                            name="confirm_password"
                            minlength="6"
                            autocomplete="new-password"
                            required
                        >

                        <button
                            type="button"
                            class="profile-password-toggle"
                            data-password-target="confirmPassword"
                            aria-label="Show password confirmation"
                        >

                            <span class="material-symbols-rounded">
                                visibility
                            </span>

                        </button>

                    </div>

                </div>


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


    </section>


</div>



<script>

document
    .querySelectorAll(
        '[data-password-target]'
    )
    .forEach(
        button => {

            button.addEventListener(
                'click',
                () => {

                    const target =
                        document.getElementById(
                            button.dataset
                                .passwordTarget
                        );


                    if (!target) {
                        return;
                    }


                    const icon =
                        button.querySelector(
                            '.material-symbols-rounded'
                        );


                    const show =
                        target.type ===
                        'password';


                    target.type =
                        show
                            ? 'text'
                            : 'password';


                    icon.textContent =
                        show
                            ? 'visibility_off'
                            : 'visibility';

                }
            );

        }
    );

</script>


<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';

?>
