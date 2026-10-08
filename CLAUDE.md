# CLAUDE.md — CompAcc POS (Underground Apparel)

Standing context and rules for this repository. Claude Code reads this file
automatically at the start of every session, for **both** team members.

- **Where things stand** (tasks, bugs, decisions, session log) lives in
  **[PROGRESS.md](PROGRESS.md)**. Follow the Session Routine in section 12.
- When this file, PROGRESS.md and the code disagree, **the actual code and
  database win** — verify, don't assume.

---

## 1. Project overview

CompAcc POS is a local point-of-sale and business management system for
**Underground Apparel**, a clothing business whose products come in
**color + size variants**. It covers:

- Sales at the counter (POS) with receipts, discounts, VAT and voids
- Variant-level inventory, product photos, stock losses
- Suppliers and supplier-linked restocking (stock receipts)
- Payroll, expenses and losses
- Reports, user accounts, settings, backups and system logs

### Team

| Name | GitHub | Name in `git log` |
|---|---|---|
| Will R. Bitoy | `willrdgzbitoy015-ops` | `Will Bitoy` |
| Kisha Kyle Samson | `kishakylepc-ui` | `KishaKyle` / `kishakylepc-ui` |

- Repo: **`kishakylepc-ui/CompAcc_POS`** on GitHub, main branch **`main`**.
- We are both still learning programming — see section 14 (How to help us).

## 2. Stack (do not change)

- **PHP 8** (plain PHP pages, no framework) + **SQLite** through PDO, session login.
- HTML / CSS / vanilla JavaScript. **Poppins** font, **Material Symbols Rounded**
  icons, dark navy / glass visual style.
- Windows + PowerShell. PHP lives at `C:\php\php.exe`.
- **Never** suggest XAMPP or MySQL, and never migrate the database engine.

## 3. Run it locally

```powershell
cd "C:\Users\Will Bitoy\Desktop\CompAcc_POS"      # or your own copy
C:\php\php.exe -S localhost:8000 -t public
```

Open **http://localhost:8000** → it goes to the login page (or the dashboard
if you are already signed in). Stop the server with **Ctrl + C**.

- **Database file:** `storage/database/pos.sqlite`. It is **git-ignored** — each
  computer has its **own** database and its own data. It never travels through GitHub.
- **Project copies:** Kisha `C:\Users\kyles\Documents\CompAcc_POS`,
  Will `C:\Users\Will Bitoy\Desktop\CompAcc_POS` (put quotes around paths with spaces).
- **Environment check:** http://localhost:8000/check.sqlite.php shows whether
  PDO / SQLite are enabled.
- After CSS changes, press **Ctrl + F5** in the browser to reload styles.

### New computer (fresh database)

⚠️ **Currently broken** — `tools/setup_database.php` fails on an empty database
(`no such column: product_code`). See PROGRESS.md → Known Issues. Until it is
fixed, get a copy of a teammate's `pos.sqlite` directly (USB / chat), **never
through GitHub**, and put it in `storage/database/`.

### Migration scripts (`tools/`)

Run from the project root: `C:\php\php.exe tools\<script>.php`

| Script | Status |
|---|---|
| `migrate_variant_inventory.php` | Already applied — **do not rerun** |
| `migrate_product_codes.php` | Already applied — **do not rerun** |
| `migrate_variant_identifiers.php` | Already applied — **do not rerun** |
| `migrate_user_contact_fields.php` | **Pending on each computer** — adds `users.email` and `users.contact_number` for Settings → My Account. Safe to run twice; makes its own backup. Follow section 6 rule 6 first. |
| `setup_database.php` | Creates all tables for a new install (currently broken, see above) |
| `test_database.php` | Prints a "connected" message |

## 4. Folder structure

```
app/
  config/database.php          PDO connection to storage/database/pos.sqlite (foreign keys ON)
  middleware/auth.php          starts the session; sends signed-out users to /login.php
  middleware/role.php          requireRole([...]) — sends users without that role to /dashboard/
  views/partials/header.php    <head>: shared CSS (app, layout, confirmation-modal, ui) + ui.js
  views/partials/sidebar.php   sidebar menu (links depend on role) + top page header
  views/partials/footer.php    page footer + the global confirmation modal and its script
  views/partials/confirmation-modal.php   empty file, not used
public/                         web root (the folder the PHP server serves)
  index.php                    entry: dashboard if signed in, otherwise login
  login.php                    sign-in page
  authenticate.php             checks username/password (password_verify), starts the session
  logout.php                   signs out
  check.sqlite.php             PHP / SQLite environment check (no login needed)
  dashboard/index.php          dashboard; Cashiers only see their own sales
  pos/index.php                point of sale: scan/search variants, cart, discount, payment
  pos/process_sale.php         saves a sale (server recalculates totals, VAT, discounts)
  pos/receipt.php              printable receipt
  pos/void_sale.php            voids a completed sale and returns the stock
  inventory/index.php          products, colors & sizes, photos, restock, stock loss,
                               delete product, link supplier from Restock
  suppliers/index.php          suppliers and product–supplier links / prices
  payroll/index.php            employees and payroll processing
  expenses/index.php           expenses and losses
  reports/index.php            report tabs (sales, products, inventory, stock receipts,
                               discounts, cashiers, suppliers, expenses, payroll, financial summary)
  accounts/index|create|edit   user accounts
  logs/index.php               system logs (read-only)
  settings/index.php           My Account (every role) + Admin tabs: Sales & Tax,
                               Business & Receipt, Inventory & Reorder, Payment QR, Backup & System
  profile/index.php            old address — redirects to Settings → My Account
  assets/css/app.css           design tokens (colors, --ua-text-* sizes, controls) + base styles
  assets/css/layout.css        sidebar, header, page layout
  assets/css/ui.css            shared components: toasts, loading spinner/bar, animations
  assets/css/<module>.css      one stylesheet per module (pos, inventory, reports, ...)
  assets/js/ui.js              shared helpers: UA.toast, UA.confirm, data-confirm-form,
                               UA.setLoading, UA.progress
  assets/images/               logo, background; products/ = product & color photos (committed);
                               payment_qr/ = payment QR images (git-ignored, never commit)
storage/
  database/pos.sqlite          the live database (git-ignored, real business data)
  database/pos.sqbpro          DB Browser for SQLite project file
  backups/                     database backups (git-ignored)
tools/                          setup and migration scripts (section 3)
```

## 5. Database tables

| Table | What it stores |
|---|---|
| `users` | Sign-in accounts: username, password hash, name, role (Admin / Manager / Cashier), status |
| `settings` | Key / value settings: store name, VAT rate, PWD/Senior discount, low-stock threshold, QR image paths |
| `tax_rates` | Old VAT presets — **not used by any page** (VAT comes from `settings`) |
| `categories` | Product categories |
| `products` | Parent product / style: `product_code` (UA-0001), name, category, cost & selling price, cached total stock, reorder level, status |
| `product_variants` | Sellable color + size variants: SKU, barcode, color swatch, **`stock_quantity` (the real stock)**, status, color photo |
| `product_suppliers` | Which suppliers supply a product, at what price, and which one is primary |
| `suppliers` | Supplier contact details and status |
| `stock_receipts` / `stock_receipt_items` | Supplier deliveries (restocks): receipt number, supplier, who received it, cost, notes / the variants and quantities received |
| `sales` / `sale_items` | Sales (Completed or Voided, with void reason) / the items sold, with a snapshot of variant SKU, color and size |
| `inventory_logs` | Every stock movement: Initial Stock, Restock, Sale, Void Return, Damaged, Adjustment |
| `employees` / `payroll` | Employees / processed payroll runs |
| `expenses` | Expenses and losses (`expense_type` = Expense or Loss) |
| `system_logs` | Audit trail of user actions (read-only in the app) |

- Triggers on `product_variants` keep `products.stock_quantity` = sum of its
  variants' stock (two duplicate trigger sets exist — harmless).
- Triggers on `products` refuse a missing `product_code`.

## 6. Database rules (critical)

1. **Never guess the schema.** Before writing SQL, inspect `sqlite_master` for the
   tables, indexes and triggers involved. Use read-only SELECT queries to inspect.
2. **`product_variants.stock_quantity` is the source of truth for stock.**
   `products.stock_quantity` is a cached total kept in sync by triggers.
   Only ever update the variant row; never write parent stock directly.
3. Variants are unique on `(product_id, color, size)`; `sku` and `barcode` are unique.
4. Restocking is supplier-linked and writes `stock_receipts` + `stock_receipt_items`
   plus an inventory log and a system log. Multi-step stock changes run in **one transaction**.
5. The three "already applied" migrations in section 3 must **not** be rerun.
6. **Before any write to the database** (migration, test data, schema change):
   explain the change, copy `storage/database/pos.sqlite` to a timestamped backup in
   `storage/backups/`, and get explicit approval first.
7. Never delete, overwrite or replace `pos.sqlite`.

## 7. Roles and permissions

| Page / action | Admin | Manager | Cashier |
|---|:-:|:-:|:-:|
| Dashboard | ✓ | ✓ | ✓ (own sales only) |
| POS, receipts | ✓ | ✓ | ✓ |
| Void a sale | ✓ | ✓ | — |
| Inventory, Suppliers, Payroll, Expenses, Reports | ✓ | ✓ | — |
| Delete product (Inventory) | ✓ | — | — |
| Accounts, System Logs | ✓ | — | — |
| Settings → My Account | ✓ | ✓ | ✓ |
| Settings → system tabs (tax, business, inventory, QR, backup) | ✓ | — | — |

Enforce on the server with `requireRole([...])` (and explicit role checks for
single actions). Hiding a link is not authorization. Never weaken a role check.

## 8. Business rules (do not change without approval)

- **VAT-inclusive pricing:** displayed prices include VAT.
  `VAT included = Total × rate / (100 + rate)`, `VATable = Total − VAT included`.
  The rate comes from Settings (presets 12 / 16 / 20 %). A ₱799 test passed.
- **PWD / Senior:** simplified flat 20 % discount, then VAT is taken from the
  discounted amount. Accepted for now; a known limitation, not a full legal implementation.
- **Financial Summary = Net Sales − Expenses − Gross Payroll.** No COGS or purchase
  costs are subtracted. Do not change this.
- Sales totals, discounts and VAT are **always recalculated on the server**.
- **No expiration-date features** (apparel business). `products.expiration_date`
  and the `expiration_warning_days` setting are leftovers.
- System Logs are **read-only** in the UI. No "clear logs" feature.
- **Restock** needs an active supplier linked to the product; one can be linked
  from inside the Restock window.
- **Delete product** (Admin only) is allowed only for an **Inactive** product with
  **no sales and no supplier receipts**. Products with history stay (Inactive).

## 9. UI rules

- Keep the dark navy / glass style, Poppins, spacing and card patterns.
- Use the tokens in `app.css` (e.g. `--ua-text-xs/sm/md/base`, `--ua-control-height`)
  instead of new hard-coded sizes. No text smaller than 10px.
- **Never use `window.confirm()`, `window.alert()` or `prompt()`.** Use the shared helpers:
  - Confirmation: a `type="button"` trigger with `data-confirm`, `data-confirm-title`,
    `data-confirm-message`, `data-confirm-label`, `data-confirm-icon` and
    **`data-confirm-form="<form id>"`** (ui.js submits the form after confirming),
    or `UA.confirm({ title, message, label, icon, onConfirm })` from code.
    Do not reintroduce the old `data-confirm="message"` pattern.
  - Messages: `UA.toast(message, 'info' | 'success' | 'warning' | 'error', { field })`.
  - Loading: forms get a button spinner automatically; use `UA.setLoading(button, true)`
    for fetch / JS actions.
- Page Escape / keyboard handlers must ignore keys while
  `window.UA?.isConfirmOpen()` is true.
- Shared partials (header / sidebar / footer) affect every page: only change them
  when the task needs it, explain why first, and never revert their newer behavior.
- Check layouts at about **1366 px, 768 px and 390 px** wide.
- When you change a CSS file, bump its `?v=` version in the `<link>` tag.

## 10. Security rules

- Prepared statements for every user-controlled value.
- CSRF token (`$_SESSION['csrf_token']`, checked with `hash_equals`) on every
  state-changing request; reuse the module's existing mechanism.
- Escape all output with `htmlspecialchars`. When printing JSON inside `<script>`,
  use `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`.
- Validate on the server even when JavaScript validates.
- Never trust hidden inputs for prices, totals, permissions or ownership.
- Never log or display passwords, password hashes or session tokens.

## 11. Coding conventions (match the existing code)

- **One PHP file per page**, in this order:
  1. `require` role middleware + `requireRole([...])`, then `database.php`
  2. POST handling: CSRF check → `if ($action === '...') { ... }` blocks →
     flash message in the session → redirect back (Post / Redirect / Get)
  3. GET: load data for the page
  4. `header.php` + `sidebar.php`, the page HTML, the page `<script>`, `footer.php`
- **Module prefixes** for helpers and CSS classes: `inventoryFlash()`,
  `supplierFlash()`, `.inventory-modal`, `.pos-card`, …
- Multi-step writes run inside `$pdo->beginTransaction()` / `commit()` with
  `rollBack()` in `catch`, and add a `system_logs` row (`ACTION_NAME`, module, details).
- Dates are stored in **UTC** (`CURRENT_TIMESTAMP`) and shown in **Asia/Manila** time.
- Many files use a tall style (one argument per line). Keep the style of the file
  you are editing.
- Product photos go in `public/assets/images/products/` as `product-<id>.<ext>` and
  `product-<id>-color-<name>-<random>.<ext>` (WebP / JPG / PNG, max 5 MB).
- Commit messages: `feat:`, `fix:`, `style:`, `docs:` + a short summary.

## 12. Session Routine (always follow)

**At the START of every session:**
1. Remind us to run **`git pull`** first (the two computers can be out of sync).
2. Read **PROGRESS.md**.
3. Run **`git log --oneline -15`**.
4. Give a **short summary**: what was done last and by whom, what is in progress,
   what is next, and any open bugs.

**At the END of every session**, or when we say **"update progress"**,
**"save progress"** or **"done for today"**:
1. Update **PROGRESS.md**:
   - add a new **Session Log** entry at the **top** (use the template in the file),
   - move tasks on the **Task Board** (In Progress / To Do / Done),
   - update **Known Issues** and **Decisions**,
   - change "Last updated".
2. Remind us to **commit and push** with a short, clear message, for example:
   `git add -A` → `git commit -m "docs: update progress"` → `git push`
   (check `git status` first — no database, backups, QR images or secrets).

## 13. Teamwork rules

- **Pull before you start, push when you finish.** Small, focused commits.
- **Check PROGRESS.md → In Progress before editing a file** the other member may be
  working on. If someone else owns it, ask them first (or coordinate in chat).
- For big changes, work on a branch (e.g. `feat/restock-ui`) and merge into `main`
  when both agree.
- **Never rewrite or delete the other member's Session Log entries.** Only add your own.
- Mark a feature **"Tested ✅"** in PROGRESS.md only after a team member confirms it
  works in the browser.
- **Never commit** secrets, `.env` files, the live database (`*.sqlite`), backups or
  payment QR images. The `.gitignore` already excludes them — keep it that way.

## 14. How to help us (style rules)

- We are learning: **explain step by step in simple terms**, and say why a change
  is needed, not only what it is.
- Give **complete, copy-paste-ready code** and the exact file path, and say plainly
  when a file was substantially rewritten.
- **One feature at a time.** After each feature, give a short **browser test
  checklist** and wait for our results before moving on.
- If something is unknown (schema, behavior, business rule), say so and check or ask.

## 15. How to work in this repo

1. **Inspect first.** Read the target files, their CSS / JS, the shared partials they
   use, and the relevant schema before proposing changes.
2. **Plan, then wait.** Present a short plan (what changes, which files, what stays
   the same) and wait for approval before editing.
3. **No unrelated refactors** or "while I'm here" changes.
4. **Edit in place, then report** every file changed and what changed in it.
5. **Verify:** run `C:\php\php.exe -l <file>` on every changed PHP file.
6. **Give a test checklist** and wait for the results.
7. **Never claim something is tested** unless a team member confirms it passed.

## 16. Git rules

- Repo `kishakylepc-ui/CompAcc_POS`, branch `main`.
- **Do not commit, push, pull, merge, reset, stash or discard changes** unless we
  explicitly ask. Never run `git reset --hard` or `git checkout -- .`.
- Before any commit we ask for: show `git status` and confirm that no database,
  backups, QR images or secrets are staged.
- The two computers may be out of sync — never assume otherwise.
