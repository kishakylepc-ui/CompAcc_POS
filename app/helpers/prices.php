<?php

/*
|--------------------------------------------------------------------------
| SALE / MARKDOWN / CLEARANCE PRICES
|--------------------------------------------------------------------------
|
| Shared by POS (pos/index.php, pos/process_sale.php) and the Prices page.
| The table price_changes (tools/migrate_price_changes.php) holds:
|
|   Sale       a lower price for chosen colors / sizes between two dates
|   Clearance  a lower price for chosen colors / sizes with no end date;
|              a clearance color / size becomes Inactive when it sells out
|   Markdown   a permanent new regular price (products.selling_price) for
|              every color / size, kept as history (status Applied)
|
| starts_on / ends_on are Philippine calendar dates (YYYY-MM-DD). The server
| always works out the price; the browser only shows it.
|
*/

/* True once tools/migrate_price_changes.php has run. */
function pricesReady(PDO $pdo): bool
{
    static $ready = null;

    if ($ready === null) {
        $hasTable =
            (int) $pdo->query("
                SELECT COUNT(*)
                FROM sqlite_master
                WHERE type = 'table'
                  AND name = 'price_changes'
            ")->fetchColumn() > 0;

        $saleItemColumns =
            array_column(
                $pdo->query('PRAGMA table_info(sale_items)')->fetchAll(),
                'name'
            );

        $ready =
            $hasTable
            && in_array('regular_price', $saleItemColumns, true)
            && in_array('price_type', $saleItemColumns, true);
    }

    return $ready;
}


/* Today's date in the Philippines (sale dates are Philippine dates). */
function pricesToday(): string
{
    return (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
}


/*
| Running Sale / Clearance prices: variant_id => price_changes row.
| An empty $variantIds means every variant. Overlaps are refused when a
| price is saved, so there is at most one per variant; the newest wins.
*/
function pricesRunning(PDO $pdo, array $variantIds = []): array
{
    if (!pricesReady($pdo)) {
        return [];
    }

    $variantIds =
        array_values(
            array_unique(
                array_filter(
                    array_map('intval', $variantIds)
                )
            )
        );

    $variantFilter = '';

    if ($variantIds !== []) {
        $variantFilter =
            'AND variant_id IN ('
            . implode(',', array_fill(0, count($variantIds), '?'))
            . ')';
    }

    $statement = $pdo->prepare("
        SELECT
            id,
            variant_id,
            change_type,
            new_price,
            starts_on,
            ends_on
        FROM price_changes
        WHERE status = 'Active'
          AND change_type IN ('Sale', 'Clearance')
          AND variant_id IS NOT NULL
          AND starts_on <= ?
          AND (ends_on IS NULL OR ends_on >= ?)
          {$variantFilter}
        ORDER BY id DESC
    ");

    $today = pricesToday();

    $statement->execute(
        array_merge([$today, $today], $variantIds)
    );

    $running = [];

    foreach ($statement->fetchAll() as $row) {
        $variantId = (int) $row['variant_id'];

        if (!isset($running[$variantId])) {
            $running[$variantId] = $row;
        }
    }

    return $running;
}


/*
| The price one item costs before PWD / Senior: the running Sale /
| Clearance price when it is lower than the regular price, otherwise the
| regular price.
*/
function pricesForItem(float $regularPrice, ?array $running): array
{
    $regular = round($regularPrice, 2);

    if ($running !== null) {
        $newPrice = round((float) $running['new_price'], 2);

        if ($newPrice < $regular) {
            return [
                'price' => $newPrice,
                'regular' => $regular,
                'type' => (string) $running['change_type'],
                'change_id' => (int) $running['id'],
                'ends_on' => $running['ends_on']
            ];
        }
    }

    return [
        'price' => $regular,
        'regular' => $regular,
        'type' => 'Regular',
        'change_id' => null,
        'ends_on' => null
    ];
}


/*
| PWD / Senior on one cart line: the customer gets the HIGHER of the two
| discounts, never both - the sale price, or the percentage off the
| regular price. Returns the discount on top of the price already charged
| (0 when the sale price is lower already). Not rounded: the caller rounds
| the total once, so a cart without sale items gives exactly
| subtotal x percent, as before.
*/
function pricesStatutoryDiscount(float $price, float $regular, int $quantity, float $percent): float
{
    if ($percent <= 0) {
        return 0.0;
    }

    $discountedRegular = $regular * (1 - $percent / 100);

    return max(0.0, $price - $discountedRegular) * $quantity;
}
