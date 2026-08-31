<?php

session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: /dashboard/');
    exit;
}

$error = $_SESSION['login_error'] ?? null;

unset($_SESSION['login_error']);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Login | CompAcc POS</title>

    <link
        rel="stylesheet"
        href="/assets/css/app.css"
    >

    <link
        rel="stylesheet"
        href="/assets/css/login.css"
    >

</head>

<body class="login-page">

<div class="login-wrapper">

    <div class="login-card">

        <div class="login-brand">

            <div class="login-logo">
                POS
            </div>

            <h1>
                Point of Sale
            </h1>

            <p>
                Computerized Accounting and
                Point of Sale System
            </p>

        </div>


        <?php if ($error): ?>

            <div class="alert alert-danger">

                <?= htmlspecialchars($error) ?>

            </div>

        <?php endif; ?>


        <form
            action="/authenticate.php"
            method="POST"
            class="login-form"
        >

            <div class="form-group">

                <label for="username">
                    Username
                </label>

                <input
                    type="text"
                    id="username"
                    name="username"
                    class="form-control"
                    placeholder="Enter your username"
                    autocomplete="username"
                    required
                    autofocus
                >

            </div>


            <div class="form-group">

                <label for="password">
                    Password
                </label>

                <div class="password-wrapper">

                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >

                    <button
                        type="button"
                        class="password-toggle"
                        id="passwordToggle"
                    >
                        Show
                    </button>

                </div>

            </div>


            <button
                type="submit"
                class="login-button"
            >
                Sign In
            </button>

        </form>


        <div class="login-security">
            Secure access for Admin, Manager, and Cashier
        </div>


        <div class="login-footer">
            CompAcc POS
        </div>

    </div>

</div>


<script>

const passwordInput =
    document.getElementById('password');

const passwordToggle =
    document.getElementById('passwordToggle');


passwordToggle.addEventListener(
    'click',
    function () {

        if (passwordInput.type === 'password') {

            passwordInput.type = 'text';

            passwordToggle.textContent = 'Hide';

        } else {

            passwordInput.type = 'password';

            passwordToggle.textContent = 'Show';
        }

    }
);

</script>

</body>

</html>