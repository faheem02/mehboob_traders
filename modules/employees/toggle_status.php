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

    // Sync the linked login too (resolve by user_id, falling back to an orphan name match)
    $user = findEmployeeLogin($pdo, $emp);
    $synced = '';
    if ($user) {
        $pdo->prepare("UPDATE users SET status = ?, updated_at = ? WHERE id = ?")
            ->execute([$new_status, date('Y-m-d'), $user['id']]);
        $synced = ' and login "' . $user['username'] . '"';
    }

    logActivity($pdo, 'toggle', 'employee', $id, 'Toggled employee status to ' . ($new_status ? 'Active' : 'Inactive') . ' for ' . $emp['full_name'] . $synced);
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    redirect('index.php', 'Error: ' . $e->getMessage(), 'error');
}
redirect('index.php', 'Employee' . ($synced ?? '') . ' status updated');
