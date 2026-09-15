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

$stmt = $pdo->prepare("
    SELECT id, invoice_no, sale_date, total_amount, paid_amount, due_amount, status 
    FROM sales 
    WHERE customer_id = ? AND status <> 'cancelled' 
    ORDER BY (due_amount > 0) DESC, sale_date DESC, id DESC
");
$stmt->execute([$customer_id]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($invoices);
