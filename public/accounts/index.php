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
| PAGE LAYOUT
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
