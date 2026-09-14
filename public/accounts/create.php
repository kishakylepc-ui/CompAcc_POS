<?php

require_once __DIR__
    . '/../../app/middleware/role.php';

requireRole([
    'Admin'
]);

require_once __DIR__
    . '/../../app/config/database.php';


$pageTitle = 'Create Account';
$currentPage = 'accounts';

$error = null;


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (
    empty(
        $_SESSION['csrf_token']
    )
) {

    $_SESSION['csrf_token'] =
        bin2hex(
            random_bytes(32)
        );
}


/*
|--------------------------------------------------------------------------
| CREATE ACCOUNT
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    $submittedToken =
        (string) (
            $_POST['csrf_token']
            ?? ''
        );


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

        $error =
            'Invalid request. Please refresh the page and try again.';

    } else {


        $firstName =
            trim(
                $_POST['first_name']
                ?? ''
            );


        $middleName =
            trim(
                $_POST['middle_name']
                ?? ''
            );


        $lastName =
            trim(
                $_POST['last_name']
                ?? ''
            );


        $suffix =
            trim(
                $_POST['suffix']
                ?? ''
            );


        $username =
            trim(
                $_POST['username']
                ?? ''
            );


        $role =
            $_POST['role']
            ?? '';


        $password =
            $_POST['password']
            ?? '';


        $confirmPassword =
            $_POST['confirm_password']
            ?? '';


        /*
        |--------------------------------------------------------------------------
        | VALIDATION
        |--------------------------------------------------------------------------
        */

        if (
            $firstName === ''
            ||
            $lastName === ''
            ||
            $username === ''
            ||
            $role === ''
            ||
            $password === ''
            ||
            $confirmPassword === ''
        ) {

            $error =
                'Please complete all required fields.';

        } elseif (
            !in_array(
                $role,
                [
                    'Admin',
                    'Manager',
                    'Cashier'
                ],
                true
            )
        ) {

            $error =
                'Invalid account type.';

        } elseif (
            strlen(
                $password
            ) < 6
        ) {

            $error =
                'Password must contain at least 6 characters.';

        } elseif (
            $password
            !==
            $confirmPassword
        ) {

            $error =
                'Passwords do not match.';

        } else {


            /*
            |--------------------------------------------------------------------------
            | CHECK USERNAME
            |--------------------------------------------------------------------------
            */

            $check =
                $pdo->prepare("
                    SELECT id
                    FROM users
                    WHERE username = ?
                    LIMIT 1
                ");


            $check->execute([
                $username
            ]);


            if (
                $check->fetch()
            ) {

                $error =
                    'That username is already being used.';

            } else {


                try {


                    $pdo->beginTransaction();


                    /*
                    |--------------------------------------------------------------------------
                    | CREATE ACCOUNT
                    |--------------------------------------------------------------------------
                    */

                    $hashedPassword =
                        password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        );


                    $insert =
                        $pdo->prepare("
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


                    $insert->execute([

                        $username,

                        $hashedPassword,

                        $firstName,

                        $middleName !== ''
                            ? $middleName
                            : null,

                        $lastName,

                        $suffix !== ''
                            ? $suffix
                            : null,

                        $role

                    ]);


                    $newUserId =
                        (int) $pdo->lastInsertId();


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
                                record_type,
                                record_id,
                                details
                            )
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");


                    $log->execute([

                        $_SESSION[
                            'user_id'
                        ]
                        ?? null,

                        'CREATE_ACCOUNT',

                        'Accounts',

                        'User',

                        $newUserId,

                        'Created '
                        . $role
                        . ' account: '
                        . $username

                    ]);


                    $pdo->commit();


                    $_SESSION[
                        'success_message'
                    ] =
                        'Account created successfully.';


                    header(
                        'Location: /accounts/'
                    );

                    exit;


                } catch (
                    Throwable $errorObject
                ) {


                    if (
                        $pdo->inTransaction()
                    ) {

                        $pdo->rollBack();
                    }


                    $error =
                        'Unable to create account: '
                        . $errorObject->getMessage();

                }

            }

        }

    }

}


/*
|--------------------------------------------------------------------------
| LAYOUT
|--------------------------------------------------------------------------
*/

require_once __DIR__
    . '/../../app/views/partials/header.php';

?>

<link
    rel="stylesheet"
    href="/assets/css/accounts.css?v=20260914-ua"
>

<?php

require_once __DIR__
    . '/../../app/views/partials/sidebar.php';

?>


<div class="create-account-wrapper">


    <div class="account-form-page-header">


        <div>

            <a
                href="/accounts/"
                class="back-link"
            >

                <span class="material-symbols-rounded">
                    arrow_back
                </span>

                Back to Accounts

            </a>


            <div class="accounts-eyebrow">
                USER ACCESS
            </div>


            <h2>
                Create Account
            </h2>


            <p>
                Add a new Admin, Manager, or Cashier account
                for UA POS.
            </p>

        </div>


        <div class="account-form-header-icon">

            <span class="material-symbols-rounded">
                person_add
            </span>

        </div>


    </div>



    <?php if ($error): ?>

        <div class="accounts-alert error">

            <span class="material-symbols-rounded">
                error
            </span>

            <span>
                <?= htmlspecialchars(
                    $error
                ) ?>
            </span>

        </div>

    <?php endif; ?>



    <div class="create-account-card">


        <form
            action="/accounts/create.php"
            method="POST"
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



            <!-- =================================================
                 PERSONAL INFORMATION
            ================================================== -->

            <div class="form-section">


                <div class="form-section-title">


                    <span class="material-symbols-rounded">
                        person
                    </span>


                    <div>

                        <h3>
                            Personal Information
                        </h3>

                        <p>
                            Enter the account holder's name.
                        </p>

                    </div>


                </div>



                <div class="form-grid">


                    <div class="form-group">

                        <label for="first_name">

                            First Name

                            <span class="required">
                                *
                            </span>

                        </label>


                        <input
                            type="text"
                            id="first_name"
                            name="first_name"
                            class="form-control"
                            placeholder="e.g. Juan"
                            value="<?= htmlspecialchars(
                                $_POST[
                                    'first_name'
                                ]
                                ?? ''
                            ) ?>"
                            autocomplete="given-name"
                            required
                        >

                    </div>



                    <div class="form-group">

                        <label for="middle_name">
                            Middle Name
                        </label>


                        <input
                            type="text"
                            id="middle_name"
                            name="middle_name"
                            class="form-control"
                            placeholder="Optional"
                            value="<?= htmlspecialchars(
                                $_POST[
                                    'middle_name'
                                ]
                                ?? ''
                            ) ?>"
                            autocomplete="additional-name"
                        >

                    </div>



                    <div class="form-group">

                        <label for="last_name">

                            Last Name

                            <span class="required">
                                *
                            </span>

                        </label>


                        <input
                            type="text"
                            id="last_name"
                            name="last_name"
                            class="form-control"
                            placeholder="e.g. Dela Cruz"
                            value="<?= htmlspecialchars(
                                $_POST[
                                    'last_name'
                                ]
                                ?? ''
                            ) ?>"
                            autocomplete="family-name"
                            required
                        >

                    </div>



                    <div class="form-group">

                        <label for="suffix">
                            Suffix
                        </label>


                        <input
                            type="text"
                            id="suffix"
                            name="suffix"
                            class="form-control"
                            placeholder="Jr., Sr., III"
                            value="<?= htmlspecialchars(
                                $_POST[
                                    'suffix'
                                ]
                                ?? ''
                            ) ?>"
                            autocomplete="honorific-suffix"
                        >

                    </div>


                </div>


            </div>



            <div class="form-divider"></div>



            <!-- =================================================
                 ACCOUNT INFORMATION
            ================================================== -->

            <div class="form-section">


                <div class="form-section-title">


                    <span class="material-symbols-rounded">
                        manage_accounts
                    </span>


                    <div>

                        <h3>
                            Account Information
                        </h3>

                        <p>
                            Set the login details and account role.
                        </p>

                    </div>


                </div>



                <div class="form-grid">


                    <div class="form-group">

                        <label for="username">

                            Username

                            <span class="required">
                                *
                            </span>

                        </label>


                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="form-control"
                            placeholder="Enter username"
                            value="<?= htmlspecialchars(
                                $_POST[
                                    'username'
                                ]
                                ?? ''
                            ) ?>"
                            autocomplete="off"
                            required
                        >

                    </div>



                    <div class="form-group">

                        <label for="role">

                            Account Type

                            <span class="required">
                                *
                            </span>

                        </label>


                        <select
                            id="role"
                            name="role"
                            class="form-control"
                            required
                        >

                            <option value="">
                                Select account type
                            </option>


                            <option
                                value="Admin"
                                <?= (
                                    $_POST['role']
                                    ?? ''
                                ) === 'Admin'
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                Admin
                            </option>


                            <option
                                value="Manager"
                                <?= (
                                    $_POST['role']
                                    ?? ''
                                ) === 'Manager'
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                Manager
                            </option>


                            <option
                                value="Cashier"
                                <?= (
                                    $_POST['role']
                                    ?? ''
                                ) === 'Cashier'
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                Cashier
                            </option>


                        </select>

                    </div>



                    <div class="form-group">

                        <label for="password">

                            Password

                            <span class="required">
                                *
                            </span>

                        </label>


                        <div class="password-field">

                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="form-control"
                                placeholder="Minimum 6 characters"
                                minlength="6"
                                autocomplete="new-password"
                                required
                            >


                            <button
                                type="button"
                                class="password-eye"
                                data-target="password"
                                aria-label="Show password"
                            >

                                <span class="material-symbols-rounded">
                                    visibility
                                </span>

                            </button>

                        </div>

                    </div>



                    <div class="form-group">

                        <label for="confirm_password">

                            Confirm Password

                            <span class="required">
                                *
                            </span>

                        </label>


                        <div class="password-field">

                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                class="form-control"
                                placeholder="Repeat password"
                                minlength="6"
                                autocomplete="new-password"
                                required
                            >


                            <button
                                type="button"
                                class="password-eye"
                                data-target="confirm_password"
                                aria-label="Show password"
                            >

                                <span class="material-symbols-rounded">
                                    visibility
                                </span>

                            </button>

                        </div>

                    </div>


                </div>


            </div>



            <!-- =================================================
                 FORM ACTIONS
            ================================================== -->

            <div class="form-actions">


                <a
                    href="/accounts/"
                    class="accounts-secondary-button"
                >
                    Cancel
                </a>


                <button
                    type="submit"
                    class="accounts-primary-button"
                >

                    <span class="material-symbols-rounded">
                        person_add
                    </span>

                    Create Account

                </button>


            </div>


        </form>


    </div>


</div>



<script>

document
    .querySelectorAll(
        '.password-eye'
    )
    .forEach(
        button => {


            button.addEventListener(
                'click',
                function () {


                    const target =
                        document.getElementById(
                            this.dataset.target
                        );


                    const icon =
                        this.querySelector(
                            '.material-symbols-rounded'
                        );


                    if (!target) {
                        return;
                    }


                    const showing =
                        target.type ===
                        'text';


                    target.type =
                        showing
                            ? 'password'
                            : 'text';


                    icon.textContent =
                        showing
                            ? 'visibility'
                            : 'visibility_off';


                    this.setAttribute(
                        'aria-label',
                        showing
                            ? 'Show password'
                            : 'Hide password'
                    );

                }
            );


        }
    );

</script>


<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';

?>
