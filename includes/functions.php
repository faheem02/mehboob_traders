<?php
require_once __DIR__ . '/../config/db.php';

// Dynamic Base URL detection for root or subfolder installation
if (!isset($base_url)) {
    $script_file = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
    $app_root = str_replace('\\', '/', dirname(__DIR__));
    $script_name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    $app_len = strlen($app_root);
    if ($script_file && $script_name && stripos($script_file, $app_root) === 0) {
        $rel = ltrim(substr($script_file, $app_len), '/');
        $base_url = substr($script_name, 0, strlen($script_name) - strlen($rel));
    } else {
        $parts = explode('/', trim($script_name, '/'));
        $base_url = !empty($parts[0]) ? '/' . $parts[0] . '/' : '/';
    }
    if (substr($base_url, -1) !== '/') {
        $base_url .= '/';
    }
}
$GLOBALS['base_url'] = $base_url;
if (!defined('BASE_URL')) {
    define('BASE_URL', $base_url);
}

// Get all records from a table
function getAll($table, $order = 'id DESC') {
    global $pdo;
    $stmt = $pdo->query("SELECT * FROM $table ORDER BY $order");
    return $stmt->fetchAll();
}

// Get single record by ID
function getById($table, $id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// Get records with a condition
function getWhere($table, $column, $value, $order = 'id DESC') {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE $column = ? ORDER BY $order");
    $stmt->execute([$value]);
    return $stmt->fetchAll();
}

// Insert and return last ID
function insert($table, $data) {
    global $pdo;
    $columns = implode(', ', array_keys($data));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));
    $stmt = $pdo->prepare("INSERT INTO $table ($columns) VALUES ($placeholders)");
    $stmt->execute(array_values($data));
    return $pdo->lastInsertId();
}

// Get valid current branch ID (or null if not exists)
function currentBranchId($pdo) {
    $bid = !empty($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 1;
    if ($bid) {
        $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ?");
        $stmt->execute([$bid]);
        if ($stmt->fetchColumn()) return $bid;
    }
    return null;
}

// Update record
function update($table, $data, $id) {
    global $pdo;
    $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($data)));
    $stmt = $pdo->prepare("UPDATE $table SET $sets WHERE id = ?");
    $stmt->execute([...array_values($data), $id]);
    return $stmt->rowCount();
}

// Delete record
function delete($table, $id) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->rowCount();
}

// Count records
function countRows($table, $column = null, $value = null) {
    global $pdo;
    if ($column && $value !== null) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $column = ?");
        $stmt->execute([$value]);
    } else {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
    }
    return $stmt->fetchColumn();
}

// ===== EMPLOYEE <-> LOGIN ACCOUNT LINKING =====
// Only `admin` and `order_booker` have users rows. Some legacy/edge rows were created
// with employees.user_id = NULL while a matching login still existed, which left the
// login visible in every order-booker dropdown after the employee was deleted.
// findEmployeeLogin() resolves the login by user_id first, then falls back to a
// name match against ORPHAN logins (users with no employee row) so cleanup never misses.
function findEmployeeLogin($pdo, $employee) {
    if (!empty($employee['user_id'])) {
        $st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $st->execute([(int)$employee['user_id']]);
        if ($u = $st->fetch()) return $u;
    }
    if (empty($employee['full_name'])) return null;
    $st = $pdo->prepare("SELECT u.* FROM users u
                         LEFT JOIN employees e ON e.user_id = u.id
                         WHERE e.id IS NULL
                           AND u.role <> 'admin'
                           AND LOWER(TRIM(u.full_name)) = LOWER(TRIM(?))
                         ORDER BY u.id DESC LIMIT 1");
    $st->execute([$employee['full_name']]);
    return $st->fetch() ?: null;
}

// Count everything that references a user, so a login with history is never hard-deleted
function userTransactionCount($pdo, $user_id) {
    $st = $pdo->prepare("SELECT
        (SELECT COUNT(*) FROM sales WHERE created_by = ?) +
        (SELECT COUNT(*) FROM purchases WHERE created_by = ?) +
        (SELECT COUNT(*) FROM expenses WHERE created_by = ?) +
        (SELECT COUNT(*) FROM customer_receipts WHERE created_by = ?) +
        (SELECT COUNT(*) FROM supplier_payments WHERE created_by = ?) +
        (SELECT COUNT(*) FROM cash_book WHERE created_by = ?) +
        (SELECT COUNT(*) FROM bank_transactions WHERE created_by = ?) +
        (SELECT COUNT(*) FROM customers WHERE created_by = ?) +
        (SELECT COUNT(*) FROM employee_salaries WHERE created_by = ?) +
        (SELECT COUNT(*) FROM activity_logs WHERE user_id = ?)");
    $st->execute(array_fill(0, 10, (int)$user_id));
    return (int)$st->fetchColumn();
}

// Remove (or deactivate) an employee's login account so it disappears from every
// order-booker dropdown and can no longer sign in.
// Returns ['action' => 'deleted'|'deactivated'|'kept'|'none', 'message' => '...'].
function removeEmployeeLogin($pdo, $employee) {
    $user = findEmployeeLogin($pdo, $employee);
    if (!$user) return ['action' => 'none', 'message' => ''];

    $uname = $user['username'];
    if ($user['role'] === 'admin') {
        return ['action' => 'kept', 'message' => 'Admin login "' . $uname . '" was kept.'];
    }

    $tx = userTransactionCount($pdo, $user['id']);
    if ($tx > 0) {
        $pdo->prepare("UPDATE users SET status = 0, updated_at = ? WHERE id = ?")
            ->execute([date('Y-m-d'), $user['id']]);
        return ['action' => 'deactivated', 'message' =>
            'Login "' . $uname . '" has ' . $tx . ' past transaction(s), so it was DEACTIVATED instead of deleted (history kept).'];
    }

    delete('users', $user['id']);
    return ['action' => 'deleted', 'message' => 'Login "' . $uname . '" was deleted.'];
}

// Helper to generate next sequential unique reference number (prevents duplicate collisions)
function generateNextCode($table, $column, $prefix, $digits = 3) {
    global $pdo;
    $start_pos = (int)(strlen($prefix) + 1);
    $stmt = $pdo->prepare("SELECT $column FROM $table WHERE $column LIKE ? ORDER BY CAST(SUBSTRING($column, $start_pos) AS UNSIGNED) DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $row = $stmt->fetch();
    $max = 0;
    if ($row && !empty($row[$column])) {
        $max = (int)substr($row[$column], strlen($prefix));
    }
    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $column LIKE ?");
    $cStmt->execute([$prefix . '%']);
    $count = (int)$cStmt->fetchColumn();
    $next = max($max, $count) + 1;

    $chk = $pdo->prepare("SELECT id FROM $table WHERE $column = ?");
    do {
        $candidate = $prefix . str_pad($next, $digits, '0', STR_PAD_LEFT);
        $chk->execute([$candidate]);
        if ($chk->fetch()) {
            $next++;
        } else {
            return $candidate;
        }
    } while (true);
}

// Generate purchase invoice number (PUR-yymmdd-###)
function generatePurchaseNo() {
    return generateNextCode('purchases', 'invoice_no', 'PUR-' . date('ymd') . '-', 3);
}

// Generate sale invoice number (INV-yymmdd-###)
function generateSaleNo() {
    return generateNextCode('sales', 'invoice_no', 'INV-' . date('ymd') . '-', 3);
}

// Generate customer number (CUS-yymm-####)
function generateCustomerNo() {
    return generateNextCode('customers', 'customer_no', 'CUS-' . date('ym') . '-', 4);
}

// Generate salary slip number (SAL-yymmdd-###)
function generateSalaryNo() {
    return generateNextCode('employee_salaries', 'slip_no', 'SAL-' . date('ymd') . '-', 3);
}

// Generate product item code (PRD-###)
function generateProductCode() {
    return generateNextCode('products', 'code', 'PRD-', 3);
}

// Generate employee code (sequential: EMP-001, EMP-002, ... keeps increasing after deletes)
function generateEmployeeCode() {
    return generateNextCode('employees', 'emp_code', 'EMP-', 3);
}

// Generate expense voucher number (EXP-yymmdd-###)
function generateExpenseNo() {
    return generateNextCode('expenses', 'voucher_no', 'EXP-' . date('ymd') . '-', 3);
}

// Format currency
function formatCurrency($amount) {
    return number_format($amount, 2);
}

// Format date
function formatDate($date) {
    return $date ? date('d-m-Y', strtotime($date)) : '-';
}

// Update supplier live balance
// Sign: positive = we owe supplier, negative = supplier owes us
// Invariant: balance = opening + total due on open purchases
//            − general payments (payments NOT linked to a purchase invoice).
// Payments linked to a purchase invoice are already reflected in that purchase's
// paid_amount/due_amount via syncSupplierPurchasePayments(), so they are NOT
// subtracted here again (that would double-count).
function updateSupplierBalance($pdo, $supplier_id) {
    if (!$supplier_id) return;
    $duestmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount - paid_amount),0)
        FROM purchases WHERE supplier_id = ? AND status <> 'cancelled'
    ");
    $duestmt->execute([$supplier_id]);
    $due = (float)$duestmt->fetchColumn();
    $paid = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE supplier_id = ? AND purchase_id IS NULL");
    $paid->execute([$supplier_id]);
    $paid = (float)$paid->fetchColumn();
    $s = getById('suppliers', $supplier_id);
    $opening = (float)($s['opening_balance'] ?? 0) + (float)($s['adjustment'] ?? 0);
    $balance = $opening + $due - $paid;
    $pdo->prepare("UPDATE suppliers SET current_balance = ? WHERE id = ?")->execute([$balance, $supplier_id]);
    return $balance;
}

// Allocate supplier payments to that supplier's purchase invoices.
// - Payments explicitly tagged with a purchase_id stay on their own invoice.
// - General payments (purchase_id NULL, e.g. excess / advance / old payments) are
//   applied FIFO to the oldest open purchase invoices with remaining due.
// - Each purchase's paid_amount/due_amount/status is recomputed so the purchase
//   list shows the true per-invoice settlement. Idempotent: the initial paid at
//   purchase time is read from the cash_book outflow rows (reference_type = 'purchase').
//   General payments falling on a purchase get re-tagged with that purchase_id so
//   updateSupplierBalance() never double-counts them.
function syncSupplierPurchasePayments($pdo, $supplier_id) {
    if (!$supplier_id) return;

    $ps = $pdo->prepare("SELECT id, total_amount FROM purchases WHERE supplier_id = ? AND status <> 'cancelled' ORDER BY purchase_date ASC, id ASC");
    $ps->execute([$supplier_id]);
    $purchases = $ps->fetchAll();
    if (!$purchases) return;

    $totals = [];
    $initial_paid = [];
    foreach ($purchases as $p) {
        $totals[(int)$p['id']] = (float)$p['total_amount'];
        $ip = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE reference_type = 'purchase' AND reference_id = ? AND transaction_type = 'outflow'");
        $ip->execute([(int)$p['id']]);
        $initial_paid[(int)$p['id']] = (float)$ip->fetchColumn();
    }

    $alloc = [];
    $general = [];
    $q = $pdo->prepare("SELECT id, amount, purchase_id FROM supplier_payments WHERE supplier_id = ? ORDER BY payment_date ASC, id ASC");
    $q->execute([$supplier_id]);
    foreach ($q->fetchAll() as $row) {
        $pid = (int)($row['purchase_id'] ?? 0);
        $amt = (float)$row['amount'];
        if ($pid && isset($totals[$pid])) {
            $alloc[$pid] = ($alloc[$pid] ?? 0) + $amt;
        } else {
            $general[] = ['id' => (int)$row['id'], 'amount' => $amt];
        }
    }

    $paid = [];
    $gi = 0;
    foreach ($purchases as $p) {
        $pid = (int)$p['id'];
        $total = $totals[$pid];
        $have = ($initial_paid[$pid] ?? 0) + ($alloc[$pid] ?? 0);
        while ($gi < count($general) && $have < $total - 0.001) {
            $take = min($total - $have, $general[$gi]['amount']);
            $have += $take;
            $general[$gi]['amount'] -= $take;
            $pdo->prepare("UPDATE supplier_payments SET purchase_id = ? WHERE id = ?")->execute([$pid, $general[$gi]['id']]);
            if ($general[$gi]['amount'] < 0.004) $gi++;
        }
        $paid[$pid] = [round(min($total, $have), 2), $total];
    }

    foreach ($purchases as $p) {
        $pid = (int)$p['id'];
        [$paid_amt, $total] = $paid[$pid];
        $due_amt = max(0, round($total - $paid_amt, 2));
        $status = $due_amt <= 0 ? 'completed' : 'received';
        $pdo->prepare("UPDATE purchases SET paid_amount = ?, due_amount = ?, status = ? WHERE id = ?")
            ->execute([$paid_amt, $due_amt, $status, $pid]);
    }
}

// Update customer live balance
// Sign: positive = customer owes us, negative = we owe customer
function updateCustomerBalance($pdo, $customer_id) {
    if (!$customer_id) return 0;
    $sales_stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount),0), COALESCE(SUM(initial_paid),0)
        FROM sales WHERE customer_id = ? AND status <> 'cancelled'
    ");
    $sales_stmt->execute([$customer_id]);
    [$total_sales, $initial_paid] = $sales_stmt->fetch(PDO::FETCH_NUM);

    $recv_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM customer_receipts WHERE customer_id = ?");
    $recv_stmt->execute([$customer_id]);
    $total_receipts = (float)$recv_stmt->fetchColumn();

    $c = getById('customers', $customer_id);
    $opening = (float)($c['opening_balance'] ?? 0);
    $balance = $opening + (float)$total_sales - (float)$initial_paid - $total_receipts;
    $pdo->prepare("UPDATE customers SET current_balance = ? WHERE id = ?")->execute([$balance, $customer_id]);
    return $balance;
}

// Sync/allocate payments to customer sales invoices
function syncCustomerSalesPayments($pdo, $customer_id) {
    if (!$customer_id) return;
    $sales = $pdo->prepare("SELECT id, total_amount, initial_paid FROM sales WHERE customer_id = ? AND status <> 'cancelled' ORDER BY sale_date ASC, id ASC");
    $sales->execute([$customer_id]);
    $all_sales = $sales->fetchAll();

    foreach ($all_sales as $s) {
        $sale_id = $s['id'];
        $r_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM customer_receipts WHERE sale_id = ?");
        $r_stmt->execute([$sale_id]);
        $receipts_paid = (float)$r_stmt->fetchColumn();

        $init_paid = (float)($s['initial_paid'] ?? 0);
        $total_paid = $init_paid + $receipts_paid;
        $total_amt = (float)$s['total_amount'];
        $paid_amt = min($total_amt, $total_paid);
        $due_amt = max(0, $total_amt - $paid_amt);
        $status = ($due_amt <= 0) ? 'completed' : 'active';

        $pdo->prepare("UPDATE sales SET paid_amount = ?, due_amount = ?, status = ? WHERE id = ?")
            ->execute([$paid_amt, $due_amt, $status, $sale_id]);
    }
}

// Allocate customer receipts to sales (alias for compatibility)
function allocateReceiptsToSales($pdo, $customer_id) {
    syncCustomerSalesPayments($pdo, $customer_id);
}

// Log activity
function logActivity($pdo, $action, $module, $reference_id = null, $description = null) {
    $uid = $_SESSION['user_id'] ?? null;
    insert('activity_logs', [
        'user_id' => $uid,
        'action' => $action,
        'module' => $module,
        'reference_id' => $reference_id,
        'description' => $description,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'created_at' => date('Y-m-d'),
    ]);
}

// Record a cash inflow in cash book
function recordCashInflow($pdo, $date, $amount, $description, $reference_type = null, $reference_id = null, $created_by = null) {
    $today = $date ?: date('Y-m-d');
    $daily = $pdo->prepare("SELECT * FROM cash_book_daily WHERE date = ?");
    $daily->execute([$today]);
    $daily_rec = $daily->fetch();

    if ($daily_rec) {
        $daily_id = $daily_rec['id'];
    } else {
        $prev = $pdo->query("SELECT closing_balance FROM cash_book_daily WHERE date < '$today' ORDER BY date DESC LIMIT 1")->fetch();
        $opening = $prev ? (float)$prev['closing_balance'] : 0;
        $daily_id = insert('cash_book_daily', [
            'date' => $today,
            'opening_balance' => $opening,
            'total_inflow' => 0,
            'total_outflow' => 0,
            'closing_balance' => $opening,
            'status' => 'open',
            'created_by' => $created_by ?: 1,
            'created_at' => date('Y-m-d'),
        ]);
    }

    insert('cash_book', [
        'daily_id' => $daily_id,
        'transaction_date' => $today,
        'transaction_type' => 'inflow',
        'amount' => $amount,
        'description' => $description,
        'reference_type' => $reference_type,
        'reference_id' => $reference_id,
        'created_by' => $created_by ?: 1,
        'created_at' => date('Y-m-d'),
    ]);

    $update = $pdo->prepare("UPDATE cash_book_daily SET total_inflow = total_inflow + ?, closing_balance = opening_balance + total_inflow - total_outflow WHERE id = ?");
    $update->execute([$amount, $daily_id]);

    // The UPDATE above only touches THIS day's closing_balance. Every later day's row still holds
    // the opening_balance it had before, so a back-dated entry would leave the daily chain broken
    // (next day's opening != this day's closing) and the running cash total would read too high.
    // Re-carry the balance forward from this date onward.
    recomputeCashDailyFrom($pdo, $today);
}

// Record a cash outflow
function recordCashOutflow($pdo, $date, $amount, $description, $reference_type = null, $reference_id = null, $created_by = null) {
    $today = $date ?: date('Y-m-d');
    $daily = $pdo->prepare("SELECT * FROM cash_book_daily WHERE date = ?");
    $daily->execute([$today]);
    $daily_rec = $daily->fetch();

    if ($daily_rec) {
        $daily_id = $daily_rec['id'];
    } else {
        $prev = $pdo->query("SELECT closing_balance FROM cash_book_daily WHERE date < '$today' ORDER BY date DESC LIMIT 1")->fetch();
        $opening = $prev ? (float)$prev['closing_balance'] : 0;
        $daily_id = insert('cash_book_daily', [
            'date' => $today,
            'opening_balance' => $opening,
            'total_inflow' => 0,
            'total_outflow' => 0,
            'closing_balance' => $opening,
            'status' => 'open',
            'created_by' => $created_by ?: 1,
            'created_at' => date('Y-m-d'),
        ]);
    }

    insert('cash_book', [
        'daily_id' => $daily_id,
        'transaction_date' => $today,
        'transaction_type' => 'outflow',
        'amount' => $amount,
        'description' => $description,
        'reference_type' => $reference_type,
        'reference_id' => $reference_id,
        'created_by' => $created_by ?: 1,
        'created_at' => date('Y-m-d'),
    ]);

    $update = $pdo->prepare("UPDATE cash_book_daily SET total_outflow = total_outflow + ?, closing_balance = opening_balance + total_inflow - total_outflow WHERE id = ?");
    $update->execute([$amount, $daily_id]);

    // See recordCashInflow(): re-carry the running balance into all later days.
    recomputeCashDailyFrom($pdo, $today);
}

// Record bank inflow (deposit)
function recordBankInflow($pdo, $date, $amount, $description, $reference_type = null, $reference_id = null, $created_by = null, $bank_account_id = null) {
    $account = resolveBankAccount($pdo, $bank_account_id, $date);
    insert('bank_transactions', [
        'bank_account_id' => $account['id'],
        'transaction_date' => $date,
        'transaction_type' => 'deposit',
        'amount' => $amount,
        'description' => $description,
        'reference_type' => $reference_type,
        'reference_id' => $reference_id,
        'created_by' => $created_by ?: 1,
        'created_at' => date('Y-m-d'),
    ]);
    $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$amount, $account['id']]);
    return $account['id'];
}

// Record bank outflow (withdrawal)
function recordBankOutflow($pdo, $date, $amount, $description, $reference_type = null, $reference_id = null, $created_by = null, $bank_account_id = null) {
    $account = resolveBankAccount($pdo, $bank_account_id, $date);
    insert('bank_transactions', [
        'bank_account_id' => $account['id'],
        'transaction_date' => $date,
        'transaction_type' => 'withdrawal',
        'amount' => $amount,
        'description' => $description,
        'reference_type' => $reference_type,
        'reference_id' => $reference_id,
        'created_by' => $created_by ?: 1,
        'created_at' => date('Y-m-d'),
    ]);
    $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$amount, $account['id']]);
    return $account['id'];
}

// Recompute a cash_book_daily row's inflow/outflow totals from its entries
function recomputeCashDayTotals($pdo, $daily_id) {
    $in = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE daily_id = ? AND transaction_type = 'inflow'");
    $in->execute([$daily_id]);
    $out = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE daily_id = ? AND transaction_type = 'outflow'");
    $out->execute([$daily_id]);
    $pdo->prepare("UPDATE cash_book_daily SET total_inflow = ?, total_outflow = ?, updated_at = ? WHERE id = ?")
        ->execute([(float)$in->fetchColumn(), (float)$out->fetchColumn(), date('Y-m-d'), $daily_id]);
}

// Recompute cash_book_daily running balances (opening/closing) from a date onward
function recomputeCashDailyFrom($pdo, $from_date) {
    $rows = $pdo->prepare("SELECT * FROM cash_book_daily WHERE date >= ? ORDER BY date ASC, id ASC");
    $rows->execute([$from_date]);
    $carry = null;
    foreach ($rows->fetchAll() as $r) {
        $opening = ($carry === null) ? (float)$r['opening_balance'] : $carry;
        $closing = $opening + (float)$r['total_inflow'] - (float)$r['total_outflow'];
        $pdo->prepare("UPDATE cash_book_daily SET opening_balance = ?, closing_balance = ?, updated_at = ? WHERE id = ?")
            ->execute([$opening, $closing, date('Y-m-d'), $r['id']]);
        $carry = $closing;
    }
}

// Reverse a salary payment's ledger effect (cash_book + bank_transactions) prior to edit/delete
function removeSalaryLedger($pdo, $salary_id) {
    $stmt = $pdo->prepare("SELECT id, daily_id, transaction_date FROM cash_book WHERE reference_type = 'salary' AND reference_id = ?");
    $stmt->execute([$salary_id]);
    $rows = $stmt->fetchAll();
    $min_date = null;
    $daily_ids = [];
    foreach ($rows as $r) {
        $pdo->prepare("DELETE FROM cash_book WHERE id = ?")->execute([$r['id']]);
        $daily_ids[] = $r['daily_id'];
        if ($min_date === null || $r['transaction_date'] < $min_date) $min_date = $r['transaction_date'];
    }
    foreach (array_unique($daily_ids) as $did) recomputeCashDayTotals($pdo, $did);
    if ($min_date) recomputeCashDailyFrom($pdo, $min_date);

    $btns = $pdo->prepare("SELECT * FROM bank_transactions WHERE reference_type = 'salary' AND reference_id = ?");
    $btns->execute([$salary_id]);
    foreach ($btns->fetchAll() as $b) {
        $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$b['amount'], $b['bank_account_id']]);
        $pdo->prepare("DELETE FROM bank_transactions WHERE id = ?")->execute([$b['id']]);
    }
}

// Reverse an expense's ledger effect (cash_book + bank_transactions) prior to edit/delete.
// Mirrors removeSalaryLedger(). Expenses only ever create OUTFLOWS, so the reversal always
// ADDS the money back. Callers must already be inside a transaction.
function removeExpenseLedger($pdo, $expense_id) {
    $stmt = $pdo->prepare("SELECT id, daily_id, transaction_date FROM cash_book WHERE reference_type = 'expense' AND reference_id = ?");
    $stmt->execute([$expense_id]);
    $rows = $stmt->fetchAll();
    $min_date = null;
    $daily_ids = [];
    foreach ($rows as $r) {
        $pdo->prepare("DELETE FROM cash_book WHERE id = ?")->execute([$r['id']]);
        $daily_ids[] = $r['daily_id'];
        if ($min_date === null || $r['transaction_date'] < $min_date) $min_date = $r['transaction_date'];
    }
    foreach (array_unique($daily_ids) as $did) recomputeCashDayTotals($pdo, $did);
    if ($min_date) recomputeCashDailyFrom($pdo, $min_date);

    $btns = $pdo->prepare("SELECT * FROM bank_transactions WHERE reference_type = 'expense' AND reference_id = ?");
    $btns->execute([$expense_id]);
    foreach ($btns->fetchAll() as $b) {
        $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$b['amount'], $b['bank_account_id']]);
        $pdo->prepare("DELETE FROM bank_transactions WHERE id = ?")->execute([$b['id']]);
    }
}

// Reverse a customer receipt's ledger effect (cash_book + bank_transactions) prior to edit/delete.
// Customer receipts create INFLOWS, so the reversal reduces bank balance and removes cash inflow.
// Callers must already be inside a transaction.
function removeCustomerReceiptLedger($pdo, $receipt) {
    $receipt_id = (int)$receipt['id'];
    $customer_id = (int)$receipt['customer_id'];
    $receipt_date = $receipt['receipt_date'];
    $amount = (float)$receipt['amount'];

    // 1. Cash book reversal: check reference_id = receipt_id first, then fallback to legacy customer_id match
    $stmt = $pdo->prepare("SELECT id, daily_id, transaction_date FROM cash_book 
        WHERE reference_type = 'customer_receipt' AND reference_id = ?");
    $stmt->execute([$receipt_id]);
    $cb = $stmt->fetch();
    if (!$cb) {
        $stmt = $pdo->prepare("SELECT id, daily_id, transaction_date FROM cash_book 
            WHERE reference_type = 'customer_receipt' AND reference_id = ? AND transaction_date = ? AND amount = ?
            ORDER BY id DESC LIMIT 1");
        $stmt->execute([$customer_id, $receipt_date, $amount]);
        $cb = $stmt->fetch();
    }
    if ($cb) {
        $pdo->prepare("DELETE FROM cash_book WHERE id = ?")->execute([$cb['id']]);
        recomputeCashDayTotals($pdo, $cb['daily_id']);
        recomputeCashDailyFrom($pdo, $cb['transaction_date']);
    }

    // 2. Bank transaction reversal: check reference_id = receipt_id first, then fallback to legacy customer_id match
    $stmt = $pdo->prepare("SELECT id, bank_account_id, amount FROM bank_transactions 
        WHERE reference_type = 'customer_receipt' AND reference_id = ?");
    $stmt->execute([$receipt_id]);
    $bt = $stmt->fetch();
    if (!$bt) {
        $stmt = $pdo->prepare("SELECT id, bank_account_id, amount FROM bank_transactions 
            WHERE reference_type = 'customer_receipt' AND reference_id = ? AND transaction_date = ? AND amount = ?
            ORDER BY id DESC LIMIT 1");
        $stmt->execute([$customer_id, $receipt_date, $amount]);
        $bt = $stmt->fetch();
    }
    if ($bt) {
        $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")
            ->execute([(float)$bt['amount'], $bt['bank_account_id']]);
        $pdo->prepare("DELETE FROM bank_transactions WHERE id = ?")->execute([$bt['id']]);
    }
}

// Resolve bank account (use given or first active, create default if none)
function resolveBankAccount($pdo, $bank_account_id, $date) {
    if ($bank_account_id) {
        $stmt = $pdo->prepare("SELECT id, current_balance FROM bank_accounts WHERE id = ? AND status = 1");
        $stmt->execute([$bank_account_id]);
        $account = $stmt->fetch();
        if ($account) return $account;
    }
    $account = $pdo->query("SELECT id, current_balance FROM bank_accounts WHERE status = 1 ORDER BY id ASC LIMIT 1")->fetch();
    if ($account) return $account;
    $account_id = insert('bank_accounts', [
        'account_name' => 'Default Account',
        'bank_name' => 'Default Bank',
        'account_no' => 'AUTO-' . date('YmdHis'),
        'account_type' => 'current',
        'opening_balance' => 0,
        'current_balance' => 0,
        'status' => 1,
        'created_at' => $date,
    ]);
    return ['id' => $account_id, 'current_balance' => 0];
}

// Redirect with message
function redirect($url, $msg = null, $type = 'success') {
    if ($msg) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION[$type] = $msg;
    }
    header("Location: $url");
    exit;
}

// ===== ROLE HELPERS =====
function roleLabel($role) {
    $labels = [
        'admin' => 'Admin',
        'manager' => 'Manager',
        'cashier' => 'Cashier',
        'salesperson' => 'Salesperson',
        'accountant' => 'Accountant',
        'salesman' => 'Salesman',
        'order_booker' => 'Order Booker',
        'loader' => 'Loader',
    ];
    return $labels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

// Redirect to dashboard if current role is not allowed
function requireRole($allowedRoles = ['admin']) {
    global $user_role, $base_url;
    if (!in_array($user_role, (array)$allowedRoles)) {
        redirect(($base_url ?? '/mehboob_traders/') . 'index.php', 'You do not have permission to access this page', 'error');
    }
}

function isAdmin() { global $user_role; return $user_role === 'admin'; }
function isEmployee() { global $user_role; return in_array($user_role, ['salesman', 'order_booker', 'loader'], true); }
function isSalesTeam() { global $user_role; return in_array($user_role, ['order_booker'], true); }

// Map employee_type -> users.role (only order_booker gets a login account)
$role_map = [
    'salesman' => 'salesman',
    'order_booker' => 'order_booker',
    'loader' => 'loader',
];

// Self-heal: give every order booker LOGIN a matching `employees` row.
// Order bookers created straight in the `users` table (seed data / Login Accounts
// page) had no employee record, so they appeared in order booker dropdowns
// (order_booker_invoices.php, DSR, Delivery List) but were invisible in the
// Employees module - no edit, no delete, no salary, no ledger. This creates the
// missing employee rows on the fly. Areas/salary are left empty for the admin to
// fill in via Employees > Edit Employee. Idempotent - a no-op once in sync.
function syncOrderBookerEmployees($pdo) {
    $st = $pdo->query("
        SELECT u.id, u.username, u.full_name, u.phone, u.status, u.created_at
        FROM users u
        LEFT JOIN employees e ON e.user_id = u.id
        WHERE u.role = 'order_booker' AND e.id IS NULL
    ");
    $orphans = $st->fetchAll();
    if (!$orphans) return 0;

    $made = [];
    $chk = $pdo->prepare("SELECT id FROM employees WHERE user_id = ? LIMIT 1");
    foreach ($orphans as $o) {
        $chk->execute([(int)$o['id']]);
        if ($chk->fetch()) continue;
        insert('employees', [
            'user_id'      => (int)$o['id'],
            'emp_code'     => generateEmployeeCode(),
            'full_name'    => $o['full_name'] ?: $o['username'],
            'employee_type'=> 'order_booker',
            'phone'        => $o['phone'],
            'area'         => '',
            'cnic'         => '',
            'address'      => '',
            'joining_date' => $o['created_at'],
            'salary'       => 0,
            'status'       => (int)$o['status'],
            'created_at'   => date('Y-m-d'),
        ]);
        $made[] = ($o['full_name'] ?: $o['username']) . ' [' . $o['username'] . ']';
    }
    logActivity($pdo, 'sync', 'employee', null,
        'Auto-created employee records for orphan order booker logins: ' . implode(', ', $made));
    return count($made);
}

// Return array of assigned areas for the currently logged in employee (order booker / salesman)
// Returns null if admin (all areas allowed), or array of trimmed area names (e.g. ['Gulberg', 'Johar Town'])
function currentUserAreas($pdo) {
    if (isAdmin()) return null;
    static $areas = false;
    if ($areas === false) {
        $st = $pdo->prepare("SELECT area FROM employees WHERE user_id = ? AND area IS NOT NULL AND area <> '' LIMIT 1");
        $st->execute([$_SESSION['user_id'] ?? 0]);
        $raw = $st->fetchColumn();
        if ($raw) {
            $parts = array_map('trim', explode(',', $raw));
            $areas = array_values(array_filter($parts, fn($p) => $p !== ''));
        } else {
            $areas = [];
        }
    }
    return $areas;
}

// Single/combined area helper for display and backward compatibility
function currentUserArea($pdo) {
    $areas = currentUserAreas($pdo);
    if ($areas === null) return null;
    return !empty($areas) ? implode(', ', $areas) : null;
}

// All distinct area names (registered areas + customer-only areas), case-insensitive dedupe
function allKnownAreas($pdo) {
    $known = $pdo->query("SELECT name FROM areas WHERE status = 1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $cust = $pdo->query("SELECT DISTINCT area FROM customers WHERE area IS NOT NULL AND area <> '' AND area <> '-' AND LOWER(area) <> 'counter'")->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    $seen = [];
    foreach (array_merge($known, $cust) as $an) {
        $k = strtolower(trim($an));
        if ($k === '' || isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = trim($an);
    }
    return $out;
}

// Normalize a GET parameter that may arrive as a single value or a multi-value array
// (e.g. order_booker_id=5 from an old link, or order_booker_id[]=5&order_booker_id[]=7 from
// a checkbox filter) into a clean list of unique positive integers, safe to bind as params.
function normalizeIdList($value) {
    if ($value === null || $value === '' || $value === []) return [];
    if (!is_array($value)) $value = [$value];
    $out = [];
    foreach ($value as $v) {
        if (is_array($v) || is_object($v)) continue;
        $s = trim((string)$v);
        if ($s === '' || !preg_match('/^\d+$/', $s)) continue;
        $n = (int)$s;
        if ($n > 0) $out[$n] = $n;
    }
    return array_values($out);
}

// All employee types as an associative array [code => name]
function allEmployeeTypes($pdo) {
    try {
        $rows = $pdo->query("SELECT code, name FROM employee_types ORDER BY is_system DESC, id ASC")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($rows)) {
            return $rows;
        }
    } catch (Exception $e) {}
    return ['salesman' => 'Salesman', 'order_booker' => 'Order Booker', 'loader' => 'Loader'];
}