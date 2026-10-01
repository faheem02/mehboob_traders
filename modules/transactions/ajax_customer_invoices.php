<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin', 'order_booker']);

header('Content-Type: application/json');

$customer_id = (int)($_GET['customer_id'] ?? 0);
if (!$customer_id) {
    echo json_encode([]);
    exit;
}

// Only invoices that still have money outstanding. When editing a payment,
// include_sale_id allows the currently linked invoice to appear as well even if its due_amount is 0.
$include_sale_id = (int)($_GET['include_sale_id'] ?? 0);
if ($include_sale_id > 0) {
    $stmt = $pdo->prepare("
        SELECT id, invoice_no, sale_date, total_amount, paid_amount, due_amount, status 
        FROM sales 
        WHERE customer_id = ? AND status <> 'cancelled' AND (due_amount > 0 OR id = ?)
        ORDER BY sale_date DESC, id DESC
    ");
    $stmt->execute([$customer_id, $include_sale_id]);
} else {
    $stmt = $pdo->prepare("
        SELECT id, invoice_no, sale_date, total_amount, paid_amount, due_amount, status 
        FROM sales 
        WHERE customer_id = ? AND status <> 'cancelled' AND due_amount > 0 
        ORDER BY sale_date DESC, id DESC
    ");
    $stmt->execute([$customer_id]);
}
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($invoices);
