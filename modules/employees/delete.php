<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$id = (int)($_GET['id'] ?? 0);
$emp = $id ? getById('employees', $id) : null;
if (!$emp) redirect('index.php', 'Employee not found', 'error');

$pdo->beginTransaction();
try {
    if (!empty($emp['user_id'])) {
        delete('users', $emp['user_id']);
    }
    delete('employees', $id);
    logActivity($pdo, 'delete', 'employee', $id, 'Deleted employee ' . $emp['full_name'] . ' and login account');
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    redirect('index.php', 'Error: ' . $e->getMessage(), 'error');
}
$t = $_GET['type'] ?? '';
redirect('index.php' . ($t ? '?type=' . $t : ''), 'Employee deleted');