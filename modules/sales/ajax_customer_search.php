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
$my_areas = currentUserAreas($pdo);
if (!isAdmin() && $my_areas !== null) {
    if (empty($my_areas)) {
        $sql .= " AND 1=0";
    } else {
        $in_clause = implode(',', array_fill(0, count($my_areas), '?'));
        $sql .= " AND area IN ($in_clause)";
        $params = array_merge($params, $my_areas);
    }
}
$sql .= " ORDER BY full_name ASC LIMIT 8";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll());