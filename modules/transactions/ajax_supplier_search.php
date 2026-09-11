<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if ($q === '') { echo json_encode([]); exit; }

$like = '%' . $q . '%';
$stmt = $pdo->prepare("
    SELECT id, name, phone, city, current_balance
    FROM suppliers
    WHERE status = 1 AND (name LIKE ? OR phone LIKE ? OR city LIKE ?)
    ORDER BY name ASC LIMIT 8
");
$stmt->execute([$like, $like, $like]);
echo json_encode($stmt->fetchAll());