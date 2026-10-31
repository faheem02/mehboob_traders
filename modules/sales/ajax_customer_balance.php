<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

$id = (int)($_GET['id'] ?? 0);
$name = trim($_GET['name'] ?? '');
$is_json = isset($_GET['json']);

$c = null;
if ($id > 0) {
    $c = getById('customers', $id);
} elseif ($name !== '') {
    $st = $pdo->prepare("SELECT * FROM customers WHERE LOWER(full_name) = LOWER(?) AND status = 1 ORDER BY id DESC LIMIT 1");
    $st->execute([$name]);
    $c = $st->fetch();
}

if (!$c) {
    if ($is_json) {
        header('Content-Type: application/json');
        echo json_encode(['found' => false, 'balance' => 0, 'formatted' => 'PKR 0.00', 'label' => 'Clear']);
        exit;
    }
    if (!$id && $name === '') { header('Location: index.php'); exit; }
    echo 'Clear';
    exit;
}

$bal = (float)$c['current_balance'];
$text = $bal > 0 ? 'Receivable PKR ' . number_format($bal, 2) : ($bal < 0 ? 'Advance PKR ' . number_format(abs($bal), 2) : 'Clear');

if ($is_json) {
    header('Content-Type: application/json');
    echo json_encode([
        'found' => true,
        'id' => (int)$c['id'],
        'name' => $c['full_name'],
        'phone' => $c['phone'] ?? '',
        'area' => $c['area'] ?? '',
        'balance' => $bal,
        'formatted' => ($bal < 0 ? '- ' : '') . 'PKR ' . number_format(abs($bal), 2),
        'label' => $text,
    ]);
    exit;
}

echo $text;