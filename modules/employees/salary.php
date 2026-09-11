<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Salary Payments';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$bank_accounts = $pdo->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    $salary_month = trim($_POST['salary_month'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $tdate = $_POST['payment_date'] ?: date('Y-m-d');
    $payment_method = $_POST['payment_method'] ?: 'cash';
    $bank_id = $payment_method == 'bank' ? ($_POST['bank_account_id'] ?: null) : null;
    $notes = trim($_POST['description'] ?? '');

    if (!$employee_id) { redirect('salary.php', 'Select an employee', 'error'); }
    if (!preg_match('/^\d{4}-\d{2}$/', $salary_month)) { redirect('salary.php', 'Select a valid salary month', 'error'); }
    if ($amount <= 0) { redirect('salary.php', 'Enter a valid amount', 'error'); }

    $pdo->beginTransaction();
    try {
        $emp = getById('employees', $employee_id);
        if (!$emp) throw new Exception('Employee not found');

        $desc = 'Salary: ' . $emp['full_name'] . ' (' . $salary_month . ')';

        if ($id) {
            $old = getById('employee_salaries', $id);
            if (!$old) throw new Exception('Salary payment not found');
            removeSalaryLedger($pdo, $id);
            update('employee_salaries', [
                'employee_id' => $employee_id,
                'salary_month' => $salary_month,
                'amount' => $amount,
                'payment_method' => $payment_method,
                'bank_account_id' => $bank_id,
                'payment_date' => $tdate,
                'notes' => $notes,
            ], $id);
            $salary_id = $id;
        } else {
            $slip_no = generateSalaryNo();
            $salary_id = insert('employee_salaries', [
                'slip_no' => $slip_no,
                'employee_id' => $employee_id,
                'salary_month' => $salary_month,
                'amount' => $amount,
                'payment_method' => $payment_method,
                'bank_account_id' => $bank_id,
                'payment_date' => $tdate,
                'notes' => $notes,
                'created_by' => $_SESSION['user_id'],
                'created_at' => date('Y-m-d'),
            ]);
        }

        if ($payment_method == 'bank') {
            recordBankOutflow($pdo, $tdate, $amount, $desc, 'salary', $salary_id, $_SESSION['user_id'], $bank_id);
        } else {
            recordCashOutflow($pdo, $tdate, $amount, $desc, 'salary', $salary_id, $_SESSION['user_id']);
        }

        if ($id) {
            logActivity($pdo, 'update', 'salary', $salary_id, 'Updated salary payment for ' . $emp['full_name'] . ' PKR ' . $amount . ' (' . $salary_month . ')');
            $pdo->commit();
            redirect('salary.php', 'Salary payment updated: ' . $emp['full_name'] . ' (PKR ' . formatCurrency($amount) . ')');
        } else {
            logActivity($pdo, 'pay', 'salary', $salary_id, 'Paid salary to ' . $emp['full_name'] . ' PKR ' . $amount . ' for ' . $salary_month);
            $pdo->commit();
            redirect('salary.php', 'Salary paid: ' . $slip_no . ' to ' . $emp['full_name'] . ' (PKR ' . formatCurrency($amount) . ')');
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('salary.php', 'Error: ' . $e->getMessage(), 'error');
    }
}

$preselect = (int)($_GET['employee_id'] ?? 0);
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$employees = $pdo->query("SELECT id, full_name, employee_type, salary FROM employees WHERE status = 1 ORDER BY full_name")->fetchAll();

$filter_sql = "";
$filter_params = [];
if ($from !== '') { $filter_sql .= " AND s.payment_date >= ?"; $filter_params[] = $from; }
if ($to !== '') { $filter_sql .= " AND s.payment_date <= ?"; $filter_params[] = $to; }

$stmt = $pdo->prepare("SELECT s.*, e.full_name, e.employee_type, e.salary AS emp_salary, ba.account_name
    FROM employee_salaries s
    JOIN employees e ON e.id = s.employee_id
    LEFT JOIN bank_accounts ba ON ba.id = s.bank_account_id
    WHERE 1=1" . $filter_sql . "
    ORDER BY s.payment_date DESC, s.id DESC");
$stmt->execute($filter_params);
$payments = $stmt->fetchAll();

$this_month = date('Y-m');
$this_total = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM employee_salaries WHERE payment_date LIKE '" . $this_month . "%'")->fetchColumn();

$total_paid = 0;
foreach ($payments as $p) $total_paid += $p['amount'];

$period_label = '';
if ($from !== '' && $to !== '') $period_label = formatDate($from) . ' to ' . formatDate($to);
elseif ($from !== '') $period_label = 'From ' . formatDate($from);
elseif ($to !== '') $period_label = 'Till ' . formatDate($to);

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="row mb-3">
  <div class="col-md-8">
    <div class="alert alert-warning alert-dismissible fade show py-2 mb-0" role="alert">
      <i class="fas fa-money-check-alt"></i> <strong>Salary Payments</strong> &nbsp;Record monthly salary paid to employees. A printable salary slip is generated for each payment.
      <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span>&times;</span></button>
    </div>
  </div>
  <div class="col-md-4 text-md-right">
    <button type="button" class="btn btn-warning shadow-sm" data-toggle="modal" data-target="#salaryModal">
      <i class="fas fa-money-check-alt"></i> Pay Salary
    </button>
  </div>
</div>

<!-- Stats -->
<div class="row mb-3">
  <div class="col-md-3 col-6 mb-2">
    <div class="small-box bg-slate">
      <div class="inner"><h3><?=count($payments)?></h3><p>Total Slips</p></div>
      <div class="icon"><i class="fas fa-file-invoice"></i></div>
    </div>
  </div>
  <div class="col-md-3 col-6 mb-2">
    <div class="small-box bg-emerald">
      <div class="inner"><h3><?=formatCurrency($total_paid)?></h3><p>Total Paid (PKR)</p></div>
      <div class="icon"><i class="fas fa-hand-holding-usd"></i></div>
    </div>
  </div>
  <div class="col-md-3 col-6 mb-2">
    <div class="small-box bg-blue">
      <div class="inner"><h3><?=formatCurrency($this_total)?></h3><p>This Month (PKR)</p></div>
      <div class="icon"><i class="fas fa-calendar-alt"></i></div>
    </div>
  </div>
  <div class="col-md-3 col-6 mb-2">
    <div class="small-box bg-orange">
      <div class="inner"><h3><?=count($employees)?></h3><p>Active Employees</p></div>
      <div class="icon"><i class="fas fa-user-tie"></i></div>
    </div>
  </div>
</div>

<!-- Date Filter Toolbar -->
<div class="card shadow mb-3 d-print-none">
  <div class="card-body py-2">
    <form method="get" class="form-inline flex-wrap">
      <label class="mr-2 mb-1"><i class="fas fa-calendar-alt"></i> From:</label>
      <input type="date" name="from" class="form-control form-control-sm mr-3 mb-1" value="<?=htmlspecialchars($from)?>">
      <label class="mr-2 mb-1">To:</label>
      <input type="date" name="to" class="form-control form-control-sm mr-3 mb-1" value="<?=htmlspecialchars($to)?>">
      <button class="btn btn-sm btn-outline-primary mr-2 mb-1"><i class="fas fa-filter"></i> Filter</button>
      <button type="button" class="btn btn-sm btn-outline-secondary mr-2 mb-1" onclick="location.href='salary.php'"><i class="fas fa-times"></i> All</button>
      <a href="salary.php?from=<?=date('Y-m-01')?>&to=<?=date('Y-m-t')?>" class="btn btn-sm btn-outline-info mb-1">This Month</a>
      <?php if ($period_label): ?>
      <span class="badge badge-primary ml-md-2 mb-1" style="background:#3b82f6;"><i class="fas fa-calendar-alt"></i> <?=htmlspecialchars($period_label)?></span>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- Pay Salary Modal -->
<div class="modal fade" id="salaryModal" tabindex="-1" role="dialog" aria-labelledby="salaryModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="method" value="pay_salary">
        <input type="hidden" name="id" id="salaryId" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="salaryModalLabel"><i class="fas fa-money-check-alt text-warning"></i> Pay Salary</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Select Employee *</label>
              <div class="ac-wrap">
                <input type="text" id="employeeSearch" class="form-control" placeholder="Type employee name to search..." autocomplete="off">
                <input type="hidden" name="employee_id" id="employee_id">
                <div class="ac-list" id="employeeList"></div>
              </div>
              <small class="text-danger d-none" id="employeeError"><i class="fas fa-exclamation-circle"></i> Please select an employee from the suggestions.</small>
              <small class="text-muted" id="salaryHint"></small>
            </div>
            <div class="col-md-3 mb-3">
              <label class="form-label">Salary Month *</label>
              <input type="month" name="salary_month" id="salaryMonth" class="form-control" value="<?=date('Y-m')?>" required>
            </div>
            <div class="col-md-3 mb-3">
              <label class="form-label">Amount (PKR) *</label>
              <input type="number" name="amount" id="salaryAmount" step="0.01" min="0" class="form-control" required placeholder="0.00">
            </div>
          </div>
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">Payment Date *</label>
              <input type="date" name="payment_date" id="paymentDate" class="form-control datepicker" value="<?=date('Y-m-d')?>" required>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Method</label>
              <select name="payment_method" id="payMethod" class="form-control">
                <option value="cash">Cash</option>
                <option value="bank">Bank</option>
              </select>
            </div>
            <div class="col-md-4 mb-3" id="bankDiv" style="display:none;">
              <label class="form-label">Bank Account</label>
              <select name="bank_account_id" id="bankAccountId" class="form-control">
                <?php foreach ($bank_accounts as $ba): ?>
                <option value="<?=$ba['id']?>"><?=htmlspecialchars($ba['account_name'])?> - <?=htmlspecialchars($ba['bank_name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-12 mb-3">
              <label class="form-label">Notes / Description</label>
              <input type="text" name="description" class="form-control" placeholder="Optional notes">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning" id="salarySubmitBtn"><i class="fas fa-check"></i> Confirm Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6 class="mb-0"><i class="fas fa-list"></i> All Salary Payments (<?=count($payments)?>)</h6>
    <input type="text" id="paySearch" class="form-control form-control-sm" placeholder="Search employee / date / description" style="max-width:260px;">
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered" id="payHistoryTable">
        <thead>
          <tr><th>#</th><th>Slip No</th><th>Date</th><th>Employee</th><th>Type</th><th>Month</th><th>Method</th><th class="text-right">Amount</th><th class="text-center">Action</th></tr>
        </thead>
        <tbody>
          <?php if (empty($payments)): ?>
            <tr><td colspan="9" class="text-center text-muted py-3">No salary payments yet.</td></tr>
          <?php else: $i = 0; foreach ($payments as $r): $i++; ?>
            <tr>
              <td><?=$i?></td>
              <td class="text-muted"><?=htmlspecialchars($r['slip_no'])?></td>
              <td><?=formatDate($r['payment_date'])?></td>
              <td class="font-weight-bold"><?=htmlspecialchars($r['full_name'])?></td>
              <td>
                <?php if ($r['employee_type']=='salesman'): ?><span class="badge badge-success">Salesman</span>
                <?php elseif ($r['employee_type']=='order_booker'): ?><span class="badge badge-info">Order Booker</span>
                <?php else: ?><span class="badge badge-warning">Loader</span><?php endif; ?>
              </td>
              <td><?=date('M Y', strtotime($r['salary_month'] . '-01'))?></td>
              <td>
                <?php if ($r['payment_method'] == 'bank'): ?>
                  <span class="badge badge-info">Bank</span> <?=htmlspecialchars($r['account_name'] ?? '')?>
                <?php else: ?>
                  <span class="badge badge-secondary">Cash</span>
                <?php endif; ?>
              </td>
              <td class="text-right font-weight-bold">PKR <?=formatCurrency($r['amount'])?></td>
              <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-warning mr-1" title="Edit Salary Payment"
                  onclick="openEditSalary(<?= $r['id'] ?>)"><i class="fas fa-edit"></i></button>
                <form method="post" action="salary_delete.php" class="d-inline" onsubmit="return confirm('Delete salary payment <?=htmlspecialchars(addslashes($r['slip_no']))?>: PKR <?=formatCurrency($r['amount'])?>? Its cash/bank entry will be reversed.');">
                  <input type="hidden" name="id" value="<?=$r['id']?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger mr-1" title="Delete Salary Payment"><i class="fas fa-trash"></i></button>
                </form>
                <a href="salary_slip.php?id=<?=$r['id']?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Print Salary Slip"><i class="fas fa-print"></i></a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
function mtEsc(s){
  return String(s == null ? '' : s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function mtHideList($list){ $list.empty().hide(); }
function mtRenderList($list, items){
  $list.empty();
  if (!items || !items.length) {
    $list.append('<div class="ac-item ac-empty">No matching record found</div>');
  } else {
    $.each(items, function(i, it){
      var sub = [];
      if (it.employee_type) sub.push(it.employee_type.replace(/_/g, ' ').replace(/\b\w/g, function(l){ return l.toUpperCase(); }));
      if (it.phone) sub.push('Phone: ' + mtEsc(it.phone));
      if (it.salary) sub.push('Salary: PKR ' + Number(it.salary).toLocaleString(undefined, {minimumFractionDigits: 2}));
      $list.append($('<div class="ac-item" data-id="' + it.id + '" data-salary="' + it.salary + '" data-name="' + mtEsc(it.name) + '">' +
        '<span class="ac-name">' + mtEsc(it.name) + '</span>' +
        (sub.length ? '<small class="ac-sub">' + sub.join(' &middot; ') + '</small>' : '') +
        '</div>'));
    });
  }
  $list.show();
}

$(document).ready(function(){
  var preselect = <?= $preselect ? 'true' : 'false' ?>;
  if (preselect) { $('#salaryModal').modal('show'); }

  function pickEmployee($item){
    $('#employee_id').val($item.data('id'));
    $('#employeeSearch').val($item.data('name'));
    $('#employeeError').addClass('d-none');
    $('#salaryAmount').val(Number($item.data('salary')) > 0 ? Number($item.data('salary')).toFixed(2) : '');
    $('#salaryHint').text(Number($item.data('salary')) > 0 ? 'Monthly salary: PKR ' + Number($item.data('salary')).toLocaleString(undefined, {minimumFractionDigits: 2}) : '');
    mtHideList($('#employeeList'));
  }

  var salaryRows = <?= json_encode(array_map(function($r) {
    return [
      'id' => (int)$r['id'],
      'employee_id' => (int)$r['employee_id'],
      'employee_name' => $r['full_name'],
      'employee_salary' => (float)$r['emp_salary'],
      'salary_month' => $r['salary_month'],
      'amount' => (float)$r['amount'],
      'payment_date' => $r['payment_date'],
      'payment_method' => $r['payment_method'],
      'bank_account_id' => (int)($r['bank_account_id'] ?? 0),
      'notes' => $r['notes'],
    ];
  }, $payments), JSON_UNESCAPED_UNICODE) ?>;

  window.openEditSalary = function(id){
    var rec = null;
    for (var i = 0; i < salaryRows.length; i++) { if (salaryRows[i].id === id) { rec = salaryRows[i]; break; } }
    if (!rec) return;

    $('#salaryId').val(rec.id);
    $('#employee_id').val(rec.employee_id);
    $('#employeeSearch').val(rec.employee_name);
    $('#salaryMonth').val(rec.salary_month);
    $('#salaryAmount').val(rec.amount.toFixed(2));
    $('#paymentDate').val(rec.payment_date);
    $('#payMethod').val(rec.payment_method).trigger('change');
    if (rec.bank_account_id) $('#bankAccountId').val(rec.bank_account_id);
    $('#salaryModal form input[name="description"]').val(rec.notes);
    $('#employeeError').addClass('d-none');
    $('#salaryHint').text(rec.employee_salary > 0 ? 'Monthly salary: PKR ' + rec.employee_salary.toLocaleString(undefined, {minimumFractionDigits: 2}) : '');
    $('#salaryModalLabel').html('<i class="fas fa-money-check-alt text-warning"></i> Edit Salary Payment');
    $('#salarySubmitBtn').html('<i class="fas fa-save"></i> Update Payment');
    $('#salaryModal').modal('show');
  };

  $('#salaryModal').on('show.bs.modal', function(){
    if ($('#salaryId').val() == 0) {
      // Fresh "Pay Salary": reset form defaults
      $('#salaryModal form')[0].reset();
      $('#salaryId').val(0);
      $('#employee_id').val('');
      $('#salaryMonth').val('<?=date('Y-m')?>');
      $('#paymentDate').val('<?=date('Y-m-d')?>');
      $('#payMethod').val('cash').trigger('change');
      $('#salaryModalLabel').html('<i class="fas fa-money-check-alt text-warning"></i> Pay Salary');
      $('#salarySubmitBtn').html('<i class="fas fa-check"></i> Confirm Payment');
      $('#salaryHint').text('');
      $('#employeeError').addClass('d-none');
    }
  });

  $('#payMethod').change(function(){ $('#bankDiv').toggle(this.value === 'bank'); });

  var empTimer = null;
  $('#employeeSearch').on('input', function(){
    var q = $.trim(this.value);
    clearTimeout(empTimer);
    if (!q) {
      $('#employee_id').val('');
      $('#salaryHint').text('');
      $('#employeeError').addClass('d-none');
      mtHideList($('#employeeList'));
      return;
    }
    empTimer = setTimeout(function(){
      $.get('ajax_employee_search.php', {q: q}, function(data){
        mtRenderList($('#employeeList'), data);
      });
    }, 250);
  });

  $('#employeeList').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    pickEmployee($(this));
  });

  $(document).on('keydown', '#employeeSearch', function(e){
    var $list = $('#employeeList');
    var items = $list.find('.ac-item:not(.ac-empty)');
    if (!$list.is(':visible') || !items.length) return;
    var idx = items.index(items.filter('.active'));
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      var dir = e.key === 'ArrowDown' ? 1 : -1;
      idx = (idx + dir + items.length) % items.length;
      items.removeClass('active').eq(idx).addClass('active');
    } else if (e.key === 'Enter') {
      e.preventDefault();
      var target = idx >= 0 ? items.eq(idx) : items.first();
      if (target.length) target.trigger('mousedown');
    } else if (e.key === 'Escape') {
      mtHideList($list);
    }
  });

  $(document).on('mouseover', '.ac-item', function(){
    $(this).addClass('active').siblings().removeClass('active');
  });
  $(document).on('mousedown', function(e){
    if (!$(e.target).closest('.ac-wrap').length) {
      $('.ac-list').empty().hide();
    }
  });

  // Legacy: employees list "Pay Salary" links via ?employee_id=
  var preselectId = <?= (int)$preselect ?: 0 ?>;
  if (preselectId) {
    $.get('ajax_employee_search.php', {q: ''}, function(){});
    // find employee directly in preloaded list by name
    <?php if ($preselect): $sel = getById('employees', $preselect); ?>
    var $fake = $('<div data-id="<?=$preselect?>" data-salary="<?=(float)$sel['salary']?>" data-name="<?=htmlspecialchars($sel['full_name'])?>"></div>');
    pickEmployee($fake);
    <?php endif; ?>
  }

  $('#salaryModal form').on('submit', function(e){
    if (!$('#employee_id').val()) {
      e.preventDefault();
      $('#employeeError').removeClass('d-none');
      $('#employeeSearch').focus();
    }
  });

  $('#paySearch').on('keyup', function(){
    var q = $(this).val().toLowerCase();
    $('#payHistoryTable tbody tr').each(function(){
      $(this).toggle($(this).text().toLowerCase().indexOf(q) > -1);
    });
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>