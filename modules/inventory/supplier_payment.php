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
    $purchase_id = (int)($_POST['purchase_id'] ?? 0) ?: null;

    if ($amount <= 0) {
        redirect('supplier_payment.php?id=' . $id, 'Enter a valid amount', 'error');
    }

    $pdo->beginTransaction();
    try {
        $rows_to_insert = [];
        if ($purchase_id) {
            syncSupplierPurchasePayments($pdo, $id);
            $pq = $pdo->prepare("SELECT id, total_amount, paid_amount, invoice_no FROM purchases WHERE id = ? AND supplier_id = ? AND status <> 'cancelled'");
            $pq->execute([$purchase_id, $id]);
            $pur = $pq->fetch();
            if ($pur) {
                $due = max(0, round((float)$pur['total_amount'] - (float)$pur['paid_amount'], 2));
                $to_inv = min($amount, $due);
                $excess = round($amount - $to_inv, 2);
                $rows_to_insert[] = ['amount' => $to_inv, 'purchase_id' => $purchase_id, 'description' => ($description ?: 'Supplier payment') . ' — ' . $pur['invoice_no']];
                if ($excess > 0.004) {
                    $rows_to_insert[] = ['amount' => $excess, 'purchase_id' => null, 'description' => ($description ?: 'Supplier payment') . ' — advance / balance (no invoice)'];
                }
            } else {
                $rows_to_insert[] = ['amount' => $amount, 'purchase_id' => null, 'description' => $description ?: 'Supplier payment'];
            }
        } else {
            $rows_to_insert[] = ['amount' => $amount, 'purchase_id' => null, 'description' => $description ?: 'Supplier payment'];
        }

        foreach ($rows_to_insert as $r) {
            insert('supplier_payments', [
                'supplier_id'    => $id,
                'purchase_id'    => $r['purchase_id'],
                'amount'         => $r['amount'],
                'payment_method' => $method,
                'bank_account_id' => $bank_id,
                'description'    => $r['description'],
                'payment_date'   => $payment_date,
                'created_by'     => $_SESSION['user_id'],
                'created_at'     => date('Y-m-d'),
            ]);
        }

        $desc = 'Supplier payment: ' . $supplier['name'] . ' (PKR ' . formatCurrency($amount) . ')';
        if ($method == 'bank') {
            recordBankOutflow($pdo, $payment_date, $amount, $desc, 'supplier_payment', $id, $_SESSION['user_id'], $bank_id);
        } else {
            recordCashOutflow($pdo, $payment_date, $amount, $desc, 'supplier_payment', $id, $_SESSION['user_id']);
        }
        syncSupplierPurchasePayments($pdo, $id);
        updateSupplierBalance($pdo, $id);
        $pdo->commit();
        logActivity($pdo, 'pay', 'supplier', $id, 'Paid supplier ' . $supplier['name'] . ' PKR ' . $amount);
        redirect('supplier_view.php?id=' . $id, 'Payment of PKR ' . formatCurrency($amount) . ' recorded');
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('supplier_payment.php?id=' . $id, 'Error: ' . $e->getMessage(), 'error');
    }
}

$open_purchases = $pdo->prepare("SELECT id, invoice_no, purchase_date, total_amount, paid_amount, due_amount FROM purchases WHERE supplier_id = ? AND status <> 'cancelled' AND due_amount > 0.001 ORDER BY purchase_date ASC, id ASC");
$open_purchases->execute([$id]);
$open_purchases = $open_purchases->fetchAll();

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
        <div class="col-md-12 mb-3">
          <label class="form-label">Pay Against Invoice <small class="text-muted">(optional — reduces that invoice's Due)</small></label>
          <select name="purchase_id" id="purchaseSelect" class="form-control">
            <option value="">— General / No invoice —</option>
            <?php foreach ($open_purchases as $op): ?>
            <option value="<?=$op['id']?>" data-due="<?=$op['due_amount']?>"><?=htmlspecialchars($op['invoice_no'])?> (Due: PKR <?=formatCurrency($op['due_amount'])?>, <?=formatDate($op['purchase_date'])?>)</option>
            <?php endforeach; ?>
          </select>
          <small class="text-muted" id="purchaseHint" style="display:none;">Selecting an invoice pre-fills its due amount.</small>
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

  $('#purchaseSelect').on('change', function(){
    var due = $(this).find(':selected').data('due');
    if (due !== undefined && due > 0) {
      $('input[name="amount"]').val(due);
      $('#purchaseHint').show();
    } else {
      $('#purchaseHint').hide();
    }
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>