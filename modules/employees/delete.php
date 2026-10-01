<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$id = (int)($_GET['id'] ?? 0);
$emp = $id ? getById('employees', $id) : null;
if (!$emp) redirect('index.php', 'Employee not found', 'error');

// Never let an admin delete their own account
if (!empty($emp['user_id']) && (int)$emp['user_id'] === (int)($_SESSION['user_id'] ?? 0)) {
    redirect('index.php', 'You cannot delete the employee account you are currently logged in with', 'error');
}

$pdo->beginTransaction();
try {
    // Remove the linked login (deactivates instead of deleting when history exists)
    $login = removeEmployeeLogin($pdo, $emp);

    // Salary slips cascade via FK, but clear explicitly so nothing is left behind
    $pdo->prepare("DELETE FROM employee_salaries WHERE employee_id = ?")->execute([$id]);

    delete('employees', $id);

    $note = ' (no login account was linked)';
    if ($login['action'] === 'deleted') {
        $note = ' and login account removed';
    } elseif ($login['action'] === 'deactivated') {
        $note = ' and login account deactivated';
    } elseif ($login['action'] === 'kept') {
        $note = ' but ' . lcfirst(rtrim($login['message'], '.'));
    }

    logActivity($pdo, 'delete', 'employee', $id, 'Deleted employee ' . $emp['full_name'] . $note);
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    redirect('index.php', 'Error: ' . $e->getMessage(), 'error');
}

$msg = 'Employee "' . $emp['full_name'] . '" deleted';
if ($login['action'] === 'deactivated') {
    $msg .= ' — its login was deactivated because past transactions exist';
} elseif ($login['action'] === 'deleted') {
    $msg .= ' along with its login account';
}
$t = $_GET['type'] ?? '';
redirect('index.php' . ($t ? '?type=' . urlencode($t) : ''), $msg);
