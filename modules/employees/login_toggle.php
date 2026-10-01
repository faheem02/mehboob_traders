<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$id = (int)($_GET['id'] ?? 0);
$user = $id ? getById('users', $id) : null;
if (!$user) redirect('login_accounts.php', 'Login account not found', 'error');

if ((int)$user['id'] === (int)($_SESSION['user_id'] ?? 0)) {
    redirect('login_accounts.php', 'You cannot change the status of the login you are currently using', 'error');
}
if ($user['role'] === 'admin') {
    redirect('login_accounts.php', 'Admin login accounts cannot be changed from here', 'error');
}

$new_status = $user['status'] ? 0 : 1;
$pdo->beginTransaction();
try {
    $pdo->prepare("UPDATE users SET status = ?, updated_at = ? WHERE id = ?")->execute([$new_status, date('Y-m-d'), $id]);

    // Keep the linked employee's status in sync (both directions)
    $pdo->prepare("UPDATE employees SET status = ? WHERE user_id = ?")->execute([$new_status, $id]);

    logActivity($pdo, 'toggle', 'user', $id, ($new_status ? 'Activated' : 'Deactivated') . ' login ' . $user['username']);
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    redirect('login_accounts.php', 'Error: ' . $e->getMessage(), 'error');
}

redirect('login_accounts.php', 'Login "' . $user['username'] . '" ' . ($new_status ? 'activated' : 'deactivated'));
