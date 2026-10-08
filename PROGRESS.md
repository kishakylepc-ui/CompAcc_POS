# PROGRESS — CompAcc POS

> Shared progress file for **Will** and **Kisha** (and our Claude sessions).
> **Start of a session:** `git pull`, then read this file.
> **End of a session:** update this file, then commit and push.
> Full routine: [CLAUDE.md](CLAUDE.md) → section 12.
>
> Status words: **Done** = code finished · **Tested ✅** = a team member confirmed
> it works in the browser · **Not tested** = nobody has confirmed it yet.

**Last updated:** 2026-10-08 (evening) — Will R. Bitoy (with Claude)
**Current phase:** Core modules are built → now **stabilizing** (bug fixes, security,
browser testing) and doing the **Restock UI redesign**.
**`main` is at:** `9cccc55` (code) + the docs commit right after it — committed on Will's PC
on 2026-10-08; push it so Kisha can pull it.

---

## Task Board

### 🟡 In Progress

| Task | Owner | Status / notes | Main files |
|---|---|---|---|
| **Restock UI improvement in Inventory** | Will | Phase 1 (inspection + report) done 2026-10-02. Already added on 2026-10-08: link a supplier from inside Restock, disabled-button look, pinned Close / Save. **Next:** Phase 2 — propose the 3-step layout (select variant → quantity & supplier → review & confirm), get approval, build it; Phase 3 — browser test checklist. | `public/inventory/index.php`, `public/assets/css/inventory.css` |
| **Payroll — remaining work** | Will | Validation, Void payroll and optional login are done (see Done). Will said Payroll is still unfinished: list what is left at the start of the next session, then continue. | `public/payroll/index.php`, `public/assets/css/payroll.css` |

### 🔵 To Do (top = most important)

| # | Task | Owner | Notes |
|---|---|---|---|
| 1 | **Fix new-computer setup** — `tools/setup_database.php` fails on an empty database | — | See Known Issue #1. Needed before anyone sets up a fresh copy. |
| 2 | **Browser-test the 2026-10-08 work** and mark it Tested ✅ | Will + Kisha | Will's POS redesign & Inventory fixes; Kisha's void sales, Expenses & Losses, stock loss, My Account; the evening work (Payroll void, "Are you sure?" confirmations, Settings validation, collapsible sidebar). |
| 3 | Run `tools/migrate_user_contact_fields.php` **and** `tools/migrate_payroll_void.php` on Kisha's computer (backup first) | Kisha | Will's PC: both run 2026-10-08 (backups in `storage/backups/`, integrity ok, no rows lost). |
| 4 | Security fixes from Known Issues #2–#6 | — | Inventory JSON escaping, restock quantity checks, restock double-submit, login attempt limit, default admin password. |
| 5 | Validate **Payroll** with real employees | — | Server validation and Void were added 2026-10-08; still needs a real-data check in the browser. |
| 6 | Test **Expenses & Losses** with real entries | — | 0 expense rows on Will's PC. |
| 7 | **Backup restore:** add or document a restore procedure and test it | — | Backups can be created and downloaded; there is no restore. |
| 8 | **Modals step 2:** closing animation, keep Tab inside open windows, screen-reader labels on Inventory modals | — | Shared helpers live in `public/assets/js/ui.js`. |
| 9 | Receipt + business settings end-to-end check | — | Change store details in Settings → print a receipt. |
| 10 | Full-system QA + responsive check (1366 / 768 / 390 px) | Both | Dashboard, System Logs, Settings, QR management still need a full pass. |
| 11 | Housekeeping | — | Delete merged branch `feat/void-expenses-ui-account-settings`; remove empty `confirmation-modal.php`; decide on unused `tax_rates` table; add a favicon. |
| 12 | "Are you sure?" on the last save forms | — | Accounts → Edit asks only when the status changes; decide whether every edit should ask (rule in CLAUDE.md section 9). |

### ✅ Done

Owner and date come from `git log` (author of the commit).

| Feature | Owner | Date | Tested? |
|---|---|---|---|
| Project setup, PHP + SQLite foundation, `.gitignore` | Kisha | 2026-08-31 | — |
| Login + dashboard redesign | Will | 2026-09-02 → 09-03 | Not tested (security review pending) |
| Login authentication improvements, responsive UI | Kisha | 2026-09-03 | Not tested |
| Inventory improvements + product photos in POS and receipts | Kisha | 2026-09-05 | Not tested |
| Supplier management + product variant support | Kisha | 2026-09-06 | Not tested |
| Supplier management module completed | Will | 2026-09-08 | Not tested |
| Employee management + payroll processing | Will | 2026-09-08 | Not tested — needs validation |
| POS variants, inventory and reporting | Kisha | 2026-09-12 | POS core + variant cart + search: Tested ✅ (earlier) |
| Restocking + stock receipts (supplier-linked) | Kisha / Will | 2026-09 | Tested ✅ (restock tested earlier) |
| Accounts, inventory confirmations, dashboard | Will | 2026-09-14 | Accounts activation: Tested ✅ · Dashboard: Not tested |
| Settings expansion + VAT-inclusive pricing | Kisha | 2026-09-20 | VAT ₱799 test: Tested ✅ · Settings / QR: Not tested |
| Shared UI: toasts instead of alerts, loading spinners/bar, animations, modal fixes (Payroll status, Escape) | Will | 2026-10-08 | Not tested |
| POS fit-to-screen redesign; Inventory edit-crash fix; SKU clash check; natural size order; Delete product (Admin); pinned Close in product windows; link supplier from Restock | Will | 2026-10-08 | Not tested (Delete product was used once on Will's PC) |
| Void sales, Expenses & Losses, stock loss tracking | Kisha | 2026-10-08 | Reviewed by Will ("happy with this"); a void was done once on Will's PC |
| Shared text sizes, spacing, responsive layouts | Kisha | 2026-10-08 | Reviewed by Will |
| My Account tab in Settings for every role | Kisha | 2026-10-08 | Not tested (contact fields need the migration) |
| Payroll: hours ≤ 16 per day, no overlapping periods, POS login optional, **Void payroll** (Admin, with reason); voided payroll left out of totals, Reports and Dashboard; `tools/migrate_payroll_void.php` | Will | 2026-10-08 | Not tested by a team member (migration run on Will's PC) |
| Green success banners fade away after 4 s (hover pauses); red error banners stay | Will | 2026-10-08 | Not tested |
| Settings: server length limits for business details, whole-number check for Inventory settings ("abc" no longer saves as 0), plain error messages (details go to the PHP error log) | Will | 2026-10-08 | Not tested by a team member (server checks passed on a scratch copy) |
| "Are you sure?" confirmation before saves: Settings (7 forms), Inventory (add / edit product, stock loss, link supplier), Payroll employee, Expenses entry, Suppliers (add / edit, link product, update price), Accounts → Create | Will | 2026-10-08 | Not tested by a team member (all 21 passed in a headless browser on a scratch copy) |
| Collapsible sidebar (« button, icons only, remembered per browser) and a menu that fits short screens without scrolling | Will | 2026-10-08 | Not tested by a team member |

---

## Known Issues / Bugs

| # | Issue | Severity | Where | Notes |
|---|---|---|---|---|
| 1 | **Fresh setup fails:** `setup_database.php` stops with `no such column: product_code` on an empty database, so nothing is created and the migrations cannot run either. | **High** | `tools/setup_database.php` | Its `products` table has no `product_code` column, but it creates an index on it. Verified 2026-10-08 on a scratch copy. Existing databases are fine. |
| 2 | Inventory prints product data inside `<script>` without `JSON_HEX_TAG`, so a product or supplier name containing `</script>` could inject code. | Medium | `public/inventory/index.php` (`inventoryProducts` JSON) | Only Admin / Manager can create names. One-flag fix. |
| 3 | Restock has no **server-side** protection against double submission. | Medium | `public/inventory/index.php` (`restock_product`) | The browser now locks the button after one click, which covers normal use. |
| 4 | Restock quantity is read with `(int)`, so `2.7` becomes `2`, and there is no maximum. | Low–Medium | `public/inventory/index.php` (`restock_product`) | The browser blocks most bad input. |
| 5 | Login has no limit on repeated wrong passwords. | Medium | `public/authenticate.php` | Passwords are checked with `password_verify`; the session is regenerated on login. |
| 6 | `setup_database.php` creates a default Admin account with a well-known password. | Medium | `tools/setup_database.php` | Change that password right after any new install. |
| 7 | No **restore** from backup. | Medium | `public/settings/index.php` (Backup tab) | Backups can be created and downloaded; Will's PC now has the migration backups in `storage/backups/`. |
| 8 | Settings → My Account shows email / contact number as unavailable until the migration runs. | Low | `tools/migrate_user_contact_fields.php` | Fixed on Will's PC (migration run 2026-10-08); still the case on Kisha's PC until To Do #3. |
| 9 | `check.sqlite.php` is open without login and shows the PHP version. | Low | `public/check.sqlite.php` | Remove it or require login. |
| 10 | Two duplicate sets of stock-sync triggers on `product_variants`. | Low | database | Both compute the same total — harmless, extra work. |
| 11 | GCash QR image file missing on Will's PC, so the QR box is blank at checkout there. | Low | `public/assets/images/payment_qr/` | QR images are git-ignored on purpose; upload it in Settings → Payment QR on each PC. |
| 12 | Premium Plains (Charcoal Black) has an old size named "Medium". | Low | data | Rename it to **M** with that row's size dropdown in Edit Product (stock and barcode stay). |
| 13 | Leftovers: `tax_rates` table unused, `products.expiration_date` and the `expiration_warning_days` setting, empty `confirmation-modal.php`, legacy `products.barcode`. | Low | various | Clean up only after agreeing. |
| 14 | No favicon (harmless 404 in the browser console). | Low | `public/` | — |

---

## Decisions

| Date | Decision |
|---|---|
| 2026-08-31 | **PHP + SQLite** run with the **PHP built-in server** (`C:\php\php.exe -S localhost:8000 -t public`). No XAMPP, no MySQL. |
| 2026-09 | **Variant stock is the source of truth**; `products.stock_quantity` is a cached total kept in sync by database triggers. |
| 2026-09 | Parent **product / style code** (`UA-0001`); variant **SKU** = code + color + size; variant **barcode** = 200000000 + variant id. |
| 2026-09 | **Restocking is supplier-linked** and always creates a stock receipt. |
| 2026-09-20 | **VAT-inclusive prices**; the VAT rate comes from Settings (presets 12 / 16 / 20 %). |
| 2026-09 | **PWD / Senior** = simplified flat 20 % discount, then VAT from the discounted amount (accepted limitation). |
| 2026-09 | **Financial Summary = Net Sales − Expenses − Gross Payroll** (no COGS). |
| 2026-09 | **No expiration-date features** (apparel). **System Logs are read-only.** |
| 2026-08-31 → 09-12 | The **live database, backups and payment QR images are never committed** (`.gitignore`). Product photos **are** committed. |
| 2026-10-08 | No browser `alert` / `confirm` / `prompt` — use the global confirmation modal and the shared helpers in `ui.js` / `ui.css`. |
| 2026-10-08 | POS uses a **fit-to-screen** layout with Total and Complete Sale always visible. |
| 2026-10-08 | Sizes are shown in **natural order** (XS, S, M, L, XL, 2XL, 3XL, One Size). |
| 2026-10-08 | **Delete product** is **Admin only**, and only for an **Inactive** product with **no sales and no supplier receipts** (opening stock may be deleted; it is recorded in System Logs). |
| 2026-10-08 | A supplier can be **linked from inside the Restock window**, using the same rules as Suppliers → Manage Products. |
| 2026-10-08 | **My Account** moved into **Settings** for every role; `/profile/` redirects there. |
| 2026-10-08 | Shared **text-size tokens** in `app.css`; no text below 10px. |
| 2026-10-08 | Bigger work happens on a **feature branch** and is merged into `main` once both agree. |
| 2026-10-08 | Shared tracking: **CLAUDE.md** holds the rules, **PROGRESS.md** holds the status. |
| 2026-10-08 | **Payroll:** at most **16 hours per calendar day**, periods may **not overlap** for the same employee, an employee does **not need** a POS login, and mistakes are **voided by an Admin with a reason** (never deleted). |
| 2026-10-08 | Every form that saves data asks **"Are you sure?"** first (`data-confirm-submit` / `UA.confirmSubmit` in `ui.js`). Forms with their own checks only ask once the values are valid. |
| 2026-10-08 | Green success banners fade after 4 s; red error banners stay until the page changes. |
| 2026-10-08 | Users see plain error messages; technical database errors go to the PHP error log only. |
| 2026-10-08 | The **sidebar can be collapsed** to icons; its width is `--ua-sidebar-width` and nothing may hard-code 260px. |

---

## Session Log

Newest entry at the top. Add your own entry; never edit someone else's.

### 2026-10-08 (evening) — Will R. Bitoy (with Claude) — Payroll, confirmations, Settings, sidebar

**Done:**
- **Payroll:** hours must be > 0 and at most 16 per day of the period; a period can't
  overlap another processed payroll of the same employee; an employee no longer needs
  a POS login; an Admin can **Void** a payroll with a reason (History shows a VOIDED
  badge, and voided payroll is left out of totals, Reports and the Dashboard).
- New `tools/migrate_payroll_void.php` (adds the void columns). Ran it **and**
  `migrate_user_contact_fields.php` on Will's PC after backups; integrity ok, no rows lost.
- Green success banners (e.g. "Employee added successfully.") now fade after 4 seconds.
- **Settings:** the server now enforces the same length limits as the form fields,
  Inventory settings must be whole numbers in range (errors name the field), and
  database errors show a plain message instead of technical text.
- **"Are you sure?"** before every save on Settings, Inventory, Payroll, Expenses,
  Suppliers and Accounts → Create (21 forms). Cancel keeps what you typed.
- **Sidebar:** « button collapses it to icons (hover shows the name; remembered per
  browser), and the menu now fits short screens, so Settings and Sign Out are always visible.
- CLAUDE.md: migrations table, payroll rules, the save-confirmation rule and the
  sidebar-width rule.

**Files changed:**
- `public/payroll/index.php`, `public/assets/css/payroll.css`, `public/reports/index.php`,
  `public/dashboard/index.php`, `tools/migrate_payroll_void.php` (new)
- `public/settings/index.php`, `public/inventory/index.php`, `public/expenses/index.php`,
  `public/suppliers/index.php`, `public/accounts/create.php`
- `app/views/partials/sidebar.php`, `app/views/partials/header.php` (version bumps),
  `public/assets/css/layout.css`, `public/assets/css/pos.css`, `public/pos/index.php`,
  `public/assets/js/ui.js`, `public/assets/css/ui.css`, `public/login.php`
- `CLAUDE.md`, `PROGRESS.md`
- Commits: `9cccc55` (code) and the docs commit right after it

**Next steps:**
- Will: browser-test the evening work (checklists were given in the chat), then mark items Tested ✅.
- Kisha: `git pull`, then run both migrations on your PC (To Do #3), backup first.
- Continue the unfinished **Payroll** work, then the **Restock UI** Phase 2.

**Notes / blockers:**
- Everything above was checked by Claude on a scratch copy (server checks + headless
  browser), but **no team member has tested it in the browser yet**.
- Press **Ctrl + F5** after pulling so the new CSS / JS load.

### 2026-10-08 — Will R. Bitoy (with Claude) — Project baseline

**Done:**
- Created the shared tracking system: **CLAUDE.md** (rules, how to run, folder map,
  tables, conventions, session routine, teamwork rules) and this **PROGRESS.md**.
- Explored the whole repo and database to record the real current state (above).
- Earlier today — commit `18a0d2f`: shared toasts, loading spinners/bar and
  animations; fixed the broken Payroll employee status button and the Escape key
  closing two windows at once.
- Earlier today — commit `5b1a501`: POS fit-to-screen redesign; fixed the Inventory
  "UNIQUE constraint" crash when editing sizes; SKU clash check; natural size order;
  Delete product (Admin only); pinned Close / Save in product windows; link a
  supplier from the Restock window.
- Reviewed Kisha's branch `feat/void-expenses-ui-account-settings` (void sales,
  Expenses & Losses, stock loss, text sizes, My Account) and merged it into `main`
  (fast-forward to `ba1769b`), then pushed.

**Files changed (this entry):**
- `CLAUDE.md` (new), `PROGRESS.md` (new)

**Next steps:**
- Kisha: `git pull`, read this file, check that the To Do owners are right.
- Fix new-computer setup (To Do #1).
- Continue the **Restock UI improvement** (Phase 2 proposal).
- Browser-test the 2026-10-08 work and mark items Tested ✅.

**Notes / blockers:**
- Each computer has its own `pos.sqlite`, so data differs between our PCs (for
  example, products created on one PC do not exist on the other).
- `tools/migrate_user_contact_fields.php` has not been run on Will's PC yet.

<!--
TEMPLATE: copy the lines between the two comment markers ABOVE the newest entry,
then fill them in. (This block is hidden when the file is viewed on GitHub.)

### YYYY-MM-DD — Your Name

**Done:**
- 

**Files changed:**
- 

**Next steps:**
- 

**Notes / blockers:**
- 
-->
