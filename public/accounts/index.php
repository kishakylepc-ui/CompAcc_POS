<?php

require_once __DIR__
    . '/../../app/middleware/role.php';

requireRole([
    'Admin'
]);

require_once __DIR__
    . '/../../app/config/database.php';


$pageTitle = 'Accounts';
$currentPage = 'accounts';


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
| DISMISS A PASSWORD REQUEST
|--------------------------------------------------------------------------
|
| Requests come from "Forgot password?" on the sign-in page
| (forgot_password.php). A request is done once the password is changed;
| Dismiss closes one that is not needed (for example sent by mistake).
|
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

        $_SESSION['error_message'] =
            'Invalid request. Please refresh the page and try again.';

        header('Location: /accounts/');
        exit;
    }


    $action =
        (string) (
            $_POST['action']
            ?? ''
        );


    if ($action === 'dismiss_reset_request') {

        $resetUserId =
            (int) (
                $_POST['user_id']
                ?? 0
            );


        try {

            $stmt = $pdo->prepare("
                SELECT
                    username
                FROM users
                WHERE id = ?
            ");

            $stmt->execute([
                $resetUserId
            ]);

            $resetUsername =
                $stmt->fetchColumn();


            if ($resetUsername === false) {

                $_SESSION['error_message'] =
                    'That account no longer exists.';

            } else {

                $pdo->prepare("
                    INSERT INTO system_logs (
                        user_id,
                        action,
                        module,
                        record_type,
                        record_id,
                        details
                    )
                    VALUES (?, 'PASSWORD_RESET_DISMISSED', 'Accounts', 'User', ?, ?)
                ")->execute([
                    $_SESSION['user_id'],
                    $resetUserId,
                    'Dismissed the password request of username: '
                    . $resetUsername
                ]);


                $_SESSION['success_message'] =
                    'The password request of '
                    . $resetUsername
                    . ' was dismissed. Their password did not change.';
            }

        } catch (PDOException $e) {

            $_SESSION['error_message'] =
                'The request could not be dismissed. Please try again.';
        }
    }


    header('Location: /accounts/');
    exit;
}


/*
|--------------------------------------------------------------------------
| FLASH
|--------------------------------------------------------------------------
*/

$successMessage =
    $_SESSION['success_message']
    ?? '';

$errorMessage =
    $_SESSION['error_message']
    ?? '';

unset(
    $_SESSION['success_message'],
    $_SESSION['error_message']
);


/*
|--------------------------------------------------------------------------
| GET USERS FROM SQLITE
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
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
    ORDER BY
        CASE role
            WHEN 'Admin' THEN 1
            WHEN 'Manager' THEN 2
            WHEN 'Cashier' THEN 3
            ELSE 4
        END,
        last_name ASC,
        first_name ASC
");

$users =
    $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| ACCOUNT COUNTS
|--------------------------------------------------------------------------
*/

$totalAccounts =
    count($users);

$activeAccounts =
    0;

$adminAccounts =
    0;

$staffAccounts =
    0;


foreach ($users as $account) {

    if (
        ($account['status'] ?? '')
        === 'Active'
    ) {

        $activeAccounts++;
    }


    if (
        ($account['role'] ?? '')
        === 'Admin'
    ) {

        $adminAccounts++;

    } else {

        $staffAccounts++;
    }
}


$currentUserId =
    (int) (
        $_SESSION['user_id']
        ?? 0
    );


/*
|--------------------------------------------------------------------------
| OPEN PASSWORD REQUESTS
|--------------------------------------------------------------------------
|
| From "Forgot password?" on the sign-in page. Open until the password is
| changed (My Account, Accounts -> Edit or tools/reset_password.php) or an
| Admin dismisses it. Dates in system_logs are UTC.
|
*/

$resetRequests = $pdo->query("
    SELECT
        u.id,
        u.username,
        u.first_name,
        u.last_name,
        u.role,
        u.status,
        COUNT(r.id) AS request_count,
        MAX(r.created_at) AS last_request
    FROM system_logs r
    JOIN users u
        ON u.id = r.record_id
    WHERE r.action = 'PASSWORD_RESET_REQUEST'
      AND r.record_type = 'User'
      AND r.created_at > COALESCE((
            SELECT MAX(c.created_at)
            FROM system_logs c
            WHERE (c.action = 'CHANGE_OWN_PASSWORD' AND c.user_id = u.id)
               OR (
                    c.action = 'UPDATE_ACCOUNT'
                    AND c.record_id = u.id
                    AND c.details LIKE '%password changed%'
               )
               OR (c.action = 'PASSWORD_RESET_DISMISSED' AND c.record_id = u.id)
          ), '')
    GROUP BY u.id
    ORDER BY last_request DESC
")->fetchAll();


/* UTC from the database -> Asia/Manila for display. */
function accountsManilaTime(string $utcDate): string
{
    $date = new DateTime(
        $utcDate,
        new DateTimeZone('UTC')
    );

    $date->setTimezone(
        new DateTimeZone('Asia/Manila')
    );

    return $date->format('M d, Y · g:i A');
}


/*
|--------------------------------------------------------------------------
| PAGE LAYOUT
|--------------------------------------------------------------------------
*/

require_once __DIR__
    . '/../../app/views/partials/header.php';

?>

<link
    rel="stylesheet"
    href="/assets/css/accounts.css?v=20261012-2"
>

<?php

require_once __DIR__
    . '/../../app/views/partials/sidebar.php';

?>


<div class="accounts-page">


    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <div class="accounts-header">

        <div>

            <div class="accounts-eyebrow">
                USER ACCESS
            </div>

            <h2>
                Account Management
            </h2>

            <p>
                Manage Admin, Manager, and Cashier accounts
                that can access UA POS.
            </p>

        </div>


        <a
            href="/accounts/create.php"
            class="accounts-primary-button"
        >

            <span class="material-symbols-rounded">
                person_add
            </span>

            Add Account

        </a>

    </div>



    <!-- =====================================================
         MESSAGES
    ====================================================== -->

    <?php if ($successMessage !== ''): ?>

        <div class="accounts-alert success">

            <span class="material-symbols-rounded">
                check_circle
            </span>

            <span>
                <?= htmlspecialchars(
                    $successMessage
                ) ?>
            </span>

        </div>

    <?php endif; ?>


    <?php if ($errorMessage !== ''): ?>

        <div class="accounts-alert error">

            <span class="material-symbols-rounded">
                error
            </span>

            <span>
                <?= htmlspecialchars(
                    $errorMessage
                ) ?>
            </span>

        </div>

    <?php endif; ?>



    <!-- =====================================================
         PASSWORD REQUESTS (Forgot password? on the sign-in page)
    ====================================================== -->

    <?php if ($resetRequests !== []): ?>

        <section
            class="accounts-reset-card"
            aria-labelledby="accountsResetTitle"
        >

            <div class="accounts-reset-header">

                <div class="accounts-reset-icon">

                    <span class="material-symbols-rounded">
                        lock_reset
                    </span>

                </div>


                <div>

                    <h3 id="accountsResetTitle">
                        Password Requests

                        <span class="accounts-reset-count">
                            <?= count($resetRequests) ?>
                        </span>
                    </h3>

                    <p>
                        These people used "Forgot password?" on the sign-in
                        page. Set a new password in Edit, then tell them the
                        new password in person.
                    </p>

                </div>

            </div>


            <ul class="accounts-reset-list">

                <?php foreach ($resetRequests as $request): ?>

                    <?php

                    $requestUserId =
                        (int) $request['id'];

                    $requestName =
                        trim(
                            $request['first_name']
                            . ' '
                            . $request['last_name']
                        );

                    $requestInitials =
                        strtoupper(
                            substr((string) $request['first_name'], 0, 1)
                            . substr((string) $request['last_name'], 0, 1)
                        );

                    $requestCount =
                        (int) $request['request_count'];

                    ?>

                    <li class="accounts-reset-item">

                        <div class="account-name">

                            <div class="account-avatar">
                                <?= htmlspecialchars($requestInitials) ?>
                            </div>

                            <div class="account-name-copy">

                                <div class="account-name-line">

                                    <strong>
                                        <?= htmlspecialchars($requestName) ?>
                                    </strong>

                                </div>

                                <small>
                                    <?= htmlspecialchars($request['username']) ?>
                                    · <?= htmlspecialchars($request['role']) ?>
                                    <?= $request['status'] !== 'Active'
                                        ? ' · Inactive'
                                        : ''
                                    ?>
                                </small>

                            </div>

                        </div>


                        <div class="accounts-reset-time">

                            <span class="material-symbols-rounded">
                                schedule
                            </span>

                            <span>
                                <?= htmlspecialchars(
                                    accountsManilaTime($request['last_request'])
                                ) ?>
                                <?= $requestCount > 1
                                    ? '(' . $requestCount . ' requests)'
                                    : ''
                                ?>
                            </span>

                        </div>


                        <div class="accounts-reset-actions">

                            <a
                                href="/accounts/edit.php?id=<?= $requestUserId ?>#new_password"
                                class="accounts-primary-button"
                            >

                                <span class="material-symbols-rounded">
                                    password
                                </span>

                                Set New Password

                            </a>


                            <form
                                method="post"
                                action="/accounts/"
                                id="accountsDismissReset-<?= $requestUserId ?>"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="dismiss_reset_request"
                                >

                                <input
                                    type="hidden"
                                    name="user_id"
                                    value="<?= $requestUserId ?>"
                                >

                                <button
                                    type="button"
                                    class="accounts-secondary-button"
                                    data-confirm
                                    data-confirm-title="Dismiss this request?"
                                    data-confirm-message="<?= htmlspecialchars(
                                        'The password of ' . $requestName
                                        . ' stays the same. Dismiss only a request that is not needed.'
                                    ) ?>"
                                    data-confirm-label="Dismiss"
                                    data-confirm-icon="close"
                                    data-confirm-form="accountsDismissReset-<?= $requestUserId ?>"
                                >
                                    Dismiss
                                </button>

                            </form>

                        </div>

                    </li>

                <?php endforeach; ?>

            </ul>

        </section>

    <?php endif; ?>



    <!-- =====================================================
         STATS
    ====================================================== -->

    <div class="accounts-stats">


        <div class="accounts-stat-card">

            <div class="accounts-stat-icon">

                <span class="material-symbols-rounded">
                    group
                </span>

            </div>

            <div>

                <span>
                    Total Accounts
                </span>

                <strong>
                    <?= $totalAccounts ?>
                </strong>

            </div>

        </div>


        <div class="accounts-stat-card">

            <div class="accounts-stat-icon">

                <span class="material-symbols-rounded">
                    verified_user
                </span>

            </div>

            <div>

                <span>
                    Active Accounts
                </span>

                <strong>
                    <?= $activeAccounts ?>
                </strong>

            </div>

        </div>


        <div class="accounts-stat-card">

            <div class="accounts-stat-icon">

                <span class="material-symbols-rounded">
                    admin_panel_settings
                </span>

            </div>

            <div>

                <span>
                    Administrators
                </span>

                <strong>
                    <?= $adminAccounts ?>
                </strong>

            </div>

        </div>


        <div class="accounts-stat-card">

            <div class="accounts-stat-icon">

                <span class="material-symbols-rounded">
                    badge
                </span>

            </div>

            <div>

                <span>
                    Manager / Cashier
                </span>

                <strong>
                    <?= $staffAccounts ?>
                </strong>

            </div>

        </div>


    </div>



    <!-- =====================================================
         ACCOUNTS TABLE
    ====================================================== -->

    <div class="accounts-card">


        <div class="accounts-card-header">

            <div>

                <h3>
                    User Accounts
                </h3>

                <p>
                    <?= $totalAccounts ?>
                    <?= $totalAccounts === 1
                        ? 'account'
                        : 'accounts'
                    ?>
                    registered
                </p>

            </div>


            <div class="accounts-card-note">

                <span class="material-symbols-rounded">
                    shield_person
                </span>

                Admin access only

            </div>

        </div>


        <div class="table-wrapper">

            <table class="accounts-table">


                <thead>

                    <tr>

                        <th>
                            Account
                        </th>

                        <th>
                            Username
                        </th>

                        <th>
                            Role
                        </th>

                        <th>
                            Status
                        </th>

                        <th>
                            Created
                        </th>

                        <th>
                            Actions
                        </th>

                    </tr>

                </thead>


                <tbody>


                <?php if (
                    count($users) > 0
                ): ?>


                    <?php foreach (
                        $users
                        as $user
                    ): ?>


                        <?php

                        $nameParts = [
                            $user['first_name']
                        ];


                        if (
                            !empty(
                                $user['middle_name']
                            )
                        ) {

                            $nameParts[] =
                                $user['middle_name'];
                        }


                        $nameParts[] =
                            $user['last_name'];


                        if (
                            !empty(
                                $user['suffix']
                            )
                        ) {

                            $nameParts[] =
                                $user['suffix'];
                        }


                        $displayName =
                            implode(
                                ' ',
                                $nameParts
                            );


                        $roleClass =
                            'badge-cashier';


                        if (
                            $user['role']
                            === 'Admin'
                        ) {

                            $roleClass =
                                'badge-admin';

                        } elseif (
                            $user['role']
                            === 'Manager'
                        ) {

                            $roleClass =
                                'badge-manager';
                        }


                        $isCurrentUser =
                            (int) $user['id']
                            === $currentUserId;


                        $initials =
                            strtoupper(
                                substr(
                                    (string) $user['first_name'],
                                    0,
                                    1
                                )
                                .
                                substr(
                                    (string) $user['last_name'],
                                    0,
                                    1
                                )
                            );

                        ?>


                        <tr
                            class="<?= $isCurrentUser
                                ? 'current-account-row'
                                : ''
                            ?>"
                        >


                            <!-- ACCOUNT -->

                            <td>

                                <div class="account-name">

                                    <div class="account-avatar">

                                        <?= htmlspecialchars(
                                            $initials
                                        ) ?>

                                    </div>


                                    <div class="account-name-copy">

                                        <div class="account-name-line">

                                            <strong>

                                                <?= htmlspecialchars(
                                                    $displayName
                                                ) ?>

                                            </strong>


                                            <?php if (
                                                $isCurrentUser
                                            ): ?>

                                                <span class="current-user-badge">
                                                    You
                                                </span>

                                            <?php endif; ?>

                                        </div>


                                        <small>

                                            <?= $isCurrentUser
                                                ? 'Currently signed in'
                                                : 'UA POS user'
                                            ?>

                                        </small>

                                    </div>

                                </div>

                            </td>


                            <!-- USERNAME -->

                            <td>

                                <span class="account-username">

                                    <?= htmlspecialchars(
                                        $user['username']
                                    ) ?>

                                </span>

                            </td>


                            <!-- ROLE -->

                            <td>

                                <span class="badge <?= $roleClass ?>">

                                    <?= htmlspecialchars(
                                        $user['role']
                                    ) ?>

                                </span>

                            </td>


                            <!-- STATUS -->

                            <td>

                                <?php if (
                                    $user['status']
                                    === 'Active'
                                ): ?>

                                    <span class="status active">

                                        <span class="status-dot"></span>

                                        Active

                                    </span>

                                <?php else: ?>

                                    <span class="status inactive">

                                        <span class="status-dot"></span>

                                        Inactive

                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- CREATED -->

                            <td>

                                <span class="account-created-date">

                                    <?= date(
                                        'M d, Y',
                                        strtotime(
                                            $user['created_at']
                                        )
                                    ) ?>

                                </span>

                            </td>


                            <!-- ACTIONS -->

                            <td>

                                <div class="account-actions">

                                    <a
                                        href="/accounts/edit.php?id=<?= (int) $user['id'] ?>"
                                        class="account-icon-button"
                                        title="Edit account"
                                        aria-label="Edit <?= htmlspecialchars(
                                            $displayName
                                        ) ?>"
                                    >

                                        <span class="material-symbols-rounded">
                                            edit
                                        </span>

                                    </a>

                                </div>

                            </td>


                        </tr>


                    <?php endforeach; ?>


                <?php else: ?>


                    <tr>

                        <td
                            colspan="6"
                            class="accounts-empty-cell"
                        >

                            <div class="accounts-empty">

                                <span class="material-symbols-rounded">
                                    manage_accounts
                                </span>

                                <strong>
                                    No accounts found
                                </strong>

                                <p>
                                    Create the first UA POS account to get started.
                                </p>

                            </div>

                        </td>

                    </tr>


                <?php endif; ?>


                </tbody>


            </table>

        </div>

    </div>


</div>


<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';

?>
