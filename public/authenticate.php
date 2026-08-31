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
| BASIC VALIDATION
|--------------------------------------------------------------------------
*/

if ($username === '' || $password === '') {

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
| CHECK ACCOUNT STATUS
|--------------------------------------------------------------------------
*/

if ($user['status'] !== 'Active') {

    $_SESSION['login_error'] =
        'Your account is currently inactive.';

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
| BUILD DISPLAY NAME
|--------------------------------------------------------------------------
*/

$nameParts = [];

$nameParts[] = $user['first_name'];

if (!empty($user['middle_name'])) {
    $nameParts[] = $user['middle_name'];
}

$nameParts[] = $user['last_name'];

if (!empty($user['suffix'])) {
    $nameParts[] = $user['suffix'];
}

$fullName = implode(
    ' ',
    $nameParts
);


/*
|--------------------------------------------------------------------------
| LOGIN SUCCESSFUL
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
    $user['role'];


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