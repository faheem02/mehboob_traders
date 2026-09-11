<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'My Profile';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$user = getById('users', $_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($current !== $user['password']) { redirect('index.php', 'Current password is incorrect', 'error'); }
    if (strlen($new_password) < 4) { redirect('index.php', 'New password must be at least 4 characters', 'error'); }
    if ($new_password !== $confirm) { redirect('index.php', 'New password and confirm password do not match', 'error'); }

    update('users', ['password' => $new_password, 'updated_at' => date('Y-m-d')], $user['id']);
    logActivity($pdo, 'edit', 'user', $user['id'], 'Changed own password');
    redirect('index.php', 'Password changed successfully');
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="row">
  <div class="col-lg-6">
    <div class="card shadow">
      <div class="card-header"><h6><i class="fas fa-user-circle"></i> Profile Details</h6></div>
      <div class="card-body">
        <div class="text-center mb-3">
          <div style="width:80px;height:80px;border-radius:50%;background:var(--primary);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:2rem;font-weight:700;">
            <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
          </div>
          <h5 class="mt-2 mb-0 font-weight-bold"><?= htmlspecialchars($user['full_name']) ?></h5>
          <span class="badge <?= isAdmin() ? 'badge-success' : 'badge-info' ?>"><?= roleLabel($user['role']) ?></span>
        </div>
        <table class="table table-sm">
          <tr><th style="width:40%;">Username</th><td><code><?= htmlspecialchars($user['username']) ?></code></td></tr>
          <tr><th>Email</th><td><?= htmlspecialchars($user['email'] ?? '-') ?></td></tr>
          <tr><th>Phone</th><td><?= htmlspecialchars($user['phone'] ?? '-') ?></td></tr>
          <tr><th>Member Since</th><td><?= formatDate($user['created_at']) ?></td></tr>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card shadow">
      <div class="card-header"><h6><i class="fas fa-key"></i> Change Password</h6></div>
      <div class="card-body">
        <form method="post">
          <div class="form-group">
            <label class="form-label">Current Password *</label>
            <input type="password" name="current_password" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label">New Password *</label>
            <input type="password" name="new_password" class="form-control" required minlength="4">
          </div>
          <div class="form-group">
            <label class="form-label">Confirm New Password *</label>
            <input type="password" name="confirm_password" class="form-control" required minlength="4">
          </div>
          <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-save"></i> Update Password</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>