<?php

session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: /dashboard/');
    exit;
}

$error = $_SESSION['login_error'] ?? null;
$oldUsername = $_SESSION['old_username'] ?? '';
$oldRole = $_SESSION['old_role'] ?? '';

unset(
    $_SESSION['login_error'],
    $_SESSION['old_username'],
    $_SESSION['old_role']
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
                    alt="UA Logo"
                >

            </div>


            <div class="login-heading">

                <h2>
                   Log in to POS
                </h2>

                <p>
                    Access your cashier and inventory tools
                </p>

            </div>



            <?php if ($error): ?>

                <div class="login-error">

                    <span class="material-symbols-rounded">
                        error
                    </span>

                    <?= htmlspecialchars($error) ?>

                </div>

            <?php endif; ?>



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

                        <span class="material-symbols-rounded field-icon">
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
                        >

                    </div>

                </div>



                <!-- PASSWORD -->

                <div class="field">

                    <label for="password">
                        Password
                    </label>

                    <div class="field-control">

                        <span class="material-symbols-rounded field-icon">
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



                <!-- ROLE -->

                <div class="field role-field">

                    <div class="role-label">

                        <label>
                            User Role
                        </label>

                        <span
                            class="material-symbols-rounded help-icon"
                            title="Select access level"
                        >
                            help
                        </span>

                    </div>

                    <span class="role-subtitle">
                        Select access level
                    </span>


                    <input
                        type="hidden"
                        name="role"
                        id="roleInput"
                        value="<?= htmlspecialchars($oldRole) ?>"
                    >


                    <div
                        class="role-select"
                        id="roleSelect"
                    >

                        <button
                            type="button"
                            class="role-select-button"
                            id="roleSelectButton"
                        >

                            <div class="role-selected-left">

                                <span class="material-symbols-rounded">
                                    badge
                                </span>

                                <span id="selectedRoleText">
                                    <?= $oldRole !== ''
                                        ? htmlspecialchars($oldRole)
                                        : 'Select role'
                                    ?>
                                </span>

                            </div>

                            <span
                                class="material-symbols-rounded arrow"
                                id="roleArrow"
                            >
                                keyboard_arrow_down
                            </span>

                        </button>


                        <div
                            class="role-menu"
                            id="roleMenu"
                        >


                            <button
                                type="button"
                                class="role-option"
                                data-role="Admin"
                            >

                                <span class="material-symbols-rounded">
                                    manage_accounts
                                </span>

                                <span class="role-option-text">

                                    <strong>Admin</strong>

                                    <small>
                                        Full system access
                                    </small>

                                </span>

                            </button>


                            <button
                                type="button"
                                class="role-option"
                                data-role="Manager"
                            >

                                <span class="material-symbols-rounded">
                                    supervisor_account
                                </span>

                                <span class="role-option-text">

                                    <strong>Manager</strong>

                                    <small>
                                        Manage operations and reports
                                    </small>

                                </span>

                            </button>


                            <button
                                type="button"
                                class="role-option"
                                data-role="Cashier"
                            >

                                <span class="material-symbols-rounded">
                                    point_of_sale
                                </span>

                                <span class="role-option-text">

                                    <strong>Cashier</strong>

                                    <small>
                                        Process sales and customer transactions
                                    </small>

                                </span>

                            </button>


                        </div>

                    </div>

                </div>



                <!-- LOGIN -->

                <button
                    type="submit"
                    class="login-button"
                >
                    Login
                </button>


            </form>



            <!-- BOTTOM -->

            <div class="or-divider">

                <span>
                    OR
                </span>

            </div>


            <div class="secure-text">

                <span class="material-symbols-rounded">
                    shield
                </span>

                Secure access to UA POS

            </div>


            <div class="copyright">
                © <?= date('Y') ?> Underground Apparel.
                All rights reserved.
            </div>


        </div>

    </section>


</div>



<script>

/* PASSWORD */

const passwordInput =
    document.getElementById('password');

const showPassword =
    document.getElementById('showPassword');

const passwordIcon =
    document.getElementById('passwordIcon');


showPassword.addEventListener(
    'click',
    function () {

        if (passwordInput.type === 'password') {

            passwordInput.type = 'text';
            passwordIcon.textContent = 'visibility_off';

        } else {

            passwordInput.type = 'password';
            passwordIcon.textContent = 'visibility';

        }

    }
);



/* ROLE DROPDOWN */

const roleSelectButton =
    document.getElementById('roleSelectButton');

const roleMenu =
    document.getElementById('roleMenu');

const roleInput =
    document.getElementById('roleInput');

const selectedRoleText =
    document.getElementById('selectedRoleText');

const roleArrow =
    document.getElementById('roleArrow');

const roleOptions =
    document.querySelectorAll('.role-option');


roleSelectButton.addEventListener(
    'click',
    function () {

        roleMenu.classList.toggle('show');
        roleArrow.classList.toggle('rotate');

    }
);


roleOptions.forEach(option => {

    option.addEventListener(
        'click',
        function () {

            const role =
                this.dataset.role;

            roleInput.value =
                role;

            selectedRoleText.textContent =
                role;

            roleMenu.classList.remove('show');

            roleArrow.classList.remove('rotate');

        }
    );

});


document.addEventListener(
    'click',
    function (event) {

        const selector =
            document.getElementById('roleSelect');

        if (!selector.contains(event.target)) {

            roleMenu.classList.remove('show');
            roleArrow.classList.remove('rotate');

        }

    }
);


/* FORM VALIDATION */

document
    .querySelector('.login-form')
    .addEventListener(
        'submit',
        function (event) {

            if (!roleInput.value) {

                event.preventDefault();

                roleSelectButton.classList.add(
                    'role-error'
                );

                roleSelectButton.focus();

            }

        }
    );

</script>


</body>

</html>