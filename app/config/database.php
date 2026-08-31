<?php

$dbPath = __DIR__ . '/../../storage/database/pos.sqlite';

try {

    $pdo = new PDO('sqlite:' . $dbPath);

    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    $pdo->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );

    // Enable SQLite foreign keys
    $pdo->exec('PRAGMA foreign_keys = ON;');

} catch (PDOException $e) {

    die(
        'Database connection failed: '
        . htmlspecialchars($e->getMessage())
    );
}