<?php

declare(strict_types=1);

/**
 * CompAcc POS - Environment Check
 *
 * Purpose:
 * - Show whether this computer's PHP can run CompAcc POS (PDO + SQLite),
 *   and whether the database folder is ready. Read-only: changes nothing.
 * - Replaces the old public/check.sqlite.php web page, which anyone on the
 *   network could open and which showed the PHP version.
 *
 * Run from the project root:
 *   C:\php\php.exe tools\check_environment.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$checks = [
    'PDO is enabled' => extension_loaded('pdo'),
    'PDO SQLite is enabled' => extension_loaded('pdo_sqlite'),
    'SQLite3 is enabled' => extension_loaded('sqlite3')
];

$databaseFolder = __DIR__ . '/../storage/database';
$databaseFile = $databaseFolder . '/pos.sqlite';

echo 'CompAcc POS - Environment Check' . PHP_EOL;
echo '===============================' . PHP_EOL;
echo 'PHP version: ' . PHP_VERSION . PHP_EOL . PHP_EOL;

foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $label . PHP_EOL;
}

$folderOk = is_dir($databaseFolder) && is_writable($databaseFolder);
echo ($folderOk ? '[OK]   ' : '[FAIL] ') . 'storage/database can be written to' . PHP_EOL;

echo PHP_EOL;
echo is_file($databaseFile)
    ? 'Database: storage/database/pos.sqlite exists.' . PHP_EOL
    : 'Database: none yet. On a new computer run: C:\php\php.exe tools\setup_database.php' . PHP_EOL;

echo PHP_EOL;

if ($checks['PDO is enabled'] && $checks['PDO SQLite is enabled'] && $folderOk) {
    echo 'Ready for PHP + SQLite.' . PHP_EOL;
    exit(0);
}

echo 'Not ready: enable extension=pdo_sqlite (and sqlite3) in php.ini, and make sure storage/database exists.' . PHP_EOL;
exit(1);
