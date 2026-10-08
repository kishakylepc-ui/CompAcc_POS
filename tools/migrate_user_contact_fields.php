<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/config/database.php';

/**
 * CompAcc POS - User Contact Fields Migration
 *
 * Purpose:
 * - Add optional email and contact number columns to users, used by
 *   Settings > My Account.
 * - Existing accounts keep all their data; the new columns start empty.
 *
 * Safe to run more than once: columns that already exist are skipped.
 *
 * Run once from the project root:
 *   C:\php\php.exe tools\migrate_user_contact_fields.php
 */

function userColumnExists(PDO $pdo, string $column): bool
{
    $columns = $pdo->query('PRAGMA table_info(users)')->fetchAll();

    foreach ($columns as $existing) {
        if ((string) $existing['name'] === $column) {
            return true;
        }
    }

    return false;
}

$dbPath = __DIR__ . '/../storage/database/pos.sqlite';
$backupDirectory = __DIR__ . '/../storage/backups';

if (!is_file($dbPath)) {
    fwrite(STDERR, "Database not found: {$dbPath}" . PHP_EOL);
    exit(1);
}

echo "CompAcc POS - User Contact Fields Migration" . PHP_EOL;
echo "===========================================" . PHP_EOL;

$missing = array_values(array_filter(
    ['email', 'contact_number'],
    static fn (string $column): bool => !userColumnExists($pdo, $column)
));

if ($missing === []) {
    echo "Nothing to do: users.email and users.contact_number already exist." . PHP_EOL;
    exit(0);
}

if (
    !is_dir($backupDirectory)
    && !mkdir($backupDirectory, 0775, true)
    && !is_dir($backupDirectory)
) {
    fwrite(STDERR, "Unable to create backup directory: {$backupDirectory}" . PHP_EOL);
    exit(1);
}

$backupPath =
    $backupDirectory
    . '/pos-before-user-contact-fields-'
    . date('Ymd-His')
    . '.sqlite';

if (!copy($dbPath, $backupPath)) {
    fwrite(STDERR, "Unable to create database backup." . PHP_EOL);
    exit(1);
}

echo "Backup created:" . PHP_EOL;
echo $backupPath . PHP_EOL . PHP_EOL;

try {
    $pdo->exec('PRAGMA busy_timeout = 5000;');

    $pdo->beginTransaction();

    foreach ($missing as $column) {
        $pdo->exec("ALTER TABLE users ADD COLUMN {$column} TEXT");
        echo "Added column: users.{$column}" . PHP_EOL;
    }

    $pdo->commit();

    echo PHP_EOL . "Done. Existing accounts were not changed." . PHP_EOL;

} catch (Throwable $error) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . PHP_EOL);
    fwrite(STDERR, 'The database was not changed. Backup: ' . $backupPath . PHP_EOL);
    exit(1);
}
