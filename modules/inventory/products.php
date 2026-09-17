<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Products';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker','loader']);

// Filter
$cat_filter = $_GET['category_id'] ?? '';
$q = trim($_GET['q'] ?? '');

$sql = "SELECT p.*, c.name AS cat_name, b.name AS brand_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        WHERE 1=1";
$params = [];
if ($cat_filter !== '') {
    $sql .= " AND p.category_id = ?";
    $params[] = $cat_filter;
}
if ($q !== '') {
    $sql .= " AND (p.name LIKE ? OR p.code LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
}
$sql .= " ORDER BY p.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6><i class="fas fa-box"></i> Products (<?=count($products)?>)</h6>
    <div>
      <button type="button" class="btn btn-sm btn-outline-secondary d-print-none" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
      <?php if (isAdmin()): ?><a href="product_create.php" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Add Product</a><?php endif; ?>
    </div>
  </div>
  <div class="card-body">
    <form method="get" class="row g-2 mb-3 d-print-none">
      <div class="col-md-4">
        <input type="text" name="q" class="form-control" placeholder="Search by name or code" value="<?=htmlspecialchars($q)?>">
      </div>
      <div class="col-md-3">
        <select name="category_id" class="form-control">
          <option value="">All Categories</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?=$c['id']?>" <?= $cat_filter == $c['id'] ? 'selected' : '' ?>><?=htmlspecialchars($c['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button class="btn btn-outline-primary btn-block"><i class="fas fa-search"></i> Search</button>
      </div>
    </form>

    <!-- Printable header -->
<div class="d-none d-print-block mb-3 text-center">
  <h4 class="font-weight-bold mb-0" style="color:#0f172a;">Mehboob Traders</h4>
  <small class="text-muted">Wholesale Business</small>
  <h5 class="font-weight-bold text-primary mt-2 mb-0">PRODUCTS LIST</h5>
  <?php $pr_meta = [];
  if ($cat_filter !== '') {
      $pr_cat = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
      $pr_cat->execute([$cat_filter]);
      $pr_meta[] = 'Category: ' . $pr_cat->fetchColumn();
  }
  if ($q !== '') $pr_meta[] = 'Search: ' . $q;
  if ($pr_meta): ?><div class="mt-1 font-weight-bold"><?=implode(' &nbsp;|&nbsp; ', $pr_meta)?></div><?php endif; ?>
  <small>Printed on <?=formatDate(date('Y-m-d'))?></small>
</div>

<div class="table-responsive">
      <table class="table table-bordered table-hover">
        <thead>
          <tr><th>Code</th><th>Name</th><th>Category</th><th>Unit</th><th>Boxes per Carton</th><th>Purchase Price</th><th>Sale Price</th><th>Stock</th><th>Status</th><th class="d-print-none">Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($products as $p): ?>
          <?php
            $stock = (float)$p['stock_quantity'];
            $stockClass = $stock <= 0 ? 'stock-out' : ($stock <= (float)$p['min_stock_level'] ? 'stock-low' : 'stock-ok');
          ?>
          <tr>
            <td><?=htmlspecialchars($p['code'])?></td>
            <td class="font-weight-bold"><?=htmlspecialchars($p['name'])?></td>
            <td><?=htmlspecialchars($p['cat_name'] ?? '-')?></td>
            <td><?=htmlspecialchars($p['unit'])?></td>
            <td><?=(int)$p['boxes_per_carton']?></td>
            <td class="purchase-price-cell" data-rate="<?=htmlspecialchars($p['purchase_price'])?>" style="white-space: nowrap;">
              <span class="purchase-rate-val">PKR <?=formatCurrency($p['purchase_price'])?></span>
              <?php if (isAdmin()): ?>
              <button type="button" class="btn btn-sm btn-link p-0 ml-1 text-secondary quick-rate" data-id="<?=$p['id']?>" data-name="<?=htmlspecialchars($p['name'])?>" data-code="<?=htmlspecialchars($p['code'])?>" data-rate="<?=htmlspecialchars($p['purchase_price'])?>" data-field="purchase_price" title="Quick update purchase rate"><i class="fas fa-pen"></i></button>
              <?php endif; ?>
            </td>
            <td class="sale-price-cell" data-rate="<?=htmlspecialchars($p['sale_price'])?>" style="white-space: nowrap;">
              <span class="sale-rate-val">PKR <?=formatCurrency($p['sale_price'])?></span>
              <?php if (isAdmin()): ?>
              <button type="button" class="btn btn-sm btn-link p-0 ml-1 text-secondary quick-rate" data-id="<?=$p['id']?>" data-name="<?=htmlspecialchars($p['name'])?>" data-code="<?=htmlspecialchars($p['code'])?>" data-rate="<?=htmlspecialchars($p['sale_price'])?>" data-field="sale_price" title="Quick update sale rate"><i class="fas fa-pen"></i></button>
              <?php endif; ?>
            </td>
            <td class="<?=$stockClass?>"><?= (int)$stock ?> Boxes
              <?php $bpc = max(1, (int)$p['boxes_per_carton']); $stock_ctns = (int)floor($stock / $bpc); $stock_rem = (int)($stock % $bpc); ?>
              <br><small class="text-muted"><?=$stock_ctns?> Carton<?=$stock_ctns==1?'':'s'?><?=$stock_rem>0 ? ' + '.$stock_rem.' Box'.($stock_rem==1?'':'es') : ''?></small>
              <?php if ($stock <= 0): ?><br><small class="text-danger"><i class="fas fa-exclamation-circle"></i> Out of stock</small>
              <?php elseif ($stock <= (float)$p['min_stock_level'] && (float)$p['min_stock_level'] > 0): ?><br><small class="text-warning"><i class="fas fa-exclamation-triangle"></i> Low stock</small>
              <?php endif; ?>
            </td>
            <td><?= $p['status'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-secondary">Inactive</span>' ?></td>
            <td class="text-center d-print-none" nowrap>
              <button type="button" class="btn btn-sm btn-outline-info view-product" data-id="<?=$p['id']?>" title="View Product"><i class="fas fa-eye"></i></button>
              <?php if (isAdmin()): ?>
              <a href="product_edit.php?id=<?=$p['id']?>" class="btn btn-sm btn-outline-warning" title="Edit Product"><i class="fas fa-edit"></i></a>
              <form method="post" action="product_delete.php" class="d-inline" onsubmit="return confirm('Delete this product?');">
                <input type="hidden" name="id" value="<?=$p['id']?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Product"><i class="fas fa-trash-alt"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($products)): ?>
          <tr><td colspan="10" class="text-center text-muted py-4">No products found. <a href="product_create.php">Add your first product</a></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Product View Modal -->
<div class="modal fade" id="productViewModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title"><i class="fas fa-box"></i> Product Details</h6>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body" id="productViewBody">
        <div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
      </div>
    </div>
  </div>
</div>

<!-- Purchase Rate Quick-Update Modal -->
<?php if (isAdmin()): ?>
<div class="modal fade" id="rateUpdateModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="rateModalTitle"><i class="fas fa-tags"></i> Update Purchase Rate</h6>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <div id="rateAlert"></div>
        <h6 class="font-weight-bold text-dark mb-0" id="rateProductName">-</h6>
        <small class="text-muted d-block mb-3"><span id="rateProductCode">-</span></small>
        <div class="form-group mb-0">
          <label class="form-label font-weight-bold small text-muted" id="rateCurrentLabel">Current Purchase Rate (PKR)</label>
          <input type="text" id="rateCurrent" class="form-control bg-light" readonly>
        </div>
        <div class="form-group mt-3 mb-0">
          <label class="form-label font-weight-bold small text-muted" id="rateNewLabel">New Purchase Rate (PKR)</label>
          <input type="number" id="rateNew" class="form-control font-weight-bold" min="0" step="0.01" placeholder="0.00">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="rateSaveBtn"><i class="fas fa-save mr-1"></i> Save Rate</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function(){
  <?php if (isAdmin()): ?>
  var rateModalProductId = null;
  var rateField = 'purchase_price';

  function rateFieldLabel(f){ return f === 'sale_price' ? 'Sale' : 'Purchase'; }

  $(document).on('click', '.quick-rate', function(){
    rateModalProductId = $(this).data('id');
    rateField = $(this).data('field') || 'purchase_price';
    var lbl = rateFieldLabel(rateField);
    $('#rateModalTitle').html('<i class="fas fa-tags"></i> Update ' + lbl + ' Rate');
    $('#rateCurrentLabel').text('Current ' + lbl + ' Rate (PKR)');
    $('#rateNewLabel').text('New ' + lbl + ' Rate (PKR)');
    $('#rateProductName').text($(this).data('name'));
    $('#rateProductCode').text($(this).data('code') ? 'Code: ' + $(this).data('code') : '');
    $('#rateCurrent').val($(this).data('rate'));
    $('#rateNew').val($(this).data('rate'));
    $('#rateAlert').empty();
    $('#rateUpdateModal').modal('show');
    setTimeout(function(){ $('#rateNew').focus(); $('#rateNew').select(); }, 400);
  });

  function showRateAlert(type, msg){
    var cls = type === 'success' ? 'alert alert-success' : 'alert alert-danger';
    $('#rateAlert').html('<div class="' + cls + ' py-2 px-3 mb-3"><i class="fas ' + (type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle') + ' mr-1"></i> ' + msg + '</div>');
  }

  $('#rateSaveBtn').on('click', function(){
    var rate = $.trim($('#rateNew').val());
    if (rate === '' || isNaN(parseFloat(rate)) || parseFloat(rate) < 0) {
      showRateAlert('error', 'Enter a valid rate of 0 or more.');
      $('#rateNew').focus();
      return;
    }
    $('#rateSaveBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving...');
    $.ajax({
      url: 'ajax_product_update_rate.php',
      method: 'POST',
      data: {id: rateModalProductId, field: rateField, rate: rate},
      dataType: 'json'
    }).done(function(res){
      if (res && res.ok) {
        var cellSel = rateField === 'sale_price' ? '.sale-price-cell' : '.purchase-price-cell';
        var $cell = $('.quick-rate[data-id="' + rateModalProductId + '"][data-field="' + rateField + '"]').closest(cellSel);
        $cell.attr('data-rate', rate);
        var valSel = rateField === 'sale_price' ? '.sale-rate-val' : '.purchase-rate-val';
        $cell.find(valSel).text('PKR ' + numberWithCommas(parseFloat(rate).toFixed(2)));
        $cell.find('.quick-rate').data('rate', rate);
        $('#rateUpdateModal').modal('hide');
        showRateAlert('success', res.message || rateFieldLabel(rateField) + ' rate updated.');
      } else {
        showRateAlert('error', (res && res.error) || 'Could not update rate.');
      }
    }).fail(function(xhr){
      var msg = 'Could not update rate. Please try again.';
      try { var j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e){}
      showRateAlert('error', msg);
    }).always(function(){
      $('#rateSaveBtn').prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Save Rate');
    });
  });

  function numberWithCommas(x){
    var parts = String(x).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return parts.join('.');
  }
  <?php endif; ?>

  $(document).on('click', '.view-product', function(){
    var id = $(this).data('id');
    $('#productViewBody').html('<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
    $('#productViewModal').modal('show');
    $.ajax({
      url: 'ajax_product_view.php',
      data: {id: id},
      dataType: 'html'
    }).done(function(html){
      $('#productViewBody').html(html);
    }).fail(function(){
      $('#productViewBody').html('<div class="alert alert-danger mb-0">Could not load product details. Please refresh and try again.</div>');
    });
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>