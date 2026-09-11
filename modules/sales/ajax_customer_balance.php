<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$c = getById('customers', $id);
if (!$c) { exit; }

echo $c['current_balance'] > 0 ? 'Receivable PKR ' . number_format((float)$c['current_balance'], 2) : ($c['current_balance'] < 0 ? 'Advance PKR ' . number_format(abs((float)$c['current_balance']), 2) : 'Clear');
?>