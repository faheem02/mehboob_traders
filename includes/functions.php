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

// Generate purchase invoice number
function generatePurchaseNo() {
    global $pdo;
    $prefix = 'PUR-' . date('ymd') . '-';
    $stmt = $pdo->query("SELECT COUNT(*) FROM purchases WHERE invoice_no LIKE '$prefix%'");
    $count = $stmt->fetchColumn() + 1;
    return $prefix . str_pad($count, 3, '0', STR_PAD_LEFT);
}

// Generate sale invoice number
function generateSaleNo() {
    global $pdo;
    $prefix = 'INV-' . date('ymd') . '-';
    $stmt = $pdo->query("SELECT COUNT(*) FROM sales WHERE invoice_no LIKE '$prefix%'");
    $count = $stmt->fetchColumn() + 1;
    return $prefix . str_pad($count, 3, '0', STR_PAD_LEFT);
}

// Generate customer number
function generateCustomerNo() {
    global $pdo;
    $prefix = 'CUS-' . date('ym') . '-';
    $stmt = $pdo->query("SELECT COUNT(*) FROM customers WHERE customer_no LIKE '$prefix%'");
    $count = $stmt->fetchColumn() + 1;
    return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
}

// Generate salary slip number
function generateSalaryNo() {
    global $pdo;
    $prefix = 'SAL-' . date('ymd') . '-';
    $stmt = $pdo->query("SELECT COUNT(*) FROM employee_salaries WHERE slip_no LIKE '$prefix%'");
    $count = $stmt->fetchColumn() + 1;
    return $prefix . str_pad($count, 3, '0', STR_PAD_LEFT);
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
function updateSupplierBalance($pdo, $supplier_id) {
    if (!$supplier_id) return;
    // Supplier balance = total due on purchases - cash paid to supplier
    $duestmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount - paid_amount),0)
        FROM purchases WHERE supplier_id = ? AND status <> 'cancelled'
    ");
    $duestmt->execute([$supplier_id]);
    $due = (float)$duestmt->fetchColumn();
    $paid = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE supplier_id = ?");
    $paid->execute([$supplier_id]);
    $paid = (float)$paid->fetchColumn();
    $s = getById('suppliers', $supplier_id);
    $opening = (float)($s['opening_balance'] ?? 0) + (float)($s['adjustment'] ?? 0);
    $balance = $opening + $due - $paid;
    $pdo->prepare("UPDATE suppliers SET current_balance = ? WHERE id = ?")->execute([$balance, $supplier_id]);
    return $balance;
}

// Update customer live balance
// Sign: positive = customer owes us, negative = we owe customer
function updateCustomerBalance($pdo, $customer_id) {
    if (!$customer_id) return;
    $due = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount - paid_amount),0)
        FROM sales WHERE customer_id = ? AND status <> 'cancelled'
    ");
    $due->execute([$customer_id]);
    $due = (float)$due->fetchColumn();
    $recv = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM customer_receipts WHERE customer_id = ?");
    $recv->execute([$customer_id]);
    $recv = (float)$recv->fetchColumn();
    $c = getById('customers', $customer_id);
    $opening = (float)($c['opening_balance'] ?? 0);
    $balance = $opening + $due - $recv;
    $pdo->prepare("UPDATE customers SET current_balance = ? WHERE id = ?")->execute([$balance, $customer_id]);
    return $balance;
}

// Allocate customer receipts to sales (FIFO: oldest sale first)
// Updates sales.paid_amount and sales.due_amount based on total receipts
function allocateReceiptsToSales($pdo, $customer_id) {
    if (!$customer_id) return;
    // Total receipts for this customer
    $r = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM customer_receipts WHERE customer_id = ?");
    $r->execute([$customer_id]);
    $total_paid = (float)$r->fetchColumn();
    // Get all unpaid/partially-paid sales for this customer, oldest first
    $sales = $pdo->prepare("SELECT id, total_amount, paid_amount FROM sales WHERE customer_id = ? AND status <> 'cancelled' ORDER BY sale_date ASC, id ASC");
    $sales->execute([$customer_id]);
    $all_sales = $sales->fetchAll();
    $remaining = $total_paid;
    foreach ($all_sales as $s) {
        $old_paid = (float)$s['paid_amount'];
        $total = (float)$s['total_amount'];
        $new_paid = min($total, $remaining);
        $new_due = max(0, $total - $new_paid);
        if ($new_paid != $old_paid) {
            $status = $new_due > 0 ? 'active' : 'completed';
            $pdo->prepare("UPDATE sales SET paid_amount = ?, due_amount = ?, status = ? WHERE id = ?")
                ->execute([$new_paid, $new_due, $status, $s['id']]);
        }
        $remaining -= $new_paid;
        if ($remaining <= 0) break;
    }
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
        session_start();
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

// Area of the currently logged-in employee (order booker / salesman / loader) from employees.area; null when no area assigned
function currentUserArea($pdo) {
    static $area = false;
    if ($area === false) {
        $st = $pdo->prepare("SELECT area FROM employees WHERE user_id = ? AND area IS NOT NULL AND area <> '' LIMIT 1");
        $st->execute([$_SESSION['user_id'] ?? 0]);
        $area = $st->fetchColumn() ?: null;
    }
    return $area;
}