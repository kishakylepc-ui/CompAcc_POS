<?php

require_once __DIR__
    . '/../../app/middleware/role.php';

requireRole([
    'Admin',
    'Manager'
]);

require_once __DIR__
    . '/../../app/config/database.php';


$pageTitle =
    'Inventory';

$currentPage =
    'inventory';


/*
|--------------------------------------------------------------------------
| CSRF TOKEN
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
| HELPERS
|--------------------------------------------------------------------------
*/

function inventoryRedirect(string $query = ''): never
{
    header('Location: /inventory/' . $query);
    exit;
}


function inventoryFlash(
    string $type,
    string $message
): void {

    if ($type === 'success') {

        $_SESSION['inventory_success'] =
            $message;

        return;
    }


    $_SESSION['inventory_error'] =
        $message;
}


function inventoryDisplayDate(
    ?string $utcDateTime,
    bool $includeTime = false
): string {

    $value = trim((string) $utcDateTime);

    if ($value === '') {
        return '—';
    }

    try {
        $date = new DateTime(
            $value,
            new DateTimeZone('UTC')
        );

        $date->setTimezone(
            new DateTimeZone('Asia/Manila')
        );

        return $date->format(
            $includeTime
                ? 'M d, Y · g:i A'
                : 'M d, Y'
        );
    } catch (Throwable $error) {
        return $value;
    }
}


function generateStockReceiptNo(PDO $pdo): string
{
    $timezone = new DateTimeZone('Asia/Manila');

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $now = new DateTimeImmutable('now', $timezone);

        $receiptNo =
            'RST-'
            . $now->format('Ymd-His')
            . '-'
            . strtoupper(bin2hex(random_bytes(2)));

        $check = $pdo->prepare("\n            SELECT 1\n            FROM stock_receipts\n            WHERE receipt_no = ?\n            LIMIT 1\n        ");

        $check->execute([$receiptNo]);

        if (!$check->fetchColumn()) {
            return $receiptNo;
        }
    }

    throw new RuntimeException('Unable to generate a unique stock receipt number.');
}


function inventoryNextProductCode(PDO $pdo): string
{
    $nextId = (int) $pdo->query("
        SELECT COALESCE(MAX(id), 0) + 1
        FROM products
    ")->fetchColumn();

    $check = $pdo->prepare("
        SELECT 1
        FROM products
        WHERE product_code = ?
        LIMIT 1
    ");

    while (true) {
        $candidate = 'UA-' . str_pad(
            (string) $nextId,
            4,
            '0',
            STR_PAD_LEFT
        );

        $check->execute([$candidate]);

        if (!$check->fetchColumn()) {
            return $candidate;
        }

        $nextId++;
    }
}



function inventoryVariantSizeToken(string $size): string
{
    $normalized = strtoupper(trim($size));

    $map = [
        'EXTRA SMALL' => 'XS',
        'XS' => 'XS',
        'SMALL' => 'S',
        'S' => 'S',
        'MEDIUM' => 'M',
        'M' => 'M',
        'LARGE' => 'L',
        'L' => 'L',
        'EXTRA LARGE' => 'XL',
        'XL' => 'XL',
        '2XL' => '2XL',
        'XXL' => '2XL',
        '3XL' => '3XL',
        'XXXL' => '3XL',
        'ONE SIZE' => 'OS',
        'ONESIZE' => 'OS',
        'OS' => 'OS'
    ];

    if (isset($map[$normalized])) {
        return $map[$normalized];
    }

    $token = preg_replace('/[^A-Z0-9]+/', '-', $normalized);
    $token = trim((string) $token, '-');

    return $token !== ''
        ? $token
        : 'STD';
}


function inventoryVariantColorToken(string $color): string
{
    $normalized = strtoupper(trim($color));
    $token = preg_replace('/[^A-Z0-9]+/', '-', $normalized);
    $token = trim((string) $token, '-');

    return $token !== ''
        ? $token
        : 'DEFAULT';
}


function inventoryVariantSku(
    string $productCode,
    string $color,
    string $size
): string {
    return
        strtoupper(trim($productCode))
        . '-'
        . inventoryVariantColorToken($color)
        . '-'
        . inventoryVariantSizeToken($size);
}


/*
 * The color + size part of a variant SKU. Two variants of one product
 * with the same key would get the same SKU, so the key also tells us
 * when a "new" size row is really an existing variant being re-added.
 */
function inventoryVariantSkuKey(string $color, string $size): string
{
    return
        inventoryVariantColorToken($color)
        . '|'
        . inventoryVariantSizeToken($size);
}


/*
 * Natural apparel size order for display: XS, S, M, L, XL, 2XL, 3XL,
 * One Size. Custom sizes ("Medium", "XXL") sort by their meaning;
 * unknown sizes go last.
 */
function inventorySizeRank(string $size): int
{
    $ranks = [
        'XS' => 1,
        'S' => 2,
        'M' => 3,
        'L' => 4,
        'XL' => 5,
        '2XL' => 6,
        '3XL' => 7,
        'OS' => 8
    ];

    return $ranks[inventoryVariantSizeToken($size)] ?? 50;
}


/*
 * Plain-language message for a failed product save. Raw database
 * errors are written to the PHP server console for debugging and are
 * not shown on the page.
 */
function inventorySaveErrorMessage(Throwable $error): string
{
    if (!$error instanceof PDOException) {
        return $error->getMessage();
    }

    error_log('[inventory] ' . $error->getMessage());

    if (str_contains($error->getMessage(), 'UNIQUE constraint failed')) {
        return 'Nothing was saved because two variants would share the same color and size, SKU or barcode. Check the colors and sizes, then try again.';
    }

    return 'Nothing was saved because the database rejected the change. Check the values, then try again.';
}


function inventoryVariantBarcodeFromId(int $variantId): string
{
    if ($variantId <= 0) {
        throw new RuntimeException('Invalid variant ID for barcode generation.');
    }

    return (string) (200000000 + $variantId);
}


function inventoryTemporaryVariantBarcode(): string
{
    return '__NEW_BARCODE_' . strtoupper(bin2hex(random_bytes(8)));
}


function inventoryNormalizeVariantImagePath(?string $path): string
{
    $value = trim((string) $path);

    if ($value === '') {
        return '';
    }

    if (
        !str_starts_with($value, '/assets/images/products/') ||
        str_contains($value, '..')
    ) {
        return '';
    }

    return $value;
}


function inventoryNormalizeColorGroupKey(string $key): string
{
    $value = trim($key);

    if (
        $value === '' ||
        !preg_match('/^[A-Za-z0-9_-]{1,80}$/', $value)
    ) {
        throw new RuntimeException('Invalid color image group.');
    }

    return $value;
}


function parseProductVariants(string $json): array
{
    $rows = json_decode($json, true);

    if (!is_array($rows) || $rows === []) {
        throw new RuntimeException('Add at least one color and size variant.');
    }

    $variants = [];
    $combinations = [];
    $skuKeys = [];
    $colorGroupKeys = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('Invalid product variant data.');
        }

        $id = (int) ($row['id'] ?? 0);
        $color = trim((string) ($row['color'] ?? ''));
        $colorHex = strtoupper(trim((string) ($row['color_hex'] ?? '')));
        $size = trim((string) ($row['size'] ?? ''));
        $stock = filter_var($row['stock_quantity'] ?? null, FILTER_VALIDATE_INT);
        $status = trim((string) ($row['status'] ?? 'Active'));
        $imageGroupKey = inventoryNormalizeColorGroupKey(
            (string) ($row['image_group_key'] ?? '')
        );
        $imagePath = inventoryNormalizeVariantImagePath(
            (string) ($row['image_path'] ?? '')
        );
        $removeColorImage = !empty($row['remove_color_image']);

        if ($color === '' || $size === '') {
            throw new RuntimeException('Every variant needs a color and size.');
        }

        if ($colorHex !== '' && !preg_match('/^#[0-9A-F]{6}$/', $colorHex)) {
            throw new RuntimeException('Color hex values must use the format #292929.');
        }

        if ($stock === false || $stock < 0) {
            throw new RuntimeException('Variant stock must be a whole number of zero or more.');
        }

        if (!in_array($status, ['Active', 'Inactive'], true)) {
            throw new RuntimeException('Invalid variant status.');
        }

        $colorKey = strtolower($color);

        if (
            isset($colorGroupKeys[$colorKey]) &&
            $colorGroupKeys[$colorKey] !== $imageGroupKey
        ) {
            throw new RuntimeException(
                'Use one color card per color, then add all sizes inside that card.'
            );
        }

        $colorGroupKeys[$colorKey] = $imageGroupKey;

        $combinationKey = strtolower($color . '|' . $size);

        if (isset($combinations[$combinationKey])) {
            throw new RuntimeException('A color and size combination can only be added once.');
        }

        $combinations[$combinationKey] = true;

        /*
         * The SKU is built from the color and size names, and different
         * names can produce the same SKU (for example "Medium" and "M",
         * or "XXL" and "2XL"). Catch that here with a clear message
         * instead of letting the database reject the save.
         */
        $skuKey = inventoryVariantSkuKey($color, $size);

        if (isset($skuKeys[$skuKey])) {
            throw new RuntimeException(
                $skuKeys[$skuKey]
                . ' and '
                . $color . ' / ' . $size
                . ' would get the same SKU code. Keep only one of these sizes.'
            );
        }

        $skuKeys[$skuKey] = $color . ' / ' . $size;

        $variants[] = [
            'id' => $id,
            'color' => $color,
            'color_hex' => $colorHex !== '' ? $colorHex : null,
            'size' => $size,
            'stock_quantity' => (int) $stock,
            'status' => $status,
            'image_group_key' => $imageGroupKey,
            'image_path' => $imagePath,
            'remove_color_image' => $removeColorImage
        ];
    }

    return $variants;
}


/*
|--------------------------------------------------------------------------
| AUTOMATIC REORDER POINT
|--------------------------------------------------------------------------
|
| Reorder Point (ROP) = Average Daily Demand × Lead Time + Safety Stock
|
| For this inventory module, safety stock is represented as an additional
| number of "safety days" of average demand:
|
| ROP = Average Daily Demand × (Lead Time Days + Safety Days)
|
| Defaults:
| - Sales history window: 30 days
| - Lead time: 7 days
| - Safety buffer: 3 days
|
| If a product has no sales history yet, the existing low-stock threshold
| is used as the temporary baseline.
|--------------------------------------------------------------------------
*/

function inventoryIntegerSetting(
    PDO $pdo,
    string $key,
    int $default
): int {

    static $cache = [];


    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }


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
        $value === false ||
        $value === null ||
        !is_numeric($value)
    ) {

        $cache[$key] =
            max(
                0,
                $default
            );

        return $cache[$key];
    }


    $cache[$key] =
        max(
            0,
            (int) $value
        );


    return $cache[$key];
}


function inventoryAutomaticVariantReorderMetrics(
    PDO $pdo,
    int $variantId,
    int $currentStock
): array {

    $fallbackReorder =
        max(
            1,
            inventoryIntegerSetting(
                $pdo,
                'variant_reorder_fallback',
                5
            )
        );


    $fallbackTarget =
        max(
            $fallbackReorder,
            inventoryIntegerSetting(
                $pdo,
                'variant_target_stock_fallback',
                15
            )
        );


    $windowDays =
        max(
            1,
            inventoryIntegerSetting(
                $pdo,
                'reorder_sales_window_days',
                30
            )
        );


    $leadTimeDays =
        max(
            1,
            inventoryIntegerSetting(
                $pdo,
                'reorder_lead_time_days',
                7
            )
        );


    $safetyDays =
        max(
            0,
            inventoryIntegerSetting(
                $pdo,
                'reorder_safety_days',
                3
            )
        );


    $targetDays =
        max(
            $leadTimeDays + $safetyDays,
            inventoryIntegerSetting(
                $pdo,
                'reorder_target_days',
                30
            )
        );


    $unitsSold =
        0;


    if ($variantId > 0) {

        $cutoff =
            (
                new DateTimeImmutable(
                    'now',
                    new DateTimeZone('UTC')
                )
            )
            ->modify(
                '-' . $windowDays . ' days'
            )
            ->format(
                'Y-m-d H:i:s'
            );


        $salesStatement =
            $pdo->prepare("
                SELECT
                    COALESCE(
                        SUM(si.quantity),
                        0
                    )
                FROM sale_items si

                INNER JOIN sales s
                    ON s.id = si.sale_id

                WHERE si.variant_id = ?
                  AND s.status = 'Completed'
                  AND s.created_at >= ?
            ");


        $salesStatement->execute([
            $variantId,
            $cutoff
        ]);


        $unitsSold =
            max(
                0,
                (int) $salesStatement->fetchColumn()
            );
    }


    $averageDailySales =
        $unitsSold > 0
            ? $unitsSold / $windowDays
            : 0.0;


    if ($unitsSold > 0) {

        $reorderLevel =
            max(
                1,
                (int) ceil(
                    $averageDailySales *
                    (
                        $leadTimeDays +
                        $safetyDays
                    )
                )
            );


        $targetStock =
            max(
                $reorderLevel,
                (int) ceil(
                    $averageDailySales *
                    $targetDays
                )
            );


        $source =
            'sales';

    } else {

        $reorderLevel =
            $fallbackReorder;


        $targetStock =
            $fallbackTarget;


        $source =
            'baseline';
    }


    $needsReorder =
        $currentStock <=
        $reorderLevel;


    $suggestedRestock =
        $needsReorder
            ? max(
                0,
                $targetStock -
                $currentStock
            )
            : 0;


    return [
        'reorder_level' =>
            $reorderLevel,

        'target_stock' =>
            $targetStock,

        'suggested_restock' =>
            $suggestedRestock,

        'needs_reorder' =>
            $needsReorder,

        'current_stock' =>
            $currentStock,

        'units_sold' =>
            $unitsSold,

        'average_daily_sales' =>
            round(
                $averageDailySales,
                2
            ),

        'window_days' =>
            $windowDays,

        'lead_time_days' =>
            $leadTimeDays,

        'safety_days' =>
            $safetyDays,

        'target_days' =>
            $targetDays,

        'source' =>
            $source
    ];
}


function inventoryAggregateVariantReorderMetrics(
    array $variants,
    int $fallbackReorder = 5,
    int $fallbackTarget = 15
): array {

    $reorderLevel =
        0;


    $targetStock =
        0;


    $suggestedRestock =
        0;


    $unitsSold =
        0;


    $averageDailySales =
        0.0;


    $lowStockVariants =
        0;


    $activeVariantCount =
        0;


    $salesBasedVariants =
        0;


    foreach ($variants as $variant) {

        if (
            ($variant['status'] ?? '') !==
            'Active'
        ) {
            continue;
        }


        $activeVariantCount++;


        $metrics =
            $variant['reorder_metrics']
            ?? [];


        $reorderLevel +=
            (int) (
                $metrics['reorder_level']
                ?? $fallbackReorder
            );


        $targetStock +=
            (int) (
                $metrics['target_stock']
                ?? $fallbackTarget
            );


        $suggestedRestock +=
            (int) (
                $metrics['suggested_restock']
                ?? 0
            );


        $unitsSold +=
            (int) (
                $metrics['units_sold']
                ?? 0
            );


        $averageDailySales +=
            (float) (
                $metrics['average_daily_sales']
                ?? 0
            );


        if (
            !empty(
                $metrics[
                    'needs_reorder'
                ]
            )
        ) {

            $lowStockVariants++;
        }


        if (
            ($metrics['source'] ?? '') ===
            'sales'
        ) {

            $salesBasedVariants++;
        }
    }


    if ($activeVariantCount === 0) {

        $reorderLevel =
            max(
                1,
                $fallbackReorder
            );


        $targetStock =
            max(
                $reorderLevel,
                $fallbackTarget
            );
    }


    $source =
        $salesBasedVariants === 0
            ? 'baseline'
            : (
                $salesBasedVariants ===
                $activeVariantCount
                    ? 'sales'
                    : 'mixed'
            );


    return [
        'reorder_level' =>
            $reorderLevel,

        'target_stock' =>
            $targetStock,

        'suggested_restock' =>
            $suggestedRestock,

        'units_sold' =>
            $unitsSold,

        'average_daily_sales' =>
            round(
                $averageDailySales,
                2
            ),

        'low_stock_variants' =>
            $lowStockVariants,

        'active_variant_count' =>
            $activeVariantCount,

        'source' =>
            $source
    ];
}


function inventoryProductReorderMetricsFromDatabase(
    PDO $pdo,
    int $productId
): array {

    $variantStatement =
        $pdo->prepare("
            SELECT
                id,
                stock_quantity,
                status
            FROM product_variants
            WHERE product_id = ?
              AND NOT (
                    status = 'Inactive'
                    AND color LIKE 'Archived-%'
              )
            ORDER BY id ASC
        ");


    $variantStatement->execute([
        $productId
    ]);


    $variants = [];


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


        $variants[] = [
            'id' =>
                $variantId,

            'status' =>
                (string) $variant[
                    'status'
                ],

            'reorder_metrics' =>
                inventoryAutomaticVariantReorderMetrics(
                    $pdo,
                    $variantId,
                    $currentStock
                )
        ];
    }


    return
        inventoryAggregateVariantReorderMetrics(
            $variants,
            max(
                1,
                inventoryIntegerSetting(
                    $pdo,
                    'variant_reorder_fallback',
                    5
                )
            ),
            max(
                1,
                inventoryIntegerSetting(
                    $pdo,
                    'variant_target_stock_fallback',
                    15
                )
            )
        );
}


function inventorySyncProductReorderLevel(
    PDO $pdo,
    int $productId
): int {

    $metrics =
        inventoryProductReorderMetricsFromDatabase(
            $pdo,
            $productId
        );


    $reorderLevel =
        max(
            0,
            (int) $metrics[
                'reorder_level'
            ]
        );


    $statement =
        $pdo->prepare("
            UPDATE products
            SET
                reorder_level = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");


    $statement->execute([
        $reorderLevel,
        $productId
    ]);


    return $reorderLevel;
}



/*
|--------------------------------------------------------------------------
| PUBLIC / IMAGE DIRECTORIES
|--------------------------------------------------------------------------
*/

$publicDirectory =
    realpath(
        __DIR__ . '/..'
    );


if ($publicDirectory === false) {

    throw new RuntimeException(
        'Unable to locate public directory.'
    );
}


$productImageDirectory =
    $publicDirectory
    . DIRECTORY_SEPARATOR
    . 'assets'
    . DIRECTORY_SEPARATOR
    . 'images'
    . DIRECTORY_SEPARATOR
    . 'products';


if (
    !is_dir(
        $productImageDirectory
    )
) {

    mkdir(
        $productImageDirectory,
        0775,
        true
    );
}


/*
|--------------------------------------------------------------------------
| FIND PRODUCT IMAGE
|--------------------------------------------------------------------------
*/

function getProductImageUrl(
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
| DELETE PRODUCT IMAGE
|--------------------------------------------------------------------------
*/

function deleteProductImages(
    string $directory,
    int $productId
): void {

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

            @unlink($file);
        }
    }
}


/*
|--------------------------------------------------------------------------
| SAVE PRODUCT IMAGE
|--------------------------------------------------------------------------
*/

function saveProductImage(
    array $file,
    string $directory,
    int $productId
): string {

    if (
        !isset(
            $file['error'],
            $file['tmp_name'],
            $file['size']
        )
    ) {

        return '';
    }


    if (
        $file['error'] ===
        UPLOAD_ERR_NO_FILE
    ) {

        return '';
    }


    if (
        $file['error'] !==
        UPLOAD_ERR_OK
    ) {

        throw new RuntimeException(
            'The product photo could not be uploaded.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | MAX 5 MB
    |--------------------------------------------------------------------------
    */

    if (
        (int) $file['size'] >
        5 * 1024 * 1024
    ) {

        throw new RuntimeException(
            'Product photo must be 5 MB or smaller.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | VERIFY REAL IMAGE
    |--------------------------------------------------------------------------
    */

    $imageInfo =
        @getimagesize(
            $file['tmp_name']
        );


    if ($imageInfo === false) {

        throw new RuntimeException(
            'The selected file is not a valid image.'
        );
    }


    $imageType =
        $imageInfo[2]
        ?? null;


    $allowedTypes = [

        IMAGETYPE_JPEG =>
            'jpg',

        IMAGETYPE_PNG =>
            'png',

        IMAGETYPE_WEBP =>
            'webp'

    ];


    if (
        !isset(
            $allowedTypes[$imageType]
        )
    ) {

        throw new RuntimeException(
            'Only JPG, PNG and WebP images are allowed.'
        );
    }


    $extension =
        $allowedTypes[$imageType];


    /*
    |--------------------------------------------------------------------------
    | DELETE OLD PRODUCT IMAGE
    |--------------------------------------------------------------------------
    */

    deleteProductImages(
        $directory,
        $productId
    );


    /*
    |--------------------------------------------------------------------------
    | SAVE WITH PRODUCT ID
    |--------------------------------------------------------------------------
    */

    $fileName =
        'product-'
        . $productId
        . '.'
        . $extension;


    $destination =
        $directory
        . DIRECTORY_SEPARATOR
        . $fileName;


    if (
        !move_uploaded_file(
            $file['tmp_name'],
            $destination
        )
    ) {

        throw new RuntimeException(
            'Unable to save the product photo.'
        );
    }


    return
        '/assets/images/products/'
        . $fileName;
}



/*
|--------------------------------------------------------------------------
| COLOR-SPECIFIC PRODUCT IMAGES
|--------------------------------------------------------------------------
|
| One image is stored per color and shared by every size variant in that
| color group. The database remains variant-based; variants in the same
| color simply reference the same image_path.
|
*/

function inventoryColorImageSlug(string $color): string
{
    $slug = strtolower(trim($color));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim((string) $slug, '-');

    if ($slug === '') {
        $slug = 'color';
    }

    return substr($slug, 0, 48);
}


function inventoryColorUploadFromFiles(
    array $files,
    string $groupKey
): ?array {

    if (
        !isset($files['error']) ||
        !is_array($files['error']) ||
        !array_key_exists($groupKey, $files['error'])
    ) {
        return null;
    }

    $error = (int) $files['error'][$groupKey];

    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    return [
        'error' => $error,
        'tmp_name' => (string) ($files['tmp_name'][$groupKey] ?? ''),
        'size' => (int) ($files['size'][$groupKey] ?? 0)
    ];
}


function saveProductColorImage(
    array $file,
    string $directory,
    int $productId,
    string $color
): string {

    if ($productId <= 0) {
        throw new RuntimeException('Invalid product for color image.');
    }

    if (
        !isset($file['error'], $file['tmp_name'], $file['size']) ||
        $file['error'] !== UPLOAD_ERR_OK
    ) {
        throw new RuntimeException('The color photo could not be uploaded.');
    }

    if ((int) $file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Color photos must be 5 MB or smaller.');
    }

    $imageInfo = @getimagesize((string) $file['tmp_name']);

    if ($imageInfo === false) {
        throw new RuntimeException('The selected color photo is not a valid image.');
    }

    $allowedTypes = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp'
    ];

    $imageType = $imageInfo[2] ?? null;

    if (!isset($allowedTypes[$imageType])) {
        throw new RuntimeException('Color photos must be JPG, PNG or WebP.');
    }

    $extension = $allowedTypes[$imageType];
    $slug = inventoryColorImageSlug($color);

    /*
     * Use a unique file name for replacements. This avoids deleting the
     * currently working color photo before the database transaction commits.
     */
    $fileName =
        'product-'
        . $productId
        . '-color-'
        . $slug
        . '-'
        . strtolower(bin2hex(random_bytes(3)))
        . '.'
        . $extension;
    $destination = $directory . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
        throw new RuntimeException('Unable to save the color photo.');
    }

    return '/assets/images/products/' . $fileName;
}


function inventoryResolveColorImagePaths(
    array $variants,
    array $files,
    string $directory,
    int $productId,
    array $existingVariantImages = []
): array {

    $groups = [];

    foreach ($variants as $variant) {
        $groupKey = (string) $variant['image_group_key'];
        $color = (string) $variant['color'];

        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'color' => $color,
                'fallback' => '',
                'remove' => false
            ];
        }

        if (strcasecmp($groups[$groupKey]['color'], $color) !== 0) {
            throw new RuntimeException('Each color image group must use one color name.');
        }

        if (!empty($variant['remove_color_image'])) {
            $groups[$groupKey]['remove'] = true;
        }

        if ($groups[$groupKey]['fallback'] === '') {
            $submittedPath = inventoryNormalizeVariantImagePath(
                (string) ($variant['image_path'] ?? '')
            );

            if ($submittedPath !== '') {
                $groups[$groupKey]['fallback'] = $submittedPath;
            }
        }

        $variantId = (int) ($variant['id'] ?? 0);

        if (
            $groups[$groupKey]['fallback'] === '' &&
            $variantId > 0 &&
            isset($existingVariantImages[$variantId])
        ) {
            $existingPath = inventoryNormalizeVariantImagePath(
                (string) $existingVariantImages[$variantId]
            );

            if ($existingPath !== '') {
                $groups[$groupKey]['fallback'] = $existingPath;
            }
        }
    }

    $resolved = [];

    foreach ($groups as $groupKey => $group) {
        if ($group['remove']) {
            $resolved[$groupKey] = null;
            continue;
        }

        $upload = inventoryColorUploadFromFiles($files, $groupKey);

        if ($upload !== null) {
            $resolved[$groupKey] = saveProductColorImage(
                $upload,
                $directory,
                $productId,
                (string) $group['color']
            );
            continue;
        }

        $resolved[$groupKey] =
            $group['fallback'] !== ''
                ? $group['fallback']
                : null;
    }

    return $resolved;
}


function inventoryFirstVariantImage(array $variants): string
{
    foreach ($variants as $variant) {
        $path = inventoryNormalizeVariantImagePath(
            (string) ($variant['image_path'] ?? '')
        );

        if ($path !== '') {
            return $path;
        }
    }

    return '';
}


/*
|--------------------------------------------------------------------------
| HANDLE POST REQUESTS
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] ===
    'POST'
) {

    $action =
        trim(
            $_POST['action']
            ?? ''
        );


    $submittedToken =
        $_POST['csrf_token']
        ?? '';


    if (
        empty(
            $_SESSION['csrf_token']
        ) ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $submittedToken
        )
    ) {

        inventoryFlash(
            'error',
            'Invalid request. Please try again.'
        );


        inventoryRedirect();
    }


    /*
    |--------------------------------------------------------------------------
    | ADD PRODUCT
    |--------------------------------------------------------------------------
    */

    if (
        $action ===
        'add_product'
    ) {

        $productCode =
            inventoryNextProductCode(
                $pdo
            );


        $productName =
            trim(
                $_POST['product_name']
                ?? ''
            );


        $categoryId =
            !empty(
                $_POST['category_id']
            )
                ? (int) $_POST['category_id']
                : null;


        $costPrice =
            (float) (
                $_POST['cost_price']
                ?? 0
            );


        $sellingPrice =
            (float) (
                $_POST['selling_price']
                ?? 0
            );


        try {
            $variants = parseProductVariants((string) ($_POST['variants_json'] ?? ''));
        } catch (RuntimeException $error) {
            inventoryFlash('error', $error->getMessage());
            inventoryRedirect();
        }


        $stockQuantity = array_sum(array_column($variants, 'stock_quantity'));


        $activeSubmittedVariants =
            array_filter(
                $variants,
                static fn (array $variant): bool =>
                    $variant['status'] ===
                    'Active'
            );


        $reorderLevel =
            max(
                1,
                count(
                    $activeSubmittedVariants
                ) *
                max(
                    1,
                    inventoryIntegerSetting(
                        $pdo,
                        'variant_reorder_fallback',
                        5
                    )
                )
            );


        $status =
            trim(
                $_POST['status']
                ?? 'Active'
            );


        /*
        |--------------------------------------------------------------------------
        | VALIDATION
        |--------------------------------------------------------------------------
        */


        if ($productName === '') {

            inventoryFlash(
                'error',
                'Product name is required.'
            );


            inventoryRedirect();
        }


        if (
            $costPrice < 0 ||
            $sellingPrice < 0
        ) {

            inventoryFlash(
                'error',
                'Prices cannot be negative.'
            );


            inventoryRedirect();
        }


        if (
            $stockQuantity < 0 ||
            $reorderLevel < 0
        ) {

            inventoryFlash(
                'error',
                'Stock values cannot be negative.'
            );


            inventoryRedirect();
        }


        if (
            !in_array(
                $status,
                [
                    'Active',
                    'Inactive'
                ],
                true
            )
        ) {

            inventoryFlash(
                'error',
                'Invalid product status.'
            );


            inventoryRedirect();
        }


        /*
        |--------------------------------------------------------------------------
        | CATEGORY VALIDATION
        |--------------------------------------------------------------------------
        */

        if ($categoryId !== null) {

            $categoryCheck =
                $pdo->prepare("
                    SELECT id
                    FROM categories
                    WHERE id = ?
                      AND status = 'Active'
                    LIMIT 1
                ");


            $categoryCheck->execute([
                $categoryId
            ]);


            if (!$categoryCheck->fetch()) {

                inventoryFlash(
                    'error',
                    'The selected category is invalid.'
                );


                inventoryRedirect();
            }
        }


        try {

            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | INSERT PRODUCT
            |--------------------------------------------------------------------------
            */

            $insert =
                $pdo->prepare("
                    INSERT INTO products (
                        product_code,
                        barcode,
                        product_name,
                        category_id,
                        cost_price,
                        selling_price,
                        stock_quantity,
                        reorder_level,
                        expiration_date,
                        status
                    )
                    VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )
                ");


            $insert->execute([

                $productCode,

                $productCode,

                $productName,

                $categoryId,

                round(
                    $costPrice,
                    2
                ),

                round(
                    $sellingPrice,
                    2
                ),

                0,

                $reorderLevel,

                null,

                $status

            ]);


            $productId =
                (int) $pdo->lastInsertId();


            $colorImagePaths = inventoryResolveColorImagePaths(
                $variants,
                $_FILES['color_images'] ?? [],
                $productImageDirectory,
                $productId
            );


            $variantInsert = $pdo->prepare("\n                INSERT INTO product_variants (\n                    product_id, color, color_hex, size, sku, barcode,\n                    stock_quantity, status, image_path\n                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)\n            ");

            $variantIdentifierUpdate = $pdo->prepare("\n                UPDATE product_variants\n                SET barcode = ?\n                WHERE id = ?\n            ");


            $initialInventoryLog = $pdo->prepare("\n                INSERT INTO inventory_logs (\n                    product_id,\n                    variant_id,\n                    user_id,\n                    supplier_id,\n                    sale_id,\n                    stock_receipt_id,\n                    action,\n                    color,\n                    size,\n                    quantity_change,\n                    previous_stock,\n                    new_stock,\n                    notes\n                )\n                VALUES (\n                    ?, ?, ?, NULL, NULL, NULL,\n                    ?, ?, ?, ?, ?, ?, ?\n                )\n            ");


            foreach ($variants as $variant) {

                $variantSku =
                    inventoryVariantSku(
                        $productCode,
                        $variant['color'],
                        $variant['size']
                    );

                $variantInsert->execute([
                    $productId,
                    $variant['color'],
                    $variant['color_hex'],
                    $variant['size'],
                    $variantSku,
                    inventoryTemporaryVariantBarcode(),
                    $variant['stock_quantity'],
                    $variant['status'],
                    $colorImagePaths[
                        $variant['image_group_key']
                    ] ?? null
                ]);


                $createdVariantId =
                    (int) $pdo->lastInsertId();


                $variantIdentifierUpdate->execute([
                    inventoryVariantBarcodeFromId($createdVariantId),
                    $createdVariantId
                ]);


                if ($variant['stock_quantity'] > 0) {

                    $initialInventoryLog->execute([
                        $productId,
                        $createdVariantId,
                        $_SESSION['user_id'],
                        'Initial Stock',
                        $variant['color'],
                        $variant['size'],
                        $variant['stock_quantity'],
                        0,
                        $variant['stock_quantity'],
                        'Opening stock recorded when the product variant was created.'
                    ]);
                }
            }


            /*
             * Keep the parent product reorder_level as a cached aggregate of
             * the automatic per-variant reorder points.
             */
            inventorySyncProductReorderLevel(
                $pdo,
                $productId
            );


            /*
            |--------------------------------------------------------------------------
            | SYSTEM LOG
            |--------------------------------------------------------------------------
            */

            $systemLog =
                $pdo->prepare("
                    INSERT INTO system_logs (
                        user_id,
                        action,
                        module,
                        record_type,
                        record_id,
                        details
                    )
                    VALUES (
                        ?, ?, ?, ?, ?, ?
                    )
                ");


            $systemLog->execute([

                $_SESSION['user_id'],

                'ADD_PRODUCT',

                'Inventory',

                'Product',

                $productId,

                'Added product '
                . $productName
                . ' with product code '
                . $productCode

            ]);


            /*
            |--------------------------------------------------------------------------
            | SAVE PHOTO
            |--------------------------------------------------------------------------
            */

            if (
                isset(
                    $_FILES['product_image']
                )
            ) {

                saveProductImage(
                    $_FILES['product_image'],
                    $productImageDirectory,
                    $productId
                );
            }


            $pdo->commit();


            inventoryFlash(
                'success',
                'Product added successfully.'
            );


        } catch (Throwable $error) {

            if ($pdo->inTransaction()) {

                $pdo->rollBack();
            }


            if (isset($productId)) {

                deleteProductImages(
                    $productImageDirectory,
                    (int) $productId
                );
            }


            inventoryFlash(
                'error',
                'Unable to add product: '
                . inventorySaveErrorMessage($error)
            );
        }


        inventoryRedirect();
    }


    /*
    |--------------------------------------------------------------------------
    | EDIT PRODUCT
    |--------------------------------------------------------------------------
    */

    if (
        $action ===
        'edit_product'
    ) {

        $productId =
            (int) (
                $_POST['product_id']
                ?? 0
            );


        $productName =
            trim(
                $_POST['product_name']
                ?? ''
            );


        $categoryId =
            !empty(
                $_POST['category_id']
            )
                ? (int) $_POST['category_id']
                : null;


        $costPrice =
            (float) (
                $_POST['cost_price']
                ?? 0
            );


        $sellingPrice =
            (float) (
                $_POST['selling_price']
                ?? 0
            );


        $reorderMetrics =
            inventoryProductReorderMetricsFromDatabase(
                $pdo,
                $productId
            );


        $reorderLevel =
            (int) $reorderMetrics[
                'reorder_level'
            ];


        $status =
            trim(
                $_POST['status']
                ?? 'Active'
            );


        try {
            $variants = parseProductVariants((string) ($_POST['variants_json'] ?? ''));
        } catch (RuntimeException $error) {
            inventoryFlash('error', $error->getMessage());
            inventoryRedirect();
        }


        $removePhoto =
            isset(
                $_POST['remove_photo']
            );


        if ($productId <= 0) {

            inventoryFlash(
                'error',
                'Invalid product.'
            );


            inventoryRedirect();
        }


        if ($productName === '') {

            inventoryFlash(
                'error',
                'Product name is required.'
            );


            inventoryRedirect();
        }


        if (
            $costPrice < 0 ||
            $sellingPrice < 0 ||
            $reorderLevel < 0
        ) {

            inventoryFlash(
                'error',
                'Product values cannot be negative.'
            );


            inventoryRedirect();
        }


        if (
            !in_array(
                $status,
                [
                    'Active',
                    'Inactive'
                ],
                true
            )
        ) {

            inventoryFlash(
                'error',
                'Invalid product status.'
            );


            inventoryRedirect();
        }


        /*
        |--------------------------------------------------------------------------
        | PRODUCT EXISTS
        |--------------------------------------------------------------------------
        */

        $existing =
            $pdo->prepare("
                SELECT
                    id,
                    product_code,
                    product_name
                FROM products
                WHERE id = ?
                LIMIT 1
            ");


        $existing->execute([
            $productId
        ]);


        $existingProduct =
            $existing->fetch();


        if (!$existingProduct) {

            inventoryFlash(
                'error',
                'Product not found.'
            );


            inventoryRedirect();
        }


        $productCode =
            (string) $existingProduct['product_code'];


        /*
        |--------------------------------------------------------------------------
        | CATEGORY
        |--------------------------------------------------------------------------
        */

        if ($categoryId !== null) {

            $categoryCheck =
                $pdo->prepare("
                    SELECT id
                    FROM categories
                    WHERE id = ?
                      AND status = 'Active'
                    LIMIT 1
                ");


            $categoryCheck->execute([
                $categoryId
            ]);


            if (!$categoryCheck->fetch()) {

                inventoryFlash(
                    'error',
                    'The selected category is invalid.'
                );


                inventoryRedirect();
            }
        }


        try {

            $pdo->beginTransaction();


            $update =
                $pdo->prepare("
                    UPDATE products
                    SET
                        product_name = ?,
                        category_id = ?,
                        cost_price = ?,
                        selling_price = ?,
                        reorder_level = ?,
                        status = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");


            $update->execute([

                $productName,

                $categoryId,

                round(
                    $costPrice,
                    2
                ),

                round(
                    $sellingPrice,
                    2
                ),

                $reorderLevel,

                $status,

                $productId

            ]);


            $existingVariantRows = $pdo->prepare("\n                SELECT\n                    id,\n                    color,\n                    size,\n                    status,\n                    stock_quantity,\n                    barcode,\n                    image_path\n                FROM product_variants\n                WHERE product_id = ?\n            ");

            $existingVariantRows->execute([$productId]);

            $existingVariantStocks = [];
            $existingVariantBarcodes = [];
            $existingVariantImages = [];
            $existingVariantSkuKeys = [];

            foreach ($existingVariantRows->fetchAll() as $existingVariantRow) {
                $existingVariantId =
                    (int) $existingVariantRow['id'];

                $existingVariantStocks[$existingVariantId] =
                    (int) $existingVariantRow['stock_quantity'];

                $existingVariantBarcodes[$existingVariantId] =
                    (string) $existingVariantRow['barcode'];

                $existingVariantImages[$existingVariantId] =
                    (string) (
                        $existingVariantRow['image_path']
                        ?? ''
                    );

                $isArchivedVariant =
                    $existingVariantRow['status'] === 'Inactive' &&
                    str_starts_with(
                        (string) $existingVariantRow['color'],
                        'Archived-'
                    );

                if (!$isArchivedVariant) {
                    $existingVariantSkuKeys[$existingVariantId] =
                        inventoryVariantSkuKey(
                            (string) $existingVariantRow['color'],
                            (string) $existingVariantRow['size']
                        );
                }
            }

            $existingVariantIds =
                array_keys($existingVariantStocks);


            /*
             * A size that was removed and added back in the same edit is
             * the same variant. Reuse it so it keeps its ID, barcode, stock
             * and sales / restock history, instead of archiving it and
             * creating a duplicate with a new barcode.
             */
            $submittedExistingIds = [];

            foreach ($variants as $variant) {
                if ($variant['id'] > 0) {
                    $submittedExistingIds[$variant['id']] = true;
                }
            }

            $reusableVariantIds = [];

            foreach ($existingVariantSkuKeys as $existingVariantId => $skuKey) {
                if (!isset($submittedExistingIds[$existingVariantId])) {
                    $reusableVariantIds[$skuKey] = $existingVariantId;
                }
            }

            foreach ($variants as $index => $variant) {
                if ($variant['id'] > 0) {
                    continue;
                }

                $skuKey = inventoryVariantSkuKey(
                    $variant['color'],
                    $variant['size']
                );

                if (isset($reusableVariantIds[$skuKey])) {
                    $variants[$index]['id'] =
                        $reusableVariantIds[$skuKey];

                    unset($reusableVariantIds[$skuKey]);
                }
            }


            $colorImagePaths = inventoryResolveColorImagePaths(
                $variants,
                $_FILES['color_images'] ?? [],
                $productImageDirectory,
                $productId,
                $existingVariantImages
            );


            /*
             * Park every variant of this product on a temporary SKU and
             * size first. The database allows each color + size (and SKU)
             * only once, even for a moment, so without this, reordering,
             * swapping or re-adding sizes failed part-way through the save.
             * The real values are written next, inside the same transaction.
             */
            $temporaryKeys = $pdo->prepare("\n                UPDATE product_variants\n                SET sku = '__EDIT_SKU_' || id,\n                    size = '__EDIT_SIZE_' || id\n                WHERE product_id = ?\n            ");

            $temporaryKeys->execute([$productId]);


            $variantUpdate = $pdo->prepare("\n                UPDATE product_variants\n                SET color = ?, color_hex = ?, size = ?, sku = ?, barcode = ?,\n                    stock_quantity = ?, status = ?, image_path = ?,\n                    updated_at = CURRENT_TIMESTAMP\n                WHERE id = ? AND product_id = ?\n            ");

            $variantInsert = $pdo->prepare("\n                INSERT INTO product_variants (\n                    product_id, color, color_hex, size, sku, barcode,\n                    stock_quantity, status, image_path\n                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)\n            ");

            $variantBarcodeUpdate = $pdo->prepare("\n                UPDATE product_variants\n                SET barcode = ?\n                WHERE id = ?\n                  AND product_id = ?\n            ");

            $submittedVariantIds = [];


            foreach ($variants as $variant) {

                if ($variant['id'] > 0) {

                    if (!array_key_exists($variant['id'], $existingVariantStocks)) {
                        throw new RuntimeException(
                            'A submitted variant does not belong to this product.'
                        );
                    }

                    /*
                     * Stock cannot be edited from Product Details anymore.
                     * Existing stock is preserved and may only change through
                     * restocking / sales / future stock-adjustment workflows.
                     */
                    $currentVariantStock =
                        $existingVariantStocks[$variant['id']];

                    $variantSku =
                        inventoryVariantSku(
                            $productCode,
                            $variant['color'],
                            $variant['size']
                        );

                    $variantBarcode =
                        inventoryVariantBarcodeFromId(
                            $variant['id']
                        );

                    $variantUpdate->execute([
                        $variant['color'],
                        $variant['color_hex'],
                        $variant['size'],
                        $variantSku,
                        $variantBarcode,
                        $currentVariantStock,
                        $variant['status'],
                        $colorImagePaths[
                            $variant['image_group_key']
                        ] ?? null,
                        $variant['id'],
                        $productId
                    ]);

                    $submittedVariantIds[] =
                        $variant['id'];

                } else {

                    /*
                     * New variants created while editing begin at zero stock.
                     * Receive stock through the Restock workflow so the supplier,
                     * cost and inventory audit trail are recorded correctly.
                     */
                    $variantSku =
                        inventoryVariantSku(
                            $productCode,
                            $variant['color'],
                            $variant['size']
                        );

                    $variantInsert->execute([
                        $productId,
                        $variant['color'],
                        $variant['color_hex'],
                        $variant['size'],
                        $variantSku,
                        inventoryTemporaryVariantBarcode(),
                        0,
                        $variant['status'],
                        $colorImagePaths[
                            $variant['image_group_key']
                        ] ?? null
                    ]);


                    $createdVariantId =
                        (int) $pdo->lastInsertId();


                    $variantBarcodeUpdate->execute([
                        inventoryVariantBarcodeFromId($createdVariantId),
                        $createdVariantId,
                        $productId
                    ]);
                }
            }


            foreach ($existingVariantIds as $existingVariantId) {

                if (in_array($existingVariantId, $submittedVariantIds, true)) {
                    continue;
                }

                $existingStock =
                    $existingVariantStocks[$existingVariantId] ?? 0;

                if ($existingStock > 0) {
                    throw new RuntimeException(
                        'A variant with stock cannot be removed. Set it to Inactive first or reduce its stock through an inventory adjustment.'
                    );
                }

                $variantUpdate->execute([
                    'Archived-' . $existingVariantId,
                    null,
                    'Archived',
                    'ARCHIVED-' . $existingVariantId,
                    inventoryVariantBarcodeFromId($existingVariantId),
                    0,
                    'Inactive',
                    null,
                    $existingVariantId,
                    $productId
                ]);
            }


            /*
             * Recalculate the cached parent reorder level after variant
             * additions, status changes or removals.
             */
            inventorySyncProductReorderLevel(
                $pdo,
                $productId
            );


            /*
            |--------------------------------------------------------------------------
            | PHOTO
            |--------------------------------------------------------------------------
            */

            $hasNewPhoto =
                isset(
                    $_FILES['product_image']
                ) &&
                (
                    $_FILES[
                        'product_image'
                    ]['error']
                    ?? UPLOAD_ERR_NO_FILE
                ) !==
                UPLOAD_ERR_NO_FILE;


            if ($hasNewPhoto) {

                saveProductImage(
                    $_FILES['product_image'],
                    $productImageDirectory,
                    $productId
                );

            } elseif ($removePhoto) {

                deleteProductImages(
                    $productImageDirectory,
                    $productId
                );
            }


            /*
            |--------------------------------------------------------------------------
            | SYSTEM LOG
            |--------------------------------------------------------------------------
            */

            $log =
                $pdo->prepare("
                    INSERT INTO system_logs (
                        user_id,
                        action,
                        module,
                        record_type,
                        record_id,
                        details
                    )
                    VALUES (
                        ?, ?, ?, ?, ?, ?
                    )
                ");


            $log->execute([

                $_SESSION['user_id'],

                'UPDATE_PRODUCT',

                'Inventory',

                'Product',

                $productId,

                'Updated product '
                . $productName

            ]);


            $pdo->commit();


            inventoryFlash(
                'success',
                'Product updated successfully.'
            );


        } catch (Throwable $error) {

            if ($pdo->inTransaction()) {

                $pdo->rollBack();
            }


            inventoryFlash(
                'error',
                'Unable to update product: '
                . inventorySaveErrorMessage($error)
            );
        }


        inventoryRedirect();
    }


    /*
    |--------------------------------------------------------------------------
    | RESTOCK PRODUCT
    |--------------------------------------------------------------------------
    */

    if (
        $action ===
        'restock_product'
    ) {

        $productId =
            (int) (
                $_POST['product_id']
                ?? 0
            );

        $variantId =
            (int) (
                $_POST['variant_id']
                ?? 0
            );

        $supplierId =
            (int) (
                $_POST['supplier_id']
                ?? 0
            );

        $quantity =
            (int) (
                $_POST['restock_quantity']
                ?? 0
            );

        $unitCostInput =
            trim(
                (string) (
                    $_POST['unit_cost']
                    ?? ''
                )
            );

        $unitCost =
            is_numeric($unitCostInput)
                ? round((float) $unitCostInput, 2)
                : -1;

        $notes =
            trim(
                (string) (
                    $_POST['restock_notes']
                    ?? ''
                )
            );


        if (
            $productId <= 0 ||
            $variantId <= 0 ||
            $supplierId <= 0 ||
            $quantity <= 0
        ) {

            inventoryFlash(
                'error',
                'Select a supplier and variant, then enter a restock quantity greater than zero.'
            );

            inventoryRedirect();
        }


        if ($unitCost < 0) {

            inventoryFlash(
                'error',
                'Unit cost must be zero or greater.'
            );

            inventoryRedirect();
        }


        try {

            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | VALIDATE PRODUCT / VARIANT / SUPPLIER LINK
            |--------------------------------------------------------------------------
            */

            $statement =
                $pdo->prepare("\n                    SELECT\n                        p.id,\n                        p.product_name,\n                        p.status AS product_status,\n\n                        pv.id AS variant_id,\n                        pv.color,\n                        pv.size,\n                        pv.sku,\n                        pv.stock_quantity AS variant_stock,\n                        pv.status AS variant_status,\n\n                        s.id AS supplier_id,\n                        s.supplier_name,\n                        s.status AS supplier_status,\n\n                        ps.supplier_price,\n                        ps.is_primary\n\n                    FROM products p\n\n                    INNER JOIN product_variants pv\n                        ON pv.product_id = p.id\n\n                    INNER JOIN product_suppliers ps\n                        ON ps.product_id = p.id\n                       AND ps.supplier_id = ?\n\n                    INNER JOIN suppliers s\n                        ON s.id = ps.supplier_id\n\n                    WHERE p.id = ?\n                      AND pv.id = ?\n                    LIMIT 1\n                ");


            $statement->execute([
                $supplierId,
                $productId,
                $variantId
            ]);


            $restockItem =
                $statement->fetch();


            if (!$restockItem) {
                throw new RuntimeException(
                    'The selected supplier is not linked to this product.'
                );
            }


            if ($restockItem['product_status'] !== 'Active') {
                throw new RuntimeException(
                    'Activate the product before restocking it.'
                );
            }


            if ($restockItem['variant_status'] !== 'Active') {
                throw new RuntimeException(
                    'Only active variants can be restocked.'
                );
            }


            if ($restockItem['supplier_status'] !== 'Active') {
                throw new RuntimeException(
                    'Only active suppliers can be used for restocking.'
                );
            }


            $previousVariantStock =
                (int) $restockItem['variant_stock'];

            $newVariantStock =
                $previousVariantStock +
                $quantity;

            $lineTotal =
                round(
                    $unitCost *
                    $quantity,
                    2
                );


            /*
            |--------------------------------------------------------------------------
            | CREATE STOCK RECEIPT
            |--------------------------------------------------------------------------
            */

            $receiptNo =
                generateStockReceiptNo($pdo);


            $receiptStatement =
                $pdo->prepare("\n                    INSERT INTO stock_receipts (\n                        receipt_no,\n                        supplier_id,\n                        received_by,\n                        total_cost,\n                        notes\n                    )\n                    VALUES (?, ?, ?, ?, ?)\n                ");


            $receiptStatement->execute([
                $receiptNo,
                $supplierId,
                $_SESSION['user_id'],
                $lineTotal,
                $notes !== ''
                    ? $notes
                    : null
            ]);


            $stockReceiptId =
                (int) $pdo->lastInsertId();


            $receiptItemStatement =
                $pdo->prepare("\n                    INSERT INTO stock_receipt_items (\n                        stock_receipt_id,\n                        product_id,\n                        variant_id,\n                        quantity,\n                        unit_cost,\n                        line_total\n                    )\n                    VALUES (?, ?, ?, ?, ?, ?)\n                ");


            $receiptItemStatement->execute([
                $stockReceiptId,
                $productId,
                $variantId,
                $quantity,
                $unitCost,
                $lineTotal
            ]);


            /*
            |--------------------------------------------------------------------------
            | UPDATE AUTHORITATIVE VARIANT STOCK
            |--------------------------------------------------------------------------
            |
            | The database trigger automatically recalculates products.stock_quantity.
            |
            */

            $update =
                $pdo->prepare("\n                    UPDATE product_variants\n                    SET\n                        stock_quantity = ?,\n                        updated_at = CURRENT_TIMESTAMP\n                    WHERE id = ?\n                      AND product_id = ?\n                      AND stock_quantity = ?\n                ");


            $update->execute([
                $newVariantStock,
                $variantId,
                $productId,
                $previousVariantStock
            ]);


            if ($update->rowCount() !== 1) {
                throw new RuntimeException(
                    'Variant stock changed while restocking. Please try again.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | INVENTORY AUDIT LOG
            |--------------------------------------------------------------------------
            |
            | previous_stock/new_stock now represent the selected VARIANT stock.
            |
            */

            $inventoryLog =
                $pdo->prepare("\n                    INSERT INTO inventory_logs (\n                        product_id,\n                        variant_id,\n                        user_id,\n                        supplier_id,\n                        sale_id,\n                        stock_receipt_id,\n                        action,\n                        color,\n                        size,\n                        quantity_change,\n                        previous_stock,\n                        new_stock,\n                        notes\n                    )\n                    VALUES (\n                        ?, ?, ?, ?, NULL, ?,\n                        ?, ?, ?, ?, ?, ?, ?\n                    )\n                ");


            $inventoryLog->execute([
                $productId,
                $variantId,
                $_SESSION['user_id'],
                $supplierId,
                $stockReceiptId,
                'Restock',
                $restockItem['color'],
                $restockItem['size'],
                $quantity,
                $previousVariantStock,
                $newVariantStock,
                $notes !== ''
                    ? $notes
                    : 'Stock receipt '
                        . $receiptNo
                        . ' from '
                        . $restockItem['supplier_name']
            ]);


            /*
            |--------------------------------------------------------------------------
            | SYSTEM LOG
            |--------------------------------------------------------------------------
            */

            $systemLog =
                $pdo->prepare("\n                    INSERT INTO system_logs (\n                        user_id,\n                        action,\n                        module,\n                        record_type,\n                        record_id,\n                        details\n                    )\n                    VALUES (?, ?, ?, ?, ?, ?)\n                ");


            $systemLog->execute([
                $_SESSION['user_id'],
                'RESTOCK_PRODUCT',
                'Inventory',
                'Stock Receipt',
                $stockReceiptId,
                'Received '
                . $quantity
                . ' unit(s) of '
                . $restockItem['product_name']
                . ' — '
                . $restockItem['color']
                . ' / '
                . $restockItem['size']
                . ' from '
                . $restockItem['supplier_name']
                . ' at PHP '
                . number_format($unitCost, 2, '.', '')
                . ' each. Receipt '
                . $receiptNo
                . '.'
            ]);


            $pdo->commit();


            inventoryFlash(
                'success',
                $receiptNo
                . ' created. '
                . $restockItem['product_name']
                . ' — '
                . $restockItem['color']
                . ' / '
                . $restockItem['size']
                . ' increased from '
                . $previousVariantStock
                . ' to '
                . $newVariantStock
                . ' units.'
            );


        } catch (Throwable $error) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }


            inventoryFlash(
                'error',
                'Unable to restock product: '
                . $error->getMessage()
            );
        }


        inventoryRedirect();
    }


    /*
    |--------------------------------------------------------------------------
    | RECORD STOCK LOSS (DAMAGED / DEFECTIVE / MISSING)
    |--------------------------------------------------------------------------
    |
    | Removes units from one color/size and records why. Optionally links the
    | loss to the supplier that delivered the item (loss-tracing).
    |
    | In one transaction:
    |   - product_variants stock goes down (triggers re-sync the product total)
    |   - inventory_logs: "Damaged" (damaged/defective) or "Adjustment" (missing)
    |   - expenses: a Loss entry worth quantity × cost per unit, so the loss is
    |     included in the Financial Summary. These entries are read-only in the
    |     Expenses page so the amount always matches the stock removed.
    |   - system_logs: RECORD_STOCK_LOSS
    |
    */

    if (
        $action ===
        'record_stock_loss'
    ) {

        $productId = (int) ($_POST['product_id'] ?? 0);
        $variantId = (int) ($_POST['variant_id'] ?? 0);
        $quantity = (int) ($_POST['loss_quantity'] ?? 0);
        $reason = trim((string) ($_POST['loss_reason'] ?? ''));
        $supplierId = (int) ($_POST['loss_supplier_id'] ?? 0);
        $unitCostInput = trim((string) ($_POST['loss_unit_cost'] ?? ''));
        $notes = trim((string) ($_POST['loss_notes'] ?? ''));

        $unitCost = is_numeric($unitCostInput)
            ? round((float) $unitCostInput, 2)
            : -1;

        $reasons = [
            'Damaged' => ['log_action' => 'Damaged', 'category' => 'Damaged Stock'],
            'Defective' => ['log_action' => 'Damaged', 'category' => 'Damaged Stock'],
            'Missing' => ['log_action' => 'Adjustment', 'category' => 'Missing Stock']
        ];

        if (
            $productId <= 0 ||
            $variantId <= 0 ||
            $quantity <= 0
        ) {
            inventoryFlash('error', 'Choose a color/size and enter how many units were lost.');
            inventoryRedirect();
        }

        if (!isset($reasons[$reason])) {
            inventoryFlash('error', 'Choose what happened to the stock.');
            inventoryRedirect();
        }

        if ($unitCost < 0) {
            inventoryFlash('error', 'Cost per unit must be zero or greater.');
            inventoryRedirect();
        }

        if (strlen($notes) > 500) {
            inventoryFlash('error', 'Keep the notes under 500 characters.');
            inventoryRedirect();
        }

        try {

            $pdo->beginTransaction();

            $variantStatement = $pdo->prepare("
                SELECT
                    p.product_name,
                    pv.color,
                    pv.size,
                    pv.stock_quantity
                FROM products p
                INNER JOIN product_variants pv
                    ON pv.product_id = p.id
                WHERE p.id = ?
                  AND pv.id = ?
                LIMIT 1
            ");

            $variantStatement->execute([$productId, $variantId]);

            $lossItem = $variantStatement->fetch();

            if (!$lossItem) {
                throw new RuntimeException('That color/size no longer exists.');
            }

            $previousStock = (int) $lossItem['stock_quantity'];

            if ($quantity > $previousStock) {
                throw new RuntimeException(
                    'Only ' . $previousStock . ' unit(s) of '
                    . $lossItem['color'] . ' / ' . $lossItem['size']
                    . ' are in stock.'
                );
            }


            /*
            | Supplier is optional, but when given it must be linked to the
            | product (active or inactive: past deliveries still count).
            */

            $supplierName = '';

            if ($supplierId > 0) {

                $supplierStatement = $pdo->prepare("
                    SELECT s.supplier_name
                    FROM product_suppliers ps
                    INNER JOIN suppliers s
                        ON s.id = ps.supplier_id
                    WHERE ps.product_id = ?
                      AND ps.supplier_id = ?
                    LIMIT 1
                ");

                $supplierStatement->execute([$productId, $supplierId]);

                $supplierName = (string) $supplierStatement->fetchColumn();

                if ($supplierName === '') {
                    throw new RuntimeException('That supplier is not linked to this product.');
                }
            }


            $newStock = $previousStock - $quantity;

            $update = $pdo->prepare("
                UPDATE product_variants
                SET
                    stock_quantity = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND product_id = ?
                  AND stock_quantity = ?
            ");

            $update->execute([$newStock, $variantId, $productId, $previousStock]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Stock changed while saving. Please try again.');
            }


            $itemLabel =
                $lossItem['product_name']
                . ' — '
                . $lossItem['color']
                . ' / '
                . $lossItem['size'];

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
                VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?)
            ");

            $inventoryLog->execute([
                $productId,
                $variantId,
                $_SESSION['user_id'],
                $supplierId > 0 ? $supplierId : null,
                $reasons[$reason]['log_action'],
                $lossItem['color'],
                $lossItem['size'],
                -$quantity,
                $previousStock,
                $newStock,
                $reason
                    . ($supplierName !== '' ? ' (supplier: ' . $supplierName . ')' : '')
                    . ($notes !== '' ? ' — ' . $notes : '')
            ]);


            $lossValue = round($quantity * $unitCost, 2);

            if ($lossValue > 0) {

                $expense = $pdo->prepare("
                    INSERT INTO expenses (
                        expense_type,
                        category,
                        description,
                        amount,
                        expense_date,
                        recorded_by
                    )
                    VALUES ('Loss', ?, ?, ?, ?, ?)
                ");

                $expense->execute([
                    $reasons[$reason]['category'],
                    substr(
                        $itemLabel
                        . ' × ' . $quantity
                        . ' (' . $reason . ')'
                        . ($supplierName !== '' ? ' · Supplier: ' . $supplierName : ''),
                        0,
                        255
                    ),
                    $lossValue,
                    (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d'),
                    $_SESSION['user_id']
                ]);
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
                VALUES (?, 'RECORD_STOCK_LOSS', 'Inventory', 'Product Variant', ?, ?)
            ");

            $systemLog->execute([
                $_SESSION['user_id'],
                $variantId,
                'Removed ' . $quantity . ' unit(s) of ' . $itemLabel
                    . ' as ' . strtolower($reason)
                    . ($supplierName !== '' ? ' (supplier: ' . $supplierName . ')' : '')
                    . '. Stock ' . $previousStock . ' → ' . $newStock
                    . '. Loss value PHP ' . number_format($lossValue, 2, '.', '') . '.'
                    . ($notes !== '' ? ' Notes: ' . $notes : '')
            ]);


            $pdo->commit();

            inventoryFlash(
                'success',
                'Removed ' . $quantity . ' × ' . $itemLabel
                    . ' (' . strtolower($reason) . '). Stock ' . $previousStock . ' → ' . $newStock . '.'
                    . ($lossValue > 0
                        ? ' A loss of ₱' . number_format($lossValue, 2) . ' was added to Expenses & Losses.'
                        : '')
            );

        } catch (Throwable $error) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            inventoryFlash('error', 'Unable to record the stock loss: ' . inventorySaveErrorMessage($error));
        }

        inventoryRedirect();
    }


    /*
    |--------------------------------------------------------------------------
    | LINK SUPPLIER (from the Restock window)
    |--------------------------------------------------------------------------
    |
    | Used when a product has no active supplier yet, so it can be
    | restocked. Same rules and System Log format as Suppliers → Manage
    | Products: active supplier, active product, price of zero or more,
    | and "primary" clears the product's other primary supplier.
    | On success the Restock window reopens with the supplier selected.
    |
    */

    if (
        $action ===
        'link_supplier'
    ) {

        $productId =
            (int) (
                $_POST['product_id']
                ?? 0
            );

        $supplierId =
            (int) (
                $_POST['supplier_id']
                ?? 0
            );

        $supplierPriceInput =
            trim(
                (string) (
                    $_POST['supplier_price']
                    ?? ''
                )
            );

        $supplierPrice =
            is_numeric($supplierPriceInput)
                ? round((float) $supplierPriceInput, 2)
                : -1;

        $isPrimary =
            isset($_POST['is_primary'])
                ? 1
                : 0;


        if (
            $productId <= 0 ||
            $supplierId <= 0
        ) {

            inventoryFlash(
                'error',
                'Select a supplier to link to this product.'
            );

            inventoryRedirect();
        }


        if ($supplierPrice < 0) {

            inventoryFlash(
                'error',
                'Supplier price must be zero or greater.'
            );

            inventoryRedirect();
        }


        try {

            $pdo->beginTransaction();


            $supplierStatement =
                $pdo->prepare("
                    SELECT
                        supplier_name,
                        status
                    FROM suppliers
                    WHERE id = ?
                    LIMIT 1
                ");

            $supplierStatement->execute([
                $supplierId
            ]);

            $supplier =
                $supplierStatement->fetch();


            $productStatement =
                $pdo->prepare("
                    SELECT
                        product_name,
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


            if (!$supplier || !$product) {
                throw new RuntimeException(
                    'Supplier or product not found.'
                );
            }


            if ($supplier['status'] !== 'Active') {
                throw new RuntimeException(
                    'Only active suppliers can be linked for restocking.'
                );
            }


            if ($product['status'] !== 'Active') {
                throw new RuntimeException(
                    'Activate this product before linking a supplier.'
                );
            }


            if ($isPrimary === 1) {

                $clearPrimary =
                    $pdo->prepare("
                        UPDATE product_suppliers
                        SET is_primary = 0
                        WHERE product_id = ?
                    ");

                $clearPrimary->execute([
                    $productId
                ]);
            }


            $existingStatement =
                $pdo->prepare("
                    SELECT id
                    FROM product_suppliers
                    WHERE product_id = ?
                      AND supplier_id = ?
                    LIMIT 1
                ");

            $existingStatement->execute([
                $productId,
                $supplierId
            ]);

            $existingLink =
                $existingStatement->fetch();


            if ($existingLink) {

                $linkId =
                    (int) $existingLink['id'];

                $pdo->prepare("
                    UPDATE product_suppliers
                    SET
                        supplier_price = ?,
                        is_primary = ?
                    WHERE id = ?
                ")->execute([
                    $supplierPrice,
                    $isPrimary,
                    $linkId
                ]);

                $logAction =
                    'UPDATE_PRODUCT_SUPPLIER';

            } else {

                $pdo->prepare("
                    INSERT INTO product_suppliers (
                        product_id,
                        supplier_id,
                        supplier_price,
                        is_primary
                    )
                    VALUES (?, ?, ?, ?)
                ")->execute([
                    $productId,
                    $supplierId,
                    $supplierPrice,
                    $isPrimary
                ]);

                $linkId =
                    (int) $pdo->lastInsertId();

                $logAction =
                    'LINK_PRODUCT_SUPPLIER';
            }


            $pdo->prepare("
                INSERT INTO system_logs (
                    user_id,
                    action,
                    module,
                    record_type,
                    record_id,
                    details
                )
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([
                $_SESSION['user_id'],
                $logAction,
                'Inventory',
                'Product Supplier',
                $linkId,
                ($existingLink ? 'Updated ' : 'Linked ')
                . $product['product_name']
                . ($existingLink ? ' for ' : ' to ')
                . $supplier['supplier_name']
                . ' at PHP '
                . number_format($supplierPrice, 2)
                . ($isPrimary === 1
                    ? ' as primary supplier'
                    : '')
                . ' (from Restock).'
            ]);


            $pdo->commit();

        } catch (Throwable $error) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            inventoryFlash(
                'error',
                'Unable to link supplier: '
                . inventorySaveErrorMessage($error)
            );

            inventoryRedirect();
        }


        inventoryFlash(
            'success',
            $supplier['supplier_name']
            . ' is now linked to '
            . $product['product_name']
            . '. You can restock it now.'
        );

        inventoryRedirect(
            '?restock=' . $productId . '&linked=1'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CHANGE STATUS
    |--------------------------------------------------------------------------
    */

    if (
        $action ===
        'change_status'
    ) {

        $productId =
            (int) (
                $_POST['product_id']
                ?? 0
            );


        $newStatus =
            trim(
                $_POST['new_status']
                ?? ''
            );


        if (
            $productId <= 0 ||
            !in_array(
                $newStatus,
                [
                    'Active',
                    'Inactive'
                ],
                true
            )
        ) {

            inventoryFlash(
                'error',
                'Invalid status request.'
            );


            inventoryRedirect();
        }


        $productStatement =
            $pdo->prepare("
                SELECT
                    id,
                    product_name
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

            inventoryFlash(
                'error',
                'Product not found.'
            );


            inventoryRedirect();
        }


        try {

            $pdo->beginTransaction();


            $update =
                $pdo->prepare("
                    UPDATE products
                    SET
                        status = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");


            $update->execute([
                $newStatus,
                $productId
            ]);


            $log =
                $pdo->prepare("
                    INSERT INTO system_logs (
                        user_id,
                        action,
                        module,
                        record_type,
                        record_id,
                        details
                    )
                    VALUES (
                        ?, ?, ?, ?, ?, ?
                    )
                ");


            $log->execute([

                $_SESSION['user_id'],

                'CHANGE_PRODUCT_STATUS',

                'Inventory',

                'Product',

                $productId,

                $product['product_name']
                . ' changed to '
                . $newStatus

            ]);


            $pdo->commit();


            inventoryFlash(
                'success',
                'Product status updated.'
            );


        } catch (Throwable $error) {

            if ($pdo->inTransaction()) {

                $pdo->rollBack();
            }


            inventoryFlash(
                'error',
                'Unable to update status: '
                . $error->getMessage()
            );
        }


        inventoryRedirect();
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE PRODUCT (Admin only)
    |--------------------------------------------------------------------------
    |
    | Allowed only for an Inactive product that has never been sold and
    | never received supplier stock. Products with history must stay
    | (Inactive) so receipts and reports keep working.
    |
    | Removes the product, its variants, supplier links and opening-stock
    | log lines in one transaction, then its photo files. A DELETE_PRODUCT
    | system log keeps a record of what was deleted.
    |
    */

    if (
        $action ===
        'delete_product'
    ) {

        if (($_SESSION['role'] ?? '') !== 'Admin') {

            inventoryFlash(
                'error',
                'Only an Admin can delete products.'
            );

            inventoryRedirect();
        }


        $productId =
            (int) (
                $_POST['product_id']
                ?? 0
            );


        if ($productId <= 0) {

            inventoryFlash(
                'error',
                'Invalid product.'
            );

            inventoryRedirect();
        }


        $deletedImagePaths = [];


        try {

            $pdo->beginTransaction();


            $productStatement =
                $pdo->prepare("
                    SELECT
                        id,
                        product_code,
                        product_name,
                        status,
                        stock_quantity
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
                    'Product not found.'
                );
            }


            $productLabel =
                $product['product_name']
                . ' ('
                . $product['product_code']
                . ')';


            $historyStatement =
                $pdo->prepare("
                    SELECT
                        (SELECT COUNT(*) FROM sale_items WHERE product_id = ?) AS sale_lines,
                        (SELECT COUNT(*) FROM stock_receipt_items WHERE product_id = ?) AS receipt_lines
                ");

            $historyStatement->execute([
                $productId,
                $productId
            ]);

            $history =
                $historyStatement->fetch();


            if (
                (int) $history['sale_lines'] > 0 ||
                (int) $history['receipt_lines'] > 0
            ) {
                throw new RuntimeException(
                    $productLabel
                    . ' has sales or supplier restock history, so it cannot be deleted. Keep it Inactive instead.'
                );
            }


            if ($product['status'] !== 'Inactive') {
                throw new RuntimeException(
                    $productLabel
                    . ' is Active. Deactivate it first, then delete it.'
                );
            }


            $variantStatement =
                $pdo->prepare("
                    SELECT
                        color,
                        size,
                        stock_quantity,
                        status,
                        image_path
                    FROM product_variants
                    WHERE product_id = ?
                    ORDER BY id ASC
                ");

            $variantStatement->execute([
                $productId
            ]);


            $variantSummary = [];


            foreach ($variantStatement->fetchAll() as $variant) {

                $isArchivedVariant =
                    $variant['status'] === 'Inactive' &&
                    str_starts_with(
                        (string) $variant['color'],
                        'Archived-'
                    );

                if (!$isArchivedVariant) {
                    $variantSummary[] =
                        $variant['color']
                        . ' / '
                        . $variant['size']
                        . ' ('
                        . (int) $variant['stock_quantity']
                        . ')';
                }

                if (!empty($variant['image_path'])) {
                    $deletedImagePaths[] =
                        (string) $variant['image_path'];
                }
            }


            /*
             * Child rows first. Only opening-stock style log lines can be
             * left here, because products with sales or receipts were
             * refused above.
             */
            foreach (
                [
                    'DELETE FROM inventory_logs WHERE product_id = ?',
                    'DELETE FROM product_suppliers WHERE product_id = ?',
                    'DELETE FROM product_variants WHERE product_id = ?'
                ]
                as $deleteSql
            ) {
                $pdo->prepare($deleteSql)->execute([
                    $productId
                ]);
            }


            $deleteProduct =
                $pdo->prepare("
                    DELETE FROM products
                    WHERE id = ?
                ");

            $deleteProduct->execute([
                $productId
            ]);


            if ($deleteProduct->rowCount() !== 1) {
                throw new RuntimeException(
                    'Product not found.'
                );
            }


            $systemLog =
                $pdo->prepare("
                    INSERT INTO system_logs (
                        user_id,
                        action,
                        module,
                        record_type,
                        record_id,
                        details
                    )
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

            $systemLog->execute([
                $_SESSION['user_id'],
                'DELETE_PRODUCT',
                'Inventory',
                'Product',
                $productId,
                'Deleted product '
                . $productLabel
                . '. Variants: '
                . ($variantSummary !== []
                    ? implode(', ', $variantSummary)
                    : 'none')
                . '. Stock removed: '
                . (int) $product['stock_quantity']
                . ' unit(s).'
            ]);


            $pdo->commit();


        } catch (Throwable $error) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            inventoryFlash(
                'error',
                'Unable to delete product: '
                . inventorySaveErrorMessage($error)
            );

            inventoryRedirect();
        }


        /*
         * Photo files are removed only after the database delete is saved.
         * Color photos are only removed when their file name belongs to
         * this product (product-<id>-color-...).
         */
        deleteProductImages(
            $productImageDirectory,
            $productId
        );

        $colorImagePrefix =
            'product-' . $productId . '-color-';

        foreach (array_unique($deletedImagePaths) as $imagePath) {

            $fileName =
                basename($imagePath);

            $filePath =
                $productImageDirectory
                . DIRECTORY_SEPARATOR
                . $fileName;

            if (
                str_starts_with($fileName, $colorImagePrefix) &&
                is_file($filePath)
            ) {
                @unlink($filePath);
            }
        }


        inventoryFlash(
            'success',
            $productLabel . ' was deleted.'
        );

        inventoryRedirect();
    }
}


/*
|--------------------------------------------------------------------------
| FLASH MESSAGES
|--------------------------------------------------------------------------
*/

$inventorySuccess =
    $_SESSION[
        'inventory_success'
    ] ?? null;


$inventoryError =
    $_SESSION[
        'inventory_error'
    ] ?? null;


unset(
    $_SESSION[
        'inventory_success'
    ],
    $_SESSION[
        'inventory_error'
    ]
);


/*
|--------------------------------------------------------------------------
| SETTINGS
|--------------------------------------------------------------------------
*/

$lowStockThreshold =
    10;


$reorderSalesWindowDays =
    30;


$reorderLeadTimeDays =
    7;


$reorderSafetyDays =
    3;


$reorderTargetDays =
    30;


$variantReorderFallback =
    5;


$variantTargetStockFallback =
    15;


$settingsStatement =
    $pdo->query("
        SELECT
            setting_key,
            setting_value
        FROM settings
        WHERE setting_key IN (
            'low_stock_threshold',
            'reorder_sales_window_days',
            'reorder_lead_time_days',
            'reorder_safety_days',
            'reorder_target_days',
            'variant_reorder_fallback',
            'variant_target_stock_fallback'
        )
    ");


foreach (
    $settingsStatement->fetchAll()
    as $setting
) {

    if (
        $setting['setting_key'] ===
        'low_stock_threshold'
    ) {

        $lowStockThreshold =
            max(
                0,
                (int) $setting[
                    'setting_value'
                ]
            );
    }


    if (
        $setting['setting_key'] ===
        'reorder_sales_window_days'
    ) {

        $reorderSalesWindowDays =
            max(
                1,
                (int) $setting[
                    'setting_value'
                ]
            );
    }


    if (
        $setting['setting_key'] ===
        'reorder_lead_time_days'
    ) {

        $reorderLeadTimeDays =
            max(
                1,
                (int) $setting[
                    'setting_value'
                ]
            );
    }


    if (
        $setting['setting_key'] ===
        'reorder_safety_days'
    ) {

        $reorderSafetyDays =
            max(
                0,
                (int) $setting[
                    'setting_value'
                ]
            );
    }


    if (
        $setting['setting_key'] ===
        'reorder_target_days'
    ) {

        $reorderTargetDays =
            max(
                1,
                (int) $setting[
                    'setting_value'
                ]
            );
    }


    if (
        $setting['setting_key'] ===
        'variant_reorder_fallback'
    ) {

        $variantReorderFallback =
            max(
                1,
                (int) $setting[
                    'setting_value'
                ]
            );
    }


    if (
        $setting['setting_key'] ===
        'variant_target_stock_fallback'
    ) {

        $variantTargetStockFallback =
            max(
                1,
                (int) $setting[
                    'setting_value'
                ]
            );
    }
}


/*
|--------------------------------------------------------------------------
| ACTIVE CATEGORIES
|--------------------------------------------------------------------------
|
| Sort alphabetically, but always place "Other" last.
|--------------------------------------------------------------------------
*/

$categoryStatement =
    $pdo->query("
        SELECT
            id,
            name,
            description
        FROM categories
        WHERE status = 'Active'
        ORDER BY
            CASE
                WHEN name = 'Other' THEN 1
                ELSE 0
            END,
            name ASC
    ");


$categories =
    $categoryStatement->fetchAll();


/*
|--------------------------------------------------------------------------
| PRODUCTS
|--------------------------------------------------------------------------
|
| Product photos are stored in:
| public/assets/images/products/
|
| There is intentionally NO image_path database column.
|--------------------------------------------------------------------------
*/

$productStatement =
    $pdo->query("
        SELECT
            p.id,
            p.product_code,
            p.product_name,
            p.category_id,
            p.cost_price,
            p.selling_price,
            p.stock_quantity,
            p.reorder_level,
            p.status,
            p.created_at,
            p.updated_at,

            c.name AS category_name

        FROM products p

        LEFT JOIN categories c
            ON c.id = p.category_id

        ORDER BY
            p.product_name ASC
    ");


$products =
    $productStatement->fetchAll();


/*
|--------------------------------------------------------------------------
| PRODUCT LIFECYCLE / LAST RESTOCK
|--------------------------------------------------------------------------
|
| Clothing does not use expiration dates. Date Added comes from
| products.created_at, while Last Restocked comes from supplier stock
| receipts. Opening stock is intentionally not treated as a restock.
|--------------------------------------------------------------------------
*/

$lastRestockStatement =
    $pdo->query("
        SELECT
            sri.product_id,
            MAX(sr.created_at) AS last_restocked_at
        FROM stock_receipt_items sri
        INNER JOIN stock_receipts sr
            ON sr.id = sri.stock_receipt_id
        GROUP BY sri.product_id
    ");


$lastRestockedByProduct = [];


foreach ($lastRestockStatement->fetchAll() as $lastRestockRow) {

    $lastRestockedByProduct[
        (int) $lastRestockRow['product_id']
    ] = (string) (
        $lastRestockRow['last_restocked_at']
        ?? ''
    );
}


/*
|--------------------------------------------------------------------------
| PRODUCTS WITH SALES / RESTOCK HISTORY
|--------------------------------------------------------------------------
|
| Used to explain why the Delete button is blocked. The delete handler
| checks the same rules again on the server.
|--------------------------------------------------------------------------
*/

$productsWithHistory = [];


foreach (
    $pdo->query("
        SELECT DISTINCT product_id FROM sale_items
        UNION
        SELECT DISTINCT product_id FROM stock_receipt_items
    ")->fetchAll()
    as $historyRow
) {
    $productsWithHistory[
        (int) $historyRow['product_id']
    ] = true;
}


$canDeleteProducts =
    ($_SESSION['role'] ?? '') === 'Admin';


$variantStatement = $pdo->query("
    SELECT id, product_id, color, color_hex, size, sku, barcode,
           stock_quantity, status, image_path
    FROM product_variants
    WHERE NOT (status = 'Inactive' AND color LIKE 'Archived-%')
    ORDER BY product_id, color, size
");


$variantsByProduct = [];


foreach ($variantStatement->fetchAll() as $variant) {

    $variantId =
        (int) $variant['id'];


    $variantStock =
        (int) $variant[
            'stock_quantity'
        ];


    $variantsByProduct[
        (int) $variant['product_id']
    ][] = [
        'id' =>
            $variantId,

        'color' =>
            (string) $variant['color'],

        'color_hex' =>
            (string) (
                $variant[
                    'color_hex'
                ]
                ?? ''
            ),

        'size' =>
            (string) $variant['size'],

        'sku' =>
            (string) $variant['sku'],

        'barcode' =>
            (string) $variant['barcode'],

        'stock_quantity' =>
            $variantStock,

        'status' =>
            (string) $variant['status'],

        'image_path' =>
            (string) (
                $variant[
                    'image_path'
                ]
                ?? ''
            ),

        'reorder_metrics' =>
            inventoryAutomaticVariantReorderMetrics(
                $pdo,
                $variantId,
                $variantStock
            )
    ];
}


/*
 * Display order: colors stay in the same order as before; sizes inside
 * each color go XS, S, M, L, XL, 2XL, 3XL, One Size instead of
 * alphabetical (L, M, S, XL, XS). Used by the product list, the product
 * editor and the restock variant list.
 */
foreach ($variantsByProduct as &$productVariantList) {

    usort(
        $productVariantList,
        static fn (array $a, array $b): int =>
            [
                $a['color'],
                inventorySizeRank($a['size']),
                $a['size']
            ]
            <=>
            [
                $b['color'],
                inventorySizeRank($b['size']),
                $b['size']
            ]
    );
}

unset($productVariantList);


$automaticReorderByProduct = [];


foreach ($products as $product) {

    $productId =
        (int) $product['id'];


    $automaticReorderByProduct[
        $productId
    ] =
        inventoryAggregateVariantReorderMetrics(
            $variantsByProduct[
                $productId
            ] ?? [],
            $variantReorderFallback,
            $variantTargetStockFallback
        );
}



/*
|--------------------------------------------------------------------------
| ACTIVE SUPPLIERS LINKED TO PRODUCTS
|--------------------------------------------------------------------------
|
| Supplier links remain product-level. During a restock the Manager chooses
| the exact variant that was received.
|
*/

$supplierLinkStatement = $pdo->query("\n    SELECT\n        ps.product_id,\n        s.id AS supplier_id,\n        s.supplier_name,\n        ps.supplier_price,\n        ps.is_primary\n    FROM product_suppliers ps\n    INNER JOIN suppliers s\n        ON s.id = ps.supplier_id\n    WHERE s.status = 'Active'\n    ORDER BY\n        ps.product_id ASC,\n        ps.is_primary DESC,\n        s.supplier_name ASC\n");


/*
 * All active suppliers, for linking one from the Restock window when a
 * product has no supplier yet.
 */
$activeSuppliers =
    $pdo->query("
        SELECT
            id,
            supplier_name
        FROM suppliers
        WHERE status = 'Active'
        ORDER BY supplier_name ASC
    ")->fetchAll();


$suppliersByProduct = [];


foreach ($supplierLinkStatement->fetchAll() as $supplierLink) {

    $suppliersByProduct[(int) $supplierLink['product_id']][] = [
        'id' => (int) $supplierLink['supplier_id'],
        'supplier_name' => (string) $supplierLink['supplier_name'],
        'supplier_price' => (float) $supplierLink['supplier_price'],
        'is_primary' => (int) $supplierLink['is_primary']
    ];
}


/*
| All linked suppliers, including inactive ones, for loss-tracing in the
| Record Stock Loss modal (a past delivery can still turn out defective).
*/

$lossSuppliersByProduct = [];

foreach ($pdo->query("
    SELECT
        ps.product_id,
        s.id AS supplier_id,
        s.supplier_name,
        s.status
    FROM product_suppliers ps
    INNER JOIN suppliers s
        ON s.id = ps.supplier_id
    ORDER BY
        ps.product_id ASC,
        ps.is_primary DESC,
        s.supplier_name ASC
")->fetchAll() as $lossSupplier) {

    $lossSuppliersByProduct[(int) $lossSupplier['product_id']][] = [
        'id' => (int) $lossSupplier['supplier_id'],
        'supplier_name' => (string) $lossSupplier['supplier_name'],
        'status' => (string) $lossSupplier['status']
    ];
}


/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/

$totalProducts =
    count($products);


$activeProducts =
    0;


$variantsToRestock =
    0;


$outOfStockVariants =
    0;


foreach ($products as $product) {

    if (
        $product['status'] ===
        'Active'
    ) {

        $activeProducts++;


        foreach (
            $variantsByProduct[
                (int) $product['id']
            ] ?? []
            as $variant
        ) {

            if (
                $variant['status'] !==
                'Active'
            ) {
                continue;
            }


            $variantStock =
                (int) $variant[
                    'stock_quantity'
                ];


            if ($variantStock <= 0) {
                $outOfStockVariants++;
            }


            if (
                !empty(
                    $variant[
                        'reorder_metrics'
                    ][
                        'needs_reorder'
                    ]
                    ?? false
                )
            ) {
                $variantsToRestock++;
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| EDIT DATA
|--------------------------------------------------------------------------
*/

$productEditData = [];


foreach ($products as $product) {

    $productId =
        (int) $product['id'];


    $productEditData[
        $productId
    ] = [

        'id' =>
            $productId,

        'product_code' =>
            (string) $product[
                'product_code'
            ],

        'product_name' =>
            (string) $product[
                'product_name'
            ],

        'category_id' =>
            $product[
                'category_id'
            ] !== null
                ? (int) $product[
                    'category_id'
                ]
                : '',

        'cost_price' =>
            (float) $product[
                'cost_price'
            ],

        'selling_price' =>
            (float) $product[
                'selling_price'
            ],

        'stock_quantity' =>
            (int) $product[
                'stock_quantity'
            ],

        'reorder_level' =>
            (int) (
                $automaticReorderByProduct[
                    $productId
                ]['reorder_level']
                ?? $lowStockThreshold
            ),

        'reorder_metrics' =>
            $automaticReorderByProduct[
                $productId
            ] ?? [
                'reorder_level' => $variantReorderFallback,
                'target_stock' => $variantTargetStockFallback,
                'suggested_restock' => 0,
                'units_sold' => 0,
                'average_daily_sales' => 0,
                'low_stock_variants' => 0,
                'active_variant_count' => 0,
                'source' => 'baseline'
            ],

        'date_added' =>
            inventoryDisplayDate(
                (string) (
                    $product['created_at']
                    ?? ''
                ),
                true
            ),

        'last_restocked' =>
            inventoryDisplayDate(
                $lastRestockedByProduct[
                    $productId
                ] ?? null,
                true
            ),

        'status' =>
            (string) $product[
                'status'
            ],

        'photo_url' =>
            getProductImageUrl(
                $productImageDirectory,
                $productId
            ),

        'variants' =>
            $variantsByProduct[$productId]
            ?? [],

        'suppliers' =>
            $suppliersByProduct[$productId]
            ?? [],

        'loss_suppliers' =>
            $lossSuppliersByProduct[$productId]
            ?? []

    ];
}


$nextProductCodePreview =
    inventoryNextProductCode(
        $pdo
    );


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
    href="/assets/css/inventory.css?v=20261008-2"
>

<style>
/* =========================================================
   INVENTORY PATCH - RESTOCK + AUTO REORDER + VARIANT UX
========================================================= */

.inventory-alert[hidden] {
    display: none !important;
}

.inventory-field small {
    display: block;
    margin-top: 8px;
    color: rgba(255,255,255,.36);
    font-size: var(--ua-text-xs);
    line-height: 1.5;
}

/* Restock modal styles live in /assets/css/inventory.css (RESTOCK MODAL). */

.inventory-variant-chip.needs-restock {
    border-color: rgba(255, 178, 102, .42);
    background: rgba(255, 178, 102, .08);
    color: #ffd3aa;
}

.inventory-field select:disabled,
.inventory-field input:disabled {
    opacity: .5;
    cursor: not-allowed;
}


/* =========================================================
   AUTOMATIC REORDER POINT
========================================================= */

.inventory-auto-reorder-card {
    min-height: 72px;
    display: flex;
    justify-content: center;
    flex-direction: column;
    gap: 7px;
    padding: 13px 16px;
    border: 1px solid rgba(255,255,255,.09);
    border-radius: 8px;
    background: rgba(255,255,255,.028);
}

.inventory-auto-reorder-value {
    display: flex;
    align-items: center;
    gap: 9px;
}

.inventory-auto-reorder-value .material-symbols-rounded {
    color: rgba(255,255,255,.48);
    font-size: 19px;
}

.inventory-auto-reorder-value strong {
    color: #fff;
    font-size: var(--ua-text-base);
    font-weight: 600;
}


/* =========================================================
   APPAREL INVENTORY LIFECYCLE
========================================================= */

.inventory-lifecycle-card {
    min-height: 72px;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    padding: 12px;
    border: 1px solid rgba(255,255,255,.09);
    border-radius: 8px;
    background: rgba(255,255,255,.028);
}

.inventory-lifecycle-item {
    min-width: 0;
    display: flex;
    justify-content: center;
    flex-direction: column;
    gap: 4px;
    padding: 10px 13px;
    border: 1px solid rgba(255,255,255,.055);
    border-radius: 7px;
    background: rgba(0,0,0,.16);
}

.inventory-lifecycle-item span {
    color: rgba(255,255,255,.36);
    font-size: var(--ua-text-xs);
    font-weight: 600;
    letter-spacing: .055em;
    text-transform: uppercase;
}

.inventory-lifecycle-item strong {
    overflow: hidden;
    color: rgba(255,255,255,.88);
    font-size: var(--ua-text-sm);
    font-weight: 500;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.inventory-lifecycle-auto {
    min-height: 72px;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 16px;
    border: 1px solid rgba(255,255,255,.09);
    border-radius: 8px;
    background: rgba(255,255,255,.028);
}

.inventory-lifecycle-auto .material-symbols-rounded {
    flex: 0 0 auto;
    color: rgba(255,255,255,.48);
    font-size: 20px;
}

.inventory-lifecycle-auto strong {
    display: block;
    color: #fff;
    font-size: var(--ua-text-sm);
    font-weight: 600;
}

.inventory-lifecycle-auto small {
    display: block;
    margin-top: 4px;
    color: rgba(255,255,255,.38);
    font-size: var(--ua-text-xs);
    line-height: 1.5;
}

.inventory-activity {
    min-width: 132px;
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.inventory-activity-row {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.inventory-activity-row span {
    color: rgba(255,255,255,.3);
    font-size: var(--ua-text-xs);
    font-weight: 600;
    letter-spacing: .04em;
    text-transform: uppercase;
}

.inventory-activity-row strong {
    color: rgba(255,255,255,.68);
    font-size: var(--ua-text-xs);
    font-weight: 500;
}

@media (max-width: 700px) {
    .inventory-lifecycle-card {
        grid-template-columns: 1fr;
    }
}


/* =========================================================
   PRODUCT MODAL WIDTH
========================================================= */

#addProductModal .inventory-modal-card,
#editProductModal .inventory-modal-card {
    max-width: 980px;
}


/* =========================================================
   VARIANT EDITOR - CARD LAYOUT
========================================================= */

.variant-editor {
    margin: 28px 0 0;
    padding: 22px;
    border: 1px solid rgba(255,255,255,.09);
    border-radius: 12px;
    background: rgba(255,255,255,.018);
}

.variant-editor-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 22px;
    margin-bottom: 18px;
}

.variant-editor-heading > div {
    min-width: 0;
}

.variant-editor-heading strong {
    color: #fff;
    font-size: 14px;
    font-weight: 600;
}

.variant-editor-heading p {
    margin: 5px 0 0;
    color: rgba(255,255,255,.4);
    font-size: var(--ua-text-sm);
    line-height: 1.5;
}

.variant-rows {
    display: grid;
    gap: 16px;
}

.variant-row {
    display: block;
    padding: 0;
    overflow: hidden;
    border: 1px solid rgba(255,255,255,.09);
    border-radius: 11px;
    background: #101113;
}

.variant-card-header {
    min-height: 50px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 9px 11px 9px 14px;
    border-bottom: 1px solid rgba(255,255,255,.065);
    background: rgba(255,255,255,.025);
}

.variant-card-title {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.variant-card-title strong {
    color: #fff;
    font-size: var(--ua-text-md);
    font-weight: 600;
}

.variant-card-title small {
    overflow: hidden;
    color: rgba(255,255,255,.36);
    font-size: var(--ua-text-xs);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.variant-card-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.variant-remove {
    width: 34px;
    height: 34px;
    display: grid;
    flex: 0 0 34px;
    place-items: center;
    border: 1px solid rgba(248,113,113,.22);
    border-radius: 7px;
    background: rgba(127,29,29,.13);
    color: #fca5a5;
    cursor: pointer;
}

.variant-remove:hover {
    border-color: rgba(248,113,113,.4);
    background: rgba(127,29,29,.24);
}

.variant-remove .material-symbols-rounded {
    font-size: 18px;
}

.variant-card-grid {
    display: grid;
    grid-template-columns:
        minmax(180px, 1.3fr)
        78px
        minmax(115px, .7fr)
        minmax(180px, 1fr)
        minmax(220px, 1.25fr);
    gap: 16px;
    padding: 18px;
}

.variant-field {
    min-width: 0;
}

.variant-field label {
    display: block;
    margin-bottom: 8px;
    color: rgba(255,255,255,.44);
    font-size: var(--ua-text-xs);
    font-weight: 600;
    letter-spacing: .05em;
    text-transform: uppercase;
}

.variant-field input,
.variant-field select {
    width: 100%;
    height: 40px;
    min-height: 40px;
    padding: 0 10px;
    border: 1px solid rgba(255,255,255,.11);
    border-radius: 7px;
    outline: none;
    background: #0c0d0f;
    color: #fff;
    font-family: 'Poppins', sans-serif;
    font-size: var(--ua-text-sm);
}

.variant-field input:focus,
.variant-field select:focus {
    border-color: rgba(255,255,255,.34);
}

.variant-field input[readonly] {
    background: rgba(255,255,255,.025);
    color: rgba(255,255,255,.55);
    cursor: not-allowed;
}

.variant-field.color-field input {
    padding: 5px;
    cursor: pointer;
}

.variant-field.wide {
    grid-column: span 2;
}

.variant-inventory-row {
    display: grid;
    grid-template-columns:
        minmax(160px, .8fr)
        minmax(160px, .8fr)
        1fr;
    gap: 16px;
    padding: 0 18px 18px;
}

.variant-stock-help {
    display: flex;
    align-items: center;
    gap: 6px;
    min-height: 40px;
    padding: 0 10px;
    border: 1px solid rgba(255,255,255,.065);
    border-radius: 7px;
    background: rgba(255,255,255,.02);
    color: rgba(255,255,255,.34);
    font-size: var(--ua-text-xs);
    line-height: 1.45;
}

.variant-stock-help .material-symbols-rounded {
    flex: 0 0 auto;
    font-size: 17px;
}


/* =========================================================
   RESPONSIVE VARIANTS
========================================================= */

@media (max-width: 1050px) {
    #addProductModal .inventory-modal-card,
    #editProductModal .inventory-modal-card {
        max-width: calc(100vw - 36px);
    }

    .variant-card-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .variant-inventory-row {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .variant-stock-help {
        grid-column: 1 / -1;
    }
}

@media (max-width: 700px) {
    .variant-editor-heading {
        align-items: stretch;
        flex-direction: column;
    }

    .variant-editor-heading .inventory-secondary-button {
        width: 100%;
    }

    .variant-card-grid,
    .variant-inventory-row {
        grid-template-columns: 1fr;
    }

    .variant-field.wide,
    .variant-stock-help {
        grid-column: auto;
    }
}
</style>


<style>
/* =========================================================
   COLOR-GROUP VARIANT EDITOR
========================================================= */
.color-groups{display:grid;gap: 20px;margin-top:14px}.color-group{border:1px solid rgba(255,255,255,.09);border-radius:16px;background:rgba(7,10,17,.56);overflow:hidden}.color-group-header{display:flex;align-items:center;justify-content:space-between;gap: 18px;padding: 18px 20px;border-bottom:1px solid rgba(255,255,255,.07);background:rgba(255,255,255,.025)}.color-group-title{display:flex;align-items:center;gap: 13px;min-width:0}.color-group-swatch{width:24px;height:24px;border-radius:50%;border:2px solid rgba(255,255,255,.18);box-shadow:0 0 0 3px rgba(255,255,255,.025);flex:0 0 auto}.color-group-title strong{display:block;font-size:var(--ua-text-base);color:#f3f4f6}.color-group-title small{display:block;margin-top: 3px;font-size:var(--ua-text-sm);color:#777f8f}.color-group-remove,.color-size-remove,.color-photo-remove{border:1px solid rgba(255,255,255,.09);background:rgba(255,255,255,.035);color:#a6adba;border-radius:9px;cursor:pointer}.color-group-remove{width:34px;height:34px;display:grid;place-items:center}.color-group-remove:hover,.color-size-remove:hover,.color-photo-remove:hover{border-color:rgba(238,113,113,.45);color:#ffaaaa;background:rgba(238,113,113,.08)}.color-group-body{display:grid;grid-template-columns:190px minmax(0,1fr);gap: 22px;padding:16px}.color-photo-panel{min-width:0}.color-photo-preview{height:168px;border:1px dashed rgba(255,255,255,.14);border-radius:13px;background:#f1f1f1;display:flex;flex-direction:column;align-items:center;justify-content:center;overflow:hidden;color:#20242d}.color-photo-preview img{width:100%;height:100%;object-fit:contain}.color-photo-preview .material-symbols-rounded{font-size:42px}.color-photo-preview small{font-size:var(--ua-text-sm);margin-top:4px}.color-photo-actions{display:grid;gap: 9px;margin-top:9px}.color-photo-upload{display:flex;align-items:center;justify-content:center;gap:6px;min-height:35px;padding:0 10px;border-radius:9px;border:1px solid rgba(255,255,255,.11);background:rgba(255,255,255,.05);color:#e5e7eb;font-size:var(--ua-text-sm);font-weight:600;cursor:pointer}.color-photo-upload:hover{background:rgba(255,255,255,.08)}.color-photo-upload input{display:none}.color-photo-remove{min-height:32px;font:inherit;font-size:var(--ua-text-xs)}.color-group-content{min-width:0}.color-details-grid{display:grid;grid-template-columns:minmax(180px,1fr) 120px;gap: 13px;margin-bottom:14px}.color-field label,.color-size-field label{display:block;margin-bottom: 7px;font-size:var(--ua-text-xs);font-weight:600;color:#aeb4c0}.color-field input,.color-size-field input,.color-size-field select{width:100%;min-height:38px;border:1px solid rgba(255,255,255,.1);border-radius:9px;background:#0d1119;color:#eef1f5;padding:8px 10px;font:inherit;font-size:var(--ua-text-sm);outline:none}.color-field input:focus,.color-size-field input:focus,.color-size-field select:focus{border-color:rgba(208,173,123,.7);box-shadow:0 0 0 2px rgba(208,173,123,.08)}.color-field input[type=color]{padding:4px;height:38px}.color-sizes-header{display:flex;align-items:center;justify-content:space-between;gap: 16px;margin:4px 0 8px}.color-sizes-header div strong{display:block;font-size:var(--ua-text-md);color:#e9ebef}.color-sizes-header div small{display:block;margin-top: 3px;color:#727a88;font-size:var(--ua-text-xs)}.color-add-size{display:inline-flex;align-items:center;gap: 7px;border:1px solid rgba(208,173,123,.28);background:rgba(208,173,123,.08);color:#e5c69b;border-radius:8px;padding: 9px 12px;font:inherit;font-size:var(--ua-text-xs);font-weight:600;cursor:pointer}.color-add-size:hover{background:rgba(208,173,123,.14)}.color-size-list{display:grid;gap:8px}.color-size-row{display:grid;grid-template-columns:105px minmax(145px,1fr) 130px 95px 105px 34px;gap: 10px;align-items:end;padding: 13px;border:1px solid rgba(255,255,255,.065);border-radius:11px;background:rgba(255,255,255,.022)}.color-size-field{min-width:0}.color-size-field input[readonly]{color:#939ba9;background:#090c12}.color-size-remove{width:34px;height:38px;display:grid;place-items:center}.color-group-note{display:flex;gap: 9px;align-items:flex-start;margin-top: 13px;padding: 12px 13px;border-radius:9px;background:rgba(255,255,255,.025);color:#747d8c;font-size:var(--ua-text-xs);line-height:1.5}.color-group-note .material-symbols-rounded{font-size:16px;color:#d0ad7b}.color-group-empty{padding: 28px;border:1px dashed rgba(255,255,255,.12);border-radius:14px;text-align:center;color:#7b8390}.color-group-empty .material-symbols-rounded{font-size:30px;display:block;margin-bottom:5px}.color-group-empty strong{display:block;color:#d9dde4;font-size:var(--ua-text-md)}.color-group-empty small{font-size:var(--ua-text-xs)}.inventory-variant-chip.has-photo:after{content:'photo';font-family:'Material Symbols Rounded';font-size:var(--ua-text-base);margin-left: 4px;color:#d0ad7b}@media(max-width:1150px){.color-group-body{grid-template-columns:160px minmax(0,1fr)}.color-size-row{grid-template-columns:95px minmax(130px,1fr) 120px 90px 100px 34px}}@media(max-width:900px){.color-group-body{grid-template-columns:1fr}.color-photo-panel{max-width:260px}.color-size-row{grid-template-columns:repeat(2,minmax(0,1fr))}.color-size-remove{align-self:end}.color-details-grid{grid-template-columns:1fr 110px}}@media(max-width:560px){.color-size-row{grid-template-columns:1fr}.color-details-grid{grid-template-columns:1fr}.color-photo-panel{max-width:none}}
</style>

<div class="inventory-page">


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <div class="inventory-top">

        <div>

            <div class="inventory-eyebrow">
                INVENTORY CONTROL
            </div>

            <h2>
                Inventory Management
            </h2>

            <p>
                Manage apparel products, variants,
                supplier restocks and stock levels.
            </p>

        </div>


        <button
            type="button"
            class="inventory-primary-button"
            id="openAddProduct"
        >

            <span class="material-symbols-rounded">
                add
            </span>

            Add Product

        </button>

    </div>



    <!-- =====================================================
         ALERTS
    ====================================================== -->

    <?php if ($inventorySuccess): ?>

        <div class="inventory-alert success">

            <span class="material-symbols-rounded">
                check_circle
            </span>

            <?= htmlspecialchars(
                $inventorySuccess
            ) ?>

        </div>

    <?php endif; ?>


    <?php if ($inventoryError): ?>

        <div class="inventory-alert error">

            <span class="material-symbols-rounded">
                error
            </span>

            <?= htmlspecialchars(
                $inventoryError
            ) ?>

        </div>

    <?php endif; ?>



    <!-- =====================================================
         STATS
    ====================================================== -->

    <div class="inventory-stats">


        <div class="inventory-stat-card">

            <div class="stat-icon">

                <span class="material-symbols-rounded">
                    inventory_2
                </span>

            </div>

            <div>

                <span>
                    Total Products
                </span>

                <strong>
                    <?= $totalProducts ?>
                </strong>

            </div>

        </div>


        <div class="inventory-stat-card">

            <div class="stat-icon">

                <span class="material-symbols-rounded">
                    check_circle
                </span>

            </div>

            <div>

                <span>
                    Active Products
                </span>

                <strong>
                    <?= $activeProducts ?>
                </strong>

            </div>

        </div>


        <div class="inventory-stat-card">

            <div class="stat-icon">

                <span class="material-symbols-rounded">
                    warning
                </span>

            </div>

            <div>

                <span>
                    Variants to Restock
                </span>

                <strong>
                    <?= $variantsToRestock ?>
                </strong>

            </div>

        </div>


        <div class="inventory-stat-card">

            <div class="stat-icon">

                <span class="material-symbols-rounded">
                    production_quantity_limits
                </span>

            </div>

            <div>

                <span>
                    Out of Stock Variants
                </span>

                <strong>
                    <?= $outOfStockVariants ?>
                </strong>

            </div>

        </div>


    </div>



    <!-- =====================================================
         PRODUCT INVENTORY
    ====================================================== -->

    <div class="inventory-card">


        <div class="inventory-card-header">

            <div>

                <h3>
                    Product Inventory
                </h3>

                <p>
                    <?= $totalProducts ?>

                    <?= $totalProducts === 1
                        ? 'product'
                        : 'products'
                    ?>
                </p>

            </div>


            <div class="inventory-toolbar">


                <select
                    id="categoryFilter"
                    class="inventory-filter-select"
                >

                    <option value="">
                        All Categories
                    </option>


                    <?php foreach (
                        $categories
                        as $category
                    ): ?>

                        <option
                            value="<?= htmlspecialchars(
                                strtolower(
                                    $category['name']
                                )
                            ) ?>"
                        >

                            <?= htmlspecialchars(
                                $category['name']
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>


                <div class="inventory-search">

                    <span class="material-symbols-rounded">
                        search
                    </span>

                    <input
                        type="text"
                        id="inventorySearch"
                        placeholder="Search product, code, SKU or variant barcode..."
                        autocomplete="off"
                    >

                </div>

            </div>

        </div>



        <div class="inventory-table-wrap">

            <table class="inventory-table">

                <thead>

                    <tr>

                        <th>Product</th>

                        <th>Product Code</th>

                        <th>Category</th>

                        <th>Price</th>

                        <th>Stock</th>

                        <th>Inventory Activity</th>

                        <th>Status</th>

                        <th>Actions</th>

                    </tr>

                </thead>


                <tbody>


                    <?php if (empty($products)): ?>

                        <tr>

                            <td
                                colspan="8"
                                class="inventory-empty-cell"
                            >

                                <div class="inventory-empty">

                                    <span class="material-symbols-rounded">
                                        inventory_2
                                    </span>

                                    <strong>
                                        No products yet
                                    </strong>

                                    <p>
                                        Add your first product
                                        to begin.
                                    </p>

                                </div>

                            </td>

                        </tr>


                    <?php else: ?>


                        <?php foreach (
                            $products
                            as $product
                        ): ?>


                            <?php

                            $productId =
                                (int) $product['id'];


                            $photoUrl =
                                getProductImageUrl(
                                    $productImageDirectory,
                                    $productId
                                );


                            if ($photoUrl === '') {
                                $photoUrl = inventoryFirstVariantImage(
                                    $variantsByProduct[$productId]
                                    ?? []
                                );
                            }


                            $categoryName =
                                trim(
                                    (string) (
                                        $product[
                                            'category_name'
                                        ]
                                        ?? ''
                                    )
                                );


                            if ($categoryName === '') {

                                $categoryName =
                                    'Uncategorized';
                            }


                            $stock =
                                (int) $product[
                                    'stock_quantity'
                                ];


                            $productReorderMetrics =
                                $automaticReorderByProduct[
                                    $productId
                                ] ?? [];


                            $warningLevel =
                                (int) (
                                    $productReorderMetrics[
                                        'reorder_level'
                                    ]
                                    ?? $variantReorderFallback
                                );


                            $lowStockVariantCount =
                                (int) (
                                    $productReorderMetrics[
                                        'low_stock_variants'
                                    ]
                                    ?? 0
                                );


                            $isLowStock =
                                $lowStockVariantCount >
                                0;


                            $searchText =
                                strtolower(
                                    $product[
                                        'product_name'
                                    ]
                                    . ' '
                                    . $product[
                                        'product_code'
                                    ]
                                    . ' '
                                    . $categoryName
                                    . ' '
                                    . implode(' ', array_map(
                                        static fn (array $variant): string =>
                                            $variant['color'] . ' ' . $variant['size'] . ' '
                                            . $variant['sku'] . ' ' . $variant['barcode'],
                                        $variantsByProduct[$productId] ?? []
                                    ))
                                );

                            ?>


                            <tr
                                class="inventory-product-row"

                                data-search="<?= htmlspecialchars(
                                    $searchText
                                ) ?>"

                                data-category="<?= htmlspecialchars(
                                    strtolower(
                                        $categoryName
                                    )
                                ) ?>"
                            >


                                <td>

                                    <div class="inventory-product">


                                        <div class="inventory-product-image">


                                            <?php if (
                                                $photoUrl !== ''
                                            ): ?>

                                                <img
                                                    src="<?= htmlspecialchars(
                                                        $photoUrl
                                                    ) ?>"
                                                    alt="<?= htmlspecialchars(
                                                        $product[
                                                            'product_name'
                                                        ]
                                                    ) ?>"
                                                >

                                            <?php else: ?>

                                                <span class="material-symbols-rounded">
                                                    image
                                                </span>

                                            <?php endif; ?>


                                        </div>


                                        <div>

                                            <strong>
                                                <?= htmlspecialchars(
                                                    $product[
                                                        'product_name'
                                                    ]
                                                ) ?>
                                            </strong>


                                            <small>

                                                Cost:

                                                ₱<?= number_format(
                                                    (float) $product[
                                                        'cost_price'
                                                    ],
                                                    2
                                                ) ?>

                                            </small>

                                            <div class="inventory-variant-chips">
                                                <?php foreach (($variantsByProduct[$productId] ?? []) as $variant): ?>
                                                    <?php if ($variant['status'] === 'Active'): ?>
                                                        <?php
                                                        $variantReorderMetrics =
                                                            $variant['reorder_metrics']
                                                            ?? [];

                                                        $variantNeedsReorder =
                                                            !empty(
                                                                $variantReorderMetrics[
                                                                    'needs_reorder'
                                                                ]
                                                            );

                                                        $variantReorderPoint =
                                                            (int) (
                                                                $variantReorderMetrics[
                                                                    'reorder_level'
                                                                ]
                                                                ?? $variantReorderFallback
                                                            );

                                                        $variantTargetStock =
                                                            (int) (
                                                                $variantReorderMetrics[
                                                                    'target_stock'
                                                                ]
                                                                ?? $variantTargetStockFallback
                                                            );

                                                        $variantSuggested =
                                                            (int) (
                                                                $variantReorderMetrics[
                                                                    'suggested_restock'
                                                                ]
                                                                ?? 0
                                                            );
                                                        ?>
                                                        <span
                                                            class="inventory-variant-chip<?= $variantNeedsReorder ? ' needs-restock' : '' ?>"
                                                            title="Reorder at <?= $variantReorderPoint ?> · Target <?= $variantTargetStock ?><?= $variantSuggested > 0 ? ' · Suggested +' . $variantSuggested : '' ?>"
                                                        >
                                                            <i style="background:<?= htmlspecialchars($variant['color_hex'] ?: '#777777') ?>"></i>
                                                            <?= htmlspecialchars($variant['color']) ?> · <?= htmlspecialchars($variant['size']) ?>
                                                            <b><?= (int) $variant['stock_quantity'] ?></b>
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>

                                        </div>

                                    </div>

                                </td>


                                <td>

                                    <span class="inventory-barcode">

                                        <?= htmlspecialchars(
                                            $product[
                                                'product_code'
                                            ]
                                        ) ?>

                                    </span>

                                </td>


                                <td>

                                    <span class="inventory-category">

                                        <?= htmlspecialchars(
                                            $categoryName
                                        ) ?>

                                    </span>

                                </td>


                                <td>

                                    <strong class="inventory-price">

                                        ₱<?= number_format(
                                            (float) $product[
                                                'selling_price'
                                            ],
                                            2
                                        ) ?>

                                    </strong>

                                </td>


                                <td>

                                    <div class="inventory-stock">

                                        <strong
                                            class="<?= $isLowStock
                                                ? 'low-stock'
                                                : ''
                                            ?>"
                                        >

                                            <?= $stock ?>

                                        </strong>


                                        <?php if ($isLowStock): ?>

                                            <span class="inventory-warning">
                                                <?= $lowStockVariantCount ?>
                                                <?= $lowStockVariantCount === 1
                                                    ? 'variant needs restock'
                                                    : 'variants need restock'
                                                ?>
                                            </span>

                                            <small>
                                                Aggregate reorder point:
                                                <?= $warningLevel ?>
                                            </small>

                                        <?php else: ?>

                                            <small>
                                                All active variants above reorder point
                                            </small>

                                        <?php endif; ?>

                                    </div>

                                </td>


                                <td>

                                    <div class="inventory-activity">

                                        <div class="inventory-activity-row">
                                            <span>Date Added</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    inventoryDisplayDate(
                                                        (string) (
                                                            $product['created_at']
                                                            ?? ''
                                                        )
                                                    )
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="inventory-activity-row">
                                            <span>Last Restocked</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    inventoryDisplayDate(
                                                        $lastRestockedByProduct[
                                                            $productId
                                                        ] ?? null
                                                    )
                                                ) ?>
                                            </strong>
                                        </div>

                                    </div>

                                </td>


                                <td>

                                    <span
                                        class="inventory-status <?= strtolower(
                                            $product[
                                                'status'
                                            ]
                                        ) ?>"
                                    >

                                        <?= htmlspecialchars(
                                            $product[
                                                'status'
                                            ]
                                        ) ?>

                                    </span>

                                </td>


                                <td>

                                    <div class="inventory-actions">


                                        <button
                                            type="button"

                                            class="inventory-icon-button edit-product-button"

                                            data-id="<?= $productId ?>"

                                            title="Edit product"
                                        >

                                            <span class="material-symbols-rounded">
                                                edit
                                            </span>

                                        </button>


                                        <button
                                            type="button"

                                            class="inventory-icon-button restock-button"

                                            data-id="<?= $productId ?>"

                                            data-name="<?= htmlspecialchars(
                                                $product[
                                                    'product_name'
                                                ]
                                            ) ?>"

                                            data-stock="<?= $stock ?>"

                                            title="Restock product"
                                        >

                                            <span class="material-symbols-rounded">
                                                add_box
                                            </span>

                                        </button>


                                        <button
                                            type="button"

                                            class="inventory-icon-button stock-loss-button"

                                            data-id="<?= $productId ?>"

                                            data-name="<?= htmlspecialchars(
                                                $product[
                                                    'product_name'
                                                ]
                                            ) ?>"

                                            title="Record damaged, defective or missing stock"

                                            aria-label="Record stock loss for <?= htmlspecialchars(
                                                $product[
                                                    'product_name'
                                                ]
                                            ) ?>"
                                        >

                                            <span class="material-symbols-rounded">
                                                heart_broken
                                            </span>

                                        </button>


                                        <form
                                            method="POST"
                                            action="/inventory/"
                                            id="inventoryStatusForm-<?= $productId ?>"
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

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="change_status"
                                            >

                                            <input
                                                type="hidden"
                                                name="product_id"
                                                value="<?= $productId ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="new_status"

                                                value="<?= $product[
                                                    'status'
                                                ] === 'Active'
                                                    ? 'Inactive'
                                                    : 'Active'
                                                ?>"
                                            >


                                            <button
                                                type="button"
                                                class="inventory-icon-button"

                                                title="<?= $product[
                                                    'status'
                                                ] === 'Active'
                                                    ? 'Deactivate product'
                                                    : 'Activate product'
                                                ?>"

                                                data-confirm

                                                data-confirm-title="<?= $product[
                                                    'status'
                                                ] === 'Active'
                                                    ? 'Deactivate product?'
                                                    : 'Activate product?'
                                                ?>"

                                                data-confirm-message="<?= htmlspecialchars(
                                                    $product[
                                                        'status'
                                                    ] === 'Active'
                                                        ? $product[
                                                            'product_name'
                                                        ]
                                                            . ' will no longer appear in active POS and restock workflows until reactivated. Existing stock, variants, and history will be preserved.'
                                                        : $product[
                                                            'product_name'
                                                        ]
                                                            . ' will become active again. Its active variants can be used in POS and restock workflows.'
                                                ) ?>"

                                                data-confirm-label="<?= $product[
                                                    'status'
                                                ] === 'Active'
                                                    ? 'Deactivate'
                                                    : 'Activate'
                                                ?>"

                                                data-confirm-icon="<?= $product[
                                                    'status'
                                                ] === 'Active'
                                                    ? 'visibility_off'
                                                    : 'visibility'
                                                ?>"

                                                data-inventory-confirm-form="inventoryStatusForm-<?= $productId ?>"
                                            >

                                                <span class="material-symbols-rounded">

                                                    <?= $product[
                                                        'status'
                                                    ] === 'Active'
                                                        ? 'visibility_off'
                                                        : 'visibility'
                                                    ?>

                                                </span>

                                            </button>

                                        </form>


                                        <?php if ($canDeleteProducts): ?>

                                            <?php

                                            $deleteBlockedReason = '';

                                            if (isset($productsWithHistory[$productId])) {

                                                $deleteBlockedReason =
                                                    $product['product_name']
                                                    . ' has sales or supplier restock history, so it cannot be deleted. Keep it Inactive instead.';

                                            } elseif ($product['status'] === 'Active') {

                                                $deleteBlockedReason =
                                                    $product['product_name']
                                                    . ' is Active. Deactivate it first, then delete it.';
                                            }


                                            $deleteVariantCount =
                                                count(
                                                    $variantsByProduct[$productId]
                                                    ?? []
                                                );

                                            ?>

                                            <form
                                                method="POST"
                                                action="/inventory/"
                                                id="inventoryDeleteForm-<?= $productId ?>"
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

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="delete_product"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="product_id"
                                                    value="<?= $productId ?>"
                                                >


                                                <?php if ($deleteBlockedReason !== ''): ?>

                                                    <button
                                                        type="button"
                                                        class="inventory-icon-button danger"
                                                        title="Delete product"
                                                        aria-label="Delete <?= htmlspecialchars($product['product_name']) ?> (not available)"
                                                        data-delete-blocked="<?= htmlspecialchars($deleteBlockedReason) ?>"
                                                    >

                                                        <span class="material-symbols-rounded">
                                                            delete
                                                        </span>

                                                    </button>

                                                <?php else: ?>

                                                    <button
                                                        type="button"
                                                        class="inventory-icon-button danger"
                                                        title="Delete product"
                                                        aria-label="Delete <?= htmlspecialchars($product['product_name']) ?>"

                                                        data-confirm

                                                        data-confirm-title="Delete product permanently?"

                                                        data-confirm-message="<?= htmlspecialchars(
                                                            $product['product_name']
                                                            . ' ('
                                                            . $product['product_code']
                                                            . ') and its '
                                                            . $deleteVariantCount
                                                            . ($deleteVariantCount === 1 ? ' variant' : ' variants')
                                                            . ($stock > 0
                                                                ? ', including ' . $stock . ' unit(s) of opening stock,'
                                                                : '')
                                                            . ' will be permanently deleted. This cannot be undone.'
                                                        ) ?>"

                                                        data-confirm-label="Delete Product"

                                                        data-confirm-icon="delete_forever"

                                                        data-confirm-form="inventoryDeleteForm-<?= $productId ?>"
                                                    >

                                                        <span class="material-symbols-rounded">
                                                            delete
                                                        </span>

                                                    </button>

                                                <?php endif; ?>

                                            </form>

                                        <?php endif; ?>


                                    </div>

                                </td>

                            </tr>


                        <?php endforeach; ?>


                    <?php endif; ?>


                </tbody>

            </table>

        </div>

    </div>

</div>



<!-- =========================================================
     ADD PRODUCT MODAL
========================================================= -->

<div
    class="inventory-modal"
    id="addProductModal"
    hidden
>

    <div class="inventory-modal-backdrop"></div>


    <div class="inventory-modal-card">

        <div class="inventory-modal-header">

            <div>

                <div class="inventory-eyebrow">
                    NEW INVENTORY ITEM
                </div>

                <h3>
                    Add Product
                </h3>

                <p>
                    Add product information and an
                    optional product photo.
                </p>

            </div>


            <button
                type="button"
                class="inventory-modal-close"
                data-close-add
                aria-label="Close"
            >

                <span class="material-symbols-rounded" aria-hidden="true">
                    close
                </span>

                <span class="inventory-modal-close-label">Close</span>

            </button>

        </div>


        <form
            method="POST"
            action="/inventory/"
            enctype="multipart/form-data"
            class="inventory-form"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $_SESSION['csrf_token']
                ) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="add_product"
            >


            <div class="product-image-upload">

                <div
                    class="product-image-preview"
                    id="addImagePreview"
                >

                    <span class="material-symbols-rounded">
                        add_photo_alternate
                    </span>

                    <small>
                        Cover Photo
                    </small>

                </div>


                <div class="product-image-upload-info">

                    <strong>
                        Default / Cover Photo
                    </strong>

                    <p>
                        Optional. POS uses this before a color is selected.
                        Each color can have its own photo below.
                    </p>


                    <label class="product-image-button">

                        <span class="material-symbols-rounded">
                            upload
                        </span>

                        Choose Image

                        <input
                            type="file"
                            id="addProductImage"
                            name="product_image"
                            accept="image/png,image/jpeg,image/webp"
                        >

                    </label>


                    <small>
                        JPG, PNG or WebP · Maximum 5 MB
                    </small>

                </div>

            </div>



            <div class="inventory-form-grid">


                <div class="inventory-field">

                    <label>
                        Product / Style Code
                    </label>

                    <input
                        type="text"
                        value="<?= htmlspecialchars($nextProductCodePreview) ?>"
                        readonly
                        aria-readonly="true"
                    >

                    <small>
                        Generated automatically for the parent product. This is not a sellable barcode.
                    </small>

                </div>


                <div class="inventory-field">

                    <label>
                        Product Name
                    </label>

                    <input
                        type="text"
                        name="product_name"
                        placeholder="Example: White Graphic Tee"
                        required
                    >

                </div>


                <div class="inventory-field">

                    <label>
                        Category
                    </label>

                    <select
                        name="category_id"
                    >

                        <option value="">
                            Uncategorized
                        </option>


                        <?php foreach (
                            $categories
                            as $category
                        ): ?>

                            <option
                                value="<?= (int) $category[
                                    'id'
                                ] ?>"
                            >

                                <?= htmlspecialchars(
                                    $category[
                                        'name'
                                    ]
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="inventory-field">

                    <label>
                        Status
                    </label>

                    <select
                        name="status"
                    >

                        <option value="Active">
                            Active
                        </option>

                        <option value="Inactive">
                            Inactive
                        </option>

                    </select>

                </div>


                <div class="inventory-field">

                    <label>
                        Cost Price
                    </label>

                    <div class="inventory-money-input">

                        <span>
                            ₱
                        </span>

                        <input
                            type="number"
                            name="cost_price"
                            min="0"
                            step="0.01"
                            required
                        >

                    </div>

                </div>


                <div class="inventory-field">

                    <label>
                        Selling Price
                    </label>

                    <div class="inventory-money-input">

                        <span>
                            ₱
                        </span>

                        <input
                            type="number"
                            name="selling_price"
                            min="0"
                            step="0.01"
                            required
                        >

                    </div>

                </div>


                <div class="inventory-field">

                    <label>
                        Automatic Reorder Point
                    </label>

                    <div class="inventory-auto-reorder-card">
                        <div class="inventory-auto-reorder-value">
                            <span class="material-symbols-rounded">
                                auto_graph
                            </span>

                            <strong>
                                <?= $variantReorderFallback ?> per new variant
                            </strong>
                        </div>

                        <small>
                            Each new variant starts with a fallback reorder point of
                            <?= $variantReorderFallback ?> and target stock of
                            <?= $variantTargetStockFallback ?> units until that exact variant develops sales history.
                            After that, it calculates the variant's reorder point and suggested restock automatically.
                        </small>
                    </div>

                </div>


                <div class="inventory-field full">

                    <label>
                        Product Timeline
                    </label>

                    <div class="inventory-lifecycle-auto">
                        <span class="material-symbols-rounded">
                            schedule
                        </span>

                        <div>
                            <strong>Date Added is automatic</strong>
                            <small>
                                It records the date when this product is created.
                                Last Restocked will appear automatically after the first supplier restock.
                            </small>
                        </div>
                    </div>

                </div>

            </div>


            <section class="variant-editor color-variant-editor" data-color-editor="add">
                <div class="variant-editor-heading">
                    <div>
                        <strong>Colors & Sizes</strong>
                        <p>Add one color card, upload one photo for that color, then add every available size inside it.</p>
                    </div>
                    <button type="button" class="inventory-secondary-button" data-add-color="add">
                        <span class="material-symbols-rounded">palette</span>
                        Add Color
                    </button>
                </div>
                <input type="hidden" name="variants_json" id="addVariantsJson">
                <div class="color-groups" id="addColorGroups"></div>
            </section>


            <div class="inventory-modal-footer">

                <button
                    type="button"
                    class="inventory-secondary-button"
                    data-close-add
                >
                    Cancel
                </button>


                <button
                    type="submit"
                    class="inventory-primary-button"
                >

                    <span class="material-symbols-rounded">
                        add
                    </span>

                    Add Product

                </button>

            </div>

        </form>

    </div>

</div>



<!-- =========================================================
     EDIT PRODUCT MODAL
========================================================= -->

<div
    class="inventory-modal"
    id="editProductModal"
    hidden
>

    <div class="inventory-modal-backdrop"></div>


    <div class="inventory-modal-card">

        <div class="inventory-modal-header">

            <div>

                <div class="inventory-eyebrow">
                    PRODUCT DETAILS
                </div>

                <h3>
                    Edit Product
                </h3>

                <p>
                    Update the product, category or photo.
                </p>

            </div>


            <button
                type="button"
                class="inventory-modal-close"
                data-close-edit
                aria-label="Close"
            >

                <span class="material-symbols-rounded" aria-hidden="true">
                    close
                </span>

                <span class="inventory-modal-close-label">Close</span>

            </button>

        </div>


        <form
            method="POST"
            action="/inventory/"
            enctype="multipart/form-data"
            class="inventory-form"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $_SESSION['csrf_token']
                ) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="edit_product"
            >

            <input
                type="hidden"
                name="product_id"
                id="editProductId"
            >


            <div class="product-image-upload">

                <div
                    class="product-image-preview"
                    id="editImagePreview"
                >

                    <span class="material-symbols-rounded">
                        image
                    </span>

                    <small>
                        No Photo
                    </small>

                </div>


                <div class="product-image-upload-info">

                    <strong>
                        Default / Cover Photo
                    </strong>

                    <p>
                        Optional fallback image. Color-specific photos are
                        managed inside the color cards below.
                    </p>


                    <label class="product-image-button">

                        <span class="material-symbols-rounded">
                            upload
                        </span>

                        Replace Image

                        <input
                            type="file"
                            id="editProductImage"
                            name="product_image"
                            accept="image/png,image/jpeg,image/webp"
                        >

                    </label>


                    <label class="remove-image-check">

                        <input
                            type="checkbox"
                            name="remove_photo"
                            id="removeProductPhoto"
                        >

                        Remove current photo

                    </label>

                </div>

            </div>



            <div class="inventory-form-grid">


                <div class="inventory-field">

                    <label>
                        Product / Style Code
                    </label>

                    <input
                        type="text"
                        id="editProductCode"
                        readonly
                        aria-readonly="true"
                    >

                    <small>
                        Identifies the parent style. Sellable barcodes belong to variants below.
                    </small>

                </div>


                <div class="inventory-field">

                    <label>
                        Product Name
                    </label>

                    <input
                        type="text"
                        name="product_name"
                        id="editProductName"
                        required
                    >

                </div>


                <div class="inventory-field">

                    <label>
                        Category
                    </label>

                    <select
                        name="category_id"
                        id="editCategoryId"
                    >

                        <option value="">
                            Uncategorized
                        </option>


                        <?php foreach (
                            $categories
                            as $category
                        ): ?>

                            <option
                                value="<?= (int) $category[
                                    'id'
                                ] ?>"
                            >

                                <?= htmlspecialchars(
                                    $category[
                                        'name'
                                    ]
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="inventory-field">

                    <label>
                        Status
                    </label>

                    <select
                        name="status"
                        id="editStatus"
                    >

                        <option value="Active">
                            Active
                        </option>

                        <option value="Inactive">
                            Inactive
                        </option>

                    </select>

                </div>


                <div class="inventory-field">

                    <label>
                        Cost Price
                    </label>

                    <div class="inventory-money-input">

                        <span>
                            ₱
                        </span>

                        <input
                            type="number"
                            name="cost_price"
                            id="editCostPrice"
                            min="0"
                            step="0.01"
                            required
                        >

                    </div>

                </div>


                <div class="inventory-field">

                    <label>
                        Selling Price
                    </label>

                    <div class="inventory-money-input">

                        <span>
                            ₱
                        </span>

                        <input
                            type="number"
                            name="selling_price"
                            id="editSellingPrice"
                            min="0"
                            step="0.01"
                            required
                        >

                    </div>

                </div>


                <div class="inventory-field">

                    <label>
                        Aggregate Automatic Reorder Point
                    </label>

                    <div class="inventory-auto-reorder-card">
                        <div class="inventory-auto-reorder-value">
                            <span class="material-symbols-rounded">
                                auto_graph
                            </span>

                            <strong id="editAutoReorderLevel">
                                —
                            </strong>
                        </div>

                        <small id="editAutoReorderMeta">
                            Calculated from recent completed sales.
                        </small>
                    </div>

                </div>


                <div class="inventory-field">

                    <label>
                        Inventory Activity
                    </label>

                    <div class="inventory-lifecycle-card">

                        <div class="inventory-lifecycle-item">
                            <span>Date Added</span>
                            <strong id="editDateAdded">—</strong>
                        </div>

                        <div class="inventory-lifecycle-item">
                            <span>Last Restocked</span>
                            <strong id="editLastRestocked">—</strong>
                        </div>

                    </div>

                </div>

            </div>


            <section class="variant-editor color-variant-editor" data-color-editor="edit">
                <div class="variant-editor-heading">
                    <div>
                        <strong>Colors & Sizes</strong>
                        <p>Each color uses one shared photo. Sizes remain separate stock variants and keep their own SKU, barcode and reorder data.</p>
                    </div>
                    <button type="button" class="inventory-secondary-button" data-add-color="edit">
                        <span class="material-symbols-rounded">palette</span>
                        Add Color
                    </button>
                </div>
                <input type="hidden" name="variants_json" id="editVariantsJson">
                <div class="color-groups" id="editColorGroups"></div>
            </section>


            <div class="edit-stock-note">

                <span class="material-symbols-rounded">
                    inventory
                </span>

                Current stock:

                <strong id="editCurrentStock">
                    0
                </strong>

                units. Use Restock to change stock quantity.

            </div>


            <div class="inventory-modal-footer">

                <button
                    type="button"
                    class="inventory-secondary-button"
                    data-close-edit
                >
                    Cancel
                </button>


                <button
                    type="submit"
                    class="inventory-primary-button"
                >

                    <span class="material-symbols-rounded">
                        save
                    </span>

                    Save Changes

                </button>

            </div>

        </form>

    </div>

</div>



<!-- =========================================================
     RESTOCK MODAL
     ---------------------------------------------------------
     1. Pick the color/size that arrived.
     2. Enter the delivery details (supplier, quantity, cost).
     The summary shows the stock change before the receipt is saved.
========================================================= -->

<div
    class="inventory-modal restock-modal"
    id="restockModal"
    hidden
>

    <div class="inventory-modal-backdrop"></div>


    <div
        class="inventory-modal-card restock-card"
        role="dialog"
        aria-modal="true"
        aria-labelledby="restockTitle"
    >

        <div class="inventory-modal-header">

            <div>

                <div class="inventory-eyebrow">
                    STOCK RECEIPT
                </div>

                <h3 id="restockTitle">
                    Restock Product
                </h3>

                <p id="restockProductDescription">
                    Receive stock from a linked supplier.
                </p>

            </div>


            <button
                type="button"
                class="inventory-modal-close"
                data-close-restock
                aria-label="Close"
            >

                <span class="material-symbols-rounded" aria-hidden="true">
                    close
                </span>

                <span class="inventory-modal-close-label">Close</span>

            </button>

        </div>


        <!--
            LINK A SUPPLIER
            Shown by openRestockModal() only when the product has no
            active supplier yet. A separate form, because forms cannot
            be nested inside the restock form below.
        -->

        <div
            class="restock-link-supplier"
            id="restockLinkSupplier"
            hidden
        >

            <div class="restock-link-heading">

                <span class="material-symbols-rounded">
                    link
                </span>

                <div>
                    <strong>
                        No supplier is linked to this product yet
                    </strong>

                    <small id="restockLinkSupplierHelp">
                        Restocking records which supplier delivered the stock. Link one now, then restock.
                    </small>
                </div>

            </div>


            <?php if (empty($activeSuppliers)): ?>

                <p class="restock-link-empty">
                    There are no active suppliers yet. Add a supplier first, then link it here.
                </p>

            <?php else: ?>

                <form
                    method="POST"
                    action="/inventory/"
                    class="restock-link-form"
                    id="restockLinkSupplierForm"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars(
                            $_SESSION['csrf_token']
                        ) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="link_supplier"
                    >

                    <input
                        type="hidden"
                        name="product_id"
                        id="restockLinkProductId"
                    >


                    <div class="inventory-field">

                        <label for="restockLinkSupplierId">
                            Supplier
                        </label>

                        <select
                            name="supplier_id"
                            id="restockLinkSupplierId"
                            required
                        >
                            <?php foreach ($activeSuppliers as $activeSupplier): ?>
                                <option value="<?= (int) $activeSupplier['id'] ?>">
                                    <?= htmlspecialchars($activeSupplier['supplier_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                    </div>


                    <div class="inventory-field">

                        <label for="restockLinkSupplierPrice">
                            Supplier Price
                        </label>

                        <div class="inventory-money-input">

                            <span>
                                ₱
                            </span>

                            <input
                                type="number"
                                name="supplier_price"
                                id="restockLinkSupplierPrice"
                                min="0"
                                step="0.01"
                                required
                            >

                        </div>

                    </div>


                    <label class="restock-link-primary">

                        <input
                            type="checkbox"
                            name="is_primary"
                            value="1"
                            checked
                        >

                        Primary supplier for this product

                    </label>


                    <button
                        type="submit"
                        class="inventory-primary-button restock-link-submit"
                    >

                        <span class="material-symbols-rounded">
                            add_link
                        </span>

                        Link Supplier

                    </button>

                </form>

            <?php endif; ?>


            <a
                href="/suppliers/"
                class="restock-link-manage"
            >
                Manage supplier links in Suppliers

                <span class="material-symbols-rounded">
                    arrow_forward
                </span>
            </a>

        </div>


        <form
            method="POST"
            action="/inventory/"
            class="inventory-form restock-form"
            id="restockForm"
            novalidate
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $_SESSION['csrf_token']
                ) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="restock_product"
            >

            <input
                type="hidden"
                name="product_id"
                id="restockProductId"
            >


            <div
                class="inventory-alert error"
                id="restockUnavailableMessage"
                hidden
            >
                <span class="material-symbols-rounded">
                    error
                </span>

                <span id="restockUnavailableText">
                    Link this product to an active supplier before restocking it.
                </span>
            </div>


            <!-- 1. WHICH VARIANT ARRIVED -->

            <section class="restock-section">

                <div class="restock-section-head">

                    <h4>
                        <span class="restock-step-number">1</span>
                        Which color and size arrived?
                    </h4>

                    <div
                        class="restock-legend"
                        id="restockLegend"
                        hidden
                    >
                        <span class="low">Low stock</span>
                        <span class="out">Out of stock</span>
                    </div>

                </div>


                <div
                    class="restock-variant-picker"
                    id="restockVariantPicker"
                    role="radiogroup"
                    aria-label="Color and size"
                ></div>

                <small
                    class="restock-field-error"
                    id="restockVariantError"
                    role="alert"
                    hidden
                ></small>


                <div
                    class="restock-selected"
                    id="restockSelectedVariant"
                    hidden
                >

                    <div
                        class="restock-selected-image"
                        id="restockSelectedImage"
                    ></div>


                    <div class="restock-selected-info">

                        <strong id="restockSelectedLabel"></strong>

                        <span>
                            SKU <b id="restockSelectedSku"></b>
                            · Barcode <b id="restockSelectedBarcode"></b>
                        </span>

                    </div>


                    <div class="restock-selected-stock">

                        <span>In Stock</span>

                        <strong id="restockCurrentStock">
                            0
                        </strong>

                        <em
                            class="restock-stock-badge"
                            id="restockStockBadge"
                            hidden
                        ></em>

                    </div>

                </div>

            </section>


            <!-- 2. DELIVERY DETAILS -->

            <section class="restock-section">

                <div class="restock-section-head">

                    <h4>
                        <span class="restock-step-number">2</span>
                        Delivery details
                    </h4>

                </div>


                <div class="inventory-form-grid">

                    <div class="inventory-field full">

                        <label for="restockSupplierId">
                            Supplier
                        </label>

                        <select
                            name="supplier_id"
                            id="restockSupplierId"
                            required
                        ></select>

                    </div>


                    <div class="inventory-field">

                        <label for="restockQuantity">
                            Quantity Received
                        </label>

                        <div class="restock-quantity">

                            <button
                                type="button"
                                class="restock-quantity-button"
                                data-restock-step="-1"
                                aria-label="Decrease quantity"
                            >
                                <span class="material-symbols-rounded">
                                    remove
                                </span>
                            </button>

                            <input
                                type="number"
                                name="restock_quantity"
                                id="restockQuantity"
                                min="1"
                                step="1"
                                inputmode="numeric"
                                placeholder="Qty"
                                required
                                aria-describedby="restockQuantityError restockSuggestion"
                            >

                            <button
                                type="button"
                                class="restock-quantity-button"
                                data-restock-step="1"
                                aria-label="Increase quantity"
                            >
                                <span class="material-symbols-rounded">
                                    add
                                </span>
                            </button>

                        </div>

                        <small
                            class="restock-field-error"
                            id="restockQuantityError"
                            role="alert"
                            hidden
                        ></small>

                        <div
                            class="restock-suggestion"
                            id="restockSuggestion"
                            hidden
                        >
                            <span id="restockSuggestionText"></span>

                            <button
                                type="button"
                                id="restockUseSuggested"
                            >
                                Use
                            </button>
                        </div>

                    </div>


                    <div class="inventory-field">

                        <label for="restockUnitCost">
                            Cost per Unit
                        </label>

                        <div class="inventory-money-input">

                            <span>
                                ₱
                            </span>

                            <input
                                type="number"
                                name="unit_cost"
                                id="restockUnitCost"
                                min="0"
                                step="0.01"
                                inputmode="decimal"
                                required
                                aria-describedby="restockUnitCostError"
                            >

                        </div>

                        <small
                            class="restock-field-error"
                            id="restockUnitCostError"
                            role="alert"
                            hidden
                        ></small>

                        <small>
                            Supplier's price. Change it if the invoice is different.
                        </small>

                    </div>


                    <div class="inventory-field full">

                        <label for="restockNotes">
                            Notes <span class="restock-optional">Optional</span>
                        </label>

                        <textarea
                            name="restock_notes"
                            id="restockNotes"
                            rows="2"
                            placeholder="e.g. Supplier invoice no. or delivery remarks"
                        ></textarea>

                    </div>

                </div>

            </section>


            <!-- SUMMARY -->

            <div class="restock-summary">

                <div class="restock-summary-row">

                    <div>

                        <span>Stock After Restock</span>

                        <strong>
                            <span id="restockPreviewBefore">—</span>

                            <span class="material-symbols-rounded" aria-hidden="true">
                                arrow_forward
                            </span>

                            <span id="restockPreviewAfter">—</span>

                            <em id="restockPreviewAdded">+0</em>
                        </strong>

                    </div>


                    <div class="restock-summary-total">

                        <span>Total Cost</span>

                        <strong id="restockLineTotal">
                            ₱0.00
                        </strong>

                        <small id="restockTotalBreakdown">
                            0 × ₱0.00
                        </small>

                    </div>

                </div>


                <p
                    class="restock-summary-warning"
                    id="restockPreviewNote"
                    hidden
                ></p>

            </div>


            <div class="inventory-modal-footer">

                <button
                    type="button"
                    class="inventory-secondary-button"
                    data-close-restock
                >
                    Cancel
                </button>


                <button
                    type="submit"
                    class="inventory-primary-button"
                    id="restockSubmitButton"
                >

                    <span class="material-symbols-rounded">
                        inventory
                    </span>

                    <span id="restockSubmitText">
                        Receive Stock
                    </span>

                </button>

            </div>

        </form>

    </div>

</div>



<!-- =========================================================
     STOCK LOSS MODAL (damaged / defective / missing)
     Reuses the Restock modal layout classes.
========================================================= -->

<div
    class="inventory-modal restock-modal stock-loss-modal"
    id="stockLossModal"
    hidden
>

    <div class="inventory-modal-backdrop" data-close-stock-loss></div>


    <div
        class="inventory-modal-card restock-card"
        role="dialog"
        aria-modal="true"
        aria-labelledby="stockLossTitle"
    >

        <div class="inventory-modal-header">

            <div>
                <div class="inventory-eyebrow">STOCK LOSS</div>
                <h3 id="stockLossTitle">Record Damaged or Missing Stock</h3>
                <p id="stockLossDescription">Remove units that can no longer be sold.</p>
            </div>

            <button
                type="button"
                class="inventory-modal-close"
                data-close-stock-loss
                aria-label="Close"
            >
                <span class="material-symbols-rounded" aria-hidden="true">close</span>

                <span class="inventory-modal-close-label">Close</span>
            </button>

        </div>


        <form
            method="POST"
            action="/inventory/"
            class="inventory-form restock-form"
            id="stockLossForm"
            novalidate
        >

            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="record_stock_loss">
            <input type="hidden" name="product_id" id="stockLossProductId">


            <div class="inventory-alert error" id="stockLossUnavailable" hidden>
                <span class="material-symbols-rounded">error</span>
                <span>This product has no stock to remove.</span>
            </div>


            <!-- 1. WHICH VARIANT -->

            <section class="restock-section">

                <div class="restock-section-head">

                    <h4>
                        <span class="restock-step-number">1</span>
                        Which color and size?
                    </h4>

                    <div class="restock-legend" id="stockLossLegend" hidden>
                        <span class="low">Low stock</span>
                        <span class="out">Out of stock</span>
                    </div>

                </div>

                <div
                    class="restock-variant-picker"
                    id="stockLossPicker"
                    role="radiogroup"
                    aria-label="Color and size"
                ></div>

                <small class="restock-field-error" id="stockLossVariantError" role="alert" hidden></small>

                <div class="restock-selected" id="stockLossSelected" hidden>

                    <div class="restock-selected-image" id="stockLossImage"></div>

                    <div class="restock-selected-info">
                        <strong id="stockLossLabel"></strong>
                        <span>
                            SKU <b id="stockLossSku"></b>
                            · Barcode <b id="stockLossBarcode"></b>
                        </span>
                    </div>

                    <div class="restock-selected-stock">
                        <span>In Stock</span>
                        <strong id="stockLossCurrentStock">0</strong>
                        <em class="restock-stock-badge" id="stockLossBadge" hidden></em>
                    </div>

                </div>

            </section>


            <!-- 2. WHAT HAPPENED -->

            <section class="restock-section">

                <div class="restock-section-head">
                    <h4>
                        <span class="restock-step-number">2</span>
                        What happened?
                    </h4>
                </div>

                <div class="stock-loss-reasons" role="radiogroup" aria-label="Reason">

                    <label>
                        <input type="radio" name="loss_reason" value="Damaged" checked>
                        <span>
                            <strong>Damaged</strong>
                            <small>Torn, stained or broken in the store.</small>
                        </span>
                    </label>

                    <label>
                        <input type="radio" name="loss_reason" value="Defective">
                        <span>
                            <strong>Defective</strong>
                            <small>Arrived faulty from the supplier.</small>
                        </span>
                    </label>

                    <label>
                        <input type="radio" name="loss_reason" value="Missing">
                        <span>
                            <strong>Missing</strong>
                            <small>Lost or stolen; not found in the count.</small>
                        </span>
                    </label>

                </div>


                <div class="inventory-form-grid">

                    <div class="inventory-field">

                        <label for="stockLossQuantity">Quantity Lost</label>

                        <div class="restock-quantity">

                            <button type="button" class="restock-quantity-button" data-stock-loss-step="-1" aria-label="Decrease quantity">
                                <span class="material-symbols-rounded">remove</span>
                            </button>

                            <input
                                type="number"
                                name="loss_quantity"
                                id="stockLossQuantity"
                                min="1"
                                step="1"
                                inputmode="numeric"
                                placeholder="Qty"
                                required
                                aria-describedby="stockLossQuantityError"
                            >

                            <button type="button" class="restock-quantity-button" data-stock-loss-step="1" aria-label="Increase quantity">
                                <span class="material-symbols-rounded">add</span>
                            </button>

                        </div>

                        <small class="restock-field-error" id="stockLossQuantityError" role="alert" hidden></small>

                    </div>


                    <div class="inventory-field">

                        <label for="stockLossUnitCost">Cost per Unit</label>

                        <div class="inventory-money-input">
                            <span>₱</span>
                            <input
                                type="number"
                                name="loss_unit_cost"
                                id="stockLossUnitCost"
                                min="0"
                                step="0.01"
                                inputmode="decimal"
                                required
                                aria-describedby="stockLossUnitCostError"
                            >
                        </div>

                        <small class="restock-field-error" id="stockLossUnitCostError" role="alert" hidden></small>

                        <small>Product cost price. Used to value the loss.</small>

                    </div>


                    <div class="inventory-field full">

                        <label for="stockLossSupplier">
                            Supplier <span class="restock-optional">Optional</span>
                        </label>

                        <select name="loss_supplier_id" id="stockLossSupplier"></select>

                        <small id="stockLossSupplierHelp">Choose the supplier if the item came from a bad delivery, so losses can be traced to them.</small>

                    </div>


                    <div class="inventory-field full">

                        <label for="stockLossNotes">
                            Notes <span class="restock-optional">Optional</span>
                        </label>

                        <textarea
                            name="loss_notes"
                            id="stockLossNotes"
                            rows="2"
                            maxlength="500"
                            placeholder="e.g. Seam torn on the left sleeve"
                        ></textarea>

                    </div>

                </div>

            </section>


            <!-- SUMMARY -->

            <div class="restock-summary">

                <div class="restock-summary-row">

                    <div>
                        <span>Stock After Removal</span>
                        <strong>
                            <span id="stockLossBefore">—</span>
                            <span class="material-symbols-rounded" aria-hidden="true">arrow_forward</span>
                            <span id="stockLossAfter">—</span>
                            <em class="loss" id="stockLossRemoved" hidden>−0</em>
                        </strong>
                    </div>

                    <div class="restock-summary-total">
                        <span>Loss Value</span>
                        <strong id="stockLossValue">₱0.00</strong>
                        <small id="stockLossBreakdown">0 × ₱0.00</small>
                    </div>

                </div>

                <p class="restock-summary-warning" id="stockLossNote">
                    The loss value is added to Expenses &amp; Losses and counted in the Financial Summary.
                </p>

            </div>


            <div class="inventory-modal-footer">

                <button type="button" class="inventory-secondary-button" data-close-stock-loss>
                    Cancel
                </button>

                <button type="submit" class="inventory-primary-button stock-loss-submit" id="stockLossSubmit">
                    <span class="material-symbols-rounded">heart_broken</span>
                    <span id="stockLossSubmitText">Remove Stock</span>
                </button>

            </div>

        </form>

    </div>

</div>



<script>

/* =========================================================
   PRODUCT DATA
========================================================= */

const inventoryProducts =
    <?= json_encode(
        $productEditData,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    ) ?>;


/* =========================================================
   MODALS
========================================================= */

const addModal =
    document.getElementById(
        'addProductModal'
    );


const editModal =
    document.getElementById(
        'editProductModal'
    );


const restockModal =
    document.getElementById(
        'restockModal'
    );


const stockLossModal =
    document.getElementById(
        'stockLossModal'
    );


function variantEscape(value) {
    const element = document.createElement('div');
    element.textContent = value ?? '';
    return element.innerHTML;
}


const inventoryStandardSizes = [
    'XS',
    'S',
    'M',
    'L',
    'XL',
    '2XL',
    '3XL',
    'One Size'
];


const addProductCode =
    <?= json_encode($nextProductCodePreview) ?>;

let currentEditProductCode =
    '';


let pendingInventoryDestructiveAction =
    null;


let pendingInventoryTemporaryTrigger =
    null;


function variantColorToken(color) {

    const token =
        String(color || '')
            .trim()
            .toUpperCase()
            .replace(/[^A-Z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');

    return token || 'DEFAULT';
}


function variantSizeToken(size) {

    const normalized =
        String(size || '')
            .trim()
            .toUpperCase();

    const map = {
        'EXTRA SMALL': 'XS',
        'XS': 'XS',
        'SMALL': 'S',
        'S': 'S',
        'MEDIUM': 'M',
        'M': 'M',
        'LARGE': 'L',
        'L': 'L',
        'EXTRA LARGE': 'XL',
        'XL': 'XL',
        '2XL': '2XL',
        'XXL': '2XL',
        '3XL': '3XL',
        'XXXL': '3XL',
        'ONE SIZE': 'OS',
        'ONESIZE': 'OS',
        'OS': 'OS'
    };

    if (map[normalized]) {
        return map[normalized];
    }

    const token =
        normalized
            .replace(/[^A-Z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');

    return token || 'STD';
}


function generatedVariantSku(
    productCode,
    color,
    size
) {

    return [
        String(productCode || '').trim().toUpperCase(),
        variantColorToken(color),
        variantSizeToken(size)
    ]
        .filter(Boolean)
        .join('-');
}


function updateVariantIdentifiers(
    row,
    mode
) {

    const productCode =
        mode === 'add'
            ? addProductCode
            : currentEditProductCode;


    const color =
        row
            .querySelector(
                '[data-variant-field="color"]'
            )
            ?.value
            .trim()
        || '';


    const size =
        row
            .querySelector(
                '[data-variant-field="size"]'
            )
            ?.value
            .trim()
        || '';


    const skuField =
        row.querySelector(
            '[data-variant-field="sku"]'
        );


    if (skuField) {
        skuField.value =
            generatedVariantSku(
                productCode,
                color,
                size
            );
    }
}


function inventorySizeOptions(currentSize = '') {

    const size =
        String(currentSize || '').trim();


    const options =
        [...inventoryStandardSizes];


    if (
        size !== '' &&
        !options.includes(size)
    ) {

        options.push(size);
    }


    return options
        .map(
            item => `
                <option
                    value="${variantEscape(item)}"
                    ${item === size ? 'selected' : ''}
                >
                    ${variantEscape(item)}
                </option>
            `
        )
        .join('');
}


let inventoryColorGroupCounter = 0;


function inventoryColorGroupKey() {
    inventoryColorGroupCounter += 1;

    return `cg_${Date.now().toString(36)}_${inventoryColorGroupCounter}`;
}


function colorGroupContainer(mode) {
    return document.getElementById(
        mode === 'add'
            ? 'addColorGroups'
            : 'editColorGroups'
    );
}


function colorGroupProductCode(mode) {
    return mode === 'add'
        ? addProductCode
        : currentEditProductCode;
}


function colorGroupTitle(group) {
    const color =
        group.querySelector('[data-color-field="color"]')?.value.trim()
        || 'New color';

    const sizeCount =
        group.querySelectorAll('.color-size-row').length;

    const title = group.querySelector('[data-color-title]');
    const meta = group.querySelector('[data-color-meta]');
    const swatch = group.querySelector('[data-color-swatch]');
    const hex =
        group.querySelector('[data-color-field="color_hex"]')?.value
        || '#777777';

    if (title) {
        title.textContent = color;
    }

    if (meta) {
        meta.textContent = `${sizeCount} ${sizeCount === 1 ? 'size' : 'sizes'} · one shared photo`;
    }

    if (swatch) {
        swatch.style.background = hex;
    }
}


function updateColorSizeIdentifiers(group, row, mode) {
    const color =
        group.querySelector('[data-color-field="color"]')?.value.trim()
        || '';

    const size =
        row.querySelector('[data-size-field="size"]')?.value.trim()
        || '';

    const sku = row.querySelector('[data-size-field="sku"]');

    if (sku) {
        sku.value = generatedVariantSku(
            colorGroupProductCode(mode),
            color,
            size
        );
    }
}


function updateAllColorSizeIdentifiers(group, mode) {
    group.querySelectorAll('.color-size-row').forEach(row => {
        updateColorSizeIdentifiers(group, row, mode);
    });

    colorGroupTitle(group);
}


function colorImagePlaceholder(preview, colorName = 'Color photo') {
    preview.innerHTML = `
        <span class="material-symbols-rounded">apparel</span>
        <small>${variantEscape(colorName || 'Color photo')}</small>
    `;
}


function previewColorImage(input, preview, group) {
    if (!input.files || !input.files[0]) {
        return;
    }

    const reader = new FileReader();

    reader.onload = event => {
        preview.innerHTML = '';

        const image = document.createElement('img');
        image.src = event.target.result;
        image.alt = 'Color product photo';
        preview.appendChild(image);

        group.dataset.removeImage = '0';
    };

    reader.readAsDataURL(input.files[0]);
}


function addColorSizeRow(
    mode,
    group,
    variant = {}
) {
    const list = group.querySelector('[data-size-list]');
    const row = document.createElement('div');
    const existingVariant = Number(variant.id || 0) > 0;
    const stockValue = Number(variant.stock_quantity || 0);
    const currentSize = String(variant.size || 'M');

    row.className = 'color-size-row';
    row.dataset.variantId = Number(variant.id || 0);
    row.dataset.imagePath = String(variant.image_path || group.dataset.existingImage || '');

    row.innerHTML = `
        <div class="color-size-field">
            <label>Size</label>
            <select data-size-field="size" required>
                ${inventorySizeOptions(currentSize)}
            </select>
        </div>

        <div class="color-size-field">
            <label>Auto SKU</label>
            <input type="text" data-size-field="sku" readonly tabindex="-1">
        </div>

        <div class="color-size-field">
            <label>Auto Barcode</label>
            <input
                type="text"
                data-size-field="barcode"
                value="${variantEscape(variant.barcode || 'Assigned when saved')}"
                readonly
                tabindex="-1"
            >
        </div>

        <div class="color-size-field">
            <label>${mode === 'edit' ? 'Current Stock' : 'Opening Stock'}</label>
            <input
                type="number"
                min="0"
                step="1"
                data-size-field="stock_quantity"
                value="${stockValue}"
                ${mode === 'edit' ? 'readonly' : ''}
                required
            >
        </div>

        <div class="color-size-field">
            <label>Status</label>
            <select data-size-field="status">
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
            </select>
        </div>

        <button
            type="button"
            class="color-size-remove"
            title="Remove size"
            aria-label="Remove size"
        >
            <span class="material-symbols-rounded">close</span>
        </button>
    `;

    row.querySelector('[data-size-field="status"]').value =
        variant.status || 'Active';

    row.querySelector('[data-size-field="size"]').addEventListener('change', () => {
        updateColorSizeIdentifiers(group, row, mode);
    });

    row.querySelector('.color-size-remove').addEventListener('click', event => {
        if (list.children.length === 1) {
            window.UA.toast('Each color must keep at least one size.', 'warning');
            return;
        }

        if (mode === 'edit' && existingVariant && stockValue > 0) {
            window.UA.toast('This size still has stock. Set it to Inactive instead of removing it.', 'warning');
            return;
        }


        const button =
            event.currentTarget;


        const sizeLabel =
            row.querySelector(
                '[data-size-field="size"]'
            )?.value
            || 'this size';


        const colorLabel =
            group.querySelector(
                '[data-color-field="color"]'
            )?.value
            || 'this color';


        button.setAttribute(
            'data-confirm',
            ''
        );


        button.dataset.confirmTitle =
            'Remove size variant?';


        button.dataset.confirmMessage =
            `${colorLabel} / ${sizeLabel} will be removed from this product when you save your changes.`;


        button.dataset.confirmLabel =
            'Remove Size';


        button.dataset.confirmIcon =
            'delete';


        pendingInventoryDestructiveAction =
            () => {

                row.remove();

                colorGroupTitle(
                    group
                );

            };


        /*
         * The global footer listener sees data-confirm during the
         * same bubbling click and opens the shared confirmation modal.
         */
    });

    list.appendChild(row);
    updateColorSizeIdentifiers(group, row, mode);
    colorGroupTitle(group);
}


function addColorGroup(
    mode,
    colorData = {}
) {
    const container = colorGroupContainer(mode);
    const group = document.createElement('div');
    const groupKey = String(colorData.groupKey || inventoryColorGroupKey());
    const color = String(colorData.color || '');
    const colorHex = String(colorData.color_hex || '#292929');
    const imagePath = String(colorData.image_path || '');
    const variants = Array.isArray(colorData.variants)
        ? colorData.variants
        : [];

    group.className = 'color-group';
    group.dataset.groupKey = groupKey;
    group.dataset.existingImage = imagePath;
    group.dataset.removeImage = '0';

    group.innerHTML = `
        <div class="color-group-header">
            <div class="color-group-title">
                <i class="color-group-swatch" data-color-swatch style="background:${variantEscape(colorHex)}"></i>
                <div>
                    <strong data-color-title>${variantEscape(color || 'New color')}</strong>
                    <small data-color-meta>0 sizes · one shared photo</small>
                </div>
            </div>

            <button
                type="button"
                class="color-group-remove"
                title="Remove color"
                aria-label="Remove color"
            >
                <span class="material-symbols-rounded">delete</span>
            </button>
        </div>

        <div class="color-group-body">
            <div class="color-photo-panel">
                <div class="color-photo-preview" data-color-preview></div>

                <div class="color-photo-actions">
                    <label class="color-photo-upload">
                        <span class="material-symbols-rounded">add_photo_alternate</span>
                        Choose Color Photo
                        <input
                            type="file"
                            name="color_images[${variantEscape(groupKey)}]"
                            accept="image/png,image/jpeg,image/webp"
                            data-color-image-input
                        >
                    </label>

                    <button
                        type="button"
                        class="color-photo-remove"
                        data-remove-color-photo
                    >
                        Remove color photo
                    </button>
                </div>
            </div>

            <div class="color-group-content">
                <div class="color-details-grid">
                    <div class="color-field">
                        <label>Color Name</label>
                        <input
                            type="text"
                            data-color-field="color"
                            value="${variantEscape(color)}"
                            placeholder="Example: Charcoal Black"
                            required
                        >
                    </div>

                    <div class="color-field">
                        <label>Swatch</label>
                        <input
                            type="color"
                            data-color-field="color_hex"
                            value="${variantEscape(colorHex || '#292929')}"
                        >
                    </div>
                </div>

                <div class="color-sizes-header">
                    <div>
                        <strong>Sizes</strong>
                        <small>Each size is a separate sellable stock variant.</small>
                    </div>

                    <button type="button" class="color-add-size" data-add-size>
                        <span class="material-symbols-rounded">add</span>
                        Add Size
                    </button>
                </div>

                <div class="color-size-list" data-size-list></div>

                <div class="color-group-note">
                    <span class="material-symbols-rounded">info</span>
                    <span>
                        One photo is shared by every size in this color. On POS, choosing this color automatically switches the product image.
                    </span>
                </div>
            </div>
        </div>
    `;

    const preview = group.querySelector('[data-color-preview]');

    if (imagePath) {
        preview.innerHTML = `
            <img src="${variantEscape(imagePath)}" alt="${variantEscape(color || 'Color')} photo">
        `;
    } else {
        colorImagePlaceholder(preview, color || 'Color photo');
    }

    group.querySelector('[data-color-image-input]').addEventListener('change', event => {
        previewColorImage(event.target, preview, group);
    });

    group.querySelector('[data-remove-color-photo]').addEventListener('click', event => {

        const button =
            event.currentTarget;


        const colorLabel =
            group.querySelector(
                '[data-color-field="color"]'
            )?.value
            .trim()
            || 'this color';


        const hasImage =
            Boolean(
                preview.querySelector('img')
            )
            ||
            Boolean(
                group.dataset.existingImage
            );


        if (!hasImage) {
            return;
        }


        button.setAttribute(
            'data-confirm',
            ''
        );


        button.dataset.confirmTitle =
            'Remove color photo?';


        button.dataset.confirmMessage =
            `The shared photo for ${colorLabel} will be removed when you save the product.`;


        button.dataset.confirmLabel =
            'Remove Photo';


        button.dataset.confirmIcon =
            'hide_image';


        pendingInventoryDestructiveAction =
            () => {

                group.dataset.removeImage =
                    '1';


                group.dataset.existingImage =
                    '';


                group
                    .querySelector(
                        '[data-color-image-input]'
                    )
                    .value =
                    '';


                colorImagePlaceholder(
                    preview,
                    group
                        .querySelector(
                            '[data-color-field="color"]'
                        )
                        .value
                        .trim()
                    || 'Color photo'
                );

            };

    });

    group.querySelector('[data-color-field="color"]').addEventListener('input', () => {
        group.dataset.removeImage = '0';
        updateAllColorSizeIdentifiers(group, mode);

        if (!preview.querySelector('img')) {
            colorImagePlaceholder(
                preview,
                group.querySelector('[data-color-field="color"]').value.trim() || 'Color photo'
            );
        }
    });

    group.querySelector('[data-color-field="color_hex"]').addEventListener('input', () => {
        colorGroupTitle(group);
    });

    group.querySelector('[data-add-size]').addEventListener('click', () => {
        addColorSizeRow(mode, group, {
            size: 'M',
            status: 'Active',
            stock_quantity: 0
        });
    });

    group.querySelector('.color-group-remove').addEventListener('click', event => {
        if (container.children.length === 1) {
            window.UA.toast('A product must keep at least one color.', 'warning');
            return;
        }

        if (mode === 'edit') {
            const hasStock = Array.from(group.querySelectorAll('.color-size-row')).some(row => {
                return Number(row.querySelector('[data-size-field="stock_quantity"]').value || 0) > 0;
            });

            if (hasStock) {
                window.UA.toast('This color still has stock. Set its sizes to Inactive instead of removing the color.', 'warning');
                return;
            }
        }


        const button =
            event.currentTarget;


        const colorLabel =
            group.querySelector(
                '[data-color-field="color"]'
            )?.value
            .trim()
            || 'this color';


        button.setAttribute(
            'data-confirm',
            ''
        );


        button.dataset.confirmTitle =
            'Remove color?';


        button.dataset.confirmMessage =
            `${colorLabel} and all of its zero-stock size variants will be removed from this product when you save.`;


        button.dataset.confirmLabel =
            'Remove Color';


        button.dataset.confirmIcon =
            'delete';


        pendingInventoryDestructiveAction =
            () => {

                group.remove();

            };

    });

    container.appendChild(group);

    if (variants.length > 0) {
        variants.forEach(variant => addColorSizeRow(mode, group, variant));
    } else {
        addColorSizeRow(mode, group, {
            size: 'M',
            status: 'Active',
            stock_quantity: 0
        });
    }

    colorGroupTitle(group);
}


function groupVariantsForEditor(variants = []) {
    const groups = [];
    const byColor = new Map();

    variants.forEach(variant => {
        const color = String(variant.color || 'Default');
        const key = color.toLowerCase();

        if (!byColor.has(key)) {
            const group = {
                groupKey: inventoryColorGroupKey(),
                color,
                color_hex: String(variant.color_hex || '#292929'),
                image_path: String(variant.image_path || ''),
                variants: []
            };

            byColor.set(key, group);
            groups.push(group);
        }

        const group = byColor.get(key);

        if (!group.image_path && variant.image_path) {
            group.image_path = String(variant.image_path);
        }

        group.variants.push(variant);
    });

    return groups;
}


function serializeVariants(mode) {
    const container = colorGroupContainer(mode);
    const variants = [];

    container.querySelectorAll('.color-group').forEach(group => {
        const color = group.querySelector('[data-color-field="color"]').value.trim();
        const colorHex = group.querySelector('[data-color-field="color_hex"]').value;
        const groupKey = group.dataset.groupKey;
        const imagePath = group.dataset.existingImage || '';
        const removeImage = group.dataset.removeImage === '1';

        group.querySelectorAll('.color-size-row').forEach(row => {
            variants.push({
                id: Number(row.dataset.variantId || 0),
                color,
                color_hex: colorHex,
                size: row.querySelector('[data-size-field="size"]').value.trim(),
                sku: row.querySelector('[data-size-field="sku"]').value.trim(),
                barcode: row.querySelector('[data-size-field="barcode"]').value.trim(),
                stock_quantity: Number(row.querySelector('[data-size-field="stock_quantity"]').value),
                status: row.querySelector('[data-size-field="status"]').value,
                image_group_key: groupKey,
                image_path: imagePath || row.dataset.imagePath || '',
                remove_color_image: removeImage
            });
        });
    });

    document.getElementById(
        mode === 'add'
            ? 'addVariantsJson'
            : 'editVariantsJson'
    ).value = JSON.stringify(variants);
}


document.querySelectorAll('[data-add-color]').forEach(button => {
    button.addEventListener('click', () => addColorGroup(button.dataset.addColor));
});


addModal.querySelector('form').addEventListener('submit', () => serializeVariants('add'));
editModal.querySelector('form').addEventListener('submit', () => serializeVariants('edit'));


function updateBodyLock() {

    document.body.classList.toggle(
        'inventory-modal-open',
        (
            !addModal.hidden ||
            !editModal.hidden ||
            !restockModal.hidden ||
            !stockLossModal.hidden
        )
    );
}


/* =========================================================
   ADD PRODUCT
========================================================= */

document
    .getElementById(
        'openAddProduct'
    )
    .addEventListener(
        'click',
        () => {

            const colorGroups = document.getElementById('addColorGroups');

            if (colorGroups.children.length === 0) {
                addColorGroup('add', {
                    color: '',
                    color_hex: '#292929',
                    image_path: '',
                    variants: [{
                        size: 'M',
                        status: 'Active',
                        stock_quantity: 0
                    }]
                });
            }

            addModal.hidden =
                false;

            updateBodyLock();

        }
    );


function closeAddModal() {

    addModal.hidden =
        true;

    updateBodyLock();
}


document
    .querySelectorAll(
        '[data-close-add]'
    )
    .forEach(
        button => {

            button.addEventListener(
                'click',
                closeAddModal
            );

        }
    );


addModal
    .querySelector(
        '.inventory-modal-backdrop'
    )
    .addEventListener(
        'click',
        closeAddModal
    );


/* =========================================================
   PHOTO PREVIEW
========================================================= */

function previewImage(
    input,
    preview
) {

    if (
        !input.files ||
        !input.files[0]
    ) {

        return;
    }


    const reader =
        new FileReader();


    reader.onload =
        event => {

            preview.innerHTML =
                '';


            const image =
                document.createElement(
                    'img'
                );


            image.src =
                event.target.result;


            image.alt =
                'Product preview';


            preview.appendChild(
                image
            );

        };


    reader.readAsDataURL(
        input.files[0]
    );
}


document
    .getElementById(
        'addProductImage'
    )
    .addEventListener(
        'change',
        event => {

            previewImage(
                event.target,
                document.getElementById(
                    'addImagePreview'
                )
            );

        }
    );


/* =========================================================
   EDIT PRODUCT
========================================================= */

function openEditModal(
    productId
) {

    const product =
        inventoryProducts[
            productId
        ];


    if (!product) {
        return;
    }


    document
        .getElementById(
            'editProductId'
        )
        .value =
        product.id;


    document
        .getElementById(
            'editProductCode'
        )
        .value =
        product.product_code;


    currentEditProductCode =
        product.product_code;


    document
        .getElementById(
            'editProductName'
        )
        .value =
        product.product_name;


    document
        .getElementById(
            'editCategoryId'
        )
        .value =
        product.category_id;


    document
        .getElementById(
            'editStatus'
        )
        .value =
        product.status;


    document
        .getElementById(
            'editCostPrice'
        )
        .value =
        product.cost_price;


    document
        .getElementById(
            'editSellingPrice'
        )
        .value =
        product.selling_price;


    const reorderMetrics =
        product.reorder_metrics || {};


    document
        .getElementById(
            'editAutoReorderLevel'
        )
        .textContent =
        `${Number(product.reorder_level || 0)} units`;


    const reorderMeta =
        document.getElementById(
            'editAutoReorderMeta'
        );


    const lowVariantCount =
        Number(
            reorderMetrics.low_stock_variants
            || 0
        );

    const activeVariantCount =
        Number(
            reorderMetrics.active_variant_count
            || 0
        );


    reorderMeta.textContent =
        `${lowVariantCount} of ${activeVariantCount} active `
        + `${activeVariantCount === 1 ? 'variant is' : 'variants are'} `
        + `at or below its own automatic reorder point. `
        + `Restock recommendations are calculated separately for each color / size variant.`;


    document
        .getElementById(
            'editDateAdded'
        )
        .textContent =
        product.date_added || '—';


    document
        .getElementById(
            'editLastRestocked'
        )
        .textContent =
        product.last_restocked || '—';


    document
        .getElementById(
            'editCurrentStock'
        )
        .textContent =
        product.stock_quantity;


    document
        .getElementById(
            'editProductImage'
        )
        .value =
        '';


    document
        .getElementById(
            'removeProductPhoto'
        )
        .checked =
        false;


    const preview =
        document.getElementById(
            'editImagePreview'
        );


    if (
        product.photo_url
    ) {

        preview.innerHTML =
            `<img
                src="${product.photo_url}"
                alt="Product photo"
            >`;

    } else {

        preview.innerHTML = `

            <span class="material-symbols-rounded">
                image
            </span>

            <small>
                No Photo
            </small>
        `;
    }


    const editColorGroups = document.getElementById('editColorGroups');
    editColorGroups.innerHTML = '';

    const groupedColors =
        groupVariantsForEditor(
            product.variants || []
        );

    groupedColors.forEach(colorGroup => {
        addColorGroup('edit', colorGroup);
    });

    if (editColorGroups.children.length === 0) {
        addColorGroup('edit', {
            color: '',
            color_hex: '#292929',
            image_path: '',
            variants: [{
                size: 'M',
                status: 'Active',
                stock_quantity: 0
            }]
        });
    }


    editModal.hidden =
        false;


    updateBodyLock();

}


function closeEditModal() {

    editModal.hidden =
        true;

    updateBodyLock();
}


document
    .querySelectorAll(
        '.edit-product-button'
    )
    .forEach(
        button => {

            button.addEventListener(
                'click',
                () => {

                    openEditModal(
                        Number(
                            button.dataset.id
                        )
                    );

                }
            );

        }
    );


document
    .querySelectorAll(
        '[data-close-edit]'
    )
    .forEach(
        button => {

            button.addEventListener(
                'click',
                closeEditModal
            );

        }
    );


editModal
    .querySelector(
        '.inventory-modal-backdrop'
    )
    .addEventListener(
        'click',
        closeEditModal
    );


document
    .getElementById(
        'editProductImage'
    )
    .addEventListener(
        'change',
        event => {

            previewImage(
                event.target,
                document.getElementById(
                    'editImagePreview'
                )
            );

        }
    );


/* =========================================================
   REMOVE COVER PHOTO CONFIRMATION
========================================================= */

const removeProductPhotoCheckbox =
    document.getElementById(
        'removeProductPhoto'
    );


removeProductPhotoCheckbox.addEventListener(
    'change',
    event => {


        if (
            !event.target.checked
        ) {
            return;
        }


        const productId =
            Number(
                document
                    .getElementById(
                        'editProductId'
                    )
                    .value
                || 0
            );


        const product =
            inventoryProducts[
                productId
            ];


        if (
            !product
            ||
            !product.photo_url
        ) {

            return;
        }


        event.target.checked =
            false;


        const trigger =
            document.createElement(
                'button'
            );


        trigger.type =
            'button';


        trigger.hidden =
            true;


        trigger.setAttribute(
            'data-confirm',
            ''
        );


        trigger.dataset.confirmTitle =
            'Remove cover photo?';


        trigger.dataset.confirmMessage =
            `${product.product_name}'s default cover photo will be removed when you save the product. Color-specific photos will not be affected.`;


        trigger.dataset.confirmLabel =
            'Remove Photo';


        trigger.dataset.confirmIcon =
            'hide_image';


        document.body.appendChild(
            trigger
        );


        pendingInventoryTemporaryTrigger =
            trigger;


        pendingInventoryDestructiveAction =
            () => {

                removeProductPhotoCheckbox.checked =
                    true;


                trigger.remove();


                pendingInventoryTemporaryTrigger =
                    null;

            };


        trigger.click();

    }
);


/* =========================================================
   RESTOCK
========================================================= */

function formatRestockMoney(value) {
    return new Intl.NumberFormat(
        'en-PH',
        {
            style: 'currency',
            currency: 'PHP'
        }
    ).format(Number(value || 0));
}


function restockUnits(value) {
    return `${value} ${Number(value) === 1 ? 'unit' : 'units'}`;
}


/*
| Sizes are stored as free text, so they are ranked through the same
| token map used for generated SKUs (Small -> S, XXL -> 2XL, ...).
| Unknown sizes keep their original order after the standard sizes.
*/
const restockSizeOrder =
    ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', 'OS'];

function restockSizeRank(size) {

    const index =
        restockSizeOrder.indexOf(
            variantSizeToken(size)
        );

    return index === -1
        ? restockSizeOrder.length
        : index;
}


const restockFields = {
    form: document.getElementById('restockForm'),
    productId: document.getElementById('restockProductId'),
    description: document.getElementById('restockProductDescription'),

    unavailable: document.getElementById('restockUnavailableMessage'),
    unavailableText: document.getElementById('restockUnavailableText'),

    picker: document.getElementById('restockVariantPicker'),
    legend: document.getElementById('restockLegend'),
    variantError: document.getElementById('restockVariantError'),
    selectedCard: document.getElementById('restockSelectedVariant'),
    selectedImage: document.getElementById('restockSelectedImage'),
    selectedLabel: document.getElementById('restockSelectedLabel'),
    selectedSku: document.getElementById('restockSelectedSku'),
    selectedBarcode: document.getElementById('restockSelectedBarcode'),
    currentStock: document.getElementById('restockCurrentStock'),
    stockBadge: document.getElementById('restockStockBadge'),

    supplier: document.getElementById('restockSupplierId'),
    quantity: document.getElementById('restockQuantity'),
    quantityError: document.getElementById('restockQuantityError'),
    stepButtons: restockModal.querySelectorAll('[data-restock-step]'),
    suggestion: document.getElementById('restockSuggestion'),
    suggestionText: document.getElementById('restockSuggestionText'),
    useSuggested: document.getElementById('restockUseSuggested'),
    unitCost: document.getElementById('restockUnitCost'),
    unitCostError: document.getElementById('restockUnitCostError'),
    notes: document.getElementById('restockNotes'),

    previewBefore: document.getElementById('restockPreviewBefore'),
    previewAfter: document.getElementById('restockPreviewAfter'),
    previewAdded: document.getElementById('restockPreviewAdded'),
    previewNote: document.getElementById('restockPreviewNote'),
    totalBreakdown: document.getElementById('restockTotalBreakdown'),
    lineTotal: document.getElementById('restockLineTotal'),

    submit: document.getElementById('restockSubmitButton'),
    submitText: document.getElementById('restockSubmitText')
};


const restockState = {
    product: null,
    variants: [],
    selectedVariant: null,
    trigger: null,
    submitting: false
};


function restockVariantMetrics(variant) {

    const metrics =
        variant?.reorder_metrics || {};

    return {
        stock: Number(variant?.stock_quantity || 0),
        reorderPoint: Number(metrics.reorder_level || 0),
        targetStock: Number(metrics.target_stock || 0),
        suggestedRestock: Number(metrics.suggested_restock || 0),
        unitsSold: Number(metrics.units_sold || 0),
        windowDays: Number(metrics.window_days || 30),
        source: metrics.source || 'baseline',
        needsReorder: Boolean(metrics.needs_reorder)
    };
}


/*
| Returns the whole-number quantity typed by the user, or null when the
| field is empty or not a positive whole number.
*/
function restockQuantityValue() {

    const raw =
        restockFields.quantity.value.trim();

    if (!/^\d+$/.test(raw)) {
        return null;
    }

    const quantity =
        Number(raw);

    return quantity >= 1
        ? quantity
        : null;
}


function restockUnitCostValue() {

    const raw =
        restockFields.unitCost.value.trim();

    if (raw === '' || !Number.isFinite(Number(raw))) {
        return null;
    }

    return Number(raw);
}


function setRestockError(element, message) {

    element.textContent =
        message || '';

    element.hidden =
        !message;

    const field =
        element.closest('.inventory-field');

    if (field) {
        field.classList.toggle(
            'has-error',
            Boolean(message)
        );
    }
}


function clearRestockErrors() {
    setRestockError(restockFields.variantError, '');
    setRestockError(restockFields.quantityError, '');
    setRestockError(restockFields.unitCostError, '');
}


/*
| Shared color/size picker used by the Restock and Stock Loss modals:
| one row per color, with a radio button for each size showing its stock.
*/
function renderVariantPicker({
    picker,
    legend,
    variants,
    emptyMessage,
    isDisabled = () => false,
    onSelect,
    focusAfterClick
}) {

    picker.innerHTML = '';

    legend.hidden =
        true;

    if (variants.length === 0) {

        const empty =
            document.createElement('div');

        empty.className =
            'restock-picker-empty';

        empty.textContent =
            emptyMessage;

        picker.appendChild(empty);

        return;
    }


    const colorGroups =
        new Map();

    variants.forEach(variant => {

        if (!colorGroups.has(variant.color)) {
            colorGroups.set(variant.color, []);
        }

        colorGroups.get(variant.color).push(variant);
    });


    colorGroups.forEach((colorVariants, color) => {

        const row =
            document.createElement('div');

        row.className =
            'restock-color-row';


        const colorName =
            document.createElement('div');

        colorName.className =
            'restock-color-name';

        const swatch =
            document.createElement('i');

        const colorHex =
            String(colorVariants[0].color_hex || '');

        if (/^#[0-9a-f]{3,8}$/i.test(colorHex)) {
            swatch.style.background = colorHex;
        }

        const colorLabel =
            document.createElement('span');

        colorLabel.textContent =
            color;

        colorName.append(swatch, colorLabel);


        const sizes =
            document.createElement('div');

        sizes.className =
            'restock-size-options';

        colorVariants.forEach(variant => {

            const metrics =
                restockVariantMetrics(variant);

            const option =
                document.createElement('label');

            option.className =
                'restock-size-option';

            if (metrics.stock <= 0) {
                option.classList.add('is-out');
                legend.hidden = false;
            } else if (metrics.needsReorder) {
                option.classList.add('is-low');
                legend.hidden = false;
            }

            option.title =
                `${variant.color} / ${variant.size} — ${restockUnits(metrics.stock)} in stock`;


            const input =
                document.createElement('input');

            input.type = 'radio';
            input.name = 'variant_id';
            input.value = variant.id;
            input.required = true;
            input.disabled = isDisabled(variant);

            input.setAttribute(
                'aria-label',
                option.title
            );

            input.addEventListener(
                'change',
                () => onSelect(variant)
            );

            // After a mouse click on a size, jump to the quantity box.
            // Keyboard arrow selection (detail === 0) keeps focus in the picker.
            input.addEventListener(
                'click',
                event => {
                    if (
                        event.detail > 0 &&
                        focusAfterClick &&
                        !focusAfterClick.disabled &&
                        window.matchMedia('(pointer: fine)').matches
                    ) {
                        focusAfterClick.focus({ preventScroll: true });
                    }
                }
            );


            const sizeLabel =
                document.createElement('span');

            sizeLabel.className =
                'restock-size-label';

            sizeLabel.textContent =
                variant.size;


            const stockLabel =
                document.createElement('span');

            stockLabel.className =
                'restock-size-stock';

            stockLabel.textContent =
                `${metrics.stock} in stock`;


            option.append(input, sizeLabel, stockLabel);

            sizes.appendChild(option);
        });


        row.append(colorName, sizes);

        picker.appendChild(row);
    });
}


/*
| Groups variants by color (keeping the colors' original order) and sorts
| sizes from smallest to largest inside each color.
*/
function sortVariantsForPicker(variants) {

    const colorOrder =
        [];

    variants.forEach(variant => {
        if (!colorOrder.includes(variant.color)) {
            colorOrder.push(variant.color);
        }
    });

    return [...variants].sort(
        (a, b) =>
            colorOrder.indexOf(a.color) - colorOrder.indexOf(b.color)
            || restockSizeRank(a.size) - restockSizeRank(b.size)
    );
}


function markSelectedVariant(picker, variantId) {

    picker
        .querySelectorAll('.restock-size-option')
        .forEach(option => {

            const input =
                option.querySelector('input');

            const selected =
                Number(input.value) === Number(variantId);

            input.checked = selected;

            option.classList.toggle(
                'is-selected',
                selected
            );
        });
}


/*
| Fills a "selected variant" card: color photo, color / size, SKU,
| barcode, current stock and a low / out-of-stock badge.
*/
function fillVariantCard(card, product, variant) {

    const metrics =
        restockVariantMetrics(variant);

    const imageUrl =
        String(variant.image_path || product.photo_url || '');

    card.image.innerHTML = '';

    if (imageUrl !== '') {

        const image =
            document.createElement('img');

        image.src = imageUrl;
        image.alt = `${variant.color} ${product.product_name || ''}`.trim();

        card.image.appendChild(image);

    } else {

        card.image.innerHTML =
            '<span class="material-symbols-rounded">checkroom</span>';
    }

    card.label.textContent =
        `${variant.color} / ${variant.size}`;

    card.sku.textContent =
        variant.sku || '—';

    card.barcode.textContent =
        variant.barcode || '—';

    card.stock.textContent =
        metrics.stock;

    card.badge.classList.remove('out', 'low');

    if (metrics.stock <= 0) {
        card.badge.textContent = 'Out of stock';
        card.badge.classList.add('out');
        card.badge.hidden = false;
    } else if (metrics.needsReorder) {
        card.badge.textContent = 'Low stock';
        card.badge.classList.add('low');
        card.badge.hidden = false;
    } else {
        card.badge.hidden = true;
    }
}


function renderRestockVariantPicker() {

    renderVariantPicker({
        picker: restockFields.picker,
        legend: restockFields.legend,
        variants: restockState.variants,
        emptyMessage: 'This product has no active color/size variants.',
        onSelect: selectRestockVariant,
        focusAfterClick: restockFields.quantity
    });
}


function selectRestockVariant(variant) {

    restockState.selectedVariant =
        variant;

    markSelectedVariant(
        restockFields.picker,
        variant.id
    );

    fillVariantCard(
        {
            image: restockFields.selectedImage,
            label: restockFields.selectedLabel,
            sku: restockFields.selectedSku,
            barcode: restockFields.selectedBarcode,
            stock: restockFields.currentStock,
            badge: restockFields.stockBadge
        },
        restockState.product || {},
        variant
    );

    setRestockError(restockFields.variantError, '');

    updateRestockPreview();
}


function updateRestockPreview() {

    const variant =
        restockState.selectedVariant;

    const metrics =
        restockVariantMetrics(variant);

    const quantity =
        restockQuantityValue();

    const unitCost =
        restockUnitCostValue();

    const received =
        quantity ?? 0;

    const after =
        metrics.stock + received;


    restockFields.selectedCard.hidden =
        !variant;


    /*
    | Suggested quantity: only offered for low / out-of-stock variants and
    | never filled in automatically. The quantity must match the delivery.
    */

    const showSuggestion =
        Boolean(variant) &&
        metrics.needsReorder &&
        metrics.suggestedRestock > 0;

    restockFields.suggestion.hidden =
        !showSuggestion;

    if (showSuggestion) {

        restockFields.suggestionText.textContent =
            `Suggested: ${restockUnits(metrics.suggestedRestock)} (target stock ${metrics.targetStock})`;

        restockFields.suggestion.title =
            (
                metrics.source === 'sales'
                    ? `Based on ${restockUnits(metrics.unitsSold)} sold in the last ${metrics.windowDays} days.`
                    : 'No recent sales yet, so the default stock levels are used.'
            )
            + ` Reorder point: ${metrics.reorderPoint}. Target stock: ${metrics.targetStock}.`;

        restockFields.useSuggested.textContent =
            `Use ${metrics.suggestedRestock}`;

        restockFields.useSuggested.hidden =
            quantity === metrics.suggestedRestock ||
            restockFields.quantity.disabled;
    }


    /*
    | Summary
    */

    restockFields.previewBefore.textContent =
        variant ? metrics.stock : '—';

    restockFields.previewAfter.textContent =
        variant ? after : '—';

    restockFields.previewAdded.textContent =
        `+${received}`;

    restockFields.previewAdded.hidden =
        !variant || received === 0;


    /*
    | Typo guard (e.g. 500 typed instead of 50). The limit is three times the
    | variant's target stock, but never below 30 so slow sellers with a tiny
    | target do not warn on ordinary deliveries. Warning only, not blocking.
    */

    const tooMany =
        Boolean(variant) &&
        quantity !== null &&
        quantity > Math.max(metrics.targetStock * 3, 30);

    restockFields.previewNote.hidden =
        !tooMany;

    if (tooMany) {
        restockFields.previewNote.textContent =
            `${restockUnits(quantity)} is a large quantity for one size. `
            + 'Please double-check it against the delivery receipt.';
    }


    const cost =
        unitCost !== null && unitCost >= 0
            ? unitCost
            : 0;

    restockFields.totalBreakdown.textContent =
        `${received} × ${formatRestockMoney(cost)}`;

    restockFields.lineTotal.textContent =
        formatRestockMoney(received * cost);

    if (!restockState.submitting) {
        restockFields.submitText.textContent =
            quantity !== null
                ? `Receive ${restockUnits(quantity)}`
                : 'Receive Stock';
    }
}


function validateRestockForm() {

    clearRestockErrors();

    let firstInvalid =
        null;


    if (!restockState.selectedVariant) {

        setRestockError(
            restockFields.variantError,
            'Choose the color and size that arrived.'
        );

        firstInvalid =
            restockFields.picker.querySelector('input');
    }


    if (restockQuantityValue() === null) {

        setRestockError(
            restockFields.quantityError,
            restockFields.quantity.value.trim() === ''
                ? 'Enter how many units arrived.'
                : 'Quantity must be a whole number (1 or more).'
        );

        firstInvalid =
            firstInvalid || restockFields.quantity;
    }


    const unitCost =
        restockUnitCostValue();

    if (restockFields.unitCost.value.trim() === '') {

        setRestockError(
            restockFields.unitCostError,
            'Enter the cost per unit. Use 0 if there was no cost.'
        );

        firstInvalid =
            firstInvalid || restockFields.unitCost;

    } else if (unitCost === null || unitCost < 0) {

        setRestockError(
            restockFields.unitCostError,
            'Cost per unit cannot be negative.'
        );

        firstInvalid =
            firstInvalid || restockFields.unitCost;
    }


    return firstInvalid;
}


function openRestockModal(
    id,
    name,
    trigger
) {

    const product =
        inventoryProducts[id] || {};

    const suppliers =
        product.suppliers || [];


    const variants =
        sortVariantsForPicker(
            (product.variants || [])
                .filter(
                    variant =>
                        variant.status === 'Active'
                )
        );


    restockState.product = product;
    restockState.variants = variants;
    restockState.selectedVariant = null;
    restockState.trigger = trigger || null;
    restockState.submitting = false;


    restockFields.productId.value =
        id;

    restockFields.description.textContent =
        product.product_code
            ? `${product.product_code} · ${name}`
            : name;

    restockFields.notes.value =
        '';

    restockFields.quantity.value =
        '';

    clearRestockErrors();


    /*
    | Suppliers linked to this product (primary supplier first).
    */

    restockFields.supplier.innerHTML =
        '';

    suppliers.forEach(supplier => {

        const option =
            document.createElement('option');

        option.value =
            supplier.id;

        option.dataset.price =
            Number(supplier.supplier_price || 0).toFixed(2);

        option.dataset.primary =
            Number(supplier.is_primary || 0) === 1
                ? '1'
                : '0';

        option.textContent =
            `${supplier.supplier_name} — ${formatRestockMoney(supplier.supplier_price)}`
            + (Number(supplier.is_primary || 0) === 1 ? ' · Primary' : '');

        restockFields.supplier.appendChild(option);
    });

    const primarySupplierIndex =
        Array.from(restockFields.supplier.options)
            .findIndex(
                option =>
                    option.dataset.primary === '1'
            );

    if (primarySupplierIndex >= 0) {
        restockFields.supplier.selectedIndex =
            primarySupplierIndex;
    }

    applyRestockSupplierPrice();


    /*
    | Availability. These mirror the server-side checks so the user is told
    | up front instead of after submitting.
    */

    const productIsActive =
        product.status === 'Active';

    const problems =
        [];

    /*
    | No supplier: the "Link a supplier" box at the top explains it (and
    | also says when the product must be activated first), so the red
    | message only covers the other problems.
    */

    if (!productIsActive && suppliers.length > 0) {
        problems.push('Activate this product before restocking it.');
    }

    if (variants.length === 0) {
        problems.push('Add or activate a color/size variant before restocking.');
    }

    const restockAvailable =
        productIsActive &&
        suppliers.length > 0 &&
        variants.length > 0;

    restockFields.unavailable.hidden =
        problems.length === 0;

    restockFields.unavailableText.textContent =
        problems.join(' ');


    const linkSupplierPanel =
        document.getElementById('restockLinkSupplier');

    const linkSupplierForm =
        document.getElementById('restockLinkSupplierForm');

    linkSupplierPanel.hidden =
        suppliers.length > 0;

    // The form is missing when there are no active suppliers at all.
    if (linkSupplierForm) {

        document
            .getElementById('restockLinkProductId')
            .value = id;

        document
            .getElementById('restockLinkSupplierPrice')
            .value =
            Number(product.cost_price || 0).toFixed(2);

        linkSupplierForm
            .querySelectorAll('select, input, button')
            .forEach(field => {
                field.disabled = !productIsActive;
            });

        document
            .getElementById('restockLinkSupplierHelp')
            .textContent =
            productIsActive
                ? 'Restocking records which supplier delivered the stock. Link one now, then restock.'
                : 'This product is Inactive. Activate it first, then link a supplier and restock.';
    }

    restockFields.supplier.disabled =
        suppliers.length === 0;

    [
        restockFields.quantity,
        restockFields.unitCost,
        restockFields.notes,
        restockFields.submit,
        ...restockFields.stepButtons
    ].forEach(control => {
        control.disabled = !restockAvailable;
    });


    /*
    | Nothing is pre-selected so the user consciously picks what arrived,
    | unless the product only has one variant.
    */

    renderRestockVariantPicker();

    restockFields.picker
        .querySelectorAll('input')
        .forEach(input => {
            input.disabled = !restockAvailable;
        });

    if (variants.length === 1) {
        selectRestockVariant(variants[0]);
    } else {
        updateRestockPreview();
    }


    restockModal.hidden =
        false;

    restockModal
        .querySelector('.restock-card')
        .scrollTop = 0;

    updateBodyLock();


    if (
        restockAvailable &&
        variants.length === 1 &&
        window.matchMedia('(pointer: fine)').matches
    ) {
        restockFields.quantity.focus({ preventScroll: true });
    }
}


function applyRestockSupplierPrice() {

    const option =
        restockFields.supplier.options[
            restockFields.supplier.selectedIndex
        ];

    restockFields.unitCost.value =
        option
            ? Number(option.dataset.price || 0).toFixed(2)
            : '';

    setRestockError(restockFields.unitCostError, '');

    updateRestockPreview();
}


function closeRestockModal() {

    restockModal.hidden =
        true;

    updateBodyLock();

    if (restockState.trigger) {
        restockState.trigger.focus();
    }
}


restockFields.supplier.addEventListener(
    'change',
    applyRestockSupplierPrice
);


restockFields.quantity.addEventListener(
    'input',
    () => {
        setRestockError(restockFields.quantityError, '');
        updateRestockPreview();
    }
);


restockFields.unitCost.addEventListener(
    'input',
    () => {
        setRestockError(restockFields.unitCostError, '');
        updateRestockPreview();
    }
);


restockFields.stepButtons.forEach(button => {

    button.addEventListener(
        'click',
        () => {

            const current =
                restockQuantityValue() ?? 0;

            restockFields.quantity.value =
                Math.max(
                    1,
                    current + Number(button.dataset.restockStep)
                );

            setRestockError(restockFields.quantityError, '');

            updateRestockPreview();
        }
    );
});


restockFields.useSuggested.addEventListener(
    'click',
    () => {

        const metrics =
            restockVariantMetrics(restockState.selectedVariant);

        if (metrics.suggestedRestock > 0) {
            restockFields.quantity.value =
                metrics.suggestedRestock;
        }

        setRestockError(restockFields.quantityError, '');

        updateRestockPreview();

        restockFields.quantity.focus();
    }
);


/*
| The form uses novalidate so errors appear inline in the modal. The server
| still validates every value. Once a valid submit starts, the button is
| locked so a double click cannot create two stock receipts.
*/

restockFields.form.addEventListener(
    'submit',
    event => {

        if (restockState.submitting) {
            event.preventDefault();
            return;
        }

        const firstInvalid =
            validateRestockForm();

        if (firstInvalid) {
            event.preventDefault();
            firstInvalid.focus();
            return;
        }

        restockState.submitting = true;

        restockFields.submit.disabled = true;

        restockFields.submitText.textContent =
            'Saving stock receipt…';
    }
);


document
    .querySelectorAll(
        '.restock-button'
    )
    .forEach(
        button => {

            button.addEventListener(
                'click',
                () => {

                    openRestockModal(
                        button.dataset.id,
                        button.dataset.name,
                        button
                    );

                }
            );

        }
    );


document
    .querySelectorAll(
        '[data-close-restock]'
    )
    .forEach(
        button => {

            button.addEventListener(
                'click',
                closeRestockModal
            );

        }
    );


/*
 * After a supplier is linked from the Restock window, the page reloads
 * with ?restock=<product id>. Reopen Restock so the user can continue,
 * then clean the address so a refresh does not reopen it again.
 */
(() => {

    const params =
        new URLSearchParams(
            window.location.search
        );

    const restockProductId =
        params.get('restock');


    if (
        !restockProductId ||
        !inventoryProducts[restockProductId]
    ) {
        return;
    }


    openRestockModal(
        restockProductId,
        inventoryProducts[restockProductId].product_name,
        null
    );


    if (params.get('linked') === '1') {

        window.UA.toast(
            'Supplier linked. You can restock this product now.',
            'success'
        );
    }


    window.history.replaceState(
        null,
        '',
        window.location.pathname
    );

})();


restockModal
    .querySelector(
        '.inventory-modal-backdrop'
    )
    .addEventListener(
        'click',
        closeRestockModal
    );


/* =========================================================
   STOCK LOSS (DAMAGED / DEFECTIVE / MISSING)
========================================================= */

const stockLossFields = {
    form: document.getElementById('stockLossForm'),
    productId: document.getElementById('stockLossProductId'),
    description: document.getElementById('stockLossDescription'),
    unavailable: document.getElementById('stockLossUnavailable'),

    picker: document.getElementById('stockLossPicker'),
    legend: document.getElementById('stockLossLegend'),
    variantError: document.getElementById('stockLossVariantError'),
    selectedCard: document.getElementById('stockLossSelected'),
    card: {
        image: document.getElementById('stockLossImage'),
        label: document.getElementById('stockLossLabel'),
        sku: document.getElementById('stockLossSku'),
        barcode: document.getElementById('stockLossBarcode'),
        stock: document.getElementById('stockLossCurrentStock'),
        badge: document.getElementById('stockLossBadge')
    },

    quantity: document.getElementById('stockLossQuantity'),
    quantityError: document.getElementById('stockLossQuantityError'),
    stepButtons: stockLossModal.querySelectorAll('[data-stock-loss-step]'),
    unitCost: document.getElementById('stockLossUnitCost'),
    unitCostError: document.getElementById('stockLossUnitCostError'),
    supplier: document.getElementById('stockLossSupplier'),
    notes: document.getElementById('stockLossNotes'),

    before: document.getElementById('stockLossBefore'),
    after: document.getElementById('stockLossAfter'),
    removed: document.getElementById('stockLossRemoved'),
    value: document.getElementById('stockLossValue'),
    breakdown: document.getElementById('stockLossBreakdown'),

    submit: document.getElementById('stockLossSubmit'),
    submitText: document.getElementById('stockLossSubmitText')
};


const stockLossState = {
    product: null,
    variants: [],
    selectedVariant: null,
    trigger: null,
    submitting: false
};


function stockLossQuantityValue() {

    const raw =
        stockLossFields.quantity.value.trim();

    return /^\d+$/.test(raw) && Number(raw) >= 1
        ? Number(raw)
        : null;
}


function updateStockLossPreview() {

    const variant =
        stockLossState.selectedVariant;

    const stock =
        restockVariantMetrics(variant).stock;

    const quantity =
        stockLossQuantityValue();

    const removed =
        quantity ?? 0;

    const unitCost =
        Number(stockLossFields.unitCost.value);

    const cost =
        stockLossFields.unitCost.value.trim() !== '' && unitCost >= 0
            ? unitCost
            : 0;


    stockLossFields.selectedCard.hidden =
        !variant;

    stockLossFields.before.textContent =
        variant ? stock : '—';

    stockLossFields.after.textContent =
        variant ? Math.max(stock - removed, 0) : '—';

    stockLossFields.removed.textContent =
        `−${removed}`;

    stockLossFields.removed.hidden =
        !variant || removed === 0;

    stockLossFields.value.textContent =
        formatRestockMoney(removed * cost);

    stockLossFields.breakdown.textContent =
        `${removed} × ${formatRestockMoney(cost)}`;


    // Immediate feedback when more units are entered than are in stock.
    setRestockError(
        stockLossFields.quantityError,
        variant && quantity !== null && quantity > stock
            ? `Only ${restockUnits(stock)} of ${variant.color} / ${variant.size} in stock.`
            : ''
    );

    if (!stockLossState.submitting) {
        stockLossFields.submitText.textContent =
            quantity !== null
                ? `Remove ${restockUnits(quantity)}`
                : 'Remove Stock';
    }
}


function selectStockLossVariant(variant) {

    stockLossState.selectedVariant =
        variant;

    markSelectedVariant(
        stockLossFields.picker,
        variant.id
    );

    fillVariantCard(
        stockLossFields.card,
        stockLossState.product || {},
        variant
    );

    stockLossFields.quantity.max =
        restockVariantMetrics(variant).stock;

    setRestockError(stockLossFields.variantError, '');

    updateStockLossPreview();
}


function openStockLossModal(
    id,
    name,
    trigger
) {

    const product =
        inventoryProducts[id] || {};

    // Every non-archived variant is listed; sizes with no stock are disabled.
    const variants =
        sortVariantsForPicker(product.variants || []);

    const inStock =
        variants.filter(
            variant =>
                restockVariantMetrics(variant).stock > 0
        );


    stockLossState.product = product;
    stockLossState.variants = variants;
    stockLossState.selectedVariant = null;
    stockLossState.trigger = trigger || null;
    stockLossState.submitting = false;


    stockLossFields.productId.value =
        id;

    stockLossFields.description.textContent =
        product.product_code
            ? `${product.product_code} · ${name}`
            : name;

    stockLossFields.quantity.value = '';
    stockLossFields.notes.value = '';
    stockLossFields.unitCost.value = Number(product.cost_price || 0).toFixed(2);

    stockLossFields.form
        .querySelector('input[name="loss_reason"][value="Damaged"]')
        .checked = true;

    setRestockError(stockLossFields.variantError, '');
    setRestockError(stockLossFields.quantityError, '');
    setRestockError(stockLossFields.unitCostError, '');


    /*
    | Supplier (optional): every supplier linked to this product, including
    | inactive ones, so defective items can be traced to past deliveries.
    */

    stockLossFields.supplier.innerHTML = '';

    const noSupplier =
        document.createElement('option');

    noSupplier.value = '';
    noSupplier.textContent = 'Not supplier-related';

    stockLossFields.supplier.appendChild(noSupplier);

    (product.loss_suppliers || []).forEach(supplier => {

        const option =
            document.createElement('option');

        option.value = supplier.id;
        option.textContent =
            supplier.supplier_name
            + (supplier.status !== 'Active' ? ' (inactive)' : '');

        stockLossFields.supplier.appendChild(option);
    });


    const available =
        inStock.length > 0;

    stockLossFields.unavailable.hidden =
        available;

    [
        stockLossFields.quantity,
        stockLossFields.unitCost,
        stockLossFields.supplier,
        stockLossFields.notes,
        stockLossFields.submit,
        ...stockLossFields.stepButtons,
        ...stockLossFields.form.querySelectorAll('input[name="loss_reason"]')
    ].forEach(control => {
        control.disabled = !available;
    });


    renderVariantPicker({
        picker: stockLossFields.picker,
        legend: stockLossFields.legend,
        variants,
        emptyMessage: 'This product has no color/size variants.',
        isDisabled: variant => restockVariantMetrics(variant).stock <= 0,
        onSelect: selectStockLossVariant,
        focusAfterClick: stockLossFields.quantity
    });

    if (inStock.length === 1) {
        selectStockLossVariant(inStock[0]);
    } else {
        updateStockLossPreview();
    }


    stockLossModal.hidden =
        false;

    stockLossModal
        .querySelector('.restock-card')
        .scrollTop = 0;

    updateBodyLock();
}


function closeStockLossModal() {

    stockLossModal.hidden =
        true;

    updateBodyLock();

    if (stockLossState.trigger) {
        stockLossState.trigger.focus();
    }
}


stockLossFields.quantity.addEventListener(
    'input',
    updateStockLossPreview
);


stockLossFields.unitCost.addEventListener(
    'input',
    () => {
        setRestockError(stockLossFields.unitCostError, '');
        updateStockLossPreview();
    }
);


stockLossFields.stepButtons.forEach(button => {

    button.addEventListener(
        'click',
        () => {

            const stock =
                restockVariantMetrics(stockLossState.selectedVariant).stock;

            const next =
                (stockLossQuantityValue() ?? 0)
                + Number(button.dataset.stockLossStep);

            // Stays between 1 and the size's stock (when a size is chosen).
            stockLossFields.quantity.value =
                Math.max(1, stock > 0 ? Math.min(next, stock) : next);

            updateStockLossPreview();
        }
    );
});


/*
| Inline validation; the server checks everything again. Once a valid save
| starts, the button is locked so a double click cannot remove stock twice.
*/

stockLossFields.form.addEventListener(
    'submit',
    event => {

        if (stockLossState.submitting) {
            event.preventDefault();
            return;
        }

        const variant =
            stockLossState.selectedVariant;

        const quantity =
            stockLossQuantityValue();

        const stock =
            restockVariantMetrics(variant).stock;

        let firstInvalid =
            null;

        setRestockError(stockLossFields.variantError, '');
        setRestockError(stockLossFields.quantityError, '');
        setRestockError(stockLossFields.unitCostError, '');

        if (!variant) {
            setRestockError(stockLossFields.variantError, 'Choose the color and size.');
            firstInvalid = stockLossFields.picker.querySelector('input:not(:disabled)');
        }

        if (quantity === null) {
            setRestockError(
                stockLossFields.quantityError,
                stockLossFields.quantity.value.trim() === ''
                    ? 'Enter how many units were lost.'
                    : 'Quantity must be a whole number (1 or more).'
            );
            firstInvalid = firstInvalid || stockLossFields.quantity;
        } else if (variant && quantity > stock) {
            setRestockError(
                stockLossFields.quantityError,
                `Only ${restockUnits(stock)} of ${variant.color} / ${variant.size} in stock.`
            );
            firstInvalid = firstInvalid || stockLossFields.quantity;
        }

        const unitCost =
            Number(stockLossFields.unitCost.value);

        if (stockLossFields.unitCost.value.trim() === '' || !(unitCost >= 0)) {
            setRestockError(stockLossFields.unitCostError, 'Enter the cost per unit (0 or more).');
            firstInvalid = firstInvalid || stockLossFields.unitCost;
        }

        if (firstInvalid) {
            event.preventDefault();
            firstInvalid.focus();
            return;
        }

        stockLossState.submitting = true;
        stockLossFields.submit.disabled = true;
        stockLossFields.submitText.textContent = 'Saving…';
    }
);


document
    .querySelectorAll('.stock-loss-button')
    .forEach(button => {
        button.addEventListener(
            'click',
            () => openStockLossModal(
                button.dataset.id,
                button.dataset.name,
                button
            )
        );
    });


stockLossModal
    .querySelectorAll('[data-close-stock-loss]')
    .forEach(element => {
        element.addEventListener('click', closeStockLossModal);
    });


/* =========================================================
   GLOBAL CONFIRMATION MODAL - INVENTORY DESTRUCTIVE ACTIONS
========================================================= */

document.addEventListener(
    'DOMContentLoaded',
    () => {


        const systemConfirmSubmit =
            document.getElementById(
                'systemConfirmSubmit'
            );


        const systemConfirmCancel =
            document.getElementById(
                'systemConfirmCancel'
            );


        const systemConfirmClose =
            document.getElementById(
                'systemConfirmClose'
            );


        const systemConfirmBackdrop =
            document.getElementById(
                'systemConfirmBackdrop'
            );


        if (!systemConfirmSubmit) {
            return;
        }


        function resetPendingInventoryDestructiveAction() {

            pendingInventoryDestructiveAction =
                null;


            if (
                pendingInventoryTemporaryTrigger
            ) {

                pendingInventoryTemporaryTrigger
                    .remove();


                pendingInventoryTemporaryTrigger =
                    null;

            }


            document
                .querySelectorAll(
                    '.color-size-remove[data-confirm], .color-group-remove[data-confirm], [data-remove-color-photo][data-confirm]'
                )
                .forEach(
                    button => {

                        button.removeAttribute(
                            'data-confirm'
                        );

                    }
                );

        }


        systemConfirmSubmit.addEventListener(
            'click',
            () => {


                if (
                    typeof pendingInventoryDestructiveAction
                    !== 'function'
                ) {
                    return;
                }


                const action =
                    pendingInventoryDestructiveAction;


                pendingInventoryDestructiveAction =
                    null;


                action();


                document
                    .querySelectorAll(
                        '.color-size-remove[data-confirm], .color-group-remove[data-confirm], [data-remove-color-photo][data-confirm]'
                    )
                    .forEach(
                        button => {

                            button.removeAttribute(
                                'data-confirm'
                            );

                        }
                    );

            }
        );


        [
            systemConfirmCancel,
            systemConfirmClose,
            systemConfirmBackdrop
        ]
            .filter(Boolean)
            .forEach(
                element => {

                    element.addEventListener(
                        'click',
                        resetPendingInventoryDestructiveAction
                    );

                }
            );


        document.addEventListener(
            'keydown',
            event => {


                if (
                    event.key ===
                    'Escape'
                ) {

                    resetPendingInventoryDestructiveAction();

                }

            }
        );


    }
);


/* =========================================================
   GLOBAL CONFIRMATION MODAL - PRODUCT STATUS
========================================================= */

let pendingInventoryStatusForm =
    null;


document
    .querySelectorAll(
        '[data-inventory-confirm-form]'
    )
    .forEach(
        button => {

            button.addEventListener(
                'click',
                () => {

                    const formId =
                        button.dataset
                            .inventoryConfirmForm;


                    pendingInventoryStatusForm =
                        document.getElementById(
                            formId
                        );

                }
            );

        }
    );


document.addEventListener(
    'DOMContentLoaded',
    () => {


        const systemConfirmSubmit =
            document.getElementById(
                'systemConfirmSubmit'
            );


        const systemConfirmCancel =
            document.getElementById(
                'systemConfirmCancel'
            );


        const systemConfirmClose =
            document.getElementById(
                'systemConfirmClose'
            );


        const systemConfirmBackdrop =
            document.getElementById(
                'systemConfirmBackdrop'
            );


        if (!systemConfirmSubmit) {
            return;
        }


        function resetInventoryStatusConfirmation() {

            pendingInventoryStatusForm =
                null;

        }


        systemConfirmSubmit.addEventListener(
            'click',
            () => {


                if (
                    !pendingInventoryStatusForm
                ) {
                    return;
                }


                const form =
                    pendingInventoryStatusForm;


                pendingInventoryStatusForm =
                    null;


                form.submit();

            }
        );


        [
            systemConfirmCancel,
            systemConfirmClose,
            systemConfirmBackdrop
        ]
            .filter(Boolean)
            .forEach(
                element => {

                    element.addEventListener(
                        'click',
                        resetInventoryStatusConfirmation
                    );

                }
            );


        document.addEventListener(
            'keydown',
            event => {


                if (
                    event.key ===
                    'Escape'
                ) {

                    resetInventoryStatusConfirmation();

                }

            }
        );


    }
);


/* =========================================================
   DELETE PRODUCT
   Allowed products use the global confirmation modal through
   data-confirm-form (handled by /assets/js/ui.js). Blocked ones
   explain why instead. The server re-checks every rule.
========================================================= */

document.addEventListener(
    'click',
    event => {

        const button =
            event.target.closest(
                '[data-delete-blocked]'
            );


        if (!button) {
            return;
        }


        window.UA.toast(
            button.dataset.deleteBlocked,
            'warning'
        );

    }
);


/* =========================================================
   SEARCH / CATEGORY FILTER
========================================================= */

const searchInput =
    document.getElementById(
        'inventorySearch'
    );


const categoryFilter =
    document.getElementById(
        'categoryFilter'
    );


function filterInventory() {

    const search =
        searchInput
            .value
            .trim()
            .toLowerCase();


    const category =
        categoryFilter
            .value
            .trim()
            .toLowerCase();


    document
        .querySelectorAll(
            '.inventory-product-row'
        )
        .forEach(
            row => {

                const searchMatch =
                    row.dataset.search
                        .includes(
                            search
                        );


                const categoryMatch =
                    category === '' ||
                    row.dataset.category ===
                    category;


                row.style.display =
                    (
                        searchMatch &&
                        categoryMatch
                    )
                        ? ''
                        : 'none';

            }
        );

}


searchInput.addEventListener(
    'input',
    filterInventory
);


categoryFilter.addEventListener(
    'change',
    filterInventory
);


/* =========================================================
   ESCAPE
========================================================= */

document.addEventListener(
    'keydown',
    event => {

        if (event.key !== 'Escape') {
            return;
        }


        /*
         * When the global confirmation is open on top of a product
         * modal, Escape belongs to the confirmation only. Without this,
         * one key press also closed the product modal and lost edits.
         */
        if (window.UA?.isConfirmOpen()) {
            return;
        }


        if (!addModal.hidden) {

            closeAddModal();

            return;
        }


        if (!editModal.hidden) {

            closeEditModal();

            return;
        }


        if (!restockModal.hidden) {

            closeRestockModal();

            return;
        }


        if (!stockLossModal.hidden) {

            closeStockLossModal();

        }

    }
);

</script>


<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';

?>
