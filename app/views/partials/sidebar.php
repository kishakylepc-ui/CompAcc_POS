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

        <!-- Shrinks the sidebar to icons only (handled by /assets/js/ui.js). -->
        <button
            type="button"
            class="sidebar-toggle"
            id="sidebarToggle"
            aria-controls="sidebarNav"
            aria-expanded="true"
            aria-label="Collapse sidebar"
        >

            <span class="material-symbols-rounded" aria-hidden="true">
                keyboard_double_arrow_left
            </span>

        </button>

    </div>



    <!-- NAVIGATION -->

    <nav class="sidebar-nav" id="sidebarNav">


        <!-- DASHBOARD -->

        <a
            href="/dashboard/"
            class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>"
        >

            <span class="material-symbols-rounded nav-icon">
                dashboard
            </span>

            <span class="nav-label">
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

            <span class="nav-label">
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

                <span class="nav-label">
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

                <span class="nav-label">
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

                <span class="nav-label">
                    Payroll
                </span>

            </a>



            <a
                href="/expenses/"
                class="nav-link <?= $currentPage === 'expenses' ? 'active' : '' ?>"
            >

                <span class="material-symbols-rounded nav-icon">
                    receipt_long
                </span>

                <span class="nav-label">
                    Expenses
                </span>

            </a>



            <a
                href="/reports/"
                class="nav-link <?= $currentPage === 'reports' ? 'active' : '' ?>"
            >

                <span class="material-symbols-rounded nav-icon">
                    bar_chart
                </span>

                <span class="nav-label">
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

                <span class="nav-label">
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

                <span class="nav-label">
                    System Logs
                </span>

            </a>


        <?php endif; ?>



        <!-- EVERYONE: My Account lives in Settings; system tabs are Admin-only -->

        <a
            href="/settings/"
            class="nav-link <?= $currentPage === 'settings' ? 'active' : '' ?>"
        >

            <span class="material-symbols-rounded nav-icon">
                settings
            </span>

            <span class="nav-label">
                Settings
            </span>

        </a>


    </nav>



    <!-- SIDEBAR LOGOUT -->

    <div class="sidebar-bottom">

        <a
    href="/logout.php"
    class="sidebar-logout"

    data-confirm
    data-confirm-title="Sign out of UA POS?"
    data-confirm-message="You'll need to sign in again to continue."
    data-confirm-label="Sign Out"
    data-confirm-icon="logout"
>

    <span class="material-symbols-rounded">
        logout
    </span>

    <span class="nav-label">
        Sign Out
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
                <?= htmlspecialchars($pageTitle ?? '') ?>
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