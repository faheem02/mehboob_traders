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

$ret = 'index.php';
$q = [];
if (!empty($_POST['ret_from'])) $q['from'] = $_POST['ret_from'];
if (!empty($_POST['ret_to']))   $q['to'] = $_POST['ret_to'];
if (isset($_POST['ret_cat']) && $_POST['ret_cat'] !== '') $q['category_id'] = $_POST['ret_cat'];
if ($q) $ret .= '?' . http_build_query($q);

$pdo->beginTransaction();
try {
    // Give the money back to cash-in-hand / bank balance, then remove the expense itself
    removeExpenseLedger($pdo, $id);
    delete('expenses', $id);
    logActivity($pdo, 'delete', 'expense', $id,
        'Deleted expense ' . ($expense['voucher_no'] ?? '') . ' PKR ' . $expense['amount'] . ' (' . $expense['payment_method'] . ')');
    $pdo->commit();
    redirect($ret, 'Expense deleted: ' . ($expense['voucher_no'] ?? '') . ' PKR ' . formatCurrency($expense['amount']));
} catch (Exception $e) {
    $pdo->rollBack();
    redirect($ret, 'Error: ' . $e->getMessage(), 'error');
}
