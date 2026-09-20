<?php

require_once __DIR__ . '/../../app/middleware/role.php';
requireRole(['Admin']);

require_once __DIR__ . '/../../app/config/database.php';

$pageTitle = 'System Logs';
$currentPage = 'logs';

function logsDisplayDateTime(?string $utcDateTime): string
{
    $value = trim((string) $utcDateTime);

    if ($value === '') {
        return '—';
    }

    try {
        $date = new DateTime($value, new DateTimeZone('UTC'));
        $date->setTimezone(new DateTimeZone('Asia/Manila'));
        return $date->format('M d, Y · g:i A');
    } catch (Throwable $error) {
        return $value;
    }
}

function logsActionLabel(string $action): string
{
    return ucwords(str_replace('_', ' ', strtolower(trim($action))));
}

function logsActorName(array $row): string
{
    $parts = [];

    foreach (['first_name', 'middle_name', 'last_name', 'suffix'] as $field) {
        $value = trim((string) ($row[$field] ?? ''));

        if ($value !== '') {
            $parts[] = $value;
        }
    }

    if (!empty($parts)) {
        return implode(' ', $parts);
    }

    $username = trim((string) ($row['username'] ?? ''));

    return $username !== ''
        ? $username
        : 'System / Deleted User';
}

function logsInitials(array $row): string
{
    $firstName = trim((string) ($row['first_name'] ?? ''));
    $lastName = trim((string) ($row['last_name'] ?? ''));

    $initials = '';

    if ($firstName !== '') {
        $initials .= strtoupper(substr($firstName, 0, 1));
    }

    if ($lastName !== '') {
        $initials .= strtoupper(substr($lastName, 0, 1));
    }

    return $initials !== '' ? $initials : 'SY';
}

function logsModuleIcon(string $module): string
{
    return match (strtolower(trim($module))) {
        'accounts' => 'manage_accounts',
        'inventory' => 'inventory_2',
        'suppliers' => 'local_shipping',
        'payroll' => 'payments',
        'reports' => 'bar_chart',
        'settings' => 'settings',
        'pos' => 'point_of_sale',
        'sales' => 'receipt_long',
        default => 'history'
    };
}

$search = trim((string) ($_GET['search'] ?? ''));
$moduleFilter = trim((string) ($_GET['module'] ?? ''));
$actionFilter = trim((string) ($_GET['action'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$moduleOptions = $pdo->query("
    SELECT DISTINCT module
    FROM system_logs
    WHERE module IS NOT NULL
      AND TRIM(module) <> ''
    ORDER BY module ASC
")->fetchAll(PDO::FETCH_COLUMN);

$actionOptions = $pdo->query("
    SELECT DISTINCT action
    FROM system_logs
    WHERE action IS NOT NULL
      AND TRIM(action) <> ''
    ORDER BY action ASC
")->fetchAll(PDO::FETCH_COLUMN);

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "
        (
            sl.action LIKE ?
            OR sl.module LIKE ?
            OR sl.record_type LIKE ?
            OR CAST(sl.record_id AS TEXT) LIKE ?
            OR sl.details LIKE ?
            OR u.username LIKE ?
            OR u.first_name LIKE ?
            OR u.middle_name LIKE ?
            OR u.last_name LIKE ?
            OR u.suffix LIKE ?
        )
    ";

    $searchValue = '%' . $search . '%';

    for ($index = 0; $index < 10; $index++) {
        $params[] = $searchValue;
    }
}

if ($moduleFilter !== '') {
    $where[] = 'sl.module = ?';
    $params[] = $moduleFilter;
}

if ($actionFilter !== '') {
    $where[] = 'sl.action = ?';
    $params[] = $actionFilter;
}

if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = "date(sl.created_at, '+8 hours') >= ?";
    $params[] = $dateFrom;
}

if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = "date(sl.created_at, '+8 hours') <= ?";
    $params[] = $dateTo;
}

$whereSql = !empty($where)
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

$totalLogs = (int) $pdo->query("
    SELECT COUNT(*)
    FROM system_logs
")->fetchColumn();

$todayLogs = (int) $pdo->query("
    SELECT COUNT(*)
    FROM system_logs
    WHERE date(created_at, '+8 hours') = date('now', '+8 hours')
")->fetchColumn();

$activeModules = (int) $pdo->query("
    SELECT COUNT(DISTINCT module)
    FROM system_logs
    WHERE module IS NOT NULL
      AND TRIM(module) <> ''
")->fetchColumn();

$activeActors = (int) $pdo->query("
    SELECT COUNT(DISTINCT user_id)
    FROM system_logs
    WHERE user_id IS NOT NULL
")->fetchColumn();

$countStatement = $pdo->prepare("
    SELECT COUNT(*)
    FROM system_logs sl
    LEFT JOIN users u
        ON u.id = sl.user_id
    $whereSql
");

$countStatement->execute($params);

$filteredTotal = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($filteredTotal / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$logsStatement = $pdo->prepare("
    SELECT
        sl.id,
        sl.user_id,
        sl.action,
        sl.module,
        sl.record_type,
        sl.record_id,
        sl.details,
        sl.created_at,

        u.username,
        u.first_name,
        u.middle_name,
        u.last_name,
        u.suffix,
        u.role

    FROM system_logs sl

    LEFT JOIN users u
        ON u.id = sl.user_id

    $whereSql

    ORDER BY
        sl.created_at DESC,
        sl.id DESC

    LIMIT ?
    OFFSET ?
");

$executeParams = $params;
$executeParams[] = $perPage;
$executeParams[] = $offset;

$logsStatement->execute($executeParams);
$logs = $logsStatement->fetchAll();

function logsPageUrl(int $page): string
{
    $query = $_GET;
    $query['page'] = $page;

    return '/logs/?' . http_build_query($query);
}

require_once __DIR__ . '/../../app/views/partials/header.php';
require_once __DIR__ . '/../../app/views/partials/sidebar.php';

?>

<link
    rel="stylesheet"
    href="/assets/css/logs.css?v=20260920"
>

<div class="logs-page">

    <div class="logs-top">

        <div>

            <div class="logs-eyebrow">
                AUDIT TRAIL
            </div>

            <h2>
                System Logs
            </h2>

            <p>
                Review recorded activity across UA POS.
                Logs are read-only and retained for accountability.
            </p>

        </div>

        <div class="logs-readonly-badge">

            <span class="material-symbols-rounded">
                lock
            </span>

            Read Only

        </div>

    </div>


    <div class="logs-stats">

        <div class="logs-stat-card">

            <div class="logs-stat-icon">
                <span class="material-symbols-rounded">history</span>
            </div>

            <div>
                <span>Total Events</span>
                <strong><?= $totalLogs ?></strong>
            </div>

        </div>


        <div class="logs-stat-card">

            <div class="logs-stat-icon">
                <span class="material-symbols-rounded">today</span>
            </div>

            <div>
                <span>Activity Today</span>
                <strong><?= $todayLogs ?></strong>
            </div>

        </div>


        <div class="logs-stat-card">

            <div class="logs-stat-icon">
                <span class="material-symbols-rounded">category</span>
            </div>

            <div>
                <span>Logged Modules</span>
                <strong><?= $activeModules ?></strong>
            </div>

        </div>


        <div class="logs-stat-card">

            <div class="logs-stat-icon">
                <span class="material-symbols-rounded">group</span>
            </div>

            <div>
                <span>Recorded Users</span>
                <strong><?= $activeActors ?></strong>
            </div>

        </div>

    </div>


    <div class="logs-card">

        <div class="logs-card-header">

            <div>

                <h3>
                    Activity History
                </h3>

                <p>
                    <?= $filteredTotal ?>
                    <?= $filteredTotal === 1 ? 'event' : 'events' ?>
                    matching the current view
                </p>

            </div>

            <div class="logs-result-range">

                <?php if ($filteredTotal > 0): ?>

                    Showing
                    <?= $offset + 1 ?>
                    –
                    <?= min($offset + $perPage, $filteredTotal) ?>

                <?php else: ?>

                    No results

                <?php endif; ?>

            </div>

        </div>


        <form
            method="GET"
            action="/logs/"
            class="logs-filters"
        >

            <div class="logs-search">

                <span class="material-symbols-rounded">
                    search
                </span>

                <input
                    type="text"
                    name="search"
                    value="<?= htmlspecialchars($search) ?>"
                    placeholder="Search action, user, details or record..."
                    autocomplete="off"
                >

            </div>


            <select
                name="module"
                class="logs-filter-control"
            >

                <option value="">
                    All Modules
                </option>

                <?php foreach ($moduleOptions as $module): ?>

                    <option
                        value="<?= htmlspecialchars((string) $module) ?>"
                        <?= $moduleFilter === (string) $module ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars((string) $module) ?>
                    </option>

                <?php endforeach; ?>

            </select>


            <select
                name="action"
                class="logs-filter-control"
            >

                <option value="">
                    All Actions
                </option>

                <?php foreach ($actionOptions as $action): ?>

                    <option
                        value="<?= htmlspecialchars((string) $action) ?>"
                        <?= $actionFilter === (string) $action ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars(logsActionLabel((string) $action)) ?>
                    </option>

                <?php endforeach; ?>

            </select>


            <div class="logs-date-field">

                <span>
                    From
                </span>

                <input
                    type="date"
                    name="date_from"
                    value="<?= htmlspecialchars($dateFrom) ?>"
                    class="logs-filter-control"
                >

            </div>


            <div class="logs-date-field">

                <span>
                    To
                </span>

                <input
                    type="date"
                    name="date_to"
                    value="<?= htmlspecialchars($dateTo) ?>"
                    class="logs-filter-control"
                >

            </div>


            <button
                type="submit"
                class="logs-primary-button"
            >

                <span class="material-symbols-rounded">
                    filter_alt
                </span>

                Apply

            </button>


            <?php if (
                $search !== ''
                || $moduleFilter !== ''
                || $actionFilter !== ''
                || $dateFrom !== ''
                || $dateTo !== ''
            ): ?>

                <a
                    href="/logs/"
                    class="logs-secondary-button"
                >
                    Clear
                </a>

            <?php endif; ?>

        </form>


        <div class="logs-table-wrap">

            <table class="logs-table">

                <thead>

                    <tr>
                        <th>Date & Time</th>
                        <th>User</th>
                        <th>Module</th>
                        <th>Action</th>
                        <th>Record</th>
                        <th>Details</th>
                    </tr>

                </thead>


                <tbody>

                <?php if (empty($logs)): ?>

                    <tr>

                        <td
                            colspan="6"
                            class="logs-empty-cell"
                        >

                            <div class="logs-empty">

                                <span class="material-symbols-rounded">
                                    history_toggle_off
                                </span>

                                <strong>
                                    No system logs found
                                </strong>

                                <p>
                                    Try changing the filters or perform an action
                                    in UA POS that creates an audit entry.
                                </p>

                            </div>

                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($logs as $log): ?>

                        <?php

                        $actorName = logsActorName($log);

                        $recordLabel = trim(
                            (string) ($log['record_type'] ?? '')
                        );

                        $recordId = $log['record_id'] !== null
                            ? (int) $log['record_id']
                            : null;

                        ?>

                        <tr>

                            <td>

                                <span class="logs-date">
                                    <?= htmlspecialchars(
                                        logsDisplayDateTime(
                                            $log['created_at']
                                        )
                                    ) ?>
                                </span>

                            </td>


                            <td>

                                <div class="logs-user">

                                    <div class="logs-avatar">
                                        <?= htmlspecialchars(logsInitials($log)) ?>
                                    </div>

                                    <div>

                                        <strong>
                                            <?= htmlspecialchars($actorName) ?>
                                        </strong>

                                        <span>

                                            <?php if (!empty($log['username'])): ?>

                                                @<?= htmlspecialchars($log['username']) ?>

                                            <?php else: ?>

                                                System

                                            <?php endif; ?>

                                            <?php if (!empty($log['role'])): ?>

                                                · <?= htmlspecialchars($log['role']) ?>

                                            <?php endif; ?>

                                        </span>

                                    </div>

                                </div>

                            </td>


                            <td>

                                <span class="logs-module">

                                    <span class="material-symbols-rounded">
                                        <?= htmlspecialchars(
                                            logsModuleIcon(
                                                (string) $log['module']
                                            )
                                        ) ?>
                                    </span>

                                    <?= htmlspecialchars($log['module']) ?>

                                </span>

                            </td>


                            <td>

                                <span class="logs-action">
                                    <?= htmlspecialchars(
                                        logsActionLabel(
                                            (string) $log['action']
                                        )
                                    ) ?>
                                </span>

                            </td>


                            <td>

                                <?php if ($recordLabel !== '' || $recordId !== null): ?>

                                    <div class="logs-record">

                                        <strong>
                                            <?= htmlspecialchars(
                                                $recordLabel !== ''
                                                    ? $recordLabel
                                                    : 'Record'
                                            ) ?>
                                        </strong>

                                        <?php if ($recordId !== null): ?>

                                            <span>
                                                #<?= $recordId ?>
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                <?php else: ?>

                                    <span class="logs-muted">
                                        —
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>

                                <div class="logs-details">

                                    <?= htmlspecialchars(
                                        trim(
                                            (string) ($log['details'] ?? '')
                                        ) !== ''
                                            ? $log['details']
                                            : 'No additional details.'
                                    ) ?>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>


        <?php if ($totalPages > 1): ?>

            <div class="logs-pagination">

                <div class="logs-pagination-info">
                    Page <?= $page ?> of <?= $totalPages ?>
                </div>

                <div class="logs-pagination-buttons">

                    <?php if ($page > 1): ?>

                        <a
                            href="<?= htmlspecialchars(logsPageUrl($page - 1)) ?>"
                            class="logs-page-button"
                        >

                            <span class="material-symbols-rounded">
                                chevron_left
                            </span>

                            Previous

                        </a>

                    <?php endif; ?>


                    <?php

                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);

                    ?>


                    <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>

                        <a
                            href="<?= htmlspecialchars(logsPageUrl($pageNumber)) ?>"
                            class="logs-page-number <?= $pageNumber === $page ? 'active' : '' ?>"
                        >
                            <?= $pageNumber ?>
                        </a>

                    <?php endfor; ?>


                    <?php if ($page < $totalPages): ?>

                        <a
                            href="<?= htmlspecialchars(logsPageUrl($page + 1)) ?>"
                            class="logs-page-button"
                        >

                            Next

                            <span class="material-symbols-rounded">
                                chevron_right
                            </span>

                        </a>

                    <?php endif; ?>

                </div>

            </div>

        <?php endif; ?>

    </div>

</div>

<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';

?>
