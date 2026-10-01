<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Low / Out of Stock';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker','loader']);

// Filters
$q = trim($_GET['q'] ?? '');
$cat_filter = $_GET['category_id'] ?? '';
$kind = $_GET['kind'] ?? '';   // '' = all alerts, 'out' = out of stock, 'low' = low stock

$sql = "SELECT p.*, c.name AS cat_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.status = 1
          AND (p.stock_quantity <= 0 OR (p.min_stock_level > 0 AND p.stock_quantity <= p.min_stock_level))";
$params = [];

if ($q !== '') {
    $sql .= " AND (p.name LIKE ? OR p.code LIKE ? OR c.name LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
}
if ($cat_filter !== '') {
    $sql .= " AND p.category_id = ?";
    $params[] = $cat_filter;
}
if ($kind === 'out') {
    $sql .= " AND p.stock_quantity <= 0";
} elseif ($kind === 'low') {
    $sql .= " AND p.stock_quantity > 0 AND p.min_stock_level > 0 AND p.stock_quantity <= p.min_stock_level";
}
$sql .= " ORDER BY (p.stock_quantity <= 0) DESC, (p.min_stock_level - p.stock_quantity) DESC, p.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

// Unfiltered totals (whole catalogue) for the summary cards
$total_alerts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status = 1 AND (stock_quantity <= 0 OR (min_stock_level > 0 AND stock_quantity <= min_stock_level))")->fetchColumn();
$total_out = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status = 1 AND stock_quantity <= 0")->fetchColumn();
$total_low = $total_alerts - $total_out;
$list_out = 0; $list_low = 0;
foreach ($products as $p) {
    if ((float)$p['stock_quantity'] <= 0) $list_out++; else $list_low++;
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6><i class="fas fa-exclamation-triangle text-warning"></i> Low / Out of Stock Products (<span id="topHeaderCount"><?=count($products)?></span>)</h6>
    <div>
      <button type="button" class="btn btn-sm btn-outline-secondary d-print-none" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
      <a href="products.php" class="btn btn-sm btn-outline-primary d-print-none ml-1"><i class="fas fa-box"></i> All Products</a>
      <?php if (isAdmin()): ?>
      <a href="product_create.php" class="btn btn-sm btn-primary ml-1"><i class="fas fa-plus"></i> Add Product</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body">

    <!-- Printable letterhead -->
    <div class="report-sheet d-none d-print-block">
      <div class="report-head">
        <div class="report-brand-line">
          <div class="report-brand">
            <div class="report-brand-name">Mehboob Traders</div>
            <div class="report-brand-sub">Wholesale Business &middot; Lahore, Pakistan &middot; GST No: --</div>
          </div>
          <div class="report-title-box">
            <div class="report-title">Low / Out of Stock Report</div>
            <div class="report-meta"><?php $ls_meta = []; if ($cat_filter !== '') { $ls_c = $pdo->prepare("SELECT name FROM categories WHERE id = ?"); $ls_c->execute([$cat_filter]); $ls_meta[] = 'Category: ' . $ls_c->fetchColumn(); } if ($kind === 'out') $ls_meta[] = 'Out of Stock only'; if ($kind === 'low') $ls_meta[] = 'Low Stock only'; if ($q !== '') $ls_meta[] = 'Search: ' . $q; echo $ls_meta ? htmlspecialchars(implode(' | ', $ls_meta)) : 'All Products'; ?></div>
          </div>
        </div>
      </div>
      <table class="report-summary-table">
        <tr>
          <td class="rs-cell"><span class="rs-label">Low Stock</span><span class="rs-val" style="color:#b45309;" id="prLow"><?=$list_low?></span></td>
          <td class="rs-cell"><span class="rs-label">Out of Stock</span><span class="rs-val" style="color:#b91c1c;" id="prOut"><?=$list_out?></span></td>
          <td class="rs-cell"><span class="rs-label">Total Listed</span><span class="rs-val" id="prTotal"><?=count($products)?></span></td>
        </tr>
      </table>
    </div>

    <!-- Filters / Search -->
    <div class="card bg-light border shadow-sm mb-3 d-print-none">
      <div class="card-body py-3 px-3">
        <form method="get" id="filterForm" class="row g-2 align-items-end">
          <div class="col-lg-4 col-md-6 mb-2 mb-lg-0">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="fas fa-search text-primary"></i> Search</label>
            <div class="input-group">
              <input type="text" id="liveProductSearch" name="q" class="form-control" style="height: 38px;" placeholder="Search product, code or category..." value="<?=htmlspecialchars($q)?>" autocomplete="off" spellcheck="false">
              <div class="input-group-append">
                <button type="button" class="btn btn-outline-secondary bg-white" id="clearSearchBtn" title="Clear Search" <?= $q === '' ? 'style="display:none;"' : '' ?>><i class="fas fa-times"></i></button>
              </div>
            </div>
          </div>
          <div class="col-lg-3 col-md-6 mb-2 mb-lg-0">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="fas fa-tags text-secondary"></i> Category</label>
            <select name="category_id" class="form-control" style="height: 38px;" onchange="document.getElementById('filterForm').submit();">
              <option value="">All Categories</option>
              <?php foreach ($categories as $c): ?>
              <option value="<?=$c['id']?>" <?= $cat_filter == $c['id'] ? 'selected' : '' ?>><?=htmlspecialchars($c['name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-lg-3 col-md-6 mb-2 mb-lg-0">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="fas fa-filter text-secondary"></i> Status</label>
            <select name="kind" class="form-control" style="height: 38px;" onchange="document.getElementById('filterForm').submit();">
              <option value="">All Alerts</option>
              <option value="low" <?= $kind === 'low' ? 'selected' : '' ?>>Low Stock Only</option>
              <option value="out" <?= $kind === 'out' ? 'selected' : '' ?>>Out of Stock Only</option>
            </select>
          </div>
          <div class="col-lg-2 col-md-6 mb-2 mb-lg-0">
            <?php if ($q !== '' || $cat_filter !== '' || $kind !== ''): ?>
            <a href="low_stock.php" class="btn btn-outline-danger btn-block" style="height: 38px;" title="Reset All Filters"><i class="fas fa-undo"></i> Reset</a>
            <?php else: ?>
            <button type="submit" class="btn btn-outline-primary btn-block" style="height: 38px;"><i class="fas fa-search"></i> Search</button>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- Live summary cards -->
    <div class="row g-2 mb-3 d-print-none">
      <div class="col-xl-3 col-md-6 col-6 mb-2 mb-xl-0">
        <div class="card border-left-warning shadow-sm h-100">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Total Alerts</div>
            <div class="h5 mb-0 font-weight-bold text-gray-800" id="statTotal"><?=$total_alerts?> Products</div>
          </div>
        </div>
      </div>
      <div class="col-xl-3 col-md-6 col-6 mb-2 mb-xl-0">
        <div class="card border-left-warning shadow-sm h-100">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Low Stock</div>
            <div class="h5 mb-0 font-weight-bold text-warning" id="statLow"><?=$total_low?> Products</div>
          </div>
        </div>
      </div>
      <div class="col-xl-3 col-md-6 col-6 mb-2 mb-xl-0">
        <div class="card border-left-danger shadow-sm h-100">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Out of Stock</div>
            <div class="h5 mb-0 font-weight-bold text-danger" id="statOut"><?=$total_out?> Products</div>
          </div>
        </div>
      </div>
      <div class="col-xl-3 col-md-6 col-6 mb-2 mb-xl-0">
        <div class="card border-left-primary shadow-sm h-100">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Shown in List</div>
            <div class="h5 mb-0 font-weight-bold text-primary" id="statShown"><?=count($products)?> Products</div>
          </div>
        </div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-bordered table-hover report-table">
        <thead>
          <tr>
            <th>Code</th><th>Product</th><th>Category</th>
            <th class="text-center">In Stock (Boxes)</th>
            <th class="text-center">Min Level (Boxes)</th>
            <th class="text-center">Shortfall (Boxes)</th>
            <th class="text-center">Status</th>
            <?php if (isAdmin()): ?><th class="no-print">Action</th><?php endif; ?>
          </tr>
        </thead>
        <tbody id="lowStockTableBody">
          <?php foreach ($products as $p): ?>
          <?php
            $stock = (float)$p['stock_quantity'];
            $min = (float)$p['min_stock_level'];
            $isOut = $stock <= 0;
            $shortfall = max(0, $min - $stock);
            $bpc = max(1, (int)$p['boxes_per_carton']);
            $ctns = (int)floor($stock / $bpc);
            $rem = (int)($stock % $bpc);
          ?>
          <tr class="low-row"
              data-hay="<?=htmlspecialchars(strtolower($p['name'].' '.$p['code'].' '.($p['cat_name'] ?? '').' '.($isOut ? 'out of stock' : 'low')))?>"
              data-kind="<?= $isOut ? 'out' : 'low' ?>"
              data-boxes="<?= (int)$stock ?>"
              data-short="<?= (int)$shortfall ?>">
            <td><?=htmlspecialchars($p['code'])?></td>
            <td class="font-weight-bold"><?=htmlspecialchars($p['name'])?></td>
            <td><?=htmlspecialchars($p['cat_name'] ?? '-')?></td>
            <td class="text-center <?= $isOut ? 'stock-out' : 'stock-low' ?>"><?= (int)$stock ?> Boxes</td>
            <td class="text-center"><?= (int)$min ?></td>
            <td class="text-center font-weight-bold text-danger"><?= (int)$shortfall ?></td>
            <td class="text-center">
              <?php if ($isOut): ?>
                <span class="badge badge-danger">Out of Stock</span>
              <?php else: ?>
                <span class="badge badge-warning">Low</span>
              <?php endif; ?>
            </td>
            <?php if (isAdmin()): ?>
            <td class="text-center no-print" nowrap>
              <a href="product_edit.php?id=<?=$p['id']?>" class="btn btn-sm btn-outline-warning" title="Edit Product"><i class="fas fa-edit"></i></a>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($products)): ?>
          <tr id="emptyRow"><td colspan="<?=isAdmin() ? 8 : 7?>" class="text-center text-success py-4"><i class="fas fa-check-circle"></i> No low or out of stock products<?= ($q !== '' || $cat_filter !== '' || $kind !== '') ? ' for this filter' : ' &mdash; all stock levels are healthy' ?></td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot class="report-tfoot">
          <tr>
            <td colspan="3" id="tfootCount">TOTAL (<?=count($products)?> products)</td>
            <td class="text-center" id="tfootBoxes"><?= array_sum(array_map(fn($p) => (int)$p['stock_quantity'], $products)) ?> Boxes</td>
            <td></td>
            <td class="text-center" id="tfootShort"><?= array_sum(array_map(fn($p) => (int)max(0, (float)$p['min_stock_level'] - (float)$p['stock_quantity']), $products)) ?></td>
            <td colspan="<?=isAdmin() ? 2 : 1?>"></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="report-foot d-none d-print-block">
      <div><strong>Prepared by:</strong> <?=htmlspecialchars($_SESSION['user_name'] ?? '—')?></div>
      <div><strong>Printed on:</strong> <?=date('d-m-Y H:i')?></div>
      <div>Mehboob Traders &middot; Low / Out of Stock Report</div>
    </div>
  </div>
</div>

<script>
$(document).ready(function(){
  function esc(s){ return $('<div>').text(s||'').html(); }

  var COLS = <?=isAdmin() ? 8 : 7?>;

  function filterRows(){
    var q = $.trim(($('#liveProductSearch').val() || '').toLowerCase());
    $('#clearSearchBtn').toggle(q.length > 0);

    var terms = q.split(/\s+/).filter(Boolean);
    var shown = 0, low = 0, out = 0, boxes = 0, short = 0;

    $('.low-row').each(function(){
      var $tr = $(this);
      var hay = $tr.data('hay') || '';
      var match = true;
      for (var i = 0; i < terms.length; i++){
        if (hay.indexOf(terms[i]) === -1) { match = false; break; }
      }
      if (match) {
        $tr.show();
        shown++;
        boxes += parseInt($tr.data('boxes'), 10) || 0;
        short += parseInt($tr.data('short'), 10) || 0;
        if ($tr.data('kind') === 'out') out++; else low++;
      } else {
        $tr.hide();
      }
    });

    if (shown === 0 && $('.low-row').length > 0) {
      if ($('#noMatchRow').length === 0) {
        $('#lowStockTableBody').append('<tr id="noMatchRow"><td colspan="' + COLS + '" class="text-center text-muted py-4"><i class="fas fa-search mr-1"></i> No matching products found for "<strong>' + esc(q) + '</strong>"</td></tr>');
      } else {
        $('#noMatchRow').html('<td colspan="' + COLS + '" class="text-center text-muted py-4"><i class="fas fa-search mr-1"></i> No matching products found for "<strong>' + esc(q) + '</strong>"</td>').show();
      }
    } else {
      $('#noMatchRow').hide();
    }

    $('#statShown').text(shown + ' Products');
    $('#topHeaderCount').text(shown);
    $('#statLow').text(low + ' Products');
    $('#statOut').text(out + ' Products');
    $('#statTotal').text(shown + ' Products');
    $('#prLow').text(low);
    $('#prOut').text(out);
    $('#prTotal').text(shown);
    $('#tfootCount').text('TOTAL (' + shown + ' products)');
    $('#tfootBoxes').text(boxes + ' Boxes');
    $('#tfootShort').text(short);
  }

  $('#liveProductSearch').on('input keyup paste change', filterRows);
  $('#liveProductSearch').on('keydown', function(e){
    if (e.key === 'Enter') { e.preventDefault(); filterRows(); }
  });
  $('#clearSearchBtn').on('click', function(){
    $('#liveProductSearch').val('').focus();
    filterRows();
  });

  if ($.trim($('#liveProductSearch').val())) { filterRows(); }
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
