# AGENTS.md

## START HERE: project memory
- Read `PROJECT_NOTES.md` FIRST on every session. It is the persistent memory: full requirements, build order, decisions log, and a "SESSION RESUME" section that records what was just done and the workflow rules. Item numbers in that file are the canonical history (latest ~#100).
- After completing any client change, append a short numbered item to `PROJECT_NOTES.md` with what was done, how, and verification notes. Never delete/rewrite old items — the file is a chronology.

## Client communication rules
- Client writes requests in Roman-Urdu. **ALL UI text/labels must be English** (client explicitly said "urdu use ghalat").
- Client's preferred UI layout: **top-right corner action buttons**, **popup modals** for forms, **all entries in a table on the same page**, **search bar** for filtering, **print button**. Avoid separate detail views — put sections on one page.
- Print convention (client cares a lot): professional letterhead via `.report-sheet` + `d-none d-print-block`, screen-only elements `d-print-none`/`no-print`, `window.print()`, `@page` A4 (landscape for wide tables) — see `modules/sales/invoices.php`/`customers.php` as the reference pattern.

## Environment (Windows + XAMPP)
- Project root: `C:\xampp\htdocs\mehboob_traders` — served at `http://localhost/mehboob_traders/`
- PHP: `C:\xampp\php\php.exe` (PHP 8.0.30, ZTS), MySQL: `C:\xampp\mysql\bin\mysql.exe` (user `root`, empty password, DB `mehboob_traders`)
- DB config in `config/db.php`. There IS a git repo here now (existing commits), but **never commit/push unless the client explicitly asks**.
- Login: Admin `admin`/`admin123` (seeded via `seed_admin.sql`); order booker `mali`/`1234`. `users.password` is stored **PLAINTEXT** — login.php compares `$password === $user['password']`, so new employee logins are stored raw, never hashed.

## Verification workflow (no test framework exists)
1. Lint changed files: `& "C:\xampp\php\php.exe" -l <file>`
2. Login + render check (cookies to a temp jar under `C:\Users\GNG\AppData\Local\Temp\opencode`):
   ```
   curl.exe -s -c $jar -d "username=admin&password=admin123" -X POST http://localhost/mehboob_traders/login.php -o NUL
   curl.exe -s -b $jar http://localhost/mehboob_traders/<page> -o <tmp>
   ```
3. DB checks: `& "C:\xampp\mysql\bin\mysql.exe" -u root mehboob_traders -e "<SQL>"`
4. End-to-end rule: create test data → verify → **delete the test data AND revert every side effect** before finishing. Test rows change stock, customer/supplier balances, `cash_book`, `cash_book_daily`, `activity_logs` — restore all of them (recompute daily rows with `recomputeCashDayTotals()`/`recomputeCashDailyFrom()` from `includes/functions.php`). Never leave test rows in the client's DB.
5. Inline JS in these pages is routinely validated by extracting `<script>` bodies and running `node --check`.
6. **Stale-page/cache gotcha**: XAMPP serves no cache headers and browsers heuristically cache `.php` output. `includes/auth.php` sends `Cache-Control: no-store`. `style.css` is version-query-ed (currently `?v=11` in `includes/header.php`) and `main.js` (`?v=2` in `includes/footer.php`). After ANY CSS/JS change, bump the `?v=` — if the client reports "old version still showing", tell them to hard-refresh (Ctrl+F5).
7. **Autocomplete pattern** (used everywhere): `.ac-wrap/.ac-list/.ac-item` CSS in style.css, 250ms debounce, suggestions bind `mousedown click` on `.ac-item`, Enter picks first result, hidden `<input type="hidden">` id + submit-guard error when not picked from suggestions. If debugging DOM/click behavior, headless Edge (`--headless --dump-dom`) over a throwaway page in web root (DELETED after) reproduces real browser behavior; jsdom does NOT reliably fire jQuery ready events.

## Architecture
- Flat PHP, no framework. PDO only (prepared statements everywhere). Bootstrap 4.6.2 / jQuery 3.6 / Font Awesome 5.15.3 / flatpickr via CDN.
- Layout via `includes/header.php` (sidebar + topbar + flash messages) and `includes/footer.php`; session guard in `includes/auth.php`; helpers in `includes/functions.php`. `BASE_URL` is computed at runtime; always prefix links/redirects with `$base_url`.
- Guards: `requireRole([...])` (admin-only default) redirects absolutely to `index.php`. Role helpers (functions.php): `isAdmin()`, `isSalesTeam()` (= **only** role `order_booker`), `currentUserAreas($pdo)` (logged-in booker's assigned areas array, null for admin), `currentUserArea($pdo)` (comma-string of same), `allKnownAreas($pdo)`, `currentBranchId($pdo)`. `salesManager()` / `salesmanArea()` no longer exist — do not use.
- **Login model**: ONLY `admin` and `order_booker` roles have `users` rows. `salesman`/`loader` are `employees.employee_type` values with `employees.user_id = NULL` (they work from prints). `modules/employees/create|edit.php` create a login ONLY when employee_type = order_booker; changing an employee to salesman/loader DELETES the linked user. Areas live in the `areas` table; employees/customers store comma-separated area names matched case-insensitively (`LOWER(area)=LOWER(?)` — "johar town" must match "Johar Town"). Order bookers are strictly data-isolated: sales scoped by `created_by = $_SESSION['user_id']` on every sales/report/dashboard page.
- **Carton vs Box (the #1 unit gotcha)**: `products.purchase_price` and `sale_price` are **PER CARTON**; `boxes_per_carton` = carton size; stock and all sale/purchase quantities are in **BOXES**. Per-box rate = price / bpc; cost = `purchase_price * qty / GREATEST(boxes_per_carton,1)`. Never display `products.unit` (stored 'carton') next to a box count — show "N Boxes (X Cartons + Y Boxes)" instead. Purchases store per-box effective price in `purchase_items.purchase_price` (`qty×price` must equal subtotal).
- **Order flow**: Take Order (`modules/sales/index.php`) is credit-only (payment_method locked), area-cards first, salesman assigned via `sales.salesman_id` for delivery; records a normal credit sale that deducts stock. `sales.payment_method` ENUM = cash/bank/credit (default credit). `modules/sales/packlist.php` ("Delivery List") is the printed delivery sheet (per-customer voucher blocks, multi-rate rows, `[ ]` loaded checkboxes).
- **Profit invariant** (item 88 in PROJECT_NOTES): `profit = actual manually-entered sale amount (Σ si.price×si.qty − discount) − purchase cost (Σ purchase_price/bpc × qty)`. Profit is shown on `dsr.php`, `order_booker_invoices.php`, `order_booker_summary.php`, `ajax_invoice_profit_breakdown.php` — but was **removed from `invoices.php`/`invoice.php` and the Delivery List at the client's request; do not add it back there**.
- Payments reconcile FIFO against invoices: customer receipts → `allocateReceiptsToSales()` (updates `sales.paid_amount`/`due_amount`, receipt row `customer_receipts.sale_id`), supplier payments → `syncSupplierPurchasePayments()` (`supplier_payments.purchase_id`). Never update a sale/purchase `paid_amount` or a party balance by hand — use these helpers + `updateCustomerBalance()`/`updateSupplierBalance()`.

## Non-obvious file locations
- `modules/inventory/` holds **both** product files (products/categories/brands) **and** supplier files (suppliers.php, supplier_view.php, supplier_edit.php, supplier_payment.php, supplier_delete.php). Do not look for a `modules/suppliers/` folder.
- Pay/Receive pages live in `modules/transactions/` (`pay_supplier.php`, `receive_customer.php`) + all their `ajax_*` endpoints (customer/supplier search + balance, customer invoices, supplier purchase-due). They appear INSIDE the Suppliers/Customers sidebar dropdowns ("Pay Amount"), not as top-level menu items.
- `modules/customers/customer_receipt.php` is the customer receipt processing script (aliased from the receive page).
- Sales module has many pages: `index.php` (Take Order), `invoices.php`, `invoice.php` (print), `dsr.php` (Daily Sales Report — standalone nav item in the "Others" sidebar section, deliberately NOT inside the Sales dropdown per client), `packlist.php` (Delivery List), `order_booker_invoices.php`, `customer_summary.php`, `order_booker_summary.php`, `ajax_invoice_profit_breakdown.php` + per-purpose `ajax_*_search.php`.
- `modules/areas/index.php` = Areas/Territories management (`ajax_area_search.php` powers the suggestion datalists/completes). `modules/reports/` was **deleted** (DSR replaced it in the sidebar) — don't recreate or reference it.
- Sidebar active-state is driven by `str_contains($_SERVER['PHP_SELF'], ...)` checks in `includes/header.php` — new pages must add their own nav item + active check.

## Data safety gotchas
- `customer_delete.php` / `supplier_delete.php` / `product_delete.php` / purchase & sale deletes only block when the record has transaction HISTORY (sales/receipts/purchases/payments/items). Records with only an opening balance are NOT protected — an earlier session accidentally deleted the client's "Ahmad"/"ALi" records this way (restorable via `activity_logs`). Test delete-paths only on throwaway records.
- Any schema change made live must be mirrored in `database_schema.sql` (and vice versa). This DB also has real client data — never drop/truncate tables; revert test changes precisely.