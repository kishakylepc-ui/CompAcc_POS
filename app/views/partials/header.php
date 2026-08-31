<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = $pageTitle ?? 'CompAcc POS';

?>

<!DOCTYPE html>
<html lang="en">

<head>
    
<link
    href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,400,0,0"
    rel="stylesheet"
>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= htmlspecialchars($pageTitle) ?> | CompAcc POS
    </title>

    <link
        rel="stylesheet"
        href="/assets/css/app.css"
    >

    <link
        rel="stylesheet"
        href="/assets/css/layout.css"
    >

</head>

<body>

<div class="app-layout">