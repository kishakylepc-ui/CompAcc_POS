<?php

require_once __DIR__
    . '/../../app/middleware/auth.php';


$pageTitle = 'Dashboard';

$currentPage = 'dashboard';


$accessError =
    $_SESSION['access_error'] ?? null;

unset(
    $_SESSION['access_error']
);


require_once __DIR__
    . '/../../app/views/partials/header.php';

require_once __DIR__
    . '/../../app/views/partials/sidebar.php';

?>


<div class="dashboard-container">


    <?php if ($accessError): ?>

        <div
            class="login-error"
            style="margin-bottom: 20px;"
        >

            <span class="material-symbols-rounded">
                error
            </span>

            <?= htmlspecialchars($accessError) ?>

        </div>

    <?php endif; ?>



    <!-- WELCOME -->

    <section class="dashboard-welcome">

        <h2>

            Welcome back,
            <?= htmlspecialchars(
                $_SESSION['full_name']
            ) ?>

        </h2>


        <p>

            You are logged in as

            <strong>
                <?= htmlspecialchars(
                    $_SESSION['role']
                ) ?>
            </strong>.

        </p>

    </section>



    <!-- STAT CARDS -->

    <section class="dashboard-stats">


        <div class="dashboard-stat">

            <div class="stat-icon">

                <span class="material-symbols-rounded">
                    payments
                </span>

            </div>

            <span class="stat-label">
                Today's Sales
            </span>

            <span class="stat-value">
                ₱0.00
            </span>

        </div>



        <div class="dashboard-stat">

            <div class="stat-icon">

                <span class="material-symbols-rounded">
                    receipt_long
                </span>

            </div>

            <span class="stat-label">
                Transactions
            </span>

            <span class="stat-value">
                0
            </span>

        </div>



        <div class="dashboard-stat">

            <div class="stat-icon">

                <span class="material-symbols-rounded">
                    inventory_2
                </span>

            </div>

            <span class="stat-label">
                Low Stock
            </span>

            <span class="stat-value">
                0
            </span>

        </div>



        <div class="dashboard-stat">

            <div class="stat-icon">

                <span class="material-symbols-rounded">
                    warning
                </span>

            </div>

            <span class="stat-label">
                Expiring Soon
            </span>

            <span class="stat-value">
                0
            </span>

        </div>


    </section>



    <!-- LOWER -->

    <section class="dashboard-lower">


        <div class="dashboard-panel">

            <h3>
                Recent Transactions
            </h3>

            <span class="dashboard-panel-subtitle">
                Latest POS transactions
            </span>


            <div class="dashboard-empty">

                <span class="material-symbols-rounded">
                    receipt_long
                </span>

                <span>
                    No transactions yet
                </span>

            </div>

        </div>



        <div class="dashboard-panel">

            <h3>
                Inventory Alerts
            </h3>

            <span class="dashboard-panel-subtitle">
                Stock and expiration warnings
            </span>


            <div class="dashboard-empty">

                <span class="material-symbols-rounded">
                    inventory
                </span>

                <span>
                    No alerts yet
                </span>

            </div>

        </div>


    </section>


</div>


<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';

?>