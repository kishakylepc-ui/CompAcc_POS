<?php

session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: /dashboard/');
    exit;
}

$error = $_SESSION['login_error'] ?? null;
$oldUsername = $_SESSION['old_username'] ?? '';

unset(
    $_SESSION['login_error'],
    $_SESSION['old_username']
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>POS Login</title>

    <link
        rel="stylesheet"
        href="/assets/css/app.css"
    >

    <link
        rel="stylesheet"
        href="/assets/css/login.css"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,400,0,0"
        rel="stylesheet"
    >

</head>


<body class="login-page">


<div class="login-screen">


    <!-- LEFT SIDE -->

    <section class="login-left">

        <div class="left-content">

            <div class="hero-small-title">
            </div>

            <h1>
            </h1>

            <p>
            </p>

        </div>

    </section>



    <!-- RIGHT SIDE -->

    <section class="login-right">

        <div class="login-card">


            <!-- LOGO -->

            <div class="login-logo">

                <img
                    src="/assets/images/UA_logo.jpg"
                    alt="Underground Apparel Logo"
                >

            </div>



            <!-- LOGIN HEADER -->

            <div class="login-heading">

                <h2>
                    Log in to POS
                </h2>

                <p>
                    Access your authorized system tools
                </p>

            </div>



            <!-- ERROR MESSAGE -->

            <?php if ($error): ?>

                <div class="login-error">

                    <span class="material-symbols-rounded">
                        error
                    </span>

                    <span>
                        <?= htmlspecialchars($error) ?>
                    </span>

                </div>

            <?php endif; ?>



            <!-- LOGIN FORM -->

            <form
                action="/authenticate.php"
                method="POST"
                class="login-form"
            >


                <!-- USERNAME -->

                <div class="field">

                    <label for="username">
                        Username
                    </label>

                    <div class="field-control">

                        <span
                            class="material-symbols-rounded field-icon"
                        >
                            person
                        </span>

                        <input
                            type="text"
                            id="username"
                            name="username"
                            placeholder="Enter your username"
                            value="<?= htmlspecialchars($oldUsername) ?>"
                            autocomplete="username"
                            required
                            autofocus
                        >

                    </div>

                </div>



                <!-- PASSWORD -->

                <div class="field">

                    <label for="password">
                        Password
                    </label>

                    <div class="field-control">

                        <span
                            class="material-symbols-rounded field-icon"
                        >
                            lock
                        </span>

                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >

                        <button
                            type="button"
                            class="show-password"
                            id="showPassword"
                            aria-label="Show password"
                        >

                            <span
                                class="material-symbols-rounded"
                                id="passwordIcon"
                            >
                                visibility
                            </span>

                        </button>

                    </div>

                </div>



                <!-- REMEMBER / FORGOT -->

                <div class="login-options">

                    <label class="remember">

                        <input
                            type="checkbox"
                            name="remember"
                            value="1"
                        >

                        <span>
                            Remember me
                        </span>

                    </label>

                    <a
                        href="#"
                        class="forgot-password"
                    >
                        Forgot password?
                    </a>

                </div>



                <!-- LOGIN BUTTON -->

                <button
                    type="submit"
                    class="login-button"
                >
                    Login
                </button>


            </form>



            <!-- LOGIN FOOTER -->

            <div class="login-footer">

                <div class="secure-text">

                    <span class="material-symbols-rounded">
                        shield_lock
                    </span>

                    <span>
                        Secure access for authorized personnel
                    </span>

                </div>

                <div class="copyright">
                    © <?= date('Y') ?> Underground Apparel POS
                </div>

            </div>


        </div>

    </section>


</div>



<script>

/* =========================================================
   SHOW / HIDE PASSWORD
========================================================= */

const passwordInput =
    document.getElementById('password');

const showPassword =
    document.getElementById('showPassword');

const passwordIcon =
    document.getElementById('passwordIcon');


showPassword.addEventListener(
    'click',
    function () {

        const isPassword =
            passwordInput.type === 'password';


        passwordInput.type =
            isPassword
                ? 'text'
                : 'password';


        passwordIcon.textContent =
            isPassword
                ? 'visibility_off'
                : 'visibility';


        showPassword.setAttribute(
            'aria-label',
            isPassword
                ? 'Hide password'
                : 'Show password'
        );

    }
);

</script>


</body>

</html>