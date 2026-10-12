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

require_once __DIR__
    . '/../../app/helpers/prices.php';

$pageTitle = 'Prices';
$currentPage = 'prices';


/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


/*
|--------------------------------------------------------------------------
| PRICE CHANGES
|--------------------------------------------------------------------------
|
| Sale       a lower price for chosen colors / sizes between two dates
| Markdown   a permanent new regular price for every color / size
| Clearance  a lower price for chosen colors / sizes with no end date; a
|            clearance color / size becomes Inactive when it sells out
|
| POS charges the price worked out by app/helpers/prices.php. PWD / Senior
| customers get the higher of the sale price and 20 % off the regular
| price, never both. Nothing is ever deleted: stopped and ended prices stay
| in the history below.
|
*/

const PRICE_CHANGE_TYPES = ['Sale', 'Markdown', 'Clearance'];

const PRICE_REASON_SUGGESTIONS = [
    'Payday sale',
    'Holiday sale',
    'End of season',
    'Slow-moving item',
    'Slightly damaged',
    'Last pieces'
];


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function pricesRedirect(): never
{
    header('Location: /prices/');
    exit;
}

function pricesFlash(string $type, string $message): void
{
    $_SESSION[$type === 'success' ? 'prices_success' : 'prices_error'] = $message;
}

function pricesMoney(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function pricesIsValidDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function pricesDisplayDate(?string $date): string
{
    if ($date === null || $date === '') {
        return '';
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed ? $parsed->format('M d, Y') : $date;
}

/* UTC from the database -> Asia/Manila for display. */
function pricesDisplayTimestamp(?string $utcDate): string
{
    if ($utcDate === null || $utcDate === '') {
        return '';
    }

    $date = new DateTime($utcDate, new DateTimeZone('UTC'));
    $date->setTimezone(new DateTimeZone('Asia/Manila'));

    return $date->format('M d, Y · g:i A');
}

function pricesPercentOff(float $regular, float $newPrice): string
{
    if ($regular <= 0) {
        return '';
    }

    return number_format((1 - $newPrice / $regular) * 100, 0) . '% off';
}

function pricesPersonName(?string $firstName, ?string $lastName): string
{
    return trim((string) $firstName . ' ' . (string) $lastName) ?: '—';
}

function pricesWriteLog(PDO $pdo, string $action, ?int $recordId, string $details): void
{
    $pdo->prepare("
        INSERT INTO system_logs (
            user_id,
            action,
            module,
            record_type,
            record_id,
            details
        )
        VALUES (?, ?, 'Prices', 'PriceChange', ?, ?)
    ")->execute([
        $_SESSION['user_id'] ?? null,
        $action,
        $recordId,
        $details
    ]);
}


$pricesReady = pricesReady($pdo);
$today = pricesToday();


/*
|--------------------------------------------------------------------------
| HANDLE POST REQUESTS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = trim((string) ($_POST['action'] ?? ''));
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if (
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $submittedToken)
    ) {
        pricesFlash('error', 'Your session expired. Refresh the page and try again.');
        pricesRedirect();
    }

    if (!$pricesReady) {
        pricesFlash('error', 'Prices need a database update first: run tools\\migrate_price_changes.php.');
        pricesRedirect();
    }


    /* NEW SALE / MARKDOWN / CLEARANCE */

    if ($action === 'save_price_change') {

        $productId = (int) ($_POST['product_id'] ?? 0);
        $type = trim((string) ($_POST['change_type'] ?? ''));
        $priceMode = (string) ($_POST['price_mode'] ?? 'price');
        $priceInput = trim((string) ($_POST['price_value'] ?? ''));
        $startsOn = trim((string) ($_POST['starts_on'] ?? ''));
        $endsOn = trim((string) ($_POST['ends_on'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $variantIds = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) ($_POST['variant_ids'] ?? [])
        ))));

        $productStatement = $pdo->prepare("
            SELECT id, product_code, product_name, selling_price, cost_price, status
            FROM products
            WHERE id = ?
            LIMIT 1
        ");
        $productStatement->execute([$productId]);
        $product = $productStatement->fetch() ?: null;

        $regular = $product ? round((float) $product['selling_price'], 2) : 0.0;
        $inputNumber = is_numeric($priceInput) ? (float) $priceInput : -1.0;

        $newPrice = $priceMode === 'percent'
            ? round($regular * (1 - $inputNumber / 100), 2)
            : round($inputNumber, 2);

        if ($type === 'Markdown') {
            $startsOn = $today;
            $endsOn = '';
        }

        if ($type === 'Clearance') {
            $endsOn = '';
        }

        $error = null;

        if ($product === null || $product['status'] !== 'Active') {
            $error = 'Choose an active product.';
        } elseif (!in_array($type, PRICE_CHANGE_TYPES, true)) {
            $error = 'Choose Sale, Markdown or Clearance.';
        } elseif ($priceMode === 'percent' && ($inputNumber < 1 || $inputNumber > 95)) {
            $error = 'The percent off must be between 1 and 95.';
        } elseif ($newPrice <= 0) {
            $error = 'Enter a new price greater than zero.';
        } elseif ($newPrice >= $regular) {
            $error = 'The new price must be lower than the regular price (' . pricesMoney($regular) . ').';
        } elseif ($reason === '') {
            $error = 'Enter a short reason, for example "End of season".';
        } elseif (strlen($reason) > 200) {
            $error = 'Keep the reason under 200 characters.';
        } elseif (!pricesIsValidDate($startsOn)) {
            $error = 'Enter a valid start date.';
        } elseif ($startsOn < $today) {
            $error = 'The start date cannot be in the past.';
        } elseif ($type === 'Sale' && !pricesIsValidDate($endsOn)) {
            $error = 'A sale needs an end date.';
        } elseif ($type === 'Sale' && $endsOn < $startsOn) {
            $error = 'The end date cannot be before the start date.';
        } elseif ($type !== 'Markdown' && $variantIds === []) {
            $error = 'Choose at least one color / size.';
        }

        /* Colors / sizes: must belong to the product, be active, and have no
           other Sale / Clearance in the same dates. */
        $variants = [];

        if ($error === null && $type !== 'Markdown') {

            $placeholders = implode(',', array_fill(0, count($variantIds), '?'));

            $variantStatement = $pdo->prepare("
                SELECT id, color, size, status
                FROM product_variants
                WHERE product_id = ?
                  AND id IN ({$placeholders})
            ");
            $variantStatement->execute(array_merge([$productId], $variantIds));

            foreach ($variantStatement->fetchAll() as $variant) {
                $variants[(int) $variant['id']] = $variant;
            }

            if (count($variants) !== count($variantIds)) {
                $error = 'One of the colors / sizes does not belong to this product. Refresh and try again.';
            } else {
                foreach ($variants as $variant) {
                    if ($variant['status'] !== 'Active') {
                        $error = $variant['color'] . ' / ' . $variant['size'] . ' is inactive.';
                        break;
                    }
                }
            }

            if ($error === null) {

                $overlapStatement = $pdo->prepare("
                    SELECT pc.change_type, pc.starts_on, pc.ends_on, pv.color, pv.size
                    FROM price_changes pc
                    INNER JOIN product_variants pv
                        ON pv.id = pc.variant_id
                    WHERE pc.status = 'Active'
                      AND pc.change_type IN ('Sale', 'Clearance')
                      AND pc.variant_id IN ({$placeholders})
                      AND pc.starts_on <= ?
                      AND (pc.ends_on IS NULL OR pc.ends_on >= ?)
                    LIMIT 1
                ");
                $overlapStatement->execute(array_merge(
                    $variantIds,
                    [$endsOn !== '' ? $endsOn : '9999-12-31', $startsOn]
                ));

                $overlap = $overlapStatement->fetch();

                if ($overlap) {
                    $error = $overlap['color'] . ' / ' . $overlap['size'] . ' already has a '
                        . $overlap['change_type'] . ' price from ' . pricesDisplayDate($overlap['starts_on'])
                        . ($overlap['ends_on'] ? ' to ' . pricesDisplayDate($overlap['ends_on']) : ' (no end date)')
                        . '. Stop it first, or choose other dates.';
                }
            }
        }

        if ($error !== null) {
            pricesFlash('error', $error);
            pricesRedirect();
        }

        $percentText = pricesPercentOff($regular, $newPrice);
        $belowCost = (float) $product['cost_price'] > 0 && $newPrice < (float) $product['cost_price'];

        try {

            $pdo->beginTransaction();

            $insert = $pdo->prepare("
                INSERT INTO price_changes (
                    product_id,
                    variant_id,
                    change_type,
                    regular_price,
                    new_price,
                    starts_on,
                    ends_on,
                    reason,
                    status,
                    created_by
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if ($type === 'Markdown') {

                $insert->execute([
                    $productId,
                    null,
                    'Markdown',
                    $regular,
                    $newPrice,
                    $today,
                    null,
                    $reason,
                    'Applied',
                    $_SESSION['user_id'] ?? null
                ]);

                $changeId = (int) $pdo->lastInsertId();

                $pdo->prepare("
                    UPDATE products
                    SET selling_price = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ")->execute([$newPrice, $productId]);

                pricesWriteLog(
                    $pdo,
                    'MARKDOWN_PRODUCT',
                    $changeId,
                    'Marked down ' . $product['product_name'] . ' (' . $product['product_code'] . ') from '
                    . pricesMoney($regular) . ' to ' . pricesMoney($newPrice) . ' (' . $percentText . ')'
                    . ($belowCost ? ', below cost' : '') . ' — ' . $reason
                );

                $message = $product['product_name'] . ' now costs ' . pricesMoney($newPrice) . ' (was ' . pricesMoney($regular) . ').';

            } else {

                $firstId = null;
                $variantNames = [];

                foreach ($variantIds as $variantId) {

                    $insert->execute([
                        $productId,
                        $variantId,
                        $type,
                        $regular,
                        $newPrice,
                        $startsOn,
                        $endsOn !== '' ? $endsOn : null,
                        $reason,
                        'Active',
                        $_SESSION['user_id'] ?? null
                    ]);

                    $firstId ??= (int) $pdo->lastInsertId();
                    $variantNames[] = $variants[$variantId]['color'] . ' / ' . $variants[$variantId]['size'];
                }

                $dates = $type === 'Sale'
                    ? pricesDisplayDate($startsOn) . ' to ' . pricesDisplayDate($endsOn)
                    : 'from ' . pricesDisplayDate($startsOn) . ', no end date';

                pricesWriteLog(
                    $pdo,
                    'ADD_PRICE_CHANGE',
                    $firstId,
                    $type . ' price ' . pricesMoney($newPrice) . ' (was ' . pricesMoney($regular) . ', ' . $percentText . ')'
                    . ($belowCost ? ', below cost' : '') . ' for ' . $product['product_name'] . ': '
                    . implode(', ', $variantNames) . '; ' . $dates . ' — ' . $reason
                );

                $message = $type . ' price saved for ' . count($variantIds)
                    . (count($variantIds) === 1 ? ' color / size' : ' colors / sizes') . ' of '
                    . $product['product_name'] . '.';
            }

            $pdo->commit();

            pricesFlash('success', $message);

        } catch (Throwable $exception) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Prices save failed: ' . $exception->getMessage());
            pricesFlash('error', 'The price could not be saved. Please try again.');
        }

        pricesRedirect();
    }


    /* STOP A SALE / CLEARANCE (running or scheduled) */

    if ($action === 'stop_price_change') {

        $changeId = (int) ($_POST['change_id'] ?? 0);

        $changeStatement = $pdo->prepare("
            SELECT pc.id, pc.change_type, pc.new_price, p.product_name, pv.color, pv.size
            FROM price_changes pc
            INNER JOIN products p
                ON p.id = pc.product_id
            LEFT JOIN product_variants pv
                ON pv.id = pc.variant_id
            WHERE pc.id = ?
              AND pc.status = 'Active'
              AND pc.change_type IN ('Sale', 'Clearance')
            LIMIT 1
        ");
        $changeStatement->execute([$changeId]);
        $change = $changeStatement->fetch();

        if (!$change) {
            pricesFlash('error', 'That price is no longer running.');
            pricesRedirect();
        }

        try {

            $pdo->beginTransaction();

            $pdo->prepare("
                UPDATE price_changes
                SET status = 'Stopped',
                    stopped_by = ?,
                    stopped_at = CURRENT_TIMESTAMP,
                    stop_reason = 'Stopped early'
                WHERE id = ?
                  AND status = 'Active'
            ")->execute([$_SESSION['user_id'] ?? null, $changeId]);

            pricesWriteLog(
                $pdo,
                'STOP_PRICE_CHANGE',
                $changeId,
                'Stopped the ' . $change['change_type'] . ' price ' . pricesMoney((float) $change['new_price'])
                . ' of ' . $change['product_name'] . ' — ' . $change['color'] . ' / ' . $change['size']
            );

            $pdo->commit();

            pricesFlash(
                'success',
                $change['product_name'] . ' — ' . $change['color'] . ' / ' . $change['size']
                . ' is back to its regular price.'
            );

        } catch (Throwable $exception) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Prices stop failed: ' . $exception->getMessage());
            pricesFlash('error', 'The price could not be stopped. Please try again.');
        }

        pricesRedirect();
    }

    pricesFlash('error', 'Unknown action.');
    pricesRedirect();
}


/*
|--------------------------------------------------------------------------
| FLASH MESSAGES
|--------------------------------------------------------------------------
*/

$pricesSuccess = $_SESSION['prices_success'] ?? '';
$pricesError = $_SESSION['prices_error'] ?? '';

unset(
    $_SESSION['prices_success'],
    $_SESSION['prices_error']
);


/*
|--------------------------------------------------------------------------
| PRODUCTS FOR THE FORM
|--------------------------------------------------------------------------
*/

$formProducts = [];
$running = [];
$scheduled = [];
$history = [];

if ($pricesReady) {

    $productRows = $pdo->query("
        SELECT id, product_code, product_name, selling_price, cost_price
        FROM products
        WHERE status = 'Active'
        ORDER BY product_name ASC
    ")->fetchAll();

    $variantRows = $pdo->query("
        SELECT id, product_id, color, size, stock_quantity
        FROM product_variants
        WHERE status = 'Active'
        ORDER BY product_id ASC, color ASC, id ASC
    ")->fetchAll();

    /* Sale / Clearance that is running now or starts later, per variant. */
    $activeRows = $pdo->prepare("
        SELECT variant_id, change_type, new_price, starts_on, ends_on
        FROM price_changes
        WHERE status = 'Active'
          AND change_type IN ('Sale', 'Clearance')
          AND (ends_on IS NULL OR ends_on >= ?)
        ORDER BY starts_on ASC
    ");
    $activeRows->execute([$today]);

    $activeByVariant = [];

    foreach ($activeRows->fetchAll() as $row) {
        $activeByVariant[(int) $row['variant_id']][] =
            $row['change_type'] . ' ' . pricesMoney((float) $row['new_price']) . ' · '
            . ($row['starts_on'] > $today ? 'from ' . pricesDisplayDate($row['starts_on']) . ' ' : '')
            . ($row['ends_on'] ? 'until ' . pricesDisplayDate($row['ends_on']) : 'no end date');
    }

    $variantsByProduct = [];

    foreach ($variantRows as $variant) {
        $variantsByProduct[(int) $variant['product_id']][] = [
            'id' => (int) $variant['id'],
            'color' => (string) $variant['color'],
            'size' => (string) $variant['size'],
            'stock' => (int) $variant['stock_quantity'],
            'active' => implode('; ', $activeByVariant[(int) $variant['id']] ?? [])
        ];
    }

    foreach ($productRows as $product) {
        $formProducts[] = [
            'id' => (int) $product['id'],
            'code' => (string) ($product['product_code'] ?? ''),
            'name' => (string) $product['product_name'],
            'price' => round((float) $product['selling_price'], 2),
            'cost' => round((float) $product['cost_price'], 2),
            'variants' => $variantsByProduct[(int) $product['id']] ?? []
        ];
    }


    /*
    |----------------------------------------------------------------------
    | RUNNING, SCHEDULED AND HISTORY
    |----------------------------------------------------------------------
    */

    $changeStatement = $pdo->query("
        SELECT
            pc.*,
            p.product_name,
            p.product_code,
            p.selling_price AS current_price,
            pv.color,
            pv.size,
            pv.stock_quantity,
            cu.first_name AS created_first_name,
            cu.last_name AS created_last_name,
            su.first_name AS stopped_first_name,
            su.last_name AS stopped_last_name
        FROM price_changes pc
        INNER JOIN products p
            ON p.id = pc.product_id
        LEFT JOIN product_variants pv
            ON pv.id = pc.variant_id
        LEFT JOIN users cu
            ON cu.id = pc.created_by
        LEFT JOIN users su
            ON su.id = pc.stopped_by
        ORDER BY pc.created_at DESC, pc.id DESC
        LIMIT 500
    ");

    foreach ($changeStatement->fetchAll() as $change) {

        $isActive = $change['status'] === 'Active';
        $ended = $isActive && $change['ends_on'] !== null && $change['ends_on'] < $today;

        if ($isActive && !$ended && $change['starts_on'] > $today) {
            $scheduled[] = $change;
        } elseif ($isActive && !$ended) {
            $running[] = $change;
        } else {
            $change['history_status'] = match (true) {
                $change['status'] === 'Applied' => 'Applied',
                $change['stop_reason'] === 'Sold out' => 'Sold out',
                $change['status'] === 'Stopped' => 'Stopped early',
                default => 'Ended'
            };
            $history[] = $change;
        }
    }
}

$runningSales = count(array_filter($running, static fn ($change) => $change['change_type'] === 'Sale'));
$runningClearance = count($running) - $runningSales;
$markdownCount = count(array_filter($history, static fn ($change) => $change['change_type'] === 'Markdown'));


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

<link rel="stylesheet" href="/assets/css/prices.css?v=20261012-2">

<div class="prices-page">

    <div class="prices-top">
        <div>
            <div class="prices-eyebrow">PRICING</div>
            <h2>Sale, Markdown &amp; Clearance</h2>
            <p>Lower prices for a while (Sale), for good (Markdown), or until the last pieces are gone (Clearance).</p>
        </div>
        <?php if ($pricesReady): ?>
            <button type="button" class="prices-primary-button" id="openPriceChange">
                <span class="material-symbols-rounded">sell</span>
                New Price Change
            </button>
        <?php endif; ?>
    </div>

    <?php if ($pricesSuccess !== ''): ?>
        <div class="prices-alert success" role="status">
            <span class="material-symbols-rounded">check_circle</span>
            <?= htmlspecialchars($pricesSuccess) ?>
        </div>
    <?php endif; ?>

    <?php if ($pricesError !== ''): ?>
        <div class="prices-alert error" role="alert">
            <span class="material-symbols-rounded">error</span>
            <?= htmlspecialchars($pricesError) ?>
        </div>
    <?php endif; ?>

    <?php if (!$pricesReady): ?>

        <div class="prices-card">
            <div class="prices-empty">
                <span class="material-symbols-rounded">database</span>
                <strong>Prices need a database update</strong>
                <p>Run <code>C:\php\php.exe tools\migrate_price_changes.php</code> once (it makes a backup first). Until then POS sells every item at its regular price.</p>
            </div>
        </div>

    <?php else: ?>

        <!-- STATS -->

        <div class="prices-stats">

            <div class="prices-stat-card">
                <div class="prices-stat-icon">
                    <span class="material-symbols-rounded">sell</span>
                </div>
                <div>
                    <span>On Sale Now</span>
                    <strong><?= $runningSales ?></strong>
                </div>
            </div>

            <div class="prices-stat-card">
                <div class="prices-stat-icon clearance">
                    <span class="material-symbols-rounded">local_offer</span>
                </div>
                <div>
                    <span>On Clearance</span>
                    <strong><?= $runningClearance ?></strong>
                </div>
            </div>

            <div class="prices-stat-card">
                <div class="prices-stat-icon">
                    <span class="material-symbols-rounded">event_upcoming</span>
                </div>
                <div>
                    <span>Starting Later</span>
                    <strong><?= count($scheduled) ?></strong>
                </div>
            </div>

            <div class="prices-stat-card">
                <div class="prices-stat-icon">
                    <span class="material-symbols-rounded">trending_down</span>
                </div>
                <div>
                    <span>Markdowns</span>
                    <strong><?= $markdownCount ?></strong>
                </div>
            </div>

        </div>


        <!-- RUNNING + SCHEDULED -->

        <?php foreach ([
            ['Running Now', 'Colors / sizes that POS sells at a lower price today.', $running, 'No sale or clearance prices are running.'],
            ['Starting Later', 'Saved sale prices that start on a later date.', $scheduled, 'Nothing is scheduled.']
        ] as [$sectionTitle, $sectionText, $sectionRows, $emptyText]): ?>

            <div class="prices-card">

                <div class="prices-card-header">
                    <div>
                        <h3><?= htmlspecialchars($sectionTitle) ?></h3>
                        <p><?= htmlspecialchars($sectionText) ?></p>
                    </div>
                </div>

                <div class="prices-table-wrap">
                    <table class="prices-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Color / Size</th>
                                <th>Type</th>
                                <th class="amount">Price</th>
                                <th>Dates</th>
                                <th>Reason</th>
                                <th>Set By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>

                            <?php if ($sectionRows === []): ?>
                                <tr>
                                    <td colspan="8" class="prices-empty-cell">
                                        <div class="prices-empty compact">
                                            <span class="material-symbols-rounded">sell</span>
                                            <strong><?= htmlspecialchars($emptyText) ?></strong>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($sectionRows as $change): ?>
                                <?php
                                $changeId = (int) $change['id'];
                                $itemName = $change['product_name'] . ' — ' . $change['color'] . ' / ' . $change['size'];
                                ?>
                                <tr>
                                    <td>
                                        <strong class="prices-product-name"><?= htmlspecialchars($change['product_name']) ?></strong>
                                        <small class="prices-muted"><?= htmlspecialchars((string) $change['product_code']) ?></small>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($change['color'] . ' / ' . $change['size']) ?>
                                        <small class="prices-muted"><?= (int) $change['stock_quantity'] ?> in stock</small>
                                    </td>
                                    <td>
                                        <span class="prices-type <?= strtolower($change['change_type']) ?>">
                                            <?= htmlspecialchars($change['change_type']) ?>
                                        </span>
                                    </td>
                                    <td class="amount">
                                        <s class="prices-muted"><?= pricesMoney((float) $change['current_price']) ?></s>
                                        <strong><?= pricesMoney((float) $change['new_price']) ?></strong>
                                        <small class="prices-muted"><?= htmlspecialchars(pricesPercentOff((float) $change['current_price'], (float) $change['new_price'])) ?></small>
                                    </td>
                                    <td class="prices-dates">
                                        <?= htmlspecialchars(pricesDisplayDate($change['starts_on'])) ?>
                                        <small class="prices-muted">
                                            <?= $change['ends_on']
                                                ? 'until ' . htmlspecialchars(pricesDisplayDate($change['ends_on']))
                                                : 'no end date'
                                            ?>
                                        </small>
                                    </td>
                                    <td class="prices-reason"><?= htmlspecialchars($change['reason']) ?></td>
                                    <td><?= htmlspecialchars(pricesPersonName($change['created_first_name'], $change['created_last_name'])) ?></td>
                                    <td>
                                        <form method="post" class="prices-inline-form" id="pricesStopForm-<?= $changeId ?>">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="stop_price_change">
                                            <input type="hidden" name="change_id" value="<?= $changeId ?>">
                                            <button
                                                type="button"
                                                class="prices-secondary-button small"
                                                data-confirm
                                                data-confirm-title="Stop this <?= strtolower($change['change_type']) ?> price?"
                                                data-confirm-message="<?= htmlspecialchars(
                                                    $itemName . ' goes back to its regular price '
                                                    . pricesMoney((float) $change['current_price']) . ' right away. It stays in the history.'
                                                ) ?>"
                                                data-confirm-label="Stop Price"
                                                data-confirm-icon="block"
                                                data-confirm-form="pricesStopForm-<?= $changeId ?>"
                                            >
                                                Stop
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                        </tbody>
                    </table>
                </div>

            </div>

        <?php endforeach; ?>


        <!-- HISTORY -->

        <div class="prices-card">

            <div class="prices-card-header">
                <div>
                    <h3>History</h3>
                    <p>Markdowns and sale / clearance prices that ended. Nothing is deleted.</p>
                </div>
            </div>

            <div class="prices-table-wrap">
                <table class="prices-table">
                    <thead>
                        <tr>
                            <th>Saved</th>
                            <th>Product</th>
                            <th>Color / Size</th>
                            <th>Type</th>
                            <th class="amount">Was → New</th>
                            <th>Dates</th>
                            <th>Status</th>
                            <th>Reason</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>

                        <?php if ($history === []): ?>
                            <tr>
                                <td colspan="9" class="prices-empty-cell">
                                    <div class="prices-empty compact">
                                        <span class="material-symbols-rounded">history</span>
                                        <strong>No history yet</strong>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($history as $change): ?>
                            <tr>
                                <td class="prices-dates"><?= htmlspecialchars(pricesDisplayTimestamp($change['created_at'])) ?></td>
                                <td>
                                    <strong class="prices-product-name"><?= htmlspecialchars($change['product_name']) ?></strong>
                                    <small class="prices-muted"><?= htmlspecialchars((string) $change['product_code']) ?></small>
                                </td>
                                <td>
                                    <?= $change['variant_id'] === null
                                        ? 'Every color / size'
                                        : htmlspecialchars($change['color'] . ' / ' . $change['size'])
                                    ?>
                                </td>
                                <td>
                                    <span class="prices-type <?= strtolower($change['change_type']) ?>">
                                        <?= htmlspecialchars($change['change_type']) ?>
                                    </span>
                                </td>
                                <td class="amount">
                                    <s class="prices-muted"><?= pricesMoney((float) $change['regular_price']) ?></s>
                                    <strong><?= pricesMoney((float) $change['new_price']) ?></strong>
                                </td>
                                <td class="prices-dates">
                                    <?php if ($change['change_type'] === 'Markdown'): ?>
                                        From <?= htmlspecialchars(pricesDisplayDate($change['starts_on'])) ?>
                                    <?php else: ?>
                                        <?= htmlspecialchars(pricesDisplayDate($change['starts_on'])) ?>
                                        <small class="prices-muted">
                                            <?= $change['ends_on']
                                                ? 'until ' . htmlspecialchars(pricesDisplayDate($change['ends_on']))
                                                : 'no end date'
                                            ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="prices-status"><?= htmlspecialchars($change['history_status']) ?></span>
                                    <?php if ($change['stopped_at']): ?>
                                        <small class="prices-muted">
                                            <?= htmlspecialchars(pricesDisplayTimestamp($change['stopped_at'])) ?>
                                            <?= $change['stopped_by']
                                                ? '· ' . htmlspecialchars(pricesPersonName($change['stopped_first_name'], $change['stopped_last_name']))
                                                : ''
                                            ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td class="prices-reason"><?= htmlspecialchars($change['reason']) ?></td>
                                <td><?= htmlspecialchars(pricesPersonName($change['created_first_name'], $change['created_last_name'])) ?></td>
                            </tr>
                        <?php endforeach; ?>

                    </tbody>
                </table>
            </div>

        </div>

    <?php endif; ?>

</div>


<?php if ($pricesReady): ?>

<!-- NEW PRICE CHANGE -->

<div class="prices-modal" id="priceModal" hidden>

    <div class="prices-modal-backdrop" data-close-price-modal></div>

    <div class="prices-modal-card" role="dialog" aria-modal="true" aria-labelledby="priceModalTitle">

        <div class="prices-modal-header">
            <div>
                <div class="prices-eyebrow">PRICING</div>
                <h3 id="priceModalTitle">New Price Change</h3>
                <p>POS uses the new price automatically. PWD / Senior customers get the better of the sale price or 20% off, never both.</p>
            </div>
            <button type="button" class="prices-modal-close" data-close-price-modal aria-label="Close">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>

        <form method="post" class="prices-form" id="priceForm" novalidate>

            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="save_price_change">

            <div class="prices-field">
                <label for="priceProduct">Product <span>*</span></label>
                <select id="priceProduct" name="product_id" required>
                    <option value="">Choose a product</option>
                    <?php foreach ($formProducts as $formProduct): ?>
                        <option value="<?= $formProduct['id'] ?>">
                            <?= htmlspecialchars($formProduct['name'] . ($formProduct['code'] !== '' ? ' · ' . $formProduct['code'] : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="prices-hint" id="priceProductInfo"></small>
            </div>

            <fieldset class="prices-type-choice">
                <legend>What kind of price?</legend>

                <label>
                    <input type="radio" name="change_type" value="Sale" checked>
                    <span>
                        <strong>Sale</strong>
                        <small>Lower price between two dates, then back to normal.</small>
                    </span>
                </label>

                <label>
                    <input type="radio" name="change_type" value="Markdown">
                    <span>
                        <strong>Markdown</strong>
                        <small>New regular price for good, every color and size.</small>
                    </span>
                </label>

                <label>
                    <input type="radio" name="change_type" value="Clearance">
                    <span>
                        <strong>Clearance</strong>
                        <small>Until the last pieces sell; sold-out sizes become Inactive.</small>
                    </span>
                </label>
            </fieldset>

            <div class="prices-field" id="priceVariantField">
                <div class="prices-field-heading">
                    <label>Colors / sizes <span>*</span></label>
                    <label class="prices-select-all">
                        <input type="checkbox" id="priceSelectAll">
                        Select all
                    </label>
                </div>
                <div class="prices-variant-list" id="priceVariantList">
                    <p class="prices-hint">Choose a product first.</p>
                </div>
            </div>

            <div class="prices-form-grid">

                <div class="prices-field">
                    <label for="priceValue">New price <span>*</span></label>
                    <div class="prices-mode">
                        <label><input type="radio" name="price_mode" value="price" checked> ₱ amount</label>
                        <label><input type="radio" name="price_mode" value="percent"> % off</label>
                    </div>
                    <div class="prices-money-input" id="priceInputWrap">
                        <span id="priceInputPrefix">₱</span>
                        <input type="number" id="priceValue" name="price_value" min="0.01" step="0.01" inputmode="decimal" required>
                    </div>
                </div>

                <div class="prices-field">
                    <label>Result</label>
                    <div class="prices-preview" id="pricePreview">—</div>
                    <p class="prices-warning" id="priceCostWarning" hidden></p>
                </div>

                <div class="prices-field" id="priceStartField">
                    <label for="priceStartsOn">Starts <span>*</span></label>
                    <input type="date" id="priceStartsOn" name="starts_on" min="<?= $today ?>" value="<?= $today ?>" required>
                </div>

                <div class="prices-field" id="priceEndField">
                    <label for="priceEndsOn">Ends <span>*</span></label>
                    <input type="date" id="priceEndsOn" name="ends_on" min="<?= $today ?>">
                </div>

                <p class="prices-hint full" id="priceDateNote" hidden></p>

                <div class="prices-field full">
                    <label for="priceReason">Reason <span>*</span></label>
                    <input type="text" id="priceReason" name="reason" maxlength="200" list="priceReasonList" placeholder="e.g. End of season" required>
                    <datalist id="priceReasonList">
                        <?php foreach (PRICE_REASON_SUGGESTIONS as $suggestion): ?>
                            <option value="<?= htmlspecialchars($suggestion) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

            </div>

            <p class="prices-form-error" id="priceFormError" role="alert" hidden></p>

            <div class="prices-modal-footer">
                <button type="button" class="prices-secondary-button" data-close-price-modal>Cancel</button>
                <button type="submit" class="prices-primary-button" id="priceSubmitButton">
                    <span class="material-symbols-rounded">save</span>
                    <span id="priceSubmitText">Save Price</span>
                </button>
            </div>

        </form>

    </div>

</div>


<script>
const priceProducts = <?= json_encode($formProducts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
const priceToday = <?= json_encode($today) ?>;

const priceModal = document.getElementById('priceModal');
const priceForm = document.getElementById('priceForm');
const priceProduct = document.getElementById('priceProduct');
const priceProductInfo = document.getElementById('priceProductInfo');
const priceVariantField = document.getElementById('priceVariantField');
const priceVariantList = document.getElementById('priceVariantList');
const priceSelectAll = document.getElementById('priceSelectAll');
const priceValue = document.getElementById('priceValue');
const priceInputPrefix = document.getElementById('priceInputPrefix');
const pricePreview = document.getElementById('pricePreview');
const priceCostWarning = document.getElementById('priceCostWarning');
const priceStartsOn = document.getElementById('priceStartsOn');
const priceEndsOn = document.getElementById('priceEndsOn');
const priceStartField = document.getElementById('priceStartField');
const priceEndField = document.getElementById('priceEndField');
const priceDateNote = document.getElementById('priceDateNote');
const priceReason = document.getElementById('priceReason');
const priceFormError = document.getElementById('priceFormError');
const priceSubmitButton = document.getElementById('priceSubmitButton');
const priceSubmitText = document.getElementById('priceSubmitText');

let priceSubmitting = false;
let priceModalTrigger = null;


function priceMoney(value) {
    return '₱' + Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function priceEscape(value) {
    const div = document.createElement('div');
    div.textContent = String(value ?? '');
    return div.innerHTML;
}

function selectedPriceProduct() {
    return priceProducts.find((product) => String(product.id) === priceProduct.value) || null;
}

function selectedPriceType() {
    return priceForm.querySelector('input[name="change_type"]:checked').value;
}

function selectedPriceMode() {
    return priceForm.querySelector('input[name="price_mode"]:checked').value;
}

/* The new price the server will save (same rounding as the server). */
function calculatedNewPrice() {
    const product = selectedPriceProduct();
    const value = Number(priceValue.value);

    if (!product || !(value > 0)) {
        return null;
    }

    return selectedPriceMode() === 'percent'
        ? Math.round(product.price * (1 - value / 100) * 100) / 100
        : Math.round(value * 100) / 100;
}

function checkedVariantBoxes() {
    return Array.from(priceVariantList.querySelectorAll('input[name="variant_ids[]"]:checked'));
}


function renderPriceVariants() {
    const product = selectedPriceProduct();

    priceProductInfo.textContent = product
        ? `Regular price ${priceMoney(product.price)}` + (product.cost > 0 ? ` · Cost ${priceMoney(product.cost)}` : '')
        : '';

    priceVariantList.innerHTML = '';
    priceSelectAll.checked = false;

    if (!product) {
        priceVariantList.innerHTML = '<p class="prices-hint">Choose a product first.</p>';
        return;
    }

    if (product.variants.length === 0) {
        priceVariantList.innerHTML = '<p class="prices-hint">This product has no active colors / sizes.</p>';
        return;
    }

    product.variants.forEach((variant) => {
        const label = document.createElement('label');
        label.className = 'prices-variant';
        label.innerHTML = `
            <input type="checkbox" name="variant_ids[]" value="${variant.id}">
            <span>
                <strong>${priceEscape(variant.color)} / ${priceEscape(variant.size)}</strong>
                <small>${variant.stock} in stock${variant.active ? ' · ' + priceEscape(variant.active) : ''}</small>
            </span>
        `;
        priceVariantList.appendChild(label);
    });
}


function updatePriceForm() {
    const type = selectedPriceType();
    const product = selectedPriceProduct();
    const newPrice = calculatedNewPrice();

    priceVariantField.hidden = type === 'Markdown';
    priceStartField.hidden = type === 'Markdown';
    priceEndField.hidden = type !== 'Sale';
    priceDateNote.hidden = type === 'Sale';
    priceDateNote.textContent = type === 'Markdown'
        ? 'A markdown takes effect right away and stays: it becomes the new regular price of every color and size.'
        : 'No end date: the clearance price stays until the size sells out (then it becomes Inactive) or until you stop it.';

    priceInputPrefix.textContent = selectedPriceMode() === 'percent' ? '%' : '₱';
    priceValue.step = selectedPriceMode() === 'percent' ? '1' : '0.01';

    if (!product || newPrice === null) {
        pricePreview.textContent = '—';
        priceCostWarning.hidden = true;
        return;
    }

    const percentOff = product.price > 0 ? Math.round((1 - newPrice / product.price) * 100) : 0;

    pricePreview.innerHTML = `<strong>${priceMoney(newPrice)}</strong> <s>${priceMoney(product.price)}</s> · ${percentOff}% off`;

    const loss = product.cost > 0 ? product.cost - newPrice : 0;

    priceCostWarning.hidden = !(loss > 0);
    priceCostWarning.textContent = loss > 0
        ? `Below cost (${priceMoney(product.cost)}): you lose ${priceMoney(loss)} on every piece.`
        : '';
}


function openPriceModal(trigger = null) {
    priceModalTrigger = trigger;
    priceSubmitting = false;
    priceSubmitButton.disabled = false;
    priceSubmitText.textContent = 'Save Price';
    priceFormError.hidden = true;

    priceForm.reset();
    priceStartsOn.value = priceToday;
    renderPriceVariants();
    updatePriceForm();

    priceModal.hidden = false;
    document.body.classList.add('prices-modal-open');
    priceProduct.focus();
}


function closePriceModal() {
    if (priceSubmitting) {
        return;
    }

    priceModal.hidden = true;
    document.body.classList.remove('prices-modal-open');

    if (priceModalTrigger) {
        priceModalTrigger.focus();
    }
}


document.getElementById('openPriceChange').addEventListener('click', (event) => {
    openPriceModal(event.currentTarget);
});

document.querySelectorAll('[data-close-price-modal]').forEach((element) => {
    element.addEventListener('click', closePriceModal);
});

priceProduct.addEventListener('change', () => {
    renderPriceVariants();
    updatePriceForm();
});

priceForm.querySelectorAll('input[name="change_type"], input[name="price_mode"]').forEach((radio) => {
    radio.addEventListener('change', () => {
        priceFormError.hidden = true;
        updatePriceForm();
    });
});

priceSelectAll.addEventListener('change', () => {
    priceVariantList.querySelectorAll('input[name="variant_ids[]"]').forEach((box) => {
        box.checked = priceSelectAll.checked;
    });
});

[priceValue, priceStartsOn, priceEndsOn, priceReason].forEach((field) => {
    field.addEventListener('input', () => {
        priceFormError.hidden = true;
        field.classList.remove('invalid');
        updatePriceForm();
    });
});


/*
| Inline checks (the server checks everything again). Shows the first
| problem in plain language and locks the button once a save starts.
*/
priceForm.addEventListener('submit', (event) => {
    if (priceSubmitting) {
        event.preventDefault();
        return;
    }

    const product = selectedPriceProduct();
    const type = selectedPriceType();
    const newPrice = calculatedNewPrice();
    const percent = Number(priceValue.value);
    const variantCount = checkedVariantBoxes().length;

    const checks = [
        [priceProduct, !product, 'Choose a product.'],
        [priceVariantList, type !== 'Markdown' && variantCount === 0, 'Choose at least one color / size.'],
        [priceValue, selectedPriceMode() === 'percent' && !(percent >= 1 && percent <= 95), 'The percent off must be between 1 and 95.'],
        [priceValue, newPrice === null || newPrice <= 0, 'Enter the new price.'],
        [priceValue, product && newPrice !== null && newPrice >= product.price, `The new price must be lower than the regular price (${product ? priceMoney(product.price) : ''}).`],
        [priceStartsOn, type !== 'Markdown' && (priceStartsOn.value === '' || priceStartsOn.value < priceToday), 'Choose a start date from today on.'],
        [priceEndsOn, type === 'Sale' && priceEndsOn.value === '', 'A sale needs an end date.'],
        [priceEndsOn, type === 'Sale' && priceEndsOn.value !== '' && priceEndsOn.value < priceStartsOn.value, 'The end date cannot be before the start date.'],
        [priceReason, priceReason.value.trim() === '', 'Enter a short reason, for example "End of season".']
    ];

    const failed = checks.find(([, invalid]) => invalid);

    if (failed) {
        event.preventDefault();
        const [field, , message] = failed;
        priceFormError.textContent = message;
        priceFormError.hidden = false;
        field.classList.add('invalid');
        field.focus?.();
        return;
    }

    /* Ask first; below cost gets its own warning. */
    const loss = product.cost > 0 ? product.cost - newPrice : 0;
    const what = type === 'Markdown'
        ? `${product.name} becomes ${priceMoney(newPrice)} for good (was ${priceMoney(product.price)}).`
        : `${variantCount} ${variantCount === 1 ? 'color / size' : 'colors / sizes'} of ${product.name} at ${priceMoney(newPrice)} (was ${priceMoney(product.price)}).`;

    if (
        window.UA?.confirmSubmit &&
        !UA.confirmSubmit(event, {
            title: loss > 0 ? 'Sell below cost?' : `Save this ${type.toLowerCase()} price?`,
            message: loss > 0
                ? `${what} That is below the cost price (${priceMoney(product.cost)}): you lose ${priceMoney(loss)} on every piece.`
                : what,
            label: 'Save Price',
            icon: loss > 0 ? 'warning' : 'sell'
        })
    ) {
        return;
    }

    priceSubmitting = true;
    priceSubmitButton.disabled = true;
    priceSubmitText.textContent = 'Saving…';
});


document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape' || window.UA?.isConfirmOpen()) {
        return;
    }

    if (!priceModal.hidden) {
        closePriceModal();
    }
});
</script>

<?php endif; ?>

<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';
