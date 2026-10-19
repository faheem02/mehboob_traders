<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Stock Closing Report';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker','loader']);

// Filter inputs
$cat_id = trim($_GET['category_id'] ?? '');
$brand_id = trim($_GET['brand_id'] ?? '');
$status_filter = trim($_GET['status_filter'] ?? 'all'); // 'all', 'in', 'low', 'out', 'alert'
$q = trim($_GET['q'] ?? '');

$sql = "SELECT p.*, c.name AS cat_name, b.name AS brand_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        WHERE p.status = 1";
$params = [];

if ($cat_id !== '') {
    $sql .= " AND p.category_id = ?";
    $params[] = (int)$cat_id;
}
if ($brand_id !== '') {
    $sql .= " AND p.brand_id = ?";
    $params[] = (int)$brand_id;
}
if ($q !== '') {
    $sql .= " AND (p.name LIKE ? OR p.code LIKE ? OR c.name LIKE ? OR b.name LIKE ?)";
    $like = "%$q%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

$sql .= " ORDER BY (p.stock_quantity <= 0) DESC, p.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products_raw = $stmt->fetchAll();

$categories = $pdo->query("SELECT id, name FROM categories WHERE status = 1 ORDER BY name ASC")->fetchAll();
$brands = $pdo->query("SELECT id, name FROM brands WHERE status = 1 ORDER BY name ASC")->fetchAll();

// Process products and calculate carton units and valuations
$products = [];
$total_boxes = 0;
$total_full_cartons = 0;
$total_loose_boxes = 0;
$total_cost_val = 0.0;
$total_sale_val = 0.0;

$count_out = 0;
$count_low = 0;
$count_in = 0;

foreach ($products_raw as $p) {
    $boxes = (int)$p['stock_quantity'];
    $bpc = max(1, (int)$p['boxes_per_carton']);
    $cartons = ($boxes > 0) ? (int)floor($boxes / $bpc) : 0;
    $loose = ($boxes > 0) ? ($boxes % $bpc) : 0;

    $purchase_ctn = (float)$p['purchase_price'];
    $sale_ctn = (float)$p['sale_price'];
    $cost_per_box = round($purchase_ctn / $bpc, 2);
    $sale_per_box = round($sale_ctn / $bpc, 2);

    $cost_val = ($boxes > 0) ? round($cost_per_box * $boxes, 2) : 0.0;
    $sale_val = ($boxes > 0) ? round($sale_per_box * $boxes, 2) : 0.0;
    $profit_val = $sale_val - $cost_val;

    $min_lvl = (int)$p['min_stock_level'];
    if ($boxes <= 0) {
        $st = 'out';
        $count_out++;
    } elseif ($min_lvl > 0 && $boxes <= $min_lvl) {
        $st = 'low';
        $count_low++;
    } else {
        $st = 'in';
        $count_in++;
    }

    if ($boxes > 0) {
        $total_boxes += $boxes;
        $total_full_cartons += $cartons;
        $total_loose_boxes += $loose;
        $total_cost_val += $cost_val;
        $total_sale_val += $sale_val;
    }

    $p['calc_boxes'] = $boxes;
    $p['calc_bpc'] = $bpc;
    $p['calc_cartons'] = $cartons;
    $p['calc_loose'] = $loose;
    $p['cost_per_box'] = $cost_per_box;
    $p['sale_per_box'] = $sale_per_box;
    $p['cost_val'] = $cost_val;
    $p['sale_val'] = $sale_val;
    $p['profit_val'] = $profit_val;
    $p['stock_status'] = $st;

    $products[] = $p;
}

$total_alert_items = $count_out + $count_low;
$total_profit_val = $total_sale_val - $total_cost_val;
$overall_margin = $total_sale_val > 0 ? round(($total_profit_val / $total_sale_val) * 100, 1) : 0.0;

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<style>
.stock-pill-btn {
  border-radius: 20px;
  padding: 0.25rem 0.85rem;
  font-size: 0.82rem;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid #cbd5e1;
  background: #fff;
  color: #475569;
  transition: all 0.2s;
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}
.stock-pill-btn:hover { background: #f8fafc; color: #1e293b; text-decoration: none; }
.stock-pill-btn.active {
  background: var(--primary);
  border-color: var(--primary);
  color: #fff;
}
.stock-pill-btn.active-warning {
  background: #f59e0b !important;
  border-color: #d97706 !important;
  color: #fff !important;
}
.stock-pill-btn.active-danger {
  background: #ef4444 !important;
  border-color: #dc2626 !important;
  color: #fff !important;
}
.stock-val-cell { font-family: 'Consolas', 'Courier New', monospace; font-size: 0.9rem; }
.cursor-pointer { cursor: pointer; }
@media print {
  @page { size: A4 landscape; margin: 8mm; }
  .report-table th, .report-table td { padding: 4px 6px !important; font-size: 10px !important; }
  .report-tfoot td { font-size: 10.5px !important; }
}
</style>

<!-- Top Action Header -->
<div class="card shadow-sm border-0 mb-3">
  <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center border-bottom">
    <div class="d-flex align-items-center">
      <div class="icon-circle bg-primary-soft text-primary mr-3" style="width:44px; height:44px; border-radius:12px; background:rgba(13,110,253,.1); display:flex; align-items:center; justify-content:center;">
        <i class="fas fa-warehouse fa-lg text-primary"></i>
      </div>
      <div>
        <h5 class="mb-0 font-weight-bold text-dark">Stock Closing Report</h5>
        <small class="text-muted">Live Inventory Status &middot; Packaging Breakdown &middot; Stock Valuation</small>
      </div>
    </div>
    <div class="d-flex flex-wrap align-items-center mt-2 mt-md-0 gap-2">
      <!-- Special Low Stock Alert Toggle Button -->
      <button type="button" class="btn btn-sm btn-warning font-weight-bold shadow-sm mr-2" id="btnLowStockAlert" title="Show all Low & Out of Stock products">
        <i class="fas fa-exclamation-triangle mr-1"></i> Low Stock Alert (<span id="btnLowStockCount"><?=$total_alert_items?></span>)
      </button>

      <button type="button" class="btn btn-sm btn-outline-secondary shadow-sm mr-2" onclick="window.print()">
        <i class="fas fa-print mr-1"></i> Print Report
      </button>
      <a href="products.php" class="btn btn-sm btn-outline-primary shadow-sm mr-2">
        <i class="fas fa-boxes mr-1"></i> Products List
      </a>
      <?php if (isAdmin()): ?>
      <a href="product_create.php" class="btn btn-sm btn-primary shadow-sm">
        <i class="fas fa-plus mr-1"></i> Add Product
      </a>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- KPI Summary Cards Row -->
<div class="row g-2 mb-3 d-print-none">
  <!-- Total Products -->
  <div class="col-xl-2 col-md-4 col-6 mb-2">
    <div class="card border-left-primary shadow-sm h-100 stat-card">
      <div class="card-body py-2 px-3">
        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Products</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800" id="cardTotalProducts"><?=count($products)?> Items</div>
        <small class="text-muted font-weight-bold" id="cardInStockCount"><?=$count_in?> In Stock</small>
      </div>
    </div>
  </div>

  <!-- Total Stock Boxes & Cartons -->
  <div class="col-xl-3 col-md-4 col-6 mb-2">
    <div class="card border-left-info shadow-sm h-100 stat-card">
      <div class="card-body py-2 px-3">
        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Stock Quantity</div>
        <div class="h5 mb-0 font-weight-bold text-primary" id="cardTotalBoxes"><?=number_format($total_boxes)?> Boxes</div>
        <small class="text-muted font-weight-bold" id="cardTotalCartons"><?=number_format($total_full_cartons)?> Cartons + <?=$total_loose_boxes?> Boxes</small>
      </div>
    </div>
  </div>

  <?php if (isAdmin()): ?>
  <!-- Stock Valuation at Cost -->
  <div class="col-xl-2 col-md-4 col-6 mb-2">
    <div class="card border-left-success shadow-sm h-100 stat-card">
      <div class="card-body py-2 px-3">
        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Valuation (Cost)</div>
        <div class="h6 mb-0 font-weight-bold text-success" id="cardValCost">PKR <?=formatCurrency($total_cost_val)?></div>
        <small class="text-muted">Total Purchase Investment</small>
      </div>
    </div>
  </div>

  <!-- Stock Valuation at Retail/Sale -->
  <div class="col-xl-3 col-md-6 col-6 mb-2">
    <div class="card border-left-secondary shadow-sm h-100 stat-card">
      <div class="card-body py-2 px-3">
        <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">Valuation (Sale Price)</div>
        <div class="h6 mb-0 font-weight-bold text-dark" id="cardValSale">PKR <?=formatCurrency($total_sale_val)?></div>
        <small class="text-success font-weight-bold" id="cardValProfit">+PKR <?=formatCurrency($total_profit_val)?> (<?=$overall_margin?>% Margin)</small>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Low & Out of Stock Alerts Card (Clickable) -->
  <div class="<?=isAdmin() ? 'col-xl-2' : 'col-xl-7'?> col-md-6 col-12 mb-2">
    <div class="card border-left-warning shadow-sm h-100 stat-card cursor-pointer" id="cardAlertsTrigger" title="Click to filter Low Stock products">
      <div class="card-body py-2 px-3">
        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1 d-flex justify-content-between align-items-center">
          <span>Low Stock Alerts</span>
          <i class="fas fa-filter text-muted small"></i>
        </div>
        <div class="d-flex align-items-center gap-2 mb-1">
          <span class="badge badge-danger font-weight-bold px-2 py-1" id="cardOutBadge"><i class="fas fa-ban mr-1"></i> <?=$count_out?> Out</span>
          <span class="badge badge-warning font-weight-bold px-2 py-1 ml-1" id="cardLowBadge"><i class="fas fa-exclamation-triangle mr-1"></i> <?=$count_low?> Low</span>
        </div>
        <small class="text-primary font-weight-bold">Click to view alert items</small>
      </div>
    </div>
  </div>
</div>

<!-- Filters & Search Toolbar -->
<div class="card shadow-sm border-0 mb-3 d-print-none">
  <div class="card-body py-2 px-3">
    <div class="row align-items-center g-2">
      <!-- Status Pills -->
      <div class="col-lg-6 col-12 mb-2 mb-lg-0">
        <div class="d-flex flex-wrap align-items-center gap-1">
          <button type="button" class="stock-pill-btn active" data-filter="all">
            <i class="fas fa-list"></i> All (<span id="pillCountAll"><?=count($products)?></span>)
          </button>
          <button type="button" class="stock-pill-btn" data-filter="in">
            <i class="fas fa-check-circle text-success"></i> In Stock (<span id="pillCountIn"><?=$count_in?></span>)
          </button>
          <button type="button" class="stock-pill-btn" data-filter="alert">
            <i class="fas fa-exclamation-triangle text-warning"></i> Low / Out (<span id="pillCountAlert"><?=$total_alert_items?></span>)
          </button>
          <button type="button" class="stock-pill-btn" data-filter="out">
            <i class="fas fa-ban text-danger"></i> Out of Stock (<span id="pillCountOut"><?=$count_out?></span>)
          </button>
        </div>
      </div>

      <!-- Search & Filters -->
      <div class="col-lg-6 col-12">
        <form method="get" id="stockFilterForm" class="row g-2 justify-content-end align-items-center">
          <div class="col-sm-5 col-12">
            <div class="input-group input-group-sm">
              <input type="text" id="liveStockSearch" class="form-control" placeholder="Search product name, code, category..." value="<?=htmlspecialchars($q)?>" autocomplete="off">
              <div class="input-group-append">
                <button type="button" class="btn btn-outline-secondary" id="btnClearSearch" title="Clear Search" style="display:none;"><i class="fas fa-times"></i></button>
              </div>
            </div>
          </div>
          <div class="col-sm-4 col-6">
            <select name="category_id" id="filterCategory" class="form-control form-control-sm">
              <option value="">All Categories</option>
              <?php foreach ($categories as $cat): ?>
              <option value="<?=$cat['id']?>" <?=$cat_id == $cat['id'] ? 'selected' : ''?>><?=htmlspecialchars($cat['name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3 col-6 text-right">
            <?php if ($cat_id !== '' || $brand_id !== '' || $q !== ''): ?>
            <a href="stock_closing.php" class="btn btn-sm btn-outline-danger btn-block" title="Reset Filters"><i class="fas fa-undo"></i> Reset</a>
            <?php else: ?>
            <button type="button" class="btn btn-sm btn-outline-secondary btn-block" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Printable Header / Letterhead -->
<div class="report-sheet d-none d-print-block mb-3">
  <div class="report-head">
    <div class="report-brand-line">
      <div class="report-brand">
        <div class="report-brand-name">Mehboob Traders</div>
        <div class="report-brand-sub">Wholesale Business &middot; Lahore, Pakistan &middot; GST No: --</div>
      </div>
      <div class="report-title-box">
        <div class="report-title">STOCK CLOSING REPORT</div>
        <div class="report-meta">
          As of <?=formatDate(date('Y-m-d'))?> &middot; Time: <?=date('h:i A')?>
          <?php if ($cat_id !== ''):
            $c_name = '';
            foreach ($categories as $c) { if ($c['id'] == $cat_id) { $c_name = $c['name']; break; } }
            echo " &middot; Category: " . htmlspecialchars($c_name);
          endif; ?>
        </div>
      </div>
    </div>
  </div>

  <table class="report-summary-table">
    <tr>
      <td class="rs-cell"><span class="rs-label">Total Products</span><span class="rs-val"><?=count($products)?></span></td>
      <td class="rs-cell"><span class="rs-label">Total Stock (Boxes)</span><span class="rs-val"><?=number_format($total_boxes)?></span></td>
      <td class="rs-cell"><span class="rs-label">Carton Breakdown</span><span class="rs-val"><?=number_format($total_full_cartons)?> Ctns + <?=$total_loose_boxes?> Bx</span></td>
      <?php if (isAdmin()): ?>
      <td class="rs-cell"><span class="rs-label">Stock Value (Cost)</span><span class="rs-val" style="color:#0f766e;">PKR <?=formatCurrency($total_cost_val)?></span></td>
      <td class="rs-cell"><span class="rs-label">Stock Value (Sale)</span><span class="rs-val" style="color:#0369a1;">PKR <?=formatCurrency($total_sale_val)?></span></td>
      <?php endif; ?>
      <td class="rs-cell"><span class="rs-label">Low/Out Stock Alerts</span><span class="rs-val" style="color:#b91c1c;"><?=$total_alert_items?> Items</span></td>
    </tr>
  </table>
</div>

<!-- Main Stock Table -->
<div class="card shadow-sm border-0 mb-4">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-bordered table-hover mb-0 report-table" id="stockClosingTable">
        <thead class="bg-light text-dark">
          <tr>
            <th class="text-center" style="width: 40px;">#</th>
            <th style="width: 85px;">Code</th>
            <th>Product Name</th>
            <th class="text-center" style="width: 95px;">Carton Size</th>
            <th class="text-center" style="width: 110px;">Stock (Boxes)</th>
            <th style="width: 140px;">Cartons Breakdown</th>
            <?php if (isAdmin()): ?>
            <th class="text-right" style="width: 120px;">Purchase Rate</th>
            <?php endif; ?>
            <th class="text-right" style="width: 110px;">Sale Rate</th>
            <?php if (isAdmin()): ?>
            <th class="text-right" style="width: 130px;">Value (Cost)</th>
            <th class="text-right" style="width: 130px;">Value (Sale)</th>
            <?php endif; ?>
            <th class="text-center" style="width: 115px;">Status</th>
            <th class="text-center d-print-none" style="width: 60px;">Action</th>
          </tr>
        </thead>
        <tbody id="stockTableBody">
          <?php if (empty($products)): ?>
          <tr id="emptyStockRow">
            <td colspan="<?=isAdmin() ? 12 : 9?>" class="text-center text-muted py-5">
              <i class="fas fa-boxes fa-3x text-muted mb-2 d-block"></i>
              No products found matching the criteria.
            </td>
          </tr>
          <?php else: ?>
          <?php foreach ($products as $idx => $p):
            $st = $p['stock_status'];
            $statusClass = ($st === 'out') ? 'badge-danger' : (($st === 'low') ? 'badge-warning text-dark' : 'badge-success');
            $statusLabel = ($st === 'out') ? 'Out of Stock' : (($st === 'low') ? 'Low Stock' : 'In Stock');
            $rowBg = ($st === 'out') ? 'style="background-color: #fff8f8;"' : (($st === 'low') ? 'style="background-color: #fffdf5;"' : '');
          ?>
          <tr class="stock-row" <?=$rowBg?>
              data-id="<?=$p['id']?>"
              data-name="<?=htmlspecialchars(strtolower($p['name']))?>"
              data-code="<?=htmlspecialchars(strtolower($p['code']))?>"
              data-cat="<?=htmlspecialchars(strtolower($p['cat_name'] ?? ''))?>"
              data-brand="<?=htmlspecialchars(strtolower($p['brand_name'] ?? ''))?>"
              data-boxes="<?=$p['calc_boxes']?>"
              data-bpc="<?=$p['calc_bpc']?>"
              data-cartons="<?=$p['calc_cartons']?>"
              data-loose="<?=$p['calc_loose']?>"
              data-cost-val="<?=$p['cost_val']?>"
              data-sale-val="<?=$p['sale_val']?>"
              data-profit-val="<?=$p['profit_val']?>"
              data-status="<?=$st?>">
            <td class="text-center text-muted small row-num"><?=($idx + 1)?></td>
            <td class="font-weight-bold text-muted small"><?=htmlspecialchars($p['code'])?></td>
            <td>
              <div class="font-weight-bold text-dark"><?=htmlspecialchars($p['name'])?></div>
              <small class="text-muted">
                <?=htmlspecialchars($p['cat_name'] ?? 'Uncategorized')?>
                <?php if (!empty($p['brand_name'])): ?> &middot; Brand: <?=htmlspecialchars($p['brand_name'])?><?php endif; ?>
              </small>
            </td>
            <td class="text-center small">
              <span class="badge badge-light border font-weight-normal">1 Ctn = <?=$p['calc_bpc']?> Bx</span>
            </td>
            <td class="text-center">
              <?php if ($p['calc_boxes'] <= 0): ?>
                <span class="badge badge-danger px-2 py-1 font-weight-bold" style="font-size: 0.85rem;">0 Boxes</span>
              <?php else: ?>
                <span class="font-weight-bold text-dark" style="font-size: 0.95rem;"><?=$p['calc_boxes']?></span> <small class="text-muted">Boxes</small>
              <?php endif; ?>
            </td>
            <td class="small">
              <?php if ($p['calc_boxes'] <= 0): ?>
                <span class="text-muted">&mdash;</span>
              <?php else: ?>
                <span class="font-weight-bold text-primary"><?=$p['calc_cartons']?> Ctn<?= $p['calc_cartons'] === 1 ? '' : 's' ?></span>
                <?php if ($p['calc_loose'] > 0): ?>
                  + <span class="font-weight-bold text-secondary"><?=$p['calc_loose']?> Bx</span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <?php if (isAdmin()): ?>
            <td class="text-right small">
              <div class="font-weight-bold text-dark">PKR <?=formatCurrency($p['cost_per_box'])?> <small class="text-muted">/bx</small></div>
              <small class="text-muted">Ctn: <?=formatCurrency($p['purchase_price'])?></small>
            </td>
            <?php endif; ?>
            <td class="text-right small">
              <div class="font-weight-bold text-dark">PKR <?=formatCurrency($p['sale_per_box'])?> <small class="text-muted">/bx</small></div>
              <small class="text-muted">Ctn: <?=formatCurrency($p['sale_price'])?></small>
            </td>
            <?php if (isAdmin()): ?>
            <td class="text-right font-weight-bold text-success stock-val-cell">
              PKR <?=formatCurrency($p['cost_val'])?>
            </td>
            <td class="text-right font-weight-bold text-primary stock-val-cell">
              PKR <?=formatCurrency($p['sale_val'])?>
            </td>
            <?php endif; ?>
            <td class="text-center">
              <span class="badge <?=$statusClass?> px-2 py-1" style="font-size: 0.78rem;">
                <?php if ($st === 'out'): ?><i class="fas fa-ban mr-1"></i><?php elseif ($st === 'low'): ?><i class="fas fa-exclamation-triangle mr-1"></i><?php else: ?><i class="fas fa-check mr-1"></i><?php endif; ?>
                <?=$statusLabel?>
              </span>
              <?php if ($st === 'low'): ?>
                <div class="text-muted" style="font-size: 10px;">Min: <?=$p['min_stock_level']?> Bx</div>
              <?php endif; ?>
            </td>
            <td class="text-center d-print-none">
              <a href="product_edit.php?id=<?=$p['id']?>" class="btn btn-sm btn-outline-primary py-0 px-2" title="Edit Product">
                <i class="fas fa-edit"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
        <tfoot class="bg-light font-weight-bold report-tfoot" style="border-top: 2px solid #cbd5e1;">
          <tr>
            <td colspan="4" class="text-right" id="tfootLabel">GRAND TOTAL (<span id="visibleItemsCount"><?=count($products)?></span> Products):</td>
            <td class="text-center" id="tfootTotalBoxes"><?=number_format($total_boxes)?> Boxes</td>
            <td id="tfootTotalCartons"><?=number_format($total_full_cartons)?> Ctns + <?=$total_loose_boxes?> Bx</td>
            <?php if (isAdmin()): ?>
            <td></td>
            <?php endif; ?>
            <td></td>
            <?php if (isAdmin()): ?>
            <td class="text-right text-success stock-val-cell" id="tfootCostVal">PKR <?=formatCurrency($total_cost_val)?></td>
            <td class="text-right text-primary stock-val-cell" id="tfootSaleVal">PKR <?=formatCurrency($total_sale_val)?></td>
            <?php endif; ?>
            <td colspan="<?=isAdmin() ? 2 : 1?>"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<!-- Print Signatures Footer -->
<div class="report-foot d-none d-print-block mt-4">
  <div style="width: 30%; border-top: 1px solid #334155; padding-top: 4px; text-align: center;">
    <strong>Prepared By</strong><br><small><?=htmlspecialchars($_SESSION['user_name'] ?? 'Staff')?></small>
  </div>
  <div style="width: 30%; border-top: 1px solid #334155; padding-top: 4px; text-align: center;">
    <strong>Warehouse Incharge / Stock Verifier</strong><br><small>Physical Count Verified</small>
  </div>
  <div style="width: 30%; border-top: 1px solid #334155; padding-top: 4px; text-align: center;">
    <strong>Authorized Signature</strong><br><small>Mehboob Traders</small>
  </div>
</div>

<script>
$(document).ready(function(){
  var activeFilter = 'all'; // 'all', 'in', 'alert', 'out'
  var isLowStockToggled = false;

  function filterStockRows(){
    var q = $.trim($('#liveStockSearch').val().toLowerCase());
    var cat = $('#filterCategory').val();
    $('#btnClearSearch').toggle(q.length > 0);

    var visibleCount = 0;
    var sumBoxes = 0;
    var sumCartons = 0;
    var sumLoose = 0;
    var sumCost = 0;
    var sumSale = 0;

    $('.stock-row').each(function(){
      var $r = $(this);
      var name = $r.data('name') || '';
      var code = $r.data('code') || '';
      var catName = $r.data('cat') || '';
      var brandName = $r.data('brand') || '';
      var status = $r.data('status') || '';
      var boxes = parseInt($r.data('boxes')) || 0;
      var cartons = parseInt($r.data('cartons')) || 0;
      var loose = parseInt($r.data('loose')) || 0;
      var cost = parseFloat($r.data('cost-val')) || 0;
      var sale = parseFloat($r.data('sale-val')) || 0;

      // Status pill / Low Stock Alert filter
      var statusMatch = true;
      if (activeFilter === 'in') {
        statusMatch = (status === 'in');
      } else if (activeFilter === 'alert') {
        statusMatch = (status === 'low' || status === 'out');
      } else if (activeFilter === 'out') {
        statusMatch = (status === 'out');
      }

      // Text query match
      var textMatch = true;
      if (q !== '') {
        textMatch = (name.indexOf(q) > -1 || code.indexOf(q) > -1 || catName.indexOf(q) > -1 || brandName.indexOf(q) > -1);
      }

      if (statusMatch && textMatch) {
        $r.show();
        visibleCount++;
        if (boxes > 0) {
          sumBoxes += boxes;
          sumCartons += cartons;
          sumLoose += loose;
          sumCost += cost;
          sumSale += sale;
        }
        $r.find('.row-num').text(visibleCount);
      } else {
        $r.hide();
      }
    });

    // Update table totals
    $('#visibleItemsCount').text(visibleCount);
    $('#tfootTotalBoxes').text(sumBoxes.toLocaleString() + ' Boxes');
    $('#tfootTotalCartons').text(sumCartons.toLocaleString() + ' Ctns + ' + sumLoose + ' Bx');
    $('#tfootCostVal').text('PKR ' + sumCost.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}));
    $('#tfootSaleVal').text('PKR ' + sumSale.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}));

    // Toggle empty row
    if (visibleCount === 0) {
      if (!$('#noMatchStockRow').length) {
        $('#stockTableBody').append('<tr id="noMatchStockRow"><td colspan="<?=isAdmin() ? 12 : 9?>" class="text-center text-muted py-5"><i class="fas fa-search fa-2x mb-2 d-block text-muted"></i>No products match the selected criteria.</td></tr>');
      } else {
        $('#noMatchStockRow').show();
      }
    } else {
      $('#noMatchStockRow').remove();
    }
  }

  // Live text search
  $('#liveStockSearch').on('input', function(){
    filterStockRows();
  });

  $('#btnClearSearch').on('click', function(){
    $('#liveStockSearch').val('').focus();
    filterStockRows();
  });

  $('#filterCategory').on('change', function(){
    $('#stockFilterForm').submit();
  });

  // Stock status pill click
  $('.stock-pill-btn').on('click', function(){
    $('.stock-pill-btn').removeClass('active active-warning active-danger');
    $(this).addClass('active');
    activeFilter = $(this).data('filter');

    // Sync button state
    if (activeFilter === 'alert') {
      $('#btnLowStockAlert').addClass('active').html('<i class="fas fa-boxes mr-1"></i> Show All Products');
      isLowStockToggled = true;
    } else {
      $('#btnLowStockAlert').removeClass('active').html('<i class="fas fa-exclamation-triangle mr-1"></i> Low Stock Alert (<span id="btnLowStockCount"><?=$total_alert_items?></span>)');
      isLowStockToggled = false;
    }

    filterStockRows();
  });

  // Low Stock Alert special toggle button
  $('#btnLowStockAlert').on('click', function(){
    if (!isLowStockToggled) {
      // Activate Low Stock Alert filter
      isLowStockToggled = true;
      activeFilter = 'alert';
      $('.stock-pill-btn').removeClass('active active-warning active-danger');
      $('.stock-pill-btn[data-filter="alert"]').addClass('active active-warning');
      $(this).html('<i class="fas fa-boxes mr-1"></i> Show All Products');
    } else {
      // Toggle back to All Products
      isLowStockToggled = false;
      activeFilter = 'all';
      $('.stock-pill-btn').removeClass('active active-warning active-danger');
      $('.stock-pill-btn[data-filter="all"]').addClass('active');
      $(this).html('<i class="fas fa-exclamation-triangle mr-1"></i> Low Stock Alert (<span id="btnLowStockCount"><?=$total_alert_items?></span>)');
    }
    filterStockRows();
  });

  // Clicking the KPI alerts card triggers Low Stock filter
  $('#cardAlertsTrigger').on('click', function(){
    $('#btnLowStockAlert').trigger('click');
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
