<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if ($q === '') { echo json_encode([]); exit; }

$like = '%' . $q . '%';
$sql = "SELECT id, full_name, phone, city, area, current_balance
        FROM customers
        WHERE (full_name LIKE ? OR phone LIKE ? OR customer_no LIKE ?)";
$params = [$like, $like, $like];
if (!isAdmin()) {
    $area = currentUserArea($pdo);
    if ($area) {
        $sql .= " AND area = ?";
        $params[] = $area;
    }
}
$sql .= " ORDER BY full_name ASC LIMIT 8";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll());