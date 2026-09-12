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

$pageTitle = 'Suppliers';
$currentPage = 'suppliers';


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
| HELPERS
|--------------------------------------------------------------------------
*/

function supplierRedirect(?int $manageSupplierId = null): never
{
    $location = '/suppliers/';

    if ($manageSupplierId !== null && $manageSupplierId > 0) {
        $location .= '?manage=' . $manageSupplierId;
    }

    header('Location: ' . $location);
    exit;
}


function supplierFlash(string $type, string $message): void
{
    $key = $type === 'success'
        ? 'supplier_success'
        : 'supplier_error';

    $_SESSION[$key] = $message;
}


function cleanOptionalValue(mixed $value): ?string
{
    $cleaned = trim((string) $value);

    return $cleaned === ''
        ? null
        : $cleaned;
}


function supplierValueTooLong(?string $value, int $maximumLength): bool
{
    return $value !== null
        && strlen($value) > $maximumLength;
}


function supplierDisplayDate(?string $utcDateTime): string
{
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

        return $date->format('M d, Y');
    } catch (Throwable $error) {
        return $value;
    }
}


function supplierProductImageUrl(int $productId): string
{
    if ($productId <= 0) {
        return '';
    }

    $directory =
        __DIR__
        . '/../assets/images/products';

    foreach (['jpg', 'jpeg', 'png', 'webp'] as $extension) {
        $filePath =
            $directory
            . DIRECTORY_SEPARATOR
            . 'product-'
            . $productId
            . '.'
            . $extension;

        if (is_file($filePath)) {
            return
                '/assets/images/products/product-'
                . $productId
                . '.'
                . $extension;
        }
    }

    return '';
}


function supplierExists(PDO $pdo, int $supplierId): bool
{
    $statement = $pdo->prepare("
        SELECT id
        FROM suppliers
        WHERE id = ?
        LIMIT 1
    ");

    $statement->execute([$supplierId]);

    return (bool) $statement->fetch();
}


function writeSupplierLog(
    PDO $pdo,
    string $action,
    string $recordType,
    int $recordId,
    string $details
): void {
    $statement = $pdo->prepare("
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

    $statement->execute([
        $_SESSION['user_id'] ?? null,
        $action,
        'Suppliers',
        $recordType,
        $recordId,
        $details
    ]);
}


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
        supplierFlash('error', 'Invalid request. Please try again.');
        supplierRedirect();
    }


    /* ADD OR UPDATE SUPPLIER */

    if (in_array($action, ['add_supplier', 'update_supplier'], true)) {
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        $supplierName = trim((string) ($_POST['supplier_name'] ?? ''));
        $contactPerson = cleanOptionalValue($_POST['contact_person'] ?? null);
        $phone = cleanOptionalValue($_POST['phone'] ?? null);
        $email = cleanOptionalValue($_POST['email'] ?? null);
        $address = cleanOptionalValue($_POST['address'] ?? null);
        $status = trim((string) ($_POST['status'] ?? 'Active'));

        if ($supplierName === '') {
            supplierFlash('error', 'Supplier name is required.');
            supplierRedirect();
        }

        if (strlen($supplierName) > 150) {
            supplierFlash('error', 'Supplier name must not exceed 150 characters.');
            supplierRedirect();
        }

        if (supplierValueTooLong($contactPerson, 150)) {
            supplierFlash('error', 'Contact person must not exceed 150 characters.');
            supplierRedirect();
        }

        if (supplierValueTooLong($phone, 50)) {
            supplierFlash('error', 'Phone number must not exceed 50 characters.');
            supplierRedirect();
        }

        if (supplierValueTooLong($email, 150)) {
            supplierFlash('error', 'Email address must not exceed 150 characters.');
            supplierRedirect();
        }

        if (supplierValueTooLong($address, 500)) {
            supplierFlash('error', 'Address must not exceed 500 characters.');
            supplierRedirect();
        }

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            supplierFlash('error', 'Please enter a valid email address.');
            supplierRedirect();
        }

        if (!in_array($status, ['Active', 'Inactive'], true)) {
            supplierFlash('error', 'Invalid supplier status.');
            supplierRedirect();
        }

        if ($action === 'update_supplier' && $supplierId <= 0) {
            supplierFlash('error', 'Invalid supplier.');
            supplierRedirect();
        }

        $duplicate = $pdo->prepare("
            SELECT id
            FROM suppliers
            WHERE LOWER(TRIM(supplier_name)) = LOWER(TRIM(?))
              AND id != ?
            LIMIT 1
        ");

        $duplicate->execute([
            $supplierName,
            $action === 'update_supplier' ? $supplierId : 0
        ]);

        if ($duplicate->fetch()) {
            supplierFlash('error', 'A supplier with that name already exists.');
            supplierRedirect();
        }

        try {
            $pdo->beginTransaction();

            if ($action === 'add_supplier') {
                $statement = $pdo->prepare("
                    INSERT INTO suppliers (
                        supplier_name,
                        contact_person,
                        phone,
                        email,
                        address,
                        status
                    )
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                $statement->execute([
                    $supplierName,
                    $contactPerson,
                    $phone,
                    $email,
                    $address,
                    $status
                ]);

                $supplierId = (int) $pdo->lastInsertId();

                writeSupplierLog(
                    $pdo,
                    'ADD_SUPPLIER',
                    'Supplier',
                    $supplierId,
                    'Added supplier ' . $supplierName
                );

                $successMessage = 'Supplier added successfully.';
            } else {
                if (!supplierExists($pdo, $supplierId)) {
                    throw new RuntimeException('Supplier not found.');
                }

                $statement = $pdo->prepare("
                    UPDATE suppliers
                    SET
                        supplier_name = ?,
                        contact_person = ?,
                        phone = ?,
                        email = ?,
                        address = ?,
                        status = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");

                $statement->execute([
                    $supplierName,
                    $contactPerson,
                    $phone,
                    $email,
                    $address,
                    $status,
                    $supplierId
                ]);

                writeSupplierLog(
                    $pdo,
                    'UPDATE_SUPPLIER',
                    'Supplier',
                    $supplierId,
                    'Updated supplier ' . $supplierName
                );

                $successMessage = 'Supplier updated successfully.';
            }

            $pdo->commit();
            supplierFlash('success', $successMessage);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            supplierFlash('error', 'Unable to save supplier: ' . $error->getMessage());
        }

        supplierRedirect();
    }


    /* ACTIVATE OR DEACTIVATE SUPPLIER */

    if ($action === 'toggle_supplier') {
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        $newStatus = trim((string) ($_POST['new_status'] ?? ''));

        if (
            $supplierId <= 0 ||
            !in_array($newStatus, ['Active', 'Inactive'], true)
        ) {
            supplierFlash('error', 'Invalid supplier status request.');
            supplierRedirect();
        }

        try {
            $pdo->beginTransaction();

            $statement = $pdo->prepare("
                SELECT supplier_name
                FROM suppliers
                WHERE id = ?
                LIMIT 1
            ");

            $statement->execute([$supplierId]);
            $supplier = $statement->fetch();

            if (!$supplier) {
                throw new RuntimeException('Supplier not found.');
            }

            $update = $pdo->prepare("
                UPDATE suppliers
                SET status = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");

            $update->execute([$newStatus, $supplierId]);

            if ($newStatus === 'Inactive') {
                $clearPrimaryLinks = $pdo->prepare("
                    UPDATE product_suppliers
                    SET is_primary = 0
                    WHERE supplier_id = ?
                      AND is_primary = 1
                ");

                $clearPrimaryLinks->execute([$supplierId]);
            }

            writeSupplierLog(
                $pdo,
                $newStatus === 'Active'
                    ? 'ACTIVATE_SUPPLIER'
                    : 'DEACTIVATE_SUPPLIER',
                'Supplier',
                $supplierId,
                $newStatus . ' supplier ' . $supplier['supplier_name']
            );

            $pdo->commit();
            supplierFlash('success', 'Supplier status updated successfully.');
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            supplierFlash('error', 'Unable to update supplier status: ' . $error->getMessage());
        }

        supplierRedirect();
    }


    /* LINK OR UPDATE A PRODUCT */

    if ($action === 'link_product') {
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        $productId = (int) ($_POST['product_id'] ?? 0);
        $supplierPriceInput = trim((string) ($_POST['supplier_price'] ?? ''));
        $supplierPrice = is_numeric($supplierPriceInput)
            ? round((float) $supplierPriceInput, 2)
            : -1;
        $isPrimary = isset($_POST['is_primary']) ? 1 : 0;

        if ($supplierId <= 0 || $productId <= 0) {
            supplierFlash('error', 'Please select a valid supplier and product.');
            supplierRedirect($supplierId);
        }

        if ($supplierPrice < 0) {
            supplierFlash('error', 'Supplier price must be zero or greater.');
            supplierRedirect($supplierId);
        }

        try {
            $pdo->beginTransaction();

            $supplierStatement = $pdo->prepare("
                SELECT
                    supplier_name,
                    status
                FROM suppliers
                WHERE id = ?
                LIMIT 1
            ");

            $supplierStatement->execute([$supplierId]);
            $supplier = $supplierStatement->fetch();

            $productStatement = $pdo->prepare("
                SELECT
                    product_name,
                    status
                FROM products
                WHERE id = ?
                LIMIT 1
            ");

            $productStatement->execute([$productId]);
            $product = $productStatement->fetch();

            if (!$supplier || !$product) {
                throw new RuntimeException('Supplier or product not found.');
            }

            $existing = $pdo->prepare("
                SELECT id
                FROM product_suppliers
                WHERE product_id = ?
                  AND supplier_id = ?
                LIMIT 1
            ");

            $existing->execute([
                $productId,
                $supplierId
            ]);

            $existingLink = $existing->fetch();

            if (
                !$existingLink
                && $supplier['status'] !== 'Active'
            ) {
                throw new RuntimeException(
                    'Activate this supplier before linking a new product.'
                );
            }

            if (
                !$existingLink
                && $product['status'] !== 'Active'
            ) {
                throw new RuntimeException(
                    'Inactive products cannot be linked to a new supplier.'
                );
            }

            if (
                $isPrimary === 1
                && $supplier['status'] !== 'Active'
            ) {
                throw new RuntimeException(
                    'An inactive supplier cannot be the primary supplier.'
                );
            }

            if ($isPrimary === 1) {
                $clearPrimary = $pdo->prepare("
                    UPDATE product_suppliers
                    SET is_primary = 0
                    WHERE product_id = ?
                ");

                $clearPrimary->execute([$productId]);
            }

            if ($existingLink) {
                $linkId = (int) $existingLink['id'];

                $saveLink = $pdo->prepare("
                    UPDATE product_suppliers
                    SET
                        supplier_price = ?,
                        is_primary = ?
                    WHERE id = ?
                ");

                $saveLink->execute([
                    $supplierPrice,
                    $isPrimary,
                    $linkId
                ]);

                $logAction = 'UPDATE_PRODUCT_SUPPLIER';
                $message = 'Product supplier details updated.';
            } else {
                $saveLink = $pdo->prepare("
                    INSERT INTO product_suppliers (
                        product_id,
                        supplier_id,
                        supplier_price,
                        is_primary
                    )
                    VALUES (?, ?, ?, ?)
                ");

                $saveLink->execute([
                    $productId,
                    $supplierId,
                    $supplierPrice,
                    $isPrimary
                ]);

                $linkId = (int) $pdo->lastInsertId();

                $logAction = 'LINK_PRODUCT_SUPPLIER';
                $message = 'Product linked to supplier successfully.';
            }

            writeSupplierLog(
                $pdo,
                $logAction,
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
            );

            $pdo->commit();
            supplierFlash('success', $message);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            supplierFlash(
                'error',
                'Unable to save product supplier: '
                    . $error->getMessage()
            );
        }

        supplierRedirect($supplierId);
    }


    /* UNLINK A PRODUCT */

    if ($action === 'unlink_product') {
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        $productId = (int) ($_POST['product_id'] ?? 0);

        if ($supplierId <= 0 || $productId <= 0) {
            supplierFlash('error', 'Invalid product link.');
            supplierRedirect($supplierId);
        }

        try {
            $pdo->beginTransaction();

            $details = $pdo->prepare("
                SELECT
                    ps.id,
                    ps.is_primary,
                    s.supplier_name,
                    p.product_name
                FROM product_suppliers ps
                INNER JOIN suppliers s
                    ON s.id = ps.supplier_id
                INNER JOIN products p
                    ON p.id = ps.product_id
                WHERE ps.supplier_id = ?
                  AND ps.product_id = ?
                LIMIT 1
            ");

            $details->execute([
                $supplierId,
                $productId
            ]);

            $linkedItem = $details->fetch();

            if (!$linkedItem) {
                throw new RuntimeException('Product link not found.');
            }

            $linkId = (int) $linkedItem['id'];

            $delete = $pdo->prepare("
                DELETE FROM product_suppliers
                WHERE id = ?
            ");

            $delete->execute([$linkId]);

            writeSupplierLog(
                $pdo,
                'UNLINK_PRODUCT_SUPPLIER',
                'Product Supplier',
                $linkId,
                'Unlinked '
                    . $linkedItem['product_name']
                    . ' from '
                    . $linkedItem['supplier_name']
                    . ((int) $linkedItem['is_primary'] === 1
                        ? ' (primary supplier link removed)'
                        : '')
            );

            $pdo->commit();

            supplierFlash(
                'success',
                'Product removed from supplier successfully.'
            );
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            supplierFlash(
                'error',
                'Unable to unlink product: '
                    . $error->getMessage()
            );
        }

        supplierRedirect($supplierId);
    }

    supplierFlash('error', 'Unknown supplier action.');
    supplierRedirect();
}


/*
|--------------------------------------------------------------------------
| FLASH MESSAGES
|--------------------------------------------------------------------------
*/

$supplierSuccess = $_SESSION['supplier_success'] ?? '';
$supplierError = $_SESSION['supplier_error'] ?? '';

unset(
    $_SESSION['supplier_success'],
    $_SESSION['supplier_error']
);


/*
|--------------------------------------------------------------------------
| SUPPLIERS AND STATISTICS
|--------------------------------------------------------------------------
*/

$supplierStatement = $pdo->query("
    SELECT
        s.id,
        s.supplier_name,
        s.contact_person,
        s.phone,
        s.email,
        s.address,
        s.status,
        s.created_at,
        s.updated_at,
        COUNT(ps.id) AS linked_product_count,
        COALESCE(SUM(CASE WHEN ps.is_primary = 1 THEN 1 ELSE 0 END), 0) AS primary_product_count
    FROM suppliers s
    LEFT JOIN product_suppliers ps
        ON ps.supplier_id = s.id
    GROUP BY s.id
    ORDER BY s.supplier_name ASC
");

$suppliers = $supplierStatement->fetchAll();
$totalSuppliers = count($suppliers);
$activeSuppliers = 0;
$linkedSuppliers = 0;
$unlinkedSuppliers = 0;

foreach ($suppliers as $supplier) {
    if ($supplier['status'] === 'Active') {
        $activeSuppliers++;
    }

    if ((int) $supplier['linked_product_count'] > 0) {
        $linkedSuppliers++;
    } else {
        $unlinkedSuppliers++;
    }
}

$productStatement = $pdo->query("
    SELECT
        id,
        product_code,
        product_name,
        cost_price,
        status
    FROM products
    ORDER BY product_name ASC
");

$products = $productStatement->fetchAll();

$supplierEditData = [];

foreach ($suppliers as $supplier) {
    $supplierEditData[(int) $supplier['id']] = [
        'id' => (int) $supplier['id'],
        'supplier_name' => (string) $supplier['supplier_name'],
        'contact_person' => (string) ($supplier['contact_person'] ?? ''),
        'phone' => (string) ($supplier['phone'] ?? ''),
        'email' => (string) ($supplier['email'] ?? ''),
        'address' => (string) ($supplier['address'] ?? ''),
        'status' => (string) $supplier['status']
    ];
}


/*
|--------------------------------------------------------------------------
| PRODUCT MANAGEMENT MODAL DATA
|--------------------------------------------------------------------------
*/

$manageSupplierId = (int) ($_GET['manage'] ?? 0);
$managedSupplier = null;
$managedProducts = [];
$availableProducts = [];

if ($manageSupplierId > 0) {
    $managedSupplierStatement = $pdo->prepare("
        SELECT id, supplier_name, status
        FROM suppliers
        WHERE id = ?
        LIMIT 1
    ");
    $managedSupplierStatement->execute([$manageSupplierId]);
    $managedSupplier = $managedSupplierStatement->fetch();

    if ($managedSupplier) {
        $managedProductStatement = $pdo->prepare("
            SELECT
                ps.product_id,
                ps.supplier_price,
                ps.is_primary,
                p.product_name,
                p.product_code,
                p.status
            FROM product_suppliers ps
            INNER JOIN products p
                ON p.id = ps.product_id
            WHERE ps.supplier_id = ?
            ORDER BY p.product_name ASC
        ");
        $managedProductStatement->execute([$manageSupplierId]);
        $managedProducts = $managedProductStatement->fetchAll();

        foreach ($managedProducts as &$managedProduct) {
            $managedProduct['image_url'] =
                supplierProductImageUrl(
                    (int) $managedProduct['product_id']
                );
        }

        unset($managedProduct);

        $availableProductStatement = $pdo->prepare("
            SELECT
                p.id,
                p.product_name,
                p.product_code,
                p.cost_price,
                p.status
            FROM products p
            WHERE p.status = 'Active'
              AND NOT EXISTS (
                SELECT 1
                FROM product_suppliers ps
                WHERE ps.product_id = p.id
                  AND ps.supplier_id = ?
            )
            ORDER BY p.product_name ASC
        ");
        $availableProductStatement->execute([$manageSupplierId]);
        $availableProducts = $availableProductStatement->fetchAll();
    }
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

<link rel="stylesheet" href="/assets/css/suppliers.css">

<div class="suppliers-page">

    <div class="suppliers-top">
        <div>
            <div class="suppliers-eyebrow">SUPPLY CHAIN</div>
            <h2>Supplier Management</h2>
            <p>Manage supplier records, product links and purchasing prices.</p>
        </div>

        <button type="button" class="suppliers-primary-button" id="openAddSupplier">
            <span class="material-symbols-rounded">add</span>
            Add Supplier
        </button>
    </div>

    <?php if ($supplierSuccess !== ''): ?>
        <div class="suppliers-alert success">
            <span class="material-symbols-rounded">check_circle</span>
            <?= htmlspecialchars($supplierSuccess) ?>
        </div>
    <?php endif; ?>

    <?php if ($supplierError !== ''): ?>
        <div class="suppliers-alert error">
            <span class="material-symbols-rounded">error</span>
            <?= htmlspecialchars($supplierError) ?>
        </div>
    <?php endif; ?>

    <div class="suppliers-stats">
        <div class="suppliers-stat-card">
            <div class="supplier-stat-icon">
                <span class="material-symbols-rounded">local_shipping</span>
            </div>
            <div>
                <span>Total Suppliers</span>
                <strong><?= $totalSuppliers ?></strong>
            </div>
        </div>

        <div class="suppliers-stat-card">
            <div class="supplier-stat-icon">
                <span class="material-symbols-rounded">verified</span>
            </div>
            <div>
                <span>Active Suppliers</span>
                <strong><?= $activeSuppliers ?></strong>
            </div>
        </div>

        <div class="suppliers-stat-card">
            <div class="supplier-stat-icon">
                <span class="material-symbols-rounded">link</span>
            </div>
            <div>
                <span>With Products</span>
                <strong><?= $linkedSuppliers ?></strong>
            </div>
        </div>

        <div class="suppliers-stat-card">
            <div class="supplier-stat-icon">
                <span class="material-symbols-rounded">link_off</span>
            </div>
            <div>
                <span>Without Products</span>
                <strong><?= $unlinkedSuppliers ?></strong>
            </div>
        </div>
    </div>

    <div class="suppliers-card">
        <div class="suppliers-card-header">
            <div>
                <h3>Supplier Directory</h3>
                <p>
                    <span id="supplierVisibleCount"><?= $totalSuppliers ?></span>
                    <span id="supplierCountLabel"><?= $totalSuppliers === 1 ? 'supplier' : 'suppliers' ?></span>
                </p>
            </div>

            <div class="suppliers-toolbar">
                <select id="supplierStatusFilter" class="suppliers-filter-select" aria-label="Filter suppliers by status">
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>

                <div class="suppliers-search">
                    <span class="material-symbols-rounded">search</span>
                    <input
                        type="text"
                        id="supplierSearch"
                        placeholder="Search supplier or contact..."
                        autocomplete="off"
                    >
                </div>
            </div>
        </div>

        <div class="suppliers-table-wrap">
            <table class="suppliers-table">
                <thead>
                    <tr>
                        <th>Supplier</th>
                        <th>Contact Person</th>
                        <th>Contact Details</th>
                        <th>Products</th>
                        <th>Status</th>
                        <th>Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody id="supplierTableBody">
                    <?php if (empty($suppliers)): ?>
                        <tr id="initialSupplierEmpty">
                            <td colspan="7" class="suppliers-empty-cell">
                                <div class="suppliers-empty">
                                    <span class="material-symbols-rounded">local_shipping</span>
                                    <strong>No suppliers yet</strong>
                                    <p>Add your first supplier to connect products.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($suppliers as $supplier): ?>
                            <?php
                            $supplierId = (int) $supplier['id'];
                            $searchText = strtolower(implode(' ', [
                                $supplier['supplier_name'],
                                $supplier['contact_person'] ?? '',
                                $supplier['phone'] ?? '',
                                $supplier['email'] ?? '',
                                $supplier['address'] ?? ''
                            ]));
                            ?>
                            <tr
                                class="supplier-row"
                                data-search="<?= htmlspecialchars($searchText) ?>"
                                data-status="<?= htmlspecialchars(strtolower($supplier['status'])) ?>"
                            >
                                <td>
                                    <div class="supplier-identity">
                                        <div class="supplier-avatar">
                                            <?= htmlspecialchars(strtoupper(substr($supplier['supplier_name'], 0, 1))) ?>
                                        </div>
                                        <div>
                                            <strong><?= htmlspecialchars($supplier['supplier_name']) ?></strong>
                                            <small>#SUP-<?= str_pad((string) $supplierId, 4, '0', STR_PAD_LEFT) ?></small>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <span class="supplier-contact-name">
                                        <?= htmlspecialchars($supplier['contact_person'] ?: 'Not specified') ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="supplier-contact-details">
                                        <?php if (!empty($supplier['phone'])): ?>
                                            <span>
                                                <span class="material-symbols-rounded">call</span>
                                                <?= htmlspecialchars($supplier['phone']) ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if (!empty($supplier['email'])): ?>
                                            <span>
                                                <span class="material-symbols-rounded">mail</span>
                                                <?= htmlspecialchars($supplier['email']) ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if (empty($supplier['phone']) && empty($supplier['email'])): ?>
                                            <span class="supplier-muted">No contact details</span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td>
                                    <a class="supplier-product-summary" href="/suppliers/?manage=<?= $supplierId ?>">
                                        <strong><?= (int) $supplier['linked_product_count'] ?></strong>
                                        <span><?= (int) $supplier['linked_product_count'] === 1 ? 'product' : 'products' ?></span>
                                        <?php if ((int) $supplier['primary_product_count'] > 0): ?>
                                            <small><?= (int) $supplier['primary_product_count'] ?> primary</small>
                                        <?php endif; ?>
                                    </a>
                                </td>

                                <td>
                                    <span class="supplier-status <?= strtolower($supplier['status']) ?>">
                                        <?= htmlspecialchars($supplier['status']) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="supplier-date">
                                        <?= htmlspecialchars(supplierDisplayDate($supplier['updated_at'])) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="supplier-actions">
                                        <button
                                            type="button"
                                            class="supplier-icon-button edit-supplier-button"
                                            data-supplier-id="<?= $supplierId ?>"
                                            title="Edit supplier"
                                            aria-label="Edit <?= htmlspecialchars($supplier['supplier_name']) ?>"
                                        >
                                            <span class="material-symbols-rounded">edit</span>
                                        </button>

                                        <a
                                            class="supplier-icon-button"
                                            href="/suppliers/?manage=<?= $supplierId ?>"
                                            title="Manage products"
                                            aria-label="Manage products for <?= htmlspecialchars($supplier['supplier_name']) ?>"
                                        >
                                            <span class="material-symbols-rounded">inventory_2</span>
                                        </a>

                                        <form method="post" class="supplier-toggle-form">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="toggle_supplier">
                                            <input type="hidden" name="supplier_id" value="<?= $supplierId ?>">
                                            <input
                                                type="hidden"
                                                name="new_status"
                                                value="<?= $supplier['status'] === 'Active' ? 'Inactive' : 'Active' ?>"
                                            >
                                            <button
                                                type="submit"
                                                class="supplier-icon-button"
                                                title="<?= $supplier['status'] === 'Active' ? 'Deactivate' : 'Activate' ?> supplier"
                                                data-confirm="<?= $supplier['status'] === 'Active'
                                                    ? 'Deactivate this supplier? Existing product links will be preserved.'
                                                    : 'Activate this supplier?' ?>"
                                            >
                                                <span class="material-symbols-rounded">
                                                    <?= $supplier['status'] === 'Active' ? 'visibility_off' : 'visibility' ?>
                                                </span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <tr id="filteredSupplierEmpty" hidden>
                        <td colspan="7" class="suppliers-empty-cell">
                            <div class="suppliers-empty compact">
                                <span class="material-symbols-rounded">search_off</span>
                                <strong>No matching suppliers</strong>
                                <p>Try another search or status filter.</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- ADD / EDIT SUPPLIER MODAL -->

<div class="supplier-modal" id="supplierFormModal" hidden>
    <button type="button" class="supplier-modal-backdrop" data-close-supplier-modal aria-label="Close supplier form"></button>

    <div class="supplier-modal-card" role="dialog" aria-modal="true" aria-labelledby="supplierFormTitle">
        <div class="supplier-modal-header">
            <div>
                <h3 id="supplierFormTitle">Add Supplier</h3>
                <p id="supplierFormDescription">Create a supplier record for purchasing and restocking.</p>
            </div>

            <button type="button" class="supplier-modal-close" data-close-supplier-modal aria-label="Close">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>

        <form method="post" class="supplier-form" id="supplierForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" id="supplierFormAction" value="add_supplier">
            <input type="hidden" name="supplier_id" id="supplierId" value="">

            <div class="supplier-form-grid">
                <div class="supplier-field full">
                    <label for="supplierName">Supplier Name <span>*</span></label>
                    <input type="text" id="supplierName" name="supplier_name" maxlength="150" required autocomplete="organization">
                </div>

                <div class="supplier-field">
                    <label for="contactPerson">Contact Person</label>
                    <input type="text" id="contactPerson" name="contact_person" maxlength="150" autocomplete="name">
                </div>

                <div class="supplier-field">
                    <label for="supplierPhone">Phone Number</label>
                    <input type="tel" id="supplierPhone" name="phone" maxlength="50" autocomplete="tel">
                </div>

                <div class="supplier-field">
                    <label for="supplierEmail">Email Address</label>
                    <input type="email" id="supplierEmail" name="email" maxlength="150" autocomplete="email">
                </div>

                <div class="supplier-field">
                    <label for="supplierStatus">Status <span>*</span></label>
                    <select id="supplierStatus" name="status" required>
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>

                <div class="supplier-field full">
                    <label for="supplierAddress">Address</label>
                    <textarea id="supplierAddress" name="address" rows="4" maxlength="500" autocomplete="street-address"></textarea>
                </div>
            </div>

            <div class="supplier-modal-footer">
                <button type="button" class="suppliers-secondary-button" data-close-supplier-modal>Cancel</button>
                <button type="submit" class="suppliers-primary-button" id="supplierSubmitButton">
                    <span class="material-symbols-rounded">save</span>
                    <span id="supplierSubmitButtonText">Save Supplier</span>
                </button>
            </div>
        </form>
    </div>
</div>


<!-- MANAGE PRODUCTS MODAL -->

<?php if ($managedSupplier): ?>
    <div class="supplier-modal" id="supplierProductsModal">
        <a class="supplier-modal-backdrop" href="/suppliers/" aria-label="Close product management"></a>

        <div class="supplier-modal-card supplier-products-modal-card" role="dialog" aria-modal="true" aria-labelledby="supplierProductsTitle">
            <div class="supplier-modal-header">
                <div>
                    <div class="supplier-modal-eyebrow">PRODUCT CONNECTIONS</div>
                    <h3 id="supplierProductsTitle"><?= htmlspecialchars($managedSupplier['supplier_name']) ?></h3>
                    <p>Set supplier prices and choose the primary supplier for each product.</p>
                </div>

                <a class="supplier-modal-close" href="/suppliers/" aria-label="Close">
                    <span class="material-symbols-rounded">close</span>
                </a>
            </div>

            <div class="supplier-products-content">
                <form method="post" class="supplier-link-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="link_product">
                    <input type="hidden" name="supplier_id" value="<?= (int) $managedSupplier['id'] ?>">

                    <div class="supplier-section-heading">
                        <div>
                            <h4>Link a Product</h4>
                            <p>Add an inventory product to this supplier.</p>
                        </div>
                    </div>

                    <?php if ($managedSupplier['status'] !== 'Active'): ?>
                        <div class="supplier-inline-empty warning">
                            <span class="material-symbols-rounded">info</span>
                            <span>Activate this supplier before linking new products. Existing links can still be reviewed and updated below.</span>
                        </div>
                    <?php elseif (empty($availableProducts)): ?>
                        <div class="supplier-inline-empty">
                            <span class="material-symbols-rounded">inventory_2</span>
                            <span><?= empty($products) ? 'Add active inventory products first.' : 'All active products are already linked.' ?></span>
                        </div>
                    <?php else: ?>
                        <div class="supplier-link-grid">
                            <div class="supplier-field">
                                <label for="linkProductId">Product <span>*</span></label>
                                <select id="linkProductId" name="product_id" required>
                                    <option value="">Select a product</option>
                                    <?php foreach ($availableProducts as $product): ?>
                                        <option
                                            value="<?= (int) $product['id'] ?>"
                                            data-cost-price="<?= htmlspecialchars(number_format((float) $product['cost_price'], 2, '.', '')) ?>"
                                        >
                                            <?= htmlspecialchars($product['product_name']) ?>
                                            — <?= htmlspecialchars($product['product_code']) ?>
                                            <?= $product['status'] === 'Inactive' ? ' (Inactive)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="supplier-field">
                                <label for="linkSupplierPrice">Supplier Price <span>*</span></label>
                                <div class="supplier-money-input">
                                    <span>₱</span>
                                    <input type="number" id="linkSupplierPrice" name="supplier_price" min="0" step="0.01" required>
                                </div>
                            </div>

                            <label class="supplier-checkbox supplier-link-primary">
                                <input type="checkbox" name="is_primary" value="1">
                                <span>
                                    <strong>Primary supplier</strong>
                                    <small>Use this supplier as the preferred source.</small>
                                </span>
                            </label>

                            <button type="submit" class="suppliers-primary-button supplier-link-button">
                                <span class="material-symbols-rounded">add_link</span>
                                Link Product
                            </button>
                        </div>
                    <?php endif; ?>
                </form>

                <div class="supplier-linked-products">
                    <div class="supplier-section-heading">
                        <div>
                            <h4>Linked Products</h4>
                            <p><?= count($managedProducts) ?> <?= count($managedProducts) === 1 ? 'product' : 'products' ?> connected.</p>
                        </div>
                    </div>

                    <?php if (empty($managedProducts)): ?>
                        <div class="supplier-inline-empty large">
                            <span class="material-symbols-rounded">link_off</span>
                            <div>
                                <strong>No linked products</strong>
                                <span>Use the form above to connect an inventory product.</span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="supplier-product-list">
                            <?php foreach ($managedProducts as $index => $linkedProduct): ?>
                                <?php $linkFormId = 'linkedProductForm' . $index; ?>
                                <div class="supplier-product-item">
                                    <div class="supplier-product-info">
                                        <div class="supplier-product-icon">
                                            <?php if (!empty($linkedProduct['image_url'])): ?>
                                                <img
                                                    src="<?= htmlspecialchars($linkedProduct['image_url']) ?>"
                                                    alt="<?= htmlspecialchars($linkedProduct['product_name']) ?>"
                                                >
                                            <?php else: ?>
                                                <span class="material-symbols-rounded">inventory_2</span>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="supplier-product-name-line">
                                                <strong><?= htmlspecialchars($linkedProduct['product_name']) ?></strong>
                                                <?php if ((int) $linkedProduct['is_primary'] === 1): ?>
                                                    <span class="supplier-primary-badge">Primary</span>
                                                <?php endif; ?>
                                                <?php if ($linkedProduct['status'] === 'Inactive'): ?>
                                                    <span class="supplier-inactive-badge">Inactive</span>
                                                <?php endif; ?>
                                            </div>
                                            <small><?= htmlspecialchars($linkedProduct['product_code']) ?></small>
                                        </div>
                                    </div>

                                    <form method="post" id="<?= $linkFormId ?>" class="supplier-product-update-form">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="link_product">
                                        <input type="hidden" name="supplier_id" value="<?= (int) $managedSupplier['id'] ?>">
                                        <input type="hidden" name="product_id" value="<?= (int) $linkedProduct['product_id'] ?>">

                                        <div class="supplier-money-input compact">
                                            <span>₱</span>
                                            <input
                                                type="number"
                                                name="supplier_price"
                                                min="0"
                                                step="0.01"
                                                value="<?= htmlspecialchars(number_format((float) $linkedProduct['supplier_price'], 2, '.', '')) ?>"
                                                aria-label="Supplier price for <?= htmlspecialchars($linkedProduct['product_name']) ?>"
                                                required
                                            >
                                        </div>

                                        <label class="supplier-primary-check" title="Set as primary supplier">
                                            <input
                                                type="checkbox"
                                                name="is_primary"
                                                value="1"
                                                <?= (int) $linkedProduct['is_primary'] === 1 ? 'checked' : '' ?>
                                            >
                                            <span class="material-symbols-rounded">star</span>
                                        </label>

                                        <button type="submit" class="supplier-icon-button" title="Save product link">
                                            <span class="material-symbols-rounded">save</span>
                                        </button>
                                    </form>

                                    <form method="post" class="supplier-unlink-form">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="unlink_product">
                                        <input type="hidden" name="supplier_id" value="<?= (int) $managedSupplier['id'] ?>">
                                        <input type="hidden" name="product_id" value="<?= (int) $linkedProduct['product_id'] ?>">
                                        <button
                                            type="submit"
                                            class="supplier-icon-button danger"
                                            title="Unlink product"
                                            data-confirm="Remove this product from the supplier?"
                                        >
                                            <span class="material-symbols-rounded">link_off</span>
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
const supplierEditData = <?= json_encode(
    $supplierEditData,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;

const supplierFormModal = document.getElementById('supplierFormModal');
const supplierForm = document.getElementById('supplierForm');
const supplierFormTitle = document.getElementById('supplierFormTitle');
const supplierFormDescription = document.getElementById('supplierFormDescription');
const supplierFormAction = document.getElementById('supplierFormAction');
const supplierSubmitButton = document.getElementById('supplierSubmitButton');
const supplierSubmitButtonText = document.getElementById('supplierSubmitButtonText');
const firstSupplierField = document.getElementById('supplierName');

function openSupplierModal(mode, supplier = null) {
    supplierForm.reset();
    supplierSubmitButton.disabled = false;

    if (mode === 'edit' && supplier) {
        supplierFormTitle.textContent = 'Edit Supplier';
        supplierFormDescription.textContent = 'Update supplier contact information and account status.';
        supplierFormAction.value = 'update_supplier';
        document.getElementById('supplierId').value = supplier.id;
        document.getElementById('supplierName').value = supplier.supplier_name;
        document.getElementById('contactPerson').value = supplier.contact_person;
        document.getElementById('supplierPhone').value = supplier.phone;
        document.getElementById('supplierEmail').value = supplier.email;
        document.getElementById('supplierAddress').value = supplier.address;
        document.getElementById('supplierStatus').value = supplier.status;
        supplierSubmitButtonText.textContent = 'Update Supplier';
    } else {
        supplierFormTitle.textContent = 'Add Supplier';
        supplierFormDescription.textContent = 'Create a supplier record for purchasing and restocking.';
        supplierFormAction.value = 'add_supplier';
        document.getElementById('supplierId').value = '';
        document.getElementById('supplierStatus').value = 'Active';
        supplierSubmitButtonText.textContent = 'Save Supplier';
    }

    supplierFormModal.hidden = false;
    document.body.classList.add('supplier-modal-open');

    window.setTimeout(() => firstSupplierField.focus(), 50);
}

function closeSupplierModal() {
    supplierFormModal.hidden = true;
    document.body.classList.remove('supplier-modal-open');
}

supplierForm.addEventListener('submit', () => {
    supplierSubmitButton.disabled = true;

    supplierSubmitButtonText.textContent =
        supplierFormAction.value === 'update_supplier'
            ? 'Updating...'
            : 'Saving...';
});

document.getElementById('openAddSupplier').addEventListener('click', () => {
    openSupplierModal('add');
});

document.querySelectorAll('.edit-supplier-button').forEach((button) => {
    button.addEventListener('click', () => {
        const supplier = supplierEditData[button.dataset.supplierId];

        if (supplier) {
            openSupplierModal('edit', supplier);
        }
    });
});

document.querySelectorAll('[data-close-supplier-modal]').forEach((button) => {
    button.addEventListener('click', closeSupplierModal);
});

document.querySelectorAll('[data-confirm]').forEach((button) => {
    button.addEventListener('click', (event) => {
        if (!window.confirm(button.dataset.confirm)) {
            event.preventDefault();
        }
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
        return;
    }

    if (!supplierFormModal.hidden) {
        closeSupplierModal();
        return;
    }

    if (document.getElementById('supplierProductsModal')) {
        window.location.href = '/suppliers/';
    }
});

const supplierSearch = document.getElementById('supplierSearch');
const supplierStatusFilter = document.getElementById('supplierStatusFilter');
const supplierRows = Array.from(document.querySelectorAll('.supplier-row'));
const filteredSupplierEmpty = document.getElementById('filteredSupplierEmpty');
const supplierVisibleCount = document.getElementById('supplierVisibleCount');
const supplierCountLabel = document.getElementById('supplierCountLabel');

function filterSuppliers() {
    const query = supplierSearch.value.trim().toLowerCase();
    const status = supplierStatusFilter.value;
    let visible = 0;

    supplierRows.forEach((row) => {
        const matchesQuery = query === '' || row.dataset.search.includes(query);
        const matchesStatus = status === '' || row.dataset.status === status;
        const show = matchesQuery && matchesStatus;

        row.hidden = !show;

        if (show) {
            visible++;
        }
    });

    filteredSupplierEmpty.hidden = supplierRows.length === 0 || visible > 0;
    supplierVisibleCount.textContent = visible;
    supplierCountLabel.textContent = visible === 1 ? 'supplier' : 'suppliers';
}

supplierSearch.addEventListener('input', filterSuppliers);
supplierStatusFilter.addEventListener('change', filterSuppliers);

const linkProductId = document.getElementById('linkProductId');
const linkSupplierPrice = document.getElementById('linkSupplierPrice');

if (linkProductId && linkSupplierPrice) {
    linkProductId.addEventListener('change', () => {
        const selectedOption = linkProductId.options[linkProductId.selectedIndex];

        if (selectedOption && selectedOption.dataset.costPrice) {
            linkSupplierPrice.value = selectedOption.dataset.costPrice;
        }
    });
}

if (document.getElementById('supplierProductsModal')) {
    document.body.classList.add('supplier-modal-open');
}
</script>

<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';

?>
