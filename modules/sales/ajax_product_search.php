<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if ($q === '') { echo json_encode([]); exit; }

$like = '%' . $q . '%';
$stmt = $pdo->prepare("
    SELECT id, code, name, unit, boxes_per_carton, sale_price, stock_quantity
    FROM products
    WHERE status = 1 AND (name LIKE ? OR code LIKE ?)
    ORDER BY name ASC LIMIT 8
");
$stmt->execute([$like, $like]);
echo json_encode($stmt->fetchAll());