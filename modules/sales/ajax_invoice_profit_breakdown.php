<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin', 'order_booker']);

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid invoice ID']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT s.*, 
           c.full_name AS customer_name, c.phone AS customer_phone, c.area AS customer_area,
           e.full_name AS salesman_name,
           u.full_name AS order_taker_name
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.id
    LEFT JOIN employees e ON s.salesman_id = e.id
    LEFT JOIN users u ON s.created_by = u.id
    WHERE s.id = ?
");
$stmt->execute([$id]);
$sale = $stmt->fetch();

if (!$sale) {
    http_response_code(404);
    echo json_encode(['error' => 'Invoice not found']);
    exit;
}

// Ownership check: order bookers can only see their own invoices
if (!isAdmin() && (int)$sale['created_by'] !== (int)$_SESSION['user_id']) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$items_stmt = $pdo->prepare("
    SELECT si.id, si.product_id, si.quantity, si.price AS sale_price, si.subtotal AS sale_subtotal,
           p.name AS product_name, p.code AS product_code, p.unit, p.boxes_per_carton, p.purchase_price
    FROM sale_items si
    JOIN products p ON si.product_id = p.id
    WHERE si.sale_id = ?
    ORDER BY si.id ASC
");
$items_stmt->execute([$id]);
$raw_items = $items_stmt->fetchAll();

$items = [];
$total_item_sale = 0;
$total_item_cost = 0;

foreach ($raw_items as $item) {
    $qty = (int)$item['quantity']; // quantity in boxes
    $bpc = max(1, (int)$item['boxes_per_carton']);
    $purchase_price = (float)$item['purchase_price']; // price per carton
    $cost_per_box = $purchase_price / $bpc;
    $cost_total = $qty * $cost_per_box;
    $sale_subtotal = (float)$item['sale_subtotal'];
    $item_profit = $sale_subtotal - $cost_total;
    $item_margin = $sale_subtotal > 0 ? ($item_profit / $sale_subtotal) * 100 : 0;

    $cartons = floor($qty / $bpc);
    $rem_boxes = $qty % $bpc;
    $packaging_label = $bpc > 1 ? ($cartons . ' ctn' . ($rem_boxes > 0 ? ' + ' . $rem_boxes . ' box' : '')) : ($qty . ' boxes');

    $total_item_sale += $sale_subtotal;
    $total_item_cost += $cost_total;

    $items[] = [
        'id'               => (int)$item['id'],
        'product_id'       => (int)$item['product_id'],
        'product_name'     => $item['product_name'],
        'product_code'     => $item['product_code'],
        'quantity'         => $qty,
        'boxes_per_carton' => $bpc,
        'packaging_label'  => $packaging_label,
        'sale_price'       => (float)$item['sale_price'],
        'sale_subtotal'    => $sale_subtotal,
        'purchase_price'   => $purchase_price,
        'cost_per_box'     => $cost_per_box,
        'cost_total'       => $cost_total,
        'profit'           => $item_profit,
        'margin_pct'       => round($item_margin, 2),
    ];
}

$discount = (float)($sale['discount_amount'] ?? 0);
$net_sale = (float)$sale['total_amount'];
$net_profit = $net_sale - $total_item_cost;
$net_margin = $net_sale > 0 ? ($net_profit / $net_sale) * 100 : 0;

echo json_encode([
    'invoice_no'       => $sale['invoice_no'],
    'sale_date'        => formatDate($sale['sale_date']),
    'customer_name'    => $sale['customer_name'] ?? 'N/A',
    'customer_phone'   => $sale['customer_phone'] ?? '',
    'customer_area'    => $sale['customer_area'] ?? '',
    'salesman_name'    => $sale['salesman_name'] ?? '—',
    'order_taker_name' => $sale['order_taker_name'] ?? '—',
    'items'            => $items,
    'total_sale'       => $net_sale,
    'discount_amount'  => $discount,
    'total_cost'       => $total_item_cost,
    'net_profit'       => $net_profit,
    'margin_pct'       => round($net_margin, 2),
    'paid_amount'      => (float)$sale['paid_amount'],
    'due_amount'       => (float)$sale['due_amount'],
]);
