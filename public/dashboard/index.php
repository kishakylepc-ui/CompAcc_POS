<?php

require_once __DIR__
    . '/../../app/middleware/auth.php';

require_once __DIR__
    . '/../../app/config/database.php';


$pageTitle =
    'Dashboard';

$currentPage =
    'dashboard';


$role =
    (string) (
        $_SESSION['role']
        ?? ''
    );


$userId =
    (int) (
        $_SESSION['user_id']
        ?? 0
    );


$isManagement =
    in_array(
        $role,
        [
            'Admin',
            'Manager'
        ],
        true
    );


/*
|--------------------------------------------------------------------------
| ACCESS MESSAGE
|--------------------------------------------------------------------------
*/

$accessError =
    $_SESSION['access_error']
    ?? null;


unset(
    $_SESSION['access_error']
);


/*
|--------------------------------------------------------------------------
| DATE / TIME HELPERS
|--------------------------------------------------------------------------
*/

$manilaTimezone =
    new DateTimeZone(
        'Asia/Manila'
    );


$utcTimezone =
    new DateTimeZone(
        'UTC'
    );


$nowManila =
    new DateTimeImmutable(
        'now',
        $manilaTimezone
    );


$todayDate =
    $nowManila->format(
        'Y-m-d'
    );


$monthStartDate =
    $nowManila
        ->modify(
            'first day of this month'
        )
        ->format(
            'Y-m-d'
        );


$todayDisplay =
    $nowManila->format(
        'l, F j, Y'
    );


function dashboardDisplayDateTime(
    ?string $utcDateTime
): string {

    $value =
        trim(
            (string) $utcDateTime
        );


    if ($value === '') {
        return '—';
    }


    try {

        $date =
            new DateTime(
                $value,
                new DateTimeZone(
                    'UTC'
                )
            );


        $date->setTimezone(
            new DateTimeZone(
                'Asia/Manila'
            )
        );


        return $date->format(
            'M d, Y · g:i A'
        );


    } catch (
        Throwable $error
    ) {

        return $value;
    }
}


function dashboardMoney(
    float $value
): string {

    return
        '₱'
        . number_format(
            $value,
            2
        );
}


function dashboardSettingInt(
    PDO $pdo,
    string $key,
    int $default
): int {

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
        ||
        !is_numeric(
            $value
        )
    ) {

        return max(
            0,
            $default
        );
    }


    return max(
        0,
        (int) $value
    );
}


/*
|--------------------------------------------------------------------------
| TODAY'S SALES SUMMARY
|--------------------------------------------------------------------------
|
| Database timestamps use UTC CURRENT_TIMESTAMP.
| We shift by +8 hours before comparing with the Manila calendar date.
|--------------------------------------------------------------------------
*/

$salesWhere =
    "
        s.status = 'Completed'
        AND date(
            s.created_at,
            '+8 hours'
        ) = ?
    ";


$salesParams = [
    $todayDate
];


if (!$isManagement) {

    $salesWhere .=
        "
        AND s.cashier_id = ?
        ";


    $salesParams[] =
        $userId;
}


$todaySalesStatement =
    $pdo->prepare("
        SELECT
            COUNT(*) AS transaction_count,
            COALESCE(
                SUM(s.total_amount),
                0
            ) AS sales_total
        FROM sales s
        WHERE $salesWhere
    ");


$todaySalesStatement->execute(
    $salesParams
);


$todaySalesSummary =
    $todaySalesStatement->fetch()
    ?: [];


$todayTransactionCount =
    (int) (
        $todaySalesSummary[
            'transaction_count'
        ]
        ?? 0
    );


$todaySales =
    (float) (
        $todaySalesSummary[
            'sales_total'
        ]
        ?? 0
    );


$todayAverageSale =
    $todayTransactionCount > 0
        ? $todaySales
            / $todayTransactionCount
        : 0.0;


/*
|--------------------------------------------------------------------------
| ITEMS SOLD TODAY
|--------------------------------------------------------------------------
*/

$itemsWhere =
    "
        s.status = 'Completed'
        AND date(
            s.created_at,
            '+8 hours'
        ) = ?
    ";


$itemsParams = [
    $todayDate
];


if (!$isManagement) {

    $itemsWhere .=
        "
        AND s.cashier_id = ?
        ";


    $itemsParams[] =
        $userId;
}


$itemsStatement =
    $pdo->prepare("
        SELECT
            COALESCE(
                SUM(si.quantity),
                0
            )
        FROM sale_items si

        INNER JOIN sales s
            ON s.id = si.sale_id

        WHERE $itemsWhere
    ");


$itemsStatement->execute(
    $itemsParams
);


$todayItemsSold =
    (int) $itemsStatement->fetchColumn();


/*
|--------------------------------------------------------------------------
| MANAGEMENT MONTH-TO-DATE FINANCIAL SNAPSHOT
|--------------------------------------------------------------------------
*/

$monthSales =
    0.0;


$monthExpenses =
    0.0;


$monthPayroll =
    0.0;


if ($isManagement) {

    $monthSalesStatement =
        $pdo->prepare("
            SELECT
                COALESCE(
                    SUM(total_amount),
                    0
                )
            FROM sales
            WHERE status = 'Completed'
              AND date(
                    created_at,
                    '+8 hours'
                  ) BETWEEN ? AND ?
        ");


    $monthSalesStatement->execute([
        $monthStartDate,
        $todayDate
    ]);


    $monthSales =
        (float) $monthSalesStatement
            ->fetchColumn();


    $monthExpensesStatement =
        $pdo->prepare("
            SELECT
                COALESCE(
                    SUM(amount),
                    0
                )
            FROM expenses
            WHERE expense_date
                BETWEEN ? AND ?
        ");


    $monthExpensesStatement->execute([
        $monthStartDate,
        $todayDate
    ]);


    $monthExpenses =
        (float) $monthExpensesStatement
            ->fetchColumn();


    $monthPayrollStatement =
        $pdo->prepare("
            SELECT
                COALESCE(
                    SUM(net_pay),
                    0
                )
            FROM payroll
            WHERE date(
                    created_at,
                    '+8 hours'
                  ) BETWEEN ? AND ?
        ");


    $monthPayrollStatement->execute([
        $monthStartDate,
        $todayDate
    ]);


    $monthPayroll =
        (float) $monthPayrollStatement
            ->fetchColumn();
}


/*
|--------------------------------------------------------------------------
| VARIANT REORDER SETTINGS
|--------------------------------------------------------------------------
*/

$reorderWindowDays =
    max(
        1,
        dashboardSettingInt(
            $pdo,
            'reorder_sales_window_days',
            30
        )
    );


$reorderLeadTimeDays =
    max(
        1,
        dashboardSettingInt(
            $pdo,
            'reorder_lead_time_days',
            7
        )
    );


$reorderSafetyDays =
    dashboardSettingInt(
        $pdo,
        'reorder_safety_days',
        3
    );


$variantReorderFallback =
    max(
        1,
        dashboardSettingInt(
            $pdo,
            'variant_reorder_fallback',
            5
        )
    );


/*
|--------------------------------------------------------------------------
| RECENT VARIANT SALES
|--------------------------------------------------------------------------
*/

$salesCutoffUtc =
    (
        new DateTimeImmutable(
            'now',
            $utcTimezone
        )
    )
    ->modify(
        '-'
        . $reorderWindowDays
        . ' days'
    )
    ->format(
        'Y-m-d H:i:s'
    );


$variantSalesStatement =
    $pdo->prepare("
        SELECT
            si.variant_id,
            COALESCE(
                SUM(si.quantity),
                0
            ) AS units_sold
        FROM sale_items si

        INNER JOIN sales s
            ON s.id = si.sale_id

        WHERE s.status = 'Completed'
          AND s.created_at >= ?
          AND si.variant_id IS NOT NULL

        GROUP BY si.variant_id
    ");


$variantSalesStatement->execute([
    $salesCutoffUtc
]);


$recentUnitsByVariant =
    [];


foreach (
    $variantSalesStatement->fetchAll()
    as $row
) {

    $recentUnitsByVariant[
        (int) $row['variant_id']
    ] =
        (int) $row['units_sold'];
}


/*
|--------------------------------------------------------------------------
| ACTIVE VARIANTS / INVENTORY ALERTS
|--------------------------------------------------------------------------
*/

$variantStatement =
    $pdo->query("
        SELECT
            pv.id,
            pv.product_id,
            pv.color,
            pv.size,
            pv.sku,
            pv.stock_quantity,

            p.product_code,
            p.product_name

        FROM product_variants pv

        INNER JOIN products p
            ON p.id = pv.product_id

        WHERE p.status = 'Active'
          AND pv.status = 'Active'

        ORDER BY
            pv.stock_quantity ASC,
            p.product_name ASC,
            pv.color ASC,
            pv.size ASC
    ");


$inventoryAlerts =
    [];


$variantsToRestock =
    0;


$outOfStockVariants =
    0;


foreach (
    $variantStatement->fetchAll()
    as $variant
) {

    $variantId =
        (int) $variant['id'];


    $currentStock =
        (int) $variant[
            'stock_quantity'
        ];


    $unitsSold =
        $recentUnitsByVariant[
            $variantId
        ]
        ?? 0;


    $averageDailyDemand =
        $unitsSold > 0
            ? $unitsSold
                / $reorderWindowDays
            : 0.0;


    if ($unitsSold > 0) {

        $reorderPoint =
            max(
                1,
                (int) ceil(
                    $averageDailyDemand
                    *
                    (
                        $reorderLeadTimeDays
                        +
                        $reorderSafetyDays
                    )
                )
            );


        $metricSource =
            'sales';

    } else {

        $reorderPoint =
            $variantReorderFallback;


        $metricSource =
            'baseline';
    }


    $needsReorder =
        $currentStock
        <= $reorderPoint;


    if ($currentStock <= 0) {
        $outOfStockVariants++;
    }


    if ($needsReorder) {

        $variantsToRestock++;


        $inventoryAlerts[] = [

            'variant_id' =>
                $variantId,

            'product_id' =>
                (int) $variant[
                    'product_id'
                ],

            'product_code' =>
                (string) $variant[
                    'product_code'
                ],

            'product_name' =>
                (string) $variant[
                    'product_name'
                ],

            'color' =>
                (string) $variant[
                    'color'
                ],

            'size' =>
                (string) $variant[
                    'size'
                ],

            'sku' =>
                (string) $variant[
                    'sku'
                ],

            'stock' =>
                $currentStock,

            'reorder_point' =>
                $reorderPoint,

            'source' =>
                $metricSource

        ];
    }
}


/*
|--------------------------------------------------------------------------
| RECENT TRANSACTIONS
|--------------------------------------------------------------------------
*/

$recentSalesSql =
    "
        SELECT
            s.id,
            s.transaction_no,
            s.total_amount,
            s.payment_method,
            s.created_at,

            u.first_name,
            u.middle_name,
            u.last_name,
            u.suffix

        FROM sales s

        INNER JOIN users u
            ON u.id = s.cashier_id

        WHERE s.status = 'Completed'
    ";


$recentSalesParams =
    [];


if (!$isManagement) {

    $recentSalesSql .=
        "
        AND s.cashier_id = ?
        ";


    $recentSalesParams[] =
        $userId;
}


$recentSalesSql .=
    "
        ORDER BY s.created_at DESC
        LIMIT 6
    ";


$recentSalesStatement =
    $pdo->prepare(
        $recentSalesSql
    );


$recentSalesStatement->execute(
    $recentSalesParams
);


$recentSales =
    $recentSalesStatement->fetchAll();


/*
|--------------------------------------------------------------------------
| RECENT RESTOCKS
|--------------------------------------------------------------------------
*/

$recentRestocks =
    [];


if ($isManagement) {

    $recentRestockStatement =
        $pdo->query("
            SELECT
                sr.id,
                sr.receipt_no,
                sr.total_cost,
                sr.created_at,

                s.supplier_name,

                u.first_name,
                u.middle_name,
                u.last_name,
                u.suffix,

                COALESCE(
                    SUM(sri.quantity),
                    0
                ) AS total_units

            FROM stock_receipts sr

            INNER JOIN suppliers s
                ON s.id = sr.supplier_id

            INNER JOIN users u
                ON u.id = sr.received_by

            LEFT JOIN stock_receipt_items sri
                ON sri.stock_receipt_id = sr.id

            GROUP BY
                sr.id

            ORDER BY
                sr.created_at DESC

            LIMIT 5
        ");


    $recentRestocks =
        $recentRestockStatement
            ->fetchAll();
}


/*
|--------------------------------------------------------------------------
| NAME HELPER
|--------------------------------------------------------------------------
*/

function dashboardPersonName(
    array $row
): string {

    $parts = [];


    foreach (
        [
            'first_name',
            'middle_name',
            'last_name',
            'suffix'
        ]
        as $key
    ) {

        $value =
            trim(
                (string) (
                    $row[$key]
                    ?? ''
                )
            );


        if ($value !== '') {
            $parts[] = $value;
        }
    }


    return
        implode(
            ' ',
            $parts
        );
}


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
    href="/assets/css/dashboard.css?v=20260914-final"
>


<div class="dashboard-container">


    <?php if ($accessError): ?>

        <div class="dashboard-alert error">

            <span class="material-symbols-rounded">
                error
            </span>

            <span>
                <?= htmlspecialchars(
                    $accessError
                ) ?>
            </span>

        </div>

    <?php endif; ?>



    <!-- =====================================================
         WELCOME / HERO
    ====================================================== -->

    <section class="dashboard-welcome">


        <div class="dashboard-welcome-copy">

            <div class="dashboard-eyebrow">
                UA POS OVERVIEW
            </div>


            <h2>

                Welcome back,
                <?= htmlspecialchars(
                    $_SESSION['full_name']
                    ?? 'User'
                ) ?>

            </h2>


            <p>

                <?= $isManagement
                    ? 'Here is the latest sales, inventory, and operational activity for Underground Apparel.'
                    : 'Here is your sales activity and recent transactions for today.'
                ?>

            </p>


            <div class="dashboard-welcome-meta">

                <span>

                    <span class="material-symbols-rounded">
                        badge
                    </span>

                    <?= htmlspecialchars(
                        $role
                    ) ?>

                </span>


                <span>

                    <span class="material-symbols-rounded">
                        calendar_today
                    </span>

                    <?= htmlspecialchars(
                        $todayDisplay
                    ) ?>

                </span>

            </div>

        </div>


        <div class="dashboard-hero-action">

            <a
                href="/pos/"
                class="dashboard-primary-action"
            >

                <span class="material-symbols-rounded">
                    point_of_sale
                </span>

                Open POS

            </a>

        </div>


    </section>



    <!-- =====================================================
         TODAY'S KPI
    ====================================================== -->

    <section class="dashboard-stats">


        <article class="dashboard-stat">

            <div class="stat-top">

                <div class="stat-icon">

                    <span class="material-symbols-rounded">
                        payments
                    </span>

                </div>

                <span class="stat-period">
                    Today
                </span>

            </div>


            <span class="stat-label">

                <?= $isManagement
                    ? "Today's Sales"
                    : "My Sales Today"
                ?>

            </span>


            <strong class="stat-value">
                <?= dashboardMoney(
                    $todaySales
                ) ?>
            </strong>

        </article>



        <article class="dashboard-stat">

            <div class="stat-top">

                <div class="stat-icon">

                    <span class="material-symbols-rounded">
                        receipt_long
                    </span>

                </div>

                <span class="stat-period">
                    Today
                </span>

            </div>


            <span class="stat-label">

                <?= $isManagement
                    ? 'Transactions'
                    : 'My Transactions'
                ?>

            </span>


            <strong class="stat-value">
                <?= $todayTransactionCount ?>
            </strong>

        </article>



        <?php if ($isManagement): ?>


            <article class="dashboard-stat <?= $variantsToRestock > 0 ? 'attention' : '' ?>">

                <div class="stat-top">

                    <div class="stat-icon">

                        <span class="material-symbols-rounded">
                            inventory_2
                        </span>

                    </div>

                    <span class="stat-period">
                        Live
                    </span>

                </div>


                <span class="stat-label">
                    Variants to Restock
                </span>


                <strong class="stat-value">
                    <?= $variantsToRestock ?>
                </strong>

            </article>



            <article class="dashboard-stat <?= $outOfStockVariants > 0 ? 'danger' : '' ?>">

                <div class="stat-top">

                    <div class="stat-icon">

                        <span class="material-symbols-rounded">
                            production_quantity_limits
                        </span>

                    </div>

                    <span class="stat-period">
                        Live
                    </span>

                </div>


                <span class="stat-label">
                    Out of Stock Variants
                </span>


                <strong class="stat-value">
                    <?= $outOfStockVariants ?>
                </strong>

            </article>


        <?php else: ?>


            <article class="dashboard-stat">

                <div class="stat-top">

                    <div class="stat-icon">

                        <span class="material-symbols-rounded">
                            apparel
                        </span>

                    </div>

                    <span class="stat-period">
                        Today
                    </span>

                </div>


                <span class="stat-label">
                    Items Sold
                </span>


                <strong class="stat-value">
                    <?= $todayItemsSold ?>
                </strong>

            </article>



            <article class="dashboard-stat">

                <div class="stat-top">

                    <div class="stat-icon">

                        <span class="material-symbols-rounded">
                            monitoring
                        </span>

                    </div>

                    <span class="stat-period">
                        Today
                    </span>

                </div>


                <span class="stat-label">
                    Average Transaction
                </span>


                <strong class="stat-value">
                    <?= dashboardMoney(
                        $todayAverageSale
                    ) ?>
                </strong>

            </article>


        <?php endif; ?>


    </section>



    <?php if ($isManagement): ?>

        <!-- =================================================
             MONTH TO DATE
        ================================================== -->

        <section class="dashboard-financial-strip">


            <div class="dashboard-financial-heading">

                <div>

                    <div class="dashboard-eyebrow">
                        MONTH TO DATE
                    </div>

                    <h3>
                        Operational Snapshot
                    </h3>

                </div>


                <a
                    href="/reports/"
                    class="dashboard-text-link"
                >

                    View Reports

                    <span class="material-symbols-rounded">
                        arrow_forward
                    </span>

                </a>

            </div>


            <div class="dashboard-financial-grid">


                <div class="dashboard-financial-item">

                    <span>
                        Completed Sales
                    </span>

                    <strong>
                        <?= dashboardMoney(
                            $monthSales
                        ) ?>
                    </strong>

                </div>


                <div class="dashboard-financial-item">

                    <span>
                        Expenses / Losses
                    </span>

                    <strong>
                        <?= dashboardMoney(
                            $monthExpenses
                        ) ?>
                    </strong>

                </div>


                <div class="dashboard-financial-item">

                    <span>
                        Net Payroll Processed
                    </span>

                    <strong>
                        <?= dashboardMoney(
                            $monthPayroll
                        ) ?>
                    </strong>

                </div>


            </div>


        </section>

    <?php endif; ?>



    <!-- =====================================================
         MAIN PANELS
    ====================================================== -->

    <section class="dashboard-lower">


        <!-- RECENT TRANSACTIONS -->

        <div class="dashboard-panel dashboard-transactions-panel">


            <div class="dashboard-panel-heading">

                <div>

                    <h3>
                        Recent Transactions
                    </h3>

                    <span class="dashboard-panel-subtitle">

                        <?= $isManagement
                            ? 'Latest completed POS transactions'
                            : 'Your latest completed POS transactions'
                        ?>

                    </span>

                </div>


                <a
                    href="<?= $isManagement
                        ? '/reports/?tab=sales'
                        : '/pos/'
                    ?>"
                    class="dashboard-panel-link"
                >

                    <?= $isManagement
                        ? 'Sales Report'
                        : 'Open POS'
                    ?>

                </a>

            </div>


            <?php if (
                empty(
                    $recentSales
                )
            ): ?>

                <div class="dashboard-empty">

                    <span class="material-symbols-rounded">
                        receipt_long
                    </span>

                    <strong>
                        No transactions yet
                    </strong>

                    <span>
                        Completed POS sales will appear here.
                    </span>

                </div>


            <?php else: ?>


                <div class="dashboard-transaction-list">


                    <?php foreach (
                        $recentSales
                        as $sale
                    ): ?>


                        <a
                            href="/pos/receipt.php?id=<?= (int) $sale['id'] ?>"
                            class="dashboard-transaction"
                        >


                            <div class="dashboard-transaction-icon">

                                <span class="material-symbols-rounded">
                                    receipt
                                </span>

                            </div>


                            <div class="dashboard-transaction-main">

                                <strong>
                                    <?= htmlspecialchars(
                                        $sale[
                                            'transaction_no'
                                        ]
                                    ) ?>
                                </strong>


                                <span>

                                    <?= htmlspecialchars(
                                        dashboardDisplayDateTime(
                                            $sale[
                                                'created_at'
                                            ]
                                        )
                                    ) ?>

                                    <?php if (
                                        $isManagement
                                    ): ?>

                                        ·
                                        <?= htmlspecialchars(
                                            dashboardPersonName(
                                                $sale
                                            )
                                        ) ?>

                                    <?php endif; ?>

                                </span>

                            </div>


                            <div class="dashboard-transaction-payment">

                                <span>
                                    <?= htmlspecialchars(
                                        $sale[
                                            'payment_method'
                                        ]
                                    ) ?>
                                </span>

                                <strong>
                                    <?= dashboardMoney(
                                        (float) $sale[
                                            'total_amount'
                                        ]
                                    ) ?>
                                </strong>

                            </div>


                        </a>


                    <?php endforeach; ?>


                </div>


            <?php endif; ?>


        </div>



        <!-- RIGHT COLUMN -->

        <div class="dashboard-side-column">


            <?php if ($isManagement): ?>


                <!-- INVENTORY ALERTS -->

                <div class="dashboard-panel">


                    <div class="dashboard-panel-heading">

                        <div>

                            <h3>
                                Inventory Alerts
                            </h3>

                            <span class="dashboard-panel-subtitle">
                                Active variants at or below reorder point
                            </span>

                        </div>


                        <a
                            href="/inventory/"
                            class="dashboard-panel-link"
                        >
                            Inventory
                        </a>

                    </div>


                    <?php if (
                        empty(
                            $inventoryAlerts
                        )
                    ): ?>

                        <div class="dashboard-empty compact">

                            <span class="material-symbols-rounded">
                                check_circle
                            </span>

                            <strong>
                                Stock levels look good
                            </strong>

                            <span>
                                No active variant currently needs restocking.
                            </span>

                        </div>


                    <?php else: ?>


                        <div class="dashboard-alert-list">


                            <?php foreach (
                                array_slice(
                                    $inventoryAlerts,
                                    0,
                                    6
                                )
                                as $alert
                            ): ?>


                                <a
                                    href="/inventory/"
                                    class="dashboard-stock-alert <?= $alert['stock'] <= 0 ? 'out' : '' ?>"
                                >

                                    <div>

                                        <strong>
                                            <?= htmlspecialchars(
                                                $alert[
                                                    'product_name'
                                                ]
                                            ) ?>
                                        </strong>

                                        <span>

                                            <?= htmlspecialchars(
                                                $alert[
                                                    'color'
                                                ]
                                            ) ?>

                                            ·

                                            <?= htmlspecialchars(
                                                $alert[
                                                    'size'
                                                ]
                                            ) ?>

                                        </span>

                                    </div>


                                    <div class="dashboard-stock-count">

                                        <strong>
                                            <?= $alert[
                                                'stock'
                                            ] ?>
                                        </strong>

                                        <span>
                                            ROP <?= $alert[
                                                'reorder_point'
                                            ] ?>
                                        </span>

                                    </div>

                                </a>


                            <?php endforeach; ?>


                        </div>


                    <?php endif; ?>


                </div>


            <?php else: ?>


                <!-- CASHIER QUICK ACTION -->

                <div class="dashboard-panel">


                    <div class="dashboard-panel-heading">

                        <div>

                            <h3>
                                Quick Actions
                            </h3>

                            <span class="dashboard-panel-subtitle">
                                Start the next transaction
                            </span>

                        </div>

                    </div>


                    <div class="dashboard-cashier-actions">

                        <a
                            href="/pos/"
                            class="dashboard-quick-action featured"
                        >

                            <span class="material-symbols-rounded">
                                point_of_sale
                            </span>

                            <div>

                                <strong>
                                    New Sale
                                </strong>

                                <small>
                                    Open the sales terminal
                                </small>

                            </div>

                        </a>

                    </div>


                </div>


            <?php endif; ?>


        </div>


    </section>



    <?php if ($isManagement): ?>

        <!-- =================================================
             ACTIVITY / QUICK ACTIONS
        ================================================== -->

        <section class="dashboard-bottom-grid">


            <!-- RECENT RESTOCKS -->

            <div class="dashboard-panel">


                <div class="dashboard-panel-heading">

                    <div>

                        <h3>
                            Recent Restocks
                        </h3>

                        <span class="dashboard-panel-subtitle">
                            Latest supplier stock receipts
                        </span>

                    </div>


                    <a
                        href="/reports/?tab=stock-receipts"
                        class="dashboard-panel-link"
                    >
                        Stock Receipts
                    </a>

                </div>


                <?php if (
                    empty(
                        $recentRestocks
                    )
                ): ?>

                    <div class="dashboard-empty compact">

                        <span class="material-symbols-rounded">
                            local_shipping
                        </span>

                        <strong>
                            No restocks yet
                        </strong>

                        <span>
                            Supplier stock receipts will appear here.
                        </span>

                    </div>


                <?php else: ?>


                    <div class="dashboard-restock-list">


                        <?php foreach (
                            $recentRestocks
                            as $restock
                        ): ?>


                            <div class="dashboard-restock-row">


                                <div class="dashboard-restock-icon">

                                    <span class="material-symbols-rounded">
                                        inventory
                                    </span>

                                </div>


                                <div class="dashboard-restock-copy">

                                    <strong>
                                        <?= htmlspecialchars(
                                            $restock[
                                                'receipt_no'
                                            ]
                                        ) ?>
                                    </strong>

                                    <span>

                                        <?= htmlspecialchars(
                                            $restock[
                                                'supplier_name'
                                            ]
                                        ) ?>

                                        ·

                                        <?= (int) $restock[
                                            'total_units'
                                        ] ?>
                                        units

                                    </span>

                                </div>


                                <div class="dashboard-restock-meta">

                                    <strong>
                                        <?= dashboardMoney(
                                            (float) $restock[
                                                'total_cost'
                                            ]
                                        ) ?>
                                    </strong>

                                    <span>
                                        <?= htmlspecialchars(
                                            dashboardDisplayDateTime(
                                                $restock[
                                                    'created_at'
                                                ]
                                            )
                                        ) ?>
                                    </span>

                                </div>


                            </div>


                        <?php endforeach; ?>


                    </div>


                <?php endif; ?>


            </div>



            <!-- QUICK LINKS -->

            <div class="dashboard-panel">


                <div class="dashboard-panel-heading">

                    <div>

                        <h3>
                            Quick Access
                        </h3>

                        <span class="dashboard-panel-subtitle">
                            Common management tasks
                        </span>

                    </div>

                </div>


                <div class="dashboard-quick-grid">


                    <a
                        href="/inventory/"
                        class="dashboard-quick-action"
                    >

                        <span class="material-symbols-rounded">
                            inventory_2
                        </span>

                        <div>

                            <strong>
                                Inventory
                            </strong>

                            <small>
                                Products and stock
                            </small>

                        </div>

                    </a>


                    <a
                        href="/suppliers/"
                        class="dashboard-quick-action"
                    >

                        <span class="material-symbols-rounded">
                            local_shipping
                        </span>

                        <div>

                            <strong>
                                Suppliers
                            </strong>

                            <small>
                                Supplier links
                            </small>

                        </div>

                    </a>


                    <a
                        href="/payroll/"
                        class="dashboard-quick-action"
                    >

                        <span class="material-symbols-rounded">
                            payments
                        </span>

                        <div>

                            <strong>
                                Payroll
                            </strong>

                            <small>
                                Salary records
                            </small>

                        </div>

                    </a>


                    <a
                        href="/reports/"
                        class="dashboard-quick-action"
                    >

                        <span class="material-symbols-rounded">
                            bar_chart
                        </span>

                        <div>

                            <strong>
                                Reports
                            </strong>

                            <small>
                                Business reports
                            </small>

                        </div>

                    </a>


                    <?php if (
                        $role === 'Admin'
                    ): ?>


                        <a
                            href="/accounts/"
                            class="dashboard-quick-action"
                        >

                            <span class="material-symbols-rounded">
                                manage_accounts
                            </span>

                            <div>

                                <strong>
                                    Accounts
                                </strong>

                                <small>
                                    User access
                                </small>

                            </div>

                        </a>


                        <a
                            href="/settings/"
                            class="dashboard-quick-action"
                        >

                            <span class="material-symbols-rounded">
                                settings
                            </span>

                            <div>

                                <strong>
                                    Settings
                                </strong>

                                <small>
                                    System configuration
                                </small>

                            </div>

                        </a>


                    <?php endif; ?>


                </div>


            </div>


        </section>

    <?php endif; ?>


</div>


<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';

?>
