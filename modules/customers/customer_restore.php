<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $customer = getById('customers', $id);
    if (!$customer) redirect('customers.php?status=inactive', 'Customer not found', 'error');
    $my_areas = currentUserAreas($pdo);
    if (!isAdmin() && $my_areas !== null) {
        if (!empty($my_areas) && !in_array($customer['area'] ?? '', $my_areas, true)) {
            redirect('customers.php?status=inactive', 'You can only manage customers from your assigned areas', 'error');
        }
    }

    $stmt = $pdo->prepare("UPDATE customers SET status = 1, updated_at = ? WHERE id = ?");
    $stmt->execute([date('Y-m-d'), $id]);

    logActivity($pdo, 'restore', 'customer', $id, 'Restored customer: ' . $customer['full_name']);
    redirect('customers.php', 'Customer "' . $customer['full_name'] . '" restored to active list successfully.');
}
redirect('customers.php');
