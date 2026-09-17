<?php
header('Content-Type: application/json');
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => 0, 'error' => 'POST required']);
    exit;
}

$id    = (int)($_POST['id'] ?? 0);
$field = $_POST['field'] ?? 'purchase_price';
if (!in_array($field, ['purchase_price', 'sale_price'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => 0, 'error' => 'Invalid field']);
    exit;
}
$label = $field === 'sale_price' ? 'Sale' : 'Purchase';

$rate = isset($_POST['rate']) ? trim($_POST['rate']) : (isset($_POST[$field]) ? trim($_POST[$field]) : null);

if (!$id) {
    http_response_code(400);
    echo json_encode(['ok' => 0, 'error' => 'Invalid product']);
    exit;
}
if ($rate === null || $rate === '' || !is_numeric($rate) || (float)$rate < 0) {
    http_response_code(400);
    echo json_encode(['ok' => 0, 'error' => $label . ' rate must be zero or greater']);
    exit;
}

$product = getById('products', $id);
if (!$product) {
    http_response_code(404);
    echo json_encode(['ok' => 0, 'error' => 'Product not found']);
    exit;
}

$old_rate = (float)$product[$field];
$new_rate = (float)$rate;

if ($new_rate === $old_rate) {
    echo json_encode(['ok' => 1, 'unchanged' => true, $field => number_format($new_rate, 2), 'message' => 'Rate unchanged']);
    exit;
}

update('products', [
    $field      => $new_rate,
    'updated_at' => date('Y-m-d'),
], $id);

logActivity($pdo, 'quick_edit', 'product', $id,
    'Quick ' . strtolower($label) . ' rate change: ' . $product['name'] .
    ' (code: ' . $product['code'] . ') ' .
    'PKR ' . number_format($old_rate, 2) . ' -> PKR ' . number_format($new_rate, 2)
);

echo json_encode([
    'ok'             => 1,
    'field'          => $field,
    $field           => number_format($new_rate, 2),
    'message'        => $label . ' rate updated: PKR ' . number_format($old_rate, 2) . ' → ' . number_format($new_rate, 2),
]);