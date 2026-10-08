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

$pageTitle = 'Expenses & Losses';
$currentPage = 'expenses';


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
| CATEGORIES
|--------------------------------------------------------------------------
|
| Expense = a normal business cost. Loss = money or property that was lost.
| Both are subtracted in the Financial Summary report
| (Net Sales - Expenses - Gross Payroll).
|
| Payroll and stock purchases are NOT offered here: payroll is already
| subtracted separately, and stock purchases are recorded through Restock.
|
*/

const EXPENSE_CATEGORIES = [
    'Expense' => [
        'Rent',
        'Utilities',
        'Store Supplies',
        'Transportation & Delivery',
        'Marketing',
        'Repairs & Maintenance',
        'Taxes & Permits',
        'Other Expense'
    ],
    'Loss' => [
        'Theft',
        'Cash Shortage',
        'Damaged Property',
        'Other Loss'
    ]
];

/*
| Written automatically by Inventory → Record Stock Loss. They are read-only
| here so the amount always matches the stock that was actually removed.
*/
const SYSTEM_LOSS_CATEGORIES = [
    'Damaged Stock',
    'Missing Stock'
];


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function manilaToday(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))
        ->format('Y-m-d');
}

function isValidMonth(string $month): bool
{
    return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month);
}

function isValidDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function expensesRedirect(string $month = ''): never
{
    header(
        'Location: /expenses/'
        . (isValidMonth($month) || $month === 'all' ? '?month=' . $month : '')
    );
    exit;
}

function expensesFlash(string $type, string $message): void
{
    $_SESSION[$type === 'success' ? 'expenses_success' : 'expenses_error'] = $message;
}

function expenseDisplayDate(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed ? $parsed->format('M d, Y') : $date;
}

function expenseMoney(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function expenseSummary(array $expense): string
{
    return $expense['expense_type']
        . ' · '
        . $expense['category']
        . ' · '
        . expenseMoney((float) $expense['amount'])
        . ' · '
        . expenseDisplayDate((string) $expense['expense_date'])
        . ' — '
        . $expense['description'];
}

function writeExpenseLog(
    PDO $pdo,
    string $action,
    int $expenseId,
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
        VALUES (?, ?, 'Expenses', 'Expense', ?, ?)
    ");

    $statement->execute([
        $_SESSION['user_id'] ?? null,
        $action,
        $expenseId,
        $details
    ]);
}

function findExpense(PDO $pdo, int $expenseId): ?array
{
    $statement = $pdo->prepare("
        SELECT id, expense_type, category, description, amount, expense_date
        FROM expenses
        WHERE id = ?
        LIMIT 1
    ");

    $statement->execute([$expenseId]);

    $expense = $statement->fetch();

    return $expense ?: null;
}


/*
|--------------------------------------------------------------------------
| HANDLE POST REQUESTS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = trim((string) ($_POST['action'] ?? ''));
    $returnMonth = trim((string) ($_POST['return_month'] ?? ''));
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if (
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $submittedToken)
    ) {
        expensesFlash('error', 'Your session expired. Refresh the page and try again.');
        expensesRedirect($returnMonth);
    }


    /* ADD OR UPDATE */

    if ($action === 'save_expense') {

        $expenseId = (int) ($_POST['expense_id'] ?? 0);
        $type = trim((string) ($_POST['expense_type'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $amountInput = trim((string) ($_POST['amount'] ?? ''));
        $date = trim((string) ($_POST['expense_date'] ?? ''));

        $amount = is_numeric($amountInput)
            ? round((float) $amountInput, 2)
            : -1;

        $error = null;

        if (!isset(EXPENSE_CATEGORIES[$type])) {
            $error = 'Choose whether this is an expense or a loss.';
        } elseif (!in_array($category, EXPENSE_CATEGORIES[$type], true)) {
            $error = 'Choose a category for this ' . strtolower($type) . '.';
        } elseif ($description === '') {
            $error = 'Enter a short description.';
        } elseif (strlen($description) > 255) {
            $error = 'Keep the description under 255 characters.';
        } elseif ($amount <= 0) {
            $error = 'Enter an amount greater than zero.';
        } elseif ($amount > 10000000) {
            $error = 'That amount is too large. Please check it.';
        } elseif (!isValidDate($date)) {
            $error = 'Enter a valid date.';
        } elseif ($date > manilaToday()) {
            $error = 'The date cannot be in the future.';
        }

        if ($error !== null) {
            expensesFlash('error', $error);
            expensesRedirect($returnMonth);
        }

        try {

            if ($expenseId > 0) {

                $existing = findExpense($pdo, $expenseId);

                if ($existing === null) {
                    throw new RuntimeException('That entry no longer exists.');
                }

                if (in_array($existing['category'], SYSTEM_LOSS_CATEGORIES, true)) {
                    throw new RuntimeException(
                        'Stock losses are recorded from Inventory and cannot be edited here.'
                    );
                }

                $update = $pdo->prepare("
                    UPDATE expenses
                    SET expense_type = ?, category = ?, description = ?, amount = ?, expense_date = ?
                    WHERE id = ?
                ");

                $update->execute([$type, $category, $description, $amount, $date, $expenseId]);

                $saved = findExpense($pdo, $expenseId);

                writeExpenseLog(
                    $pdo,
                    'UPDATE_EXPENSE',
                    $expenseId,
                    'Updated ' . expenseSummary($existing) . ' → ' . expenseSummary($saved)
                );

                expensesFlash('success', $type . ' updated.');

            } else {

                $insert = $pdo->prepare("
                    INSERT INTO expenses (
                        expense_type,
                        category,
                        description,
                        amount,
                        expense_date,
                        recorded_by
                    )
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                $insert->execute([
                    $type,
                    $category,
                    $description,
                    $amount,
                    $date,
                    $_SESSION['user_id']
                ]);

                $newId = (int) $pdo->lastInsertId();

                writeExpenseLog(
                    $pdo,
                    'ADD_EXPENSE',
                    $newId,
                    'Recorded ' . expenseSummary(findExpense($pdo, $newId))
                );

                expensesFlash(
                    'success',
                    $type . ' of ' . expenseMoney($amount) . ' recorded.'
                );
            }

        } catch (Throwable $error) {
            expensesFlash('error', 'Unable to save: ' . $error->getMessage());
        }

        // Show the month the entry belongs to.
        expensesRedirect(substr($date, 0, 7));
    }


    /* DELETE */

    if ($action === 'delete_expense') {

        $expenseId = (int) ($_POST['expense_id'] ?? 0);

        try {

            $existing = findExpense($pdo, $expenseId);

            if ($existing === null) {
                throw new RuntimeException('That entry no longer exists.');
            }

            if (in_array($existing['category'], SYSTEM_LOSS_CATEGORIES, true)) {
                throw new RuntimeException(
                    'Stock losses are recorded from Inventory and cannot be deleted here.'
                );
            }

            $pdo->beginTransaction();

            $pdo->prepare("DELETE FROM expenses WHERE id = ?")->execute([$expenseId]);

            writeExpenseLog(
                $pdo,
                'DELETE_EXPENSE',
                $expenseId,
                'Deleted ' . expenseSummary($existing)
            );

            $pdo->commit();

            expensesFlash('success', $existing['expense_type'] . ' deleted.');

        } catch (Throwable $error) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            expensesFlash('error', 'Unable to delete: ' . $error->getMessage());
        }

        expensesRedirect($returnMonth);
    }


    expensesFlash('error', 'Unknown action.');
    expensesRedirect($returnMonth);
}


/*
|--------------------------------------------------------------------------
| FLASH MESSAGES
|--------------------------------------------------------------------------
*/

$expensesSuccess = $_SESSION['expenses_success'] ?? '';
$expensesError = $_SESSION['expenses_error'] ?? '';

unset(
    $_SESSION['expenses_success'],
    $_SESSION['expenses_error']
);


/*
|--------------------------------------------------------------------------
| PERIOD + ENTRIES
|--------------------------------------------------------------------------
*/

$today = manilaToday();
$currentMonth = substr($today, 0, 7);

$selectedMonth = trim((string) ($_GET['month'] ?? $currentMonth));

if ($selectedMonth !== 'all' && !isValidMonth($selectedMonth)) {
    $selectedMonth = $currentMonth;
}

$entrySql = "
    SELECT
        e.id,
        e.expense_type,
        e.category,
        e.description,
        e.amount,
        e.expense_date,
        u.first_name,
        u.last_name,
        u.username
    FROM expenses e
    LEFT JOIN users u
        ON u.id = e.recorded_by
";

if ($selectedMonth === 'all') {

    $periodLabel = 'All time';

    $entryStatement = $pdo->query(
        $entrySql . " ORDER BY e.expense_date DESC, e.id DESC"
    );

} else {

    $monthStart = $selectedMonth . '-01';
    $monthEnd = (new DateTimeImmutable($monthStart))->format('Y-m-t');

    $periodLabel = (new DateTimeImmutable($monthStart))->format('F Y');

    $entryStatement = $pdo->prepare(
        $entrySql . " WHERE e.expense_date BETWEEN ? AND ? ORDER BY e.expense_date DESC, e.id DESC"
    );

    $entryStatement->execute([$monthStart, $monthEnd]);
}

$entries = $entryStatement->fetchAll();

$expenseTotal = 0.0;
$lossTotal = 0.0;

foreach ($entries as $entry) {
    if ($entry['expense_type'] === 'Loss') {
        $lossTotal += (float) $entry['amount'];
    } else {
        $expenseTotal += (float) $entry['amount'];
    }
}

$previousMonth = $selectedMonth === 'all'
    ? null
    : (new DateTimeImmutable($selectedMonth . '-01'))->modify('-1 month')->format('Y-m');

$nextMonth = $selectedMonth === 'all' || $selectedMonth >= $currentMonth
    ? null
    : (new DateTimeImmutable($selectedMonth . '-01'))->modify('+1 month')->format('Y-m');


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

<link rel="stylesheet" href="/assets/css/expenses.css">

<div class="expenses-page">

    <div class="expenses-top">
        <div>
            <div class="expenses-eyebrow">ACCOUNTING</div>
            <h2>Expenses &amp; Losses</h2>
            <p>Record business costs and losses. They are subtracted in the Financial Summary report.</p>
        </div>
        <button type="button" class="expenses-primary-button" id="openAddExpense">
            <span class="material-symbols-rounded">add</span>
            Record Entry
        </button>
    </div>

    <?php if ($expensesSuccess !== ''): ?>
        <div class="expenses-alert success" role="status">
            <span class="material-symbols-rounded">check_circle</span>
            <?= htmlspecialchars($expensesSuccess) ?>
        </div>
    <?php endif; ?>

    <?php if ($expensesError !== ''): ?>
        <div class="expenses-alert error" role="alert">
            <span class="material-symbols-rounded">error</span>
            <?= htmlspecialchars($expensesError) ?>
        </div>
    <?php endif; ?>


    <!-- PERIOD -->

    <form method="get" class="expenses-period" id="expensesPeriodForm">

        <?php if ($previousMonth !== null): ?>
            <a class="expenses-icon-button" href="/expenses/?month=<?= $previousMonth ?>" aria-label="Previous month" title="Previous month">
                <span class="material-symbols-rounded">chevron_left</span>
            </a>
        <?php endif; ?>

        <label class="expenses-period-picker">
            <span class="material-symbols-rounded">calendar_month</span>
            <input
                type="month"
                name="month"
                id="expensesMonth"
                value="<?= $selectedMonth === 'all' ? '' : htmlspecialchars($selectedMonth) ?>"
                max="<?= $currentMonth ?>"
                aria-label="Month"
            >
        </label>

        <?php if ($nextMonth !== null): ?>
            <a class="expenses-icon-button" href="/expenses/?month=<?= $nextMonth ?>" aria-label="Next month" title="Next month">
                <span class="material-symbols-rounded">chevron_right</span>
            </a>
        <?php endif; ?>

        <a class="expenses-period-link<?= $selectedMonth === $currentMonth ? ' active' : '' ?>" href="/expenses/?month=<?= $currentMonth ?>">This Month</a>
        <a class="expenses-period-link<?= $selectedMonth === 'all' ? ' active' : '' ?>" href="/expenses/?month=all">All Time</a>

    </form>


    <!-- STATS -->

    <div class="expenses-stats">

        <div class="expenses-stat-card">
            <div class="expenses-stat-icon">
                <span class="material-symbols-rounded">receipt_long</span>
            </div>
            <div>
                <span>Expenses · <?= htmlspecialchars($periodLabel) ?></span>
                <strong><?= expenseMoney($expenseTotal) ?></strong>
            </div>
        </div>

        <div class="expenses-stat-card">
            <div class="expenses-stat-icon loss">
                <span class="material-symbols-rounded">trending_down</span>
            </div>
            <div>
                <span>Losses · <?= htmlspecialchars($periodLabel) ?></span>
                <strong><?= expenseMoney($lossTotal) ?></strong>
            </div>
        </div>

        <div class="expenses-stat-card">
            <div class="expenses-stat-icon">
                <span class="material-symbols-rounded">functions</span>
            </div>
            <div>
                <span>Total Deducted</span>
                <strong><?= expenseMoney($expenseTotal + $lossTotal) ?></strong>
            </div>
        </div>

        <div class="expenses-stat-card">
            <div class="expenses-stat-icon">
                <span class="material-symbols-rounded">format_list_numbered</span>
            </div>
            <div>
                <span>Entries</span>
                <strong><?= count($entries) ?></strong>
            </div>
        </div>

    </div>


    <!-- ENTRIES -->

    <div class="expenses-card">

        <div class="expenses-card-header">
            <div>
                <h3><?= htmlspecialchars($periodLabel) ?></h3>
                <p>
                    <span id="expenseVisibleCount"><?= count($entries) ?></span>
                    <span id="expenseCountLabel"><?= count($entries) === 1 ? 'entry' : 'entries' ?></span>
                </p>
            </div>

            <div class="expenses-toolbar">
                <select id="expenseTypeFilter" class="expenses-filter-select" aria-label="Filter by type">
                    <option value="">Expenses &amp; Losses</option>
                    <option value="expense">Expenses only</option>
                    <option value="loss">Losses only</option>
                </select>
                <div class="expenses-search">
                    <span class="material-symbols-rounded">search</span>
                    <input type="search" id="expenseSearch" placeholder="Search description or category" aria-label="Search entries">
                </div>
            </div>
        </div>

        <div class="expenses-table-wrap">
            <table class="expenses-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th class="amount">Amount</th>
                        <th>Recorded By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>

                    <?php if (empty($entries)): ?>
                        <tr>
                            <td colspan="7" class="expenses-empty-cell">
                                <div class="expenses-empty">
                                    <span class="material-symbols-rounded">receipt_long</span>
                                    <strong>No entries for <?= htmlspecialchars($periodLabel) ?></strong>
                                    <p>Use “Record Entry” to add an expense or a loss.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>

                        <?php foreach ($entries as $entry): ?>
                            <?php
                            $entryId = (int) $entry['id'];
                            $isLoss = $entry['expense_type'] === 'Loss';
                            $isSystemEntry = in_array($entry['category'], SYSTEM_LOSS_CATEGORIES, true);
                            $recorder = trim($entry['first_name'] . ' ' . $entry['last_name']) ?: (string) ($entry['username'] ?? 'Deleted user');
                            ?>
                            <tr
                                class="expense-row"
                                data-type="<?= $isLoss ? 'loss' : 'expense' ?>"
                                data-search="<?= htmlspecialchars(strtolower($entry['category'] . ' ' . $entry['description'])) ?>"
                            >
                                <td class="expense-date"><?= htmlspecialchars(expenseDisplayDate((string) $entry['expense_date'])) ?></td>
                                <td>
                                    <span class="expense-type <?= $isLoss ? 'loss' : 'expense' ?>">
                                        <?= htmlspecialchars($entry['expense_type']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars((string) $entry['category']) ?></td>
                                <td class="expense-description"><?= htmlspecialchars($entry['description']) ?></td>
                                <td class="amount"><?= expenseMoney((float) $entry['amount']) ?></td>
                                <td><?= htmlspecialchars($recorder) ?></td>
                                <td>
                                    <?php if ($isSystemEntry): ?>
                                        <span class="expense-locked" title="Recorded from Inventory. It cannot be edited here so it always matches the stock that was removed.">
                                            <span class="material-symbols-rounded">lock</span>
                                            From Inventory
                                        </span>
                                    <?php else: ?>
                                        <div class="expense-actions">
                                            <button
                                                type="button"
                                                class="expenses-icon-button edit-expense-button"
                                                data-expense='<?= htmlspecialchars(json_encode([
                                                    'id' => $entryId,
                                                    'expense_type' => $entry['expense_type'],
                                                    'category' => $entry['category'],
                                                    'description' => $entry['description'],
                                                    'amount' => number_format((float) $entry['amount'], 2, '.', ''),
                                                    'expense_date' => $entry['expense_date']
                                                ]), ENT_QUOTES) ?>'
                                                title="Edit"
                                                aria-label="Edit <?= htmlspecialchars($entry['description']) ?>"
                                            >
                                                <span class="material-symbols-rounded">edit</span>
                                            </button>

                                            <form method="post" class="expense-delete-form" id="expenseDeleteForm-<?= $entryId ?>">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="action" value="delete_expense">
                                                <input type="hidden" name="expense_id" value="<?= $entryId ?>">
                                                <input type="hidden" name="return_month" value="<?= htmlspecialchars($selectedMonth) ?>">
                                                <button
                                                    type="button"
                                                    class="expenses-icon-button danger"
                                                    title="Delete"
                                                    aria-label="Delete <?= htmlspecialchars($entry['description']) ?>"
                                                    data-confirm
                                                    data-confirm-title="Delete this <?= $isLoss ? 'loss' : 'expense' ?>?"
                                                    data-confirm-message="<?= htmlspecialchars(
                                                        $entry['description'] . ' (' . expenseMoney((float) $entry['amount']) . ') will be removed from reports. This is recorded in the system log.'
                                                    ) ?>"
                                                    data-confirm-label="Delete"
                                                    data-confirm-icon="delete"
                                                    data-expense-confirm-form="expenseDeleteForm-<?= $entryId ?>"
                                                >
                                                    <span class="material-symbols-rounded">delete</span>
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php endif; ?>

                    <tr id="filteredExpenseEmpty" hidden>
                        <td colspan="7" class="expenses-empty-cell">
                            <div class="expenses-empty compact">
                                <span class="material-symbols-rounded">search_off</span>
                                <strong>No matching entries</strong>
                                <p>Try another search or type filter.</p>
                            </div>
                        </td>
                    </tr>

                </tbody>
            </table>
        </div>

    </div>

</div>


<!-- ADD / EDIT MODAL -->

<div class="expense-modal" id="expenseModal" hidden>

    <div class="expense-modal-backdrop" data-close-expense-modal></div>

    <div class="expense-modal-card" role="dialog" aria-modal="true" aria-labelledby="expenseModalTitle">

        <div class="expense-modal-header">
            <div>
                <div class="expenses-eyebrow">ACCOUNTING</div>
                <h3 id="expenseModalTitle">Record Entry</h3>
                <p>Payroll and stock purchases are recorded in their own modules, so don't add them here.</p>
            </div>
            <button type="button" class="expense-modal-close" data-close-expense-modal aria-label="Close">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>

        <form method="post" class="expense-form" id="expenseForm" novalidate>

            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="save_expense">
            <input type="hidden" name="expense_id" id="expenseId" value="">
            <input type="hidden" name="return_month" value="<?= htmlspecialchars($selectedMonth) ?>">

            <fieldset class="expense-type-choice">
                <legend>What is this?</legend>

                <label>
                    <input type="radio" name="expense_type" value="Expense" checked>
                    <span>
                        <strong>Expense</strong>
                        <small>A normal business cost, like rent or electricity.</small>
                    </span>
                </label>

                <label>
                    <input type="radio" name="expense_type" value="Loss">
                    <span>
                        <strong>Loss</strong>
                        <small>Money or property that was lost, like theft or a cash shortage.</small>
                    </span>
                </label>
            </fieldset>

            <div class="expense-form-grid">

                <div class="expense-field">
                    <label for="expenseCategory">Category <span>*</span></label>
                    <select id="expenseCategory" name="category" required></select>
                </div>

                <div class="expense-field">
                    <label for="expenseAmount">Amount <span>*</span></label>
                    <div class="expense-money-input">
                        <span>₱</span>
                        <input type="number" id="expenseAmount" name="amount" min="0.01" step="0.01" inputmode="decimal" required>
                    </div>
                </div>

                <div class="expense-field full">
                    <label for="expenseDescription">Description <span>*</span></label>
                    <input type="text" id="expenseDescription" name="description" maxlength="255" placeholder="e.g. October electricity bill" required>
                </div>

                <div class="expense-field">
                    <label for="expenseDate">Date <span>*</span></label>
                    <input type="date" id="expenseDate" name="expense_date" max="<?= $today ?>" required>
                </div>

            </div>

            <p class="expense-form-error" id="expenseFormError" role="alert" hidden></p>

            <div class="expense-modal-footer">
                <button type="button" class="expenses-secondary-button" data-close-expense-modal>Cancel</button>
                <button type="submit" class="expenses-primary-button" id="expenseSubmitButton">
                    <span class="material-symbols-rounded">save</span>
                    <span id="expenseSubmitText">Save Entry</span>
                </button>
            </div>

        </form>

    </div>

</div>


<script>
const expenseCategories = <?= json_encode(EXPENSE_CATEGORIES, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
const expenseToday = <?= json_encode($today) ?>;

const expenseModal = document.getElementById('expenseModal');
const expenseForm = document.getElementById('expenseForm');
const expenseCategory = document.getElementById('expenseCategory');
const expenseAmount = document.getElementById('expenseAmount');
const expenseDescription = document.getElementById('expenseDescription');
const expenseDate = document.getElementById('expenseDate');
const expenseFormError = document.getElementById('expenseFormError');
const expenseSubmitButton = document.getElementById('expenseSubmitButton');
const expenseSubmitText = document.getElementById('expenseSubmitText');

let expenseSubmitting = false;
let expenseModalTrigger = null;


function selectedExpenseType() {
    return expenseForm.querySelector('input[name="expense_type"]:checked').value;
}


function fillCategories(type, selected = '') {
    expenseCategory.innerHTML = '';

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Choose a category';
    expenseCategory.appendChild(placeholder);

    (expenseCategories[type] || []).forEach((category) => {
        const option = document.createElement('option');
        option.value = category;
        option.textContent = category;
        option.selected = category === selected;
        expenseCategory.appendChild(option);
    });
}


function openExpenseModal(entry = null, trigger = null) {
    expenseModalTrigger = trigger;
    expenseSubmitting = false;
    expenseSubmitButton.disabled = false;
    expenseFormError.hidden = true;

    const type = entry ? entry.expense_type : 'Expense';

    expenseForm.querySelector(`input[name="expense_type"][value="${type}"]`).checked = true;
    fillCategories(type, entry ? entry.category : '');

    document.getElementById('expenseId').value = entry ? entry.id : '';
    expenseAmount.value = entry ? entry.amount : '';
    expenseDescription.value = entry ? entry.description : '';
    expenseDate.value = entry ? entry.expense_date : expenseToday;

    document.getElementById('expenseModalTitle').textContent = entry ? 'Edit Entry' : 'Record Entry';
    expenseSubmitText.textContent = entry ? 'Save Changes' : 'Save Entry';

    expenseModal.hidden = false;
    document.body.classList.add('expense-modal-open');
    expenseCategory.focus();
}


function closeExpenseModal() {
    if (expenseSubmitting) {
        return;
    }

    expenseModal.hidden = true;
    document.body.classList.remove('expense-modal-open');

    if (expenseModalTrigger) {
        expenseModalTrigger.focus();
    }
}


document.getElementById('openAddExpense').addEventListener('click', (event) => {
    openExpenseModal(null, event.currentTarget);
});

document.querySelectorAll('.edit-expense-button').forEach((button) => {
    button.addEventListener('click', () => {
        openExpenseModal(JSON.parse(button.dataset.expense), button);
    });
});

document.querySelectorAll('[data-close-expense-modal]').forEach((element) => {
    element.addEventListener('click', closeExpenseModal);
});

expenseForm.querySelectorAll('input[name="expense_type"]').forEach((radio) => {
    radio.addEventListener('change', () => {
        fillCategories(selectedExpenseType());
        expenseFormError.hidden = true;
    });
});

[expenseCategory, expenseAmount, expenseDescription, expenseDate].forEach((field) => {
    field.addEventListener('input', () => {
        expenseFormError.hidden = true;
        field.classList.remove('invalid');
    });
});


/*
| Inline validation (the server checks everything again). Shows the first
| problem in plain language and locks the button once a save starts.
*/
expenseForm.addEventListener('submit', (event) => {
    if (expenseSubmitting) {
        event.preventDefault();
        return;
    }

    const amount = Number(expenseAmount.value);

    const checks = [
        [expenseCategory, expenseCategory.value === '', 'Choose a category.'],
        [expenseAmount, !(amount > 0), 'Enter an amount greater than zero.'],
        [expenseDescription, expenseDescription.value.trim() === '', 'Enter a short description.'],
        [expenseDate, expenseDate.value === '', 'Enter the date.'],
        [expenseDate, expenseDate.value > expenseToday, 'The date cannot be in the future.']
    ];

    const failed = checks.find(([, invalid]) => invalid);

    if (failed) {
        event.preventDefault();
        const [field, , message] = failed;
        expenseFormError.textContent = message;
        expenseFormError.hidden = false;
        field.classList.add('invalid');
        field.focus();
        return;
    }

    /* Ask first; the form is sent again after the user confirms. */
    const editing = document.getElementById('expenseId').value !== '';
    const entryType = expenseForm.querySelector('input[name="expense_type"]:checked')?.value || 'Expense';
    const amountText = amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    if (
        window.UA?.confirmSubmit &&
        !UA.confirmSubmit(event, {
            title: editing ? 'Save changes to this entry?' : `Record this ${entryType.toLowerCase()}?`,
            message: `₱${amountText} · ${expenseCategory.options[expenseCategory.selectedIndex]?.text || ''} · ${expenseDescription.value.trim()}`,
            label: editing ? 'Save Changes' : 'Save Entry',
            icon: entryType === 'Loss' ? 'report' : 'receipt_long'
        })
    ) {
        return;
    }

    expenseSubmitting = true;
    expenseSubmitButton.disabled = true;
    expenseSubmitText.textContent = 'Saving…';
});


/* Month picker: load the chosen month straight away. */
document.getElementById('expensesMonth').addEventListener('change', (event) => {
    if (event.target.value !== '') {
        document.getElementById('expensesPeriodForm').submit();
    }
});


/* Search + type filter */
const expenseSearch = document.getElementById('expenseSearch');
const expenseTypeFilter = document.getElementById('expenseTypeFilter');
const expenseRows = Array.from(document.querySelectorAll('.expense-row'));
const filteredExpenseEmpty = document.getElementById('filteredExpenseEmpty');

function filterExpenses() {
    const query = expenseSearch.value.trim().toLowerCase();
    const type = expenseTypeFilter.value;
    let visible = 0;

    expenseRows.forEach((row) => {
        const show =
            (query === '' || row.dataset.search.includes(query))
            && (type === '' || row.dataset.type === type);

        row.hidden = !show;

        if (show) {
            visible++;
        }
    });

    filteredExpenseEmpty.hidden = expenseRows.length === 0 || visible > 0;
    document.getElementById('expenseVisibleCount').textContent = visible;
    document.getElementById('expenseCountLabel').textContent = visible === 1 ? 'entry' : 'entries';
}

expenseSearch.addEventListener('input', filterExpenses);
expenseTypeFilter.addEventListener('change', filterExpenses);


/* =========================================================
   GLOBAL CONFIRMATION MODAL - DELETE ENTRY
========================================================= */

let pendingExpenseDeleteForm = null;

document.querySelectorAll('[data-expense-confirm-form]').forEach((button) => {
    button.addEventListener('click', () => {
        pendingExpenseDeleteForm = document.getElementById(button.dataset.expenseConfirmForm);
    });
});

document.addEventListener('DOMContentLoaded', () => {
    const confirmSubmit = document.getElementById('systemConfirmSubmit');

    if (!confirmSubmit) {
        return;
    }

    confirmSubmit.addEventListener('click', () => {
        if (!pendingExpenseDeleteForm) {
            return;
        }

        const form = pendingExpenseDeleteForm;
        pendingExpenseDeleteForm = null;
        form.submit();
    });

    ['systemConfirmCancel', 'systemConfirmClose', 'systemConfirmBackdrop']
        .map((id) => document.getElementById(id))
        .filter(Boolean)
        .forEach((element) => {
            element.addEventListener('click', () => {
                pendingExpenseDeleteForm = null;
            });
        });
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
        return;
    }

    pendingExpenseDeleteForm = null;

    /* Escape closes the confirmation first, not the entry window behind it. */
    if (window.UA?.isConfirmOpen()) {
        return;
    }

    if (!expenseModal.hidden) {
        closeExpenseModal();
    }
});
</script>

<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';

?>
