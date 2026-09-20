<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = $pageTitle ?? 'UA POS';

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="theme-color"
        content="#050607"
    >

    <title>
        <?= htmlspecialchars($pageTitle) ?> | UA POS
    </title>

    <link
        rel="stylesheet"
        href="/assets/css/app.css?v=20260920"
    >

    <link
        rel="stylesheet"
        href="/assets/css/layout.css?v=20260920"
    >

    <link
        rel="stylesheet"
        href="/assets/css/confirmation-modal.css?v=20260920"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,400,0,0"
        rel="stylesheet"
    >

</head>

<body class="app-body">

<div class="app-layout">
