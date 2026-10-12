# PROGRESS — CompAcc POS

> Shared progress file for **Will** and **Kisha** (and our Claude sessions).
> **Start of a session:** `git pull`, then read this file.
> **End of a session:** update this file, then commit and push.
> Full routine: [CLAUDE.md](CLAUDE.md) → section 12.
>
> Status words: **Done** = code finished · **Tested ✅** = a team member confirmed
> it works in the browser · **Not tested** = nobody has confirmed it yet.

**Last updated:** 2026-10-12 — Kisha Kyle Samson (with Claude)
**Current phase:** Core modules are built → now **stabilizing** (bug fixes, security,
browser testing) and doing the **Restock UI redesign**.
**`main` is at:** Kisha's 2026-10-12 push: Will's split payment merged with the login limit,
the new light-mode look and Forgot password.
**Branch `feat/sale-prices`** (Kisha, 2026-10-12, 4 commits, **not pushed yet**): Sale /
Markdown / Clearance prices — waiting for Kisha's browser test and Will's review before
it is merged into `main`.

---

## Task Board

### 🟡 In Progress

| Task | Owner | Status / notes | Main files |
|---|---|---|---|
| **Restock UI improvement in Inventory** | Will | Phase 1 (inspection + report) done 2026-10-02. Already added on 2026-10-08: link a supplier from inside Restock, disabled-button look, pinned Close / Save. **Next:** Phase 2 — propose the 3-step layout (select variant → quantity & supplier → review & confirm), get approval, build it; Phase 3 — browser test checklist. **Note (Kisha, 2026-10-09):** `main` already has a step-by-step Restock window from Kisha's 2026-10-07 work — ① "Which color and size arrived?" ② "Delivery details" (supplier, quantity, cost) ③ summary of stock before → after and total cost. What is still missing is an "Are you sure?" before Restock saves (stock loss and link supplier already ask). Will: please check whether Phase 2 is only that, before rebuilding. | `public/inventory/index.php`, `public/assets/css/inventory.css` |
| **Sale / Markdown / Clearance prices** | Kisha | **Built on branch `feat/sale-prices`** (2026-10-12: `7b17072`, `470801f`, `0f9f41b`, `fa1708c`; not pushed yet). New **Prices** page (Inventory → Prices), POS / receipt / Reports changes, `tools/migrate_price_changes.php` (**run on Kisha's PC**). Inventory was **not** edited. **Next:** Kisha browser-tests (checklist in the chat) → push the branch → Will reviews → merge into `main` when both agree → **Will runs the migration** on his PC. | `public/prices/`, `app/helpers/prices.php`, `public/pos/`, `public/reports/index.php`, `tools/migrate_price_changes.php` |
| **Payroll — remaining work** | Will | Validation, Void payroll and optional login are done (see Done). Will said Payroll is still unfinished: list what is left at the start of the next session, then continue. | `public/payroll/index.php`, `public/assets/css/payroll.css` |

### 🔵 To Do (top = most important)

| # | Task | Owner | Notes |
|---|---|---|---|
| 1 | ~~**Fix new-computer setup** — `tools/setup_database.php` fails on an empty database~~ **Done** | Kisha | Code finished 2026-10-09 (see Done). Still needs a team member to try it on a new, empty copy. |
| 2 | **Browser-test the 2026-10-08 work** and mark it Tested ✅ | Will + Kisha | Will's POS redesign & Inventory fixes; Kisha's void sales, Expenses & Losses, stock loss, My Account; the evening work (Payroll void, "Are you sure?" confirmations, Settings validation, collapsible sidebar). |
| 3 | ~~Run `tools/migrate_user_contact_fields.php` **and** `tools/migrate_payroll_void.php` on Kisha's computer (backup first)~~ **Done** | Kisha | Will's PC: both run 2026-10-08 (backups in `storage/backups/`, integrity ok, no rows lost). **Kisha's PC (checked 2026-10-09):** the database already has both sets of columns (contact fields migration ran 2026-10-08 with a backup; running the payroll script reports "Nothing to do"); integrity ok. |
| 4 | Security fixes from Known Issues #2–#4 | — | Inventory JSON escaping, restock quantity checks, restock double-submit (Inventory = Will's file in progress). Login attempt limit (#5) and default admin password (#6) were fixed 2026-10-09. |
| 5 | Validate **Payroll** with real employees | — | Server validation and Void were added 2026-10-08; still needs a real-data check in the browser. |
| 6 | Test **Expenses & Losses** with real entries | — | 0 expense rows on Will's PC. |
| 7 | **Backup restore:** add or document a restore procedure and test it | — | Backups can be created and downloaded; there is no restore. |
| 8 | **Modals step 2:** closing animation, keep Tab inside open windows, screen-reader labels on Inventory modals | — | Shared helpers live in `public/assets/js/ui.js`. |
| 9 | Receipt + business settings end-to-end check | — | Change store details in Settings → print a receipt. |
| 10 | Full-system QA + responsive check (1366 / 768 / 390 px) | Both | Dashboard, System Logs, Settings, QR management still need a full pass. |
| 11 | Housekeeping | — | Delete merged branch `feat/void-expenses-ui-account-settings`; remove empty `confirmation-modal.php`; decide on unused `tax_rates` table; add a favicon. |
| 12 | "Are you sure?" on the last save forms | — | Accounts → Edit asks only when the status changes; decide whether every edit should ask (rule in CLAUDE.md section 9). |
| 13 | Record the old → new price when a price is edited in **Inventory → Edit product** | Will | Today the System Log only says "Updated product …". The Prices page keeps a full history for Sale / Markdown / Clearance, but a price typed in Inventory leaves no trace of the old price. Inventory is Will's file. |

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
| **New-computer setup fixed:** `tools/setup_database.php` creates every table already up to date (product codes, contact fields, payroll void), asks for the `admin` password instead of creating `admin123` / `manager123` / `cashier123`, refuses to run on a database that already has tables, and stops with a clear message if anything fails | Kisha | 2026-10-09 | Not tested by a team member (Claude checked on scratch copies: the new database matches the live one on all 286 schema items except the duplicate triggers; every page loads with no PHP errors; wrong / short passwords and a second run change nothing) |
| **Light / dark mode:** sun / moon button in the top bar and on the login page, remembered per browser, applied before the page draws; light colors generated by `tools/build_light_theme.php` into `theme-light.css` (dark stylesheets untouched); photos, QR codes, logos, swatches, the receipt paper and printouts never change | Kisha | 2026-10-09 | Partly tested by Kisha (found the POS selected-size bug, fixed); full browser test pending — Will to review |
| **Login limit:** wrong passwords / unknown usernames saved in System Logs as `LOGIN_FAILED`; 5 wrong tries for one username → locked 5 minutes (even for the right password), with a "tries left" warning from the 3rd try. **`check.sqlite.php` removed**, replaced by `tools/check_environment.php` (run in PowerShell). Committed 2026-10-12; since then a password change also restarts the count | Kisha | 2026-10-09 | Not tested by a team member (Claude: 17 / 17 checks on a scratch copy through the real login page) |
| **Split payment in POS** (cash plus one cashless method), `sale_payments` table + `tools/migrate_split_payments.php`; receipt, reports and dashboard show each part | Will | 2026-10-11 | Not tested by Kisha yet (looks right in light mode; the table is on both PCs) |
| **Light mode, new look:** store photo at full strength (no white layer) with every text box on a solid soft-grey background; readable hover on black buttons (dark gold, white text); the builder keeps text colors on colored backgrounds; POS selected-size hover fixed (both modes); **same small motion in dark and light** (page sections and sign-in card rise in, summary cards lift 1px) | Kisha | 2026-10-12 | Not tested by a team member (Claude: 0 text on the photo, 0 hover problems in light mode, contrast checks on a scratch copy) |
| **Forgot password:** request to the Admins from the sign-in page (System Logs, no email; limited per hour; same answer for unknown usernames), **Accounts → Password Requests** with Set New Password / Dismiss, a new password ends the login lock, rescue tool `tools/reset_password.php` (backup first) | Kisha | 2026-10-12 | Not tested by a team member (Claude: 44 / 44 server checks + 12 / 12 window checks on a scratch copy) |

---

## Known Issues / Bugs

| # | Issue | Severity | Where | Notes |
|---|---|---|---|---|
| 1 | ~~**Fresh setup fails:** `setup_database.php` stops with `no such column: product_code` on an empty database.~~ | Resolved | `tools/setup_database.php` | Fixed 2026-10-09 (Kisha): the script now creates the final schema directly. Not tested by a team member yet. |
| 2 | Inventory prints product data inside `<script>` without `JSON_HEX_TAG`, so a product or supplier name containing `</script>` could inject code. | Medium | `public/inventory/index.php` (`inventoryProducts` JSON) | Only Admin / Manager can create names. One-flag fix. |
| 3 | Restock has no **server-side** protection against double submission. | Medium | `public/inventory/index.php` (`restock_product`) | The browser now locks the button after one click, which covers normal use. |
| 4 | Restock quantity is read with `(int)`, so `2.7` becomes `2`, and there is no maximum. | Low–Medium | `public/inventory/index.php` (`restock_product`) | The browser blocks most bad input. |
| 5 | ~~Login has no limit on repeated wrong passwords.~~ | Resolved | `public/authenticate.php` | Fixed 2026-10-09: 5 wrong tries per username → 5-minute lock; failures are in System Logs (`LOGIN_FAILED`). Not tested by a team member yet. |
| 6 | ~~`setup_database.php` creates a default Admin account with a well-known password.~~ | Resolved | `tools/setup_database.php` | Fixed 2026-10-09: it asks for the Admin password and no longer creates Manager / Cashier accounts. Older installs made with the old script should still change `admin123`. |
| 7 | No **restore** from backup. | Medium | `public/settings/index.php` (Backup tab) | Backups can be created and downloaded; Will's PC now has the migration backups in `storage/backups/`. |
| 8 | ~~Settings → My Account shows email / contact number as unavailable until the migration runs.~~ | Resolved | `tools/migrate_user_contact_fields.php` | Migration run on both PCs (Will 2026-10-08; Kisha's database checked 2026-10-09). |
| 9 | ~~`check.sqlite.php` is open without login and shows the PHP version.~~ | Resolved | `tools/check_environment.php` | Fixed 2026-10-09: the web page was removed; run `C:\php\php.exe tools\check_environment.php` instead. |
| 10 | Two duplicate sets of stock-sync triggers on `product_variants`. | Low | database | Both compute the same total — harmless, extra work. |
| 11 | GCash QR image file missing on Will's PC, so the QR box is blank at checkout there. | Low | `public/assets/images/payment_qr/` | QR images are git-ignored on purpose; upload it in Settings → Payment QR on each PC. |
| 12 | Premium Plains (Charcoal Black) has an old size named "Medium". | Low | data | Rename it to **M** with that row's size dropdown in Edit Product (stock and barcode stay). |
| 13 | Leftovers: `tax_rates` table unused, `products.expiration_date` and the `expiration_warning_days` setting, empty `confirmation-modal.php`, legacy `products.barcode`. | Low | various | Clean up only after agreeing. |
| 14 | No favicon (harmless 404 in the browser console). | Low | `public/` | — |
| 16 | In dark mode the "Optional" hint next to fields in the Restock / Stock Loss windows is very faint (contrast 2.6). | Low | `public/inventory/index.php` (`.restock-optional`) | Found by Claude's hover check 2026-10-12. Inventory is Will's file in progress. |
| 17 | (Branch `feat/sale-prices`) Voiding a sale that sold the **last clearance piece** returns the stock, but that color / size stays **Inactive** (hidden in POS). | Low | `public/pos/void_sale.php` | Set it Active again in Inventory if it should be sold again. Decide later whether a void should reactivate it. |
| 18 | (Branch `feat/sale-prices`) A **Markdown** below a running Sale price makes that Sale stop applying (POS always charges the lower price). The Sale row still shows as Running until it ends or is stopped. | Low | `public/prices/index.php` | Works correctly for customers; only the list can look confusing. |
| 15 | **Delete product erases stock-loss history.** The delete only checks for sales and supplier receipts, then deletes **all** `inventory_logs` rows of the product — including Damaged / Adjustment rows from Record Stock Loss — while the Loss entry stays in Expenses & Losses. | Medium | `public/inventory/index.php` (`delete_product`) | Found by Kisha 2026-10-08. Suggested fix: also block delete when the product has Damaged or Adjustment log rows (keep it Inactive instead). Inventory is Will's file in progress — agree before changing. |

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
| 2026-10-09 | **Login limit:** 5 wrong tries per username → 5-minute lock (right password refused while locked); failures are recorded in **System Logs** (`LOGIN_FAILED`), no new table. The environment check is a **command** (`tools/check_environment.php`), not a web page. |
| 2026-10-09 | **Light mode** is optional and remembered **per browser** (no database change); **dark stays the default**. Light colors are **generated** from the dark CSS by `tools/build_light_theme.php` (re-run after color changes); photos, QR codes, logos, swatches, the receipt paper and printouts never change. |
| 2026-10-09 | **New installs** use `tools/setup_database.php`, which builds the final schema directly (no migrations afterwards), creates only an `admin` account with a password chosen during setup, and refuses to run on a database that already has tables. A fresh install gets one set of stock-sync triggers (existing databases keep their harmless duplicate set). |
| 2026-10-12 | **Light mode look:** the store photo shows at full strength (no white layer); every box with text has a solid **soft-grey** background, a thin gold line marks page titles and windows, black buttons are charcoal and turn **dark gold with white text** on hover. |
| 2026-10-12 | **Motion is the same in dark and light mode** and stays small (rise-in, 1px hover lift, modal pop-in); it lives in `ui.css`, never light-only. |
| 2026-10-12 | **Sale / Markdown / Clearance** live on a new **Prices** page (Admin and Manager), not inside Inventory. Sale = chosen colors / sizes between two dates; Markdown = new regular price for good; Clearance = no end date. Reason required; overlaps refused; nothing is deleted (Stop + history). |
| 2026-10-12 | **PWD / Senior on a Sale / Clearance item = the higher of the two** (sale price or 20 % off the regular price), never both. Reports and receipts use the saved `sales.discount_amount`. |
| 2026-10-12 | A sale price **below cost is allowed after a warning** ("you lose ₱X per piece"). A **clearance color / size that sells out becomes Inactive** automatically. |
| 2026-10-12 | **Forgot password = a request to the Admins** (System Logs, no email server, no self-service reset). Admins set the new password in Accounts → Edit; `tools/reset_password.php` is the rescue when no Admin can sign in. A password change restarts the wrong-try count. |

---

## Session Log

Newest entry at the top. Add your own entry; never edit someone else's.

### 2026-10-12 (evening) — Kisha Kyle Samson (with Claude) — Sale / Markdown / Clearance prices (branch)

**Done (all on branch `feat/sale-prices`, not pushed yet):**
- Decisions by Kisha: PWD / Senior get the **higher of the two** discounts; prices are managed on
  a **new Prices page**; a price **below cost is allowed after a warning**; a **clearance size that
  sells out becomes Inactive**.
- **Prices page** (Inventory → Prices, Admin and Manager): New Price Change window with searchable
  **product cards with photos**, Sale / Markdown / Clearance, colors / sizes with their color
  photos, ₱ price or % off, dates, reason, below-cost warning and "Are you sure?". Lists: Running
  Now (with **Stop**), Starting Later, History (photos, who and when).
- **POS:** SALE / CLEARANCE tags, the card price follows the chosen size (old price crossed out),
  crossed-out price in the cart and the confirm window, note about the PWD / Senior rule.
  `process_sale.php` works the price out again on the server, saves `regular_price` /
  `price_type`, and sets a sold-out clearance size Inactive (`CLEARANCE_SOLD_OUT` log).
- **Receipt:** "Sale · was ₱799.00" under the item and "You saved ₱…" under the total.
- **Reports:** discounts come from the saved `discount_amount` (same totals as before:
  ₱639.20 / ₱799.00 checked); Discounts tab shows Sale and Clearance savings per item.
- **Migration** `tools/migrate_price_changes.php` — **run on Kisha's PC** with approval (backup
  `storage/backups/pos-before-price-changes-20261012-051319.sqlite`). Only change to old data:
  sales 4, 5, 6 got their saved discount (₱159.80, already in their totals); row counts and the
  sales total unchanged; integrity ok. A second run does nothing (the discount fill-in only runs the
  first time, so a real ₱0 discount on a later PWD sale is never overwritten).
  `setup_database.php` creates the new table for new computers.
- **POS cart layout fix:** from about 1440 px wide, item names were cut off ("UA Snapb…"). Carts
  under 560 px now use two rows (name on top, quantity and price below; the price no longer
  squeezes the name). Checked at 1366, 1440, 1920, 1024, 768 and 390 px.
- Checked by Claude on a scratch copy: 55 / 55 server checks, 27 / 27 browser checks, light mode 0
  low-contrast and 0 hover problems, 0 text on the photo. **Not tested by a team member yet.**

**Files changed:**
- New: `public/prices/index.php`, `public/assets/css/prices.css`, `app/helpers/prices.php`,
  `tools/migrate_price_changes.php`
- `public/pos/index.php`, `public/pos/process_sale.php`, `public/pos/receipt.php`,
  `public/reports/index.php`, `tools/setup_database.php`, `app/views/partials/sidebar.php`
  (Prices link), `public/assets/css/pos.css`, `public/assets/css/receipt.css`,
  `public/assets/css/ui.css`, `tools/build_light_theme.php`, `public/assets/css/theme-light.css`
  (generated), version bumps in `header.php`, `login.php`, `receipt.php`
- `CLAUDE.md` (table, migration, roles, price rules), `PROGRESS.md`
- Commits on the branch: `7b17072`, `470801f`, `0f9f41b`, `fa1708c`

**Next steps:**
- Kisha: browser-test Prices, POS (sale + PWD + clearance sold out), receipt and Reports →
  Discounts; then push the branch (`git push -u origin feat/sale-prices`).
- Will: review the branch; when both agree, merge into `main`, then run
  `C:\php\php.exe tools\migrate_price_changes.php` on Will's PC (it makes its own backup).
- Will: To Do #13 (log old → new price in Inventory → Edit product).

**Notes / blockers:**
- Database for Will: **nothing to send** — only the migration after the merge.
- Kisha's database already has the prices migration, so on Kisha's PC switching back to `main`
  is fine (the new table / columns are simply unused there).

### 2026-10-12 — Kisha Kyle Samson (with Claude) — Merge split payment, light mode look, Forgot password

**Done:**
- Committed the 2026-10-09 login limit and environment check, then pulled and **merged Will's
  split payment** (`4005454`). Conflicts were only the `theme-light.css?v=` lines and the CLAUDE.md
  tool table (both rows kept). Kisha's database already has `sale_payments`, so the split-payment
  migration is done on both PCs.
- **Light mode, after Kisha's tests:**
  - No white layer over the store photo (pages and sign-in); every card, panel, window and the
    sign-in card has a solid soft-grey background; thin gold line on page titles and windows;
    charcoal buttons.
  - Hover bug: black buttons turned gold while their text stayed light → now dark gold with white
    text. The builder now keeps the text color of rules with a colored background, and uses dark
    text on bright colored backgrounds that have none.
  - POS: hovering the **selected size** hid its text in **both** modes → fixed in `pos.css`.
  - **Same small motion in dark and light** (`ui.css`): page sections rise in (Expenses was
    missing), the sign-in card rises in, summary cards lift 1px on hover. No light-only effects.
- **Forgot password** (no email server): "Forgot password?" on the sign-in page opens a window;
  the username is sent as a request to the Admins (`PASSWORD_RESET_REQUEST` in System Logs; the
  same answer whether the username exists; max 3 per username and 20 in total per hour).
  **Accounts → Password Requests** lists open requests with **Set New Password** (opens Edit at the
  password field) and **Dismiss** ("Are you sure?", `PASSWORD_RESET_DISMISSED`). A request closes
  by itself once the password changes. A new password also ends the 5-minute login lock.
  Rescue tool `tools/reset_password.php` for when no Admin can sign in (backup first, asks `YES`).
- Checked by Claude on a scratch copy: Forgot password 44 / 44 server checks + 12 / 12 window
  checks; light mode 0 text on the photo, 0 hover problems; dark mode unchanged except the
  intended motion. Not tested by a team member yet.
- CLAUDE.md: rescue tool, `forgot_password.php`, light-mode and motion rules, Forgot password and
  login-limit security rules.

**Files changed:**
- `public/forgot_password.php` (new), `tools/reset_password.php` (new), `public/login.php`,
  `public/authenticate.php`, `public/accounts/index.php`, `public/assets/css/accounts.css`,
  `public/assets/css/login.css`
- `tools/build_light_theme.php`, `public/assets/css/theme-light.css` (generated),
  `public/assets/css/ui.css`, `public/assets/css/pos.css`, version bumps in
  `app/views/partials/header.php`, `public/pos/index.php`, `public/pos/receipt.php`,
  `public/accounts/create.php`, `public/accounts/edit.php`
- `CLAUDE.md`, `PROGRESS.md`

**Next steps:**
- Kisha + Will: browser-test light mode and Forgot password (checklists in the chat), then mark
  them Tested ✅.
- Kisha (In Progress): **Sale / Markdown / Clearance prices** — decisions first, then agree with
  Will who builds the Inventory part.
- Will: Known Issue #16 (faint "Optional" hint in Restock) when you are in Inventory.

**Notes / blockers:**
- Database for Will: **no change in structure** — `git pull` is enough.

### 2026-10-09 — Kisha Kyle Samson (with Claude) — Pull, migration check, new-computer setup

**Done:**
- Pulled `main` (`2e97dce`): Will's merge of Kisha's branch, the payroll / confirmations /
  settings / sidebar work, CLAUDE.md and PROGRESS.md.
- Checked the database on Kisha's PC: it already has the contact-field and payroll-void
  columns (`migrate_payroll_void.php` reports "Nothing to do"); integrity ok, no rows lost.
  To Do #3 and Known Issue #8 are done.
- **Fixed new-computer setup** (To Do #1, Known Issues #1 and #6): `tools/setup_database.php`
  was rewritten — it builds every table already up to date, asks for the `admin` password,
  and refuses to touch a database that already has tables.
- PROGRESS.md: corrected the migration status, added Known Issue #15 (Delete product erases
  stock-loss history), and added a note for Will that the step-by-step Restock window
  already exists (only "Are you sure?" on Restock is missing).
- CLAUDE.md: section 3 now explains the working new-computer setup; migration table updated.

**Files changed:**
- `tools/setup_database.php` (rewritten), `CLAUDE.md`, `PROGRESS.md`

**Next steps:**
- Kisha: try the new setup on a separate empty copy (checklist in the chat), then mark it Tested ✅.
- Will: read the Restock note in In Progress and Known Issue #15 (both in Inventory, your file).
- ~~Next for Kisha (proposed): login attempt limit + protect `check.sqlite.php` (Known Issues #5, #9).~~ Done later the same day (below).

**Later the same day — light / dark mode (built on branch `feat/light-mode`, merged into `main` at Kisha's request):**
- Sun / moon button in the top bar (every page) and on the login page; the choice is
  remembered per browser and applied before the page is drawn (no flash). The receipt
  page follows it; the receipt paper and printouts never change.
- New `tools/build_light_theme.php` reads every stylesheet and writes
  `public/assets/css/theme-light.css` (1,083 rules). The dark stylesheets were not edited.
- Checked by Claude on a scratch copy (headless Chrome): dark mode unchanged on all 58
  screens (only the new button added); light mode readable on 87 screens (1366 / 768 /
  390 px) — everything passes the contrast check except the receipt paper (kept as
  printed) and the red "Remove Stock" button (same as in dark mode); the button works,
  is remembered across pages, and printing ignores light mode.
- Files: `tools/build_light_theme.php` (new), `public/assets/css/theme-light.css` (new,
  generated), `public/assets/css/ui.css`, `public/assets/js/ui.js`,
  `app/views/partials/header.php`, `app/views/partials/sidebar.php`, `public/login.php`,
  `public/pos/receipt.php`, `CLAUDE.md`, `PROGRESS.md`.
- Partly tested by Kisha. Merged into `main` at Kisha's request; Will, please try it
  (Ctrl+F5) and report anything hard to read. His Inventory / Payroll work is unaffected:
  no module stylesheet was changed.
- Fix after Kisha's first test: in light mode the **selected size on the POS** turned
  white-on-white. Cause: rules whose color comes from a shared token (`var(--pos-gold)`)
  were skipped, so the plain button's light rule won. The builder now re-writes every
  color line (even unchanged ones) so selected / active states keep their priority; also
  checked: POS with an item in the cart, a size picked in Restock, the expense form.

**Later the same day — login limit and environment check (Known Issues #5, #9):**
- `public/authenticate.php`: every wrong password or unknown username is saved in System
  Logs as `LOGIN_FAILED`; from the 3rd wrong try the message says how many tries are left;
  after 5 wrong tries (within 15 minutes, since the last successful login) the username is
  locked until 5 minutes after the last wrong try — even the right password is refused.
  Messages never reveal whether a username exists. No database change.
- `public/check.sqlite.php` removed (it showed the PHP version to anyone); new
  `tools/check_environment.php` does the same check from PowerShell, plus the database
  folder.
- Checked by Claude on a scratch copy through the real login page: 17 / 17 (lock after 5,
  right password refused while locked, unlock after the wait, count starts over after a
  successful login, unknown usernames, other users unaffected, System Logs shows the
  failures, no PHP errors). Not tested by a team member yet.
- Files: `public/authenticate.php`, `public/check.sqlite.php` (deleted),
  `tools/check_environment.php` (new), `CLAUDE.md`, `PROGRESS.md`.

**Notes / blockers:**
- The database on Kisha's PC was replaced at 15:31 on 2026-10-09 (outside git) with one that
  already had both migrations and the 2026-10-08 evening activity. Its VAT setting is **16 %**
  (changed 2026-10-08 21:27) — set it back in Settings → Sales & Tax if the store uses 12 %.
- The setup fix and light / dark mode are committed and on `main`; ~~the login limit and
  environment check are not committed yet.~~ Committed and pushed 2026-10-12.

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
