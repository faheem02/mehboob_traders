<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $supplier = getById('suppliers', $id);
    if (!$supplier) redirect('suppliers.php', 'Supplier not found', 'error');

    $chk = $pdo->prepare("SELECT
        (SELECT COUNT(*) FROM purchases WHERE supplier_id = ?) AS purchases,
        (SELECT COUNT(*) FROM supplier_payments WHERE supplier_id = ?) AS payments");
    $chk->execute([$id, $id]);
    $r = $chk->fetch();
    if ($r['purchases'] > 0 || $r['payments'] > 0) {
        redirect('suppliers.php', 'Cannot delete "' . $supplier['name'] . '": it has purchase/payment history', 'error');
    }

    delete('suppliers', $id);
    logActivity($pdo, 'delete', 'supplier', $id, 'Deleted supplier: ' . $supplier['name']);
    redirect('suppliers.php', 'Supplier deleted');
}
redirect('suppliers.php');