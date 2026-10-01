<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$id = (int)($_POST['id'] ?? 0);
$user = $id ? getById('users', $id) : null;
if (!$user) redirect('login_accounts.php', 'Login account not found', 'error');

if ((int)$user['id'] === (int)($_SESSION['user_id'] ?? 0)) {
    redirect('login_accounts.php', 'You cannot delete the login you are currently using', 'error');
}
if ($user['role'] === 'admin') {
    redirect('login_accounts.php', 'Admin login accounts cannot be deleted from here', 'error');
}

$pdo->beginTransaction();
try {
    $tx = userTransactionCount($pdo, $id);

    // Detach any employee still pointing at this login so it does not go NULL silently
    $pdo->prepare("UPDATE employees SET user_id = NULL WHERE user_id = ?")->execute([$id]);

    if ($tx > 0) {
        // History exists -> deactivate so login is blocked but records stay intact
        $pdo->prepare("UPDATE users SET status = 0, updated_at = ? WHERE id = ?")->execute([date('Y-m-d'), $id]);
        $msg = 'Login "' . $user['username'] . '" deactivated (it has ' . $tx . ' transaction(s), so history was kept)';
    } else {
        delete('users', $id);
        $msg = 'Login "' . $user['username'] . '" deleted';
    }

    logActivity($pdo, 'delete', 'user', $id, $msg);
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    redirect('login_accounts.php', 'Error: ' . $e->getMessage(), 'error');
}

redirect('login_accounts.php', $msg);
