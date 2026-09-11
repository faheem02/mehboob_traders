<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Salary Ledger';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

function monthRange($start, $end) {
    $months = [];
    $cur = new DateTime($start . '-01');
    $last = new DateTime($end . '-01');
    while ($cur <= $last) {
        $months[] = $cur->format('Y-m');
        $cur->modify('+1 month');
    }
    return $months;
}

$q = trim($_GET['q'] ?? '');
$type = trim($_GET['type'] ?? '');
$sql = "SELECT * FROM employees WHERE status = 1";
$params = [];
if ($type !== '') {
    $sql .= " AND employee_type = ?";
    $params[] = $type;
}
if ($q !== '') {
    $sql .= " AND (full_name LIKE ? OR phone LIKE ?)";
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
$sql .= " ORDER BY full_name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

$payments = $pdo->query("SELECT employee_id, salary_month, amount, payment_date FROM employee_salaries ORDER BY payment_date DESC")->fetchAll();

$paid_by_emp = [];
$last_paid = [];
$total_paid_all = 0;
foreach ($payments as $p) {
    $cap = $paid_by_emp[$p['employee_id']][$p['salary_month']] ?? 0;
    $paid_by_emp[$p['employee_id']][$p['salary_month']] = $cap + (float)$p['amount'];
    $total_paid_all += (float)$p['amount'];
    if (!isset($last_paid[$p['employee_id']]) || $p['payment_date'] > $last_paid[$p['employee_id']]) {
        $last_paid[$p['employee_id']] = $p['payment_date'];
    }
}

$current_month = date('Y-m');
$rows = [];
$emp_due = 0;
$total_pending = 0;
$total_pending_months = 0;
foreach ($employees as $e) {
    $join = $e['joining_date'] ?: $e['created_at'];
    $start_month = date('Y-m', strtotime($join));
    $due_months = monthRange($start_month, $current_month);
    $salary = (float)$e['salary'];

    $details = [];
    $pending_months = [];
    $remaining_total = 0;
    foreach ($due_months as $m) {
        $paid = $paid_by_emp[$e['id']][$m] ?? 0;
        $remaining = max(0, $salary - $paid);
        $remaining_total += $remaining;
        if ($remaining > 0) $pending_months[] = $m;
    }

    if ($remaining_total > 0) $emp_due++;
    $total_pending += $remaining_total;
    $total_pending_months += count($pending_months);
    $rows[] = [
        'emp' => $e,
        'due_months' => $due_months,
        'pending_months' => $pending_months,
        'monthly' => $salary,
        'pending_amount' => $remaining_total,
        'last_paid' => $last_paid[$e['id']] ?? null,
    ];
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- print header (hidden on screen, shows on paper) -->
<div class="d-none d-print-block mb-3">
  <h4 class="font-weight-bold mb-0" style="color:#0f172a;">Mehboob Traders</h4>
  <small class="text-muted">Salary Ledger — as of <?=formatDate(date('Y-m-d'))?></small>
</div>

<div class="row mb-3">
  <div class="col-md-8">
    <div class="alert alert-info alert-dismissible fade show py-2 mb-0" role="alert">
      <i class="fas fa-book"></i> <strong>Salary Ledger</strong> &nbsp;Shows every month since each employee's joining date. Click the months button to see paid / partial / pending months and the remaining balance per month.
      <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span>&times;</span></button>
    </div>
  </div>
  <div class="col-md-4 text-md-right d-print-none">
    <button type="button" class="btn btn-outline-secondary shadow-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
  </div>
</div>

<!-- Stats -->
<div class="row mb-3">
  <div class="col-md-3 col-6 mb-2">
    <div class="small-box bg-slate">
      <div class="inner"><h3><?=count($employees)?></h3><p>Active Employees</p></div>
      <div class="icon"><i class="fas fa-user-tie"></i></div>
    </div>
  </div>
  <div class="col-md-3 col-6 mb-2">
    <div class="small-box bg-orange">
      <div class="inner"><h3><?=$emp_due?></h3><p>Employees With Pending</p></div>
      <div class="icon"><i class="fas fa-hourglass-half"></i></div>
    </div>
  </div>
  <div class="col-md-3 col-6 mb-2">
    <div class="small-box bg-danger" style="background:#ef4444;">
      <div class="inner"><h3><?=formatCurrency($total_pending)?></h3><p>Total Remaining Salary (PKR)</p></div>
      <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
    </div>
  </div>
  <div class="col-md-3 col-6 mb-2">
    <div class="small-box bg-emerald">
      <div class="inner"><h3><?=formatCurrency($total_paid_all)?></h3><p>Total Salary Paid (PKR)</p></div>
      <div class="icon"><i class="fas fa-hand-holding-usd"></i></div>
    </div>
  </div>
</div>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6 class="mb-0"><i class="fas fa-list"></i> Salary Ledger (Monthly)</h6>
    <form method="get" class="form-inline d-print-none">
      <select name="type" class="form-control form-control-sm mr-2" onchange="this.form.submit()">
        <option value="">All Types</option>
        <option value="salesman" <?= $type=='salesman' ? 'selected' : '' ?>>Salesman</option>
        <option value="order_booker" <?= $type=='order_booker' ? 'selected' : '' ?>>Order Booker</option>
        <option value="loader" <?= $type=='loader' ? 'selected' : '' ?>>Loader</option>
      </select>
      <input type="text" id="ledgerSearch" class="form-control form-control-sm" placeholder="Search name / phone / type..." value="<?=htmlspecialchars($q)?>" style="max-width:260px;">
    </form>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered table-hover" id="ledgerTable">
        <thead>
          <tr>
            <th>Employee</th>
            <th>Type</th>
            <th>Joining</th>
            <th class="text-right">Monthly Salary</th>
            <th class="text-center">Pending Months</th>
            <th class="text-right">Remaining Balance</th>
            <th>Last Paid</th>
            <th class="text-center">Months</th>
            <th class="text-center d-print-none">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No active employees found.</td></tr>
          <?php else: foreach ($rows as $r):
            $e = $r['emp'];
            $balClass = $r['pending_amount'] > 0 ? 'balance-negative' : 'balance-positive';
          ?>
            <tr>
              <td class="font-weight-bold"><?=htmlspecialchars($e['full_name'])?></td>
              <td>
                <?php if ($e['employee_type']=='salesman'): ?><span class="badge badge-success">Salesman</span>
                <?php elseif ($e['employee_type']=='order_booker'): ?><span class="badge badge-info">Order Booker</span>
                <?php else: ?><span class="badge badge-warning">Loader</span><?php endif; ?>
              </td>
              <td>
                <?= $e['joining_date'] ? formatDate($e['joining_date']) : '<span class="text-muted" title="Using record date">' . formatDate($e['created_at']) . '*</span>' ?>
              </td>
              <td class="text-right"><?=formatCurrency($r['monthly'])?></td>
              <td class="text-center">
                <?php if (count($r['pending_months']) > 0): ?>
                  <span class="badge badge-danger px-2"><?=count($r['pending_months'])?></span>
                <?php else: ?>
                  <span class="badge badge-success px-2">0</span>
                <?php endif; ?>
              </td>
              <td class="text-right font-weight-bold <?=$balClass?>">PKR <?=formatCurrency($r['pending_amount'])?></td>
              <td><?= $r['last_paid'] ? formatDate($r['last_paid']) : '<span class="text-muted">Never</span>' ?></td>
              <td class="text-center">
                <a href="salary_details.php?employee_id=<?=$e['id']?>" class="btn btn-sm btn-outline-primary" title="View all months"><i class="fas fa-calendar-alt"></i> <?=count($r['due_months'])?></a>
              </td>
              <td class="text-center d-print-none">
                <a href="salary.php?employee_id=<?=$e['id']?>" class="btn btn-sm btn-outline-success" title="Pay Salary"><i class="fas fa-money-check-alt"></i></a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
$('#ledgerSearch').on('keyup', function(){
  var q = $(this).val().toLowerCase();
  $('#ledgerTable tbody tr').each(function(){
    $(this).toggle($(this).text().toLowerCase().indexOf(q) > -1);
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>