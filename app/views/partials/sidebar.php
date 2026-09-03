<?php

$currentPage = $currentPage ?? '';

$role = $_SESSION['role'] ?? '';

?>

<aside class="sidebar">


    <!-- BRAND -->

    <div class="sidebar-brand">

        <img
            src="/assets/images/UA_logo.jpg"
            alt="UA"
            class="sidebar-logo-image"
        >

        <span class="sidebar-brand-name">
            UNDERGROUND APPAREL
        </span>

    </div>



    <!-- NAVIGATION -->

    <nav class="sidebar-nav">


        <!-- DASHBOARD -->

        <a
            href="/dashboard/"
            class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>"
        >

            <span class="material-symbols-rounded nav-icon">
                dashboard
            </span>

            <span>
                Dashboard
            </span>

        </a>



        <!-- POS -->

        <a
            href="/pos/"
            class="nav-link <?= $currentPage === 'pos' ? 'active' : '' ?>"
        >

            <span class="material-symbols-rounded nav-icon">
                point_of_sale
            </span>

            <span>
                POS
            </span>

        </a>



        <!-- ADMIN + MANAGER -->

        <?php if (
            $role === 'Admin' ||
            $role === 'Manager'
        ): ?>


            <a
                href="/inventory/"
                class="nav-link <?= $currentPage === 'inventory' ? 'active' : '' ?>"
            >

                <span class="material-symbols-rounded nav-icon">
                    inventory_2
                </span>

                <span>
                    Inventory
                </span>

            </a>



            <a
                href="/suppliers/"
                class="nav-link <?= $currentPage === 'suppliers' ? 'active' : '' ?>"
            >

                <span class="material-symbols-rounded nav-icon">
                    local_shipping
                </span>

                <span>
                    Suppliers
                </span>

            </a>



            <a
                href="/payroll/"
                class="nav-link <?= $currentPage === 'payroll' ? 'active' : '' ?>"
            >

                <span class="material-symbols-rounded nav-icon">
                    payments
                </span>

                <span>
                    Payroll
                </span>

            </a>



            <a
                href="/reports/"
                class="nav-link <?= $currentPage === 'reports' ? 'active' : '' ?>"
            >

                <span class="material-symbols-rounded nav-icon">
                    bar_chart
                </span>

                <span>
                    Reports
                </span>

            </a>


        <?php endif; ?>



        <!-- ADMIN ONLY -->

        <?php if ($role === 'Admin'): ?>


            <div class="nav-section">
                Administration
            </div>


            <a
                href="/accounts/"
                class="nav-link <?= $currentPage === 'accounts' ? 'active' : '' ?>"
            >

                <span class="material-symbols-rounded nav-icon">
                    manage_accounts
                </span>

                <span>
                    Accounts
                </span>

            </a>



            <a
                href="/logs/"
                class="nav-link <?= $currentPage === 'logs' ? 'active' : '' ?>"
            >

                <span class="material-symbols-rounded nav-icon">
                    history
                </span>

                <span>
                    System Logs
                </span>

            </a>



            <a
                href="/settings/"
                class="nav-link <?= $currentPage === 'settings' ? 'active' : '' ?>"
            >

                <span class="material-symbols-rounded nav-icon">
                    settings
                </span>

                <span>
                    Settings
                </span>

            </a>


        <?php endif; ?>


    </nav>



    <!-- SIDEBAR LOGOUT -->

    <div class="sidebar-bottom">

        <a
            href="/logout.php"
            class="sidebar-logout"
        >

            <span class="material-symbols-rounded">
                logout
            </span>

            <span>
                Logout
            </span>

        </a>

    </div>


</aside>



<!-- MAIN -->

<div class="main-area">


    <!-- TOP HEADER -->

    <header class="top-header">


        <div class="header-title">

            <h1>
                <?= htmlspecialchars($pageTitle) ?>
            </h1>

        </div>



        <div class="header-actions">


            <div class="header-user">

                <strong>
                    <?= htmlspecialchars(
                        $_SESSION['full_name'] ?? ''
                    ) ?>
                </strong>

                <span>
                    <?= htmlspecialchars(
                        $_SESSION['role'] ?? ''
                    ) ?>
                </span>

            </div>





        </div>


    </header>


    <main class="page-content">