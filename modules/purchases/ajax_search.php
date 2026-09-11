<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

header('Content-Type: application/json');

$type = $_GET['type'] ?? '';
$q = trim($_GET['q'] ?? '');
if ($q === '') { echo json_encode([]); exit; }

$like = '%' . $q . '%';

if ($type === 'supplier') {
    $stmt = $pdo->prepare("
        SELECT id, name, phone, city
        FROM suppliers
        WHERE status = 1 AND (name LIKE ? OR phone LIKE ? OR contact_person LIKE ? OR city LIKE ?)
        ORDER BY name ASC LIMIT 8
    ");
    $stmt->execute([$like, $like, $like, $like]);
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($type === 'product') {
    $stmt = $pdo->prepare("
        SELECT id, code, name, unit, boxes_per_carton, purchase_price, stock_quantity
        FROM products
        WHERE status = 1 AND (name LIKE ? OR code LIKE ?)
        ORDER BY name ASC LIMIT 8
    ");
    $stmt->execute([$like, $like]);
    echo json_encode($stmt->fetchAll());
    exit;
}

echo json_encode([]);