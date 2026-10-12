<?php

session_start();

require_once __DIR__ . '/../app/config/database.php';


/*
|--------------------------------------------------------------------------
| FORGOT PASSWORD - REQUEST TO AN ADMIN
|--------------------------------------------------------------------------
|
| There is no email server, so nobody can reset a password by themselves.
| This page saves a PASSWORD_RESET_REQUEST row in system_logs; Admins see
| the open requests in Accounts and set a new password in Accounts -> Edit.
|
| The answer is the same whether the username exists or not, so this page
| cannot be used to find usernames. Requests are limited per username and
| in total, so the logs cannot be flooded. Dates in system_logs are UTC.
|
*/

const RESET_REQUESTS_PER_USERNAME = 3;   /* per hour */
const RESET_REQUESTS_TOTAL = 20;         /* per hour, all usernames together */


/*
|--------------------------------------------------------------------------
| ONLY ALLOW POST REQUESTS FROM SIGNED-OUT USERS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header('Location: /login.php');
    exit;
}


if (isset($_SESSION['user_id'])) {

    header('Location: /dashboard/');
    exit;
}


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

$submittedToken =
    (string) (
        $_POST['csrf_token']
        ?? ''
    );

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals(
        $_SESSION['csrf_token'],
        $submittedToken
    )
) {

    $_SESSION['login_error'] =
        'Invalid request. Please refresh the page and try again.';

    header('Location: /login.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| GET USERNAME
|--------------------------------------------------------------------------
*/

/* substr, not mb_substr: the mbstring extension is not enabled here. */
$username = substr(
    trim(
        (string) (
            $_POST['username'] ?? ''
        )
    ),
    0,
    50
);


if ($username === '') {

    $_SESSION['login_error'] =
        'Enter your username to request a new password.';

    $_SESSION['forgot_open'] = true;

    header('Location: /login.php');
    exit;
}


$_SESSION['old_username'] =
    $username;


try {

    /*
    |----------------------------------------------------------------------
    | FIND USER (any status: an Admin decides what to do)
    |----------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id
        FROM users
        WHERE username = ?
        LIMIT 1
    ");

    $stmt->execute([
        $username
    ]);

    $userId =
        $stmt->fetchColumn();

    $userId =
        $userId === false
            ? null
            : (int) $userId;


    $requestDetails =
        $userId !== null
            ? 'Password reset requested for username: ' . $username
            : 'Password reset requested for unknown username: ' . $username;


    /*
    |----------------------------------------------------------------------
    | LIMITS (the same for known and unknown usernames)
    |----------------------------------------------------------------------
    */

    $limitStatement = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN details IN (?, ?) THEN 1 ELSE 0 END) AS for_username
        FROM system_logs
        WHERE action = 'PASSWORD_RESET_REQUEST'
          AND created_at >= datetime('now', '-60 minutes')
    ");

    $limitStatement->execute([
        'Password reset requested for username: ' . $username,
        'Password reset requested for unknown username: ' . $username
    ]);

    $limits =
        $limitStatement->fetch();


    if (
        (int) $limits['for_username'] >= RESET_REQUESTS_PER_USERNAME ||
        (int) $limits['total'] >= RESET_REQUESTS_TOTAL
    ) {

        $_SESSION['login_error'] =
            'Too many password requests. Please wait an hour, or ask an Admin directly.';

        header('Location: /login.php');
        exit;
    }


    /*
    |----------------------------------------------------------------------
    | SAVE THE REQUEST
    |----------------------------------------------------------------------
    */

    $pdo->prepare("
        INSERT INTO system_logs (
            user_id,
            action,
            module,
            record_type,
            record_id,
            details
        )
        VALUES (?, 'PASSWORD_RESET_REQUEST', 'Authentication', 'User', ?, ?)
    ")->execute([
        $userId,
        $userId,
        $requestDetails
    ]);

} catch (PDOException $e) {

    $_SESSION['login_error'] =
        'The request could not be sent. Please ask an Admin directly.';

    header('Location: /login.php');
    exit;
}


$_SESSION['login_notice'] =
    'Request sent. If that username exists, an Admin will see it in Accounts and set a new password for you. Ask the Admin for your new password.';

header('Location: /login.php');
exit;
