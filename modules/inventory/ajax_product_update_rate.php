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

// Bulk update support
if (isset($_POST['rates'])) {
    $raw_rates = $_POST['rates'];
    if (is_string($raw_rates)) {
        $raw_rates = json_decode($raw_rates, true);
    }
    if (!is_array($raw_rates)) {
        http_response_code(400);
        echo json_encode(['ok' => 0, 'error' => 'Invalid rates data']);
        exit;
    }
    $updated_count = 0;
    foreach ($raw_rates as $item) {
        $pid = (int)($item['id'] ?? 0);
        $r = isset($item['rate']) ? trim((string)$item['rate']) : null;
        if (!$pid || $r === null || $r === '' || !is_numeric($r) || (float)$r < 0) continue;
        $prod = getById('products', $pid);
        if (!$prod) continue;
        $old_r = (float)$prod['purchase_price'];
        $new_r = (float)$r;
        if (abs($old_r - $new_r) > 0.0001) {
            update('products', [
                'purchase_price' => $new_r,
                'updated_at'     => date('Y-m-d'),
            ], $pid);
            logActivity($pdo, 'quick_edit', 'product', $pid,
                'Purchase rate update: ' . $prod['name'] . ' (code: ' . $prod['code'] . ') PKR ' . number_format($old_r, 2) . ' -> PKR ' . number_format($new_r, 2)
            );
            $updated_count++;
        }
    }
    echo json_encode([
        'ok'            => 1,
        'updated_count' => $updated_count,
        'message'       => $updated_count > 0 ? "$updated_count product rate(s) updated successfully." : "No rates were changed.",
    ]);
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