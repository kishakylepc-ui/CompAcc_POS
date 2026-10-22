<?php

declare(strict_types=1);

require_once __DIR__
    . '/../../app/middleware/role.php';

requireRole([
    'Admin',
    'Manager',
    'Cashier'
]);

require_once __DIR__
    . '/../../app/config/database.php';


/*
|--------------------------------------------------------------------------
| VOID SALE
|--------------------------------------------------------------------------
|
| Cancels a completed sale:
|   1. every item is returned to its exact color/size stock
|      (the database triggers re-sync the product totals),
|   2. each return is written to inventory_logs as "Void Return",
|   3. the sale is marked Voided with who, when and why,
|   4. the action is written to system_logs.
|
| Dashboard and report totals already count only status = 'Completed',
| so a voided sale drops out of every total automatically.
|
| Admin and Manager void directly. A Cashier can void only their OWN sale
| made TODAY (Asia/Manila), and only when a Manager or Admin types their
| own username and password into the void window (see the section below).
|
*/


/*
|--------------------------------------------------------------------------
| CASHIER VOID WITH MANAGER APPROVAL
|--------------------------------------------------------------------------
|
| The Cashier stays signed in as the Cashier: the approver is only checked
| here and is never signed in. The approver's id is what gets recorded as
| "who voided" (sales.voided_by, inventory_logs.user_id, system_logs);
| the system log also says which cashier asked.
|
| Guessing is limited like the login (public/authenticate.php): every wrong
| approval is saved in system_logs as VOID_APPROVAL_FAILED (never the
| password). After VOID_APPROVAL_MAX_FAILURES wrong tries within
| VOID_APPROVAL_WINDOW_MINUTES for the same approver username, OR by the
| same cashier, approvals are blocked until VOID_APPROVAL_LOCK_MINUTES after
| the last wrong try, even with the right password. A successful approval
| starts the count again. Dates in system_logs are UTC.
|
*/

const VOID_REASON_MAX_LENGTH = 500;
const VOID_APPROVAL_MAX_FAILURES = 5;
const VOID_APPROVAL_LOCK_MINUTES = 5;
const VOID_APPROVAL_WINDOW_MINUTES = 15;

const VOID_APPROVAL_FAILED_MESSAGE =
    "Approval failed. Check the Manager's username and password.";

/*
| A real bcrypt hash (cost 12, like our passwords) of a random value nobody
| knows. A wrong or unknown approver is checked against it, so every failed
| approval takes as long as one with a real account.
*/
const VOID_DUMMY_PASSWORD_HASH =
    '$2y$12$cha0ygOJwXd0jFQR.3V2I.k1hd6400hKdskWOKsEWk1Bdoa1RO1mu';


/* Characters, not bytes (the mbstring extension is not enabled here). */
function voidTextLength(string $text): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($text, 'UTF-8');
    }

    $count = preg_match_all('/./us', $text);

    return $count === false
        ? strlen($text)
        : $count;
}


/* The approver name as it is logged and counted: safe characters only. */
function voidApproverNameForLog(string $username): string
{
    return substr(
        (string) preg_replace('/[^A-Za-z0-9._@-]/', '?', $username),
        0,
        60
    );
}


/* So a username with _ or % is matched literally inside LIKE. */
function voidLikeEscape(string $text): string
{
    return str_replace(
        ['\\', '%', '_'],
        ['\\\\', '\\%', '\\_'],
        $text
    );
}


/*
| Seconds until approvals work again (0 = not locked). Wrong tries are
| counted for this cashier AND for the approver username that was typed,
| each since the last successful approval.
*/
function voidApprovalLockSecondsLeft(
    PDO $pdo,
    int $cashierId,
    string $triedName
): int {

    $window = '-' . VOID_APPROVAL_WINDOW_MINUTES . ' minutes';


    $byCashier = $pdo->prepare("
        SELECT
            COUNT(*) AS failures,
            MAX(created_at) AS last_failure
        FROM system_logs
        WHERE action = 'VOID_APPROVAL_FAILED'
          AND user_id = ?
          AND created_at >= datetime('now', ?)
          AND created_at > COALESCE((
                SELECT MAX(sl.created_at)
                FROM system_logs sl
                INNER JOIN sales s
                    ON s.id = sl.record_id
                WHERE sl.action = 'VOID_SALE'
                  AND sl.record_type = 'Sale'
                  AND sl.details LIKE '%Requested by cashier%'
                  AND s.cashier_id = ?
              ), '')
    ");

    $byCashier->execute([
        $cashierId,
        $window,
        $cashierId
    ]);


    $byApprover = $pdo->prepare("
        SELECT
            COUNT(*) AS failures,
            MAX(created_at) AS last_failure
        FROM system_logs
        WHERE action = 'VOID_APPROVAL_FAILED'
          AND details LIKE ? ESCAPE '\\'
          AND created_at >= datetime('now', ?)
          AND created_at > COALESCE((
                SELECT MAX(sl.created_at)
                FROM system_logs sl
                INNER JOIN users u
                    ON u.id = sl.user_id
                WHERE sl.action = 'VOID_SALE'
                  AND sl.details LIKE '%Requested by cashier%'
                  AND u.username = ?
              ), '')
    ");

    $byApprover->execute([
        'Approver username tried: [' . voidLikeEscape($triedName) . '] | %',
        $window,
        $triedName
    ]);


    $secondsLeft = 0;

    foreach ([$byCashier, $byApprover] as $statement) {

        $row = $statement->fetch();

        if ((int) $row['failures'] >= VOID_APPROVAL_MAX_FAILURES) {

            $lockedUntil =
                strtotime($row['last_failure'] . ' UTC')
                + VOID_APPROVAL_LOCK_MINUTES * 60;

            $secondsLeft = max(
                $secondsLeft,
                $lockedUntil - time()
            );
        }
    }

    return max(0, $secondsLeft);
}


/*
| Saved on its own (outside the void transaction), so the wrong try is kept
| even though the void does not happen. The password is never logged.
*/
function voidApprovalLogFailure(
    PDO $pdo,
    int $cashierId,
    string $cashierUsername,
    int $saleId,
    string $triedName
): void {

    $pdo->prepare("
        INSERT INTO system_logs (
            user_id,
            action,
            module,
            record_type,
            record_id,
            details
        )
        VALUES (?, 'VOID_APPROVAL_FAILED', 'POS', 'Sale', ?, ?)
    ")->execute([
        $cashierId,
        $saleId,
        'Approver username tried: [' . $triedName . '] | Requested by cashier: '
            . substr($cashierUsername, 0, 60)
    ]);
}

function voidSaleRedirect(
    int $saleId,
    string $type,
    string $message
): never {

    $_SESSION[
        $type === 'success'
            ? 'receipt_success'
            : 'receipt_error'
    ] = $message;

    header(
        'Location: '
        . ($saleId > 0 ? '/pos/receipt.php?id=' . $saleId : '/pos/')
    );

    exit;
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /pos/');
    exit;
}


$saleId = (int) ($_POST['sale_id'] ?? 0);

$reason = trim((string) ($_POST['void_reason'] ?? ''));

$submittedToken = (string) ($_POST['csrf_token'] ?? '');


if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $submittedToken)
) {
    voidSaleRedirect(
        $saleId,
        'error',
        'Your session expired. Refresh the page and try again.'
    );
}

if ($saleId <= 0) {
    voidSaleRedirect(0, 'error', 'Invalid sale.');
}

if ($reason === '') {
    voidSaleRedirect(
        $saleId,
        'error',
        'Enter the reason for voiding this sale.'
    );
}

if (voidTextLength($reason) > VOID_REASON_MAX_LENGTH) {
    voidSaleRedirect(
        $saleId,
        'error',
        'Keep the void reason under 500 characters.'
    );
}


/*
| Who is recorded as voiding: the signed-in Admin / Manager, or, for a
| Cashier, the Manager / Admin who approved it.
*/

$voidedById = (int) ($_SESSION['user_id'] ?? 0);

$requestedByCashier = '';


try {

    /*
    | CASHIER: own sale, made today, and a valid Manager / Admin approval.
    | All of this is checked here on the server, before anything is changed.
    */

    if (($_SESSION['role'] ?? '') === 'Cashier') {

        $cashierId = (int) ($_SESSION['user_id'] ?? 0);

        $approverUsername = trim((string) ($_POST['approver_username'] ?? ''));

        $approverPassword = (string) ($_POST['approver_password'] ?? '');

        if ($approverUsername === '' || $approverPassword === '') {
            throw new RuntimeException(
                "Enter the Manager's username and password to approve this void."
            );
        }


        $gateStatement = $pdo->prepare("
            SELECT id, cashier_id, status, created_at
            FROM sales
            WHERE id = ?
            LIMIT 1
        ");

        $gateStatement->execute([$saleId]);

        $gateSale = $gateStatement->fetch();

        if (!$gateSale) {
            throw new RuntimeException('Sale not found.');
        }

        if ($gateSale['status'] !== 'Completed') {
            throw new RuntimeException('This sale has already been voided.');
        }


        /* created_at is UTC; "today" is the Philippine day. */
        $saleDay =
            (new DateTimeImmutable(
                (string) $gateSale['created_at'],
                new DateTimeZone('UTC')
            ))
                ->setTimezone(new DateTimeZone('Asia/Manila'))
                ->format('Y-m-d');

        $today =
            (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))
                ->format('Y-m-d');

        if (
            (int) $gateSale['cashier_id'] !== $cashierId ||
            $saleDay !== $today
        ) {
            throw new RuntimeException(
                'A Cashier can only void their own sales from today. '
                . 'Ask a Manager to void this sale.'
            );
        }


        $cashierStatement = $pdo->prepare("
            SELECT username
            FROM users
            WHERE id = ?
            LIMIT 1
        ");

        $cashierStatement->execute([$cashierId]);

        $cashierUsername = (string) $cashierStatement->fetchColumn();

        $triedName = voidApproverNameForLog($approverUsername);


        /* Too many wrong tries: refuse BEFORE checking any password. */
        $lockSeconds = voidApprovalLockSecondsLeft(
            $pdo,
            $cashierId,
            $triedName
        );

        if ($lockSeconds > 0) {

            $minutesLeft = (int) ceil($lockSeconds / 60);

            throw new RuntimeException(
                'Too many failed approvals. Please wait '
                . $minutesLeft
                . ($minutesLeft === 1 ? ' minute' : ' minutes')
                . ' and try again.'
            );
        }


        /*
        | The role and status come from the database, never from the form.
        | One password check always runs (against a dummy hash when the
        | username does not exist), so a wrong username, a wrong password
        | and an ineligible account all look and take the same.
        */

        $approverStatement = $pdo->prepare("
            SELECT id, password, role, status
            FROM users
            WHERE username = ?
            LIMIT 1
        ");

        $approverStatement->execute([$approverUsername]);

        $approver = $approverStatement->fetch();

        $passwordMatches = password_verify(
            $approverPassword,
            $approver
                ? (string) $approver['password']
                : VOID_DUMMY_PASSWORD_HASH
        );

        $approverAllowed =
            $approver
            && $approver['status'] === 'Active'
            && in_array($approver['role'], ['Manager', 'Admin'], true)
            && (int) $approver['id'] !== $cashierId;

        if (!$passwordMatches || !$approverAllowed) {

            voidApprovalLogFailure(
                $pdo,
                $cashierId,
                $cashierUsername,
                $saleId,
                $triedName
            );

            throw new RuntimeException(VOID_APPROVAL_FAILED_MESSAGE);
        }

        $voidedById = (int) $approver['id'];

        $requestedByCashier = $cashierUsername;
    }


    $pdo->beginTransaction();


    /*
    | Sale must exist and still be completed.
    */

    $saleStatement = $pdo->prepare("
        SELECT id, transaction_no, total_amount, status
        FROM sales
        WHERE id = ?
        LIMIT 1
    ");

    $saleStatement->execute([$saleId]);

    $sale = $saleStatement->fetch();

    if (!$sale) {
        throw new RuntimeException('Sale not found.');
    }

    if ($sale['status'] !== 'Completed') {
        throw new RuntimeException('This sale has already been voided.');
    }


    /*
    | Items to return. Sales recorded before color/size tracking have no
    | variant, so their stock cannot be returned to an exact size.
    */

    $itemStatement = $pdo->prepare("
        SELECT
            si.product_id,
            si.variant_id,
            si.product_name,
            si.color,
            si.size,
            si.quantity,
            pv.id AS existing_variant_id
        FROM sale_items si
        LEFT JOIN product_variants pv
            ON pv.id = si.variant_id
           AND pv.product_id = si.product_id
        WHERE si.sale_id = ?
        ORDER BY si.id ASC
    ");

    $itemStatement->execute([$saleId]);

    $items = $itemStatement->fetchAll();

    if (empty($items)) {
        throw new RuntimeException('This sale has no items to return.');
    }

    foreach ($items as $item) {
        if ($item['existing_variant_id'] === null) {
            throw new RuntimeException(
                'This sale was recorded before color/size tracking, '
                . 'so its stock cannot be returned automatically.'
            );
        }
    }


    /*
    | Return stock to each variant and log it.
    */

    $stockRead = $pdo->prepare("
        SELECT stock_quantity
        FROM product_variants
        WHERE id = ?
        LIMIT 1
    ");

    $stockUpdate = $pdo->prepare("
        UPDATE product_variants
        SET
            stock_quantity = stock_quantity + ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND product_id = ?
    ");

    $inventoryLog = $pdo->prepare("
        INSERT INTO inventory_logs (
            product_id,
            variant_id,
            user_id,
            supplier_id,
            sale_id,
            stock_receipt_id,
            action,
            color,
            size,
            quantity_change,
            previous_stock,
            new_stock,
            notes
        )
        VALUES (?, ?, ?, NULL, ?, NULL, 'Void Return', ?, ?, ?, ?, ?, ?)
    ");

    $unitsReturned = 0;

    foreach ($items as $item) {

        $productId = (int) $item['product_id'];
        $variantId = (int) $item['variant_id'];
        $quantity = (int) $item['quantity'];

        // Re-read each time: the same size can appear on more than one line.
        $stockRead->execute([$variantId]);

        $previousStock = (int) $stockRead->fetchColumn();

        $stockUpdate->execute([$quantity, $variantId, $productId]);

        if ($stockUpdate->rowCount() !== 1) {
            throw new RuntimeException(
                'Unable to return stock for '
                . $item['product_name']
                . '.'
            );
        }

        $inventoryLog->execute([
            $productId,
            $variantId,
            $voidedById,
            $saleId,
            $item['color'],
            $item['size'],
            $quantity,
            $previousStock,
            $previousStock + $quantity,
            'Stock returned from voided sale '
                . $sale['transaction_no']
                . ' — '
                . $item['color']
                . ' / '
                . $item['size']
        ]);

        $unitsReturned += $quantity;
    }


    /*
    | Mark the sale as voided. The status condition stops a double void.
    */

    $voidUpdate = $pdo->prepare("
        UPDATE sales
        SET
            status = 'Voided',
            voided_by = ?,
            void_reason = ?,
            voided_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND status = 'Completed'
    ");

    $voidUpdate->execute([
        $voidedById,
        $reason,
        $saleId
    ]);

    if ($voidUpdate->rowCount() !== 1) {
        throw new RuntimeException('This sale has already been voided.');
    }


    $systemLog = $pdo->prepare("
        INSERT INTO system_logs (
            user_id,
            action,
            module,
            record_type,
            record_id,
            details
        )
        VALUES (?, 'VOID_SALE', 'POS', 'Sale', ?, ?)
    ");

    $systemLog->execute([
        $voidedById,
        $saleId,
        'Voided sale '
            . $sale['transaction_no']
            . ' (₱'
            . number_format((float) $sale['total_amount'], 2, '.', '')
            . '). '
            . $unitsReturned
            . ' unit(s) returned to stock. '
            . (
                $requestedByCashier !== ''
                    ? 'Requested by cashier ' . $requestedByCashier . '. '
                    : ''
            )
            . 'Reason: '
            . $reason
    ]);


    $pdo->commit();

    voidSaleRedirect(
        $saleId,
        'success',
        'Sale '
            . $sale['transaction_no']
            . ' was voided. '
            . $unitsReturned
            . ($unitsReturned === 1 ? ' unit was' : ' units were')
            . ' returned to stock.'
    );

} catch (Throwable $error) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    /*
    | Our own plain messages are shown as they are. PDOException is also a
    | RuntimeException, so it must be excluded: a database error is only
    | written to the PHP error log, never shown on screen.
    */

    if (
        $error instanceof RuntimeException
        && !($error instanceof PDOException)
    ) {

        $message = $error->getMessage();

    } else {

        error_log('void_sale.php: ' . $error->getMessage());

        $message = 'Unable to void this sale. Please try again.';
    }

    voidSaleRedirect(
        $saleId,
        'error',
        $message
    );
}
