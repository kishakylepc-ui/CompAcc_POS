<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/config/database.php';

/**
 * CompAcc POS - Automatic Variant Identifier Migration
 *
 * Purpose:
 * - Keep Product / Style Codes at the parent level.
 * - Generate readable variant SKUs from Product Code + Color + Size.
 * - Generate a stable numeric sellable barcode from each variant ID.
 * - Preserve historical sale-item SKU/barcode snapshots.
 *
 * Run once from the project root:
 *   C:\php\php.exe tools\migrate_variant_identifiers.php
 */

function variantIdentifierProductCodeColumnExists(PDO $pdo): bool
{
    foreach ($pdo->query('PRAGMA table_info(products)')->fetchAll() as $column) {
        if ((string) $column['name'] === 'product_code') {
            return true;
        }
    }

    return false;
}

function variantIdentifierColorToken(string $color): string
{
    $value = strtoupper(trim($color));
    $value = preg_replace('/[^A-Z0-9]+/', '-', $value);
    $value = trim((string) $value, '-');

    return $value !== ''
        ? $value
        : 'DEFAULT';
}

function variantIdentifierSizeToken(string $size): string
{
    $value = strtoupper(trim($size));

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

    if (isset($map[$value])) {
        return $map[$value];
    }

    $value = preg_replace('/[^A-Z0-9]+/', '-', $value);
    $value = trim((string) $value, '-');

    return $value !== ''
        ? $value
        : 'STD';
}

function variantIdentifierSku(
    string $productCode,
    string $color,
    string $size
): string {
    return
        strtoupper(trim($productCode))
        . '-'
        . variantIdentifierColorToken($color)
        . '-'
        . variantIdentifierSizeToken($size);
}

function variantIdentifierBarcode(int $variantId): string
{
    return (string) (200000000 + $variantId);
}

$dbPath = __DIR__ . '/../storage/database/pos.sqlite';
$backupDirectory = __DIR__ . '/../storage/backups';

if (!is_file($dbPath)) {
    fwrite(STDERR, "Database not found: {$dbPath}" . PHP_EOL);
    exit(1);
}

if (
    !is_dir($backupDirectory)
    && !mkdir($backupDirectory, 0775, true)
    && !is_dir($backupDirectory)
) {
    fwrite(STDERR, "Unable to create backup directory: {$backupDirectory}" . PHP_EOL);
    exit(1);
}

$backupPath =
    $backupDirectory
    . '/pos-before-auto-variant-identifiers-'
    . date('Ymd-His')
    . '.sqlite';

if (!copy($dbPath, $backupPath)) {
    fwrite(STDERR, "Unable to create database backup." . PHP_EOL);
    exit(1);
}

echo "CompAcc POS - Automatic Variant Identifier Migration" . PHP_EOL;
echo "=====================================================" . PHP_EOL;
echo "Backup created:" . PHP_EOL;
echo $backupPath . PHP_EOL . PHP_EOL;

try {
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');

    if (!variantIdentifierProductCodeColumnExists($pdo)) {
        throw new RuntimeException(
            'products.product_code is missing. Run migrate_product_codes.php first.'
        );
    }

    $variants = $pdo->query("
        SELECT
            pv.id,
            pv.product_id,
            pv.color,
            pv.size,
            pv.status,
            p.product_code,
            p.product_name
        FROM product_variants pv
        INNER JOIN products p
            ON p.id = pv.product_id
        ORDER BY pv.id ASC
    ")->fetchAll();

    $pdo->beginTransaction();

    /*
     * Temporary unique values prevent UNIQUE conflicts while every SKU and
     * barcode is being reassigned in the same transaction.
     */
    $pdo->exec("
        UPDATE product_variants
        SET
            sku = '__AUTO_SKU_' || id,
            barcode = '__AUTO_BARCODE_' || id
    ");

    $update = $pdo->prepare("
        UPDATE product_variants
        SET
            sku = ?,
            barcode = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");

    $results = [];

    foreach ($variants as $variant) {
        $variantId = (int) $variant['id'];
        $color = (string) $variant['color'];
        $size = (string) $variant['size'];
        $productCode = (string) $variant['product_code'];

        $isArchived =
            str_starts_with($color, 'Archived-')
            || strcasecmp($size, 'Archived') === 0;

        $sku = $isArchived
            ? 'ARCHIVED-' . $variantId
            : variantIdentifierSku(
                $productCode,
                $color,
                $size
            );

        $barcode =
            variantIdentifierBarcode(
                $variantId
            );

        $update->execute([
            $sku,
            $barcode,
            $variantId
        ]);

        $results[] = [
            'id' => $variantId,
            'product' => (string) $variant['product_name'],
            'color' => $color,
            'size' => $size,
            'sku' => $sku,
            'barcode' => $barcode
        ];
    }

    $pdo->commit();

    echo "Migration completed successfully." . PHP_EOL . PHP_EOL;

    if ($results === []) {
        echo "No variants found." . PHP_EOL;
    } else {
        echo "Current variant identifiers:" . PHP_EOL;

        foreach ($results as $result) {
            echo '- Variant #' . $result['id']
                . ' | ' . $result['product']
                . ' | ' . $result['color']
                . ' / ' . $result['size']
                . ' | SKU: ' . $result['sku']
                . ' | Barcode: ' . $result['barcode']
                . PHP_EOL;
        }
    }

    echo PHP_EOL;
    echo "Identifier rule:" . PHP_EOL;
    echo "- Product Code = parent/style identifier" . PHP_EOL;
    echo "- Variant SKU = generated from Product Code + Color + Size" . PHP_EOL;
    echo "- Variant Barcode = stable numeric sellable identifier" . PHP_EOL;
    echo PHP_EOL;
    echo "Historical sale-item identifiers were not changed." . PHP_EOL;

} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, PHP_EOL . "MIGRATION FAILED" . PHP_EOL);
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
