<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Customer Ledger';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

$id = (int)($_GET['id'] ?? 0);
$customer = getById('customers', $id);
if (!$customer) { redirect('customers.php', 'Customer not found', 'error'); }

$my_areas = currentUserAreas($pdo);
if (!isAdmin() && $my_areas !== null) {
    if (!empty($my_areas) && !in_array($customer['area'] ?? '', $my_areas, true)) {
        redirect('customers.php', 'You can only view customers from your assigned areas', 'error');
    }
}

updateCustomerBalance($pdo, $id);
$customer = getById('customers', $id);

$opening = (float)($customer['opening_balance'] ?? 0);

// Sales (increases receivable)
$sales = $pdo->prepare("SELECT id, invoice_no, sale_date, total_amount, initial_paid, paid_amount FROM sales WHERE customer_id = ? AND status <> 'cancelled' ORDER BY sale_date ASC, id ASC");
$sales->execute([$id]);
$sales = $sales->fetchAll();

// Receipts (decreases receivable)
$receipts = $pdo->prepare("SELECT r.id, r.receipt_date, r.amount, r.payment_method, r.description, r.sale_id, s.invoice_no 
    FROM customer_receipts r 
    LEFT JOIN sales s ON s.id = r.sale_id 
    WHERE r.customer_id = ? 
    ORDER BY r.receipt_date ASC, r.id ASC");
$receipts->execute([$id]);
$receipts = $receipts->fetchAll();

// Build chronological ledger rows
$rows = [];
$rows[] = ['date' => $customer['created_at'] ?? $customer['updated_at'] ?? date('Y-m-d'), 'sort' => 0, 'desc' => 'Opening Balance', 'debit' => $opening > 0 ? $opening : 0, 'credit' => $opening < 0 ? abs($opening) : 0, 'method' => '', 'type' => 'opening', 'link' => null];
foreach ($sales as $s) {
    $rows[] = ['date' => $s['sale_date'], 'sort' => 1, 'desc' => 'Sale #' . $s['invoice_no'], 'debit' => (float)$s['total_amount'], 'credit' => 0, 'method' => '', 'type' => 'sale', 'link' => '../sales/invoice.php?id=' . $s['id'] ];
    if ((float)($s['initial_paid'] ?? 0) > 0) {
        $rows[] = ['date' => $s['sale_date'], 'sort' => 1.5, 'desc' => 'Cash at Sale #' . $s['invoice_no'], 'debit' => 0, 'credit' => (float)$s['initial_paid'], 'method' => 'cash', 'type' => 'receipt', 'link' => '../sales/invoice.php?id=' . $s['id'] ];
    }
}
foreach ($receipts as $r) {
    $desc = $r['description'] ?: 'Payment Received';
    if (!empty($r['invoice_no']) && strpos($desc, $r['invoice_no']) === false) {
        $desc .= ' (Invoice #' . $r['invoice_no'] . ')';
    }
    $rows[] = ['date' => $r['receipt_date'], 'sort' => 2, 'desc' => $desc, 'debit' => 0, 'credit' => (float)$r['amount'], 'method' => $r['payment_method'], 'type' => 'receipt', 'link' => '../transactions/receive_customer.php' ];
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

$current = (float)$customer['current_balance'];

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-print-none mb-2">
  <a href="customers.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-arrow-left"></i> Back to Customers</a>
</div>

<div class="card shadow mb-3 no-print">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h6 class="mb-0"><i class="fas fa-user"></i> <?=htmlspecialchars($customer['full_name'])?> <small class="text-muted">(<?=htmlspecialchars($customer['customer_no'])?>)</small></h6>
    <div class="d-flex flex-wrap align-items-center gap-2">
      <div class="d-flex align-items-center bg-light border rounded py-1 px-2">
        <span class="small text-muted mr-2 text-nowrap"><i class="fas fa-calendar-alt"></i> From</span>
        <input type="date" id="ledgerFrom" class="form-control form-control-sm" style="max-width:135px;" title="From date">
        <span class="small text-muted mx-2 text-nowrap">To</span>
        <input type="date" id="ledgerTo" class="form-control form-control-sm" style="max-width:135px;" title="To date">
        <button type="button" class="btn btn-sm btn-link p-1 ml-1 text-danger" onclick="clearLedgerFilter()" id="ledgerClearBtn" style="display:none;" title="Clear date filter"><i class="fas fa-times-circle"></i></button>
      </div>
      <div class="border-right mr-1" style="height:28px;"></div>
      <a href="customer_edit.php?id=<?=$id?>" class="btn btn-sm btn-outline-warning"><i class="fas fa-edit"></i> Edit</a>
      <?php if (isAdmin()): ?>
      <a href="<?=($base_url ?? '/mehboob_traders/')?>modules/transactions/receive_customer.php?customer_id=<?=$id?>" class="btn btn-sm btn-success"><i class="fas fa-hand-holding-usd"></i> Receive Payment</a>
      <?php endif; ?>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    </div>
  </div>
  <div class="card-body py-2">
    <div class="row text-center">
      <div class="col-md-3"><div class="text-xs text-uppercase text-muted">Phone</div><div class="h6"><?=htmlspecialchars($customer['phone'] ?? '-')?></div></div>
      <div class="col-md-3"><div class="text-xs text-uppercase text-muted">City</div><div class="h6"><?=htmlspecialchars($customer['city'] ?? '-')?></div></div>
      <div class="col-md-3"><div class="text-xs text-uppercase text-muted">Area</div><div class="h6"><?=htmlspecialchars($customer['area'] ?? '-')?></div></div>
      <?php $balClass = $current > 0 ? 'balance-negative' : ($current < 0 ? 'balance-positive' : 'balance-zero'); ?>
      <div class="col-md-3"><div class="text-xs text-uppercase text-muted">Current Balance</div>
        <div class="h5 font-weight-bold <?=$balClass?>"><?=$current > 0 ? 'Receivable PKR '.formatCurrency($current) : ($current < 0 ? 'Advance PKR '.formatCurrency(abs($current)) : 'PKR 0.00')?></div>
      </div>
    </div>
  </div>
</div>

<!-- Printable ledger header -->
<div class="d-none d-print-block mb-3 text-center">
  <h4 class="font-weight-bold mb-0" style="color:#0f172a;">Mehboob Traders</h4>
  <small class="text-muted">Wholesale Business</small>
  <h5 class="font-weight-bold text-primary mt-2 mb-0">CUSTOMER LEDGER</h5>
  <div class="font-weight-bold"><?=htmlspecialchars($customer['full_name'])?> (<?=htmlspecialchars($customer['customer_no'])?>)</div>
  <small><?=htmlspecialchars($customer['phone'] ?? '')?> <?=htmlspecialchars($customer['city'] ?? '')?></small>
  <div class="mt-1">As of: <?=formatDate(date('Y-m-d'))?></div>
</div>

<div class="card shadow">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h6 class="mb-0"><i class="fas fa-book"></i> Ledger Transactions</h6>
    <span class="badge badge-primary">Balance: <?=$current > 0 ? 'Receivable' : ($current < 0 ? 'Advance' : 'Settled')?></span>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered table-hover" id="ledgerTable">
        <thead class="thead-light">
          <tr><th>Date</th><th>Description</th><th class="text-right">Debit (PKR)</th><th class="text-right">Credit (PKR)</th><th class="text-right">Balance (PKR)</th></tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
          <tr class="<?=$r['type']==='opening' ? 'table-secondary font-weight-bold' : ''?>" data-date="<?=htmlspecialchars($r['date'])?>">
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
    <div class="mt-3 pt-2 border-top d-print-none">
      <div class="text-xs text-uppercase text-muted">Total Balance To Collect</div>
      <div class="h5 mb-0 <?=$current > 0 ? 'balance-negative' : ($current < 0 ? 'balance-positive' : '')?>">
        <?=$current > 0 ? 'PKR '.formatCurrency($current).' Receivable' : ($current < 0 ? 'PKR '.formatCurrency(abs($current)).' Advance' : 'PKR 0.00')?>
      </div>
    </div>
  </div>
</div>

<script>
function applyLedgerDateFilter() {
  var from = document.getElementById('ledgerFrom').value;
  var to = document.getElementById('ledgerTo').value;
  var clearBtn = document.getElementById('ledgerClearBtn');
  clearBtn.style.display = (from || to) ? '' : 'none';
  document.querySelectorAll('#ledgerTable tbody tr').forEach(function(tr) {
    var d = tr.getAttribute('data-date') || '';
    var show = true;
    if (from && d < from) show = false;
    if (to && d > to) show = false;
    tr.style.display = show ? '' : 'none';
  });
}
function clearLedgerFilter() {
  document.getElementById('ledgerFrom').value = '';
  document.getElementById('ledgerTo').value = '';
  applyLedgerDateFilter();
}
document.getElementById('ledgerFrom').addEventListener('change', applyLedgerDateFilter);
document.getElementById('ledgerTo').addEventListener('change', applyLedgerDateFilter);
</script>
<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>