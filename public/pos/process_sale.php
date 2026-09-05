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


header('Content-Type: application/json');


/*
|--------------------------------------------------------------------------
| RESPONSE HELPER
|--------------------------------------------------------------------------
*/

function jsonResponse(
    int $status,
    bool $success,
    string $message,
    array $extra = []
): never {

    http_response_code($status);

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message
            ],
            $extra
        )
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| METHOD
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD']
    !== 'POST'
) {

    jsonResponse(
        405,
        false,
        'Method not allowed.'
    );
}


/*
|--------------------------------------------------------------------------
| READ JSON
|--------------------------------------------------------------------------
*/

$input =
    json_decode(
        file_get_contents(
            'php://input'
        ),
        true
    );


if (!is_array($input)) {

    jsonResponse(
        400,
        false,
        'Invalid request data.'
    );
}


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

$csrfToken =
    (string) (
        $input['csrf_token']
        ?? ''
    );


if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals(
        $_SESSION['csrf_token'],
        $csrfToken
    )
) {

    jsonResponse(
        419,
        false,
        'Your request expired. Refresh the POS and try again.'
    );
}


/*
|--------------------------------------------------------------------------
| CART
|--------------------------------------------------------------------------
*/

$cart =
    $input['cart']
    ?? [];


if (
    !is_array($cart) ||
    empty($cart)
) {

    jsonResponse(
        422,
        false,
        'The sale has no products.'
    );
}


/*
|--------------------------------------------------------------------------
| DISCOUNT
|--------------------------------------------------------------------------
*/

$discountType =
    trim(
        (string) (
            $input['discount_type']
            ?? 'None'
        )
    );


$allowedDiscounts = [
    'None',
    'PWD',
    'Senior'
];


if (
    !in_array(
        $discountType,
        $allowedDiscounts,
        true
    )
) {

    jsonResponse(
        422,
        false,
        'Invalid discount type.'
    );
}


$discountPercent =
    (
        $discountType === 'PWD' ||
        $discountType === 'Senior'
    )
        ? 20.0
        : 0.0;


$discountCustomerName =
    trim(
        (string) (
            $input['customer_name']
            ?? ''
        )
    );


$discountIdNumber =
    trim(
        (string) (
            $input['customer_id']
            ?? ''
        )
    );


if (
    $discountPercent > 0 &&
    (
        $discountCustomerName === '' ||
        $discountIdNumber === ''
    )
) {

    jsonResponse(
        422,
        false,
        'Customer name and ID number are required for PWD or Senior discounts.'
    );
}


/*
|--------------------------------------------------------------------------
| PAYMENT
|--------------------------------------------------------------------------
*/

$paymentMethod =
    trim(
        (string) (
            $input['payment_method']
            ?? ''
        )
    );


$allowedPayments = [
    'Cash',
    'GCash',
    'Maya',
    'MariBank'
];


if (
    !in_array(
        $paymentMethod,
        $allowedPayments,
        true
    )
) {

    jsonResponse(
        422,
        false,
        'Invalid payment method.'
    );
}


$paymentReference =
    trim(
        (string) (
            $input['payment_reference']
            ?? ''
        )
    );


if (
    $paymentMethod !== 'Cash' &&
    $paymentReference === ''
) {

    jsonResponse(
        422,
        false,
        'A payment reference number is required for cashless payments.'
    );
}


/*
|--------------------------------------------------------------------------
| CURRENT VAT
|--------------------------------------------------------------------------
*/

$taxRate =
    12.0;


$taxStatement =
    $pdo->prepare("
        SELECT setting_value
        FROM settings
        WHERE setting_key = ?
        LIMIT 1
    ");


$taxStatement->execute([
    'default_tax_rate'
]);


$taxValue =
    $taxStatement->fetchColumn();


$allowedTaxRates = [
    12.0,
    16.0,
    20.0
];


if (is_numeric($taxValue)) {

    $candidate =
        (float) $taxValue;


    if (
        in_array(
            $candidate,
            $allowedTaxRates,
            true
        )
    ) {

        $taxRate =
            $candidate;
    }
}


/*
|--------------------------------------------------------------------------
| CASHIER
|--------------------------------------------------------------------------
*/

$cashierId =
    (int) (
        $_SESSION['user_id']
        ?? 0
    );


if ($cashierId <= 0) {

    jsonResponse(
        401,
        false,
        'Your login session is invalid.'
    );
}


/*
|--------------------------------------------------------------------------
| START DATABASE TRANSACTION
|--------------------------------------------------------------------------
*/

try {

    $pdo->beginTransaction();


    /*
    |--------------------------------------------------------------------------
    | VALIDATE CART FROM DATABASE
    |--------------------------------------------------------------------------
    */

    $validatedItems = [];

    $subtotal =
        0.0;


    foreach ($cart as $cartItem) {

        if (!is_array($cartItem)) {

            throw new RuntimeException(
                'Invalid cart item.'
            );
        }


        $productId =
            (int) (
                $cartItem['id']
                ?? 0
            );


        $quantity =
            (int) (
                $cartItem['quantity']
                ?? 0
            );


        if (
            $productId <= 0 ||
            $quantity <= 0
        ) {

            throw new RuntimeException(
                'Invalid product quantity.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | GET PRODUCT
        |--------------------------------------------------------------------------
        */

        $productStatement =
            $pdo->prepare("
                SELECT
                    id,
                    barcode,
                    product_name,
                    selling_price,
                    stock_quantity,
                    status
                FROM products
                WHERE id = ?
                LIMIT 1
            ");


        $productStatement->execute([
            $productId
        ]);


        $product =
            $productStatement->fetch();


        if (!$product) {

            throw new RuntimeException(
                'A product in the cart no longer exists.'
            );
        }


        if (
            $product['status']
            !== 'Active'
        ) {

            throw new RuntimeException(
                $product['product_name']
                . ' is no longer active.'
            );
        }


        $currentStock =
            (int) $product[
                'stock_quantity'
            ];


        if (
            $quantity >
            $currentStock
        ) {

            throw new RuntimeException(
                'Not enough stock for '
                . $product['product_name']
                . '. Available stock: '
                . $currentStock
                . '.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | PRICE FROM DATABASE
        |--------------------------------------------------------------------------
        */

        $unitPrice =
            round(
                (float) $product[
                    'selling_price'
                ],
                2
            );


        $lineTotal =
            round(
                $unitPrice *
                $quantity,
                2
            );


        $subtotal +=
            $lineTotal;


        $validatedItems[] = [

            'id' =>
                (int) $product['id'],

            'barcode' =>
                (string) (
                    $product['barcode']
                    ?? ''
                ),

            'product_name' =>
                (string) $product[
                    'product_name'
                ],

            'quantity' =>
                $quantity,

            'unit_price' =>
                $unitPrice,

            'line_total' =>
                $lineTotal,

            'previous_stock' =>
                $currentStock,

            'new_stock' =>
                $currentStock -
                $quantity

        ];
    }


    $subtotal =
        round(
            $subtotal,
            2
        );


    /*
    |--------------------------------------------------------------------------
    | CALCULATE TAX
    |--------------------------------------------------------------------------
    */

    $taxAmount =
        round(
            $subtotal *
            (
                $taxRate /
                100
            ),
            2
        );


    /*
    |--------------------------------------------------------------------------
    | CALCULATE DISCOUNT
    |--------------------------------------------------------------------------
    */

    $discountAmount =
        round(
            $subtotal *
            (
                $discountPercent /
                100
            ),
            2
        );


    /*
    |--------------------------------------------------------------------------
    | TOTAL
    |--------------------------------------------------------------------------
    */

    $totalAmount =
        round(
            max(
                $subtotal +
                $taxAmount -
                $discountAmount,
                0
            ),
            2
        );


    /*
    |--------------------------------------------------------------------------
    | PAYMENT VALUES
    |--------------------------------------------------------------------------
    */

    $amountTendered =
        0.0;

    $changeAmount =
        0.0;


    if (
        $paymentMethod === 'Cash'
    ) {

        $amountTendered =
            round(
                (float) (
                    $input[
                        'amount_tendered'
                    ]
                    ?? 0
                ),
                2
            );


        if (
            $amountTendered <
            $totalAmount
        ) {

            throw new RuntimeException(
                'The amount tendered is not enough.'
            );
        }


        $changeAmount =
            round(
                $amountTendered -
                $totalAmount,
                2
            );


        $paymentReference =
            '';
    }


    /*
    |--------------------------------------------------------------------------
    | TRANSACTION NUMBER
    |--------------------------------------------------------------------------
    */

    $transactionNo =
        'UA-'
        . date('Ymd-His')
        . '-'
        . strtoupper(
            bin2hex(
                random_bytes(2)
            )
        );


    /*
    |--------------------------------------------------------------------------
    | INSERT SALE
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    | These are the REAL column names in your CompAcc database:
    |
    | discount_percent
    | discount_customer_name
    | discount_id_number
    | total_amount
    |
    */

    $saleStatement =
        $pdo->prepare("
            INSERT INTO sales (
                transaction_no,
                cashier_id,
                subtotal,
                tax_rate,
                tax_amount,
                discount_type,
                discount_percent,
                discount_customer_name,
                discount_id_number,
                total_amount,
                payment_method,
                payment_reference,
                amount_tendered,
                change_amount,
                status
            )
            VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?
            )
        ");


    $saleStatement->execute([

        $transactionNo,

        $cashierId,

        $subtotal,

        $taxRate,

        $taxAmount,

        $discountType,

        $discountPercent,

        $discountCustomerName !== ''
            ? $discountCustomerName
            : null,

        $discountIdNumber !== ''
            ? $discountIdNumber
            : null,

        $totalAmount,

        $paymentMethod,

        $paymentReference !== ''
            ? $paymentReference
            : null,

        $amountTendered,

        $changeAmount,

        'Completed'

    ]);


    $saleId =
        (int) $pdo->lastInsertId();


    /*
    |--------------------------------------------------------------------------
    | INSERT SALE ITEMS
    |--------------------------------------------------------------------------
    */

    $saleItemStatement =
        $pdo->prepare("
            INSERT INTO sale_items (
                sale_id,
                product_id,
                barcode,
                product_name,
                quantity,
                unit_price,
                line_total
            )
            VALUES (
                ?, ?, ?, ?, ?, ?, ?
            )
        ");


    /*
    |--------------------------------------------------------------------------
    | UPDATE STOCK
    |--------------------------------------------------------------------------
    */

    $stockStatement =
        $pdo->prepare("
            UPDATE products
            SET
                stock_quantity = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
              AND stock_quantity >= ?
        ");


    /*
    |--------------------------------------------------------------------------
    | INVENTORY LOG
    |--------------------------------------------------------------------------
    */

    $inventoryLogStatement =
        $pdo->prepare("
            INSERT INTO inventory_logs (
                product_id,
                user_id,
                supplier_id,
                sale_id,
                action,
                quantity_change,
                previous_stock,
                new_stock,
                notes
            )
            VALUES (
                ?, ?, NULL, ?,
                ?, ?, ?, ?, ?
            )
        ");


    foreach (
        $validatedItems
        as $item
    ) {

        /*
        |--------------------------------------------------------------------------
        | SALE ITEM
        |--------------------------------------------------------------------------
        */

        $saleItemStatement->execute([

            $saleId,

            $item['id'],

            $item['barcode'],

            $item['product_name'],

            $item['quantity'],

            $item['unit_price'],

            $item['line_total']

        ]);


        /*
        |--------------------------------------------------------------------------
        | DEDUCT INVENTORY
        |--------------------------------------------------------------------------
        */

        $stockStatement->execute([

            $item['new_stock'],

            $item['id'],

            $item['quantity']

        ]);


        if (
            $stockStatement->rowCount()
            !== 1
        ) {

            throw new RuntimeException(
                'Stock changed while processing '
                . $item['product_name']
                . '. Please try again.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | INVENTORY LOG
        |--------------------------------------------------------------------------
        */

        $inventoryLogStatement->execute([

            $item['id'],

            $cashierId,

            $saleId,

            'Sale',

            -$item['quantity'],

            $item['previous_stock'],

            $item['new_stock'],

            'Stock deducted from POS sale '
            . $transactionNo

        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | SYSTEM LOG
    |--------------------------------------------------------------------------
    */

    $systemLogStatement =
        $pdo->prepare("
            INSERT INTO system_logs (
                user_id,
                action,
                module,
                details
            )
            VALUES (?, ?, ?, ?)
        ");


    $systemLogStatement->execute([

        $cashierId,

        'COMPLETE_SALE',

        'POS',

        'Completed sale '
        . $transactionNo
        . ' with total ₱'
        . number_format(
            $totalAmount,
            2,
            '.',
            ''
        )

    ]);


    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    $pdo->commit();


    jsonResponse(
        200,
        true,
        'Sale completed successfully.',
        [

            'sale_id' =>
                $saleId,

            'transaction_no' =>
                $transactionNo,

            'total' =>
                $totalAmount,

            'receipt_url' =>
                '/pos/receipt.php?id='
                . $saleId

        ]
    );


} catch (Throwable $error) {

    /*
    |--------------------------------------------------------------------------
    | ROLLBACK EVERYTHING
    |--------------------------------------------------------------------------
    */

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }


    jsonResponse(
        422,
        false,
        $error->getMessage()
    );
}