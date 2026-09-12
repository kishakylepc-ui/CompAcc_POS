<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role.php';

requireRole([
    'Admin',
    'Manager'
]);

require_once __DIR__ . '/../../app/config/database.php';

$pageTitle = 'Reports';
$currentPage = 'reports';

date_default_timezone_set('Asia/Manila');


function reportMoney(float|int|string|null $value): string
{
    return '₱' . number_format((float) ($value ?? 0), 2);
}

function reportDateTime(string $utcDateTime): string
{
    if ($utcDateTime === '') {
        return '—';
    }

    try {
        $date = new DateTime($utcDateTime, new DateTimeZone('UTC'));
        $date->setTimezone(new DateTimeZone('Asia/Manila'));
        return $date->format('M d, Y h:i A');
    } catch (Throwable $error) {
        return $utcDateTime;
    }
}

function reportCashierName(array $row): string
{
    $parts = [];

    foreach (['first_name', 'middle_name', 'last_name', 'suffix'] as $column) {
        $value = trim((string) ($row[$column] ?? ''));

        if ($value !== '') {
            $parts[] = $value;
        }
    }

    if (!empty($parts)) {
        return implode(' ', $parts);
    }

    $username = trim((string) ($row['username'] ?? ''));

    return $username !== '' ? $username : 'Unknown Cashier';
}

function reportEmployeeName(array $row): string
{
    $parts = [];

    foreach (
        [
            'employee_first_name',
            'employee_middle_name',
            'employee_last_name',
            'employee_suffix'
        ]
        as $column
    ) {
        $value = trim((string) ($row[$column] ?? ''));

        if ($value !== '') {
            $parts[] = $value;
        }
    }

    if (!empty($parts)) {
        return implode(' ', $parts);
    }

    $employeeCode = trim(
        (string) ($row['employee_code'] ?? '')
    );

    return $employeeCode !== ''
        ? $employeeCode
        : 'Unknown Employee';
}

function reportPrefixedUserName(
    array $row,
    string $prefix
): string {

    $parts = [];

    foreach (
        [
            'first_name',
            'middle_name',
            'last_name',
            'suffix'
        ]
        as $column
    ) {
        $value = trim(
            (string) (
                $row[$prefix . $column]
                ?? ''
            )
        );

        if ($value !== '') {
            $parts[] = $value;
        }
    }

    if (!empty($parts)) {
        return implode(' ', $parts);
    }

    $username = trim(
        (string) (
            $row[$prefix . 'username']
            ?? ''
        )
    );

    return $username !== ''
        ? $username
        : 'Unknown User';
}

function reportDateOnly(string $value): string
{
    if ($value === '') {
        return '—';
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return $value;
    }

    return date('M d, Y', $timestamp);
}

function validReportDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $value,
        new DateTimeZone('Asia/Manila')
    );

    return $date !== false && $date->format('Y-m-d') === $value;
}

function executeReportQuery(
    PDO $pdo,
    string $sql,
    bool $allTime,
    ?string $fromUtc,
    ?string $toUtcExclusive
): PDOStatement {

    $statement = $pdo->prepare($sql);

    if ($allTime) {
        $statement->execute();
    } else {
        $statement->execute([
            ':from_utc' => $fromUtc,
            ':to_utc' => $toUtcExclusive
        ]);
    }

    return $statement;
}

function executeLocalDateReportQuery(
    PDO $pdo,
    string $sql,
    bool $allTime,
    string $fromDate,
    string $toDate
): PDOStatement {

    $statement = $pdo->prepare($sql);

    if ($allTime) {
        $statement->execute();
    } else {
        $statement->execute([
            ':from_date' => $fromDate,
            ':to_date' => $toDate
        ]);
    }

    return $statement;
}

function reportTabUrl(
    string $view,
    bool $allTime,
    string $fromDate,
    string $toDate
): string {

    $parameters = [
        'view' => $view
    ];

    if ($allTime) {
        $parameters['range'] = 'all';
    } else {
        $parameters['from'] = $fromDate;
        $parameters['to'] = $toDate;
    }

    return '/reports/?' . http_build_query($parameters);
}


$allowedViews = [
    'overview',
    'sales',
    'products',
    'inventory',
    'restocks',
    'discounts',
    'cashiers',
    'suppliers',
    'expenses',
    'payroll',
    'financials'
];

$view = trim((string) ($_GET['view'] ?? 'overview'));

if (!in_array($view, $allowedViews, true)) {
    $view = 'overview';
}

$manilaTimezone = new DateTimeZone('Asia/Manila');
$utcTimezone = new DateTimeZone('UTC');

$today = new DateTimeImmutable('today', $manilaTimezone);

$defaultFrom = $today
    ->modify('first day of this month')
    ->format('Y-m-d');

$defaultTo = $today->format('Y-m-d');

$allTime = ($_GET['range'] ?? '') === 'all';

$fromDate = trim((string) ($_GET['from'] ?? $defaultFrom));
$toDate = trim((string) ($_GET['to'] ?? $defaultTo));

$filterError = '';

if (!$allTime) {

    if (!validReportDate($fromDate) || !validReportDate($toDate)) {
        $filterError =
            'Invalid date range. The report was reset to this month.';

        $fromDate = $defaultFrom;
        $toDate = $defaultTo;
    }

    if ($fromDate > $toDate) {
        $filterError =
            'The From date cannot be later than the To date. The report was reset to this month.';

        $fromDate = $defaultFrom;
        $toDate = $defaultTo;
    }
}

$fromUtc = null;
$toUtcExclusive = null;

if (!$allTime) {

    $fromLocal =
        new DateTimeImmutable(
            $fromDate . ' 00:00:00',
            $manilaTimezone
        );

    $toLocalExclusive =
        (
            new DateTimeImmutable(
                $toDate . ' 00:00:00',
                $manilaTimezone
            )
        )->modify('+1 day');

    $fromUtc =
        $fromLocal
            ->setTimezone($utcTimezone)
            ->format('Y-m-d H:i:s');

    $toUtcExclusive =
        $toLocalExclusive
            ->setTimezone($utcTimezone)
            ->format('Y-m-d H:i:s');
}

$salesDateClause =
    $allTime
        ? ''
        : '
            AND s.created_at >= :from_utc
            AND s.created_at < :to_utc
        ';


$expenseDateClause =
    $allTime
        ? ''
        : '
            AND e.expense_date >= :from_date
            AND e.expense_date <= :to_date
        ';

$payrollDateClause =
    $allTime
        ? ''
        : '
            AND p.period_end >= :from_date
            AND p.period_end <= :to_date
        ';


$stockReceiptDateClause =
    $allTime
        ? ''
        : '
            AND sr.created_at >= :from_utc
            AND sr.created_at < :to_utc
        ';


$summaryStatement =
    executeReportQuery(
        $pdo,
        "
            SELECT
                COUNT(*) AS transaction_count,
                COALESCE(SUM(s.subtotal), 0) AS subtotal,
                COALESCE(SUM(s.tax_amount), 0) AS tax_amount,
                COALESCE(
                    SUM(
                        s.subtotal *
                        (s.discount_percent / 100.0)
                    ),
                    0
                ) AS discount_amount,
                COALESCE(SUM(s.total_amount), 0) AS total_sales,
                COALESCE(AVG(s.total_amount), 0) AS average_sale
            FROM sales s
            WHERE s.status = 'Completed'
            {$salesDateClause}
        ",
        $allTime,
        $fromUtc,
        $toUtcExclusive
    );

$summary = $summaryStatement->fetch() ?: [];

$itemCountStatement =
    executeReportQuery(
        $pdo,
        "
            SELECT
                COALESCE(SUM(si.quantity), 0)
            FROM sale_items si
            INNER JOIN sales s
                ON s.id = si.sale_id
            WHERE s.status = 'Completed'
            {$salesDateClause}
        ",
        $allTime,
        $fromUtc,
        $toUtcExclusive
    );

$itemsSold = (int) $itemCountStatement->fetchColumn();


$salesStatement =
    executeReportQuery(
        $pdo,
        "
            SELECT
                s.id,
                s.transaction_no,
                s.cashier_id,
                s.subtotal,
                s.tax_rate,
                s.tax_amount,
                s.discount_type,
                s.discount_percent,
                s.discount_customer_name,
                s.discount_id_number,
                s.total_amount,
                s.payment_method,
                s.payment_reference,
                s.amount_tendered,
                s.change_amount,
                s.status,
                s.created_at,

                u.username,
                u.first_name,
                u.middle_name,
                u.last_name,
                u.suffix

            FROM sales s

            LEFT JOIN users u
                ON u.id = s.cashier_id

            WHERE 1 = 1
            {$salesDateClause}

            ORDER BY
                s.created_at DESC,
                s.id DESC
        ",
        $allTime,
        $fromUtc,
        $toUtcExclusive
    );

$salesRows = $salesStatement->fetchAll();


$productSalesStatement =
    executeReportQuery(
        $pdo,
        "
            SELECT
                si.product_id,
                si.product_name,
                COUNT(DISTINCT s.id) AS transaction_count,
                COALESCE(SUM(si.quantity), 0) AS units_sold,
                COALESCE(SUM(si.line_total), 0) AS product_revenue

            FROM sale_items si

            INNER JOIN sales s
                ON s.id = si.sale_id

            WHERE s.status = 'Completed'
            {$salesDateClause}

            GROUP BY
                si.product_id,
                si.product_name

            ORDER BY
                units_sold DESC,
                product_revenue DESC,
                si.product_name ASC
        ",
        $allTime,
        $fromUtc,
        $toUtcExclusive
    );

$productSalesRows = $productSalesStatement->fetchAll();


$inventoryStatement =
    $pdo->query("
        SELECT
            p.id,
            p.barcode,
            p.product_name,
            p.cost_price,
            p.selling_price,
            p.stock_quantity,
            p.reorder_level,
            p.expiration_date,
            p.status,
            c.name AS category_name
        FROM products p
        LEFT JOIN categories c
            ON c.id = p.category_id
        ORDER BY
            p.product_name ASC
    ");

$inventoryRows = $inventoryStatement->fetchAll();

$inventoryCostValue = 0.0;
$inventoryRetailValue = 0.0;
$lowStockCount = 0;

foreach ($inventoryRows as $inventoryRow) {

    $stock = (int) $inventoryRow['stock_quantity'];
    $reorderLevel = (int) $inventoryRow['reorder_level'];

    $inventoryCostValue +=
        $stock * (float) $inventoryRow['cost_price'];

    $inventoryRetailValue +=
        $stock * (float) $inventoryRow['selling_price'];

    if ($stock <= $reorderLevel) {
        $lowStockCount++;
    }
}


$discountStatement =
    executeReportQuery(
        $pdo,
        "
            SELECT
                s.id,
                s.transaction_no,
                s.discount_type,
                s.discount_percent,
                s.discount_customer_name,
                s.discount_id_number,
                s.subtotal,
                s.total_amount,
                s.created_at

            FROM sales s

            WHERE
                s.status = 'Completed'
                AND s.discount_percent > 0

            {$salesDateClause}

            ORDER BY
                s.created_at DESC,
                s.id DESC
        ",
        $allTime,
        $fromUtc,
        $toUtcExclusive
    );

$discountRows = $discountStatement->fetchAll();

$totalDiscountUsed = 0.0;

foreach ($discountRows as $discountRow) {

    $totalDiscountUsed +=
        (float) $discountRow['subtotal']
        *
        (
            (float) $discountRow['discount_percent']
            / 100
        );
}


$cashierStatement =
    executeReportQuery(
        $pdo,
        "
            SELECT
                s.cashier_id,

                u.username,
                u.first_name,
                u.middle_name,
                u.last_name,
                u.suffix,

                COUNT(*) AS transaction_count,

                COALESCE(SUM(s.subtotal), 0) AS subtotal,

                COALESCE(SUM(s.tax_amount), 0) AS tax_amount,

                COALESCE(
                    SUM(
                        s.subtotal *
                        (s.discount_percent / 100.0)
                    ),
                    0
                ) AS discount_amount,

                COALESCE(SUM(s.total_amount), 0) AS total_sales,

                COALESCE(AVG(s.total_amount), 0) AS average_sale

            FROM sales s

            LEFT JOIN users u
                ON u.id = s.cashier_id

            WHERE s.status = 'Completed'
            {$salesDateClause}

            GROUP BY
                s.cashier_id,
                u.username,
                u.first_name,
                u.middle_name,
                u.last_name,
                u.suffix

            ORDER BY
                total_sales DESC,
                transaction_count DESC
        ",
        $allTime,
        $fromUtc,
        $toUtcExclusive
    );

$cashierRows = $cashierStatement->fetchAll();


$supplierStatement =
    $pdo->query("
        SELECT
            s.id,
            s.supplier_name,
            s.contact_person,
            s.phone,
            s.email,
            s.status,

            COUNT(ps.id)
                AS linked_product_count,

            COALESCE(
                SUM(
                    CASE
                        WHEN ps.is_primary = 1
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS primary_product_count,

            COALESCE(
                AVG(ps.supplier_price),
                0
            ) AS average_supplier_price

        FROM suppliers s

        LEFT JOIN product_suppliers ps
            ON ps.supplier_id = s.id

        GROUP BY
            s.id,
            s.supplier_name,
            s.contact_person,
            s.phone,
            s.email,
            s.status

        ORDER BY
            s.supplier_name ASC
    ");

$supplierRows = $supplierStatement->fetchAll();


/*
|--------------------------------------------------------------------------
| STOCK RECEIPTS / RESTOCKING REPORT
|--------------------------------------------------------------------------
|
| Inventory performs the restock. Reports keeps the permanent purchasing /
| stock receipt history. The date filter uses stock_receipts.created_at,
| which is stored in UTC just like sales.
|--------------------------------------------------------------------------
*/

$stockReceiptStatement =
    executeReportQuery(
        $pdo,
        "
            SELECT
                sr.id,
                sr.receipt_no,
                sr.supplier_id,
                sr.received_by,
                sr.total_cost,
                sr.notes,
                sr.created_at,

                s.supplier_name,

                u.username AS receiver_username,
                u.first_name AS receiver_first_name,
                u.middle_name AS receiver_middle_name,
                u.last_name AS receiver_last_name,
                u.suffix AS receiver_suffix,

                COUNT(sri.id) AS line_count,
                COALESCE(SUM(sri.quantity), 0) AS total_units

            FROM stock_receipts sr

            INNER JOIN suppliers s
                ON s.id = sr.supplier_id

            LEFT JOIN users u
                ON u.id = sr.received_by

            LEFT JOIN stock_receipt_items sri
                ON sri.stock_receipt_id = sr.id

            WHERE 1 = 1
            {$stockReceiptDateClause}

            GROUP BY
                sr.id,
                sr.receipt_no,
                sr.supplier_id,
                sr.received_by,
                sr.total_cost,
                sr.notes,
                sr.created_at,
                s.supplier_name,
                u.username,
                u.first_name,
                u.middle_name,
                u.last_name,
                u.suffix

            ORDER BY
                sr.created_at DESC,
                sr.id DESC
        ",
        $allTime,
        $fromUtc,
        $toUtcExclusive
    );

$stockReceiptRows =
    $stockReceiptStatement->fetchAll();


$stockReceiptCount =
    count($stockReceiptRows);

$stockReceiptUnitsTotal =
    0;

$stockReceiptCostTotal =
    0.0;

$stockReceiptSupplierIds =
    [];

foreach ($stockReceiptRows as $stockReceiptRow) {

    $stockReceiptUnitsTotal +=
        (int) $stockReceiptRow['total_units'];

    $stockReceiptCostTotal +=
        (float) $stockReceiptRow['total_cost'];

    $stockReceiptSupplierIds[
        (int) $stockReceiptRow['supplier_id']
    ] = true;
}

$stockReceiptSupplierCount =
    count($stockReceiptSupplierIds);


/*
|--------------------------------------------------------------------------
| SELECTED STOCK RECEIPT
|--------------------------------------------------------------------------
*/

$selectedStockReceiptId =
    $view === 'restocks'
        ? (int) ($_GET['receipt_id'] ?? 0)
        : 0;

$selectedStockReceipt =
    null;

$selectedStockReceiptItems =
    [];

if ($selectedStockReceiptId > 0) {

    $selectedStockReceiptStatement =
        $pdo->prepare("
            SELECT
                sr.id,
                sr.receipt_no,
                sr.supplier_id,
                sr.received_by,
                sr.total_cost,
                sr.notes,
                sr.created_at,

                s.supplier_name,
                s.contact_person,
                s.phone,
                s.email,

                u.username AS receiver_username,
                u.first_name AS receiver_first_name,
                u.middle_name AS receiver_middle_name,
                u.last_name AS receiver_last_name,
                u.suffix AS receiver_suffix

            FROM stock_receipts sr

            INNER JOIN suppliers s
                ON s.id = sr.supplier_id

            LEFT JOIN users u
                ON u.id = sr.received_by

            WHERE sr.id = ?

            LIMIT 1
        ");

    $selectedStockReceiptStatement->execute([
        $selectedStockReceiptId
    ]);

    $selectedStockReceipt =
        $selectedStockReceiptStatement->fetch()
        ?: null;


    if ($selectedStockReceipt !== null) {

        $selectedStockReceiptItemStatement =
            $pdo->prepare("
                SELECT
                    sri.id,
                    sri.product_id,
                    sri.variant_id,
                    sri.quantity,
                    sri.unit_cost,
                    sri.line_total,

                    p.product_name,
                    p.product_code,

                    pv.color,
                    pv.size,
                    pv.sku,
                    pv.barcode AS variant_barcode

                FROM stock_receipt_items sri

                INNER JOIN products p
                    ON p.id = sri.product_id

                INNER JOIN product_variants pv
                    ON pv.id = sri.variant_id

                WHERE sri.stock_receipt_id = ?

                ORDER BY
                    p.product_name ASC,
                    pv.color ASC,
                    pv.size ASC,
                    sri.id ASC
            ");

        $selectedStockReceiptItemStatement->execute([
            $selectedStockReceiptId
        ]);

        $selectedStockReceiptItems =
            $selectedStockReceiptItemStatement->fetchAll();
    }
}


/*
|--------------------------------------------------------------------------
| EXPENSE REPORT
|--------------------------------------------------------------------------
*/

$expenseStatement =
    executeLocalDateReportQuery(
        $pdo,
        "
            SELECT
                e.id,
                e.expense_type,
                e.category,
                e.description,
                e.amount,
                e.expense_date,
                e.recorded_by,
                e.created_at,

                u.username AS recorder_username,
                u.first_name AS recorder_first_name,
                u.middle_name AS recorder_middle_name,
                u.last_name AS recorder_last_name,
                u.suffix AS recorder_suffix

            FROM expenses e

            LEFT JOIN users u
                ON u.id = e.recorded_by

            WHERE 1 = 1
            {$expenseDateClause}

            ORDER BY
                e.expense_date DESC,
                e.id DESC
        ",
        $allTime,
        $fromDate,
        $toDate
    );

$expenseRows = $expenseStatement->fetchAll();

$expenseTotal = 0.0;
$expenseBreakdown = [];

foreach ($expenseRows as $expenseRow) {
    $amount = (float) $expenseRow['amount'];

    $expenseTotal += $amount;

    $breakdownLabel = trim(
        (string) (
            $expenseRow['category']
            ?? ''
        )
    );

    if ($breakdownLabel === '') {
        $breakdownLabel = trim(
            (string) $expenseRow['expense_type']
        );
    }

    if ($breakdownLabel === '') {
        $breakdownLabel = 'Uncategorized';
    }

    if (!isset($expenseBreakdown[$breakdownLabel])) {
        $expenseBreakdown[$breakdownLabel] = 0.0;
    }

    $expenseBreakdown[$breakdownLabel] += $amount;
}

arsort($expenseBreakdown);


/*
|--------------------------------------------------------------------------
| PAYROLL REPORT
|--------------------------------------------------------------------------
*/

$payrollStatement =
    executeLocalDateReportQuery(
        $pdo,
        "
            SELECT
                p.id,
                p.employee_id,
                p.period_start,
                p.period_end,
                p.hours_worked,
                p.gross_pay,
                p.deductions,
                p.deduction_notes,
                p.net_pay,
                p.processed_by,
                p.created_at,

                e.employee_code,
                e.first_name AS employee_first_name,
                e.middle_name AS employee_middle_name,
                e.last_name AS employee_last_name,
                e.suffix AS employee_suffix,
                e.position,
                e.pay_type,
                e.pay_rate,

                u.username AS processor_username,
                u.first_name AS processor_first_name,
                u.middle_name AS processor_middle_name,
                u.last_name AS processor_last_name,
                u.suffix AS processor_suffix

            FROM payroll p

            INNER JOIN employees e
                ON e.id = p.employee_id

            LEFT JOIN users u
                ON u.id = p.processed_by

            WHERE 1 = 1
            {$payrollDateClause}

            ORDER BY
                p.period_end DESC,
                p.id DESC
        ",
        $allTime,
        $fromDate,
        $toDate
    );

$payrollRows = $payrollStatement->fetchAll();

$payrollHoursTotal = 0.0;
$payrollGrossTotal = 0.0;
$payrollDeductionTotal = 0.0;
$payrollNetTotal = 0.0;

foreach ($payrollRows as $payrollRow) {
    $payrollHoursTotal +=
        (float) $payrollRow['hours_worked'];

    $payrollGrossTotal +=
        (float) $payrollRow['gross_pay'];

    $payrollDeductionTotal +=
        (float) $payrollRow['deductions'];

    $payrollNetTotal +=
        (float) $payrollRow['net_pay'];
}


/*
|--------------------------------------------------------------------------
| FINANCIAL SUMMARY
|--------------------------------------------------------------------------
*/

$selectedSalesTotal =
    (float) (
        $summary['total_sales']
        ?? 0
    );

$estimatedOperatingResult =
    $selectedSalesTotal
    - $expenseTotal
    - $payrollGrossTotal;


$paymentStatement =
    executeReportQuery(
        $pdo,
        "
            SELECT
                s.payment_method,
                COUNT(*) AS transaction_count,
                COALESCE(SUM(s.total_amount), 0) AS total_sales
            FROM sales s
            WHERE s.status = 'Completed'
            {$salesDateClause}
            GROUP BY s.payment_method
            ORDER BY total_sales DESC
        ",
        $allTime,
        $fromUtc,
        $toUtcExclusive
    );

$paymentRows = $paymentStatement->fetchAll();

$topProducts = array_slice($productSalesRows, 0, 5);

$reportLabels = [
    'overview' => 'Overview', 'sales' => 'Sales',
    'products' => 'Product Sales', 'inventory' => 'Inventory',
    'restocks' => $selectedStockReceipt !== null
        ? 'Stock Receipt'
        : 'Stock Receipts',
    'discounts' => 'Discounts', 'cashiers' => 'Cashiers',
    'suppliers' => 'Suppliers', 'expenses' => 'Expenses',
    'payroll' => 'Payroll', 'financials' => 'Financial Summary'
];

if ($view === 'restocks' && $selectedStockReceipt !== null) {

    $reportPeriod =
        'Receipt '
        . (string) $selectedStockReceipt['receipt_no']
        . ' • '
        . reportDateTime(
            (string) $selectedStockReceipt['created_at']
        );

} elseif (in_array($view, ['inventory', 'suppliers'], true)) {

    $reportPeriod =
        'Current snapshot as of '
        . date('M d, Y');

} else {

    $reportPeriod =
        $allTime
            ? 'All recorded data'
            : reportDateOnly($fromDate)
                . ' — '
                . reportDateOnly($toDate);
}

$widePrint = in_array(
    $view,
    [
        'sales',
        'inventory',
        'restocks',
        'discounts',
        'cashiers',
        'suppliers',
        'expenses',
        'payroll'
    ],
    true
);




require_once __DIR__
    . '/../../app/views/partials/header.php';

require_once __DIR__
    . '/../../app/views/partials/sidebar.php';

?>

<link
    rel="stylesheet"
    href="/assets/css/reports.css?v=20260908"
>


<div class="reports-page" data-print-layout="<?= $widePrint ? 'wide' : 'standard' ?>">
    <header class="reports-document-header">
        <img class="reports-brand-logo" src="/assets/images/UA_logo.jpg" alt="UA" width="72" height="72">
        <div class="reports-document-title">
            <p class="reports-brand-name">UNDERGROUND APPAREL</p>
            <h1><?= htmlspecialchars($reportLabels[$view]) ?> Report</h1>
            <p class="reports-document-period"><?= htmlspecialchars($reportPeriod) ?></p>
        </div>
        <div class="reports-document-issued">
            <span>Generated</span>
            <time datetime="<?= date('c') ?>"><?= date('M d, Y') ?><br><?= date('h:i A') ?> · PHT</time>
        </div>
    </header>


    <div class="reports-top no-print">

        <div>

            <div class="reports-eyebrow">
                BUSINESS ANALYTICS
            </div>

            <h2>
                Reports
            </h2>

            <p>
                Review sales, products, inventory, restocking,
                suppliers, expenses, payroll and financial performance.
            </p>

        </div>


        <button
            type="button"
            class="reports-print-button no-print"
            onclick="window.print()"
        >

            <span class="material-symbols-rounded">
                print
            </span>

            Print Report

        </button>

    </div>


    <?php if ($filterError !== ''): ?>

        <div class="reports-alert">

            <span class="material-symbols-rounded">
                warning
            </span>

            <?= htmlspecialchars($filterError) ?>

        </div>

    <?php endif; ?>


    <div class="reports-filter-card no-print">

        <form
            action="/reports/"
            method="GET"
            class="reports-filter-form"
        >

            <input
                type="hidden"
                name="view"
                value="<?= htmlspecialchars($view) ?>"
            >


            <div class="reports-filter-field">

                <label for="reportFrom">
                    From
                </label>

                <input
                    type="date"
                    id="reportFrom"
                    name="from"
                    value="<?= htmlspecialchars($fromDate) ?>"
                >

            </div>


            <div class="reports-filter-field">

                <label for="reportTo">
                    To
                </label>

                <input
                    type="date"
                    id="reportTo"
                    name="to"
                    value="<?= htmlspecialchars($toDate) ?>"
                >

            </div>


            <button
                type="submit"
                class="reports-apply-button"
            >

                <span class="material-symbols-rounded">
                    filter_alt
                </span>

                Apply Filter

            </button>


            <a
                href="<?= htmlspecialchars(
                    '/reports/?'
                    . http_build_query([
                        'view' => $view,
                        'from' => $defaultFrom,
                        'to' => $defaultTo
                    ])
                ) ?>"
                class="reports-filter-link"
            >
                This Month
            </a>


            <a
                href="<?= htmlspecialchars(
                    '/reports/?'
                    . http_build_query([
                        'view' => $view,
                        'range' => 'all'
                    ])
                ) ?>"
                class="reports-filter-link"
            >
                All Time
            </a>

        </form>


        <div class="reports-current-range">

            <span class="material-symbols-rounded">
                date_range
            </span>

            <?php if ($allTime): ?>

                All recorded data

            <?php else: ?>

                <?= htmlspecialchars(
                    date(
                        'M d, Y',
                        strtotime($fromDate)
                    )
                ) ?>

                —

                <?= htmlspecialchars(
                    date(
                        'M d, Y',
                        strtotime($toDate)
                    )
                ) ?>

            <?php endif; ?>

        </div>

    </div>


    <nav class="reports-tabs no-print">

        <?php

        $tabs = [
            'overview' => 'Overview',
            'sales' => 'Sales',
            'products' => 'Product Sales',
            'inventory' => 'Inventory',
            'restocks' => 'Stock Receipts',
            'discounts' => 'Discounts',
            'cashiers' => 'Cashiers',
            'suppliers' => 'Suppliers',
            'expenses' => 'Expenses',
            'payroll' => 'Payroll',
            'financials' => 'Financial Summary'
        ];

        ?>

        <?php foreach ($tabs as $tabKey => $tabLabel): ?>

            <a
                href="<?= htmlspecialchars(
                    reportTabUrl(
                        $tabKey,
                        $allTime,
                        $fromDate,
                        $toDate
                    )
                ) ?>"
                class="<?= $view === $tabKey
                    ? 'active'
                    : ''
                ?>"
            >
                <?= htmlspecialchars($tabLabel) ?>
            </a>

        <?php endforeach; ?>

    </nav>


    <?php if ($view !== 'restocks'): ?>

    <div class="reports-stats">

        <div class="reports-stat-card">

            <div class="reports-stat-icon">
                <span class="material-symbols-rounded">
                    receipt_long
                </span>
            </div>

            <div>
                <span>Transactions</span>
                <strong>
                    <?= (int) (
                        $summary['transaction_count']
                        ?? 0
                    ) ?>
                </strong>
            </div>

        </div>


        <div class="reports-stat-card">

            <div class="reports-stat-icon">
                <span class="material-symbols-rounded">
                    shopping_bag
                </span>
            </div>

            <div>
                <span>Items Sold</span>
                <strong><?= $itemsSold ?></strong>
            </div>

        </div>


        <div class="reports-stat-card">

            <div class="reports-stat-icon">
                <span class="material-symbols-rounded">
                    payments
                </span>
            </div>

            <div>
                <span>Total Sales</span>
                <strong>
                    <?= reportMoney(
                        $summary['total_sales']
                        ?? 0
                    ) ?>
                </strong>
            </div>

        </div>


        <div class="reports-stat-card">

            <div class="reports-stat-icon">
                <span class="material-symbols-rounded">
                    analytics
                </span>
            </div>

            <div>
                <span>Average Sale</span>
                <strong>
                    <?= reportMoney(
                        $summary['average_sale']
                        ?? 0
                    ) ?>
                </strong>
            </div>

        </div>

    </div>


    <?php else: ?>

        <div class="reports-stats <?= $selectedStockReceipt !== null ? 'no-print' : '' ?>">

            <div class="reports-stat-card">
                <div class="reports-stat-icon">
                    <span class="material-symbols-rounded">
                        receipt_long
                    </span>
                </div>
                <div>
                    <span>Stock Receipts</span>
                    <strong><?= $stockReceiptCount ?></strong>
                </div>
            </div>

            <div class="reports-stat-card">
                <div class="reports-stat-icon">
                    <span class="material-symbols-rounded">
                        inventory_2
                    </span>
                </div>
                <div>
                    <span>Units Received</span>
                    <strong><?= $stockReceiptUnitsTotal ?></strong>
                </div>
            </div>

            <div class="reports-stat-card">
                <div class="reports-stat-icon">
                    <span class="material-symbols-rounded">
                        payments
                    </span>
                </div>
                <div>
                    <span>Purchase Cost</span>
                    <strong><?= reportMoney($stockReceiptCostTotal) ?></strong>
                </div>
            </div>

            <div class="reports-stat-card">
                <div class="reports-stat-icon">
                    <span class="material-symbols-rounded">
                        local_shipping
                    </span>
                </div>
                <div>
                    <span>Suppliers Used</span>
                    <strong><?= $stockReceiptSupplierCount ?></strong>
                </div>
            </div>

        </div>

    <?php endif; ?>


    <?php if ($view === 'overview'): ?>

        <div class="reports-overview-grid">


            <section class="reports-card">

                <div class="reports-card-header">

                    <div>
                        <h3>Sales Summary</h3>
                        <p>
                            Financial totals for the selected period.
                        </p>
                    </div>

                </div>


                <div class="reports-summary-list">

                    <div>
                        <span>Merchandise Subtotal</span>
                        <strong>
                            <?= reportMoney(
                                $summary['subtotal']
                                ?? 0
                            ) ?>
                        </strong>
                    </div>

                    <div>
                        <span>VAT Collected</span>
                        <strong>
                            <?= reportMoney(
                                $summary['tax_amount']
                                ?? 0
                            ) ?>
                        </strong>
                    </div>

                    <div>
                        <span>Discounts Given</span>
                        <strong>
                            -<?= reportMoney(
                                $summary['discount_amount']
                                ?? 0
                            ) ?>
                        </strong>
                    </div>

                    <div class="grand">
                        <span>Net Sales</span>
                        <strong>
                            <?= reportMoney(
                                $summary['total_sales']
                                ?? 0
                            ) ?>
                        </strong>
                    </div>

                </div>

            </section>


            <section class="reports-card">

                <div class="reports-card-header">

                    <div>
                        <h3>Payment Methods</h3>
                        <p>
                            Sales totals grouped by payment method.
                        </p>
                    </div>

                </div>


                <?php if (empty($paymentRows)): ?>

                    <div class="reports-empty">

                        <span class="material-symbols-rounded">
                            payments
                        </span>

                        <strong>
                            No payment data
                        </strong>

                    </div>

                <?php else: ?>

                    <div class="reports-mini-list">

                        <?php foreach ($paymentRows as $paymentRow): ?>

                            <div>

                                <span>

                                    <?= htmlspecialchars(
                                        (string) $paymentRow[
                                            'payment_method'
                                        ]
                                    ) ?>

                                    <small>
                                        <?= (int) $paymentRow[
                                            'transaction_count'
                                        ] ?>
                                        transactions
                                    </small>

                                </span>

                                <strong>
                                    <?= reportMoney(
                                        $paymentRow[
                                            'total_sales'
                                        ]
                                    ) ?>
                                </strong>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </section>


            <section class="reports-card">

                <div class="reports-card-header">

                    <div>
                        <h3>Operating Costs</h3>
                        <p>
                            Expenses and gross payroll for the selected period.
                        </p>
                    </div>

                </div>


                <div class="reports-summary-list">

                    <div>
                        <span>Expenses</span>
                        <strong>
                            <?= reportMoney($expenseTotal) ?>
                        </strong>
                    </div>

                    <div>
                        <span>Gross Payroll</span>
                        <strong>
                            <?= reportMoney($payrollGrossTotal) ?>
                        </strong>
                    </div>

                    <div class="grand">
                        <span>Estimated Operating Result</span>
                        <strong
                            class="<?= $estimatedOperatingResult < 0
                                ? 'reports-negative-value'
                                : ''
                            ?>"
                        >
                            <?= reportMoney(
                                $estimatedOperatingResult
                            ) ?>
                        </strong>
                    </div>

                </div>

            </section>


            <section class="reports-card reports-wide-card">

                <div class="reports-card-header">

                    <div>
                        <h3>Top Products</h3>
                        <p>
                            Best-selling products by quantity.
                        </p>
                    </div>

                </div>


                <?php if (empty($topProducts)): ?>

                    <div class="reports-empty">

                        <span class="material-symbols-rounded">
                            inventory_2
                        </span>

                        <strong>
                            No product sales yet
                        </strong>

                    </div>

                <?php else: ?>

                    <div class="reports-table-wrap">

                        <table class="reports-table">

                            <thead>

                                <tr>
                                    <th>Product</th>
                                    <th>Transactions</th>
                                    <th>Units Sold</th>
                                    <th>Revenue</th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($topProducts as $productRow): ?>

                                    <tr>

                                        <td>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    (string) $productRow[
                                                        'product_name'
                                                    ]
                                                ) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= (int) $productRow[
                                                'transaction_count'
                                            ] ?>
                                        </td>

                                        <td>
                                            <?= (int) $productRow[
                                                'units_sold'
                                            ] ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= reportMoney(
                                                    $productRow[
                                                        'product_revenue'
                                                    ]
                                                ) ?>
                                            </strong>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </section>

        </div>

    <?php endif; ?>


    <?php if ($view === 'sales'): ?>

        <section class="reports-card">

            <div class="reports-card-header">

                <div>
                    <h3>Sales Transactions</h3>
                    <p>
                        <?= count($salesRows) ?>
                        recorded transactions in this report.
                    </p>
                </div>

            </div>


            <div class="reports-table-wrap">

                <table class="reports-table sales">

                    <thead>

                        <tr>
                            <th>Transaction</th>
                            <th>Date</th>
                            <th>Cashier</th>
                            <th>Subtotal</th>
                            <th>VAT</th>
                            <th>Discount</th>
                            <th>Total</th>
                            <th>Payment</th>
                            <th>Status</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($salesRows)): ?>

                            <tr>
                                <td
                                    colspan="9"
                                    class="reports-empty-cell"
                                >
                                    No sales found for this period.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($salesRows as $sale): ?>

                                <?php

                                $discountAmount =
                                    (float) $sale['subtotal']
                                    *
                                    (
                                        (float) $sale[
                                            'discount_percent'
                                        ]
                                        / 100
                                    );

                                ?>

                                <tr>

                                    <td>

                                        <a
                                            href="/pos/receipt.php?id=<?= (int) $sale['id'] ?>"
                                            class="reports-transaction-link"
                                        >
                                            <?= htmlspecialchars(
                                                (string) $sale[
                                                    'transaction_no'
                                                ]
                                            ) ?>
                                        </a>

                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            reportDateTime(
                                                (string) $sale[
                                                    'created_at'
                                                ]
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            reportCashierName($sale)
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= reportMoney(
                                            $sale['subtotal']
                                        ) ?>
                                    </td>

                                    <td>

                                        <?= reportMoney(
                                            $sale['tax_amount']
                                        ) ?>

                                        <small class="reports-muted">
                                            <?= number_format(
                                                (float) $sale[
                                                    'tax_rate'
                                                ],
                                                0
                                            ) ?>%
                                        </small>

                                    </td>

                                    <td>

                                        <?php if (
                                            (float) $sale[
                                                'discount_percent'
                                            ] > 0
                                        ): ?>

                                            -<?= reportMoney(
                                                $discountAmount
                                            ) ?>

                                            <small class="reports-muted">
                                                <?= htmlspecialchars(
                                                    (string) $sale[
                                                        'discount_type'
                                                    ]
                                                ) ?>
                                            </small>

                                        <?php else: ?>

                                            —

                                        <?php endif; ?>

                                    </td>

                                    <td>
                                        <strong>
                                            <?= reportMoney(
                                                $sale[
                                                    'total_amount'
                                                ]
                                            ) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            (string) $sale[
                                                'payment_method'
                                            ]
                                        ) ?>
                                    </td>

                                    <td>

                                        <span
                                            class="reports-status <?= strtolower(
                                                (string) $sale['status']
                                            ) ?>"
                                        >
                                            <?= htmlspecialchars(
                                                (string) $sale['status']
                                            ) ?>
                                        </span>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    <?php endif; ?>


    <?php if ($view === 'products'): ?>

        <section class="reports-card">

            <div class="reports-card-header">

                <div>
                    <h3>Product Sales</h3>
                    <p>
                        Revenue below is merchandise revenue
                        before transaction-level VAT and discounts.
                    </p>
                </div>

            </div>


            <div class="reports-table-wrap">

                <table class="reports-table">

                    <thead>

                        <tr>
                            <th>Product</th>
                            <th>Transactions</th>
                            <th>Units Sold</th>
                            <th>Product Revenue</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($productSalesRows)): ?>

                            <tr>
                                <td
                                    colspan="4"
                                    class="reports-empty-cell"
                                >
                                    No product sales found for this period.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach (
                                $productSalesRows
                                as $productRow
                            ): ?>

                                <tr>

                                    <td>
                                        <strong>
                                            <?= htmlspecialchars(
                                                (string) $productRow[
                                                    'product_name'
                                                ]
                                            ) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= (int) $productRow[
                                            'transaction_count'
                                        ] ?>
                                    </td>

                                    <td>
                                        <?= (int) $productRow[
                                            'units_sold'
                                        ] ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= reportMoney(
                                                $productRow[
                                                    'product_revenue'
                                                ]
                                            ) ?>
                                        </strong>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    <?php endif; ?>


    <?php if ($view === 'inventory'): ?>

        <div class="reports-inventory-stats">

            <div>
                <span>Products</span>
                <strong><?= count($inventoryRows) ?></strong>
            </div>

            <div>
                <span>Low Stock</span>
                <strong><?= $lowStockCount ?></strong>
            </div>

            <div>
                <span>Cost Value</span>
                <strong><?= reportMoney($inventoryCostValue) ?></strong>
            </div>

            <div>
                <span>Retail Value</span>
                <strong><?= reportMoney($inventoryRetailValue) ?></strong>
            </div>

        </div>


        <section class="reports-card">

            <div class="reports-card-header">

                <div>
                    <h3>Current Inventory</h3>
                    <p>
                        Current product-level stock and inventory valuation.
                    </p>
                </div>

            </div>


            <div class="reports-table-wrap">

                <table class="reports-table inventory">

                    <thead>

                        <tr>
                            <th>Product</th>
                            <th>Barcode</th>
                            <th>Category</th>
                            <th>Cost</th>
                            <th>Selling</th>
                            <th>Stock</th>
                            <th>Reorder</th>
                            <th>Cost Value</th>
                            <th>Retail Value</th>
                            <th>Status</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($inventoryRows)): ?>

                            <tr>
                                <td
                                    colspan="10"
                                    class="reports-empty-cell"
                                >
                                    No products found.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($inventoryRows as $product): ?>

                                <?php

                                $stock =
                                    (int) $product[
                                        'stock_quantity'
                                    ];

                                $reorder =
                                    (int) $product[
                                        'reorder_level'
                                    ];

                                $isLow =
                                    $stock <= $reorder;

                                ?>

                                <tr>

                                    <td>
                                        <strong>
                                            <?= htmlspecialchars(
                                                (string) $product[
                                                    'product_name'
                                                ]
                                            ) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            (string) $product[
                                                'barcode'
                                            ]
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            (string) (
                                                $product[
                                                    'category_name'
                                                ]
                                                ?? 'Uncategorized'
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= reportMoney(
                                            $product['cost_price']
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= reportMoney(
                                            $product['selling_price']
                                        ) ?>
                                    </td>

                                    <td>

                                        <strong
                                            class="<?= $isLow
                                                ? 'reports-low-stock'
                                                : ''
                                            ?>"
                                        >
                                            <?= $stock ?>
                                        </strong>

                                        <?php if ($isLow): ?>
                                            <small class="reports-muted">
                                                Low
                                            </small>
                                        <?php endif; ?>

                                    </td>

                                    <td>
                                        <?= $reorder ?>
                                    </td>

                                    <td>
                                        <?= reportMoney(
                                            $stock
                                            *
                                            (float) $product[
                                                'cost_price'
                                            ]
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= reportMoney(
                                            $stock
                                            *
                                            (float) $product[
                                                'selling_price'
                                            ]
                                        ) ?>
                                    </td>

                                    <td>

                                        <span
                                            class="reports-status <?= strtolower(
                                                (string) $product['status']
                                            ) ?>"
                                        >
                                            <?= htmlspecialchars(
                                                (string) $product['status']
                                            ) ?>
                                        </span>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    <?php endif; ?>


    <?php if ($view === 'restocks'): ?>

        <?php if ($selectedStockReceipt !== null): ?>

            <section class="reports-card">

                <div class="reports-card-header">

                    <div>
                        <h3>
                            Stock Receipt
                            <?= htmlspecialchars(
                                (string) $selectedStockReceipt[
                                    'receipt_no'
                                ]
                            ) ?>
                        </h3>

                        <p>
                            Supplier restocking receipt and received inventory details.
                        </p>
                    </div>

                    <a
                        href="<?= htmlspecialchars(
                            reportTabUrl(
                                'restocks',
                                $allTime,
                                $fromDate,
                                $toDate
                            )
                        ) ?>"
                        class="reports-filter-link no-print"
                    >
                        Back to Stock Receipts
                    </a>

                </div>


                <div class="reports-overview-grid">

                    <section class="reports-card">

                        <div class="reports-card-header">
                            <div>
                                <h3>Receipt Information</h3>
                            </div>
                        </div>

                        <div class="reports-summary-list">

                            <div>
                                <span>Receipt Number</span>
                                <strong>
                                    <?= htmlspecialchars(
                                        (string) $selectedStockReceipt[
                                            'receipt_no'
                                        ]
                                    ) ?>
                                </strong>
                            </div>

                            <div>
                                <span>Date Received</span>
                                <strong>
                                    <?= htmlspecialchars(
                                        reportDateTime(
                                            (string) $selectedStockReceipt[
                                                'created_at'
                                            ]
                                        )
                                    ) ?>
                                </strong>
                            </div>

                            <div>
                                <span>Supplier</span>
                                <strong>
                                    <?= htmlspecialchars(
                                        (string) $selectedStockReceipt[
                                            'supplier_name'
                                        ]
                                    ) ?>
                                </strong>
                            </div>

                            <div>
                                <span>Received By</span>
                                <strong>
                                    <?= htmlspecialchars(
                                        reportPrefixedUserName(
                                            $selectedStockReceipt,
                                            'receiver_'
                                        )
                                    ) ?>
                                </strong>
                            </div>

                            <div class="grand">
                                <span>Total Purchase Cost</span>
                                <strong>
                                    <?= reportMoney(
                                        $selectedStockReceipt[
                                            'total_cost'
                                        ]
                                    ) ?>
                                </strong>
                            </div>

                        </div>

                    </section>


                    <section class="reports-card">

                        <div class="reports-card-header">
                            <div>
                                <h3>Supplier Details</h3>
                            </div>
                        </div>

                        <div class="reports-summary-list">

                            <div>
                                <span>Contact Person</span>
                                <strong>
                                    <?= htmlspecialchars(
                                        trim(
                                            (string) (
                                                $selectedStockReceipt[
                                                    'contact_person'
                                                ]
                                                ?? ''
                                            )
                                        ) !== ''
                                            ? (string) $selectedStockReceipt[
                                                'contact_person'
                                            ]
                                            : '—'
                                    ) ?>
                                </strong>
                            </div>

                            <div>
                                <span>Phone</span>
                                <strong>
                                    <?= htmlspecialchars(
                                        trim(
                                            (string) (
                                                $selectedStockReceipt['phone']
                                                ?? ''
                                            )
                                        ) !== ''
                                            ? (string) $selectedStockReceipt[
                                                'phone'
                                            ]
                                            : '—'
                                    ) ?>
                                </strong>
                            </div>

                            <div>
                                <span>Email</span>
                                <strong>
                                    <?= htmlspecialchars(
                                        trim(
                                            (string) (
                                                $selectedStockReceipt['email']
                                                ?? ''
                                            )
                                        ) !== ''
                                            ? (string) $selectedStockReceipt[
                                                'email'
                                            ]
                                            : '—'
                                    ) ?>
                                </strong>
                            </div>

                            <div>
                                <span>Notes</span>
                                <strong>
                                    <?= htmlspecialchars(
                                        trim(
                                            (string) (
                                                $selectedStockReceipt['notes']
                                                ?? ''
                                            )
                                        ) !== ''
                                            ? (string) $selectedStockReceipt[
                                                'notes'
                                            ]
                                            : 'No notes'
                                    ) ?>
                                </strong>
                            </div>

                        </div>

                    </section>

                </div>


                <div class="reports-table-wrap">

                    <table class="reports-table">

                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Product Code</th>
                                <th>Color / Size</th>
                                <th>Variant SKU</th>
                                <th>Barcode</th>
                                <th>Quantity</th>
                                <th>Unit Cost</th>
                                <th>Line Total</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if (empty($selectedStockReceiptItems)): ?>

                                <tr>
                                    <td
                                        colspan="8"
                                        class="reports-empty-cell"
                                    >
                                        No receipt items found.
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php foreach (
                                    $selectedStockReceiptItems
                                    as $receiptItem
                                ): ?>

                                    <tr>

                                        <td>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    (string) $receiptItem[
                                                        'product_name'
                                                    ]
                                                ) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(
                                                (string) $receiptItem[
                                                    'product_code'
                                                ]
                                            ) ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    (string) $receiptItem[
                                                        'color'
                                                    ]
                                                ) ?>
                                            </strong>

                                            <small class="reports-muted">
                                                <?= htmlspecialchars(
                                                    (string) $receiptItem[
                                                        'size'
                                                    ]
                                                ) ?>
                                            </small>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(
                                                (string) $receiptItem['sku']
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(
                                                (string) $receiptItem[
                                                    'variant_barcode'
                                                ]
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= (int) $receiptItem[
                                                'quantity'
                                            ] ?>
                                        </td>

                                        <td>
                                            <?= reportMoney(
                                                $receiptItem['unit_cost']
                                            ) ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= reportMoney(
                                                    $receiptItem[
                                                        'line_total'
                                                    ]
                                                ) ?>
                                            </strong>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </section>

        <?php else: ?>

            <section class="reports-card">

                <div class="reports-card-header">

                    <div>
                        <h3>Stock Receipts</h3>
                        <p>
                            Permanent supplier restocking and inventory purchase history.
                        </p>
                    </div>

                </div>


                <div class="reports-table-wrap">

                    <table class="reports-table">

                        <thead>
                            <tr>
                                <th>Receipt</th>
                                <th>Date Received</th>
                                <th>Supplier</th>
                                <th>Lines</th>
                                <th>Units Received</th>
                                <th>Total Cost</th>
                                <th>Received By</th>
                                <th>Notes</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if (empty($stockReceiptRows)): ?>

                                <tr>
                                    <td
                                        colspan="8"
                                        class="reports-empty-cell"
                                    >
                                        No stock receipts found for this period.
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php foreach (
                                    $stockReceiptRows
                                    as $stockReceipt
                                ): ?>

                                    <tr>

                                        <td>
                                            <a
                                                href="<?= htmlspecialchars(
                                                    '/reports/?'
                                                    . http_build_query(
                                                        array_merge(
                                                            [
                                                                'view' =>
                                                                    'restocks',
                                                                'receipt_id' =>
                                                                    (int) $stockReceipt[
                                                                        'id'
                                                                    ]
                                                            ],
                                                            $allTime
                                                                ? [
                                                                    'range' =>
                                                                        'all'
                                                                ]
                                                                : [
                                                                    'from' =>
                                                                        $fromDate,
                                                                    'to' =>
                                                                        $toDate
                                                                ]
                                                        )
                                                    )
                                                ) ?>"
                                                class="reports-transaction-link"
                                            >
                                                <?= htmlspecialchars(
                                                    (string) $stockReceipt[
                                                        'receipt_no'
                                                    ]
                                                ) ?>
                                            </a>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(
                                                reportDateTime(
                                                    (string) $stockReceipt[
                                                        'created_at'
                                                    ]
                                                )
                                            ) ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    (string) $stockReceipt[
                                                        'supplier_name'
                                                    ]
                                                ) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= (int) $stockReceipt[
                                                'line_count'
                                            ] ?>
                                        </td>

                                        <td>
                                            <?= (int) $stockReceipt[
                                                'total_units'
                                            ] ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= reportMoney(
                                                    $stockReceipt[
                                                        'total_cost'
                                                    ]
                                                ) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(
                                                reportPrefixedUserName(
                                                    $stockReceipt,
                                                    'receiver_'
                                                )
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(
                                                trim(
                                                    (string) (
                                                        $stockReceipt['notes']
                                                        ?? ''
                                                    )
                                                ) !== ''
                                                    ? (string) $stockReceipt[
                                                        'notes'
                                                    ]
                                                    : '—'
                                            ) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </section>

        <?php endif; ?>

    <?php endif; ?>


    <?php if ($view === 'discounts'): ?>

        <div class="reports-inventory-stats">

            <div>
                <span>Discounted Sales</span>
                <strong><?= count($discountRows) ?></strong>
            </div>

            <div>
                <span>Total Discounts</span>
                <strong><?= reportMoney($totalDiscountUsed) ?></strong>
            </div>

        </div>


        <section class="reports-card">

            <div class="reports-card-header">

                <div>
                    <h3>PWD & Senior Discounts</h3>
                    <p>
                        Discount usage and recorded customer identification.
                    </p>
                </div>

            </div>


            <div class="reports-table-wrap">

                <table class="reports-table">

                    <thead>

                        <tr>
                            <th>Transaction</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Customer</th>
                            <th>ID Number</th>
                            <th>Subtotal</th>
                            <th>Discount</th>
                            <th>Total</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($discountRows)): ?>

                            <tr>
                                <td
                                    colspan="8"
                                    class="reports-empty-cell"
                                >
                                    No discounted transactions found.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($discountRows as $discountRow): ?>

                                <?php

                                $discountAmount =
                                    (float) $discountRow[
                                        'subtotal'
                                    ]
                                    *
                                    (
                                        (float) $discountRow[
                                            'discount_percent'
                                        ]
                                        / 100
                                    );

                                ?>

                                <tr>

                                    <td>

                                        <a
                                            href="/pos/receipt.php?id=<?= (int) $discountRow['id'] ?>"
                                            class="reports-transaction-link"
                                        >
                                            <?= htmlspecialchars(
                                                (string) $discountRow[
                                                    'transaction_no'
                                                ]
                                            ) ?>
                                        </a>

                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            reportDateTime(
                                                (string) $discountRow[
                                                    'created_at'
                                                ]
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            (string) $discountRow[
                                                'discount_type'
                                            ]
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            (string) (
                                                $discountRow[
                                                    'discount_customer_name'
                                                ]
                                                ?? '—'
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            (string) (
                                                $discountRow[
                                                    'discount_id_number'
                                                ]
                                                ?? '—'
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= reportMoney(
                                            $discountRow[
                                                'subtotal'
                                            ]
                                        ) ?>
                                    </td>

                                    <td>
                                        -<?= reportMoney(
                                            $discountAmount
                                        ) ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= reportMoney(
                                                $discountRow[
                                                    'total_amount'
                                                ]
                                            ) ?>
                                        </strong>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    <?php endif; ?>


    <?php if ($view === 'cashiers'): ?>

        <section class="reports-card">

            <div class="reports-card-header">

                <div>
                    <h3>Cashier Performance</h3>
                    <p>
                        Transactions and sales totals by cashier.
                    </p>
                </div>

            </div>


            <div class="reports-table-wrap">

                <table class="reports-table">

                    <thead>

                        <tr>
                            <th>Cashier</th>
                            <th>Transactions</th>
                            <th>Subtotal</th>
                            <th>VAT</th>
                            <th>Discounts</th>
                            <th>Total Sales</th>
                            <th>Average Sale</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($cashierRows)): ?>

                            <tr>
                                <td
                                    colspan="7"
                                    class="reports-empty-cell"
                                >
                                    No cashier sales found.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($cashierRows as $cashier): ?>

                                <tr>

                                    <td>
                                        <strong>
                                            <?= htmlspecialchars(
                                                reportCashierName(
                                                    $cashier
                                                )
                                            ) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= (int) $cashier[
                                            'transaction_count'
                                        ] ?>
                                    </td>

                                    <td>
                                        <?= reportMoney(
                                            $cashier['subtotal']
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= reportMoney(
                                            $cashier['tax_amount']
                                        ) ?>
                                    </td>

                                    <td>
                                        -<?= reportMoney(
                                            $cashier[
                                                'discount_amount'
                                            ]
                                        ) ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= reportMoney(
                                                $cashier[
                                                    'total_sales'
                                                ]
                                            ) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= reportMoney(
                                            $cashier[
                                                'average_sale'
                                            ]
                                        ) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    <?php endif; ?>


    <?php if ($view === 'suppliers'): ?>

        <section class="reports-card">

            <div class="reports-card-header">

                <div>
                    <h3>Supplier Summary</h3>
                    <p>
                        Supplier status, linked products and pricing.
                    </p>
                </div>

            </div>


            <div class="reports-table-wrap">

                <table class="reports-table">

                    <thead>

                        <tr>
                            <th>Supplier</th>
                            <th>Contact Person</th>
                            <th>Contact</th>
                            <th>Linked Products</th>
                            <th>Primary For</th>
                            <th>Avg. Supplier Price</th>
                            <th>Status</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($supplierRows)): ?>

                            <tr>
                                <td
                                    colspan="7"
                                    class="reports-empty-cell"
                                >
                                    No suppliers found.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($supplierRows as $supplier): ?>

                                <tr>

                                    <td>
                                        <strong>
                                            <?= htmlspecialchars(
                                                (string) $supplier[
                                                    'supplier_name'
                                                ]
                                            ) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            (string) (
                                                $supplier[
                                                    'contact_person'
                                                ]
                                                ?? '—'
                                            )
                                        ) ?>
                                    </td>

                                    <td>

                                        <div class="reports-contact">

                                            <span>
                                                <?= htmlspecialchars(
                                                    (string) (
                                                        $supplier[
                                                            'phone'
                                                        ]
                                                        ?? '—'
                                                    )
                                                ) ?>
                                            </span>

                                            <small>
                                                <?= htmlspecialchars(
                                                    (string) (
                                                        $supplier[
                                                            'email'
                                                        ]
                                                        ?? ''
                                                    )
                                                ) ?>
                                            </small>

                                        </div>

                                    </td>

                                    <td>
                                        <?= (int) $supplier[
                                            'linked_product_count'
                                        ] ?>
                                    </td>

                                    <td>
                                        <?= (int) $supplier[
                                            'primary_product_count'
                                        ] ?>
                                    </td>

                                    <td>
                                        <?= reportMoney(
                                            $supplier[
                                                'average_supplier_price'
                                            ]
                                        ) ?>
                                    </td>

                                    <td>

                                        <span
                                            class="reports-status <?= strtolower(
                                                (string) $supplier['status']
                                            ) ?>"
                                        >
                                            <?= htmlspecialchars(
                                                (string) $supplier['status']
                                            ) ?>
                                        </span>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    <?php endif; ?>


    <?php if ($view === 'expenses'): ?>

        <div class="reports-inventory-stats">

            <div>
                <span>Expense Entries</span>
                <strong><?= count($expenseRows) ?></strong>
            </div>

            <div>
                <span>Total Expenses</span>
                <strong><?= reportMoney($expenseTotal) ?></strong>
            </div>

            <div>
                <span>Expense Groups</span>
                <strong><?= count($expenseBreakdown) ?></strong>
            </div>

        </div>


        <section class="reports-card">

            <div class="reports-card-header">

                <div>
                    <h3>Expense Report</h3>
                    <p>
                        Recorded business expenses for the selected period.
                    </p>
                </div>

            </div>


            <div class="reports-table-wrap">

                <table class="reports-table">

                    <thead>

                        <tr>
                            <th>Date</th>
                            <th>Expense Type</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Amount</th>
                            <th>Recorded By</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($expenseRows)): ?>

                            <tr>
                                <td
                                    colspan="6"
                                    class="reports-empty-cell"
                                >
                                    No expenses found for this period.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($expenseRows as $expense): ?>

                                <tr>

                                    <td>
                                        <?= htmlspecialchars(
                                            reportDateOnly(
                                                (string) $expense[
                                                    'expense_date'
                                                ]
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= htmlspecialchars(
                                                (string) $expense[
                                                    'expense_type'
                                                ]
                                            ) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            trim(
                                                (string) (
                                                    $expense['category']
                                                    ?? ''
                                                )
                                            ) !== ''
                                                ? (string) $expense['category']
                                                : '—'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            (string) $expense[
                                                'description'
                                            ]
                                        ) ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= reportMoney(
                                                $expense['amount']
                                            ) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            reportPrefixedUserName(
                                                $expense,
                                                'recorder_'
                                            )
                                        ) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    <?php endif; ?>


    <?php if ($view === 'payroll'): ?>

        <div class="reports-inventory-stats">

            <div>
                <span>Payroll Entries</span>
                <strong><?= count($payrollRows) ?></strong>
            </div>

            <div>
                <span>Hours Worked</span>
                <strong>
                    <?= number_format($payrollHoursTotal, 2) ?>
                </strong>
            </div>

            <div>
                <span>Gross Payroll</span>
                <strong><?= reportMoney($payrollGrossTotal) ?></strong>
            </div>

            <div>
                <span>Deductions</span>
                <strong><?= reportMoney($payrollDeductionTotal) ?></strong>
            </div>

            <div>
                <span>Net Payroll</span>
                <strong><?= reportMoney($payrollNetTotal) ?></strong>
            </div>

        </div>


        <section class="reports-card">

            <div class="reports-card-header">

                <div>
                    <h3>Payroll Report</h3>
                    <p>
                        Payroll entries are filtered by payroll period end date.
                    </p>
                </div>

            </div>


            <div class="reports-table-wrap">

                <table class="reports-table payroll">

                    <thead>

                        <tr>
                            <th>Employee</th>
                            <th>Employee Code</th>
                            <th>Position</th>
                            <th>Pay Type</th>
                            <th>Period</th>
                            <th>Hours</th>
                            <th>Gross Pay</th>
                            <th>Deductions</th>
                            <th>Net Pay</th>
                            <th>Processed By</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($payrollRows)): ?>

                            <tr>
                                <td
                                    colspan="10"
                                    class="reports-empty-cell"
                                >
                                    No payroll entries found for this period.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($payrollRows as $payroll): ?>

                                <tr>

                                    <td>
                                        <strong>
                                            <?= htmlspecialchars(
                                                reportEmployeeName(
                                                    $payroll
                                                )
                                            ) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            (string) $payroll[
                                                'employee_code'
                                            ]
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            (string) $payroll[
                                                'position'
                                            ]
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            (string) $payroll[
                                                'pay_type'
                                            ]
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            reportDateOnly(
                                                (string) $payroll[
                                                    'period_start'
                                                ]
                                            )
                                        ) ?>
                                        —
                                        <?= htmlspecialchars(
                                            reportDateOnly(
                                                (string) $payroll[
                                                    'period_end'
                                                ]
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= number_format(
                                            (float) $payroll[
                                                'hours_worked'
                                            ],
                                            2
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= reportMoney(
                                            $payroll['gross_pay']
                                        ) ?>
                                    </td>

                                    <td>

                                        <?= reportMoney(
                                            $payroll['deductions']
                                        ) ?>

                                        <?php if (
                                            trim(
                                                (string) (
                                                    $payroll[
                                                        'deduction_notes'
                                                    ]
                                                    ?? ''
                                                )
                                            ) !== ''
                                        ): ?>

                                            <small class="reports-muted">
                                                <?= htmlspecialchars(
                                                    (string) $payroll[
                                                        'deduction_notes'
                                                    ]
                                                ) ?>
                                            </small>

                                        <?php endif; ?>

                                    </td>

                                    <td>
                                        <strong>
                                            <?= reportMoney(
                                                $payroll['net_pay']
                                            ) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            reportPrefixedUserName(
                                                $payroll,
                                                'processor_'
                                            )
                                        ) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    <?php endif; ?>


    <?php if ($view === 'financials'): ?>

        <div class="reports-financial-grid">

            <div class="reports-financial-card positive">

                <span>Net Sales</span>

                <strong>
                    <?= reportMoney($selectedSalesTotal) ?>
                </strong>

                <small>
                    Completed transaction totals after VAT and discounts.
                </small>

            </div>


            <div class="reports-financial-card">

                <span>Expenses</span>

                <strong>
                    -<?= reportMoney($expenseTotal) ?>
                </strong>

                <small>
                    Recorded expenses using expense_date.
                </small>

            </div>


            <div class="reports-financial-card">

                <span>Gross Payroll</span>

                <strong>
                    -<?= reportMoney($payrollGrossTotal) ?>
                </strong>

                <small>
                    Full payroll cost before employee deductions.
                </small>

            </div>


            <div
                class="reports-financial-card result <?= $estimatedOperatingResult < 0
                    ? 'negative'
                    : 'positive'
                ?>"
            >

                <span>Estimated Operating Result</span>

                <strong>
                    <?= reportMoney(
                        $estimatedOperatingResult
                    ) ?>
                </strong>

                <small>
                    Net Sales − Expenses − Gross Payroll
                </small>

            </div>

        </div>


        <div class="reports-overview-grid">

            <section class="reports-card">

                <div class="reports-card-header">

                    <div>
                        <h3>Expense Breakdown</h3>
                        <p>
                            Expenses grouped by category, or type when no category is set.
                        </p>
                    </div>

                </div>


                <?php if (empty($expenseBreakdown)): ?>

                    <div class="reports-empty">

                        <span class="material-symbols-rounded">
                            receipt_long
                        </span>

                        <strong>
                            No expense data
                        </strong>

                    </div>

                <?php else: ?>

                    <div class="reports-mini-list">

                        <?php foreach (
                            $expenseBreakdown
                            as $label => $amount
                        ): ?>

                            <div>
                                <span>
                                    <?= htmlspecialchars($label) ?>
                                </span>

                                <strong>
                                    <?= reportMoney($amount) ?>
                                </strong>
                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </section>


            <section class="reports-card">

                <div class="reports-card-header">

                    <div>
                        <h3>Payroll Summary</h3>
                        <p>
                            Payroll totals for the selected period.
                        </p>
                    </div>

                </div>


                <div class="reports-summary-list">

                    <div>
                        <span>Gross Pay</span>
                        <strong>
                            <?= reportMoney($payrollGrossTotal) ?>
                        </strong>
                    </div>

                    <div>
                        <span>Deductions</span>
                        <strong>
                            <?= reportMoney($payrollDeductionTotal) ?>
                        </strong>
                    </div>

                    <div>
                        <span>Net Pay</span>
                        <strong>
                            <?= reportMoney($payrollNetTotal) ?>
                        </strong>
                    </div>

                    <div>
                        <span>Hours Worked</span>
                        <strong>
                            <?= number_format(
                                $payrollHoursTotal,
                                2
                            ) ?>
                        </strong>
                    </div>

                </div>

            </section>

        </div>


        <div class="reports-financial-note">

            <span class="material-symbols-rounded">
                info
            </span>

            <p>
                Estimated Operating Result is not the same as formal accounting net profit.
                It currently subtracts recorded expenses and gross payroll from completed
                sales. Historical cost of goods sold is not deducted because sale_items
                does not store a cost-price snapshot for each transaction.
            </p>

        </div>

    <?php endif; ?>


    <div class="reports-footnote">

        <span class="material-symbols-rounded">
            info
        </span>

        <p>
            Sales reports use completed transactions only.
            Product revenue is calculated from sale item line totals
            before transaction-level VAT and PWD/Senior discounts.
            Inventory and supplier reports show current values and
            are not limited by the selected sales date range. Stock receipt
            reports use the receipt creation date. Expense reports use
            expense_date, while payroll reports use period_end.
        </p>

    </div>


</div>


<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';

?>
