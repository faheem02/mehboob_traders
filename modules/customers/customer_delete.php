<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $customer = getById('customers', $id);
    if (!$customer) redirect('customers.php', 'Customer not found', 'error');
    if (!isAdmin()) {
        $my_area = currentUserArea($pdo);
        if ($my_area && ($customer['area'] ?? '') !== $my_area) {
            redirect('customers.php', 'You can only delete customers from your area', 'error');
        }
    }

    $chk = $pdo->prepare("SELECT
        (SELECT COUNT(*) FROM sales WHERE customer_id = ?) AS sales,
        (SELECT COUNT(*) FROM customer_receipts WHERE customer_id = ?) AS receipts");
    $chk->execute([$id, $id]);
    $r = $chk->fetch();
    if ($r['sales'] > 0 || $r['receipts'] > 0) {
        redirect('customers.php', 'Cannot delete "' . $customer['full_name'] . '": it has sale/receipt history', 'error');
    }

    delete('customers', $id);
    logActivity($pdo, 'delete', 'customer', $id, 'Deleted customer: ' . $customer['full_name']);
    redirect('customers.php', 'Customer deleted');
}
redirect('customers.php');