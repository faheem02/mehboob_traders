<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Pay Supplier';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$id = (int)($_GET['id'] ?? 0);
$supplier = getById('suppliers', $id);
if (!$supplier) { redirect('suppliers.php', 'Supplier not found', 'error'); }
updateSupplierBalance($pdo, $id);
$supplier = getById('suppliers', $id);

$bank_accounts = $pdo->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (float)($_POST['amount'] ?? 0);
    $payment_date = $_POST['payment_date'] ?: date('Y-m-d');
    $method = $_POST['payment_method'] ?: 'cash';
    $bank_id = $method == 'bank' ? ($_POST['bank_account_id'] ?: null) : null;
    $description = trim($_POST['description'] ?? 'Supplier payment');

    if ($amount <= 0) {
        redirect('supplier_payment.php?id=' . $id, 'Enter a valid amount', 'error');
    }

    $pdo->beginTransaction();
    try {
        insert('supplier_payments', [
            'supplier_id' => $id,
            'amount' => $amount,
            'payment_method' => $method,
            'bank_account_id' => $bank_id,
            'description' => $description,
            'payment_date' => $payment_date,
            'created_by' => $_SESSION['user_id'],
            'created_at' => date('Y-m-d'),
        ]);
        $desc = 'Supplier payment: ' . $supplier['name'] . ' (PKR ' . formatCurrency($amount) . ')';
        if ($method == 'bank') {
            recordBankOutflow($pdo, $payment_date, $amount, $desc, 'supplier_payment', $id, $_SESSION['user_id'], $bank_id);
        } else {
            recordCashOutflow($pdo, $payment_date, $amount, $desc, 'supplier_payment', $id, $_SESSION['user_id']);
        }
        updateSupplierBalance($pdo, $id);
        $pdo->commit();
        logActivity($pdo, 'pay', 'supplier', $id, 'Paid supplier ' . $supplier['name'] . ' PKR ' . $amount);
        redirect('supplier_view.php?id=' . $id, 'Payment of PKR ' . formatCurrency($amount) . ' recorded');
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('supplier_payment.php?id=' . $id, 'Error: ' . $e->getMessage(), 'error');
    }
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>
<?php $bal = (float)$supplier['current_balance']; ?>
<div class="card shadow">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h6><i class="fas fa-coins"></i> Pay: <?=htmlspecialchars($supplier['name'])?></h6>
    <a href="supplier_view.php?id=<?=$id?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-arrow-left"></i> Back</a>
  </div>
  <div class="card-body">
    <div class="alert <?= $bal > 0 ? 'alert-danger' : ($bal < 0 ? 'alert-success' : 'alert-secondary') ?> py-2">
      <strong>Current Balance:</strong> <?=$bal > 0 ? 'PKR '.formatCurrency($bal).' payable (we owe)' : ($bal < 0 ? 'Advance PKR '.formatCurrency(abs($bal)).' (supplier owes us)' : 'Clear (PKR 0)')?>
    </div>

    <form method="post">
      <div class="row">
        <div class="col-md-3 mb-3">
          <label class="form-label">Amount (PKR) *</label>
          <input type="number" name="amount" step="0.01" min="0" class="form-control" required placeholder="0.00">
          <?php if ($bal > 0): ?><small class="text-muted">Payable: PKR <?=formatCurrency($bal)?></small><?php endif; ?>
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Payment Date *</label>
          <input type="date" name="payment_date" class="form-control datepicker" value="<?=date('Y-m-d')?>" required>
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Method</label>
          <select name="payment_method" id="payMethod" class="form-control">
            <option value="cash">Cash</option>
            <option value="bank">Bank</option>
          </select>
        </div>
        <div class="col-md-3 mb-3" id="bankDiv" style="display:none;">
          <label class="form-label">Bank Account</label>
          <select name="bank_account_id" class="form-control">
            <?php foreach ($bank_accounts as $ba): ?>
            <option value="<?=$ba['id']?>"><?=htmlspecialchars($ba['account_name'])?> - <?=htmlspecialchars($ba['bank_name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-8 mb-3">
          <label class="form-label">Description / Notes</label>
          <input type="text" name="description" class="form-control" value="Supplier payment">
        </div>
        <div class="col-md-4 mb-3 d-flex align-items-end">
          <button type="submit" class="btn btn-success btn-block py-2"><i class="fas fa-check"></i> Confirm Payment</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
$(document).ready(function(){
  $('#payMethod').change(function(){ $('#bankDiv').toggle(this.value === 'bank'); });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>