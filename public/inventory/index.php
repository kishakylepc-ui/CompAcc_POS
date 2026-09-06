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


function parseProductVariants(string $json): array
{
    $rows = json_decode($json, true);

    if (!is_array($rows) || $rows === []) {
        throw new RuntimeException('Add at least one color and size variant.');
    }

    $variants = [];
    $combinations = [];
    $skus = [];
    $barcodes = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('Invalid product variant data.');
        }

        $id = (int) ($row['id'] ?? 0);
        $color = trim((string) ($row['color'] ?? ''));
        $colorHex = strtoupper(trim((string) ($row['color_hex'] ?? '')));
        $size = trim((string) ($row['size'] ?? ''));
        $sku = trim((string) ($row['sku'] ?? ''));
        $barcode = trim((string) ($row['barcode'] ?? ''));
        $stock = filter_var($row['stock_quantity'] ?? null, FILTER_VALIDATE_INT);
        $status = trim((string) ($row['status'] ?? 'Active'));

        if ($color === '' || $size === '' || $sku === '' || $barcode === '') {
            throw new RuntimeException('Every variant needs a color, size, SKU and barcode.');
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

        $combinationKey = strtolower($color . '|' . $size);
        $skuKey = strtolower($sku);
        $barcodeKey = strtolower($barcode);

        if (isset($combinations[$combinationKey])) {
            throw new RuntimeException('A color and size combination can only be added once.');
        }

        if (isset($skus[$skuKey]) || isset($barcodes[$barcodeKey])) {
            throw new RuntimeException('Variant SKUs and barcodes must be unique.');
        }

        $combinations[$combinationKey] = true;
        $skus[$skuKey] = true;
        $barcodes[$barcodeKey] = true;

        $variants[] = [
            'id' => $id,
            'color' => $color,
            'color_hex' => $colorHex !== '' ? $colorHex : null,
            'size' => $size,
            'sku' => $sku,
            'barcode' => $barcode,
            'stock_quantity' => (int) $stock,
            'status' => $status
        ];
    }

    return $variants;
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

        $barcode =
            trim(
                $_POST['barcode']
                ?? ''
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


        $reorderLevel =
            (int) (
                $_POST['reorder_level']
                ?? 10
            );


        $expirationDate =
            trim(
                $_POST['expiration_date']
                ?? ''
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

        if ($barcode === '') {

            inventoryFlash(
                'error',
                'Barcode is required.'
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
        | UNIQUE BARCODE
        |--------------------------------------------------------------------------
        */

        $barcodeCheck =
            $pdo->prepare("
                SELECT id
                FROM products
                WHERE barcode = ?
                LIMIT 1
            ");


        $barcodeCheck->execute([
            $barcode
        ]);


        if ($barcodeCheck->fetch()) {

            inventoryFlash(
                'error',
                'That barcode is already assigned to another product.'
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
                        ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )
                ");


            $insert->execute([

                $barcode,

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

                $stockQuantity,

                $reorderLevel,

                $expirationDate !== ''
                    ? $expirationDate
                    : null,

                $status

            ]);


            $productId =
                (int) $pdo->lastInsertId();


            $variantInsert = $pdo->prepare("
                INSERT INTO product_variants (
                    product_id, color, color_hex, size, sku, barcode,
                    stock_quantity, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");


            foreach ($variants as $variant) {
                $variantInsert->execute([
                    $productId,
                    $variant['color'],
                    $variant['color_hex'],
                    $variant['size'],
                    $variant['sku'],
                    $variant['barcode'],
                    $variant['stock_quantity'],
                    $variant['status']
                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | INVENTORY LOG
            |--------------------------------------------------------------------------
            */

            $inventoryLog =
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
                        ?, ?, NULL, NULL,
                        ?, ?, ?, ?, ?
                    )
                ");


            $inventoryLog->execute([

                $productId,

                $_SESSION['user_id'],

                'Initial Stock',

                $stockQuantity,

                0,

                $stockQuantity,

                'Product created with initial stock.'

            ]);


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
                . ' with barcode '
                . $barcode

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


        $barcode =
            trim(
                $_POST['barcode']
                ?? ''
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


        $reorderLevel =
            (int) (
                $_POST['reorder_level']
                ?? 0
            );


        $expirationDate =
            trim(
                $_POST['expiration_date']
                ?? ''
            );


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


        if (
            $barcode === '' ||
            $productName === ''
        ) {

            inventoryFlash(
                'error',
                'Barcode and product name are required.'
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
                    product_name
                FROM products
                WHERE id = ?
                LIMIT 1
            ");


        $existing->execute([
            $productId
        ]);


        if (!$existing->fetch()) {

            inventoryFlash(
                'error',
                'Product not found.'
            );


            inventoryRedirect();
        }


        /*
        |--------------------------------------------------------------------------
        | BARCODE UNIQUE
        |--------------------------------------------------------------------------
        */

        $barcodeCheck =
            $pdo->prepare("
                SELECT id
                FROM products
                WHERE barcode = ?
                  AND id != ?
                LIMIT 1
            ");


        $barcodeCheck->execute([
            $barcode,
            $productId
        ]);


        if ($barcodeCheck->fetch()) {

            inventoryFlash(
                'error',
                'That barcode belongs to another product.'
            );


            inventoryRedirect();
        }


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
                        barcode = ?,
                        product_name = ?,
                        category_id = ?,
                        cost_price = ?,
                        selling_price = ?,
                        reorder_level = ?,
                        expiration_date = ?,
                        status = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");


            $update->execute([

                $barcode,

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

                $expirationDate !== ''
                    ? $expirationDate
                    : null,

                $status,

                $productId

            ]);


            $existingVariantRows = $pdo->prepare("
                SELECT id FROM product_variants WHERE product_id = ?
            ");
            $existingVariantRows->execute([$productId]);
            $existingVariantIds = array_map('intval', $existingVariantRows->fetchAll(PDO::FETCH_COLUMN));

            $temporaryKeys = $pdo->prepare("
                UPDATE product_variants
                SET sku = '__EDIT_SKU_' || id,
                    barcode = '__EDIT_BARCODE_' || id
                WHERE product_id = ?
            ");
            $temporaryKeys->execute([$productId]);

            $variantUpdate = $pdo->prepare("
                UPDATE product_variants
                SET color = ?, color_hex = ?, size = ?, sku = ?, barcode = ?,
                    stock_quantity = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND product_id = ?
            ");
            $variantInsert = $pdo->prepare("
                INSERT INTO product_variants (
                    product_id, color, color_hex, size, sku, barcode,
                    stock_quantity, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $submittedVariantIds = [];

            foreach ($variants as $variant) {
                if ($variant['id'] > 0) {
                    if (!in_array($variant['id'], $existingVariantIds, true)) {
                        throw new RuntimeException('A submitted variant does not belong to this product.');
                    }

                    $variantUpdate->execute([
                        $variant['color'], $variant['color_hex'], $variant['size'],
                        $variant['sku'], $variant['barcode'], $variant['stock_quantity'],
                        $variant['status'], $variant['id'], $productId
                    ]);
                    $submittedVariantIds[] = $variant['id'];
                } else {
                    $variantInsert->execute([
                        $productId, $variant['color'], $variant['color_hex'],
                        $variant['size'], $variant['sku'], $variant['barcode'],
                        $variant['stock_quantity'], $variant['status']
                    ]);
                }
            }

            foreach ($existingVariantIds as $existingVariantId) {
                if (!in_array($existingVariantId, $submittedVariantIds, true)) {
                    $variantUpdate->execute([
                        'Archived-' . $existingVariantId, null, 'Archived',
                        '__ARCHIVED_SKU_' . $existingVariantId,
                        '__ARCHIVED_BARCODE_' . $existingVariantId,
                        0, 'Inactive', $existingVariantId, $productId
                    ]);
                }
            }


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


        $quantity =
            (int) (
                $_POST['restock_quantity']
                ?? 0
            );


        $notes =
            trim(
                $_POST['restock_notes']
                ?? ''
            );


        if (
            $productId <= 0 ||
            $variantId <= 0 ||
            $quantity <= 0
        ) {

            inventoryFlash(
                'error',
                'Restock quantity must be greater than zero.'
            );


            inventoryRedirect();
        }


        try {

            $pdo->beginTransaction();


            $statement =
                $pdo->prepare("
                    SELECT
                        p.id,
                        p.product_name,
                        p.stock_quantity,
                        pv.id AS variant_id,
                        pv.color,
                        pv.size,
                        pv.stock_quantity AS variant_stock
                    FROM products p
                    INNER JOIN product_variants pv
                        ON pv.product_id = p.id
                    WHERE p.id = ?
                      AND pv.id = ?
                      AND pv.status = 'Active'
                    LIMIT 1
                ");


            $statement->execute([
                $productId,
                $variantId
            ]);


            $product =
                $statement->fetch();


            if (!$product) {

                throw new RuntimeException(
                    'Product not found.'
                );
            }


            $previousStock =
                (int) $product[
                    'stock_quantity'
                ];


            $newStock =
                $previousStock +
                $quantity;


            $previousVariantStock =
                (int) $product['variant_stock'];


            $newVariantStock =
                $previousVariantStock + $quantity;


            $update =
                $pdo->prepare("
                    UPDATE product_variants
                    SET
                        stock_quantity = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                      AND product_id = ?
                ");


            $update->execute([
                $newVariantStock,
                $variantId,
                $productId
            ]);


            $inventoryLog =
                $pdo->prepare("
                    INSERT INTO inventory_logs (
                        product_id,
                        user_id,
                        supplier_id,
                        sale_id,
                        action,
                        variant_id,
                        size,
                        color,
                        quantity_change,
                        previous_stock,
                        new_stock,
                        notes
                    )
                    VALUES (
                        ?, ?, NULL, NULL,
                        ?, ?, ?, ?, ?, ?, ?, ?
                    )
                ");


            $inventoryLog->execute([

                $productId,

                $_SESSION['user_id'],

                'Restock',

                $variantId,

                $product['size'],

                $product['color'],

                $quantity,

                $previousStock,

                $newStock,

                $notes !== ''
                    ? $notes
                    : 'Manual restock for '
                        . $product['color']
                        . ' / '
                        . $product['size']
                        . '.'

            ]);


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

                'RESTOCK_PRODUCT',

                'Inventory',

                'Product',

                $productId,

                'Restocked '
                . $product['product_name']
                . ' by '
                . $quantity
                . ' unit(s).'

            ]);


            $pdo->commit();


            inventoryFlash(
                'success',
                $product['product_name']
                . ' restocked successfully.'
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


$expirationWarningDays =
    30;


$settingsStatement =
    $pdo->query("
        SELECT
            setting_key,
            setting_value
        FROM settings
        WHERE setting_key IN (
            'low_stock_threshold',
            'expiration_warning_days'
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
        'expiration_warning_days'
    ) {

        $expirationWarningDays =
            max(
                0,
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
            p.barcode,
            p.product_name,
            p.category_id,
            p.cost_price,
            p.selling_price,
            p.stock_quantity,
            p.reorder_level,
            p.expiration_date,
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


$variantStatement = $pdo->query("
    SELECT id, product_id, color, color_hex, size, sku, barcode,
           stock_quantity, status, image_path
    FROM product_variants
    WHERE NOT (status = 'Inactive' AND color LIKE 'Archived-%')
    ORDER BY product_id, color, size
");


$variantsByProduct = [];


foreach ($variantStatement->fetchAll() as $variant) {
    $variantsByProduct[(int) $variant['product_id']][] = [
        'id' => (int) $variant['id'],
        'color' => (string) $variant['color'],
        'color_hex' => (string) ($variant['color_hex'] ?? ''),
        'size' => (string) $variant['size'],
        'sku' => (string) $variant['sku'],
        'barcode' => (string) $variant['barcode'],
        'stock_quantity' => (int) $variant['stock_quantity'],
        'status' => (string) $variant['status'],
        'image_path' => (string) ($variant['image_path'] ?? '')
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


$lowStockProducts =
    0;


$expiringProducts =
    0;


$today =
    new DateTimeImmutable(
        'today'
    );


$expiryLimit =
    $today->modify(
        '+'
        . $expirationWarningDays
        . ' days'
    );


foreach ($products as $product) {

    if (
        $product['status'] ===
        'Active'
    ) {

        $activeProducts++;
    }


    $stock =
        (int) $product[
            'stock_quantity'
        ];


    $reorder =
        (int) $product[
            'reorder_level'
        ];


    $warningLevel =
        $reorder > 0
            ? $reorder
            : $lowStockThreshold;


    if ($stock <= $warningLevel) {

        $lowStockProducts++;
    }


    if (
        !empty(
            $product[
                'expiration_date'
            ]
        )
    ) {

        try {

            $expiration =
                new DateTimeImmutable(
                    $product[
                        'expiration_date'
                    ]
                );


            if (
                $expiration >= $today &&
                $expiration <= $expiryLimit
            ) {

                $expiringProducts++;
            }


        } catch (Throwable $ignored) {
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

        'barcode' =>
            (string) $product[
                'barcode'
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
            (int) $product[
                'reorder_level'
            ],

        'expiration_date' =>
            (string) (
                $product[
                    'expiration_date'
                ]
                ?? ''
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
            ?? []

    ];
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
    href="/assets/css/inventory.css"
>


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
                Manage product photos, categories,
                prices and stock levels.
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
                    Low Stock
                </span>

                <strong>
                    <?= $lowStockProducts ?>
                </strong>

            </div>

        </div>


        <div class="inventory-stat-card">

            <div class="stat-icon">

                <span class="material-symbols-rounded">
                    event
                </span>

            </div>

            <div>

                <span>
                    Expiring Soon
                </span>

                <strong>
                    <?= $expiringProducts ?>
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
                        placeholder="Search name or barcode..."
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

                        <th>Barcode</th>

                        <th>Category</th>

                        <th>Price</th>

                        <th>Stock</th>

                        <th>Expiration</th>

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


                            $reorder =
                                (int) $product[
                                    'reorder_level'
                                ];


                            $warningLevel =
                                $reorder > 0
                                    ? $reorder
                                    : $lowStockThreshold;


                            $isLowStock =
                                $stock <=
                                $warningLevel;


                            $expirationDate =
                                trim(
                                    (string) (
                                        $product[
                                            'expiration_date'
                                        ]
                                        ?? ''
                                    )
                                );


                            $expirationText =
                                '—';


                            $expirationClass =
                                '';


                            if ($expirationDate !== '') {

                                $expirationText =
                                    date(
                                        'M d, Y',
                                        strtotime(
                                            $expirationDate
                                        )
                                    );


                                try {

                                    $expiration =
                                        new DateTimeImmutable(
                                            $expirationDate
                                        );


                                    if (
                                        $expiration <
                                        $today
                                    ) {

                                        $expirationClass =
                                            'expired';

                                    } elseif (
                                        $expiration <=
                                        $expiryLimit
                                    ) {

                                        $expirationClass =
                                            'expiring';
                                    }


                                } catch (
                                    Throwable $ignored
                                ) {
                                }
                            }


                            $searchText =
                                strtolower(
                                    $product[
                                        'product_name'
                                    ]
                                    . ' '
                                    . $product[
                                        'barcode'
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
                                                        <span class="inventory-variant-chip">
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
                                                'barcode'
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
                                                Low stock
                                            </span>

                                        <?php else: ?>

                                            <small>

                                                Reorder:

                                                <?= $warningLevel ?>

                                            </small>

                                        <?php endif; ?>

                                    </div>

                                </td>


                                <td>

                                    <span
                                        class="inventory-expiration <?= $expirationClass ?>"
                                    >

                                        <?= htmlspecialchars(
                                            $expirationText
                                        ) ?>

                                    </span>

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
                        Product Photo
                    </small>

                </div>


                <div class="product-image-upload-info">

                    <strong>
                        Product Photo
                    </strong>

                    <p>
                        The photo is stored in the project
                        files, not in the SQLite database.
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
                        Barcode
                    </label>

                    <input
                        type="text"
                        name="barcode"
                        placeholder="Example: 1000002"
                        required
                    >

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
                        Reorder Level
                    </label>

                    <input
                        type="number"
                        name="reorder_level"
                        min="0"
                        step="1"
                        value="<?= $lowStockThreshold ?>"
                        required
                    >

                </div>


                <div class="inventory-field full">

                    <label>
                        Expiration Date
                    </label>

                    <input
                        type="date"
                        name="expiration_date"
                    >

                    <small>
                        Optional for clothing products.
                    </small>

                </div>

            </div>


            <section class="variant-editor" data-variant-editor="add">
                <div class="variant-editor-heading">
                    <div>
                        <strong>Color and Size Variants</strong>
                        <p>Each combination receives its own SKU, barcode and stock.</p>
                    </div>
                    <button type="button" class="inventory-secondary-button" data-add-variant="add">
                        <span class="material-symbols-rounded">add</span>
                        Add Variant
                    </button>
                </div>
                <input type="hidden" name="variants_json" id="addVariantsJson">
                <div class="variant-rows" id="addVariantRows"></div>
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
                        Product Photo
                    </strong>

                    <p>
                        Upload a new image to replace
                        the current product photo.
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
                        Barcode
                    </label>

                    <input
                        type="text"
                        name="barcode"
                        id="editBarcode"
                        required
                    >

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
                        Reorder Level
                    </label>

                    <input
                        type="number"
                        name="reorder_level"
                        id="editReorderLevel"
                        min="0"
                        step="1"
                        required
                    >

                </div>


                <div class="inventory-field">

                    <label>
                        Expiration Date
                    </label>

                    <input
                        type="date"
                        name="expiration_date"
                        id="editExpirationDate"
                    >

                </div>

            </div>


            <section class="variant-editor" data-variant-editor="edit">
                <div class="variant-editor-heading">
                    <div>
                        <strong>Color and Size Variants</strong>
                        <p>Edit existing combinations or add another color and size.</p>
                    </div>
                    <button type="button" class="inventory-secondary-button" data-add-variant="edit">
                        <span class="material-symbols-rounded">add</span>
                        Add Variant
                    </button>
                </div>
                <input type="hidden" name="variants_json" id="editVariantsJson">
                <div class="variant-rows" id="editVariantRows"></div>
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
                <label>Color and Size</label>
                <select name="variant_id" id="restockVariantId" required></select>
            </div>


            <div class="inventory-field">

                <label>
                    Current Stock
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
                    Quantity to Add
                </label>

                <input
                    type="number"
                    name="restock_quantity"
                    id="restockQuantity"
                    min="1"
                    step="1"
                    required
                >

            </div>


            <div class="inventory-field">

                <label>
                    Notes
                </label>

                <textarea
                    name="restock_notes"
                    id="restockNotes"
                    rows="3"
                    placeholder="Optional restock notes..."
                ></textarea>

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


function addVariantRow(mode, variant = {}) {
    const container = document.getElementById(mode === 'add' ? 'addVariantRows' : 'editVariantRows');
    const row = document.createElement('div');
    row.className = 'variant-row';
    row.dataset.variantId = Number(variant.id || 0);
    row.innerHTML = `
        <div class="variant-field"><label>Color</label><input data-variant-field="color" value="${variantEscape(variant.color || '')}" placeholder="Charcoal Black" required></div>
        <div class="variant-field color-field"><label>Color Hex</label><input type="color" data-variant-field="color_hex" value="${variantEscape(variant.color_hex || '#292929')}"></div>
        <div class="variant-field"><label>Size</label><input data-variant-field="size" value="${variantEscape(variant.size || '')}" placeholder="M" required></div>
        <div class="variant-field"><label>SKU</label><input data-variant-field="sku" value="${variantEscape(variant.sku || '')}" placeholder="UAPP-CB-M" required></div>
        <div class="variant-field"><label>Barcode</label><input data-variant-field="barcode" value="${variantEscape(variant.barcode || '')}" placeholder="1000001-CB-M" required></div>
        <div class="variant-field"><label>Stock</label><input type="number" min="0" step="1" data-variant-field="stock_quantity" value="${Number(variant.stock_quantity || 0)}" required></div>
        <div class="variant-field"><label>Status</label><select data-variant-field="status"><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div>
        <button type="button" class="variant-remove" title="Remove variant"><span class="material-symbols-rounded">delete</span></button>
    `;
    row.querySelector('[data-variant-field="status"]').value = variant.status || 'Active';
    row.querySelector('.variant-remove').addEventListener('click', () => {
        if (container.children.length === 1) {
            alert('A product must have at least one variant.');
            return;
        }
        row.remove();
    });
    container.appendChild(row);
}


function serializeVariants(mode) {
    const container = document.getElementById(mode === 'add' ? 'addVariantRows' : 'editVariantRows');
    const variants = Array.from(container.querySelectorAll('.variant-row')).map(row => ({
        id: Number(row.dataset.variantId || 0),
        color: row.querySelector('[data-variant-field="color"]').value.trim(),
        color_hex: row.querySelector('[data-variant-field="color_hex"]').value,
        size: row.querySelector('[data-variant-field="size"]').value.trim(),
        sku: row.querySelector('[data-variant-field="sku"]').value.trim(),
        barcode: row.querySelector('[data-variant-field="barcode"]').value.trim(),
        stock_quantity: Number(row.querySelector('[data-variant-field="stock_quantity"]').value),
        status: row.querySelector('[data-variant-field="status"]').value
    }));
    document.getElementById(mode === 'add' ? 'addVariantsJson' : 'editVariantsJson').value = JSON.stringify(variants);
}


document.querySelectorAll('[data-add-variant]').forEach(button => {
    button.addEventListener('click', () => addVariantRow(button.dataset.addVariant));
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

            const variantRows = document.getElementById('addVariantRows');
            if (variantRows.children.length === 0) {
                addVariantRow('add', {
                    color: 'Charcoal Black',
                    color_hex: '#292929',
                    size: 'M',
                    status: 'Active',
                    stock_quantity: 0
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
            'editBarcode'
        )
        .value =
        product.barcode;


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


    document
        .getElementById(
            'editReorderLevel'
        )
        .value =
        product.reorder_level;


    document
        .getElementById(
            'editExpirationDate'
        )
        .value =
        product.expiration_date;


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


    const editVariantRows = document.getElementById('editVariantRows');
    editVariantRows.innerHTML = '';
    (product.variants || []).forEach(variant => addVariantRow('edit', variant));

    if (editVariantRows.children.length === 0) {
        addVariantRow('edit');
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

function openRestockModal(
    id,
    name,
    stock
) {

    const product = inventoryProducts[id];
    const variantSelect = document.getElementById('restockVariantId');
    variantSelect.innerHTML = '';

    (product?.variants || [])
        .filter(variant => variant.status === 'Active')
        .forEach(variant => {
            const option = document.createElement('option');
            option.value = variant.id;
            option.textContent = `${variant.color} / ${variant.size} — ${variant.stock_quantity} in stock`;
            option.dataset.stock = variant.stock_quantity;
            variantSelect.appendChild(option);
        });

    const updateVariantStockLabel = () => {
        const option = variantSelect.options[variantSelect.selectedIndex];
        const variantStock = Number(option?.dataset.stock || 0);
        document.getElementById('restockCurrentStock').textContent =
            `${variantStock} ${variantStock === 1 ? 'unit' : 'units'} in selected variant`;
    };

    variantSelect.onchange = updateVariantStockLabel;

    document
        .getElementById(
            'restockProductId'
        )
        .value =
        id;


    document
        .getElementById(
            'restockProductDescription'
        )
        .textContent =
        name;


    document
        .getElementById(
            'restockCurrentStock'
        )
        .textContent =
        `${stock} total units`;


    updateVariantStockLabel();


    document
        .getElementById(
            'restockQuantity'
        )
        .value =
        '';


    document
        .getElementById(
            'restockNotes'
        )
        .value =
        '';


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
