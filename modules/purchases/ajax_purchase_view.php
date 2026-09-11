<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo 'Not found'; exit; }

$st = $pdo->prepare("SELECT p.*, s.name AS supplier_name, s.phone AS supplier_phone, s.city AS supplier_city
                     FROM purchases p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE p.id = ?");
$st->execute([$id]);
$purchase = $st->fetch();
if (!$purchase) { http_response_code(404); echo 'Not found'; exit; }

$st = $pdo->prepare("SELECT pi.*, pr.name AS product_name, pr.unit
                     FROM purchase_items pi LEFT JOIN products pr ON pi.product_id = pr.id
                     WHERE pi.purchase_id = ? ORDER BY pi.id");
$st->execute([$id]);
$items = $st->fetchAll();

$status_badge = $purchase['status'] === 'cancelled'
    ? '<span class="badge badge-danger">Cancelled</span>'
    : ($purchase['due_amount'] > 0 ? '<span class="badge badge-warning">Partial</span>' : '<span class="badge badge-success">Paid</span>');
?>
<h6>
  <?=htmlspecialchars($purchase['invoice_no'])?>
  <?=$status_badge?>
</h6>
<table class="table table-sm table-bordered mb-3">
  <tbody>
    <tr><th class="w-25">Supplier</th><td><?=htmlspecialchars($purchase['supplier_name'] ?? 'N/A')?></td><th>Date</th><td><?=formatDate($purchase['purchase_date'])?></td></tr>
    <tr><th>Phone</th><td><?=htmlspecialchars($purchase['supplier_phone'] ?? '-')?></td><th>City</th><td><?=htmlspecialchars($purchase['supplier_city'] ?? '-')?></td></tr>
    <tr><th>Method</th><td><span class="badge badge-secondary"><?=ucfirst($purchase['payment_method'])?></span></td><th>Paid</th><td class="text-success">PKR <?=formatCurrency($purchase['paid_amount'])?></td></tr>
  </tbody>
</table>

<?php if (count($items)): ?>
<div class="table-responsive mb-3">
  <table class="table table-sm table-bordered">
    <thead class="thead-light"><tr><th>#</th><th>Product</th><th>Cartons</th><th>BPC</th><th>Loose</th><th>Total Boxes</th><th>Rate / Box</th><th>Subtotal</th></tr></thead>
    <tbody>
      <?php foreach ($items as $i => $it): ?>
      <tr>
        <td><?=$i+1?></td>
        <td><?=htmlspecialchars($it['product_name'] ?? 'Deleted')?><br><small class="text-muted"><?=htmlspecialchars($it['unit'] ?? '')?></small></td>
        <td><?=(int)$it['cartons']?></td>
        <td><?=(int)$it['boxes_per_carton']?></td>
        <td><?=(int)$it['loose_boxes']?></td>
        <td><?=(int)$it['quantity']?></td>
        <td>PKR <?=formatCurrency($it['purchase_price'])?></td>
        <td>PKR <?=formatCurrency($it['subtotal'])?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<p class="text-muted small">No items found.</p>
<?php endif; ?>

<table class="table table-sm w-50">
  <tr><th>Total</th><td class="font-weight-bold">PKR <?=formatCurrency($purchase['total_amount'])?></td></tr>
  <?php if ($purchase['discount_amount'] > 0): ?>
  <tr><th>Discount</th><td>- PKR <?=formatCurrency($purchase['discount_amount'])?></td></tr>
  <?php endif; ?>
  <tr><th>Paid</th><td class="text-success">PKR <?=formatCurrency($purchase['paid_amount'])?></td></tr>
  <tr><th>Due</th><td class="<?=$purchase['due_amount'] > 0 ? 'text-danger font-weight-bold' : 'text-success' ?>">PKR <?=formatCurrency($purchase['due_amount'])?></td></tr>
</table>

<?php if ($purchase['notes']): ?>
<div class="mt-2"><strong>Notes:</strong> <?=htmlspecialchars($purchase['notes'])?></div>
<?php endif; ?>