<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Sale Invoice';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

$id = (int)($_GET['id'] ?? 0);
$sale = getById('sales', $id);
if (!$sale) { redirect('invoices.php', 'Invoice not found', 'error'); }
if (!isAdmin() && (int)$sale['created_by'] !== (int)$_SESSION['user_id']) {
    redirect('invoices.php', 'You do not have permission to view this invoice', 'error');
}

$customer = getById('customers', $sale['customer_id']);
$salesman = $sale['salesman_id'] ? getById('employees', $sale['salesman_id']) : null;
$items = $pdo->prepare("SELECT si.*, p.code, p.name, p.unit, p.boxes_per_carton FROM sale_items si LEFT JOIN products p ON si.product_id = p.id WHERE si.sale_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();
$receipts = $pdo->prepare("SELECT r.*, ba.account_name FROM customer_receipts r LEFT JOIN bank_accounts ba ON ba.id = r.bank_account_id WHERE r.sale_id = ? ORDER BY r.receipt_date ASC, r.id ASC");
$receipts->execute([$id]);
$receipts = $receipts->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-print-none mb-3 d-flex justify-content-between align-items-center">
  <a href="invoices.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-arrow-left"></i> Back to Invoices</a>
  <div>
    <?php if (isAdmin() && (float)$sale['due_amount'] > 0): ?>
    <a href="../transactions/receive_customer.php?customer_id=<?=$sale['customer_id']?>&sale_id=<?=$sale['id']?>" class="btn btn-success btn-sm mr-2"><i class="fas fa-hand-holding-usd"></i> Receive Payment</a>
    <?php endif; ?>
    <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
  </div>
</div>

<div class="card shadow">
  <div class="card-body">
    <div class="row border-bottom pb-3 mb-3">
      <div class="col-6">
        <h4 class="font-weight-bold mb-0" style="color:#0f172a;">Mehboob Traders</h4>
        <small class="text-muted">Wholesale Business <br> GST No: --</small>
      </div>
      <div class="col-6 text-right">
        <h5 class="font-weight-bold text-primary">SALE INVOICE</h5>
        <div><strong>Invoice #:</strong> <?=htmlspecialchars($sale['invoice_no'])?></div>
        <div><strong>Delivery Date:</strong> <?=formatDate($sale['delivery_date'] ?: $sale['sale_date'])?></div>
        <div><strong>Order Date:</strong> <?=formatDate($sale['sale_date'])?></div>
      </div>
    </div>

    <div class="row mb-3">
      <div class="col-6">
        <h6 class="text-muted text-uppercase">Billed To (Customer)</h6>
        <div class="font-weight-bold"><?=htmlspecialchars($customer['full_name'] ?? 'N/A')?></div>
        <div><?=htmlspecialchars($customer['phone'] ?? '')?></div>
        <div><?=htmlspecialchars($customer['address'] ?? '')?></div>
        <?php if ($salesman): ?>
        <div class="mt-2"><strong>Delivered By:</strong> <?=htmlspecialchars($salesman['full_name'])?>
          <?php if (!empty($salesman['area'])): ?><small class="text-muted">(<?=htmlspecialchars($salesman['area'])?>)</small><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="col-6 text-right">
        <h6 class="text-muted text-uppercase">Payment</h6>
        <div><strong>Paid:</strong> PKR <?=formatCurrency($sale['paid_amount'])?> (<?=ucfirst($sale['payment_method'])?>)</div>
        <div><strong>Due:</strong> PKR <?=formatCurrency($sale['due_amount'])?></div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-bordered">
        <thead>
          <tr>
            <th>#</th>
            <th>Product</th>
            <th class="text-center">Qty (Boxes)</th>
            <th class="text-center">Unit</th>
            <th class="text-right">Rate / Box</th>
            <th class="text-right">Subtotal</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $i => $it): ?>
          <?php
            $qty = (int)$it['quantity'];
            $bpc = max(1, (int)($it['boxes_per_carton'] ?? 1));
            $ctns = (int)floor($qty / $bpc);
            $remBoxes = $qty % $bpc;
          ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td class="font-weight-bold">
              <?=htmlspecialchars($it['name'])?>
              <?php if (!empty($it['code'])): ?>
                <small class="text-muted d-block font-weight-normal">Code: <?=htmlspecialchars($it['code'])?></small>
              <?php endif; ?>
            </td>
            <td class="text-center font-weight-bold">
              <?=$qty?> Boxes
              <?php if ($bpc > 1): ?>
                <br><small class="text-muted font-weight-normal">(<?=$ctns?> Ctn<?=$ctns==1?'':'s'?><?=$remBoxes > 0 ? ' + ' . $remBoxes . ' Box' . ($remBoxes==1?'':'es') : ''?>)</small>
              <?php endif; ?>
            </td>
            <td class="text-center">Boxes</td>
            <td class="text-right">PKR <?=formatCurrency($it['price'])?></td>
            <td class="text-right font-weight-bold">PKR <?=formatCurrency($it['subtotal'])?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <?php $cs = 5; ?>
        <tfoot>
          <tr><th colspan="<?=$cs?>" class="text-right">Total:</th><th class="text-right">PKR <?=formatCurrency($sale['total_amount'])?></th></tr>
          <?php if ($sale['discount_amount'] > 0): ?>
          <tr><th colspan="<?=$cs?>" class="text-right text-muted">Discount:</th><th class="text-right">- PKR <?=formatCurrency($sale['discount_amount'])?></th></tr>
          <?php endif; ?>
          <tr><th colspan="<?=$cs?>" class="text-right text-success">Paid:</th><th class="text-right text-success">PKR <?=formatCurrency($sale['paid_amount'])?></th></tr>
          <tr><th colspan="<?=$cs?>" class="text-right text-danger">Due:</th><th class="text-right text-danger">PKR <?=formatCurrency($sale['due_amount'])?></th></tr>
        </tfoot>
      </table>
    </div>

    <?php if ($sale['notes']): ?>
    <div class="mt-3"><strong>Notes:</strong> <?=htmlspecialchars($sale['notes'])?></div>
    <?php endif; ?>

    <?php if (!empty($receipts)): ?>
    <div class="mt-4 pt-3 border-top">
      <h6 class="font-weight-bold text-success mb-2"><i class="fas fa-receipt"></i> Payment Receipts (<?=count($receipts)?>)</h6>
      <div class="table-responsive">
        <table class="table table-sm table-bordered">
          <thead class="thead-light">
            <tr>
              <th>Date</th>
              <th>Description</th>
              <th>Method</th>
              <th class="text-right">Amount Received</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($receipts as $rc): ?>
            <tr>
              <td><?=formatDate($rc['receipt_date'])?></td>
              <td><?=htmlspecialchars($rc['description'] ?? 'Payment')?></td>
              <td>
                <?php if ($rc['payment_method'] == 'bank'): ?>
                  <span class="badge badge-info">Bank</span> <?=htmlspecialchars($rc['account_name'] ?? '')?>
                <?php else: ?>
                  <span class="badge badge-success">Cash</span>
                <?php endif; ?>
              </td>
              <td class="text-right text-success font-weight-bold">PKR <?=formatCurrency($rc['amount'])?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($_GET['print'])): ?>
<script>
window.addEventListener('load', function(){
  setTimeout(function(){ window.print(); }, 300);
});
</script>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>