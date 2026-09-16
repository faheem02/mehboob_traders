<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['error' => 'Invalid sale ID']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
$stmt->execute([$id]);
$sale = $stmt->fetch();
if (!$sale) {
    echo json_encode(['error' => 'Sale not found']);
    exit;
}
// Ownership guard (same as sale_edit): non-admin only their own invoices
if (!isAdmin() && (int)$sale['created_by'] !== (int)$_SESSION['user_id']) {
    echo json_encode(['error' => 'You can only edit your own invoices']);
    exit;
}

$customer_name = '';
if ($sale['customer_id']) {
    $c = $pdo->prepare("SELECT full_name FROM customers WHERE id = ?");
    $c->execute([$sale['customer_id']]);
    $customer_name = (string)$c->fetchColumn();
}

$salesman_name = '';
if ($sale['salesman_id']) {
    $e = $pdo->prepare("SELECT full_name FROM employees WHERE id = ?");
    $e->execute([$sale['salesman_id']]);
    $salesman_name = (string)$e->fetchColumn();
}

$items = $pdo->prepare("
    SELECT si.product_id, p.name AS product_name, p.code AS product_code, p.unit, p.boxes_per_carton, p.stock_quantity, p.sale_price, si.quantity, si.price
    FROM sale_items si
    JOIN products p ON p.id = si.product_id
    WHERE si.sale_id = ?
    ORDER BY si.id ASC
");
$items->execute([$id]);

echo json_encode([
    'id'              => (int)$sale['id'],
    'invoice_no'      => $sale['invoice_no'],
    'sale_date'       => $sale['sale_date'],
    'customer_id'     => $sale['customer_id'] ? (int)$sale['customer_id'] : null,
    'customer_name'   => $customer_name,
    'salesman_id'     => $sale['salesman_id'] ? (int)$sale['salesman_id'] : null,
    'salesman_name'   => $salesman_name,
    'payment_method'  => $sale['payment_method'],
    'bank_account_id' => $sale['bank_account_id'] ? (int)$sale['bank_account_id'] : null,
    'paid_amount'     => (float)$sale['paid_amount'],
    'discount_amount' => (float)$sale['discount_amount'],
    'total_amount'    => (float)$sale['total_amount'],
    'due_amount'      => (float)$sale['due_amount'],
    'notes'           => $sale['notes'],
    'items'           => $items->fetchAll(),
]);