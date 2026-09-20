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
| HELPERS
|--------------------------------------------------------------------------
*/

function receiptSetting(
    PDO $pdo,
    string $key,
    string $default = ''
): string {

    $statement =
        $pdo->prepare("
            SELECT setting_value
            FROM settings
            WHERE setting_key = ?
            LIMIT 1
        ");


    $statement->execute([
        $key
    ]);


    $value =
        $statement->fetchColumn();


    if (
        $value === false
        ||
        $value === null
    ) {

        return $default;
    }


    return (string) $value;
}


function receiptProductImage(
    string $directory,
    int $productId
): string {

    foreach (
        [
            'jpg',
            'jpeg',
            'png',
            'webp'
        ]
        as $extension
    ) {

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
| SALE ID
|--------------------------------------------------------------------------
*/

$saleId =
    isset(
        $_GET['id']
    )
        ? (int) $_GET['id']
        : 0;


if ($saleId <= 0) {

    http_response_code(
        400
    );

    exit(
        'Invalid receipt.'
    );
}


/*
|--------------------------------------------------------------------------
| SALE
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

    http_response_code(
        404
    );

    exit(
        'Receipt not found.'
    );
}


/*
|--------------------------------------------------------------------------
| ITEMS
|--------------------------------------------------------------------------
*/

$itemStatement =
    $pdo->prepare("
        SELECT
            id,
            sale_id,
            product_id,
            variant_id,
            color,
            size,
            variant_sku,
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
| BUSINESS / RECEIPT SETTINGS
|--------------------------------------------------------------------------
*/

$businessName =
    trim(
        receiptSetting(
            $pdo,
            'business_name',
            'Underground Apparel'
        )
    );


if ($businessName === '') {
    $businessName = 'Underground Apparel';
}


$businessAddress =
    trim(
        receiptSetting(
            $pdo,
            'business_address'
        )
    );


$businessContact =
    trim(
        receiptSetting(
            $pdo,
            'business_contact'
        )
    );


$businessEmail =
    trim(
        receiptSetting(
            $pdo,
            'business_email'
        )
    );


$receiptFooterMessage =
    trim(
        receiptSetting(
            $pdo,
            'receipt_footer_message',
            'Thank you for shopping with us.'
        )
    );


if ($receiptFooterMessage === '') {
    $receiptFooterMessage = 'Thank you for shopping with us.';
}


$receiptShowCashier =
    receiptSetting(
        $pdo,
        'receipt_show_cashier',
        '1'
    ) !== '0';


$businessMeta = [];


if ($businessAddress !== '') {
    $businessMeta[] = $businessAddress;
}


if ($businessContact !== '') {
    $businessMeta[] = $businessContact;
}


if ($businessEmail !== '') {
    $businessMeta[] = $businessEmail;
}


/*
|--------------------------------------------------------------------------
| CASHIER NAME
|--------------------------------------------------------------------------
*/

$cashierNameParts = [];


foreach (
    [
        'first_name',
        'middle_name',
        'last_name',
        'suffix'
    ]
    as $field
) {

    $value =
        trim(
            (string) (
                $sale[
                    $field
                ]
                ?? ''
            )
        );


    if ($value !== '') {
        $cashierNameParts[] = $value;
    }
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
            $sale[
                'username'
            ]
            ?? 'Cashier'
        );
}


/*
|--------------------------------------------------------------------------
| DATE / MANILA TIME
|--------------------------------------------------------------------------
*/

try {

    $saleDateTime =
        new DateTime(
            (string) $sale[
                'created_at'
            ],
            new DateTimeZone(
                'UTC'
            )
        );


    $saleDateTime->setTimezone(
        new DateTimeZone(
            'Asia/Manila'
        )
    );


    $displaySaleDate =
        $saleDateTime->format(
            'M d, Y h:i A'
        );


} catch (
    Throwable $error
) {

    $displaySaleDate =
        (string) $sale[
            'created_at'
        ];
}


/*
|--------------------------------------------------------------------------
| VALUES
|--------------------------------------------------------------------------
*/

$subtotal =
    (float) $sale[
        'subtotal'
    ];


$taxRate =
    (float) $sale[
        'tax_rate'
    ];


$taxAmount =
    (float) $sale[
        'tax_amount'
    ];


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


/*
|--------------------------------------------------------------------------
| VAT-INCLUSIVE RECEIPT BREAKDOWN
|--------------------------------------------------------------------------
|
| New sales store:
|   subtotal     = gross selling-price total
|   tax_amount   = VAT already included in the amount paid
|   total_amount = subtotal - discount
|
| Older transactions used tax-exclusive totals. We detect those so
| historical receipts are not relabeled incorrectly.
|--------------------------------------------------------------------------
*/

$calculatedDiscountAmount =
    $discountAmount > 0
        ? $discountAmount
        : round(
            $subtotal *
            (
                $discountPercent /
                100
            ),
            2
        );


$inclusiveExpectedTotal =
    round(
        max(
            $subtotal -
            $calculatedDiscountAmount,
            0
        ),
        2
    );


$exclusiveExpectedTotal =
    round(
        max(
            $subtotal +
            $taxAmount -
            $calculatedDiscountAmount,
            0
        ),
        2
    );


$isVatInclusiveSale =
    abs(
        $total -
        $inclusiveExpectedTotal
    )
    <=
    abs(
        $total -
        $exclusiveExpectedTotal
    );


$vatableSales =
    $isVatInclusiveSale
        ? round(
            max(
                $total -
                $taxAmount,
                0
            ),
            2
        )
        : $subtotal;


$itemCount =
    0;


foreach (
    $items
    as $item
) {

    $itemCount +=
        (int) $item[
            'quantity'
        ];
}


$productImageDirectory =
    __DIR__
    . '/../assets/images/products';

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



    <main class="receipt-paper">


        <!-- HEADER -->

        <header class="receipt-header">


            <div class="receipt-logo-wrap">

                <img
                    src="/assets/images/UA_logo.jpg"
                    alt="<?= htmlspecialchars(
                        $businessName
                    ) ?> Logo"
                    class="receipt-logo-image"
                >

            </div>


            <div>

                <h1>
                    <?= htmlspecialchars(
                        $businessName
                    ) ?>
                </h1>

                <p>
                    Point of Sale Receipt
                </p>

                <?php if (!empty($businessMeta)): ?>

                    <p>
                        <?= htmlspecialchars(
                            implode(
                                ' · ',
                                $businessMeta
                            )
                        ) ?>
                    </p>

                <?php endif; ?>

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


            <?php if ($receiptShowCashier): ?>

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

            <?php endif; ?>


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
                    receiptProductImage(
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
                                (string) (
                                    $item[
                                        'barcode'
                                    ]
                                    ?? ''
                                )
                            ) ?>

                            <?php if (
                                !empty(
                                    $item[
                                        'size'
                                    ]
                                )
                            ): ?>

                                ·
                                <?= htmlspecialchars(
                                    $item[
                                        'color'
                                    ]
                                    ?: 'Default'
                                ) ?>
                                /
                                <?= htmlspecialchars(
                                    $item[
                                        'size'
                                    ]
                                ) ?>

                            <?php endif; ?>

                            <?php if (
                                !empty(
                                    $item[
                                        'variant_sku'
                                    ]
                                )
                            ): ?>

                                ·
                                <?= htmlspecialchars(
                                    $item[
                                        'variant_sku'
                                    ]
                                ) ?>

                            <?php endif; ?>

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


            <?php if ($isVatInclusiveSale): ?>


                <div class="receipt-total-row">

                    <span>
                        Gross Sales
                    </span>

                    <strong>
                        ₱<?= number_format(
                            $subtotal,
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
                                $calculatedDiscountAmount,
                                2
                            ) ?>
                        </strong>

                    </div>

                <?php endif; ?>


                <div class="receipt-total-row">

                    <span>
                        VATable Sales
                    </span>

                    <strong>
                        ₱<?= number_format(
                            $vatableSales,
                            2
                        ) ?>
                    </strong>

                </div>


                <div class="receipt-total-row">

                    <span>
                        VAT Included
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


            <?php else: ?>


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
                                $calculatedDiscountAmount,
                                2
                            ) ?>
                        </strong>

                    </div>

                <?php endif; ?>


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
                    $discountCustomerName !== ''
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
                <?= htmlspecialchars(
                    $receiptFooterMessage
                ) ?>
            </strong>

            <p>
                <?= htmlspecialchars(
                    $businessName
                ) ?>
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
