<?php

require_once __DIR__ . '/../../app/middleware/role.php';

requireRole([
    'Admin',
    'Manager',
    'Cashier'
]);

require_once __DIR__ . '/../../app/config/database.php';

$pageTitle = 'Point of Sale';
$currentPage = 'pos';

$currentRole = $_SESSION['role'] ?? '';
$isAdmin = $currentRole === 'Admin';


/*
|--------------------------------------------------------------------------
| HELPER - GET SETTING
|--------------------------------------------------------------------------
*/

function getPosSetting(
    PDO $pdo,
    string $key,
    string $default = ''
): string {

    $stmt = $pdo->prepare("
        SELECT setting_value
        FROM settings
        WHERE setting_key = ?
        LIMIT 1
    ");

    $stmt->execute([$key]);

    $value = $stmt->fetchColumn();

    if ($value === false || $value === null) {
        return $default;
    }

    return (string) $value;
}


/*
|--------------------------------------------------------------------------
| PRODUCT PHOTO DIRECTORY
|--------------------------------------------------------------------------
*/

$productImageDirectory =
    __DIR__
    . '/../assets/images/products';


/*
|--------------------------------------------------------------------------
| GET PRODUCT PHOTO
|--------------------------------------------------------------------------
*/

function getPosProductImageUrl(
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
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {

    $_SESSION['csrf_token'] =
        bin2hex(
            random_bytes(32)
        );
}


/*
|--------------------------------------------------------------------------
| VAT
|--------------------------------------------------------------------------
*/

$allowedTaxRates = [
    12.0,
    16.0,
    20.0
];


/*
|--------------------------------------------------------------------------
| ADMIN - UPDATE VAT
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'update_tax_rate'
) {

    if (!$isAdmin) {

        $_SESSION['pos_error'] =
            'Only an Admin can change the VAT rate.';

        header('Location: /pos/');
        exit;
    }


    $submittedToken =
        $_POST['csrf_token'] ?? '';


    if (
        !hash_equals(
            $_SESSION['csrf_token'],
            $submittedToken
        )
    ) {

        $_SESSION['pos_error'] =
            'Invalid request. Please try again.';

        header('Location: /pos/');
        exit;
    }


    $newTaxRate =
        isset($_POST['tax_rate'])
            ? (float) $_POST['tax_rate']
            : 0;


    if (
        !in_array(
            $newTaxRate,
            $allowedTaxRates,
            true
        )
    ) {

        $_SESSION['pos_error'] =
            'Invalid VAT rate selected.';

        header('Location: /pos/');
        exit;
    }


    $check = $pdo->prepare("
        SELECT COUNT(*)
        FROM settings
        WHERE setting_key = ?
    ");

    $check->execute([
        'default_tax_rate'
    ]);


    if ((int) $check->fetchColumn() > 0) {

        $stmt = $pdo->prepare("
            UPDATE settings
            SET setting_value = ?
            WHERE setting_key = ?
        ");

        $stmt->execute([
            (string) $newTaxRate,
            'default_tax_rate'
        ]);

    } else {

        $stmt = $pdo->prepare("
            INSERT INTO settings (
                setting_key,
                setting_value
            )
            VALUES (?, ?)
        ");

        $stmt->execute([
            'default_tax_rate',
            (string) $newTaxRate
        ]);
    }


    $log = $pdo->prepare("
        INSERT INTO system_logs (
            user_id,
            action,
            module,
            details
        )
        VALUES (?, ?, ?, ?)
    ");

    $log->execute([
        $_SESSION['user_id'],
        'UPDATE_TAX_RATE',
        'POS',
        'Default VAT rate changed to '
        . number_format(
            $newTaxRate,
            0
        )
        . '%'
    ]);


    $_SESSION['pos_success'] =
        'VAT rate updated to '
        . number_format(
            $newTaxRate,
            0
        )
        . '%.';


    header('Location: /pos/');
    exit;
}


/*
|--------------------------------------------------------------------------
| FLASH
|--------------------------------------------------------------------------
*/

$posSuccess =
    $_SESSION['pos_success']
    ?? null;


$posError =
    $_SESSION['pos_error']
    ?? null;


unset(
    $_SESSION['pos_success'],
    $_SESSION['pos_error']
);


/*
|--------------------------------------------------------------------------
| LOAD VAT
|--------------------------------------------------------------------------
*/

$configuredTaxRate = 12.0;


$taxSetting =
    getPosSetting(
        $pdo,
        'default_tax_rate',
        '12'
    );


if (is_numeric($taxSetting)) {

    $candidate =
        (float) $taxSetting;


    if (
        in_array(
            $candidate,
            $allowedTaxRates,
            true
        )
    ) {

        $configuredTaxRate =
            $candidate;
    }
}


/*
|--------------------------------------------------------------------------
| QR SETTINGS
|--------------------------------------------------------------------------
*/

$gcashQr =
    trim(
        getPosSetting(
            $pdo,
            'gcash_qr'
        )
    );


$mayaQr =
    trim(
        getPosSetting(
            $pdo,
            'maya_qr'
        )
    );


$maribankQr =
    trim(
        getPosSetting(
            $pdo,
            'maribank_qr'
        )
    );


/*
|--------------------------------------------------------------------------
| PRODUCTS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        barcode,
        product_name,
        selling_price,
        stock_quantity,
        expiration_date
    FROM products
    WHERE status = 'Active'
    ORDER BY product_name ASC
");


$stmt->execute();


$products =
    $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| LAYOUT
|--------------------------------------------------------------------------
*/

require_once __DIR__
    . '/../../app/views/partials/header.php';


require_once __DIR__
    . '/../../app/views/partials/sidebar.php';

?>

<link
    rel="stylesheet"
    href="/assets/css/pos.css"
>

<link
    rel="stylesheet"
    href="/assets/css/pos-confirm.css"
>


<div class="pos-page">


    <!-- =====================================================
         SIMPLIFIED HEADER
    ====================================================== -->

    <div class="pos-header">

        <div class="pos-eyebrow">
            SALES TERMINAL
        </div>

    </div>


    <!-- =====================================================
         FLASH
    ====================================================== -->

    <?php if ($posSuccess): ?>

        <div class="pos-alert success">

            <span class="material-symbols-rounded">
                check_circle
            </span>

            <?= htmlspecialchars(
                $posSuccess
            ) ?>

        </div>

    <?php endif; ?>


    <?php if ($posError): ?>

        <div class="pos-alert error">

            <span class="material-symbols-rounded">
                error
            </span>

            <?= htmlspecialchars(
                $posError
            ) ?>

        </div>

    <?php endif; ?>



    <!-- =====================================================
         MAIN GRID
    ====================================================== -->

    <div class="pos-grid">


        <!-- =================================================
             LEFT
        ================================================== -->

        <section class="pos-products-panel">


            <!-- SEARCH -->

            <div class="pos-card search-card">


                <div class="card-heading">

                    <div>

                        <h3>
                            Product Search
                        </h3>

                        <p>
                            Enter a barcode manually or search
                            for a product by name.
                        </p>

                    </div>


                    <span class="material-symbols-rounded">
                        manage_search
                    </span>

                </div>


                <div class="product-search">

                    <span class="material-symbols-rounded">
                        search
                    </span>


                    <input
                        type="text"
                        id="productSearch"
                        placeholder="Enter barcode or product name..."
                        autocomplete="off"
                        autofocus
                    >


                    <button
                        type="button"
                        id="clearSearch"
                        title="Clear search"
                    >

                        <span class="material-symbols-rounded">
                            close
                        </span>

                    </button>

                </div>


                <div class="search-help">

                    <span class="material-symbols-rounded">
                        keyboard
                    </span>

                    Type a barcode and press Enter
                    to add the matching product.

                </div>


                <div class="keyboard-shortcuts">

                    <span>
                        <kbd>/</kbd>
                        Search
                    </span>

                    <span>
                        <kbd>Enter</kbd>
                        Add
                    </span>

                    <span>
                        <kbd>+</kbd>
                        Quantity +
                    </span>

                    <span>
                        <kbd>-</kbd>
                        Quantity -
                    </span>

                    <span>
                        <kbd>Delete</kbd>
                        Remove
                    </span>

                    <span>
                        <kbd>Alt + Enter</kbd>
                        Checkout
                    </span>

                </div>

            </div>



            <!-- PRODUCTS -->

            <div class="pos-card products-card">


                <div class="products-card-header">

                    <div>

                        <h3>
                            Products
                        </h3>

                        <p id="productCount">

                            <?= count($products) ?>

                            available

                            <?= count($products) === 1
                                ? 'product'
                                : 'products'
                            ?>

                        </p>

                    </div>


                    <div class="stock-legend">

                        <span class="legend-dot"></span>

                        In stock

                    </div>

                </div>


                <div
                    class="product-list"
                    id="productList"
                >


                    <?php if (empty($products)): ?>


                        <div class="products-empty">

                            <span class="material-symbols-rounded">
                                inventory_2
                            </span>

                            <strong>
                                No products available
                            </strong>

                            <p>
                                Add products through Inventory
                                before processing a sale.
                            </p>

                        </div>


                    <?php else: ?>


                        <?php foreach (
                            $products
                            as $product
                        ): ?>


                            <?php

                            $productId =
                                (int) $product['id'];


                            $stock =
                                (int) $product[
                                    'stock_quantity'
                                ];


                            $price =
                                (float) $product[
                                    'selling_price'
                                ];


                            $productPhoto =
                                getPosProductImageUrl(
                                    $productImageDirectory,
                                    $productId
                                );

                            ?>


                            <button
                                type="button"
                                class="product-item"

                                data-id="<?= $productId ?>"

                                data-barcode="<?= htmlspecialchars(
                                    $product['barcode']
                                    ?? ''
                                ) ?>"

                                data-name="<?= htmlspecialchars(
                                    $product[
                                        'product_name'
                                    ]
                                ) ?>"

                                data-price="<?= htmlspecialchars(
                                    (string) $price
                                ) ?>"

                                data-stock="<?= $stock ?>"

                                data-photo="<?= htmlspecialchars(
                                    $productPhoto
                                ) ?>"

                                <?= $stock <= 0
                                    ? 'disabled'
                                    : ''
                                ?>
                            >


                                <!-- PHOTO -->

                                <div class="product-icon product-photo">

                                    <?php if (
                                        $productPhoto !== ''
                                    ): ?>

                                        <img
                                            src="<?= htmlspecialchars(
                                                $productPhoto
                                            ) ?>"
                                            alt="<?= htmlspecialchars(
                                                $product[
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


                                <!-- DETAILS -->

                                <div class="product-details">

                                    <strong>

                                        <?= htmlspecialchars(
                                            $product[
                                                'product_name'
                                            ]
                                        ) ?>

                                    </strong>


                                    <div class="product-meta">

                                        <span>

                                            <?= htmlspecialchars(
                                                $product[
                                                    'barcode'
                                                ]
                                                ?: 'No barcode'
                                            ) ?>

                                        </span>


                                        <span class="meta-separator">
                                            •
                                        </span>


                                        <span>
                                            Stock:
                                            <?= $stock ?>
                                        </span>

                                    </div>

                                </div>


                                <!-- PRICE -->

                                <div class="product-price">

                                    <strong>

                                        ₱<?= number_format(
                                            $price,
                                            2
                                        ) ?>

                                    </strong>


                                    <?php if (
                                        $stock <= 0
                                    ): ?>

                                        <small class="out-of-stock">
                                            Out of stock
                                        </small>

                                    <?php else: ?>

                                        <small>
                                            Add item
                                        </small>

                                    <?php endif; ?>

                                </div>

                            </button>


                        <?php endforeach; ?>


                    <?php endif; ?>


                </div>

            </div>

        </section>



        <!-- =================================================
             RIGHT / CART
        ================================================== -->

        <aside class="pos-cart-panel">


            <div class="pos-card cart-card">


                <!-- CART HEADER -->

                <div class="cart-header">

                    <div>

                        <div class="cart-title-row">

                            <span class="material-symbols-rounded">
                                shopping_cart
                            </span>

                            <h3>
                                Current Sale
                            </h3>

                        </div>


                        <p id="cartItemCount">
                            0 items
                        </p>

                    </div>


                    <button
                        type="button"
                        class="clear-cart-button"
                        id="clearCart"
                    >

                        <span class="material-symbols-rounded">
                            delete_sweep
                        </span>

                        Clear

                    </button>

                </div>



                <!-- CART ITEMS -->

                <div
                    class="cart-items"
                    id="cartItems"
                >


                    <div
                        class="cart-empty"
                        id="cartEmpty"
                    >

                        <span class="material-symbols-rounded">
                            shopping_cart_checkout
                        </span>

                        <strong>
                            Your cart is empty
                        </strong>

                        <p>
                            Search for a product or manually
                            enter its barcode to begin.
                        </p>

                    </div>

                </div>



                <!-- =================================================
                     SALE OPTIONS
                ================================================== -->

                <div class="sale-options">


                    <!-- VAT -->

                    <div class="option-block">


                        <div class="option-heading">

                            <label>
                                Tax Rate
                            </label>


                            <?php if ($isAdmin): ?>

                                <span>
                                    Admin setting
                                </span>

                            <?php else: ?>

                                <span>
                                    Configured by Admin
                                </span>

                            <?php endif; ?>

                        </div>


                        <?php if ($isAdmin): ?>


                            <form
                                action="/pos/"
                                method="POST"
                            >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="update_tax_rate"
                                >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= htmlspecialchars(
                                        $_SESSION[
                                            'csrf_token'
                                        ]
                                    ) ?>"
                                >


                                <div class="tax-options">


                                    <?php foreach (
                                        $allowedTaxRates
                                        as $taxRate
                                    ): ?>


                                        <button
                                            type="submit"
                                            name="tax_rate"
                                            value="<?= $taxRate ?>"

                                            class="tax-button
                                            <?= $configuredTaxRate === $taxRate
                                                ? 'active'
                                                : ''
                                            ?>"
                                        >

                                            <?= number_format(
                                                $taxRate,
                                                0
                                            ) ?>%

                                        </button>


                                    <?php endforeach; ?>


                                </div>

                            </form>


                        <?php else: ?>


                            <div class="configured-tax-display">

                                <span class="material-symbols-rounded">
                                    lock
                                </span>

                                <?= number_format(
                                    $configuredTaxRate,
                                    0
                                ) ?>% VAT

                            </div>


                        <?php endif; ?>


                    </div>



                    <!-- DISCOUNT -->

                    <div class="option-block">


                        <div class="option-heading">

                            <label>
                                Discount
                            </label>

                            <span>
                                Optional
                            </span>

                        </div>


                        <select
                            id="discountType"
                            class="pos-select"
                        >

                            <option value="None">
                                No Discount
                            </option>

                            <option value="PWD">
                                PWD - 20%
                            </option>

                            <option value="Senior">
                                Senior Citizen - 20%
                            </option>

                        </select>

                    </div>



                    <!-- DISCOUNT DETAILS -->

                    <div
                        id="discountDetails"
                        class="discount-details"
                    >


                        <div class="pos-form-group">


                            <div class="option-heading">

                                <label for="discountCustomerName">
                                    Customer Name
                                </label>

                                <span>
                                    Required
                                </span>

                            </div>


                            <input
                                type="text"
                                id="discountCustomerName"
                                class="pos-input"
                                placeholder="Enter customer name"
                                autocomplete="off"
                            >

                        </div>


                        <div class="pos-form-group">


                            <div class="option-heading">

                                <label
                                    for="discountCustomerId"
                                    id="discountIdLabel"
                                >
                                    ID Number
                                </label>

                                <span>
                                    Required
                                </span>

                            </div>


                            <input
                                type="text"
                                id="discountCustomerId"
                                class="pos-input"
                                placeholder="Enter ID number"
                                autocomplete="off"
                            >

                        </div>

                    </div>

                </div>



                <!-- =================================================
                     TOTALS
                ================================================== -->

                <div class="cart-summary">


                    <div class="summary-row">

                        <span>
                            Subtotal
                        </span>

                        <strong id="subtotalValue">
                            ₱0.00
                        </strong>

                    </div>


                    <div class="summary-row">

                        <span id="taxLabel">

                            VAT
                            (<?= number_format(
                                $configuredTaxRate,
                                0
                            ) ?>%)

                        </span>

                        <strong id="taxValue">
                            ₱0.00
                        </strong>

                    </div>


                    <div
                        class="summary-row discount-summary"
                        id="discountRow"
                    >

                        <span id="discountLabel">
                            Discount
                        </span>

                        <strong id="discountValue">
                            -₱0.00
                        </strong>

                    </div>


                    <div class="summary-divider"></div>


                    <div class="summary-total">

                        <div>

                            <span>
                                Total
                            </span>

                            <small>
                                Amount due
                            </small>

                        </div>

                        <strong id="totalValue">
                            ₱0.00
                        </strong>

                    </div>

                </div>



                <!-- =================================================
                     PAYMENT
                ================================================== -->

                <div class="payment-section">


                    <div class="option-heading">

                        <label>
                            Payment Method
                        </label>

                        <span>
                            Select one
                        </span>

                    </div>


                    <div class="payment-methods">


                        <button
                            type="button"
                            class="payment-button active"
                            data-payment="Cash"
                            title="Alt + C"
                        >

                            <span class="material-symbols-rounded">
                                payments
                            </span>

                            Cash

                        </button>


                        <button
                            type="button"
                            class="payment-button"
                            data-payment="GCash"
                            title="Alt + G"
                        >

                            <span class="material-symbols-rounded">
                                qr_code_2
                            </span>

                            GCash

                        </button>


                        <button
                            type="button"
                            class="payment-button"
                            data-payment="Maya"
                            title="Alt + M"
                        >

                            <span class="material-symbols-rounded">
                                qr_code
                            </span>

                            Maya

                        </button>


                        <button
                            type="button"
                            class="payment-button"
                            data-payment="MariBank"
                            title="Alt + B"
                        >

                            <span class="material-symbols-rounded">
                                account_balance
                            </span>

                            MariBank

                        </button>

                    </div>



                    <!-- CASH -->

                    <div
                        class="payment-detail-card"
                        id="cashPaymentDetails"
                    >


                        <div class="payment-detail-header">

                            <div>

                                <strong>
                                    Cash Payment
                                </strong>

                                <small>
                                    Enter the amount received.
                                </small>

                            </div>


                            <span class="material-symbols-rounded">
                                payments
                            </span>

                        </div>


                        <div class="pos-form-group">

                            <label for="cashTendered">
                                Amount Tendered
                            </label>


                            <div class="money-input">

                                <span>
                                    ₱
                                </span>

                                <input
                                    type="number"
                                    id="cashTendered"
                                    min="0"
                                    step="0.01"
                                    placeholder="0.00"
                                    inputmode="decimal"
                                    autocomplete="off"
                                >

                            </div>

                        </div>


                        <div class="cash-change-row">

                            <div>

                                <span>
                                    Change
                                </span>

                                <small id="cashStatus">
                                    Enter amount tendered
                                </small>

                            </div>

                            <strong id="changeValue">
                                ₱0.00
                            </strong>

                        </div>

                    </div>



                    <!-- CASHLESS -->

                    <div
                        class="payment-detail-card"
                        id="cashlessPaymentDetails"
                        hidden
                    >


                        <div class="payment-detail-header">

                            <div>

                                <strong id="cashlessTitle">
                                    GCash Payment
                                </strong>

                                <small>
                                    Display-only QR payment.
                                </small>

                            </div>


                            <span
                                class="material-symbols-rounded"
                                id="cashlessIcon"
                            >
                                qr_code_2
                            </span>

                        </div>


                        <div class="qr-payment-layout">


                            <div class="qr-preview">

                                <img
                                    id="paymentQrImage"
                                    src=""
                                    alt="Payment QR Code"
                                    hidden
                                >


                                <div
                                    class="qr-placeholder"
                                    id="qrPlaceholder"
                                >

                                    <span class="material-symbols-rounded">
                                        qr_code_2
                                    </span>

                                    <strong>
                                        QR not configured
                                    </strong>

                                    <small id="qrPlaceholderText">
                                        Add a QR image in Settings.
                                    </small>

                                </div>

                            </div>


                            <div class="cashless-info">

                                <strong id="cashlessMethodName">
                                    GCash
                                </strong>

                                <p>
                                    The QR is configured by the Admin.
                                    Payment is completed outside the system.
                                </p>

                            </div>

                        </div>


                        <div class="pos-form-group">

                            <label for="paymentReference">
                                Payment Reference Number
                            </label>

                            <input
                                type="text"
                                id="paymentReference"
                                class="pos-input"
                                placeholder="Enter payment reference number"
                                autocomplete="off"
                            >

                            <small class="field-note">
                                Required for cashless payments.
                            </small>

                        </div>

                    </div>

                </div>



                <!-- CHECKOUT -->

                <button
                    type="button"
                    class="checkout-button"
                    id="checkoutButton"
                    disabled
                >

                    <span id="checkoutButtonText">
                        Complete Sale
                    </span>

                    <span class="material-symbols-rounded">
                        arrow_forward
                    </span>

                </button>


                <div class="checkout-note">

                    <span class="material-symbols-rounded">
                        keyboard
                    </span>

                    Alt + Enter to review the sale.

                </div>

            </div>

        </aside>

    </div>

</div>



<!-- =========================================================
     CONFIRM SALE MODAL
========================================================= -->

<div
    class="sale-confirm-modal"
    id="saleConfirmModal"
    hidden
>


    <div
        class="sale-confirm-backdrop"
        id="saleConfirmBackdrop"
    ></div>


    <div
        class="sale-confirm-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="saleConfirmTitle"
    >


        <div class="sale-confirm-header">

            <div>

                <div class="sale-confirm-eyebrow">
                    TRANSACTION REVIEW
                </div>

                <h3 id="saleConfirmTitle">
                    Complete Sale?
                </h3>

                <p>
                    Review the transaction before saving it.
                </p>

            </div>


            <button
                type="button"
                class="sale-confirm-close"
                id="closeConfirmModal"
                aria-label="Close"
            >

                <span class="material-symbols-rounded">
                    close
                </span>

            </button>

        </div>



        <div class="sale-confirm-body">


            <!-- SALE SUMMARY -->

            <div class="sale-confirm-section">


                <div class="sale-confirm-section-title">

                    <span class="material-symbols-rounded">
                        shopping_cart
                    </span>

                    Sale Summary

                </div>


                <div class="sale-confirm-row">

                    <span>
                        Items
                    </span>

                    <strong id="confirmItemCount">
                        0
                    </strong>

                </div>


                <div class="sale-confirm-row">

                    <span>
                        Subtotal
                    </span>

                    <strong id="confirmSubtotal">
                        ₱0.00
                    </strong>

                </div>


                <div class="sale-confirm-row">

                    <span id="confirmTaxLabel">
                        VAT
                    </span>

                    <strong id="confirmTax">
                        ₱0.00
                    </strong>

                </div>


                <div
                    class="sale-confirm-row"
                    id="confirmDiscountRow"
                    hidden
                >

                    <span id="confirmDiscountLabel">
                        Discount
                    </span>

                    <strong
                        class="sale-confirm-discount"
                        id="confirmDiscount"
                    >
                        -₱0.00
                    </strong>

                </div>


                <div class="sale-confirm-total">

                    <span>
                        Total
                    </span>

                    <strong id="confirmTotal">
                        ₱0.00
                    </strong>

                </div>

            </div>



            <!-- DISCOUNT DETAILS -->

            <div
                class="sale-confirm-section"
                id="confirmDiscountDetails"
                hidden
            >


                <div class="sale-confirm-section-title">

                    <span class="material-symbols-rounded">
                        verified
                    </span>

                    Discount Information

                </div>


                <div class="sale-confirm-row">

                    <span>
                        Type
                    </span>

                    <strong id="confirmDiscountType">
                        —
                    </strong>

                </div>


                <div class="sale-confirm-row">

                    <span>
                        Customer
                    </span>

                    <strong id="confirmCustomerName">
                        —
                    </strong>

                </div>


                <div class="sale-confirm-row">

                    <span>
                        ID Number
                    </span>

                    <strong id="confirmCustomerId">
                        —
                    </strong>

                </div>

            </div>



            <!-- PAYMENT -->

            <div class="sale-confirm-section">


                <div class="sale-confirm-section-title">

                    <span class="material-symbols-rounded">
                        payments
                    </span>

                    Payment Information

                </div>


                <div class="sale-confirm-row">

                    <span>
                        Payment Method
                    </span>

                    <strong id="confirmPaymentMethod">
                        Cash
                    </strong>

                </div>


                <div id="confirmCashDetails">


                    <div class="sale-confirm-row">

                        <span>
                            Amount Tendered
                        </span>

                        <strong id="confirmTendered">
                            ₱0.00
                        </strong>

                    </div>


                    <div class="sale-confirm-row">

                        <span>
                            Change
                        </span>

                        <strong id="confirmChange">
                            ₱0.00
                        </strong>

                    </div>

                </div>


                <div
                    id="confirmCashlessDetails"
                    hidden
                >

                    <div class="sale-confirm-row">

                        <span>
                            Reference Number
                        </span>

                        <strong id="confirmReference">
                            —
                        </strong>

                    </div>

                </div>

            </div>


            <div class="sale-confirm-warning">

                <span class="material-symbols-rounded">
                    info
                </span>

                <p>
                    Confirming will save the transaction
                    and deduct the sold quantity from inventory.
                </p>

            </div>

        </div>



        <div class="sale-confirm-footer">

            <button
                type="button"
                class="sale-confirm-cancel"
                id="cancelConfirmSale"
            >
                Cancel
            </button>


            <button
                type="button"
                class="sale-confirm-submit"
                id="confirmSaleButton"
            >

                <span id="confirmSaleButtonText">
                    Confirm Sale
                </span>

                <span class="material-symbols-rounded">
                    check
                </span>

            </button>

        </div>

    </div>

</div>



<script>

/* =========================================================
   SERVER DATA
========================================================= */

const configuredTaxRate =
    <?= json_encode(
        $configuredTaxRate
    ) ?>;


const csrfToken =
    <?= json_encode(
        $_SESSION['csrf_token']
    ) ?>;


const paymentQrCodes = {

    GCash:
        <?= json_encode(
            $gcashQr
        ) ?>,

    Maya:
        <?= json_encode(
            $mayaQr
        ) ?>,

    MariBank:
        <?= json_encode(
            $maribankQr
        ) ?>

};


/* =========================================================
   PRODUCT DATA
========================================================= */

const productButtons =
    Array.from(
        document.querySelectorAll(
            '.product-item'
        )
    );


const products =
    productButtons.map(
        button => ({

            id:
                Number(
                    button.dataset.id
                ),

            barcode:
                button.dataset.barcode
                || '',

            name:
                button.dataset.name,

            price:
                Number(
                    button.dataset.price
                ),

            stock:
                Number(
                    button.dataset.stock
                ),

            photo:
                button.dataset.photo
                || '',

            element:
                button

        })
    );


/* =========================================================
   STATE
========================================================= */

let cart = [];

let selectedPayment =
    'Cash';

let selectedDiscount =
    'None';

let selectedCartProductId =
    null;

let currentSubtotal =
    0;

let currentTaxAmount =
    0;

let currentDiscountAmount =
    0;

let currentTotal =
    0;

let checkoutInProgress =
    false;


/* =========================================================
   ELEMENTS
========================================================= */

const searchInput =
    document.getElementById(
        'productSearch'
    );


const clearSearchButton =
    document.getElementById(
        'clearSearch'
    );


const productCount =
    document.getElementById(
        'productCount'
    );


const cartItems =
    document.getElementById(
        'cartItems'
    );


const cartEmpty =
    document.getElementById(
        'cartEmpty'
    );


const cartItemCount =
    document.getElementById(
        'cartItemCount'
    );


const clearCartButton =
    document.getElementById(
        'clearCart'
    );


const subtotalValue =
    document.getElementById(
        'subtotalValue'
    );


const taxLabel =
    document.getElementById(
        'taxLabel'
    );


const taxValue =
    document.getElementById(
        'taxValue'
    );


const discountRow =
    document.getElementById(
        'discountRow'
    );


const discountLabel =
    document.getElementById(
        'discountLabel'
    );


const discountValue =
    document.getElementById(
        'discountValue'
    );


const totalValue =
    document.getElementById(
        'totalValue'
    );


const discountType =
    document.getElementById(
        'discountType'
    );


const discountDetails =
    document.getElementById(
        'discountDetails'
    );


const discountCustomerName =
    document.getElementById(
        'discountCustomerName'
    );


const discountCustomerId =
    document.getElementById(
        'discountCustomerId'
    );


const discountIdLabel =
    document.getElementById(
        'discountIdLabel'
    );


const cashPaymentDetails =
    document.getElementById(
        'cashPaymentDetails'
    );


const cashlessPaymentDetails =
    document.getElementById(
        'cashlessPaymentDetails'
    );


const cashTendered =
    document.getElementById(
        'cashTendered'
    );


const changeValue =
    document.getElementById(
        'changeValue'
    );


const cashStatus =
    document.getElementById(
        'cashStatus'
    );


const cashlessTitle =
    document.getElementById(
        'cashlessTitle'
    );


const cashlessIcon =
    document.getElementById(
        'cashlessIcon'
    );


const cashlessMethodName =
    document.getElementById(
        'cashlessMethodName'
    );


const paymentQrImage =
    document.getElementById(
        'paymentQrImage'
    );


const qrPlaceholder =
    document.getElementById(
        'qrPlaceholder'
    );


const qrPlaceholderText =
    document.getElementById(
        'qrPlaceholderText'
    );


const paymentReference =
    document.getElementById(
        'paymentReference'
    );


const checkoutButton =
    document.getElementById(
        'checkoutButton'
    );


const checkoutButtonText =
    document.getElementById(
        'checkoutButtonText'
    );


/* =========================================================
   CONFIRM MODAL ELEMENTS
========================================================= */

const saleConfirmModal =
    document.getElementById(
        'saleConfirmModal'
    );


const saleConfirmBackdrop =
    document.getElementById(
        'saleConfirmBackdrop'
    );


const closeConfirmModalButton =
    document.getElementById(
        'closeConfirmModal'
    );


const cancelConfirmSale =
    document.getElementById(
        'cancelConfirmSale'
    );


const confirmSaleButton =
    document.getElementById(
        'confirmSaleButton'
    );


const confirmSaleButtonText =
    document.getElementById(
        'confirmSaleButtonText'
    );


const confirmItemCount =
    document.getElementById(
        'confirmItemCount'
    );


const confirmSubtotal =
    document.getElementById(
        'confirmSubtotal'
    );


const confirmTaxLabel =
    document.getElementById(
        'confirmTaxLabel'
    );


const confirmTax =
    document.getElementById(
        'confirmTax'
    );


const confirmDiscountRow =
    document.getElementById(
        'confirmDiscountRow'
    );


const confirmDiscountLabel =
    document.getElementById(
        'confirmDiscountLabel'
    );


const confirmDiscount =
    document.getElementById(
        'confirmDiscount'
    );


const confirmTotal =
    document.getElementById(
        'confirmTotal'
    );


const confirmDiscountDetails =
    document.getElementById(
        'confirmDiscountDetails'
    );


const confirmDiscountType =
    document.getElementById(
        'confirmDiscountType'
    );


const confirmCustomerName =
    document.getElementById(
        'confirmCustomerName'
    );


const confirmCustomerId =
    document.getElementById(
        'confirmCustomerId'
    );


const confirmPaymentMethod =
    document.getElementById(
        'confirmPaymentMethod'
    );


const confirmCashDetails =
    document.getElementById(
        'confirmCashDetails'
    );


const confirmCashlessDetails =
    document.getElementById(
        'confirmCashlessDetails'
    );


const confirmTendered =
    document.getElementById(
        'confirmTendered'
    );


const confirmChange =
    document.getElementById(
        'confirmChange'
    );


const confirmReference =
    document.getElementById(
        'confirmReference'
    );


/* =========================================================
   MONEY
========================================================= */

function money(value) {

    return new Intl.NumberFormat(
        'en-PH',
        {
            style:
                'currency',

            currency:
                'PHP'
        }
    ).format(value);

}


/* =========================================================
   ADD PRODUCT
========================================================= */

function addProduct(product) {

    if (
        !product ||
        product.stock <= 0
    ) {
        return;
    }


    const existing =
        cart.find(
            item =>
                item.id ===
                product.id
        );


    if (existing) {

        if (
            existing.quantity >=
            product.stock
        ) {

            alert(
                'There is not enough stock for this product.'
            );

            return;
        }


        existing.quantity++;


    } else {


        cart.push({

            id:
                product.id,

            barcode:
                product.barcode,

            name:
                product.name,

            price:
                product.price,

            stock:
                product.stock,

            photo:
                product.photo,

            quantity:
                1

        });

    }


    selectedCartProductId =
        product.id;


    renderCart();

}


/* =========================================================
   SELECT CART ITEM
========================================================= */

function selectCartItem(productId) {

    const exists =
        cart.some(
            item =>
                item.id ===
                productId
        );


    if (!exists) {
        return;
    }


    selectedCartProductId =
        productId;


    renderCart();

}


/* =========================================================
   QUANTITY
========================================================= */

function changeQuantity(
    productId,
    amount
) {

    const item =
        cart.find(
            item =>
                item.id ===
                productId
        );


    if (!item) {
        return;
    }


    selectedCartProductId =
        productId;


    const newQuantity =
        item.quantity +
        amount;


    if (newQuantity <= 0) {

        removeFromCart(
            productId
        );

        return;
    }


    if (
        newQuantity >
        item.stock
    ) {

        alert(
            'There is not enough stock for this product.'
        );

        return;
    }


    item.quantity =
        newQuantity;


    renderCart();

}


/* =========================================================
   REMOVE
========================================================= */

function removeFromCart(productId) {

    cart =
        cart.filter(
            item =>
                item.id !==
                productId
        );


    if (
        selectedCartProductId ===
        productId
    ) {

        selectedCartProductId =
            cart.length > 0
                ? cart[
                    cart.length - 1
                ].id
                : null;
    }


    renderCart();

}


/* =========================================================
   RENDER CART
========================================================= */

function renderCart() {

    cartItems
        .querySelectorAll(
            '.cart-item'
        )
        .forEach(
            item =>
                item.remove()
        );


    if (cart.length === 0) {

        cartEmpty.style.display =
            'flex';

        checkoutButton.disabled =
            true;


    } else {


        cartEmpty.style.display =
            'none';


        checkoutButton.disabled =
            checkoutInProgress;


        const selectedExists =
            cart.some(
                item =>
                    item.id ===
                    selectedCartProductId
            );


        if (!selectedExists) {

            selectedCartProductId =
                cart[
                    cart.length - 1
                ].id;
        }

    }


    cart.forEach(
        item => {


            const selected =
                item.id ===
                selectedCartProductId;


            const element =
                document.createElement(
                    'div'
                );


            element.className =
                selected
                    ? 'cart-item selected'
                    : 'cart-item';


            const photoMarkup =
                item.photo
                    ? `
                        <img
                            src="${escapeHtml(
                                item.photo
                            )}"
                            alt="${escapeHtml(
                                item.name
                            )}"
                        >
                    `
                    : `
                        <span class="material-symbols-rounded">
                            apparel
                        </span>
                    `;


            element.innerHTML = `

                <div class="cart-item-top">


                    <div class="cart-item-main">


                        <div class="cart-item-photo">

                            ${photoMarkup}

                        </div>


                        <div class="cart-item-info">

                            <strong>
                                ${escapeHtml(
                                    item.name
                                )}
                            </strong>

                            <small>

                                ${escapeHtml(
                                    item.barcode ||
                                    'No barcode'
                                )}

                                ${
                                    selected
                                        ? ' • Selected'
                                        : ''
                                }

                            </small>

                        </div>

                    </div>


                    <button
                        type="button"
                        class="remove-item"
                    >

                        <span class="material-symbols-rounded">
                            close
                        </span>

                    </button>

                </div>


                <div class="cart-item-bottom">


                    <div class="quantity-control">

                        <button
                            type="button"
                            class="quantity-minus"
                        >
                            −
                        </button>

                        <span>
                            ${item.quantity}
                        </span>

                        <button
                            type="button"
                            class="quantity-plus"
                        >
                            +
                        </button>

                    </div>


                    <div class="cart-line-price">

                        <small>

                            ${money(
                                item.price
                            )} each

                        </small>

                        <strong>

                            ${money(
                                item.price *
                                item.quantity
                            )}

                        </strong>

                    </div>

                </div>
            `;


            element
                .querySelector(
                    '.cart-item-main'
                )
                .addEventListener(
                    'click',
                    () =>
                        selectCartItem(
                            item.id
                        )
                );


            element
                .querySelector(
                    '.quantity-minus'
                )
                .addEventListener(
                    'click',
                    () =>
                        changeQuantity(
                            item.id,
                            -1
                        )
                );


            element
                .querySelector(
                    '.quantity-plus'
                )
                .addEventListener(
                    'click',
                    () =>
                        changeQuantity(
                            item.id,
                            1
                        )
                );


            element
                .querySelector(
                    '.remove-item'
                )
                .addEventListener(
                    'click',
                    () =>
                        removeFromCart(
                            item.id
                        )
                );


            cartItems.appendChild(
                element
            );

        }
    );


    updateTotals();

}


/* =========================================================
   TOTALS
========================================================= */

function updateTotals() {

    const itemCount =
        cart.reduce(
            (
                total,
                item
            ) =>
                total +
                item.quantity,
            0
        );


    cartItemCount.textContent =
        itemCount === 1
            ? '1 item'
            : `${itemCount} items`;


    currentSubtotal =
        cart.reduce(
            (
                total,
                item
            ) =>
                total +
                (
                    item.price *
                    item.quantity
                ),
            0
        );


    currentTaxAmount =
        currentSubtotal *
        (
            configuredTaxRate /
            100
        );


    const discountPercentage =
        (
            selectedDiscount === 'PWD' ||
            selectedDiscount === 'Senior'
        )
            ? 20
            : 0;


    currentDiscountAmount =
        currentSubtotal *
        (
            discountPercentage /
            100
        );


    currentTotal =
        Math.max(
            currentSubtotal +
            currentTaxAmount -
            currentDiscountAmount,
            0
        );


    subtotalValue.textContent =
        money(
            currentSubtotal
        );


    taxLabel.textContent =
        `VAT (${configuredTaxRate}%)`;


    taxValue.textContent =
        money(
            currentTaxAmount
        );


    if (
        discountPercentage >
        0
    ) {

        discountRow.classList.add(
            'show'
        );


        discountLabel.textContent =
            `${
                selectedDiscount ===
                'Senior'
                    ? 'Senior Citizen'
                    : 'PWD'
            } (${discountPercentage}%)`;


        discountValue.textContent =
            '-'
            + money(
                currentDiscountAmount
            );


    } else {


        discountRow.classList.remove(
            'show'
        );


        discountValue.textContent =
            '-₱0.00';

    }


    totalValue.textContent =
        money(
            currentTotal
        );


    updateCashChange();

}


/* =========================================================
   DISCOUNT
========================================================= */

function updateDiscountFields() {

    selectedDiscount =
        discountType.value;


    if (
        selectedDiscount ===
        'PWD' ||
        selectedDiscount ===
        'Senior'
    ) {

        discountDetails.style.display =
            'grid';


        if (
            selectedDiscount ===
            'PWD'
        ) {

            discountIdLabel.textContent =
                'PWD ID Number';


            discountCustomerId.placeholder =
                'Enter PWD ID number';


        } else {


            discountIdLabel.textContent =
                'Senior Citizen ID Number';


            discountCustomerId.placeholder =
                'Enter Senior Citizen ID number';

        }


    } else {


        discountDetails.style.display =
            'none';


        discountCustomerName.value =
            '';


        discountCustomerId.value =
            '';

    }


    updateTotals();

}


function selectDiscount(type) {

    discountType.value =
        type;


    updateDiscountFields();


    if (
        type === 'PWD' ||
        type === 'Senior'
    ) {

        setTimeout(
            () =>
                discountCustomerName
                    .focus(),
            30
        );

    }

}


/* =========================================================
   CASH CHANGE
========================================================= */

function updateCashChange() {

    if (
        selectedPayment !==
        'Cash'
    ) {
        return;
    }


    const tendered =
        Number(
            cashTendered.value
            || 0
        );


    cashStatus.classList.remove(
        'valid',
        'invalid'
    );


    if (tendered <= 0) {

        changeValue.textContent =
            money(0);


        cashStatus.textContent =
            'Enter amount tendered';


        return;
    }


    const change =
        tendered -
        currentTotal;


    if (change < 0) {

        changeValue.textContent =
            money(0);


        cashStatus.textContent =
            `Short by ${money(
                Math.abs(
                    change
                )
            )}`;


        cashStatus.classList.add(
            'invalid'
        );


        return;
    }


    changeValue.textContent =
        money(
            change
        );


    cashStatus.textContent =
        'Payment amount is sufficient';


    cashStatus.classList.add(
        'valid'
    );

}


/* =========================================================
   PAYMENT
========================================================= */

function updatePaymentDetails() {

    if (
        selectedPayment ===
        'Cash'
    ) {

        cashPaymentDetails.hidden =
            false;


        cashlessPaymentDetails.hidden =
            true;


        paymentReference.value =
            '';


        updateCashChange();


        return;

    }


    cashPaymentDetails.hidden =
        true;


    cashlessPaymentDetails.hidden =
        false;


    const qr =
        paymentQrCodes[
            selectedPayment
        ]
        || '';


    cashlessTitle.textContent =
        `${selectedPayment} Payment`;


    cashlessMethodName.textContent =
        selectedPayment;


    cashlessIcon.textContent =
        selectedPayment ===
        'MariBank'
            ? 'account_balance'
            : 'qr_code_2';


    if (qr !== '') {

        paymentQrImage.src =
            qr;


        paymentQrImage.alt =
            `${selectedPayment} QR Code`;


        paymentQrImage.hidden =
            false;


        qrPlaceholder.hidden =
            true;


    } else {


        paymentQrImage.hidden =
            true;


        paymentQrImage.removeAttribute(
            'src'
        );


        qrPlaceholder.hidden =
            false;


        qrPlaceholderText.textContent =
            `No ${selectedPayment} QR code has been configured.`;

    }

}


function selectPayment(method) {

    document
        .querySelectorAll(
            '.payment-button'
        )
        .forEach(
            button =>
                button.classList.remove(
                    'active'
                )
        );


    const button =
        document.querySelector(
            `.payment-button[data-payment="${method}"]`
        );


    if (!button) {
        return;
    }


    button.classList.add(
        'active'
    );


    selectedPayment =
        method;


    updatePaymentDetails();

}


/* =========================================================
   SEARCH
========================================================= */

function filterProducts() {

    const query =
        searchInput
            .value
            .trim()
            .toLowerCase();


    let visible =
        0;


    products.forEach(
        product => {


            const matches =
                query === '' ||
                product.name
                    .toLowerCase()
                    .includes(
                        query
                    ) ||
                product.barcode
                    .toLowerCase()
                    .includes(
                        query
                    );


            product.element.style.display =
                matches
                    ? 'flex'
                    : 'none';


            if (matches) {
                visible++;
            }

        }
    );


    productCount.textContent =
        `${visible} available ${
            visible === 1
                ? 'product'
                : 'products'
        }`;

}


searchInput.addEventListener(
    'keydown',
    event => {


        if (
            event.key !==
            'Enter'
        ) {
            return;
        }


        event.preventDefault();


        const query =
            searchInput
                .value
                .trim()
                .toLowerCase();


        if (!query) {
            return;
        }


        const exact =
            products.find(
                product =>
                    product.barcode
                        .toLowerCase()
                        === query
            );


        if (exact) {

            addProduct(
                exact
            );


            searchInput.value =
                '';


            filterProducts();


            searchInput.focus();


            return;
        }


        const visible =
            products.filter(
                product =>
                    product.element
                        .style.display !==
                    'none'
            );


        if (
            visible.length ===
            1
        ) {

            addProduct(
                visible[0]
            );


            searchInput.value =
                '';


            filterProducts();


            searchInput.focus();

        }

    }
);


searchInput.addEventListener(
    'input',
    filterProducts
);


clearSearchButton.addEventListener(
    'click',
    () => {

        searchInput.value =
            '';


        filterProducts();


        searchInput.focus();

    }
);


/* =========================================================
   PRODUCT BUTTONS
========================================================= */

products.forEach(
    product => {

        product.element
            .addEventListener(
                'click',
                () =>
                    addProduct(
                        product
                    )
            );

    }
);


/* =========================================================
   CLEAR CART
========================================================= */

clearCartButton.addEventListener(
    'click',
    () => {


        if (
            cart.length ===
            0
        ) {
            return;
        }


        if (
            !confirm(
                'Clear all items from the current sale?'
            )
        ) {
            return;
        }


        cart = [];


        selectedCartProductId =
            null;


        cashTendered.value =
            '';


        paymentReference.value =
            '';


        renderCart();


        searchInput.focus();

    }
);


/* =========================================================
   EVENTS
========================================================= */

discountType.addEventListener(
    'change',
    updateDiscountFields
);


cashTendered.addEventListener(
    'input',
    updateCashChange
);


document
    .querySelectorAll(
        '.payment-button'
    )
    .forEach(
        button => {

            button.addEventListener(
                'click',
                () =>
                    selectPayment(
                        button.dataset
                            .payment
                    )
            );

        }
    );


/* =========================================================
   OPEN CONFIRM MODAL
========================================================= */

function openConfirmSaleModal() {

    const itemCount =
        cart.reduce(
            (
                total,
                item
            ) =>
                total +
                item.quantity,
            0
        );


    confirmItemCount.textContent =
        itemCount === 1
            ? '1 item'
            : `${itemCount} items`;


    confirmSubtotal.textContent =
        money(
            currentSubtotal
        );


    confirmTaxLabel.textContent =
        `VAT (${configuredTaxRate}%)`;


    confirmTax.textContent =
        money(
            currentTaxAmount
        );


    confirmTotal.textContent =
        money(
            currentTotal
        );


    if (
        selectedDiscount ===
        'PWD' ||
        selectedDiscount ===
        'Senior'
    ) {

        confirmDiscountRow.hidden =
            false;


        confirmDiscountDetails.hidden =
            false;


        const discountName =
            selectedDiscount ===
            'Senior'
                ? 'Senior Citizen'
                : 'PWD';


        confirmDiscountLabel.textContent =
            `${discountName} (20%)`;


        confirmDiscount.textContent =
            '-'
            + money(
                currentDiscountAmount
            );


        confirmDiscountType.textContent =
            `${discountName} - 20%`;


        confirmCustomerName.textContent =
            discountCustomerName
                .value
                .trim();


        confirmCustomerId.textContent =
            discountCustomerId
                .value
                .trim();


    } else {


        confirmDiscountRow.hidden =
            true;


        confirmDiscountDetails.hidden =
            true;

    }


    confirmPaymentMethod.textContent =
        selectedPayment;


    if (
        selectedPayment ===
        'Cash'
    ) {

        confirmCashDetails.hidden =
            false;


        confirmCashlessDetails.hidden =
            true;


        const tendered =
            Number(
                cashTendered.value
                || 0
            );


        confirmTendered.textContent =
            money(
                tendered
            );


        confirmChange.textContent =
            money(
                tendered -
                currentTotal
            );


    } else {


        confirmCashDetails.hidden =
            true;


        confirmCashlessDetails.hidden =
            false;


        confirmReference.textContent =
            paymentReference
                .value
                .trim();

    }


    saleConfirmModal.hidden =
        false;


    document.body.classList.add(
        'sale-confirm-open'
    );


    setTimeout(
        () => {

            confirmSaleButton
                .focus();

        },
        50
    );

}


/* =========================================================
   CLOSE CONFIRM MODAL
========================================================= */

function closeSaleConfirmModal() {

    if (checkoutInProgress) {
        return;
    }


    saleConfirmModal.hidden =
        true;


    document.body.classList.remove(
        'sale-confirm-open'
    );


    checkoutButton.focus();

}


/* =========================================================
   VALIDATION
========================================================= */

function attemptCheckout() {

    if (
        checkoutInProgress ||
        cart.length === 0
    ) {
        return;
    }


    if (
        selectedDiscount ===
        'PWD' ||
        selectedDiscount ===
        'Senior'
    ) {

        const customerName =
            discountCustomerName
                .value
                .trim();


        const customerId =
            discountCustomerId
                .value
                .trim();


        if (!customerName) {

            alert(
                'Please enter the customer name for the discount.'
            );


            discountCustomerName
                .focus();


            return;
        }


        if (!customerId) {

            alert(
                'Please enter the customer ID number for the discount.'
            );


            discountCustomerId
                .focus();


            return;
        }

    }


    if (
        selectedPayment ===
        'Cash'
    ) {

        const tendered =
            Number(
                cashTendered.value
                || 0
            );


        if (
            tendered <= 0
        ) {

            alert(
                'Please enter the amount tendered.'
            );


            cashTendered.focus();


            return;
        }


        if (
            tendered <
            currentTotal
        ) {

            alert(
                'The amount tendered is not enough.'
            );


            cashTendered.focus();


            return;
        }

    }


    if (
        selectedPayment !==
        'Cash'
    ) {

        const reference =
            paymentReference
                .value
                .trim();


        if (!reference) {

            alert(
                `Please enter the ${selectedPayment} reference number.`
            );


            paymentReference
                .focus();


            return;
        }

    }


    openConfirmSaleModal();

}


/* =========================================================
   PROCESS SALE
========================================================= */

async function processConfirmedSale() {

    if (
        checkoutInProgress
    ) {
        return;
    }


    checkoutInProgress =
        true;


    confirmSaleButton.disabled =
        true;


    checkoutButton.disabled =
        true;


    confirmSaleButtonText.textContent =
        'Processing...';


    checkoutButtonText.textContent =
        'Processing...';


    const customerName =
        (
            selectedDiscount ===
            'PWD' ||
            selectedDiscount ===
            'Senior'
        )
            ? discountCustomerName
                .value
                .trim()
            : '';


    const customerId =
        (
            selectedDiscount ===
            'PWD' ||
            selectedDiscount ===
            'Senior'
        )
            ? discountCustomerId
                .value
                .trim()
            : '';


    const tendered =
        selectedPayment ===
        'Cash'
            ? Number(
                cashTendered.value
                || 0
            )
            : 0;


    const reference =
        selectedPayment !==
        'Cash'
            ? paymentReference
                .value
                .trim()
            : '';


    try {

        const response =
            await fetch(
                '/pos/process_sale.php',
                {

                    method:
                        'POST',

                    headers: {

                        'Content-Type':
                            'application/json'

                    },

                    body:
                        JSON.stringify({

                            csrf_token:
                                csrfToken,

                            cart:
                                cart.map(
                                    item => ({

                                        id:
                                            item.id,

                                        quantity:
                                            item.quantity

                                    })
                                ),

                            discount_type:
                                selectedDiscount,

                            customer_name:
                                customerName,

                            customer_id:
                                customerId,

                            payment_method:
                                selectedPayment,

                            payment_reference:
                                reference,

                            amount_tendered:
                                tendered

                        })

                }
            );


        const data =
            await response.json();


        if (
            !response.ok ||
            !data.success
        ) {

            throw new Error(
                data.message ||
                'Unable to complete sale.'
            );
        }


        window.location.href =
            `/pos/receipt.php?id=${encodeURIComponent(
                data.sale_id
            )}`;


    } catch (error) {


        alert(
            error.message ||
            'Unable to complete the sale.'
        );


        checkoutInProgress =
            false;


        confirmSaleButton.disabled =
            false;


        checkoutButton.disabled =
            false;


        confirmSaleButtonText.textContent =
            'Confirm Sale';


        checkoutButtonText.textContent =
            'Complete Sale';

    }

}


/* =========================================================
   CONFIRM EVENTS
========================================================= */

checkoutButton.addEventListener(
    'click',
    attemptCheckout
);


confirmSaleButton.addEventListener(
    'click',
    processConfirmedSale
);


cancelConfirmSale.addEventListener(
    'click',
    closeSaleConfirmModal
);


closeConfirmModalButton.addEventListener(
    'click',
    closeSaleConfirmModal
);


saleConfirmBackdrop.addEventListener(
    'click',
    closeSaleConfirmModal
);


/* =========================================================
   INPUT CHECK
========================================================= */

function isTypingTarget(element) {

    if (!element) {
        return false;
    }


    const tag =
        element.tagName
            .toLowerCase();


    return (
        tag === 'input' ||
        tag === 'textarea' ||
        tag === 'select' ||
        element.isContentEditable
    );

}


/* =========================================================
   KEYBOARD SHORTCUTS
========================================================= */

document.addEventListener(
    'keydown',
    event => {


        if (
            !saleConfirmModal.hidden
        ) {

            if (
                event.key ===
                'Escape'
            ) {

                event.preventDefault();


                closeSaleConfirmModal();

            }


            return;
        }


        const typing =
            isTypingTarget(
                document.activeElement
            );


        if (
            event.key === '/' &&
            !typing
        ) {

            event.preventDefault();


            searchInput.focus();


            searchInput.select();


            return;
        }


        if (
            event.key ===
            'Escape'
        ) {

            if (
                document.activeElement ===
                searchInput
            ) {

                searchInput.value =
                    '';


                filterProducts();

            }


            searchInput.focus();


            return;
        }


        if (
            event.altKey &&
            event.key ===
            'Enter'
        ) {

            event.preventDefault();


            attemptCheckout();


            return;
        }


        if (
            event.altKey &&
            event.key ===
            '1'
        ) {

            event.preventDefault();


            selectDiscount(
                'None'
            );


            return;
        }


        if (
            event.altKey &&
            event.key ===
            '2'
        ) {

            event.preventDefault();


            selectDiscount(
                'PWD'
            );


            return;
        }


        if (
            event.altKey &&
            event.key ===
            '3'
        ) {

            event.preventDefault();


            selectDiscount(
                'Senior'
            );


            return;
        }


        if (
            event.altKey &&
            event.key
                .toLowerCase() ===
                'c'
        ) {

            event.preventDefault();


            selectPayment(
                'Cash'
            );


            setTimeout(
                () =>
                    cashTendered
                        .focus(),
                30
            );


            return;
        }


        if (
            event.altKey &&
            event.key
                .toLowerCase() ===
                'g'
        ) {

            event.preventDefault();


            selectPayment(
                'GCash'
            );


            setTimeout(
                () =>
                    paymentReference
                        .focus(),
                30
            );


            return;
        }


        if (
            event.altKey &&
            event.key
                .toLowerCase() ===
                'm'
        ) {

            event.preventDefault();


            selectPayment(
                'Maya'
            );


            setTimeout(
                () =>
                    paymentReference
                        .focus(),
                30
            );


            return;
        }


        if (
            event.altKey &&
            event.key
                .toLowerCase() ===
                'b'
        ) {

            event.preventDefault();


            selectPayment(
                'MariBank'
            );


            setTimeout(
                () =>
                    paymentReference
                        .focus(),
                30
            );


            return;
        }


        if (typing) {
            return;
        }


        if (
            event.key === '+' &&
            selectedCartProductId !==
            null
        ) {

            event.preventDefault();


            changeQuantity(
                selectedCartProductId,
                1
            );


            return;
        }


        if (
            event.key === '-' &&
            selectedCartProductId !==
            null
        ) {

            event.preventDefault();


            changeQuantity(
                selectedCartProductId,
                -1
            );


            return;
        }


        if (
            event.key ===
            'Delete' &&
            selectedCartProductId !==
            null
        ) {

            event.preventDefault();


            removeFromCart(
                selectedCartProductId
            );

        }

    }
);


/* =========================================================
   ESCAPE HTML
========================================================= */

function escapeHtml(value) {

    const div =
        document.createElement(
            'div'
        );


    div.textContent =
        value;


    return div.innerHTML;

}


/* =========================================================
   INITIALIZE
========================================================= */

renderCart();

updateDiscountFields();

updatePaymentDetails();

</script>


<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';

?>