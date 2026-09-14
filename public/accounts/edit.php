<?php

require_once __DIR__
    . '/../../app/middleware/role.php';

requireRole([
    'Admin'
]);

require_once __DIR__
    . '/../../app/config/database.php';


$pageTitle = 'Edit Account';
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
| ACCOUNT ID
|--------------------------------------------------------------------------
*/

$accountId =
    (int) (
        $_POST['account_id']
        ?? $_GET['id']
        ?? 0
    );


if ($accountId <= 0) {

    $_SESSION['error_message'] =
        'Invalid account.';

    header(
        'Location: /accounts/'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| LOAD ACCOUNT
|--------------------------------------------------------------------------
*/

$accountStatement =
    $pdo->prepare("
        SELECT
            id,
            username,
            password,
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


$accountStatement->execute([
    $accountId
]);


$account =
    $accountStatement->fetch();


if (!$account) {

    $_SESSION['error_message'] =
        'Account not found.';

    header(
        'Location: /accounts/'
    );

    exit;
}


$currentUserId =
    (int) (
        $_SESSION['user_id']
        ?? 0
    );


$isCurrentAccount =
    (int) $account['id']
    === $currentUserId;


$originalStatus =
    (string) $account['status'];


/*
|--------------------------------------------------------------------------
| DISPLAY NAME HELPER
|--------------------------------------------------------------------------
*/

function buildAccountDisplayName(
    string $firstName,
    string $middleName,
    string $lastName,
    string $suffix
): string {

    $parts = [
        trim($firstName)
    ];


    if (
        trim($middleName)
        !== ''
    ) {

        $parts[] =
            trim($middleName);
    }


    $parts[] =
        trim($lastName);


    if (
        trim($suffix)
        !== ''
    ) {

        $parts[] =
            trim($suffix);
    }


    return implode(
        ' ',
        array_filter(
            $parts,
            static fn (
                string $part
            ): bool =>
                $part !== ''
        )
    );
}


/*
|--------------------------------------------------------------------------
| UPDATE ACCOUNT
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
                (string) (
                    $_POST['first_name']
                    ?? ''
                )
            );


        $middleName =
            trim(
                (string) (
                    $_POST['middle_name']
                    ?? ''
                )
            );


        $lastName =
            trim(
                (string) (
                    $_POST['last_name']
                    ?? ''
                )
            );


        $suffix =
            trim(
                (string) (
                    $_POST['suffix']
                    ?? ''
                )
            );


        $username =
            trim(
                (string) (
                    $_POST['username']
                    ?? ''
                )
            );


        $role =
            trim(
                (string) (
                    $_POST['role']
                    ?? ''
                )
            );


        $status =
            trim(
                (string) (
                    $_POST['status']
                    ?? ''
                )
            );


        $newPassword =
            (string) (
                $_POST['new_password']
                ?? ''
            );


        $confirmNewPassword =
            (string) (
                $_POST['confirm_new_password']
                ?? ''
            );


        /*
        |--------------------------------------------------------------------------
        | PROTECT CURRENT ADMIN ACCOUNT
        |--------------------------------------------------------------------------
        */

        if ($isCurrentAccount) {

            $role =
                'Admin';


            $status =
                'Active';
        }


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
            !in_array(
                $status,
                [
                    'Active',
                    'Inactive'
                ],
                true
            )
        ) {

            $error =
                'Invalid account status.';

        } elseif (
            $newPassword !== ''
            &&
            strlen(
                $newPassword
            ) < 6
        ) {

            $error =
                'New password must contain at least 6 characters.';

        } elseif (
            $newPassword !==
            $confirmNewPassword
        ) {

            $error =
                'New passwords do not match.';

        } else {


            /*
            |--------------------------------------------------------------------------
            | UNIQUE USERNAME
            |--------------------------------------------------------------------------
            */

            $usernameCheck =
                $pdo->prepare("
                    SELECT id
                    FROM users
                    WHERE username = ?
                      AND id != ?
                    LIMIT 1
                ");


            $usernameCheck->execute([
                $username,
                $accountId
            ]);


            if (
                $usernameCheck->fetch()
            ) {

                $error =
                    'That username is already being used.';

            } else {


                try {


                    $pdo->beginTransaction();


                    /*
                    |--------------------------------------------------------------------------
                    | UPDATE USER
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $newPassword !== ''
                    ) {

                        $hashedPassword =
                            password_hash(
                                $newPassword,
                                PASSWORD_DEFAULT
                            );


                        $update =
                            $pdo->prepare("
                                UPDATE users
                                SET
                                    username = ?,
                                    password = ?,
                                    first_name = ?,
                                    middle_name = ?,
                                    last_name = ?,
                                    suffix = ?,
                                    role = ?,
                                    status = ?
                                WHERE id = ?
                            ");


                        $update->execute([

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

                            $role,

                            $status,

                            $accountId

                        ]);


                    } else {


                        $update =
                            $pdo->prepare("
                                UPDATE users
                                SET
                                    username = ?,
                                    first_name = ?,
                                    middle_name = ?,
                                    last_name = ?,
                                    suffix = ?,
                                    role = ?,
                                    status = ?
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

                            $role,

                            $status,

                            $accountId

                        ]);

                    }


                    /*
                    |--------------------------------------------------------------------------
                    | SYSTEM LOG
                    |--------------------------------------------------------------------------
                    */

                    $details =
                        'Updated '
                        . $role
                        . ' account: '
                        . $username
                        . ' ('
                        . $status
                        . ')';


                    if (
                        $newPassword !== ''
                    ) {

                        $details .=
                            '; password changed';
                    }


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

                        'UPDATE_ACCOUNT',

                        'Accounts',

                        'User',

                        $accountId,

                        $details

                    ]);


                    $pdo->commit();


                    /*
                    |--------------------------------------------------------------------------
                    | UPDATE CURRENT SESSION NAME
                    |--------------------------------------------------------------------------
                    */

                    if ($isCurrentAccount) {

                        $_SESSION['full_name'] =
                            buildAccountDisplayName(
                                $firstName,
                                $middleName,
                                $lastName,
                                $suffix
                            );
                    }


                    $_SESSION[
                        'success_message'
                    ] =
                        'Account updated successfully.';


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
                        'Unable to update account: '
                        . $errorObject->getMessage();

                }

            }

        }


        /*
        |--------------------------------------------------------------------------
        | KEEP ENTERED VALUES AFTER VALIDATION ERROR
        |--------------------------------------------------------------------------
        */

        $account['first_name'] =
            $firstName;

        $account['middle_name'] =
            $middleName;

        $account['last_name'] =
            $lastName;

        $account['suffix'] =
            $suffix;

        $account['username'] =
            $username;

        $account['role'] =
            $role;

        $account['status'] =
            $status;

    }

}


/*
|--------------------------------------------------------------------------
| DISPLAY NAME
|--------------------------------------------------------------------------
*/

$displayName =
    buildAccountDisplayName(
        (string) $account['first_name'],
        (string) ($account['middle_name'] ?? ''),
        (string) $account['last_name'],
        (string) ($account['suffix'] ?? '')
    );


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
    href="/assets/css/accounts.css?v=20260914-edit"
>

<?php

require_once __DIR__
    . '/../../app/views/partials/sidebar.php';

?>


<div class="create-account-wrapper">


    <!-- =====================================================
         HEADER
    ====================================================== -->

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
                Edit Account
            </h2>


            <p>
                Update account information, role, status,
                or login credentials for UA POS.
            </p>

        </div>


        <div class="account-form-header-icon">

            <span class="material-symbols-rounded">
                manage_accounts
            </span>

        </div>


    </div>



    <!-- =====================================================
         ERROR
    ====================================================== -->

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



    <!-- =====================================================
         CURRENT ACCOUNT NOTICE
    ====================================================== -->

    <?php if ($isCurrentAccount): ?>

        <div class="account-safety-note">

            <span class="material-symbols-rounded">
                shield_person
            </span>

            <div>

                <strong>
                    You are editing your current account
                </strong>

                <p>
                    To prevent accidental lockout, your own
                    Admin role and Active status cannot be changed.
                </p>

            </div>

        </div>

    <?php endif; ?>



    <!-- =====================================================
         ACCOUNT SUMMARY
    ====================================================== -->

    <div class="account-edit-summary">


        <div class="account-edit-avatar">

            <?= htmlspecialchars(
                strtoupper(
                    substr(
                        (string) $account['first_name'],
                        0,
                        1
                    )
                    .
                    substr(
                        (string) $account['last_name'],
                        0,
                        1
                    )
                )
            ) ?>

        </div>


        <div class="account-edit-summary-copy">

            <div class="account-edit-name-line">

                <strong>
                    <?= htmlspecialchars(
                        $displayName
                    ) ?>
                </strong>


                <?php if ($isCurrentAccount): ?>

                    <span class="current-user-badge">
                        You
                    </span>

                <?php endif; ?>

            </div>


            <span>
                @<?= htmlspecialchars(
                    (string) $account['username']
                ) ?>
                ·
                <?= htmlspecialchars(
                    (string) $account['role']
                ) ?>
                ·
                <?= htmlspecialchars(
                    (string) $account['status']
                ) ?>
            </span>

        </div>


    </div>



    <!-- =====================================================
         FORM
    ====================================================== -->

    <div class="create-account-card">


        <form
            action="/accounts/edit.php?id=<?= $accountId ?>"
            method="POST"
            id="accountEditForm"
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
                name="account_id"
                value="<?= $accountId ?>"
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
                            Update the account holder's name.
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
                            value="<?= htmlspecialchars(
                                (string) $account['first_name']
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
                            value="<?= htmlspecialchars(
                                (string) (
                                    $account['middle_name']
                                    ?? ''
                                )
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
                            value="<?= htmlspecialchars(
                                (string) $account['last_name']
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
                            value="<?= htmlspecialchars(
                                (string) (
                                    $account['suffix']
                                    ?? ''
                                )
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
                        badge
                    </span>


                    <div>

                        <h3>
                            Account Information
                        </h3>

                        <p>
                            Update username, role, and account status.
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
                            value="<?= htmlspecialchars(
                                (string) $account['username']
                            ) ?>"
                            autocomplete="off"
                            required
                        >

                    </div>



                    <div class="form-group">

                        <label for="role">
                            Account Type
                        </label>


                        <?php if ($isCurrentAccount): ?>

                            <select
                                id="role"
                                class="form-control"
                                disabled
                            >

                                <option selected>
                                    Admin
                                </option>

                            </select>


                            <input
                                type="hidden"
                                name="role"
                                value="Admin"
                            >


                            <small class="form-help">
                                Your current Admin role is protected.
                            </small>


                        <?php else: ?>


                            <select
                                id="role"
                                name="role"
                                class="form-control"
                                required
                            >

                                <?php foreach (
                                    [
                                        'Admin',
                                        'Manager',
                                        'Cashier'
                                    ]
                                    as $roleOption
                                ): ?>

                                    <option
                                        value="<?= htmlspecialchars(
                                            $roleOption
                                        ) ?>"
                                        <?= $account['role'] === $roleOption
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >

                                        <?= htmlspecialchars(
                                            $roleOption
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>


                        <?php endif; ?>

                    </div>



                    <div class="form-group">

                        <label for="status">
                            Account Status
                        </label>


                        <?php if ($isCurrentAccount): ?>

                            <select
                                id="status"
                                class="form-control"
                                disabled
                            >

                                <option selected>
                                    Active
                                </option>

                            </select>


                            <input
                                type="hidden"
                                name="status"
                                value="Active"
                            >


                            <small class="form-help">
                                Your current account cannot be deactivated.
                            </small>


                        <?php else: ?>


                            <select
                                id="status"
                                name="status"
                                class="form-control"
                                required
                            >

                                <option
                                    value="Active"
                                    <?= $account['status'] === 'Active'
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    Active
                                </option>


                                <option
                                    value="Inactive"
                                    <?= $account['status'] === 'Inactive'
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    Inactive
                                </option>

                            </select>


                        <?php endif; ?>

                    </div>


                    <div class="form-group account-status-explainer">

                        <label>
                            Access Effect
                        </label>


                        <div
                            class="account-access-preview"
                            id="accountAccessPreview"
                        >

                            <span class="material-symbols-rounded">
                                login
                            </span>


                            <div>

                                <strong id="accountAccessTitle">
                                    Sign-in allowed
                                </strong>

                                <small id="accountAccessText">
                                    This user can sign in to UA POS.
                                </small>

                            </div>

                        </div>

                    </div>


                </div>


            </div>



            <div class="form-divider"></div>



            <!-- =================================================
                 PASSWORD
            ================================================== -->

            <div class="form-section">


                <div class="form-section-title">


                    <span class="material-symbols-rounded">
                        password
                    </span>


                    <div>

                        <h3>
                            Change Password
                        </h3>

                        <p>
                            Optional. Leave both fields blank to keep
                            the current password.
                        </p>

                    </div>


                </div>



                <div class="form-grid">


                    <div class="form-group">

                        <label for="new_password">
                            New Password
                        </label>


                        <div class="password-field">

                            <input
                                type="password"
                                id="new_password"
                                name="new_password"
                                class="form-control"
                                placeholder="Leave blank to keep current password"
                                minlength="6"
                                autocomplete="new-password"
                            >


                            <button
                                type="button"
                                class="password-eye"
                                data-target="new_password"
                                aria-label="Show password"
                            >

                                <span class="material-symbols-rounded">
                                    visibility
                                </span>

                            </button>

                        </div>


                        <small class="form-help">
                            Minimum 6 characters when changing the password.
                        </small>

                    </div>



                    <div class="form-group">

                        <label for="confirm_new_password">
                            Confirm New Password
                        </label>


                        <div class="password-field">

                            <input
                                type="password"
                                id="confirm_new_password"
                                name="confirm_new_password"
                                class="form-control"
                                placeholder="Repeat new password"
                                minlength="6"
                                autocomplete="new-password"
                            >


                            <button
                                type="button"
                                class="password-eye"
                                data-target="confirm_new_password"
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
                 ACTIONS
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
                    id="saveAccountButton"
                >

                    <span class="material-symbols-rounded">
                        save
                    </span>

                    Save Changes

                </button>


            </div>


        </form>


    </div>


</div>



<!--
    Hidden trigger used only when the account status changes.
    The global confirmation modal in footer.php reads these attributes.
-->

<button
    type="button"
    id="accountStatusConfirmTrigger"
    data-confirm
    hidden
></button>



<script>

const accountEditForm =
    document.getElementById(
        'accountEditForm'
    );


const statusField =
    document.getElementById(
        'status'
    );


const accountStatusConfirmTrigger =
    document.getElementById(
        'accountStatusConfirmTrigger'
    );


const originalAccountStatus =
    <?= json_encode(
        $originalStatus
    ) ?>;


const editedAccountName =
    <?= json_encode(
        $displayName
    ) ?>;


const editingCurrentAccount =
    <?= $isCurrentAccount
        ? 'true'
        : 'false'
    ?>;


let statusConfirmationPending =
    false;


/* =========================================================
   PASSWORD VISIBILITY
========================================================= */

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


/* =========================================================
   STATUS PREVIEW
========================================================= */

function updateAccountAccessPreview() {

    const preview =
        document.getElementById(
            'accountAccessPreview'
        );


    const title =
        document.getElementById(
            'accountAccessTitle'
        );


    const text =
        document.getElementById(
            'accountAccessText'
        );


    if (
        !preview
        ||
        !title
        ||
        !text
        ||
        !statusField
    ) {
        return;
    }


    const status =
        editingCurrentAccount
            ? 'Active'
            : statusField.value;


    preview.classList.toggle(
        'inactive',
        status === 'Inactive'
    );


    if (
        status === 'Inactive'
    ) {

        title.textContent =
            'Sign-in blocked';


        text.textContent =
            'This user will not be able to sign in to UA POS.';

    } else {

        title.textContent =
            'Sign-in allowed';


        text.textContent =
            'This user can sign in to UA POS.';

    }

}


if (statusField) {

    statusField.addEventListener(
        'change',
        updateAccountAccessPreview
    );
}


updateAccountAccessPreview();


/* =========================================================
   STATUS CHANGE CONFIRMATION
========================================================= */

accountEditForm.addEventListener(
    'submit',
    event => {


        if (
            editingCurrentAccount
            ||
            statusConfirmationPending
            ||
            !statusField
        ) {

            return;
        }


        const selectedStatus =
            statusField.value;


        if (
            selectedStatus ===
            originalAccountStatus
        ) {

            return;
        }


        event.preventDefault();


        statusConfirmationPending =
            true;


        if (
            selectedStatus ===
            'Inactive'
        ) {

            accountStatusConfirmTrigger.dataset.confirmTitle =
                'Deactivate account?';


            accountStatusConfirmTrigger.dataset.confirmMessage =
                editedAccountName
                + ' will no longer be able to sign in to UA POS until this account is reactivated.';


            accountStatusConfirmTrigger.dataset.confirmLabel =
                'Deactivate';


            accountStatusConfirmTrigger.dataset.confirmIcon =
                'person_off';


        } else {


            accountStatusConfirmTrigger.dataset.confirmTitle =
                'Activate account?';


            accountStatusConfirmTrigger.dataset.confirmMessage =
                editedAccountName
                + ' will be able to sign in to UA POS again.';


            accountStatusConfirmTrigger.dataset.confirmLabel =
                'Activate';


            accountStatusConfirmTrigger.dataset.confirmIcon =
                'person_check';

        }


        accountStatusConfirmTrigger.click();

    }
);


/* =========================================================
   CONNECT TO GLOBAL CONFIRMATION MODAL
========================================================= */

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


        function resetStatusConfirmation() {

            statusConfirmationPending =
                false;

        }


        systemConfirmSubmit.addEventListener(
            'click',
            () => {


                if (
                    !statusConfirmationPending
                ) {
                    return;
                }


                /*
                 * Native form validation has already passed
                 * before the submit event opened this modal.
                 */

                accountEditForm.submit();

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
                        resetStatusConfirmation
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

                    resetStatusConfirmation();

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
