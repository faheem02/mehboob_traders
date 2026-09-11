<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Purchase Invoice';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$id = (int)($_GET['id'] ?? 0);
$purchase = getById('purchases', $id);
if (!$purchase) redirect('index.php', 'Purchase not found', 'error');

$supplier = getById('suppliers', $purchase['supplier_id']);

$st = $pdo->prepare("SELECT pi.*, pr.name, pr.unit
                     FROM purchase_items pi LEFT JOIN products pr ON pi.product_id = pr.id
                     WHERE pi.purchase_id = ? ORDER BY pi.id");
$st->execute([$id]);
$items = $st->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-print-none mb-3 d-flex justify-content-between align-items-center">
  <a href="index.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-arrow-left"></i> Back to Purchases</a>
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
        <h5 class="font-weight-bold text-primary">PURCHASE INVOICE</h5>
        <div><strong>Invoice #:</strong> <?=htmlspecialchars($purchase['invoice_no'])?></div>
        <div><strong>Date:</strong> <?=formatDate($purchase['purchase_date'])?></div>
      </div>
    </div>

    <div class="row mb-3">
      <div class="col-6">
        <h6 class="text-muted text-uppercase">Supplier</h6>
        <div class="font-weight-bold"><?=htmlspecialchars($supplier['name'] ?? 'N/A')?></div>
        <div><?=htmlspecialchars($supplier['phone'] ?? '')?></div>
        <div><?=htmlspecialchars($supplier['city'] ?? '')?></div>
      </div>
      <div class="col-6 text-right">
        <h6 class="text-muted text-uppercase">Payment</h6>
        <div><strong>Method:</strong> <?=ucfirst($purchase['payment_method'])?></div>
        <div><strong>Paid:</strong> PKR <?=formatCurrency($purchase['paid_amount'])?></div>
        <div><strong>Due:</strong> PKR <?=formatCurrency($purchase['due_amount'])?></div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-bordered">
        <thead>
          <tr><th>#</th><th>Product</th><th>Cartons</th><th>BPC</th><th>Loose Boxes</th><th>Total Boxes</th><th>Rate / Box</th><th>Subtotal</th></tr>
        </thead>
        <tbody>
          <?php foreach ($items as $i => $it): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?=htmlspecialchars($it['name'] ?? 'Deleted')?></td>
            <td><?=(int)$it['cartons']?></td>
            <td><?=(int)$it['boxes_per_carton']?></td>
            <td><?=(int)$it['loose_boxes']?></td>
            <td><?=(int)$it['quantity']?></td>
            <td>PKR <?=formatCurrency($it['purchase_price'])?></td>
            <td>PKR <?=formatCurrency($it['subtotal'])?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><th colspan="7" class="text-right">Total:</th><th>PKR <?=formatCurrency($purchase['total_amount'])?></th></tr>
          <?php if ($purchase['discount_amount'] > 0): ?>
          <tr><th colspan="7" class="text-right text-muted">Discount:</th><th>- PKR <?=formatCurrency($purchase['discount_amount'])?></th></tr>
          <?php endif; ?>
          <tr><th colspan="7" class="text-right text-success">Paid:</th><th class="text-success">PKR <?=formatCurrency($purchase['paid_amount'])?></th></tr>
          <tr><th colspan="7" class="text-right text-danger">Due:</th><th class="text-danger">PKR <?=formatCurrency($purchase['due_amount'])?></th></tr>
        </tfoot>
      </table>
    </div>

    <?php if ($purchase['notes']): ?>
    <div class="mt-3"><strong>Notes:</strong> <?=htmlspecialchars($purchase['notes'])?></div>
    <?php endif; ?>
  </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>