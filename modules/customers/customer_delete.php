<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $customer = getById('customers', $id);
    if (!$customer) redirect('customers.php', 'Customer not found', 'error');
    $my_areas = currentUserAreas($pdo);
    if (!isAdmin() && $my_areas !== null) {
        if (!empty($my_areas) && !in_array($customer['area'] ?? '', $my_areas, true)) {
            redirect('customers.php', 'You can only delete customers from your assigned areas', 'error');
        }
    }

    // Soft delete customer: marks status = 0 so all invoices, products purchased, and sales history are 100% preserved
    $stmt = $pdo->prepare("UPDATE customers SET status = 0, updated_at = ? WHERE id = ?");
    $stmt->execute([date('Y-m-d'), $id]);

    logActivity($pdo, 'delete', 'customer', $id, 'Deactivated customer: ' . $customer['full_name']);
    redirect('customers.php', 'Customer removed from active list. All past invoices and purchase history remain safely preserved.');
}
redirect('customers.php');