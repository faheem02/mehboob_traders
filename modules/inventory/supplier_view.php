<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Supplier Ledger';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$id = (int)($_GET['id'] ?? 0);
$supplier = getById('suppliers', $id);
if (!$supplier) { redirect('suppliers.php', 'Supplier not found', 'error'); }

updateSupplierBalance($pdo, $id);
$supplier = getById('suppliers', $id);

$opening = (float)($supplier['opening_balance'] ?? 0) + (float)($supplier['adjustment'] ?? 0);

// Purchases (increases payable)
$purchases = $pdo->prepare("SELECT invoice_no, purchase_date, total_amount, paid_amount FROM purchases WHERE supplier_id = ? AND status <> 'cancelled' ORDER BY purchase_date ASC, id ASC");
$purchases->execute([$id]);
$purchases = $purchases->fetchAll();

// Payments (decreases payable)
$payments = $pdo->prepare("SELECT id, payment_date, amount, payment_method, description FROM supplier_payments WHERE supplier_id = ? ORDER BY payment_date ASC, id ASC");
$payments->execute([$id]);
$payments = $payments->fetchAll();

// Build chronological ledger rows
$rows = [];
$rows[] = ['date' => $supplier['created_at'] ?? $supplier['updated_at'] ?? date('Y-m-d'), 'sort' => 0, 'desc' => 'Opening Balance', 'debit' => $opening > 0 ? $opening : 0, 'credit' => $opening < 0 ? abs($opening) : 0, 'method' => '', 'type' => 'opening', 'link' => null];
foreach ($purchases as $p) {
    $rows[] = ['date' => $p['purchase_date'], 'sort' => 1, 'desc' => 'Purchase #' . $p['invoice_no'], 'debit' => (float)($p['total_amount'] - $p['paid_amount']), 'credit' => 0, 'method' => '', 'type' => 'purchase', 'link' => 'index.php' ];
}
foreach ($payments as $pm) {
    $rows[] = ['date' => $pm['payment_date'], 'sort' => 2, 'desc' => $pm['description'] ?: 'Payment Made', 'debit' => 0, 'credit' => (float)$pm['amount'], 'method' => $pm['payment_method'], 'type' => 'payment', 'link' => 'pay_supplier.php' ];
}
usort($rows, function($a, $b) {
    if ($a['date'] === $b['date']) return $a['sort'] <=> $b['sort'];
    return strcmp($a['date'], $b['date']);
});

// Running balance
$running = 0;
foreach ($rows as $i => &$r) {
    if ($i === 0) {
        $running = $opening;
    } else {
        $running += $r['debit'] - $r['credit'];
    }
    $r['balance'] = $running;
}
unset($r);

$current = (float)$supplier['current_balance'];

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-print-none mb-2">
  <a href="suppliers.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-arrow-left"></i> Back to Suppliers</a>
</div>

<div class="card shadow mb-3 no-print">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h6 class="mb-0"><i class="fas fa-truck-loading"></i> <?=htmlspecialchars($supplier['name'])?></h6>
    <div class="d-flex flex-wrap align-items-center gap-2">
      <input type="text" id="ledgerSearch" class="form-control form-control-sm" placeholder="Search date / description" style="max-width:220px;">
      <a href="<?=($base_url ?? '/mehboob_traders/')?>modules/transactions/pay_supplier.php?supplier_id=<?=$id?>" class="btn btn-sm btn-success"><i class="fas fa-coins"></i> Pay Supplier</a>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    </div>
  </div>
  <div class="card-body py-2">
    <div class="row text-center">
      <div class="col-md-3"><div class="text-xs text-uppercase text-muted">Phone</div><div class="h6"><?=htmlspecialchars($supplier['phone'] ?? '-')?></div></div>
      <div class="col-md-3"><div class="text-xs text-uppercase text-muted">City</div><div class="h6"><?=htmlspecialchars($supplier['city'] ?? '-')?></div></div>
      <div class="col-md-3"><div class="text-xs text-uppercase text-muted">Contact Person</div><div class="h6"><?=htmlspecialchars($supplier['contact_person'] ?? '-')?></div></div>
      <?php $balClass = $current > 0 ? 'balance-negative' : ($current < 0 ? 'balance-positive' : 'balance-zero'); ?>
      <div class="col-md-3"><div class="text-xs text-uppercase text-muted">Current Balance</div>
        <div class="h5 font-weight-bold <?=$balClass?>"><?=$current > 0 ? 'Payable PKR '.formatCurrency($current) : ($current < 0 ? 'Advance PKR '.formatCurrency(abs($current)) : 'PKR 0.00')?></div>
      </div>
    </div>
  </div>
</div>

<!-- Printable ledger header -->
<div class="d-none d-print-block mb-3 text-center">
  <h4 class="font-weight-bold mb-0" style="color:#0f172a;">Mehboob Traders</h4>
  <small class="text-muted">Wholesale Business</small>
  <h5 class="font-weight-bold text-primary mt-2 mb-0">SUPPLIER LEDGER</h5>
  <div class="font-weight-bold"><?=htmlspecialchars($supplier['name'])?></div>
  <small><?=htmlspecialchars($supplier['phone'] ?? '')?> <?=htmlspecialchars($supplier['city'] ?? '')?></small>
  <div class="mt-1">As of: <?=formatDate(date('Y-m-d'))?></div>
</div>

<div class="card shadow">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h6 class="mb-0"><i class="fas fa-book"></i> Ledger Transactions</h6>
    <span class="badge badge-primary">Balance: <?=$current > 0 ? 'Payable' : ($current < 0 ? 'Advance' : 'Settled')?></span>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered table-hover" id="ledgerTable">
        <thead class="thead-light">
          <tr><th>Date</th><th>Description</th><th class="text-right">Debit (PKR)</th><th class="text-right">Credit (PKR)</th><th class="text-right">Balance (PKR)</th></tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
          <tr class="<?=$r['type']==='opening' ? 'table-secondary font-weight-bold' : ''?>">
            <td><?=formatDate($r['date'])?></td>
            <td>
              <?=htmlspecialchars($r['desc'])?>
              <?php if ($r['method']): ?><span class="badge badge-secondary"><?=ucfirst($r['method'])?></span><?php endif; ?>
            </td>
            <td class="text-right"><?=$r['debit'] > 0 ? 'PKR '.formatCurrency($r['debit']) : '-'?></td>
            <td class="text-right"><?=$r['credit'] > 0 ? 'PKR '.formatCurrency($r['credit']) : '-'?></td>
            <td class="text-right <?= $r['balance'] > 0 ? 'balance-negative font-weight-bold' : ($r['balance'] < 0 ? 'balance-positive' : '')?>">PKR <?=formatCurrency($r['balance'])?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="table-active font-weight-bold">
            <td colspan="4" class="text-right">Closing / Current Balance</td>
            <td class="text-right <?=$current > 0 ? 'balance-negative' : ($current < 0 ? 'balance-positive' : '')?>">PKR <?=formatCurrency($current)?></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <div class="row mt-3 d-print-none">
      <div class="col-md-6">
        <div class="text-xs text-uppercase text-muted">Total Balance To Pay</div>
        <div class="h5 <?=$current > 0 ? 'balance-negative' : ($current < 0 ? 'balance-positive' : '')?>">
          <?=$current > 0 ? 'PKR '.formatCurrency($current).' Payable' : ($current < 0 ? 'PKR '.formatCurrency(abs($current)).' Advance' : 'PKR 0.00')?>
        </div>
      </div>
      <div class="col-md-6 d-flex align-items-end justify-content-end">
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print Ledger</button>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById('ledgerSearch').addEventListener('input', function() {
  var q = this.value.toLowerCase();
  document.querySelectorAll('#ledgerTable tbody tr').forEach(function(tr) {
    tr.style.display = tr.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
  });
});
</script>
<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>