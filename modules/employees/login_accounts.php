<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Login Accounts';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$q = trim($_GET['q'] ?? '');

// All logins + whether an employee row is still linked to them
$sql = "SELECT u.id, u.username, u.full_name, u.phone, u.role, u.status, u.created_at,
               e.id AS emp_id, e.emp_code, e.employee_type, e.status AS emp_status, e.area,
               (SELECT COUNT(*) FROM sales WHERE created_by = u.id) +
               (SELECT COUNT(*) FROM purchases WHERE created_by = u.id) +
               (SELECT COUNT(*) FROM expenses WHERE created_by = u.id) +
               (SELECT COUNT(*) FROM customer_receipts WHERE created_by = u.id) +
               (SELECT COUNT(*) FROM supplier_payments WHERE created_by = u.id) +
               (SELECT COUNT(*) FROM cash_book WHERE created_by = u.id) +
               (SELECT COUNT(*) FROM bank_transactions WHERE created_by = u.id) +
               (SELECT COUNT(*) FROM customers WHERE created_by = u.id) +
               (SELECT COUNT(*) FROM activity_logs WHERE user_id = u.id) AS tx_count
        FROM users u
        LEFT JOIN employees e ON e.user_id = u.id
        WHERE 1=1";
$params = [];
if ($q !== '') {
    $sql .= " AND (u.username LIKE ? OR u.full_name LIKE ? OR e.emp_code LIKE ?)";
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}
$sql .= " ORDER BY (e.id IS NULL) DESC, u.full_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$accounts = $stmt->fetchAll();

$total = count($accounts);
$orphans = 0; $active = 0; $linked = 0;
foreach ($accounts as $a) {
    if (empty($a['emp_id'])) $orphans++;
    else $linked++;
    if ($a['status']) $active++;
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6><i class="fas fa-user-lock"></i> Login Accounts (<?=$total?>)</h6>
    <div>
      <button type="button" class="btn btn-sm btn-outline-secondary d-print-none" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
      <a href="index.php" class="btn btn-sm btn-outline-primary d-print-none ml-1"><i class="fas fa-users"></i> Employees</a>
    </div>
  </div>
  <div class="card-body">

    <div class="alert alert-warning py-2 px-3 small d-print-none">
      <i class="fas fa-exclamation-triangle mr-1"></i>
      <strong>Note:</strong> A login marked <span class="badge badge-danger">No Employee</span> is an
      <strong>orphan account</strong> — the employee record was deleted but the login was left behind,
      so it still appears in order booker dropdowns. Delete it here to remove it completely.
      Accounts that have past transactions can only be deactivated (history is kept); accounts with
      no transactions are deleted permanently.
    </div>

    <!-- Printable header -->
    <div class="d-none d-print-block mb-3 text-center">
      <h4 class="font-weight-bold mb-0" style="color:#0f172a;">Mehboob Traders</h4>
      <small class="text-muted">Wholesale Business</small>
      <h5 class="font-weight-bold text-primary mt-2 mb-0">LOGIN ACCOUNTS</h5>
      <?php if ($q !== ''): ?><div class="mt-1 font-weight-bold">Search: <?=htmlspecialchars($q)?></div><?php endif; ?>
      <small>Printed on <?=formatDate(date('Y-m-d'))?></small>
    </div>

    <div class="row g-2 mb-3 d-print-none">
      <div class="col-md-3 col-6 mb-2">
        <div class="card border-left-primary shadow-sm h-100">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Logins</div>
            <div class="h5 mb-0 font-weight-bold text-gray-800" id="statTotal"><?=$total?></div>
          </div>
        </div>
      </div>
      <div class="col-md-3 col-6 mb-2">
        <div class="card border-left-success shadow-sm h-100">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Active</div>
            <div class="h5 mb-0 font-weight-bold text-success" id="statActive"><?=$active?></div>
          </div>
        </div>
      </div>
      <div class="col-md-3 col-6 mb-2">
        <div class="card border-left-info shadow-sm h-100">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Linked to Employee</div>
            <div class="h5 mb-0 font-weight-bold text-info" id="statLinked"><?=$linked?></div>
          </div>
        </div>
      </div>
      <div class="col-md-3 col-6 mb-2">
        <div class="card border-left-danger shadow-sm h-100">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Orphan (No Employee)</div>
            <div class="h5 mb-0 font-weight-bold text-danger" id="statOrphan"><?=$orphans?></div>
          </div>
        </div>
      </div>
    </div>

    <form method="get" class="row g-2 mb-3 d-print-none">
      <div class="col-md-5">
        <input type="text" id="liveUserSearch" name="q" class="form-control" placeholder="Search username, name or employee ID" value="<?=htmlspecialchars($q)?>" autocomplete="off" spellcheck="false">
      </div>
      <div class="col-md-3">
        <button class="btn btn-outline-primary btn-block"><i class="fas fa-search"></i> Search</button>
      </div>
      <?php if ($q !== ''): ?>
      <div class="col-md-2">
        <a href="login_accounts.php" class="btn btn-outline-danger btn-block"><i class="fas fa-undo"></i> Reset</a>
      </div>
      <?php endif; ?>
    </form>

    <div class="table-responsive">
      <table class="table table-bordered table-hover" id="usersTable">
        <thead>
          <tr>
            <th>Username</th><th>Full Name</th><th>Role</th>
            <th>Linked Employee</th><th>Area</th>
            <th class="text-center">Transactions</th>
            <th class="text-center">Status</th>
            <th class="text-center d-print-none">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($accounts as $a): ?>
          <?php $is_orphan = empty($a['emp_id']); $is_self = (int)$a['id'] === (int)($_SESSION['user_id'] ?? 0); ?>
          <tr class="user-row" data-hay="<?=htmlspecialchars(strtolower($a['username'].' '.$a['full_name'].' '.($a['emp_code'] ?? '')))?>">
            <td><code><?=htmlspecialchars($a['username'])?></code></td>
            <td class="font-weight-bold"><?=htmlspecialchars($a['full_name'])?></td>
            <td><span class="badge badge-<?= $a['role']==='admin' ? 'secondary' : 'primary' ?>"><?=htmlspecialchars(roleLabel($a['role']))?></span></td>
            <td>
              <?php if ($is_orphan): ?>
                <span class="badge badge-danger"><i class="fas fa-exclamation-triangle"></i> No Employee</span>
              <?php else: ?>
                <span class="font-weight-bold"><?=htmlspecialchars($a['emp_code'] ?: '-')?></span>
                <span class="badge badge-light border text-muted ml-1"><?=htmlspecialchars(ucfirst($a['employee_type']))?></span>
                <?php if (!$a['emp_status']): ?><span class="badge badge-warning ml-1">Employee Inactive</span><?php endif; ?>
              <?php endif; ?>
            </td>
            <td><?= !empty($a['area']) ? htmlspecialchars($a['area']) : '<span class="text-muted">—</span>' ?></td>
            <td class="text-center"><?= (int)$a['tx_count'] ?></td>
            <td class="text-center">
              <?php if ($a['status']): ?>
                <span class="badge badge-success">Active</span>
              <?php else: ?>
                <span class="badge badge-secondary">Inactive</span>
              <?php endif; ?>
            </td>
            <td class="text-center d-print-none" nowrap>
              <?php if (!$is_self): ?>
                <a href="login_toggle.php?id=<?=$a['id']?>" class="btn btn-sm btn-outline-<?= $a['status'] ? 'warning' : 'success' ?>" title="<?= $a['status'] ? 'Deactivate' : 'Activate' ?> login"><i class="fas fa-power-off"></i></a>
                <form method="post" action="login_delete.php" class="d-inline" onsubmit="return confirm('Delete login &quot;<?=htmlspecialchars($a['username'])?>&quot;?<?= (int)$a['tx_count'] > 0 ? '\n\nThis login has ' . (int)$a['tx_count'] . ' transaction(s) so it will be DEACTIVATED, not deleted, to keep history intact.' : '' ?>');">
                  <input type="hidden" name="id" value="<?=$a['id']?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete login"><i class="fas fa-trash-alt"></i></button>
                </form>
              <?php else: ?>
                <span class="text-muted small">Current</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($accounts)): ?>
          <tr class="empty-state"><td colspan="8" class="text-center text-muted py-4">No login accounts found.</td></tr>
          <?php endif; ?>
          <tr class="empty-state" id="userNoMatch" style="display:none;"><td colspan="8" class="text-center text-muted py-4">No accounts match your search.</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
$(document).ready(function(){
  $('#liveUserSearch').on('input', function(){
    var q = $.trim(this.value).toLowerCase();
    var visible = 0;
    $('#usersTable tbody tr:not(.empty-state)').each(function(){
      var hay = $(this).data('hay') || '';
      var show = !q || hay.indexOf(q) > -1;
      $(this).toggle(show);
      if (show) visible++;
    });
    $('#userNoMatch').toggle(visible === 0);
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
