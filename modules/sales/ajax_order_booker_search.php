<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if ($q === '') { echo json_encode([]); exit; }

$like = '%' . $q . '%';
$stmt = $pdo->prepare("
    SELECT id, full_name, phone
    FROM users
    WHERE status = 1 AND role = 'order_booker' AND (full_name LIKE ? OR username LIKE ?)
    ORDER BY full_name ASC LIMIT 8
");
$stmt->execute([$like, $like]);
echo json_encode($stmt->fetchAll());