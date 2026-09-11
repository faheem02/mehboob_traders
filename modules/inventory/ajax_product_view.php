<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker','loader']);

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('Product not found'); }

$st = $pdo->prepare("SELECT p.*, c.name AS cat_name, b.name AS brand_name
                     FROM products p
                     LEFT JOIN categories c ON p.category_id = c.id
                     LEFT JOIN brands b ON p.brand_id = b.id
                     WHERE p.id = ?");
$st->execute([$id]);
$p = $st->fetch();
if (!$p) { http_response_code(404); exit('Product not found'); }

$st = $pdo->prepare("SELECT pi.cartons, pi.loose_boxes, pi.quantity, pi.purchase_price, pi.subtotal,
                            pu.invoice_no, pu.purchase_date, s.name AS supplier_name
                     FROM purchase_items pi
                     JOIN purchases pu ON pi.purchase_id = pu.id
                     LEFT JOIN suppliers s ON pu.supplier_id = s.id
                     WHERE pi.product_id = ? AND pu.status <> 'cancelled'
                     ORDER BY pu.id DESC LIMIT 10");
$st->execute([$id]);
$purchases = $st->fetchAll();

$st = $pdo->prepare("SELECT si.quantity, si.price, si.subtotal,
                            sa.invoice_no, sa.sale_date, cu.full_name AS customer_name
                     FROM sale_items si
                     JOIN sales sa ON si.sale_id = sa.id
                     LEFT JOIN customers cu ON sa.customer_id = cu.id
                     WHERE si.product_id = ? AND sa.status <> 'cancelled'
                     ORDER BY sa.id DESC LIMIT 10");
$st->execute([$id]);
$sales = $st->fetchAll();

$bpc = max(1, (int)$p['boxes_per_carton']);
$stock = (int)$p['stock_quantity'];
$stock_ctns = (int)floor($stock / $bpc);
$stock_rem = $stock % $bpc;
?>
<div class="row">
  <div class="col-md-6">
    <h6 class="text-primary"><?=htmlspecialchars($p['name'])?>
      <span class="badge badge-secondary ml-1"><?=htmlspecialchars($p['code'])?></span>
      <?= $p['status'] ? '<span class="badge badge-success ml-1">Active</span>' : '<span class="badge badge-secondary ml-1">Inactive</span>' ?>
    </h6>
  </div>
  <div class="col-md-6 text-md-right">
    <span class="font-weight-bold <?=$stock<=0?'text-danger':($stock<=max(1,(int)$p['min_stock_level'])?'text-warning':'text-success')?>">
      <?=$stock?> Boxes<?= $stock_ctns > 0 ? ' / ' . $stock_ctns . ' Carton' . ($stock_ctns==1?'':'s') . ($stock_rem > 0 ? ' + ' . $stock_rem . ' Box' . ($stock_rem==1?'':'es') : '') : '' ?>
    </span>
  </div>
</div>
<h6 class="text-muted border-bottom pb-2 mb-2">Product Details</h6>
<table class="table table-sm table-bordered">
  <tbody>
    <tr><th class="w-25">Category</th><td><?=htmlspecialchars($p['cat_name'] ?? '-')?></td><th class="w-25">Brand</th><td><?=htmlspecialchars($p['brand_name'] ?? '-')?></td></tr>
    <tr><th>Unit</th><td><?=htmlspecialchars($p['unit'])?></td><th>Boxes per Carton</th><td><?=$bpc?></td></tr>
    <tr><th>Purchase Price</th><td>PKR <?=formatCurrency($p['purchase_price'])?> / carton</td><th>Sale Price</th><td>PKR <?=formatCurrency($p['sale_price'])?> / carton</td></tr>
    <tr><th>Min Stock Level</th><td><?=(int)$p['min_stock_level']?> boxes</td><th>Stock</th><td><?=$stock?> boxes</td></tr>
    <tr><th>Description</th><td colspan="3"><?=htmlspecialchars($p['description'] ?? '-')?></td></tr>
  </tbody>
</table>

<?php if (count($purchases)): ?>
<h6 class="text-muted border-bottom pb-2 mb-2"><i class="fas fa-cart-arrow-down"></i> Recent Purchases</h6>
<div class="table-responsive mb-3">
  <table class="table table-sm table-bordered">
    <thead class="thead-light"><tr><th>Invoice</th><th>Date</th><th>Supplier</th><th>Cartons</th><th>Loose</th><th>Qty (Boxes)</th><th>Rate / Box</th><th>Subtotal</th></tr></thead>
    <tbody>
      <?php foreach ($purchases as $pi): ?>
      <tr>
        <td><?=htmlspecialchars($pi['invoice_no'])?></td>
        <td><?=htmlspecialchars($pi['purchase_date'])?></td>
        <td><?=htmlspecialchars($pi['supplier_name'] ?? '-')?></td>
        <td><?=(int)$pi['cartons']?></td>
        <td><?=(int)$pi['loose_boxes']?></td>
        <td><?=(int)$pi['quantity']?></td>
        <td>PKR <?=formatCurrency($pi['purchase_price'])?></td>
        <td>PKR <?=formatCurrency($pi['subtotal'])?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<p class="text-muted small"><i class="fas fa-cart-arrow-down"></i> No purchases yet.</p>
<?php endif; ?>

<?php if (count($sales)): ?>
<h6 class="text-muted border-bottom pb-2 mb-2"><i class="fas fa-shopping-cart"></i> Recent Sales</h6>
<div class="table-responsive mb-2">
  <table class="table table-sm table-bordered">
    <thead class="thead-light"><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Qty (Boxes)</th><th>Rate / Box</th><th>Subtotal</th></tr></thead>
    <tbody>
      <?php foreach ($sales as $si): ?>
      <tr>
        <td><?=htmlspecialchars($si['invoice_no'])?></td>
        <td><?=htmlspecialchars($si['sale_date'])?></td>
        <td><?=htmlspecialchars($si['customer_name'] ?? '-')?></td>
        <td><?=(int)$si['quantity']?></td>
        <td>PKR <?=formatCurrency($si['price'])?></td>
        <td>PKR <?=formatCurrency($si['subtotal'])?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<p class="text-muted small"><i class="fas fa-shopping-cart"></i> No sales yet.</p>
<?php endif; ?>