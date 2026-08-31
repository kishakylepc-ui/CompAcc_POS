<?php

session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: /dashboard/');
    exit;
}

header('Location: /login.php');
exit;