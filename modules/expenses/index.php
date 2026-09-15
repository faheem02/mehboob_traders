<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Expenses';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$categories = $pdo->query("SELECT id, name FROM expense_categories WHERE status = 1 ORDER BY name")->fetchAll();
$bank_accounts = $pdo->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    $pdo->beginTransaction();
    try {
        $eid = insert('expenses', [
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
$sql = "SELECT e.*, ec.name AS cat_name FROM expenses e LEFT JOIN expense_categories ec ON e.category_id = ec.id WHERE 1=1";
$params = [];
if ($from) { $sql .= " AND e.expense_date >= ?"; $params[] = $from; }
if ($to) { $sql .= " AND e.expense_date <= ?"; $params[] = $to; }
if ($cat !== '') { $sql .= " AND e.category_id = ?"; $params[] = $cat; }
$sql .= " ORDER BY e.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll();

$total_expense = 0;
foreach ($expenses as $e) $total_expense += $e['amount'];

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
    <button type="button" class="btn btn-danger shadow-sm" data-toggle="modal" data-target="#expenseModal">
      <i class="fas fa-plus-circle"></i> New Expense
    </button>
  </div>
</div>

<!-- New Expense Modal -->
<div class="modal fade" id="expenseModal" tabindex="-1" role="dialog" aria-labelledby="expenseModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title" id="expenseModalLabel"><i class="fas fa-plus-circle text-danger"></i> New Expense</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Amount (PKR) *</label>
              <input type="number" name="amount" step="0.01" min="0" class="form-control" required placeholder="0.00">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Date *</label>
              <input type="date" name="expense_date" class="form-control datepicker" value="<?=date('Y-m-d')?>" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Category</label>
              <select name="category_id" class="form-control">
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
              <select name="bank_account_id" class="form-control">
                <?php foreach ($bank_accounts as $ba): ?>
                <option value="<?=$ba['id']?>"><?=htmlspecialchars($ba['account_name'])?> - <?=htmlspecialchars($ba['bank_name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Vendor / Payee</label>
              <input type="text" name="vendor_name" class="form-control" placeholder="Optional">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Bill No</label>
              <input type="text" name="bill_no" class="form-control" placeholder="Optional">
            </div>
            <div class="col-md-12 mb-3">
              <label class="form-label">Description</label>
              <input type="text" name="description" class="form-control" placeholder="e.g. Shop rent">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-check"></i> Save Expense</button>
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
    <form method="get" class="row g-2 mb-3 d-print-none">
      <div class="col-md-3"><input type="date" name="from" class="form-control datepicker" value="<?=htmlspecialchars($from)?>"></div>
      <div class="col-md-3"><input type="date" name="to" class="form-control datepicker" value="<?=htmlspecialchars($to)?>"></div>
      <div class="col-md-4">
        <select name="category_id" class="form-control">
          <option value="">All Categories</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?=$c['id']?>" <?= $cat == $c['id'] ? 'selected' : '' ?>><?=htmlspecialchars($c['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button class="btn btn-outline-primary btn-block"><i class="fas fa-filter"></i> Go</button>
        <?php if ($from || $to || $cat !== ''): ?>
        <a href="index.php" class="btn btn-outline-secondary btn-block mt-1"><i class="fas fa-times"></i> All</a>
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
      if ($e_cat_name) $e_meta[] = 'Category: ' . $e_cat_name;
      if ($e_meta): ?><div class="mt-1 font-weight-bold"><?=implode(' &nbsp;|&nbsp; ', $e_meta)?></div><?php endif; ?>
      <small>Printed on <?=formatDate(date('Y-m-d'))?></small>
    </div>

    <div class="alert alert-danger py-2 text-center d-print-none"><strong>Filtered Total: PKR <?=formatCurrency($total_expense)?></strong></div>
    <div class="d-none d-print-block mb-2"><h6 class="font-weight-bold text-danger">Total: PKR <?=formatCurrency($total_expense)?></h6></div>

    <div class="table-responsive">
      <table class="table table-bordered table-hover" id="expenseTable">
        <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Vendor</th><th>Method</th><th>Amount</th></tr></thead>
        <tbody>
          <?php foreach ($expenses as $e): ?>
          <tr>
            <td><?=formatDate($e['expense_date'])?></td>
            <td><?=htmlspecialchars($e['cat_name'] ?? '-')?></td>
            <td><?=htmlspecialchars($e['description'] ?? '-')?></td>
            <td><?=htmlspecialchars($e['vendor_name'] ?? '-')?></td>
            <td>
              <?php if ($e['payment_method'] == 'bank'): ?>
                <span class="badge badge-info">Bank</span>
              <?php else: ?>
                <span class="badge badge-secondary">Cash</span>
              <?php endif; ?>
            </td>
            <td class="text-danger font-weight-bold">PKR <?=formatCurrency($e['amount'])?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($expenses)): ?><tr><td colspan="6" class="text-center text-muted py-3">No expenses yet</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
$(document).ready(function(){
  $('#payMethod').change(function(){ $('#bankDiv').toggle(this.value === 'bank'); });

  $('#expenseSearch').on('keyup', function(){
    var q = $(this).val().toLowerCase();
    $('#expenseTable tbody tr').each(function(){
      $(this).toggle($(this).text().toLowerCase().indexOf(q) > -1);
    });
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>