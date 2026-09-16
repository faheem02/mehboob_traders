<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Employees';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$type = $_GET['type'] ?? '';
$types = ['salesman' => 'Salesman', 'order_booker' => 'Order Booker', 'loader' => 'Loader'];

$where = '';
$params = [];
if ($type && isset($types[$type])) {
    $where = 'WHERE employee_type = :t';
    $params[':t'] = $type;
}

$sql = "SELECT e.*, u.username AS login_username, u.status AS user_status
        FROM employees e
        LEFT JOIN users u ON e.user_id = u.id
        $where
        ORDER BY e.employee_type, e.full_name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

$counts = [
    'salesman' => countRows('employees', 'employee_type', 'salesman'),
    'order_booker' => countRows('employees', 'employee_type', 'order_booker'),
    'loader' => countRows('employees', 'employee_type', 'loader'),
];
$total = $counts['salesman'] + $counts['order_booker'] + $counts['loader'];

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- Stats -->
<div class="row mb-3">
  <div class="col-md-3 col-6 mb-2">
    <a href="index.php" class="text-decoration-none">
      <div class="small-box bg-slate">
        <div class="inner"><h3><?=$total?></h3><p>Total Employees</p></div>
        <div class="icon"><i class="fas fa-users"></i></div>
      </div>
    </a>
  </div>
  <div class="col-md-3 col-6 mb-2">
    <a href="index.php?type=salesman" class="text-decoration-none">
      <div class="small-box bg-emerald">
        <div class="inner"><h3><?=$counts['salesman']?></h3><p>Salesman</p></div>
        <div class="icon"><i class="fas fa-user-tie"></i></div>
      </div>
    </a>
  </div>
  <div class="col-md-3 col-6 mb-2">
    <a href="index.php?type=order_booker" class="text-decoration-none">
      <div class="small-box bg-blue">
        <div class="inner"><h3><?=$counts['order_booker']?></h3><p>Order Booker</p></div>
        <div class="icon"><i class="fas fa-clipboard-list"></i></div>
      </div>
    </a>
  </div>
  <div class="col-md-3 col-6 mb-2">
    <a href="index.php?type=loader" class="text-decoration-none">
      <div class="small-box bg-orange">
        <div class="inner"><h3><?=$counts['loader']?></h3><p>Loader</p></div>
        <div class="icon"><i class="fas fa-truck"></i></div>
      </div>
    </a>
  </div>
</div>

<div class="card shadow">
  <div class="card-header d-flex align-items-center justify-content-between flex-wrap d-print-none">
    <h6 class="mb-0"><i class="fas fa-list"></i> Employees <?= $type && isset($types[$type]) ? '- ' . $types[$type] : '' ?></h6>
    <form method="get" class="form-inline mb-0">
      <input type="text" id="empSearch" class="form-control form-control-sm mr-2" style="min-width:220px;" placeholder="Search name, area, phone, CNIC..." autocomplete="off" onkeydown="if(event.key==='Enter')event.preventDefault();">
      <select name="type" class="form-control form-control-sm mr-2" onchange="this.form.submit()">
        <option value="">All Types</option>
        <?php foreach ($types as $k => $v) { ?>
          <option value="<?=$k?>" <?= $type==$k ? 'selected' : '' ?>><?=$v?></option>
        <?php } ?>
      </select>
      <button type="button" class="btn btn-sm btn-outline-secondary mr-2 d-print-none" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
      <a href="create.php" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Add Employee</a>
    </form>
  </div>
  <div class="card-body">
    <div class="d-none d-print-block mb-3 text-center">
      <h4 class="font-weight-bold mb-0" style="color:#0f172a;">Mehboob Traders</h4>
      <small class="text-muted">Wholesale Business</small>
      <h5 class="font-weight-bold text-primary mt-2 mb-0">EMPLOYEES LIST</h5>
      <?php if ($type && isset($types[$type])): ?><div class="mt-1 font-weight-bold">Type: <?=$types[$type]?></div><?php endif; ?>
      <small>Printed on <?=formatDate(date('Y-m-d'))?></small>
    </div>
    <div class="table-responsive">
      <table class="table table-bordered table-hover" id="employeesTable">
        <thead>
          <tr>
            <th style="width:50px;">#</th>
            <th>Name</th>
            <th>Type</th>
            <th>Area</th>
            <th>Phone</th>
            <th>CNIC</th>
            <th>Login User</th>
            <th>Salary (PKR)</th>
            <th class="text-center">Status</th>
            <th class="text-center d-print-none">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($employees)): ?>
            <tr class="empty-state"><td colspan="10" class="text-center text-muted py-4">No employees found. Click "Add Employee" to add one.</td></tr>
          <?php else: $i = 1; foreach ($employees as $e): ?>
            <tr>
              <td><?=$i++?></td>
              <td class="font-weight-bold"><?=htmlspecialchars($e['full_name'])?></td>
              <td>
                <?php if ($e['employee_type']=='salesman'): ?><span class="badge badge-success">Salesman</span>
                <?php elseif ($e['employee_type']=='order_booker'): ?><span class="badge badge-info">Order Booker</span>
                <?php else: ?><span class="badge badge-warning">Loader</span><?php endif; ?>
              </td>
              <td>
                <?php if (!empty($e['area'])): 
                  $parts = array_map('trim', explode(',', $e['area']));
                  foreach ($parts as $p): ?>
                    <span class="badge badge-light border text-dark mr-1 mb-1"><?=htmlspecialchars($p)?></span>
                  <?php endforeach; 
                else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td><?=htmlspecialchars($e['phone'] ?: '-')?></td>
              <td><?=htmlspecialchars($e['cnic'] ?: '-')?></td>
              <td><?= $e['login_username'] ? '<code>' . htmlspecialchars($e['login_username']) . '</code>' : '<span class="text-muted">No login</span>' ?></td>
              <td class="text-right"><?= formatCurrency($e['salary']) ?></td>
              <td class="text-center">
                <?php if ($e['status']): ?>
                  <a href="toggle_status.php?id=<?=$e['id']?>" class="badge badge-success" title="Click to deactivate">Active</a>
                <?php else: ?>
                  <a href="toggle_status.php?id=<?=$e['id']?>" class="badge badge-danger" title="Click to activate">Inactive</a>
                <?php endif; ?>
              </td>
              <td class="text-center d-print-none">
                <a href="salary.php?employee_id=<?=$e['id']?>" class="btn btn-sm btn-outline-success" title="Pay Salary"><i class="fas fa-money-check-alt"></i></a>
                <a href="edit.php?id=<?=$e['id']?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-edit"></i></a>
                <a href="delete.php?id=<?=$e['id']?>&type=<?=$type?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this employee?');" title="Delete"><i class="fas fa-trash"></i></a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          <tr class="empty-state" id="empNoMatch" style="display:none;"><td colspan="10" class="text-center text-muted py-4">No employees match your search.</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
$(document).ready(function(){
  $('#empSearch').on('input', function(){
    var q = $.trim(this.value).toLowerCase();
    var visible = 0;
    $('#employeesTable tbody tr:not(.empty-state)').each(function(){
      var show = !q || $(this).text().toLowerCase().indexOf(q) > -1;
      $(this).toggle(show);
      if (show) visible++;
    });
    $('#empNoMatch').toggle(visible === 0);
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>