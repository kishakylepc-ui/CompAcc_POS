<?php

session_start();

require_once __DIR__ . '/../app/config/database.php';


/*
|--------------------------------------------------------------------------
| ONLY ALLOW POST REQUESTS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header('Location: /login.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| GET LOGIN INPUT
|--------------------------------------------------------------------------
*/

$username = trim(
    $_POST['username'] ?? ''
);

$password =
    $_POST['password'] ?? '';


/*
|--------------------------------------------------------------------------
| SAVE OLD INPUT
|--------------------------------------------------------------------------
*/

$_SESSION['old_username'] =
    $username;


/*
|--------------------------------------------------------------------------
| BASIC VALIDATION
|--------------------------------------------------------------------------
*/

if (
    $username === '' ||
    $password === ''
) {

    $_SESSION['login_error'] =
        'Please enter your username and password.';

    header('Location: /login.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| FIND USER IN SQLITE
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        username,
        password,
        first_name,
        middle_name,
        last_name,
        suffix,
        role,
        status
    FROM users
    WHERE username = ?
    LIMIT 1
");

$stmt->execute([
    $username
]);

$user = $stmt->fetch();


/*
|--------------------------------------------------------------------------
| WRONG-PASSWORD LIMIT
|--------------------------------------------------------------------------
|
| Every wrong password (or unknown username) is saved in system_logs as
| LOGIN_FAILED, so an Admin can see guessing in System Logs. After
| LOGIN_MAX_FAILURES wrong tries for one username (within the last
| LOGIN_FAILURE_WINDOW minutes, since its last successful login or its
| last password change), that
| username is locked until LOGIN_LOCK_MINUTES after the last wrong try,
| even for the right password. Dates in system_logs are UTC.
|
*/

const LOGIN_MAX_FAILURES = 5;
const LOGIN_LOCK_MINUTES = 5;
const LOGIN_FAILURE_WINDOW = 15;


/* The exact details text of a failed try, also used to count them. */
function loginFailureDetails(string $username, bool $userExists): string
{
    /* substr, not mb_substr: the mbstring extension is not enabled here. */
    $shown = substr($username, 0, 60);

    return $userExists
        ? 'Wrong password for username: ' . $shown
        : 'Unknown username: ' . $shown;
}


$failureStatement = $pdo->prepare("
    SELECT
        COUNT(*) AS failures,
        MAX(created_at) AS last_failure
    FROM system_logs
    WHERE action = 'LOGIN_FAILED'
      AND details = ?
      AND created_at >= datetime('now', ?)
      AND created_at > COALESCE((
            SELECT MAX(created_at)
            FROM system_logs
            WHERE (action = 'LOGIN' AND user_id = ?)
               /* A new password (My Account, Accounts -> Edit or
                  tools/reset_password.php) starts the count again. */
               OR (action = 'CHANGE_OWN_PASSWORD' AND user_id = ?)
               OR (
                    action = 'UPDATE_ACCOUNT'
                    AND record_id = ?
                    AND details LIKE '%password changed%'
               )
          ), '')
");

$failureUserId =
    $user ? (int) $user['id'] : 0;

$failureStatement->execute([
    loginFailureDetails($username, (bool) $user),
    '-' . LOGIN_FAILURE_WINDOW . ' minutes',
    $failureUserId,
    $failureUserId,
    $failureUserId
]);

$failureInfo = $failureStatement->fetch();


$lockSecondsLeft = 0;

if ((int) $failureInfo['failures'] >= LOGIN_MAX_FAILURES) {

    $lockedUntil =
        strtotime($failureInfo['last_failure'] . ' UTC')
        + LOGIN_LOCK_MINUTES * 60;

    $lockSecondsLeft =
        max(0, $lockedUntil - time());
}


if ($lockSecondsLeft > 0) {

    $minutesLeft = (int) ceil($lockSecondsLeft / 60);

    $_SESSION['login_error'] =
        'Too many wrong attempts for this username. Please wait '
        . $minutesLeft
        . ($minutesLeft === 1 ? ' minute' : ' minutes')
        . ' and try again.';

    header('Location: /login.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| INVALID USERNAME OR WRONG PASSWORD
|--------------------------------------------------------------------------
|
| Both get the same message, so it does not reveal which usernames exist.
|
*/

if (
    !$user ||
    !password_verify(
        $password,
        $user['password']
    )
) {

    $pdo->prepare("
        INSERT INTO system_logs (
            user_id,
            action,
            module,
            record_type,
            details
        )
        VALUES (?, 'LOGIN_FAILED', 'Authentication', 'Login', ?)
    ")->execute([
        $user ? (int) $user['id'] : null,
        loginFailureDetails($username, (bool) $user)
    ]);


    $failuresNow =
        (int) $failureInfo['failures'] + 1;

    $triesLeft =
        LOGIN_MAX_FAILURES - $failuresNow;


    if ($triesLeft <= 0) {

        $_SESSION['login_error'] =
            'Too many wrong attempts for this username. Please wait '
            . LOGIN_LOCK_MINUTES
            . ' minutes and try again.';

    } elseif ($failuresNow >= 3) {

        $_SESSION['login_error'] =
            'Invalid username or password. '
            . $triesLeft
            . ($triesLeft === 1 ? ' try' : ' tries')
            . ' left before a '
            . LOGIN_LOCK_MINUTES
            . '-minute wait.';

    } else {

        $_SESSION['login_error'] =
            'Invalid username or password.';
    }

    header('Location: /login.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| CHECK ACCOUNT STATUS
|--------------------------------------------------------------------------
*/

if (
    strcasecmp(
        trim($user['status']),
        'Active'
    ) !== 0
) {

    $_SESSION['login_error'] =
        'Your account is currently inactive.';

    header('Location: /login.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| GET ROLE FROM DATABASE
|--------------------------------------------------------------------------
|
| The user no longer selects a role on the login page.
|
| The system automatically determines the role stored in SQLite.
|
*/

$databaseRole = trim(
    $user['role']
);


/*
|--------------------------------------------------------------------------
| VALIDATE DATABASE ROLE
|--------------------------------------------------------------------------
*/

$allowedRoles = [
    'Admin',
    'Manager',
    'Cashier'
];


if (!in_array(
    $databaseRole,
    $allowedRoles,
    true
)) {

    $_SESSION['login_error'] =
        'This account has an invalid system role.';

    header('Location: /login.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| BUILD DISPLAY NAME
|--------------------------------------------------------------------------
*/

$nameParts = [];


/* First Name */

if (!empty(
    $user['first_name']
)) {

    $nameParts[] =
        trim($user['first_name']);
}


/* Middle Name */

if (!empty(
    $user['middle_name']
)) {

    $nameParts[] =
        trim($user['middle_name']);
}


/* Last Name */

if (!empty(
    $user['last_name']
)) {

    $nameParts[] =
        trim($user['last_name']);
}


/* Suffix */

if (!empty(
    $user['suffix']
)) {

    $nameParts[] =
        trim($user['suffix']);
}


$fullName = implode(
    ' ',
    $nameParts
);


/*
|--------------------------------------------------------------------------
| LOGIN SUCCESS
|--------------------------------------------------------------------------
*/

session_regenerate_id(true);


$_SESSION['user_id'] =
    $user['id'];

$_SESSION['username'] =
    $user['username'];

$_SESSION['first_name'] =
    $user['first_name'];

$_SESSION['middle_name'] =
    $user['middle_name'];

$_SESSION['last_name'] =
    $user['last_name'];

$_SESSION['suffix'] =
    $user['suffix'];

$_SESSION['full_name'] =
    $fullName;

$_SESSION['role'] =
    $databaseRole;


/*
|--------------------------------------------------------------------------
| REMOVE OLD LOGIN INPUT
|--------------------------------------------------------------------------
*/

unset(
    $_SESSION['old_username']
);


/*
|--------------------------------------------------------------------------
| SYSTEM LOG
|--------------------------------------------------------------------------
*/

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
    $user['id'],
    'LOGIN',
    'Authentication',
    'User logged in successfully'
]);


/*
|--------------------------------------------------------------------------
| REDIRECT TO DASHBOARD
|--------------------------------------------------------------------------
*/

header('Location: /dashboard/');
exit;