<?php

require_once __DIR__ . '/auth.php';


function requireRole(array $allowedRoles): void
{

    $userRole = $_SESSION['role'] ?? '';


    if (!in_array($userRole, $allowedRoles, true)) {

        $_SESSION['access_error'] =
            'You do not have permission to access that page.';

        header('Location: /dashboard/');
        exit;
    }
}