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

$bpc = max(1, (int)$p['boxes_per_carton']);
$stock = (int)$p['stock_quantity'];
$stock_ctns = (int)floor($stock / $bpc);
$stock_rem = $stock % $bpc;
$opening_stock = (int)($p['opening_stock'] ?? 0);
$opening_ctns = (int)floor($opening_stock / $bpc);
$opening_rem = $opening_stock % $bpc;

// 1. Total Purchases summary & list
$purchases = [];
$purch_totals = [
    'purch_count' => 0,
    'total_boxes' => 0,
    'total_amount' => 0.0,
    'last_date' => null,
    'last_boxes' => 0,
    'last_inv' => '',
];
if (isAdmin()) {
    $pt_stmt = $pdo->prepare("SELECT COUNT(DISTINCT pu.id) as purch_count, 
                                     COALESCE(SUM(pi.quantity), 0) as total_boxes,
                                     COALESCE(SUM(pi.subtotal), 0) as total_amount
                              FROM purchase_items pi
                              JOIN purchases pu ON pi.purchase_id = pu.id
                              WHERE pi.product_id = ? AND pu.status <> 'cancelled'");
    $pt_stmt->execute([$id]);
    $pt_res = $pt_stmt->fetch();
    if ($pt_res) {
        $purch_totals['purch_count'] = (int)$pt_res['purch_count'];
        $purch_totals['total_boxes'] = (int)$pt_res['total_boxes'];
        $purch_totals['total_amount'] = (float)$pt_res['total_amount'];
    }

    $st = $pdo->prepare("SELECT pi.cartons, pi.loose_boxes, pi.quantity, pi.purchase_price, pi.subtotal,
                                pu.invoice_no, pu.purchase_date, s.name AS supplier_name
                         FROM purchase_items pi
                         JOIN purchases pu ON pi.purchase_id = pu.id
                         LEFT JOIN suppliers s ON pu.supplier_id = s.id
                         WHERE pi.product_id = ? AND pu.status <> 'cancelled'
                         ORDER BY pu.purchase_date DESC, pu.id DESC");
    $st->execute([$id]);
    $purchases = $st->fetchAll();
    if (!empty($purchases)) {
        $purch_totals['last_date'] = $purchases[0]['purchase_date'];
        $purch_totals['last_boxes'] = (int)$purchases[0]['quantity'];
        $purch_totals['last_inv'] = $purchases[0]['invoice_no'];
    }
}

// 2. Total Sales summary & list
$sales_totals = [
    'sales_count' => 0,
    'total_boxes' => 0,
    'total_amount' => 0.0,
    'since_last_boxes' => 0,
    'since_last_count' => 0,
];
$st_sum = $pdo->prepare("SELECT COUNT(DISTINCT sa.id) as sales_count,
                                COALESCE(SUM(si.quantity), 0) as total_boxes,
                                COALESCE(SUM(si.subtotal), 0) as total_amount
                         FROM sale_items si
                         JOIN sales sa ON si.sale_id = sa.id
                         WHERE si.product_id = ? AND sa.status <> 'cancelled'");
$st_sum->execute([$id]);
$sales_res = $st_sum->fetch();
if ($sales_res) {
    $sales_totals['sales_count'] = (int)$sales_res['sales_count'];
    $sales_totals['total_boxes'] = (int)$sales_res['total_boxes'];
    $sales_totals['total_amount'] = (float)$sales_res['total_amount'];
}

if (!empty($purch_totals['last_date'])) {
    $since_stmt = $pdo->prepare("SELECT COUNT(DISTINCT sa.id) as inv_cnt,
                                        COALESCE(SUM(si.quantity), 0) as boxes_cnt
                                 FROM sale_items si
                                 JOIN sales sa ON si.sale_id = sa.id
                                 WHERE si.product_id = ? AND sa.status <> 'cancelled' AND sa.sale_date >= ?");
    $since_stmt->execute([$id, $purch_totals['last_date']]);
    $since_res = $since_stmt->fetch();
    if ($since_res) {
        $sales_totals['since_last_count'] = (int)$since_res['inv_cnt'];
        $sales_totals['since_last_boxes'] = (int)$since_res['boxes_cnt'];
    }
}

// Fetch all sales ordered by date DESC
$st = $pdo->prepare("SELECT si.quantity, si.price, si.subtotal,
                            sa.id AS sale_id, sa.invoice_no, sa.sale_date, cu.full_name AS customer_name
                     FROM sale_items si
                     JOIN sales sa ON si.sale_id = sa.id
                     LEFT JOIN customers cu ON sa.customer_id = cu.id
                     WHERE si.product_id = ? AND sa.status <> 'cancelled'
                     ORDER BY sa.sale_date DESC, sa.id DESC");
$st->execute([$id]);
$all_sales = $st->fetchAll();
$recent_sales = array_slice($all_sales, 0, 10);
$more_sales = array_slice($all_sales, 10);

// 3. Total Returns
$ret_stmt = $pdo->prepare("SELECT COUNT(DISTINCT sr.id) as ret_count,
                                  COALESCE(SUM(sri.quantity), 0) as ret_boxes
                           FROM sale_return_items sri
                           JOIN sale_returns sr ON sri.return_id = sr.id
                           WHERE sri.product_id = ? AND sr.status = 'Completed'");
$ret_stmt->execute([$id]);
$ret_res = $ret_stmt->fetch();
$total_returns_boxes = (int)($ret_res['ret_boxes'] ?? 0);
$total_returns_count = (int)($ret_res['ret_count'] ?? 0);

// 4. Stock Adjustments History
$adj_list = [];
if (isAdmin()) {
    $adj_stmt = $pdo->prepare("SELECT sa.*, u.full_name AS user_name
                               FROM stock_adjustments sa
                               LEFT JOIN users u ON sa.created_by = u.id
                               WHERE sa.product_id = ?
                               ORDER BY sa.adjustment_date DESC, sa.id DESC");
    $adj_stmt->execute([$id]);
    $adj_list = $adj_stmt->fetchAll();
}
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom">
  <div>
    <h5 class="text-primary font-weight-bold mb-0">
      <?=htmlspecialchars($p['name'])?>
      <span class="badge badge-secondary ml-1"><?=htmlspecialchars($p['code'])?></span>
      <?= $p['status'] ? '<span class="badge badge-success ml-1">Active</span>' : '<span class="badge badge-secondary ml-1">Inactive</span>' ?>
    </h5>
  </div>
  <div class="d-flex align-items-center mt-2 mt-sm-0">
    <div class="mr-3 text-right">
      <div class="small text-muted text-uppercase">Current Stock</div>
      <span class="h5 font-weight-bold mb-0 <?=$stock<=0?'text-danger':($stock<=max(1,(int)$p['min_stock_level'])?'text-warning':'text-success')?>">
        <?=$stock?> Boxes
        <small class="text-muted d-block" style="font-size:0.75rem;">
          <?=$stock_ctns?> Ctn<?= $stock_rem > 0 ? " + {$stock_rem} Loose" : '' ?>
          <?= $stock <= 0 ? ' (Out of stock)' : '' ?>
        </small>
      </span>
    </div>
    <?php if (isAdmin()): ?>
      <a href="stock_adjustment.php?q=<?=urlencode($p['code'])?>" class="btn btn-sm btn-outline-success mr-1" title="Physical Count & Stock Adjustment">
        <i class="fas fa-clipboard-check mr-1"></i> Adjust Stock
      </a>
      <a href="product_edit.php?id=<?=$id?>" class="btn btn-sm btn-outline-primary" title="Edit product details">
        <i class="fas fa-edit mr-1"></i> Edit Product
      </a>
    <?php endif; ?>
  </div>
</div>

<!-- Stock Movement Overview Banner -->
<div class="row text-center mb-2">
  <div class="col-6 col-md-3 col-lg mb-2">
    <div class="border rounded p-2 bg-light h-100 border-secondary">
      <div class="text-xs text-uppercase text-secondary font-weight-bold"><i class="fas fa-boxes mr-1"></i> Opening Stock</div>
      <div class="h6 font-weight-bold text-dark mb-0"><?=$opening_stock?> <small>Boxes</small></div>
      <small class="text-muted"><?=$opening_ctns?> Ctn<?= $opening_rem > 0 ? " + {$opening_rem} Box" : '' ?></small>
    </div>
  </div>

  <?php if (isAdmin()): ?>
  <div class="col-6 col-md-3 col-lg mb-2">
    <div class="border rounded p-2 bg-light h-100 border-dark">
      <div class="text-xs text-uppercase text-muted font-weight-bold"><i class="fas fa-truck-loading mr-1"></i> Total Purchased</div>
      <div class="h6 font-weight-bold text-dark mb-0">+ <?=$purch_totals['total_boxes']?> <small>Boxes</small></div>
      <small class="text-muted"><?=(int)floor($purch_totals['total_boxes'] / $bpc)?> Ctn (<?=$purch_totals['purch_count']?> purchases)</small>
    </div>
  </div>
  <?php endif; ?>

  <div class="col-6 col-md-3 col-lg mb-2">
    <div class="border rounded p-2 bg-light h-100 border-primary">
      <div class="text-xs text-uppercase text-primary font-weight-bold"><i class="fas fa-shopping-cart mr-1"></i> Total Sold</div>
      <div class="h6 font-weight-bold text-primary mb-0">&minus; <?=$sales_totals['total_boxes']?> <small>Boxes</small></div>
      <small class="text-muted"><?=(int)floor($sales_totals['total_boxes'] / $bpc)?> Ctn (<?=$sales_totals['sales_count']?> invoices)</small>
    </div>
  </div>

  <?php if ($total_returns_boxes > 0): ?>
  <div class="col-6 col-md-3 col-lg mb-2">
    <div class="border rounded p-2 bg-light h-100 border-warning">
      <div class="text-xs text-uppercase text-warning font-weight-bold"><i class="fas fa-undo mr-1"></i> Sale Returns</div>
      <div class="h6 font-weight-bold text-warning mb-0">+ <?=$total_returns_boxes?> <small>Boxes</small></div>
      <small class="text-muted"><?=$total_returns_count?> return<?= $total_returns_count > 1 ? 's' : '' ?></small>
    </div>
  </div>
  <?php endif; ?>

  <div class="col-6 col-md-3 col-lg mb-2">
    <div class="border rounded p-2 bg-light h-100 <?=$stock<=0 ? 'border-danger' : 'border-success'?>">
      <div class="text-xs text-uppercase font-weight-bold <?=$stock<=0?'text-danger':'text-success'?>"><i class="fas fa-warehouse mr-1"></i> In Stock</div>
      <div class="h6 font-weight-bold <?=$stock<=0?'text-danger':'text-success'?> mb-0"><?=$stock?> <small>Boxes</small></div>
      <small class="text-muted"><?=$stock_ctns?> Ctn<?= $stock_rem > 0 ? " + {$stock_rem} Box" : '' ?></small>
    </div>
  </div>
</div>

<!-- Stock Equation / Audit Strip -->
<div class="alert alert-light border py-2 px-3 small text-center mb-3 text-dark">
  <i class="fas fa-calculator text-primary mr-1"></i>
  <strong>Stock Reconciliation:</strong>
  Opening (<strong><?=$opening_stock?></strong>) 
  + Purchased (<strong><?=$purch_totals['total_boxes']?></strong>) 
  &minus; Sold (<strong><?=$sales_totals['total_boxes']?></strong>)
  <?= $total_returns_boxes > 0 ? " + Returned (<strong>{$total_returns_boxes}</strong>)" : "" ?>
  = <strong>Current Stock:</strong>
  <span class="badge <?=$stock<=0?'badge-danger':'badge-success'?> px-2 py-1 font-weight-bold" style="font-size:12px;">
    <?=$stock?> Boxes (<?=$stock_ctns?> Ctn<?= $stock_rem > 0 ? " + {$stock_rem} Loose" : '' ?>)
  </span>
</div>

<h6 class="text-muted border-bottom pb-2 mb-2"><i class="fas fa-info-circle mr-1"></i> Product Details</h6>
<table class="table table-sm table-bordered mb-3">
  <tbody>
    <tr><th class="w-25">Category</th><td><?=htmlspecialchars($p['cat_name'] ?? '-')?></td><th class="w-25">Brand</th><td><?=htmlspecialchars($p['brand_name'] ?? '-')?></td></tr>
    <tr><th>Unit</th><td><?=htmlspecialchars($p['unit'])?></td><th>Boxes per Carton</th><td><?=$bpc?></td></tr>
    <tr>
      <?php if (isAdmin()): ?>
      <th>Purchase Price</th><td>PKR <?=formatCurrency($p['purchase_price'])?> / carton</td>
      <th>Sale Price</th><td>PKR <?=formatCurrency($p['sale_price'])?> / carton</td>
      <?php else: ?>
      <th>Sale Price</th><td colspan="3">PKR <?=formatCurrency($p['sale_price'])?> / carton</td>
      <?php endif; ?>
    </tr>
    <tr>
      <th>Opening Stock</th><td><strong><?=$opening_stock?></strong> boxes (<?=$opening_ctns?> Ctn<?= $opening_rem > 0 ? " + {$opening_rem} Box" : '' ?>)</td>
      <th>Current Stock</th><td><strong class="<?=$stock<=0?'text-danger':'text-success'?>"><?=$stock?></strong> boxes (<?=$stock_ctns?> Ctn<?= $stock_rem > 0 ? " + {$stock_rem} Box" : '' ?>) <?= $stock <= 0 ? '<span class="badge badge-danger ml-1">Out of Stock</span>' : ($stock <= max(1, (int)$p['min_stock_level']) ? '<span class="badge badge-warning ml-1">Low Stock</span>' : '<span class="badge badge-success ml-1">In Stock</span>') ?></td>
    </tr>
    <tr><th>Min Stock Level</th><td><?=(int)$p['min_stock_level']?> boxes</td><th>Status</th><td><?= $p['status'] ? '<span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i>Active</span>' : '<span class="badge badge-secondary"><i class="fas fa-ban mr-1"></i>Inactive</span>' ?></td></tr>
    <?php if (!empty($p['description'])): ?>
    <tr><th>Description</th><td colspan="3"><?=htmlspecialchars($p['description'])?></td></tr>
    <?php endif; ?>
  </tbody>
</table>

<?php if (isAdmin()): ?>
<h6 class="text-muted border-bottom pb-2 mb-2 d-flex justify-content-between align-items-center">
  <span><i class="fas fa-cart-arrow-down mr-1"></i> Purchases (<?=$purch_totals['purch_count']?> Total &middot; <?=$purch_totals['total_boxes']?> Boxes)</span>
</h6>
<?php if (count($purchases)): ?>
<div class="table-responsive mb-3 border rounded" style="max-height: 220px; overflow-y: auto;">
  <table class="table table-sm table-bordered table-hover mb-0">
    <thead class="thead-light" style="position: sticky; top: 0; z-index: 1;">
      <tr><th>Invoice</th><th>Date</th><th>Supplier</th><th>Cartons</th><th>Loose</th><th>Qty (Boxes)</th><th>Rate / Box</th><th>Subtotal</th></tr>
    </thead>
    <tbody>
      <?php foreach ($purchases as $pi): ?>
      <tr>
        <td class="font-weight-bold"><?=htmlspecialchars($pi['invoice_no'])?></td>
        <td><?=formatDate($pi['purchase_date'])?></td>
        <td><?=htmlspecialchars($pi['supplier_name'] ?? '-')?></td>
        <td><?=(int)$pi['cartons']?></td>
        <td><?=(int)$pi['loose_boxes']?></td>
        <td class="font-weight-bold"><?=(int)$pi['quantity']?></td>
        <td>PKR <?=formatCurrency($pi['purchase_price'])?></td>
        <td class="font-weight-bold">PKR <?=formatCurrency($pi['subtotal'])?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<p class="text-muted small mb-3"><i class="fas fa-cart-arrow-down"></i> No purchases recorded yet.</p>
<?php endif; ?>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center border-bottom pb-2 mb-2">
  <h6 class="text-muted mb-0">
    <i class="fas fa-shopping-cart mr-1"></i> Sales History 
    <span class="badge badge-primary font-weight-normal ml-1"><?=$sales_totals['sales_count']?> Invoices &middot; <?=$sales_totals['total_boxes']?> Boxes Sold</span>
  </h6>
  <?php if (count($more_sales) > 0): ?>
    <button type="button" class="btn btn-xs btn-outline-primary" id="toggleAllSalesBtn" onclick="toggleProductSalesView()">
      <i class="fas fa-list mr-1"></i> Show All <?=$sales_totals['sales_count']?> Sales
    </button>
  <?php endif; ?>
</div>

<?php if (!empty($purch_totals['last_date']) && $sales_totals['since_last_boxes'] > 0): ?>
<div class="alert alert-info py-1 px-3 small mb-2 d-flex justify-content-between align-items-center">
  <div>
    <i class="fas fa-info-circle mr-1"></i> Since last purchase on <strong><?=formatDate($purch_totals['last_date'])?></strong> (<?=$purch_totals['last_boxes']?> boxes), a total of <strong><?=$sales_totals['since_last_boxes']?> boxes</strong> have been sold across <strong><?=$sales_totals['since_last_count']?> sales invoices</strong>.
  </div>
</div>
<?php endif; ?>

<?php if (count($all_sales)): ?>
<div class="table-responsive mb-2 border rounded" style="max-height: 280px; overflow-y: auto;">
  <table class="table table-sm table-bordered table-hover mb-0" id="productSalesHistoryTable">
    <thead class="thead-light" style="position: sticky; top: 0; z-index: 1;">
      <tr>
        <th style="width: 40px;">#</th>
        <th>Invoice</th>
        <th>Date</th>
        <th>Customer</th>
        <th class="text-right">Qty (Boxes)</th>
        <th class="text-right">Rate / Box</th>
        <th class="text-right">Subtotal</th>
      </tr>
    </thead>
    <tbody>
      <?php $idx = 1; foreach ($recent_sales as $si): ?>
      <tr>
        <td class="text-muted small"><?=$idx++?></td>
        <td class="font-weight-bold">
          <a href="../sales/invoice.php?id=<?=$si['sale_id']?>" target="_blank" class="text-primary"><?=htmlspecialchars($si['invoice_no'])?></a>
        </td>
        <td><?=formatDate($si['sale_date'])?></td>
        <td><?=htmlspecialchars($si['customer_name'] ?? 'Walk-in Customer')?></td>
        <td class="text-right font-weight-bold"><?=$si['quantity']?></td>
        <td class="text-right">PKR <?=formatCurrency($si['price'])?></td>
        <td class="text-right font-weight-bold">PKR <?=formatCurrency($si['subtotal'])?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <?php if (count($more_sales) > 0): ?>
    <tbody id="extraProductSalesBody" style="display: none;">
      <?php foreach ($more_sales as $si): ?>
      <tr class="bg-light">
        <td class="text-muted small"><?=$idx++?></td>
        <td class="font-weight-bold">
          <a href="../sales/invoice.php?id=<?=$si['sale_id']?>" target="_blank" class="text-primary"><?=htmlspecialchars($si['invoice_no'])?></a>
        </td>
        <td><?=formatDate($si['sale_date'])?></td>
        <td><?=htmlspecialchars($si['customer_name'] ?? 'Walk-in Customer')?></td>
        <td class="text-right font-weight-bold"><?=$si['quantity']?></td>
        <td class="text-right">PKR <?=formatCurrency($si['price'])?></td>
        <td class="text-right font-weight-bold">PKR <?=formatCurrency($si['subtotal'])?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <?php endif; ?>
    <tfoot class="thead-light font-weight-bold">
      <tr>
        <td colspan="4" class="text-right text-uppercase">Total Sold Across All Invoices:</td>
        <td class="text-right text-primary font-weight-bold"><?=$sales_totals['total_boxes']?> Boxes</td>
        <td></td>
        <td class="text-right font-weight-bold">PKR <?=formatCurrency($sales_totals['total_amount'])?></td>
      </tr>
    </tfoot>
  </table>
</div>
<small class="text-muted d-block text-right mb-2">
  Showing <span id="salesDisplayCount"><?=count($recent_sales)?></span> of <?=$sales_totals['sales_count']?> total sales invoices.
</small>
<?php else: ?>
<p class="text-muted small mb-2"><i class="fas fa-shopping-cart"></i> No sales recorded yet.</p>
<?php endif; ?>

<?php if (isAdmin() && count($adj_list) > 0): ?>
<h6 class="text-muted border-bottom pb-2 mb-2 mt-3 d-flex justify-content-between align-items-center">
  <span><i class="fas fa-clipboard-check mr-1"></i> Stock Adjustments History (<?=count($adj_list)?>)</span>
</h6>
<div class="table-responsive mb-2 border rounded" style="max-height: 200px; overflow-y: auto;">
  <table class="table table-sm table-bordered table-hover mb-0">
    <thead class="thead-light" style="position: sticky; top: 0; z-index: 1;">
      <tr>
        <th>Ref No</th>
        <th>Date</th>
        <th class="text-right">System</th>
        <th class="text-right">Physical</th>
        <th class="text-right">Difference</th>
        <th>Reason / Notes</th>
        <th>By</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($adj_list as $adj):
        $diff = (int)$adj['difference'];
        $diffClass = $diff > 0 ? 'text-success font-weight-bold' : ($diff < 0 ? 'text-danger font-weight-bold' : 'text-muted');
        $diffText = ($diff > 0 ? '+' : '') . $diff . ' Bx';
      ?>
      <tr>
        <td class="font-weight-bold"><?=htmlspecialchars($adj['adjustment_no'])?></td>
        <td><?=formatDate($adj['adjustment_date'])?></td>
        <td class="text-right"><?=(int)$adj['system_stock']?> Bx</td>
        <td class="text-right font-weight-bold"><?=(int)$adj['physical_stock']?> Bx</td>
        <td class="text-right <?=$diffClass?>"><?=$diffText?></td>
        <td class="small"><?=htmlspecialchars($adj['reason'] ?: '-')?><?= !empty($adj['notes']) ? ' &middot; ' . htmlspecialchars($adj['notes']) : '' ?></td>
        <td class="small text-muted"><?=htmlspecialchars($adj['user_name'] ?? 'Admin')?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<script>
function toggleProductSalesView() {
  var extra = document.getElementById('extraProductSalesBody');
  var btn = document.getElementById('toggleAllSalesBtn');
  var countSpan = document.getElementById('salesDisplayCount');
  if (!extra) return;
  if (extra.style.display === 'none' || extra.style.display === '') {
    extra.style.display = 'table-row-group';
    btn.innerHTML = '<i class="fas fa-chevron-up mr-1"></i> Show Latest 10 Only';
    if (countSpan) countSpan.textContent = '<?=$sales_totals['sales_count']?>';
  } else {
    extra.style.display = 'none';
    btn.innerHTML = '<i class="fas fa-list mr-1"></i> Show All <?=$sales_totals['sales_count']?> Sales';
    if (countSpan) countSpan.textContent = '<?=count($recent_sales)?>';
  }
}
</script>