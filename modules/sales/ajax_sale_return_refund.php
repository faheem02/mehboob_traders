<?php
/**
 * AJAX endpoints used by the "Refund by Invoice" modal on sale_return.php.
 * - action=customer_invoices&customer_id= : invoices of a customer (with returned amounts)
 * - action=invoice_details&invoice_id=    : items of one invoice (with already returned qty)
 */
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$result = [];

if ($action === 'customer_invoices') {
    $customer_id = (int)($_GET['customer_id'] ?? 0);
    if ($customer_id <= 0) {
        echo json_encode([]);
        exit;
    }
    $sql = "
        SELECT s.id, s.invoice_no, s.sale_date, s.total_amount, s.paid_amount, s.due_amount,
               (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS item_count,
               (SELECT COALESCE(SUM(sri.total_price), 0)
                  FROM sale_return_items sri
                  JOIN sale_returns sr ON sr.id = sri.return_id
                 WHERE sr.status = 'Completed' AND sr.invoice_id = s.id) AS returned_amount
        FROM sales s
        WHERE s.customer_id = ? AND s.status <> 'cancelled'
        ORDER BY s.sale_date DESC, s.id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$customer_id]);
    $result = $stmt->fetchAll();
} elseif ($action === 'invoice_details') {
    $invoice_id = (int)($_GET['invoice_id'] ?? 0);
    if ($invoice_id <= 0) {
        echo json_encode(['invoice' => null, 'items' => []]);
        exit;
    }
    $stmt = $pdo->prepare("
        SELECT s.*, c.full_name AS customer_name, c.customer_no AS customer_no,
               e.full_name AS salesman_name
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN employees e ON s.salesman_id = e.id
        WHERE s.id = ?
    ");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch();
    if (!$invoice) {
        echo json_encode(['invoice' => null, 'items' => []]);
        exit;
    }

    $returned_map = [];
    $stmt_r = $pdo->prepare("
        SELECT sri.product_id, COALESCE(SUM(sri.quantity), 0) AS q
        FROM sale_return_items sri
        JOIN sale_returns sr ON sr.id = sri.return_id
        WHERE sr.status = 'Completed' AND sr.invoice_id = ?
        GROUP BY sri.product_id
    ");
    $stmt_r->execute([$invoice_id]);
    foreach ($stmt_r->fetchAll() as $row) {
        $returned_map[(int)$row['product_id']] = (int)$row['q'];
    }

    $items = [];
    $stmt_i = $pdo->prepare("
        SELECT si.product_id, p.name AS product_name, p.code AS product_code,
               si.quantity, si.price AS unit_price
        FROM sale_items si
        JOIN products p ON p.id = si.product_id
        WHERE si.sale_id = ?
        ORDER BY si.id ASC
    ");
    $stmt_i->execute([$invoice_id]);
    foreach ($stmt_i->fetchAll() as $row) {
        $pid = (int)$row['product_id'];
        $sold_qty = (int)$row['quantity'];
        $already = (int)($returned_map[$pid] ?? 0);
        $items[] = [
            'product_id' => $pid,
            'product_name' => $row['product_name'],
            'product_code' => $row['product_code'],
            'sold_qty' => $sold_qty,
            'returned_qty' => $already,
            'returnable_qty' => max(0, $sold_qty - $already),
            'unit_price' => (float)$row['unit_price'],
        ];
    }
    $result = [
        'invoice' => $invoice,
        'items' => $items,
    ];
}

echo json_encode($result);
