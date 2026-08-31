<?php

require_once __DIR__
    . '/../../app/middleware/auth.php';


/*
|--------------------------------------------------------------------------
| PAGE SETTINGS
|--------------------------------------------------------------------------
*/

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';


/*
|--------------------------------------------------------------------------
| ACCESS DENIED MESSAGE
|--------------------------------------------------------------------------
*/

$accessError = $_SESSION['access_error'] ?? null;

unset($_SESSION['access_error']);


/*
|--------------------------------------------------------------------------
| LAYOUT
|--------------------------------------------------------------------------
*/

require_once __DIR__
    . '/../../app/views/partials/header.php';

require_once __DIR__
    . '/../../app/views/partials/sidebar.php';

?>


<!-- ACCESS ERROR -->

<?php if ($accessError): ?>

    <div class="alert alert-danger">

        <?= htmlspecialchars($accessError) ?>

    </div>

<?php endif; ?>


<!-- DASHBOARD CONTENT -->

<div class="card"
     style="padding: 30px;">

    <h2 style="
        color: var(--navy-900);
        margin-bottom: 8px;
    ">

        Welcome back,
        <?= htmlspecialchars($_SESSION['full_name']) ?>

    </h2>


    <p style="
        color: var(--gray-500);
    ">

        You are logged in as
        <strong>
            <?= htmlspecialchars($_SESSION['role']) ?>
        </strong>.

    </p>

</div>


<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';

?>