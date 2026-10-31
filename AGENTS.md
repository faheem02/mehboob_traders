# AGENTS.md

## START HERE: project memory
- Read `PROJECT_NOTES.md` FIRST every session. It is the persistent memory (requirements, decisions, session resumes, gotchas); the numbered items are the canonical history, currently through ~#200. Append a new numbered item after every client change (what, how, verification); never rewrite or delete old items.
- The client uses this app live while work is happening. Re-read the current DB state before snapshotting/reverting, never assume row ids are stable, and exercise write/delete/refund flows only on throwaway records.
- There is no test framework, CI, or `opencode.json`; verification is the manual workflow below.

## Client communication & UI rules
- Client writes requests in Roman-Urdu. **ALL UI text/labels must be English** (explicit instruction: "urdu use ghalat").
- Preferred layout: **top-right action buttons**, **popup modals** for forms, **everything in a table on the same page**, **search bar** for filtering, **print button**. Avoid separate detail views — keep sections on one page.
- Print convention (client cares a lot): `.report-sheet` letterhead + `d-none d-print-block`, screen-only `d-print-none`/`no-print`, `window.print()`, `@page` A4 (landscape for wide tables). Reference: `modules/sales/invoices.php`, `modules/customers/customers.php`.
- `assets/css/style.css` caps `.font-weight-bold` at 600; use the `.font-weight-heavy` utility when 700 is genuinely needed (printed names).

## Environment (Windows + XAMPP)
- Project root `C:\xampp\htdocs\mehboob_traders`, served at `http://localhost/mehboob_traders/`.
- PHP `C:\xampp\php\php.exe` (8.2.12, ZTS); MySQL `C:\xampp\mysql\bin\mysql.exe` (user `root`, empty password, **DB `mehboob_live`** — that is what `config/db.php` uses). A stale copy named `mehboob_traders` also exists; do NOT test against it.
- Git repo exists, but **never commit/push unless the client explicitly asks**.
- Login: admin `admin`/`admin123` (from `seed_admin.sql`); order booker test account `mali`/`1234`. `users.password` is **PLAINTEXT** — `login.php` compares `$password === $user['password']`; never hash new logins.

## Verification workflow (no test framework)
1. Lint changed files: `& "C:\xampp\php\php.exe" -l <file>`
2. Login + render check (cookie jar under `C:\Users\fahee\AppData\Local\Temp\opencode`):
   ```
   curl.exe -s -c $jar -d "username=admin&password=admin123" -X POST http://localhost/mehboob_traders/login.php -o NUL
   curl.exe -s -b $jar http://localhost/mehboob_traders/<page> -o <tmp>
   ```
3. DB checks: `& "C:\xampp\mysql\bin\mysql.exe" -u root mehboob_live -e "<SQL>"`
4. End-to-end: create test data → verify → **delete it and revert every side effect** (stock, customer/supplier balances, `cash_book`, `cash_book_daily`, `bank_transactions`, `bank_accounts.current_balance`, `activity_logs`). Cash rows can be recomputed with `recomputeCashDayTotals()` / `recomputeCashDailyFrom()` from `includes/functions.php`. Never leave test rows in the client's DB.
5. Validate inline JS by extracting `<script>` bodies and running `node --check`.
6. **Cache gotcha**: XAMPP sends no cache headers and browsers heuristically cache `.php` output. `includes/auth.php` sends `Cache-Control: no-store`. `style.css` is versioned (currently `?v=19` in `includes/header.php`), `main.js` is `?v=2` in `includes/footer.php`. Bump `?v=` after ANY CSS/JS change; if the client sees an old version, tell them Ctrl+F5.
7. **Autocomplete pattern** (used across the app): `.ac-wrap`/`.ac-list`/`.ac-item` CSS, 250ms debounce, `mousedown click` binding, Enter picks first result, hidden input id + submit guard. For real DOM/click debugging use headless Edge (`msedge.exe --headless --dump-dom`) on a throwaway page (delete it after); jsdom does not reliably fire jQuery ready events.

## Architecture
- Flat PHP, no framework. PDO with prepared statements only. Bootstrap 4.6.2 / jQuery 3.6 / Font Awesome 5.15.3 / flatpickr / Chart.js via CDN.
- Layout via `includes/header.php` (sidebar, topbar, flash messages) and `includes/footer.php`; session guard in `includes/auth.php`; helpers in `includes/functions.php`. `BASE_URL` is computed at runtime — always prefix links/redirects with `$base_url`.
- Guards: `requireRole([...])` (default admin) redirects absolutely to `index.php` with a flash error (guarded POSTs therefore answer 302). Helpers: `isAdmin()`, `isSalesTeam()` (= only role `order_booker`), `isEmployee()`, `roleLabel()`, `normalizeIdList()`, `currentUserAreas($pdo)`, `currentUserArea($pdo)`, `allKnownAreas($pdo)`, `currentBranchId($pdo)`. Never call the removed `salesManager()`/`salesmanArea()`.
- **Login model**: only roles `admin` and `order_booker` have `users` rows. `salesman`/`loader` are employee types without logins (they work from prints). Employee types now live in the `employee_types` table (dynamic, `allEmployeeTypes($pdo)`; `employees.employee_type` is a VARCHAR key, not an ENUM). `modules/employees/create|edit.php` create a login only for `order_booker`; other types get none, and changing type removes/deactivates the linked login via `removeEmployeeLogin()` (deactivated instead of deleted when the user has transactions). `modules/employees/login_accounts.php` (admin) cleans up orphan logins.
- **Silent write on every authenticated page**: `includes/auth.php` calls `syncOrderBookerEmployees($pdo)`, which inserts a missing `employees` row for any `order_booker` login (idempotent, logs only when it repairs). Do not add more auto-writes of this kind; check it when employee/user counts change unexpectedly.
- **Areas**: employees and customers store comma-separated area names matched case-insensitively (`LOWER(area)=LOWER(?)`). `currentUserAreas()` returns `null` for admin and an array for a booker — an empty array intentionally blocks data (blank pages) until the admin assigns areas. `allKnownAreas()` excludes the pseudo-area `Counter`. Order bookers are strictly isolated: sales/reports/dashboard filter by `created_by = $_SESSION['user_id']` (or scoped by their areas).
- **Carton vs Box (the #1 unit gotcha)**: `products.purchase_price` and `sale_price` are **PER CARTON**; `boxes_per_carton` is the carton size; stock and all sale/purchase quantities are in **BOXES**. Per-box rate = price / bpc; cost = `purchase_price * qty / GREATEST(boxes_per_carton,1)`. Never show `products.unit` (stored 'carton') next to a box count — show "N Boxes (X Cartons + Y Boxes)". Purchases store the per-box effective price in `purchase_items.purchase_price` (`qty × price` must equal the line subtotal).
- **Sales flows**: Take Order (`modules/sales/index.php`) has two paths — shop order (`#orderForm`, **credit only**) and Counter Sale (`#counterSaleForm`, cash/bank/credit, auto-creates a customer with `area = 'Counter'`). The Invoices action column has **Receive** (only when `due_amount > 0`) and an admin-only **Refund** modal that posts to `ajax_sale_return_refund.php`; returns are listed in `modules/sales/sale_returns.php` (edit/print endpoints admin-only). Sale/purchase deletes fully reverse stock, cash/bank ledger rows and linked receipts.
- **Profit invariant** (item 88): profit = manually entered sale amount (Σ `si.price×si.qty` − discount) − cost. Cost uses the snapshot in `sale_items.cost_price` (per box, frozen at sale time), falling back to `purchase_price / boxes_per_carton` for older rows. Profit appears on `dsr.php`, `order_booker_invoices.php`, `order_booker_summary.php`, `ajax_invoice_profit_breakdown.php`; it was deliberately removed from `invoices.php`, `invoice.php` and the Delivery List — do not add it back there.
- Payments reconcile FIFO: customer receipts → `allocateReceiptsToSales()` + `syncCustomerSalesPayments()`; supplier payments → `syncSupplierPurchasePayments()`. Never hand-edit `paid_amount`/`due_amount` or a party balance — call these helpers plus `updateCustomerBalance()`/`updateSupplierBalance()`. Credit-note returns (`Credit Note`, `Deduct Balance`, `Store Credit`, `Adjust / Deduct Customer Balance`) reduce the customer balance inside `updateCustomerBalance()`; cash/bank refunds are outflows.
- Order-booker filters are multi-value (`order_booker_id[]`) on `invoices.php` and are forwarded to `packlist.php` / `customer_summary.php`; use `normalizeIdList()` and never mix the scalar and array forms in one query string (`parse_str` keeps only the last).
- Dashboard Monthly/Annual Revenue is periodized by `COALESCE(delivery_date, sale_date)` and excludes cancelled sales; order bookers see only their own.
- Print restrictions the client asked for: `invoice.php` refuses to print when `due_amount <= 0` (shows a Paid badge instead), and the `invoices.php` print view hides fully paid rows (`.invoice-paid`). The Delivery List (`packlist.php`) is always a single-day sheet.

## Non-obvious file locations
- `modules/inventory/` holds **both** product files (products/categories/brands, `stock_closing.php`, `low_stock.php`) **and** supplier files (`suppliers.php`, `supplier_view.php`, `supplier_edit.php`, `supplier_payment.php`, `supplier_delete.php`, admin-only `supplier_report.php`). There is no `modules/suppliers/`.
- Pay/Receive pages live in `modules/transactions/` (`pay_supplier.php`, `receive_customer.php`) with their `ajax_*` endpoints; they appear inside the Suppliers/Customers sidebar dropdowns as "Pay Amount", not as top-level items. `modules/customers/customer_receipt.php` is the receipt processing script.
- Sales module pages: `index.php`, `invoices.php`, `invoice.php` (single print), `sale_returns.php`, `sale_return_edit.php`, `print_return.php`, `total_sale_invoices.php`, `dsr.php` (Daily Sales Report — standalone nav item in "Others", deliberately outside the Sales dropdown), `packlist.php` (Delivery List), `order_booker_invoices.php` / `order_booker_summary.php` (admin), `customer_summary.php`, `sale_edit.php` / `sale_delete.php`.
- `modules/employees/`: employee CRUD, `login_accounts.php` (+ `login_toggle.php` / `login_delete.php`), `salary.php` (Pay Salary) with `salary_ledger.php`, `salary_details.php`, `salary_slip.php`, and `employee_salaries` (slip no from `generateSalaryNo()`).
- `modules/areas/index.php` = Areas/Territories (`ajax_area_search.php` powers suggestions). `modules/reports/` was deleted — do not recreate it.
- Sidebar active state is driven by `str_contains($_SERVER['PHP_SELF'], ...)` checks in `includes/header.php`; new pages must add their nav item and active check (admin-only sections are wrapped in `isAdmin()`).

## Data safety gotchas
- `customer_delete.php` is now a **soft delete** (`customers.status = 0`); archived customers are visible under the Inactive tab and restored by `customer_restore.php`. Suppliers and products are still hard-deleted, but blocked when purchase/sale/payment history exists. Purchases and sales are hard-deleted with full reversal — never test these on real records.
- Any schema change made live must be mirrored in `database_schema.sql` (and vice versa). The live DB has real client data — never drop/truncate tables and revert test changes exactly.
- `recordCashInflow()`/`recordCashOutflow()` already call `recomputeCashDailyFrom()`, so normal money paths keep the daily opening/closing chain correct. If you delete/edit cash rows directly in SQL, recompute the chain yourself.
