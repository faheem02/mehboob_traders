<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Sale Invoice';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

$id = (int)($_GET['id'] ?? 0);
$sale = getById('sales', $id);
if (!$sale) { redirect('invoices.php', 'Invoice not found', 'error'); }

$customer = getById('customers', $sale['customer_id']);
$salesman = $sale['salesman_id'] ? getById('employees', $sale['salesman_id']) : null;
$items = $pdo->prepare("SELECT si.*, p.name, p.unit, p.purchase_price, p.boxes_per_carton FROM sale_items si LEFT JOIN products p ON si.product_id = p.id WHERE si.sale_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();
$total_cost = 0;
foreach ($items as $it) { $bpc = (float)max((float)($it['boxes_per_carton'] ?? 1), 1); $total_cost += (float)$it['purchase_price'] * $it['quantity'] / $bpc; }
$total_profit = (float)$sale['paid_amount'] - $total_cost;

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-print-none mb-3 d-flex justify-content-between align-items-center">
  <a href="invoices.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-arrow-left"></i> Back to Invoices</a>
  <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
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
        <div><strong>Date:</strong> <?=formatDate($sale['sale_date'])?></div>
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
          <tr><th>#</th><th>Product</th><th>Qty</th><th>Unit</th><th>Rate</th><th>Subtotal</th><th>Profit</th></tr>
        </thead>
        <tbody>
          <?php foreach ($items as $i => $it): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?=htmlspecialchars($it['name'])?></td>
            <td><?=(int)$it['quantity']?></td>
            <td><?=htmlspecialchars($it['unit'] ?? 'pcs')?></td>
            <td>PKR <?=formatCurrency($it['price'])?></td>
            <td>PKR <?=formatCurrency($it['subtotal'])?></td>
            <?php $bpc2 = (float)max((float)($it['boxes_per_carton'] ?? 1), 1); $item_cost = (float)$it['purchase_price'] * $it['quantity'] / $bpc2; $item_profit = (float)$sale['total_amount'] > 0 ? ((float)$sale['paid_amount'] / (float)$sale['total_amount']) * (float)$it['subtotal'] - $item_cost : -$item_cost; ?>
            <td class="<?= $item_profit >= 0 ? 'text-success' : 'text-danger' ?>">PKR <?=formatCurrency($item_profit)?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <?php $cs = 6; ?>
        <tfoot>
          <tr><th colspan="<?=$cs?>" class="text-right">Total:</th><th>PKR <?=formatCurrency($sale['total_amount'])?></th></tr>
          <?php if ($sale['discount_amount'] > 0): ?>
          <tr><th colspan="<?=$cs?>" class="text-right text-muted">Discount:</th><th>- PKR <?=formatCurrency($sale['discount_amount'])?></th></tr>
          <?php endif; ?>
          <tr><th colspan="<?=$cs?>" class="text-right">Profit:</th><th class="<?=$total_profit >= 0 ? 'text-success' : 'text-danger' ?>">PKR <?=formatCurrency($total_profit)?></th></tr>
          <tr><th colspan="<?=$cs?>" class="text-right text-success">Paid:</th><th class="text-success">PKR <?=formatCurrency($sale['paid_amount'])?></th></tr>
          <tr><th colspan="<?=$cs?>" class="text-right text-danger">Due:</th><th class="text-danger">PKR <?=formatCurrency($sale['due_amount'])?></th></tr>
        </tfoot>
      </table>
    </div>

    <?php if ($sale['notes']): ?>
    <div class="mt-3"><strong>Notes:</strong> <?=htmlspecialchars($sale['notes'])?></div>
    <?php endif; ?>
  </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>