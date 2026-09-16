<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $stmt = $pdo->prepare("SELECT id, name, city FROM areas WHERE status = 1 AND (name LIKE ? OR city LIKE ?) ORDER BY name ASC LIMIT 15");
    $stmt->execute(["%$q%", "%$q%"]);
} else {
    $stmt = $pdo->query("SELECT id, name, city FROM areas WHERE status = 1 ORDER BY name ASC LIMIT 30");
}
$areas = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($areas);
