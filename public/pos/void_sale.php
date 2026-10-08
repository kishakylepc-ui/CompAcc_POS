<?php

declare(strict_types=1);

require_once __DIR__
    . '/../../app/middleware/role.php';

requireRole([
    'Admin',
    'Manager'
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
*/

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

if (strlen($reason) > 500) {
    voidSaleRedirect(
        $saleId,
        'error',
        'Keep the void reason under 500 characters.'
    );
}


try {

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
            $_SESSION['user_id'],
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
        $_SESSION['user_id'],
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
        $_SESSION['user_id'],
        $saleId,
        'Voided sale '
            . $sale['transaction_no']
            . ' (₱'
            . number_format((float) $sale['total_amount'], 2, '.', '')
            . '). '
            . $unitsReturned
            . ' unit(s) returned to stock. Reason: '
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

    voidSaleRedirect(
        $saleId,
        'error',
        'Unable to void this sale: ' . $error->getMessage()
    );
}
