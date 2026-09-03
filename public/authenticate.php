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
| INVALID USERNAME
|--------------------------------------------------------------------------
*/

if (!$user) {

    $_SESSION['login_error'] =
        'Invalid username or password.';

    header('Location: /login.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| VERIFY PASSWORD
|--------------------------------------------------------------------------
*/

if (!password_verify(
    $password,
    $user['password']
)) {

    $_SESSION['login_error'] =
        'Invalid username or password.';

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