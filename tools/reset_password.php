<?php

declare(strict_types=1);

/**
 * CompAcc POS - Reset a Forgotten Password (rescue tool)
 *
 * Purpose:
 * - For when nobody can sign in as Admin (for example the only Admin forgot
 *   the password). Normally an Admin resets passwords in Accounts -> Edit.
 *
 * What it does:
 * - Lists the accounts, asks for the username and a new password (typed
 *   twice, at least 6 characters) and asks "YES" before saving.
 * - Copies storage/database/pos.sqlite to storage/backups/ first.
 * - Saves the new password (hashed) and writes a System Log entry. The
 *   wrong-password lock of that username ends, because the count of wrong
 *   tries starts again after a password change.
 *
 * Only someone with access to this computer can run it. The password is
 * visible while you type it, so make sure nobody is watching.
 *
 * Run from the project root:
 *   C:\php\php.exe tools\reset_password.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}


function resetFail(string $message): never
{
    fwrite(STDERR, PHP_EOL . 'PASSWORD RESET STOPPED' . PHP_EOL . $message . PHP_EOL);
    exit(1);
}


function resetPrompt(string $label): string
{
    echo $label;

    $line = fgets(STDIN);

    return $line === false
        ? ''
        : rtrim($line, "\r\n");
}


echo 'CompAcc POS - Reset a Forgotten Password' . PHP_EOL;
echo '========================================' . PHP_EOL;


/*
|--------------------------------------------------------------------------
| DATABASE (never create a new one here)
|--------------------------------------------------------------------------
*/

$databaseFile = __DIR__ . '/../storage/database/pos.sqlite';

if (!is_file($databaseFile)) {
    resetFail('No database found at storage/database/pos.sqlite, so nothing was changed.');
}

require_once __DIR__ . '/../app/config/database.php';


/*
|--------------------------------------------------------------------------
| CHOOSE THE ACCOUNT
|--------------------------------------------------------------------------
*/

$accounts = $pdo
    ->query("SELECT id, username, first_name, last_name, role, status FROM users ORDER BY role, username")
    ->fetchAll();

if ($accounts === []) {
    resetFail('This database has no accounts. Use tools/setup_database.php on a new computer instead.');
}

echo PHP_EOL . 'Accounts:' . PHP_EOL;

foreach ($accounts as $account) {
    echo '  - ' . $account['username']
        . '  (' . trim($account['first_name'] . ' ' . $account['last_name'])
        . ', ' . $account['role']
        . ($account['status'] !== 'Active' ? ', INACTIVE' : '')
        . ')' . PHP_EOL;
}

$username = trim(resetPrompt(PHP_EOL . 'Username to reset (leave empty to cancel): '));

if ($username === '') {
    echo 'Cancelled. Nothing was changed.' . PHP_EOL;
    exit(0);
}

$user = null;

foreach ($accounts as $account) {
    if ($account['username'] === $username) {
        $user = $account;
    }
}

if ($user === null) {
    resetFail('There is no account with the username "' . $username . '" (capital letters count). Nothing was changed.');
}


/*
|--------------------------------------------------------------------------
| NEW PASSWORD
|--------------------------------------------------------------------------
*/

echo PHP_EOL . 'The password is visible while you type it.' . PHP_EOL;

$newPassword = resetPrompt('New password (at least 6 characters): ');

if (strlen($newPassword) < 6) {
    resetFail('The password must contain at least 6 characters. Nothing was changed.');
}

if (resetPrompt('Type the new password again: ') !== $newPassword) {
    resetFail('The two passwords do not match. Nothing was changed.');
}


$activate = false;

if ($user['status'] !== 'Active') {
    $activate = strtolower(trim(resetPrompt(
        PHP_EOL . 'This account is INACTIVE and cannot sign in. Make it Active too? (y/N): '
    ))) === 'y';
}


$summary = 'Reset the password of ' . $user['username'] . ' (' . $user['role'] . ')'
    . ($activate ? ' and make the account Active' : '') . '.';

echo PHP_EOL . $summary . PHP_EOL;

if (resetPrompt('Type YES to continue: ') !== 'YES') {
    echo 'Cancelled. Nothing was changed.' . PHP_EOL;
    exit(0);
}


/*
|--------------------------------------------------------------------------
| BACKUP FIRST
|--------------------------------------------------------------------------
*/

$backupDirectory = __DIR__ . '/../storage/backups';

if (
    !is_dir($backupDirectory)
    && !mkdir($backupDirectory, 0775, true)
    && !is_dir($backupDirectory)
) {
    resetFail('Unable to create the backup folder storage/backups. Nothing was changed.');
}

$backupPath =
    $backupDirectory
    . '/pos-before-password-reset-'
    . date('Ymd-His')
    . '.sqlite';

if (!copy($databaseFile, $backupPath)) {
    resetFail('Unable to create the database backup. Nothing was changed.');
}

echo PHP_EOL . 'Backup created:' . PHP_EOL . realpath($backupPath) . PHP_EOL;


/*
|--------------------------------------------------------------------------
| SAVE (one transaction)
|--------------------------------------------------------------------------
*/

try {
    $pdo->exec('PRAGMA busy_timeout = 5000;');

    $pdo->beginTransaction();

    $pdo->prepare("
        UPDATE users
        SET password = ?,
            status = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([
        password_hash($newPassword, PASSWORD_DEFAULT),
        $activate ? 'Active' : $user['status'],
        (int) $user['id']
    ]);

    /* "password changed" in the details: My Account shows it as the last
       password change, and the login lock counts again from here. */
    $pdo->prepare("
        INSERT INTO system_logs (
            user_id,
            action,
            module,
            record_type,
            record_id,
            details
        )
        VALUES (NULL, 'UPDATE_ACCOUNT', 'Accounts', 'User', ?, ?)
    ")->execute([
        (int) $user['id'],
        'Password reset from the command line (tools/reset_password.php) for username: '
        . $user['username']
        . ($activate ? '; account set Active' : '')
        . '; password changed'
    ]);

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    resetFail('The password was not changed: ' . $e->getMessage());
}


echo PHP_EOL . 'Done. ' . $user['username'] . ' can now sign in with the new password.' . PHP_EOL;

if ($user['status'] !== 'Active' && !$activate) {
    echo 'Note: the account is still Inactive, so it cannot sign in yet.' . PHP_EOL;
}
