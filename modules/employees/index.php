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
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
    <h6 class="mb-0"><i class="fas fa-list"></i> Employees <?= $type && isset($types[$type]) ? '- ' . $types[$type] : '' ?></h6>
    <div class="d-flex">
      <form method="get" class="form-inline mr-2">
        <select name="type" class="form-control form-control-sm mr-2" onchange="this.form.submit()">
          <option value="">All Types</option>
          <?php foreach ($types as $k => $v) { ?>
            <option value="<?=$k?>" <?= $type==$k ? 'selected' : '' ?>><?=$v?></option>
          <?php } ?>
        </select>
      </form>
      <a href="create.php" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Add Employee</a>
    </div>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered table-hover">
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
            <th class="text-center">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($employees)): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">No employees found. Click "Add Employee" to add one.</td></tr>
          <?php else: $i = 1; foreach ($employees as $e): ?>
            <tr>
              <td><?=$i++?></td>
              <td class="font-weight-bold"><?=htmlspecialchars($e['full_name'])?></td>
              <td>
                <?php if ($e['employee_type']=='salesman'): ?><span class="badge badge-success">Salesman</span>
                <?php elseif ($e['employee_type']=='order_booker'): ?><span class="badge badge-info">Order Booker</span>
                <?php else: ?><span class="badge badge-warning">Loader</span><?php endif; ?>
              </td>
              <td><?=htmlspecialchars($e['area'] ?: '-')?></td>
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
              <td class="text-center">
                <a href="salary.php?employee_id=<?=$e['id']?>" class="btn btn-sm btn-outline-success" title="Pay Salary"><i class="fas fa-money-check-alt"></i></a>
                <a href="edit.php?id=<?=$e['id']?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-edit"></i></a>
                <a href="delete.php?id=<?=$e['id']?>&type=<?=$type?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this employee?');" title="Delete"><i class="fas fa-trash"></i></a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>