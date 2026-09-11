<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$id = (int)($_GET['id'] ?? 0);
$emp = $id ? getById('employees', $id) : null;
if (!$emp) redirect('index.php', 'Employee not found', 'error');

$pdo->beginTransaction();
try {
    $new_status = $emp['status'] ? 0 : 1;
    update('employees', ['status' => $new_status], $id);
    if (!empty($emp['user_id'])) {
        $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$new_status, $emp['user_id']]);
    }
    logActivity($pdo, 'toggle', 'employee', $id, 'Toggled employee status to ' . ($new_status ? 'Active' : 'Inactive') . ' for ' . $emp['full_name']);
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    redirect('index.php', 'Error: ' . $e->getMessage(), 'error');
}
redirect('index.php', 'Employee status updated');