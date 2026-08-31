<?php

require_once __DIR__
    . '/../../app/middleware/role.php';

requireRole(['Admin']);

require_once __DIR__
    . '/../../app/config/database.php';


$pageTitle = 'Accounts';
$currentPage = 'accounts';


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
    ORDER BY last_name ASC, first_name ASC
");

$users = $stmt->fetchAll();


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
    href="/assets/css/accounts.css"
>

<?php

require_once __DIR__
    . '/../../app/views/partials/sidebar.php';

?>


<div class="accounts-header">

    <div>

        <h2>
            Account Management
        </h2>

        <p>
            Manage Admin, Manager, and Cashier accounts.
        </p>

    </div>


    <a
        href="/accounts/create.php"
        class="btn btn-primary"
    >

        <span class="material-symbols-rounded">
            person_add
        </span>

        Add Account

    </a>

</div>


<div class="card accounts-card">

    <div class="table-wrapper">

        <table class="accounts-table">

            <thead>

                <tr>

                    <th>Name</th>

                    <th>Username</th>

                    <th>Role</th>

                    <th>Status</th>

                    <th>Created</th>

                    <th>Actions</th>

                </tr>

            </thead>


            <tbody>

            <?php if (count($users) > 0): ?>


                <?php foreach ($users as $user): ?>


                    <?php

                    /*
                    |--------------------------------------------------------------------------
                    | BUILD FULL DISPLAY NAME
                    |--------------------------------------------------------------------------
                    */

                    $nameParts = [];

                    $nameParts[] =
                        $user['first_name'];

                    if (!empty(
                        $user['middle_name']
                    )) {

                        $nameParts[] =
                            $user['middle_name'];
                    }

                    $nameParts[] =
                        $user['last_name'];

                    if (!empty(
                        $user['suffix']
                    )) {

                        $nameParts[] =
                            $user['suffix'];
                    }

                    $displayName =
                        implode(
                            ' ',
                            $nameParts
                        );


                    /*
                    |--------------------------------------------------------------------------
                    | ROLE BADGE
                    |--------------------------------------------------------------------------
                    */

                    $roleClass =
                        'badge-cashier';

                    if (
                        $user['role']
                        === 'Admin'
                    ) {

                        $roleClass =
                            'badge-admin';
                    }

                    if (
                        $user['role']
                        === 'Manager'
                    ) {

                        $roleClass =
                            'badge-manager';
                    }

                    ?>


                    <tr>


                        <!-- NAME -->

                        <td>

                            <div class="account-name">

                                <div class="account-avatar">

                                    <?= strtoupper(
    substr($user['first_name'], 0, 1)
    .
    substr($user['last_name'], 0, 1)
) ?>

                                </div>


                                <div>

                                    <strong>

                                        <?= htmlspecialchars(
                                            $displayName
                                        ) ?>

                                    </strong>

                                </div>

                            </div>

                        </td>


                        <!-- USERNAME -->

                        <td>

                            <?= htmlspecialchars(
                                $user['username']
                            ) ?>

                        </td>


                        <!-- ROLE -->

                        <td>

                            <span
                                class="badge <?= $roleClass ?>"
                            >

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

                                <span
                                    class="status active"
                                >

                                    Active

                                </span>

                            <?php else: ?>

                                <span
                                    class="status inactive"
                                >

                                    Inactive

                                </span>

                            <?php endif; ?>

                        </td>


                        <!-- CREATED -->

                        <td>

                            <?= date(
                                'M d, Y',
                                strtotime(
                                    $user['created_at']
                                )
                            ) ?>

                        </td>


                        <!-- ACTIONS -->

                        <td>

                            <a
                                href="/accounts/edit.php?id=<?= (int) $user['id'] ?>"
                                class="icon-button"
                                title="Edit Account"
                            >

                                <span class="material-symbols-rounded">
                                    edit
                                </span>

                            </a>

                        </td>


                    </tr>


                <?php endforeach; ?>


            <?php else: ?>


                <tr>

                    <td colspan="6">

                        No accounts found.

                    </td>

                </tr>


            <?php endif; ?>


            </tbody>

        </table>

    </div>

</div>


<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';

?>