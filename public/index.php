<?php

session_start();

/*
|--------------------------------------------------------------------------
| APPLICATION ENTRY POINT
|--------------------------------------------------------------------------
|
| If the user is already logged in, send them to the dashboard.
| Otherwise, send them to the login page.
|
*/

if (isset($_SESSION['user_id'])) {

    header('Location: /dashboard/');
    exit;
}

header('Location: /login.php');
exit;