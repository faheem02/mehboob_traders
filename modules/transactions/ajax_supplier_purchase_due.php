<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { echo json_encode([]); exit; }

// Sync first so paid/due are current
syncSupplierPurchasePayments($pdo, $id);

$rows = $pdo->prepare("
    SELECT id, invoice_no, purchase_date, total_amount, paid_amount, due_amount
    FROM purchases
    WHERE supplier_id = ? AND status <> 'cancelled' AND due_amount > 0.001
    ORDER BY purchase_date ASC, id ASC
");
$rows->execute([$id]);
$result = $rows->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($result);
