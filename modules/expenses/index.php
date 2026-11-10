<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Expenses';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$categories = $pdo->query("SELECT id, name FROM expense_categories WHERE status = 1 ORDER BY name")->fetchAll();
$bank_accounts = $pdo->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id")->fetchAll();

// Ensure 'Salary' category exists in expense_categories
$salary_cat_id = null;
foreach ($categories as $c) {
    if (strcasecmp($c['name'], 'Salary') === 0) {
        $salary_cat_id = (int)$c['id'];
        break;
    }
}
if (!$salary_cat_id) {
    $chk = $pdo->query("SELECT id FROM expense_categories WHERE LOWER(name) = 'salary' LIMIT 1")->fetch();
    if ($chk) {
        $salary_cat_id = (int)$chk['id'];
    } else {
        $pdo->prepare("INSERT INTO expense_categories (name, description, status, created_at) VALUES ('Salary', 'Employee Salary Payments', 1, CURDATE())")->execute();
        $salary_cat_id = (int)$pdo->lastInsertId();
    }
    $categories = $pdo->query("SELECT id, name FROM expense_categories WHERE status = 1 ORDER BY name")->fetchAll();
}

// A POST that carries a non-empty `id` is an edit and belongs to expense_edit.php - never create from it
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['id'])) {
    $amount = (float)($_POST['amount'] ?? 0);
    $expense_date = $_POST['expense_date'] ?: date('Y-m-d');
    $category_id = $_POST['category_id'] ?: null;
    $method = $_POST['payment_method'] ?: 'cash';
    $bank_id = $method == 'bank' ? ($_POST['bank_account_id'] ?: null) : null;
    $description = trim($_POST['description'] ?? '');
    $vendor = trim($_POST['vendor_name'] ?? '');
    $bill_no = trim($_POST['bill_no'] ?? '');

    if ($amount <= 0) {
        redirect('index.php', 'Enter a valid expense amount', 'error');
    }

    $voucher_no = trim($_POST['voucher_no'] ?? '');
    if ($voucher_no !== '') {
        $chk = $pdo->prepare("SELECT id FROM expenses WHERE voucher_no = ?");
        $chk->execute([$voucher_no]);
        if ($chk->fetch()) $voucher_no = '';
    }
    if ($voucher_no === '') $voucher_no = generateExpenseNo();

    $pdo->beginTransaction();
    try {
        $eid = insert('expenses', [
            'voucher_no' => $voucher_no,
            'category_id' => $category_id,
            'expense_date' => $expense_date,
            'amount' => $amount,
            'description' => $description,
            'vendor_name' => $vendor,
            'bill_no' => $bill_no,
            'payment_method' => $method,
            'bank_account_id' => $bank_id,
            'branch_id' => currentBranchId($pdo),
            'created_by' => $_SESSION['user_id'],
            'created_at' => date('Y-m-d'),
        ]);
        $desc = 'Expense: ' . ($description ?: 'Expense');
        if ($method == 'bank') {
            recordBankOutflow($pdo, $expense_date, $amount, $desc, 'expense', $eid, $_SESSION['user_id'], $bank_id);
        } else {
            recordCashOutflow($pdo, $expense_date, $amount, $desc, 'expense', $eid, $_SESSION['user_id']);
        }
        $pdo->commit();
        logActivity($pdo, 'create', 'expense', $eid, 'Recorded expense PKR ' . $amount);
        redirect('index.php', 'Expense of PKR ' . formatCurrency($amount) . ' recorded');
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('index.php', 'Error: ' . $e->getMessage(), 'error');
    }
}

// Filters
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$cat = $_GET['category_id'] ?? '';

$is_salary_filter = ($cat !== '' && ($cat === 'salary' || ($salary_cat_id !== null && (int)$cat === $salary_cat_id)));

// 1. General Expenses
$general_expenses = [];
if ($cat === '' || !$is_salary_filter) {
    $exp_where = " WHERE 1=1";
    $exp_params = [];
    if ($cat !== '') {
        $exp_where .= " AND e.category_id = ?";
        $exp_params[] = $cat;
    }
    if ($from) { $exp_where .= " AND e.expense_date >= ?"; $exp_params[] = $from; }
    if ($to) { $exp_where .= " AND e.expense_date <= ?"; $exp_params[] = $to; }

    $exp_sql = "SELECT 
        e.id,
        e.voucher_no,
        e.category_id,
        e.expense_date,
        e.amount,
        e.description,
        e.vendor_name,
        e.bill_no,
        e.payment_method,
        e.bank_account_id,
        COALESCE(ec.name, 'Uncategorized') AS cat_name,
        'expense' AS entry_type,
        NULL AS employee_id,
        NULL AS employee_name
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    $exp_where";

    $stmt = $pdo->prepare($exp_sql);
    $stmt->execute($exp_params);
    $general_expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($is_salary_filter && $salary_cat_id !== null) {
    // If Salary is selected, also include any manual expense explicitly filed under Salary category
    $exp_where = " WHERE e.category_id = ?";
    $exp_params = [$salary_cat_id];
    if ($from) { $exp_where .= " AND e.expense_date >= ?"; $exp_params[] = $from; }
    if ($to) { $exp_where .= " AND e.expense_date <= ?"; $exp_params[] = $to; }

    $exp_sql = "SELECT 
        e.id,
        e.voucher_no,
        e.category_id,
        e.expense_date,
        e.amount,
        e.description,
        e.vendor_name,
        e.bill_no,
        e.payment_method,
        e.bank_account_id,
        COALESCE(ec.name, 'Salary') AS cat_name,
        'expense' AS entry_type,
        NULL AS employee_id,
        NULL AS employee_name
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    $exp_where";

    $stmt = $pdo->prepare($exp_sql);
    $stmt->execute($exp_params);
    $general_expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 2. Employee Salaries
$salary_expenses = [];
if ($cat === '' || $is_salary_filter) {
    $sal_where = " WHERE 1=1";
    $sal_params = [];
    if ($from) { $sal_where .= " AND s.payment_date >= ?"; $sal_params[] = $from; }
    if ($to) { $sal_where .= " AND s.payment_date <= ?"; $sal_params[] = $to; }

    $sal_sql = "SELECT 
        s.id,
        s.slip_no AS voucher_no,
        " . ($salary_cat_id ?: "NULL") . " AS category_id,
        s.payment_date AS expense_date,
        s.amount,
        CONCAT('Salary: ', emp.full_name, ' (', s.salary_month, ')', IF(s.notes IS NOT NULL AND s.notes != '', CONCAT(' - ', s.notes), '')) AS description,
        emp.full_name AS vendor_name,
        s.slip_no AS bill_no,
        s.payment_method,
        s.bank_account_id,
        'Salary' AS cat_name,
        'salary' AS entry_type,
        s.employee_id,
        emp.full_name AS employee_name
    FROM employee_salaries s
    JOIN employees emp ON emp.id = s.employee_id
    $sal_where";

    $stmt_sal = $pdo->prepare($sal_sql);
    $stmt_sal->execute($sal_params);
    $salary_expenses = $stmt_sal->fetchAll(PDO::FETCH_ASSOC);
}

// Combine and sort by date DESC, then id DESC
$expenses = array_merge($general_expenses, $salary_expenses);
usort($expenses, function($a, $b) {
    $c = strcmp($b['expense_date'], $a['expense_date']);
    if ($c !== 0) return $c;
    return $b['id'] - $a['id'];
});

$total_expense = 0;
foreach ($expenses as $e) $total_expense += (float)$e['amount'];

$next_voucher_no = generateExpenseNo();
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="row mb-3 d-print-none">
  <div class="col-md-8">
    <div class="alert alert-danger alert-dismissible fade show py-2 mb-0" role="alert">
      <i class="fas fa-wallet"></i> <strong>Expenses</strong> &nbsp;Record shop expenses (cash or bank) and track them here.
      <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span>&times;</span></button>
    </div>
  </div>
  <div class="col-md-4 text-md-right">
    <button type="button" class="btn btn-outline-secondary shadow-sm mr-2" onclick="window.print()">
      <i class="fas fa-print"></i> Print
    </button>
    <button type="button" id="newExpenseBtn" class="btn btn-danger shadow-sm" data-toggle="modal" data-target="#expenseModal">
      <i class="fas fa-plus-circle"></i> New Expense
    </button>
  </div>
</div>

<!-- New Expense Modal -->
<div class="modal fade" id="expenseModal" tabindex="-1" role="dialog" aria-labelledby="expenseModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <form method="post" id="expenseForm">
        <input type="hidden" name="id" id="expId" value="">
        <input type="hidden" name="voucher_no" value="<?=htmlspecialchars($next_voucher_no)?>">
        <input type="hidden" name="ret_from" value="<?=htmlspecialchars($from)?>">
        <input type="hidden" name="ret_to" value="<?=htmlspecialchars($to)?>">
        <input type="hidden" name="ret_cat" value="<?=htmlspecialchars($cat)?>">
        <div class="modal-header">
          <h5 class="modal-title" id="expenseModalLabel"><i class="fas fa-plus-circle text-danger"></i> New Expense</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Voucher No (Auto) *</label>
              <input type="text" id="expVoucherDisplay" class="form-control bg-light font-weight-bold text-danger" value="<?=htmlspecialchars($next_voucher_no)?>" readonly>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Amount (PKR) *</label>
              <input type="number" name="amount" id="expAmount" step="0.01" min="0" class="form-control" required placeholder="0.00">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Expense Date *</label>
              <input type="date" name="expense_date" id="expDate" class="form-control" value="<?=date('Y-m-d')?>" required>
              <small class="text-muted">You can select today's date or any past date.</small>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Category</label>
              <select name="category_id" id="expCategory" class="form-control">
                <option value="">-- Select --</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?=$c['id']?>"><?=htmlspecialchars($c['name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Payment Method</label>
              <select name="payment_method" id="payMethod" class="form-control">
                <option value="cash">Cash</option>
                <option value="bank">Bank</option>
              </select>
            </div>
            <div class="col-md-12 mb-3" id="bankDiv" style="display:none;">
              <label class="form-label">Bank Account</label>
              <select name="bank_account_id" id="expBank" class="form-control">
                <?php foreach ($bank_accounts as $ba): ?>
                <option value="<?=$ba['id']?>"><?=htmlspecialchars($ba['account_name'])?> - <?=htmlspecialchars($ba['bank_name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Vendor / Payee</label>
              <input type="text" name="vendor_name" id="expVendor" class="form-control" placeholder="Optional">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Bill No</label>
              <input type="text" name="bill_no" id="expBill" class="form-control" placeholder="Optional">
            </div>
            <div class="col-md-12 mb-3">
              <label class="form-label">Description</label>
              <input type="text" name="description" id="expDesc" class="form-control" placeholder="e.g. Shop rent">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger" id="expSubmitBtn"><i class="fas fa-check"></i> Save Expense</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6 class="mb-0"><i class="fas fa-file-invoice-dollar"></i> Expense List (<?=count($expenses)?>)</h6>
    <input type="text" id="expenseSearch" class="form-control form-control-sm d-print-none" placeholder="Search date / category / vendor / description" style="max-width:280px;">
  </div>
  <div class="card-body">
    <form method="get" class="row g-2 mb-3 d-print-none align-items-end">
      <div class="col-lg-3 col-md-4 col-sm-6 mb-2">
        <label class="small font-weight-bold text-muted mb-1 d-block"><i class="far fa-calendar-alt"></i> From Date</label>
        <input type="date" name="from" class="form-control" value="<?=htmlspecialchars($from)?>">
      </div>
      <div class="col-lg-3 col-md-4 col-sm-6 mb-2">
        <label class="small font-weight-bold text-muted mb-1 d-block"><i class="far fa-calendar-alt"></i> To Date</label>
        <input type="date" name="to" class="form-control" value="<?=htmlspecialchars($to)?>">
      </div>
      <div class="col-lg-3 col-md-4 col-sm-6 mb-2">
        <label class="small font-weight-bold text-muted mb-1 d-block"><i class="fas fa-tags text-secondary"></i> Category</label>
        <select name="category_id" class="form-control">
          <option value="">All Categories</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?=$c['id']?>" <?= (string)$cat === (string)$c['id'] ? 'selected' : '' ?>><?=htmlspecialchars($c['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-lg-3 col-md-4 col-sm-6 mb-2 d-flex">
        <button type="submit" class="btn btn-primary flex-fill mr-1"><i class="fas fa-filter mr-1"></i> Filter</button>
        <?php if ($from || $to || $cat !== ''): ?>
        <a href="index.php" class="btn btn-outline-secondary" title="Clear Filters"><i class="fas fa-undo"></i></a>
        <?php endif; ?>
      </div>
    </form>

    <!-- Printable header -->
    <div class="d-none d-print-block mb-3 text-center">
      <h4 class="font-weight-bold mb-0" style="color:#0f172a;">Mehboob Traders</h4>
      <small class="text-muted">Wholesale Business</small>
      <h5 class="font-weight-bold text-danger mt-2 mb-0">EXPENSE LIST</h5>
      <?php $e_meta = [];
      if ($from) $e_meta[] = 'From: ' . formatDate($from);
      if ($to) $e_meta[] = 'To: ' . formatDate($to);
      $e_cat_name = '';
      foreach ($categories as $c) if ((string)$c['id'] === (string)$cat) { $e_cat_name = $c['name']; break; }
      if ($is_salary_filter && !$e_cat_name) { $e_cat_name = 'Salary'; }
      if ($e_cat_name) $e_meta[] = 'Category: ' . $e_cat_name;
      if ($e_meta): ?><div class="mt-1 font-weight-bold"><?=implode(' &nbsp;|&nbsp; ', $e_meta)?></div><?php endif; ?>
      <small>Printed on <?=formatDate(date('Y-m-d'))?></small>
    </div>

    <div class="alert alert-danger py-2 text-center d-print-none"><strong>Filtered Total: PKR <?=formatCurrency($total_expense)?></strong></div>
    <div class="d-none d-print-block mb-2"><h6 class="font-weight-bold text-danger">Total: PKR <?=formatCurrency($total_expense)?></h6></div>

    <div class="table-responsive">
      <table class="table table-bordered table-hover" id="expenseTable">
        <thead><tr><th>Voucher No</th><th>Date</th><th>Category</th><th>Description</th><th>Vendor / Payee</th><th>Method</th><th>Amount</th><th class="text-center d-print-none">Action</th></tr></thead>
        <tbody>
          <?php foreach ($expenses as $e): ?>
          <tr>
            <td>
              <?php if (($e['entry_type'] ?? '') === 'salary'): ?>
                <span class="badge badge-light border text-primary font-weight-bold" style="font-size:11px;"><?=htmlspecialchars($e['voucher_no'] ?? '-')?></span>
              <?php else: ?>
                <code class="text-danger font-weight-bold"><?=htmlspecialchars($e['voucher_no'] ?? '-')?></code>
              <?php endif; ?>
            </td>
            <td><?=formatDate($e['expense_date'])?></td>
            <td>
              <?php if (($e['entry_type'] ?? '') === 'salary'): ?>
                <span class="badge badge-info"><i class="fas fa-user-tie mr-1"></i> Salary</span>
              <?php else: ?>
                <span class="badge badge-secondary"><?=htmlspecialchars($e['cat_name'] ?? '-')?></span>
              <?php endif; ?>
            </td>
            <td>
              <?=htmlspecialchars($e['description'] ?? '-')?>
              <?php if (($e['entry_type'] ?? '') === 'salary' && !empty($e['employee_name'])): ?>
                <small class="text-muted d-block"><i class="fas fa-id-card"></i> Staff: <?=htmlspecialchars($e['employee_name'])?></small>
              <?php endif; ?>
            </td>
            <td>
              <?=htmlspecialchars($e['vendor_name'] ?? '-')?>
              <?php if (($e['entry_type'] ?? '') === 'salary'): ?>
                <small class="text-muted d-block">(Staff)</small>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($e['payment_method'] == 'bank'): ?>
                <span class="badge badge-info">Bank</span>
              <?php else: ?>
                <span class="badge badge-secondary">Cash</span>
              <?php endif; ?>
            </td>
            <td class="text-danger font-weight-bold">PKR <?=formatCurrency($e['amount'])?></td>
            <td class="text-center d-print-none">
              <?php if (($e['entry_type'] ?? '') === 'salary'): ?>
                <a href="../employees/salary_slip.php?id=<?=$e['id']?>" target="_blank" class="btn btn-sm btn-outline-primary mr-1" title="Print Salary Slip">
                  <i class="fas fa-print"></i> Slip
                </a>
                <a href="../employees/salary.php?employee_id=<?=(int)($e['employee_id'] ?? 0)?>" class="btn btn-sm btn-outline-info" title="Manage in Salary Payments">
                  <i class="fas fa-external-link-alt"></i>
                </a>
              <?php else: ?>
                <button type="button" class="btn btn-sm btn-outline-warning mr-1" title="Edit Expense"
                  onclick="openEditExpense(this)"
                  data-id="<?=$e['id']?>"
                  data-voucher="<?=htmlspecialchars($e['voucher_no'] ?? '', ENT_QUOTES)?>"
                  data-amount="<?=htmlspecialchars((string)$e['amount'], ENT_QUOTES)?>"
                  data-date="<?=htmlspecialchars($e['expense_date'], ENT_QUOTES)?>"
                  data-category="<?=htmlspecialchars((string)($e['category_id'] ?? ''), ENT_QUOTES)?>"
                  data-desc="<?=htmlspecialchars($e['description'] ?? '', ENT_QUOTES)?>"
                  data-vendor="<?=htmlspecialchars($e['vendor_name'] ?? '', ENT_QUOTES)?>"
                  data-bill="<?=htmlspecialchars($e['bill_no'] ?? '', ENT_QUOTES)?>"
                  data-method="<?=htmlspecialchars($e['payment_method'] ?? 'cash', ENT_QUOTES)?>"
                  data-bank="<?=htmlspecialchars((string)($e['bank_account_id'] ?? ''), ENT_QUOTES)?>"
                ><i class="fas fa-edit"></i></button>
                <form method="post" action="expense_delete.php" class="d-inline" onsubmit="return confirm('Delete expense <?=htmlspecialchars(addslashes($e['voucher_no'] ?? ''))?>: PKR <?=formatCurrency($e['amount'])?>? Its cash/bank entry will be reversed.');">
                  <input type="hidden" name="id" value="<?=$e['id']?>">
                  <input type="hidden" name="ret_from" value="<?=htmlspecialchars($from, ENT_QUOTES)?>">
                  <input type="hidden" name="ret_to" value="<?=htmlspecialchars($to, ENT_QUOTES)?>">
                  <input type="hidden" name="ret_cat" value="<?=htmlspecialchars($cat, ENT_QUOTES)?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Expense"><i class="fas fa-trash"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($expenses)): ?><tr><td colspan="8" class="text-center text-muted py-3">No expenses found</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
function mtExpMethodToggle(){
  $('#bankDiv').toggle($('#payMethod').val() === 'bank');
}
function resetExpenseModal(){
  $('#expenseForm')[0].reset();
  // Must be explicit: setting .value on <input type="hidden"> also rewrites its value
  // ATTRIBUTE (HTML spec quirk), so form.reset() would happily restore the old expense id.
  $('#expId').val('');
  $('#expenseForm').attr('action', '');
  $('#expenseModalLabel').html('<i class="fas fa-plus-circle text-danger"></i> New Expense');
  $('#expSubmitBtn').html('<i class="fas fa-check"></i> Save Expense');
  mtExpMethodToggle();
}
function openEditExpense(btn){
  var $b = $(btn);
  $('#expId').val($b.data('id'));
  $('#expVoucherDisplay').val($b.data('voucher'));
  $('#expAmount').val($b.data('amount'));
  $('#expDate').val($b.data('date'));
  $('#expCategory').val($b.data('category'));
  $('#expDesc').val($b.data('desc'));
  $('#expVendor').val($b.data('vendor'));
  $('#expBill').val($b.data('bill'));
  $('#payMethod').val($b.data('method'));
  if ($b.data('bank')) $('#expBank').val($b.data('bank'));
  mtExpMethodToggle();
  $('#expenseForm').attr('action', 'expense_edit.php');
  $('#expenseModalLabel').html('<i class="fas fa-edit text-warning"></i> Edit Expense');
  $('#expSubmitBtn').html('<i class="fas fa-save"></i> Update Expense');
  $('#expenseModal').modal('show');
}
$(document).ready(function(){
  $('#payMethod').change(mtExpMethodToggle);
  $('#expenseModal').on('hidden.bs.modal', resetExpenseModal);
  $('#newExpenseBtn').on('click', resetExpenseModal);

  $('#expenseSearch').on('keyup', function(){
    var q = $(this).val().toLowerCase();
    $('#expenseTable tbody tr').each(function(){
      $(this).toggle($(this).text().toLowerCase().indexOf(q) > -1);
    });
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>