<?php

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
| SALE ID
|--------------------------------------------------------------------------
*/

$saleId =
    isset($_GET['id'])
        ? (int) $_GET['id']
        : 0;


if ($saleId <= 0) {

    http_response_code(400);

    exit(
        'Invalid receipt.'
    );
}


/*
|--------------------------------------------------------------------------
| LOAD SALE
|--------------------------------------------------------------------------
*/

$saleStatement =
    $pdo->prepare("
        SELECT
            s.*,

            u.username,
            u.first_name,
            u.middle_name,
            u.last_name,
            u.suffix

        FROM sales s

        LEFT JOIN users u
            ON u.id = s.cashier_id

        WHERE s.id = ?

        LIMIT 1
    ");


$saleStatement->execute([
    $saleId
]);


$sale =
    $saleStatement->fetch();


if (!$sale) {

    http_response_code(404);

    exit(
        'Receipt not found.'
    );
}


/*
|--------------------------------------------------------------------------
| LOAD SALE ITEMS
|--------------------------------------------------------------------------
*/

$itemStatement =
    $pdo->prepare("
        SELECT
            id,
            sale_id,
            product_id,
            barcode,
            product_name,
            quantity,
            unit_price,
            line_total
        FROM sale_items
        WHERE sale_id = ?
        ORDER BY id ASC
    ");


$itemStatement->execute([
    $saleId
]);


$items =
    $itemStatement->fetchAll();


/*
|--------------------------------------------------------------------------
| CASHIER NAME
|--------------------------------------------------------------------------
*/

$cashierNameParts = [];


$firstName =
    trim(
        (string) (
            $sale['first_name']
            ?? ''
        )
    );


$middleName =
    trim(
        (string) (
            $sale['middle_name']
            ?? ''
        )
    );


$lastName =
    trim(
        (string) (
            $sale['last_name']
            ?? ''
        )
    );


$suffix =
    trim(
        (string) (
            $sale['suffix']
            ?? ''
        )
    );


if ($firstName !== '') {

    $cashierNameParts[] =
        $firstName;
}


if ($middleName !== '') {

    $cashierNameParts[] =
        $middleName;
}


if ($lastName !== '') {

    $cashierNameParts[] =
        $lastName;
}


if ($suffix !== '') {

    $cashierNameParts[] =
        $suffix;
}


$cashierName =
    trim(
        implode(
            ' ',
            $cashierNameParts
        )
    );


if ($cashierName === '') {

    $cashierName =
        (string) (
            $sale['username']
            ?? 'Cashier'
        );
}


/*
|--------------------------------------------------------------------------
| RECEIPT DATE / MANILA TIME
|--------------------------------------------------------------------------
|
| SQLite CURRENT_TIMESTAMP is UTC.
|--------------------------------------------------------------------------
*/

$localTimezone =
    new DateTimeZone(
        'Asia/Manila'
    );


$utcTimezone =
    new DateTimeZone(
        'UTC'
    );


try {

    $saleDateTime =
        new DateTime(
            (string) $sale['created_at'],
            $utcTimezone
        );


    $saleDateTime->setTimezone(
        $localTimezone
    );


    $displaySaleDate =
        $saleDateTime->format(
            'M d, Y h:i A'
        );


} catch (Throwable $error) {

    $displaySaleDate =
        (string) $sale['created_at'];
}


/*
|--------------------------------------------------------------------------
| PRODUCT IMAGE DIRECTORY
|--------------------------------------------------------------------------
*/

$productImageDirectory =
    __DIR__
    . '/../assets/images/products';


/*
|--------------------------------------------------------------------------
| PRODUCT PHOTO
|--------------------------------------------------------------------------
*/

function getReceiptProductImage(
    string $directory,
    int $productId
): string {

    $extensions = [
        'jpg',
        'jpeg',
        'png',
        'webp'
    ];


    foreach ($extensions as $extension) {

        $file =
            $directory
            . DIRECTORY_SEPARATOR
            . 'product-'
            . $productId
            . '.'
            . $extension;


        if (is_file($file)) {

            return
                '/assets/images/products/product-'
                . $productId
                . '.'
                . $extension;
        }
    }


    return '';
}


/*
|--------------------------------------------------------------------------
| VALUES
|--------------------------------------------------------------------------
*/

$subtotal =
    (float) $sale['subtotal'];


$taxRate =
    (float) $sale['tax_rate'];


$taxAmount =
    (float) $sale['tax_amount'];


$discountType =
    (string) $sale[
        'discount_type'
    ];


$discountPercent =
    (float) $sale[
        'discount_percent'
    ];


$discountAmount =
    (float) $sale[
        'discount_amount'
    ];


$total =
    (float) $sale[
        'total_amount'
    ];


$paymentMethod =
    (string) $sale[
        'payment_method'
    ];


$paymentReference =
    trim(
        (string) (
            $sale[
                'payment_reference'
            ]
            ?? ''
        )
    );


$amountTendered =
    (float) $sale[
        'amount_tendered'
    ];


$changeAmount =
    (float) $sale[
        'change_amount'
    ];


$discountCustomerName =
    trim(
        (string) (
            $sale[
                'discount_customer_name'
            ]
            ?? ''
        )
    );


$discountIdNumber =
    trim(
        (string) (
            $sale[
                'discount_id_number'
            ]
            ?? ''
        )
    );


$status =
    (string) $sale[
        'status'
    ];


$itemCount =
    0;


foreach ($items as $item) {

    $itemCount +=
        (int) $item[
            'quantity'
        ];
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Receipt -
        <?= htmlspecialchars(
            $sale[
                'transaction_no'
            ]
        ) ?>
    </title>


    <link
        rel="stylesheet"
        href="/assets/css/receipt.css"
    >


    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,400,0,0"
        rel="stylesheet"
    >

</head>


<body>


<div class="receipt-page">


    <!-- =====================================================
         ACTIONS
    ====================================================== -->

    <div class="receipt-actions no-print">

        <a
            href="/pos/"
            class="receipt-action secondary"
        >

            <span class="material-symbols-rounded">
                arrow_back
            </span>

            Back to POS

        </a>


        <button
            type="button"
            class="receipt-action primary"
            onclick="window.print()"
        >

            <span class="material-symbols-rounded">
                print
            </span>

            Print Receipt

        </button>

    </div>



    <!-- =====================================================
         RECEIPT
    ====================================================== -->

    <main class="receipt-paper">


        <!-- HEADER -->

        <header class="receipt-header">


            <div class="receipt-logo-wrap">

                <img
                    src="/assets/images/UA_logo.jpg"
                    alt="Underground Apparel Logo"
                    class="receipt-logo-image"
                >

            </div>


            <div>

                <h1>
                    Underground Apparel
                </h1>

                <p>
                    Point of Sale Receipt
                </p>

            </div>

        </header>



        <!-- TRANSACTION INFO -->

        <section class="receipt-info">


            <div class="receipt-info-row">

                <span>
                    Transaction No.
                </span>

                <strong>
                    <?= htmlspecialchars(
                        $sale[
                            'transaction_no'
                        ]
                    ) ?>
                </strong>

            </div>


            <div class="receipt-info-row">

                <span>
                    Date
                </span>

                <strong>
                    <?= htmlspecialchars(
                        $displaySaleDate
                    ) ?>
                </strong>

            </div>


            <div class="receipt-info-row">

                <span>
                    Cashier
                </span>

                <strong>
                    <?= htmlspecialchars(
                        $cashierName
                    ) ?>
                </strong>

            </div>


            <div class="receipt-info-row">

                <span>
                    Items
                </span>

                <strong>
                    <?= $itemCount ?>
                </strong>

            </div>


            <div class="receipt-info-row">

                <span>
                    Status
                </span>

                <strong class="receipt-status">
                    <?= htmlspecialchars(
                        $status
                    ) ?>
                </strong>

            </div>

        </section>



        <!-- PRODUCTS -->

        <section class="receipt-products">


            <div class="receipt-section-heading">

                <span>
                    ITEMS PURCHASED
                </span>

            </div>


            <?php foreach (
                $items
                as $item
            ): ?>


                <?php

                $productId =
                    (int) $item[
                        'product_id'
                    ];


                $productPhoto =
                    getReceiptProductImage(
                        $productImageDirectory,
                        $productId
                    );

                ?>


                <div class="receipt-product">


                    <div class="receipt-product-photo">


                        <?php if (
                            $productPhoto !== ''
                        ): ?>

                            <img
                                src="<?= htmlspecialchars(
                                    $productPhoto
                                ) ?>"
                                alt="<?= htmlspecialchars(
                                    $item[
                                        'product_name'
                                    ]
                                ) ?>"
                            >

                        <?php else: ?>

                            <span class="material-symbols-rounded">
                                apparel
                            </span>

                        <?php endif; ?>


                    </div>


                    <div class="receipt-product-info">

                        <strong>

                            <?= htmlspecialchars(
                                $item[
                                    'product_name'
                                ]
                            ) ?>

                        </strong>


                        <small>

                            <?= htmlspecialchars(
                                $item[
                                    'barcode'
                                ]
                            ) ?>

                        </small>


                        <div class="receipt-product-calculation">

                            <?= (int) $item[
                                'quantity'
                            ] ?>

                            ×

                            ₱<?= number_format(
                                (float) $item[
                                    'unit_price'
                                ],
                                2
                            ) ?>

                        </div>

                    </div>


                    <div class="receipt-product-total">

                        ₱<?= number_format(
                            (float) $item[
                                'line_total'
                            ],
                            2
                        ) ?>

                    </div>

                </div>


            <?php endforeach; ?>


        </section>



        <!-- TOTALS -->

        <section class="receipt-totals">


            <div class="receipt-total-row">

                <span>
                    Subtotal
                </span>

                <strong>

                    ₱<?= number_format(
                        $subtotal,
                        2
                    ) ?>

                </strong>

            </div>


            <div class="receipt-total-row">

                <span>

                    VAT
                    (<?= number_format(
                        $taxRate,
                        0
                    ) ?>%)

                </span>

                <strong>

                    ₱<?= number_format(
                        $taxAmount,
                        2
                    ) ?>

                </strong>

            </div>


            <?php if (
                $discountPercent > 0
            ): ?>

                <div class="receipt-total-row discount">

                    <span>

                        <?= htmlspecialchars(
                            $discountType ===
                            'Senior'
                                ? 'Senior Citizen'
                                : $discountType
                        ) ?>

                        Discount
                        (<?= number_format(
                            $discountPercent,
                            0
                        ) ?>%)

                    </span>

                    <strong>

                        -₱<?= number_format(
                            $discountAmount,
                            2
                        ) ?>

                    </strong>

                </div>

            <?php endif; ?>


            <div class="receipt-grand-total">

                <span>
                    TOTAL
                </span>

                <strong>

                    ₱<?= number_format(
                        $total,
                        2
                    ) ?>

                </strong>

            </div>

        </section>



        <!-- DISCOUNT INFORMATION -->

        <?php if (
            $discountPercent > 0
        ): ?>


            <section class="receipt-detail-section">

                <div class="receipt-section-heading">

                    <span>
                        DISCOUNT INFORMATION
                    </span>

                </div>


                <div class="receipt-info-row">

                    <span>
                        Type
                    </span>

                    <strong>

                        <?= htmlspecialchars(
                            $discountType ===
                            'Senior'
                                ? 'Senior Citizen'
                                : $discountType
                        ) ?>

                    </strong>

                </div>


                <?php if (
                    $discountCustomerName !==
                    ''
                ): ?>

                    <div class="receipt-info-row">

                        <span>
                            Customer
                        </span>

                        <strong>

                            <?= htmlspecialchars(
                                $discountCustomerName
                            ) ?>

                        </strong>

                    </div>

                <?php endif; ?>


                <?php if (
                    $discountIdNumber !== ''
                ): ?>

                    <div class="receipt-info-row">

                        <span>
                            ID Number
                        </span>

                        <strong>

                            <?= htmlspecialchars(
                                $discountIdNumber
                            ) ?>

                        </strong>

                    </div>

                <?php endif; ?>


            </section>


        <?php endif; ?>



        <!-- PAYMENT -->

        <section class="receipt-detail-section">


            <div class="receipt-section-heading">

                <span>
                    PAYMENT
                </span>

            </div>


            <div class="receipt-info-row">

                <span>
                    Method
                </span>

                <strong>

                    <?= htmlspecialchars(
                        $paymentMethod
                    ) ?>

                </strong>

            </div>


            <?php if (
                $paymentMethod === 'Cash'
            ): ?>


                <div class="receipt-info-row">

                    <span>
                        Amount Tendered
                    </span>

                    <strong>

                        ₱<?= number_format(
                            $amountTendered,
                            2
                        ) ?>

                    </strong>

                </div>


                <div class="receipt-info-row">

                    <span>
                        Change
                    </span>

                    <strong>

                        ₱<?= number_format(
                            $changeAmount,
                            2
                        ) ?>

                    </strong>

                </div>


            <?php elseif (
                $paymentReference !== ''
            ): ?>


                <div class="receipt-info-row">

                    <span>
                        Reference No.
                    </span>

                    <strong>

                        <?= htmlspecialchars(
                            $paymentReference
                        ) ?>

                    </strong>

                </div>


            <?php endif; ?>


        </section>



        <!-- FOOTER -->

        <footer class="receipt-footer">

            <strong>
                Thank you for shopping with us.
            </strong>

            <p>
                Underground Apparel
            </p>

            <small>

                <?= htmlspecialchars(
                    $sale[
                        'transaction_no'
                    ]
                ) ?>

            </small>

        </footer>

    </main>

</div>


</body>

</html>