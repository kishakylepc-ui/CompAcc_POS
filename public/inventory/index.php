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

function inventoryRedirect(): never
{
    header('Location: /inventory/');
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
                . $error->getMessage()
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


            $existingVariantRows = $pdo->prepare("\n                SELECT\n                    id,\n                    stock_quantity,\n                    barcode,\n                    image_path\n                FROM product_variants\n                WHERE product_id = ?\n            ");

            $existingVariantRows->execute([$productId]);

            $existingVariantStocks = [];
            $existingVariantBarcodes = [];
            $existingVariantImages = [];

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
            }

            $existingVariantIds =
                array_keys($existingVariantStocks);


            $colorImagePaths = inventoryResolveColorImagePaths(
                $variants,
                $_FILES['color_images'] ?? [],
                $productImageDirectory,
                $productId,
                $existingVariantImages
            );


            $temporaryKeys = $pdo->prepare("\n                UPDATE product_variants\n                SET sku = '__EDIT_SKU_' || id\n                WHERE product_id = ?\n            ");

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
                . $error->getMessage()
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
    href="/assets/css/inventory.css"
>

<style>
/* =========================================================
   INVENTORY PATCH - RESTOCK + AUTO REORDER + VARIANT UX
========================================================= */

.inventory-alert[hidden],
#restockUnavailableMessage[hidden] {
    display: none !important;
}

#restockUnavailableMessage {
    margin-top: 14px;
}

.inventory-field small {
    display: block;
    margin-top: 6px;
    color: rgba(255,255,255,.36);
    font-size: 8px;
    line-height: 1.5;
}

.inventory-current-stock {
    font-variant-numeric: tabular-nums;
}

#restockLineTotal {
    font-size: 15px;
    font-weight: 600;
    color: #fff;
}

.inventory-variant-chip.needs-restock {
    border-color: rgba(255, 178, 102, .42);
    background: rgba(255, 178, 102, .08);
    color: #ffd3aa;
}

.restock-recommendation {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 9px;
    padding: 12px;
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 12px;
    background: rgba(255, 255, 255, .025);
}

.restock-metric {
    min-width: 0;
    padding: 10px;
    border: 1px solid rgba(255, 255, 255, .055);
    border-radius: 9px;
    background: rgba(0, 0, 0, .14);
}

.restock-metric span {
    display: block;
    margin-bottom: 4px;
    color: rgba(255,255,255,.38);
    font-size: 7px;
    letter-spacing: .05em;
    text-transform: uppercase;
}

.restock-metric strong {
    display: block;
    color: rgba(255,255,255,.9);
    font-size: 11px;
    font-weight: 600;
}

.restock-metric.suggested {
    border-color: rgba(208, 173, 123, .34);
    background: rgba(208, 173, 123, .08);
}

.restock-metric.suggested strong {
    color: #e9c99a;
}

.restock-recommendation-note {
    grid-column: 1 / -1;
    margin: 0;
    color: rgba(255,255,255,.42);
    font-size: 8px;
    line-height: 1.55;
}

.restock-recommendation-note.good {
    color: rgba(170, 225, 190, .76);
}

.restock-recommendation-note.warning {
    color: rgba(255, 204, 145, .82);
}

@media (max-width: 640px) {
    .restock-recommendation {
        grid-template-columns: 1fr;
    }

    .restock-recommendation-note {
        grid-column: auto;
    }
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
    gap: 5px;
    padding: 10px 12px;
    border: 1px solid rgba(255,255,255,.09);
    border-radius: 8px;
    background: rgba(255,255,255,.028);
}

.inventory-auto-reorder-value {
    display: flex;
    align-items: center;
    gap: 7px;
}

.inventory-auto-reorder-value .material-symbols-rounded {
    color: rgba(255,255,255,.48);
    font-size: 18px;
}

.inventory-auto-reorder-value strong {
    color: #fff;
    font-size: 12px;
    font-weight: 600;
}


/* =========================================================
   APPAREL INVENTORY LIFECYCLE
========================================================= */

.inventory-lifecycle-card {
    min-height: 72px;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
    padding: 9px;
    border: 1px solid rgba(255,255,255,.09);
    border-radius: 8px;
    background: rgba(255,255,255,.028);
}

.inventory-lifecycle-item {
    min-width: 0;
    display: flex;
    justify-content: center;
    flex-direction: column;
    gap: 3px;
    padding: 8px 10px;
    border: 1px solid rgba(255,255,255,.055);
    border-radius: 7px;
    background: rgba(0,0,0,.16);
}

.inventory-lifecycle-item span {
    color: rgba(255,255,255,.36);
    font-size: 7px;
    font-weight: 600;
    letter-spacing: .055em;
    text-transform: uppercase;
}

.inventory-lifecycle-item strong {
    overflow: hidden;
    color: rgba(255,255,255,.88);
    font-size: 9px;
    font-weight: 500;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.inventory-lifecycle-auto {
    min-height: 72px;
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 10px 12px;
    border: 1px solid rgba(255,255,255,.09);
    border-radius: 8px;
    background: rgba(255,255,255,.028);
}

.inventory-lifecycle-auto .material-symbols-rounded {
    flex: 0 0 auto;
    color: rgba(255,255,255,.48);
    font-size: 19px;
}

.inventory-lifecycle-auto strong {
    display: block;
    color: #fff;
    font-size: 9px;
    font-weight: 600;
}

.inventory-lifecycle-auto small {
    display: block;
    margin-top: 3px;
    color: rgba(255,255,255,.38);
    font-size: 7px;
    line-height: 1.5;
}

.inventory-activity {
    min-width: 132px;
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.inventory-activity-row {
    display: flex;
    flex-direction: column;
    gap: 1px;
}

.inventory-activity-row span {
    color: rgba(255,255,255,.3);
    font-size: 6px;
    font-weight: 600;
    letter-spacing: .04em;
    text-transform: uppercase;
}

.inventory-activity-row strong {
    color: rgba(255,255,255,.68);
    font-size: 8px;
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
    margin: 24px 0 0;
    padding: 18px;
    border: 1px solid rgba(255,255,255,.09);
    border-radius: 12px;
    background: rgba(255,255,255,.018);
}

.variant-editor-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 14px;
}

.variant-editor-heading > div {
    min-width: 0;
}

.variant-editor-heading strong {
    color: #fff;
    font-size: 13px;
    font-weight: 600;
}

.variant-editor-heading p {
    margin: 4px 0 0;
    color: rgba(255,255,255,.4);
    font-size: 9px;
    line-height: 1.5;
}

.variant-rows {
    display: grid;
    gap: 12px;
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
    gap: 2px;
}

.variant-card-title strong {
    color: #fff;
    font-size: 10px;
    font-weight: 600;
}

.variant-card-title small {
    overflow: hidden;
    color: rgba(255,255,255,.36);
    font-size: 8px;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.variant-card-actions {
    display: flex;
    align-items: center;
    gap: 6px;
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
    font-size: 17px;
}

.variant-card-grid {
    display: grid;
    grid-template-columns:
        minmax(180px, 1.3fr)
        78px
        minmax(115px, .7fr)
        minmax(180px, 1fr)
        minmax(220px, 1.25fr);
    gap: 12px;
    padding: 14px;
}

.variant-field {
    min-width: 0;
}

.variant-field label {
    display: block;
    margin-bottom: 6px;
    color: rgba(255,255,255,.44);
    font-size: 8px;
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
    font-size: 9px;
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
    gap: 12px;
    padding: 0 14px 14px;
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
    font-size: 8px;
    line-height: 1.45;
}

.variant-stock-help .material-symbols-rounded {
    flex: 0 0 auto;
    font-size: 16px;
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
.color-groups{display:grid;gap:16px;margin-top:14px}.color-group{border:1px solid rgba(255,255,255,.09);border-radius:16px;background:rgba(7,10,17,.56);overflow:hidden}.color-group-header{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.07);background:rgba(255,255,255,.025)}.color-group-title{display:flex;align-items:center;gap:10px;min-width:0}.color-group-swatch{width:24px;height:24px;border-radius:50%;border:2px solid rgba(255,255,255,.18);box-shadow:0 0 0 3px rgba(255,255,255,.025);flex:0 0 auto}.color-group-title strong{display:block;font-size:12px;color:#f3f4f6}.color-group-title small{display:block;margin-top:2px;font-size:9px;color:#777f8f}.color-group-remove,.color-size-remove,.color-photo-remove{border:1px solid rgba(255,255,255,.09);background:rgba(255,255,255,.035);color:#a6adba;border-radius:9px;cursor:pointer}.color-group-remove{width:34px;height:34px;display:grid;place-items:center}.color-group-remove:hover,.color-size-remove:hover,.color-photo-remove:hover{border-color:rgba(238,113,113,.45);color:#ffaaaa;background:rgba(238,113,113,.08)}.color-group-body{display:grid;grid-template-columns:190px minmax(0,1fr);gap:18px;padding:16px}.color-photo-panel{min-width:0}.color-photo-preview{height:168px;border:1px dashed rgba(255,255,255,.14);border-radius:13px;background:#f1f1f1;display:flex;flex-direction:column;align-items:center;justify-content:center;overflow:hidden;color:#20242d}.color-photo-preview img{width:100%;height:100%;object-fit:contain}.color-photo-preview .material-symbols-rounded{font-size:42px}.color-photo-preview small{font-size:9px;margin-top:4px}.color-photo-actions{display:grid;gap:7px;margin-top:9px}.color-photo-upload{display:flex;align-items:center;justify-content:center;gap:6px;min-height:35px;padding:0 10px;border-radius:9px;border:1px solid rgba(255,255,255,.11);background:rgba(255,255,255,.05);color:#e5e7eb;font-size:9px;font-weight:600;cursor:pointer}.color-photo-upload:hover{background:rgba(255,255,255,.08)}.color-photo-upload input{display:none}.color-photo-remove{min-height:32px;font:inherit;font-size:8px}.color-group-content{min-width:0}.color-details-grid{display:grid;grid-template-columns:minmax(180px,1fr) 120px;gap:10px;margin-bottom:14px}.color-field label,.color-size-field label{display:block;margin-bottom:5px;font-size:8px;font-weight:600;color:#aeb4c0}.color-field input,.color-size-field input,.color-size-field select{width:100%;min-height:38px;border:1px solid rgba(255,255,255,.1);border-radius:9px;background:#0d1119;color:#eef1f5;padding:8px 10px;font:inherit;font-size:9px;outline:none}.color-field input:focus,.color-size-field input:focus,.color-size-field select:focus{border-color:rgba(208,173,123,.7);box-shadow:0 0 0 2px rgba(208,173,123,.08)}.color-field input[type=color]{padding:4px;height:38px}.color-sizes-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:4px 0 8px}.color-sizes-header div strong{display:block;font-size:10px;color:#e9ebef}.color-sizes-header div small{display:block;margin-top:2px;color:#727a88;font-size:8px}.color-add-size{display:inline-flex;align-items:center;gap:5px;border:1px solid rgba(208,173,123,.28);background:rgba(208,173,123,.08);color:#e5c69b;border-radius:8px;padding:7px 9px;font:inherit;font-size:8px;font-weight:600;cursor:pointer}.color-add-size:hover{background:rgba(208,173,123,.14)}.color-size-list{display:grid;gap:8px}.color-size-row{display:grid;grid-template-columns:105px minmax(145px,1fr) 130px 95px 105px 34px;gap:8px;align-items:end;padding:10px;border:1px solid rgba(255,255,255,.065);border-radius:11px;background:rgba(255,255,255,.022)}.color-size-field{min-width:0}.color-size-field input[readonly]{color:#939ba9;background:#090c12}.color-size-remove{width:34px;height:38px;display:grid;place-items:center}.color-group-note{display:flex;gap:7px;align-items:flex-start;margin-top:10px;padding:9px 10px;border-radius:9px;background:rgba(255,255,255,.025);color:#747d8c;font-size:8px;line-height:1.5}.color-group-note .material-symbols-rounded{font-size:15px;color:#d0ad7b}.color-group-empty{padding:24px;border:1px dashed rgba(255,255,255,.12);border-radius:14px;text-align:center;color:#7b8390}.color-group-empty .material-symbols-rounded{font-size:30px;display:block;margin-bottom:5px}.color-group-empty strong{display:block;color:#d9dde4;font-size:10px}.color-group-empty small{font-size:8px}.inventory-variant-chip.has-photo:after{content:'photo';font-family:'Material Symbols Rounded';font-size:12px;margin-left:3px;color:#d0ad7b}@media(max-width:1150px){.color-group-body{grid-template-columns:160px minmax(0,1fr)}.color-size-row{grid-template-columns:95px minmax(130px,1fr) 120px 90px 100px 34px}}@media(max-width:900px){.color-group-body{grid-template-columns:1fr}.color-photo-panel{max-width:260px}.color-size-row{grid-template-columns:repeat(2,minmax(0,1fr))}.color-size-remove{align-self:end}.color-details-grid{grid-template-columns:1fr 110px}}@media(max-width:560px){.color-size-row{grid-template-columns:1fr}.color-details-grid{grid-template-columns:1fr}.color-photo-panel{max-width:none}}
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


                                        <form
                                            method="POST"
                                            action="/inventory/"
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
                                                type="submit"
                                                class="inventory-icon-button"

                                                title="<?= $product[
                                                    'status'
                                                ] === 'Active'
                                                    ? 'Deactivate product'
                                                    : 'Activate product'
                                                ?>"
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
            >

                <span class="material-symbols-rounded">
                    close
                </span>

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
            >

                <span class="material-symbols-rounded">
                    close
                </span>

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
========================================================= -->

<div
    class="inventory-modal"
    id="restockModal"
    hidden
>

    <div class="inventory-modal-backdrop"></div>


    <div class="inventory-modal-card small">

        <div class="inventory-modal-header">

            <div>

                <div class="inventory-eyebrow">
                    STOCK UPDATE
                </div>

                <h3>
                    Restock Product
                </h3>

                <p id="restockProductDescription">
                    Add inventory quantity.
                </p>

            </div>


            <button
                type="button"
                class="inventory-modal-close"
                data-close-restock
            >

                <span class="material-symbols-rounded">
                    close
                </span>

            </button>

        </div>


        <form
            method="POST"
            action="/inventory/"
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
                value="restock_product"
            >

            <input
                type="hidden"
                name="product_id"
                id="restockProductId"
            >


            <div class="inventory-field">

                <label>
                    Supplier
                </label>

                <select
                    name="supplier_id"
                    id="restockSupplierId"
                    required
                ></select>

                <small id="restockSupplierHelp">
                    Select the supplier that delivered this stock.
                </small>

            </div>


            <div class="inventory-field">

                <label>
                    Color and Size
                </label>

                <select
                    name="variant_id"
                    id="restockVariantId"
                    required
                ></select>

            </div>


            <div class="inventory-field">

                <label>
                    Current Variant Stock
                </label>

                <div
                    class="inventory-current-stock"
                    id="restockCurrentStock"
                >
                    0 units
                </div>

            </div>


            <div class="inventory-field">

                <label>
                    Automatic Restock Recommendation
                </label>

                <div class="restock-recommendation">

                    <div class="restock-metric">
                        <span>Avg. Daily Sales</span>
                        <strong id="restockAverageDailySales">
                            0.00 / day
                        </strong>
                    </div>

                    <div class="restock-metric">
                        <span>Reorder Point</span>
                        <strong id="restockReorderPoint">
                            0 units
                        </strong>
                    </div>

                    <div class="restock-metric">
                        <span>Target Stock</span>
                        <strong id="restockTargetStock">
                            0 units
                        </strong>
                    </div>

                    <div class="restock-metric suggested">
                        <span>Suggested Restock</span>
                        <strong id="restockSuggestedQuantity">
                            0 units
                        </strong>
                    </div>

                    <p
                        class="restock-recommendation-note"
                        id="restockRecommendationNote"
                    >
                        Select a variant to calculate its restock recommendation.
                    </p>

                </div>

            </div>


            <div class="inventory-field">

                <label>
                    Quantity Received
                </label>

                <input
                    type="number"
                    name="restock_quantity"
                    id="restockQuantity"
                    min="1"
                    step="1"
                    required
                >

                <small id="restockQuantityHelp">
                    When a variant reaches its reorder point, the suggested quantity is filled automatically. You can still change it to match the actual delivery.
                </small>

            </div>


            <div class="inventory-field">

                <label>
                    Unit Cost
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
                        required
                    >

                </div>

                <small>
                    Defaults to the linked supplier price. You may enter the actual invoice cost.
                </small>

            </div>


            <div class="inventory-field">

                <label>
                    Restock Total
                </label>

                <div
                    class="inventory-current-stock"
                    id="restockLineTotal"
                >
                    ₱0.00
                </div>

            </div>


            <div class="inventory-field">

                <label>
                    Notes
                </label>

                <textarea
                    name="restock_notes"
                    id="restockNotes"
                    rows="3"
                    placeholder="Optional supplier invoice or delivery notes..."
                ></textarea>

            </div>


            <div
                class="inventory-alert error"
                id="restockUnavailableMessage"
                hidden
            >
                <span class="material-symbols-rounded">
                    error
                </span>

                Link this product to an active supplier before restocking it.
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

                    Restock

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

    row.querySelector('.color-size-remove').addEventListener('click', () => {
        if (list.children.length === 1) {
            alert('Each color must keep at least one size.');
            return;
        }

        if (mode === 'edit' && existingVariant && stockValue > 0) {
            alert('This size still has stock. Set it to Inactive instead of removing it.');
            return;
        }

        row.remove();
        colorGroupTitle(group);
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

    group.querySelector('[data-remove-color-photo]').addEventListener('click', () => {
        group.dataset.removeImage = '1';
        group.dataset.existingImage = '';
        group.querySelector('[data-color-image-input]').value = '';
        colorImagePlaceholder(
            preview,
            group.querySelector('[data-color-field="color"]').value.trim() || 'Color photo'
        );
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

    group.querySelector('.color-group-remove').addEventListener('click', () => {
        if (container.children.length === 1) {
            alert('A product must keep at least one color.');
            return;
        }

        if (mode === 'edit') {
            const hasStock = Array.from(group.querySelectorAll('.color-size-row')).some(row => {
                return Number(row.querySelector('[data-size-field="stock_quantity"]').value || 0) > 0;
            });

            if (hasStock) {
                alert('This color still has stock. Set its sizes to Inactive instead of removing the color.');
                return;
            }
        }

        group.remove();
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
            !restockModal.hidden
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


function openRestockModal(
    id,
    name,
    stock
) {

    const product = inventoryProducts[id];

    const supplierSelect =
        document.getElementById('restockSupplierId');

    const variantSelect =
        document.getElementById('restockVariantId');

    const quantityInput =
        document.getElementById('restockQuantity');

    const unitCostInput =
        document.getElementById('restockUnitCost');

    const lineTotal =
        document.getElementById('restockLineTotal');

    const submitButton =
        document.getElementById('restockSubmitButton');

    const unavailableMessage =
        document.getElementById('restockUnavailableMessage');

    const supplierHelp =
        document.getElementById('restockSupplierHelp');

    const averageDailySalesLabel =
        document.getElementById('restockAverageDailySales');

    const reorderPointLabel =
        document.getElementById('restockReorderPoint');

    const targetStockLabel =
        document.getElementById('restockTargetStock');

    const suggestedQuantityLabel =
        document.getElementById('restockSuggestedQuantity');

    const recommendationNote =
        document.getElementById('restockRecommendationNote');


    supplierSelect.innerHTML = '';
    variantSelect.innerHTML = '';


    const suppliers =
        product?.suppliers || [];

    const variants =
        (product?.variants || [])
            .filter(
                variant =>
                    variant.status === 'Active'
            );


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

        supplierSelect.appendChild(option);
    });


    variants.forEach(variant => {

        const option =
            document.createElement('option');

        const metrics =
            variant.reorder_metrics || {};

        option.value =
            variant.id;

        option.textContent =
            `${variant.color} / ${variant.size} — ${variant.stock_quantity} in stock`;

        option.dataset.stock =
            variant.stock_quantity;

        option.dataset.reorderPoint =
            Number(metrics.reorder_level || 0);

        option.dataset.targetStock =
            Number(metrics.target_stock || 0);

        option.dataset.suggestedRestock =
            Number(metrics.suggested_restock || 0);

        option.dataset.averageDailySales =
            Number(metrics.average_daily_sales || 0);

        option.dataset.unitsSold =
            Number(metrics.units_sold || 0);

        option.dataset.windowDays =
            Number(metrics.window_days || 30);

        option.dataset.source =
            metrics.source || 'baseline';

        option.dataset.needsReorder =
            metrics.needs_reorder
                ? '1'
                : '0';

        variantSelect.appendChild(option);
    });


    const primarySupplierIndex =
        Array.from(supplierSelect.options)
            .findIndex(
                option =>
                    option.dataset.primary === '1'
            );

    if (primarySupplierIndex >= 0) {
        supplierSelect.selectedIndex =
            primarySupplierIndex;
    }


    const updateSupplierPrice = () => {

        const option =
            supplierSelect.options[
                supplierSelect.selectedIndex
            ];

        unitCostInput.value =
            option
                ? Number(option.dataset.price || 0).toFixed(2)
                : '';

        updateLineTotal();
    };


    const updateVariantRecommendation = () => {

        const option =
            variantSelect.options[
                variantSelect.selectedIndex
            ];

        const variantStock =
            Number(
                option?.dataset.stock
                || 0
            );

        const reorderPoint =
            Number(
                option?.dataset.reorderPoint
                || 0
            );

        const targetStock =
            Number(
                option?.dataset.targetStock
                || 0
            );

        const suggestedRestock =
            Number(
                option?.dataset.suggestedRestock
                || 0
            );

        const averageDailySales =
            Number(
                option?.dataset.averageDailySales
                || 0
            );

        const unitsSold =
            Number(
                option?.dataset.unitsSold
                || 0
            );

        const windowDays =
            Number(
                option?.dataset.windowDays
                || 30
            );

        const source =
            option?.dataset.source
            || 'baseline';

        const needsReorder =
            option?.dataset.needsReorder ===
            '1';


        document
            .getElementById('restockCurrentStock')
            .textContent =
            `${variantStock} ${variantStock === 1 ? 'unit' : 'units'} in selected variant`;


        averageDailySalesLabel.textContent =
            `${averageDailySales.toFixed(2)} / day`;


        reorderPointLabel.textContent =
            `${reorderPoint} ${reorderPoint === 1 ? 'unit' : 'units'}`;


        targetStockLabel.textContent =
            `${targetStock} ${targetStock === 1 ? 'unit' : 'units'}`;


        suggestedQuantityLabel.textContent =
            `${suggestedRestock} ${suggestedRestock === 1 ? 'unit' : 'units'}`;


        recommendationNote.classList.remove(
            'good',
            'warning'
        );


        if (needsReorder) {

            quantityInput.value =
                suggestedRestock > 0
                    ? String(
                        suggestedRestock
                    )
                    : '1';


            recommendationNote.classList.add(
                'warning'
            );


            if (source === 'sales') {

                recommendationNote.textContent =
                    `${unitsSold} units sold in the last ${windowDays} days. `
                    + `This variant is at or below its automatic reorder point, `
                    + `so it pre-filled the quantity needed to reach its target stock.`;

            } else {

                recommendationNote.textContent =
                    `No recent variant sales are available yet. `
                    + `It is using the new-variant fallback levels and has pre-filled `
                    + `the quantity needed to reach the fallback target stock.`;
            }

        } else {

            quantityInput.value =
                '';


            recommendationNote.classList.add(
                'good'
            );


            if (source === 'sales') {

                recommendationNote.textContent =
                    `This variant is above its reorder point, so no automatic restock is currently recommended. `
                    + `You may still enter a quantity if stock was actually delivered.`;

            } else {

                recommendationNote.textContent =
                    `This variant is above the fallback reorder point. `
                    + `No automatic restock is currently recommended until it reaches the threshold or develops sales history.`;
            }
        }


        updateLineTotal();
    };


    const updateLineTotal = () => {

        const quantity =
            Number(quantityInput.value || 0);

        const unitCost =
            Number(unitCostInput.value || 0);

        lineTotal.textContent =
            formatRestockMoney(
                quantity * unitCost
            );
    };


    supplierSelect.onchange =
        updateSupplierPrice;

    variantSelect.onchange =
        updateVariantRecommendation;

    quantityInput.oninput =
        updateLineTotal;

    unitCostInput.oninput =
        updateLineTotal;


    document
        .getElementById('restockProductId')
        .value =
        id;


    document
        .getElementById('restockProductDescription')
        .textContent =
        `${name} · Receive stock from a linked supplier.`;


    quantityInput.value = '';

    document
        .getElementById('restockNotes')
        .value = '';


    const restockAvailable =
        suppliers.length > 0 &&
        variants.length > 0;


    supplierSelect.disabled =
        suppliers.length === 0;

    variantSelect.disabled =
        variants.length === 0;

    quantityInput.disabled =
        !restockAvailable;

    unitCostInput.disabled =
        !restockAvailable;

    submitButton.disabled =
        !restockAvailable;

    unavailableMessage.hidden =
        restockAvailable;


    if (suppliers.length === 0) {
        supplierHelp.textContent =
            'No active supplier is linked to this product. Add a supplier link first.';
    } else {
        supplierHelp.textContent =
            'The primary supplier is selected automatically when available.';
    }


    updateSupplierPrice();
    updateVariantRecommendation();
    updateLineTotal();


    restockModal.hidden =
        false;


    updateBodyLock();

}


function closeRestockModal() {

    restockModal.hidden =
        true;

    updateBodyLock();
}


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

                        button.dataset.stock

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


restockModal
    .querySelector(
        '.inventory-modal-backdrop'
    )
    .addEventListener(
        'click',
        closeRestockModal
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

        }

    }
);

</script>


<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';

?>
