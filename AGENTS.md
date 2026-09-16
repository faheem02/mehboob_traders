# AGENTS.md

## START HERE: project memory
- Read `PROJECT_NOTES.md` FIRST on every session. It is the persistent memory: full requirements, build order, decisions log, and a "SESSION RESUME" section that records what was just done and the workflow rules.
- After completing any client change, append a short numbered item (`#24`, `#25`, ...) to `PROJECT_NOTES.md` with what was done, how, and verification notes.

## Client communication rules
- Client writes requests in Roman-Urdu. **ALL UI text/labels must be English** (client explicitly said "urdu use ghalat").
- Client's preferred UI layout: peer-centered, **top-right corner action buttons**, **popup modals** for forms, **all entries listed in a table on the same page**, search bar for filtering, print button. Avoid separate detail views — put sections on one page.

## Environment (Windows + XAMPP)
- Project root: `C:\xampp\htdocs\mehboob_traders` — served at `http://localhost/mehboob_traders/`
- PHP: `C:\xampp\php\php.exe` (PHP 8.0.30, ZTS)
- MySQL: `C:\xampp\mysql\bin\mysql.exe` (user `root`, empty password, DB `mehboob_traders`)
- No git repo here — **never commit/push unless the client explicitly asks**.
- DB config in `config/db.php` (localhost/root/no-pw, db `mehboob_traders`).

## Verification workflow (no test framework exists)
1. Lint changed files:
   `& "C:\xampp\php\php.exe" -l <file>`
2. Login + render check (cookies to a temp jar):
   ```
   curl.exe -s -c $jar -d "username=admin&password=admin123" -X POST http://localhost/mehboob_traders/login.php -o NUL
   curl.exe -s -b $jar http://localhost/mehboob_traders/<page> -o <tmp>
   ```
   Use `C:\Users\GNG\AppData\Local\Temp\opencode` for temp files/jars.
3. DB checks: `& "C:\xampp\mysql\bin\mysql.exe" -u root mehboob_traders -e "<SQL>"`
4. End-to-end rule: create test data → verify → **delete the test data** afterward. Never leave test rows in the client's DB.
5. **Stale-page/cache gotcha**: XAMPP PHP pages are heuristically cached by browsers (no cache headers), and `style.css`/`main.js` are version-query-ed (`style.css?v=3` in `header.php`, `main.js?v=2` in `footer.php`). `includes/auth.php` already sends `Cache-Control: no-store`. If a client reports "old version still showing / change not visible", have them hard-refresh (Ctrl+F5) — and after any CSS/JS change, bump the `?v=` in `includes/header.php`/`footer.php`.
6. **Autocomplete JS**: `modules/purchases/create.php` + `modules/purchases/ajax_search.php` (search bars w/ suggestions). Selection binds `mousedown click` on `.ac-item` and Enter picks first result. If debugging DOM/click behavior, a headless-Chromium probe (Edge `--headless --dump-dom`) over a throwaway page in web root (DELETED after) reproduces real browser behavior; jsdom does NOT reliably fire jQuery ready events.

## Architecture
- Flat PHP, no framework. PDO only (prepared statements). Bootstrap 4.6.2 / jQuery 3.6 / Font Awesome 5.15.3 via CDN.
- Layout via `includes/header.php` (sidebar + topbar + flash messages) and `includes/footer.php`; session guard in `includes/auth.php`; helpers in `includes/functions.php`.
- `BASE_URL` is computed at runtime (works in subfolder). Always prefix links/redirects with `$base_url`. Use absolute redirects in guards: `requireRole([...])` (functions.php) redirects to `index.php`.
- Roles: **only `admin` and `order_booker` have user accounts**. `salesman`/`loader` stay as `employees.employee_type` values but those employees must have NO `users` row (they work from printed Delivery Lists). `modules/employees/create|edit.php` auto-create/delete the linked login based on employee type. Use `isAdmin()`, `isSalesTeam()` (= order_booker only), `salesManager(...)` helpers. Order takers take CREDIT-ONLY orders in `modules/sales/index.php` ("Take Order", mobile, area-first, order taker's areas from `employees.area` comma-separated, matched case-insensitively)` and assign a Salesman (`sales.salesman_id` → `employees.id`) to deliver; `modules/sales/packlist.php` = area-wise printed Delivery List. NOTE: area matching is enforced case-insensitive (`LOWER(area)=LOWER(?)`) — customer areas like "johar town" vs area table "Johar Town" must meet. Commercial data ("take order", "delivery list") is entered by whichever login is at the shop — the Order Booker's own username.

## Non-obvious file locations
- `modules/inventory/` holds **both** product files (products/categories/brands) **and** supplier files (suppliers.php, supplier_view.php, supplier_edit.php, supplier_payment.php, supplier_delete.php). Do not look for a `modules/suppliers/` folder.
- Pay/Receive pages live in `modules/transactions/` (`pay_supplier.php`, `receive_customer.php`) and are listed INSIDE the Suppliers/Customers sidebar dropdowns, not as top-level menu items.
- `modules/customers/customer_receipt.php` is the customer receipt processing script.

## Data safety gotchas
- `customer_delete.php` / `supplier_delete.php` only block deletion when the record has transaction HISTORY (sales/receipts/purchases/payments). Records with only an opening balance are NOT protected — be careful during delete-path tests (an earlier session accidentally deleted the client's "Ahmad"/"ALi" records this way; restorable via `activity_logs`).
- Any schema change made live must be mirrored in `database_schema.sql` (and vice versa).

## Login
- Admin: `admin` / `admin123` (seeded via `seed_admin.sql`)
- Order Booker: `mali` / `1234` (Muhammad Ali — the only employee login; salesman-type employees have NO login).