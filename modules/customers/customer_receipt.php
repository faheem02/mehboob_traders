<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Receive Payment';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$id = (int)($_GET['id'] ?? 0);
$customer = getById('customers', $id);
if (!$customer) { redirect('customers.php', 'Customer not found', 'error'); }
updateCustomerBalance($pdo, $id);
$customer = getById('customers', $id);

$bank_accounts = $pdo->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (float)($_POST['amount'] ?? 0);
    $receipt_date = $_POST['receipt_date'] ?: date('Y-m-d');
    $method = $_POST['payment_method'] ?: 'cash';
    $bank_id = $method == 'bank' ? ($_POST['bank_account_id'] ?: null) : null;
    $description = trim($_POST['description'] ?? 'Customer payment');

    if ($amount <= 0) {
        redirect('customer_receipt.php?id=' . $id, 'Enter a valid amount', 'error');
    }

    $pdo->beginTransaction();
    try {
        insert('customer_receipts', [
            'customer_id' => $id,
            'amount' => $amount,
            'payment_method' => $method,
            'bank_account_id' => $bank_id,
            'description' => $description,
            'receipt_date' => $receipt_date,
            'created_by' => $_SESSION['user_id'],
            'created_at' => date('Y-m-d'),
        ]);
        $desc = 'Customer receipt: ' . $customer['full_name'] . ' (PKR ' . formatCurrency($amount) . ')';
        if ($method == 'bank') {
            recordBankInflow($pdo, $receipt_date, $amount, $desc, 'customer_receipt', $id, $_SESSION['user_id'], $bank_id);
        } else {
            recordCashInflow($pdo, $receipt_date, $amount, $desc, 'customer_receipt', $id, $_SESSION['user_id']);
        }
        updateCustomerBalance($pdo, $id);
        $pdo->commit();
        logActivity($pdo, 'payment', 'customer', $id, 'Received ' . $amount . ' from ' . $customer['full_name']);
        redirect('customer_view.php?id=' . $id, 'Payment of PKR ' . formatCurrency($amount) . ' received');
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('customer_receipt.php?id=' . $id, 'Error: ' . $e->getMessage(), 'error');
    }
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>
<?php $bal = (float)$customer['current_balance']; ?>
<div class="card shadow">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h6><i class="fas fa-hand-holding-usd"></i> Receive From: <?=htmlspecialchars($customer['full_name'])?></h6>
    <a href="customer_view.php?id=<?=$id?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-arrow-left"></i> Back</a>
  </div>
  <div class="card-body">
    <div class="alert <?= $bal > 0 ? 'alert-danger' : ($bal < 0 ? 'alert-success' : 'alert-secondary') ?> py-2">
      <strong>Current Balance:</strong> <?=$bal > 0 ? 'Receivable PKR '.formatCurrency($bal).' (to receive)' : ($bal < 0 ? 'Advance PKR '.formatCurrency(abs($bal)).' (we owe customer)' : 'Clear (PKR 0)')?>
    </div>

    <form method="post">
      <div class="row">
        <div class="col-md-3 mb-3">
          <label class="form-label">Amount (PKR) *</label>
          <input type="number" name="amount" step="0.01" min="0" class="form-control" required placeholder="0.00">
          <?php if ($bal > 0): ?><small class="text-muted">Receivable: PKR <?=formatCurrency($bal)?></small><?php endif; ?>
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Receipt Date *</label>
          <input type="date" name="receipt_date" class="form-control datepicker" value="<?=date('Y-m-d')?>" required>
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
          <input type="text" name="description" class="form-control" value="Customer payment">
        </div>
        <div class="col-md-4 mb-3 d-flex align-items-end">
          <button type="submit" class="btn btn-success btn-block py-2"><i class="fas fa-check"></i> Confirm Receipt</button>
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