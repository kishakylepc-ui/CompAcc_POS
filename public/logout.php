<?php

session_start();

require_once __DIR__ . '/../app/config/database.php';


/*
|--------------------------------------------------------------------------
| Record logout before destroying session
|--------------------------------------------------------------------------
*/

if (isset($_SESSION['user_id'])) {

    $log = $pdo->prepare("
        INSERT INTO system_logs (
            user_id,
            action,
            module,
            details
        )
        VALUES (?, ?, ?, ?)
    ");

    $log->execute([
        $_SESSION['user_id'],
        'LOGOUT',
        'Authentication',
        'User logged out'
    ]);
}


/*
|--------------------------------------------------------------------------
| Destroy session
|--------------------------------------------------------------------------
*/

$_SESSION = [];

session_destroy();

header('Location: /login.php');
exit;