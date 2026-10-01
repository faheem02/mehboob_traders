<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    redirect('index.php');
}

$id = (int)$_POST['id'];
$expense = getById('expenses', $id);
if (!$expense) redirect('index.php', 'Expense not found', 'error');

// Return URL: rebuild from the allow-listed filter fields only (never trust a raw query string)
$ret = 'index.php';
$q = [];
if (!empty($_POST['ret_from'])) $q['from'] = $_POST['ret_from'];
if (!empty($_POST['ret_to']))   $q['to'] = $_POST['ret_to'];
if (isset($_POST['ret_cat']) && $_POST['ret_cat'] !== '') $q['category_id'] = $_POST['ret_cat'];
if ($q) $ret .= '?' . http_build_query($q);

$amount = (float)($_POST['amount'] ?? 0);
$expense_date = $_POST['expense_date'] ?: date('Y-m-d');
$category_id = $_POST['category_id'] ?: null;
$method = ($_POST['payment_method'] ?? 'cash') === 'bank' ? 'bank' : 'cash';
$bank_id = $method === 'bank' ? ($_POST['bank_account_id'] ?: null) : null;
$description = trim($_POST['description'] ?? '');
$vendor = trim($_POST['vendor_name'] ?? '');
$bill_no = trim($_POST['bill_no'] ?? '');

if ($amount <= 0) {
    redirect($ret, 'Enter a valid expense amount', 'error');
}

$pdo->beginTransaction();
try {
    // Undo the old cash/bank effect first, so switching method/amount cannot double-count
    removeExpenseLedger($pdo, $id);

    update('expenses', [
        'category_id' => $category_id,
        'expense_date' => $expense_date,
        'amount' => $amount,
        'description' => $description,
        'vendor_name' => $vendor,
        'bill_no' => $bill_no,
        'payment_method' => $method,
        'bank_account_id' => $bank_id,
        'updated_at' => date('Y-m-d'),
    ], $id);

    $desc = 'Expense: ' . ($description ?: 'Expense');
    if ($method === 'bank') {
        recordBankOutflow($pdo, $expense_date, $amount, $desc, 'expense', $id, $_SESSION['user_id'], $bank_id);
    } else {
        recordCashOutflow($pdo, $expense_date, $amount, $desc, 'expense', $id, $_SESSION['user_id']);
    }

    // No explicit recomputeCashDailyFrom() needed here: removeExpenseLedger() re-carries the
    // balance from the old date, and recordCashOutflow() now re-carries it from the new one.

    logActivity($pdo, 'update', 'expense', $id,
        'Updated expense ' . ($expense['voucher_no'] ?? '') . ' PKR ' . $expense['amount'] . ' -> PKR ' . $amount);
    $pdo->commit();
    redirect($ret, 'Expense updated: ' . ($expense['voucher_no'] ?? '') . ' is now PKR ' . formatCurrency($amount));
} catch (Exception $e) {
    $pdo->rollBack();
    redirect($ret, 'Error: ' . $e->getMessage(), 'error');
}
