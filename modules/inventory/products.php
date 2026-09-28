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
$all_active_products = isAdmin() ? $pdo->query("SELECT p.*, c.name AS cat_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.status = 1 ORDER BY p.name ASC")->fetchAll() : [];

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6><i class="fas fa-box"></i> Products (<?=count($products)?>)</h6>
    <div>
      <button type="button" class="btn btn-sm btn-outline-secondary d-print-none" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
      <?php if (isAdmin()): ?>
      <button type="button" class="btn btn-sm btn-outline-primary d-print-none ml-1" data-toggle="modal" data-target="#bulkRatesModal" title="Update Product Purchase Rates"><i class="fas fa-tags"></i> Product Rates</button>
      <a href="product_create.php" class="btn btn-sm btn-primary ml-1"><i class="fas fa-plus"></i> Add Product</a>
      <?php endif; ?>
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
          <tr><th>Code</th><th>Name</th><th>Category</th><th>Unit</th><th>Boxes per Carton</th><?php if (isAdmin()): ?><th>Purchase Price</th><?php endif; ?><th>Sale Price</th><th>Stock (Boxes)</th><th>Status</th><th class="d-print-none">Action</th></tr>
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
            <?php if (isAdmin()): ?>
            <td class="purchase-price-cell" data-rate="<?=htmlspecialchars($p['purchase_price'])?>" style="white-space: nowrap;">
              <span class="purchase-rate-val">PKR <?=formatCurrency($p['purchase_price'])?></span>
              <button type="button" class="btn btn-sm btn-link p-0 ml-1 text-secondary quick-rate" data-id="<?=$p['id']?>" data-name="<?=htmlspecialchars($p['name'])?>" data-code="<?=htmlspecialchars($p['code'])?>" data-rate="<?=htmlspecialchars($p['purchase_price'])?>" data-field="purchase_price" title="Quick update purchase rate"><i class="fas fa-pen"></i></button>
            </td>
            <?php endif; ?>
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
          <tr><td colspan="<?=isAdmin() ? 10 : 9?>" class="text-center text-muted py-4">No products found.<?php if (isAdmin()): ?> <a href="product_create.php">Add your first product</a><?php endif; ?></td></tr>
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

<?php if (isAdmin()): ?>
<!-- Product Rates Modal (All Products) -->
<div class="modal fade" id="bulkRatesModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header bg-light py-2">
        <h6 class="modal-title font-weight-bold text-dark"><i class="fas fa-tags text-primary mr-1"></i> Product Purchase Rates (Cost for Profit)</h6>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body p-3">
        <div class="alert alert-info py-2 px-3 mb-3 small">
          <i class="fas fa-info-circle mr-1"></i> <strong>Cost &amp; Profit Margin:</strong> Update purchase (cost) rates here. Profit margins in DSR and reports will be calculated against this purchase rate.
        </div>

        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="input-group input-group-sm" style="max-width: 320px;">
            <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div>
            <input type="text" id="bulkRateSearch" class="form-control" placeholder="Search product name or code...">
          </div>
          <span class="small text-muted" id="bulkRateCount"><?=count($all_active_products)?> product(s)</span>
        </div>

        <div id="bulkRateAlert"></div>

        <div class="table-responsive border rounded" style="max-height: 420px; overflow-y: auto;">
          <table class="table table-sm table-hover mb-0" id="bulkRateTable">
            <thead class="thead-light" style="position: sticky; top: 0; z-index: 1;">
              <tr>
                <th>Product</th>
                <th>Boxes / Carton</th>
                <th>Current Cost (PKR)</th>
                <th style="width: 175px;">New Cost (PKR)</th>
                <th class="text-center" style="width: 90px;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($all_active_products as $ap): ?>
              <?php $bpc = max(1, (int)$ap['boxes_per_carton']); ?>
              <tr class="bulk-rate-row" data-id="<?=$ap['id']?>" data-name="<?=strtolower(htmlspecialchars($ap['name']))?>" data-code="<?=strtolower(htmlspecialchars($ap['code']))?>">
                <td class="align-middle">
                  <div class="font-weight-bold text-dark bulk-prod-name"><?=htmlspecialchars($ap['name'])?></div>
                  <small class="text-muted">Code: <?=htmlspecialchars($ap['code'])?> <?= $ap['cat_name'] ? '&middot; ' . htmlspecialchars($ap['cat_name']) : '' ?></small>
                </td>
                <td class="align-middle"><?=$bpc?> box<?=$bpc>1?'es':''?></td>
                <td class="align-middle">
                  <span class="bulk-current-rate-text font-weight-bold text-secondary">PKR <?=formatCurrency($ap['purchase_price'])?></span>
                  <small class="text-muted d-block">/ carton</small>
                </td>
                <td class="align-middle">
                  <div class="input-group input-group-sm">
                    <div class="input-group-prepend"><span class="input-group-text">PKR</span></div>
                    <input type="number" min="0" step="0.01" class="form-control font-weight-bold bulk-rate-input" data-id="<?=$ap['id']?>" data-current="<?=$ap['purchase_price']?>" value="<?=htmlspecialchars($ap['purchase_price'])?>">
                  </div>
                </td>
                <td class="text-center align-middle">
                  <button type="button" class="btn btn-sm btn-outline-primary btn-save-single-rate" data-id="<?=$ap['id']?>" title="Save this product rate">
                    <i class="fas fa-save"></i> Save
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>
              <tr id="bulkRateNoMatch" class="d-none">
                <td colspan="5" class="text-center text-muted py-3">No matching products found.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer d-flex justify-content-between py-2">
        <span class="small text-muted" id="bulkRateModifiedStatus">No changes made yet</span>
        <div>
          <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>
          <button type="button" class="btn btn-primary btn-sm" id="btnSaveAllBulkRates"><i class="fas fa-check-double mr-1"></i> Save All Changed</button>
        </div>
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

  // ===== BULK RATES MODAL LOGIC =====
  $('#bulkRateSearch').on('input keyup', function(){
    var q = $.trim($(this).val().toLowerCase());
    var count = 0;
    $('.bulk-rate-row').each(function(){
      var name = $(this).data('name') || '';
      var code = $(this).data('code') || '';
      if (!q || name.indexOf(q) > -1 || code.indexOf(q) > -1) {
        $(this).show();
        count++;
      } else {
        $(this).hide();
      }
    });
    $('#bulkRateNoMatch').toggleClass('d-none', count > 0);
    $('#bulkRateCount').text(count + ' product(s)');
  });

  function updateModifiedCount(){
    var modified = 0;
    $('.bulk-rate-input').each(function(){
      var cur = parseFloat($(this).data('current')) || 0;
      var val = parseFloat($(this).val()) || 0;
      if (Math.abs(cur - val) > 0.001) {
        modified++;
        $(this).addClass('border-warning text-primary font-weight-bold');
      } else {
        $(this).removeClass('border-warning text-primary font-weight-bold');
      }
    });
    if (modified > 0) {
      $('#bulkRateModifiedStatus').html('<span class="text-warning font-weight-bold"><i class="fas fa-exclamation-circle"></i> ' + modified + ' rate(s) modified</span>');
    } else {
      $('#bulkRateModifiedStatus').text('No changes made yet');
    }
  }

  $(document).on('input', '.bulk-rate-input', updateModifiedCount);

  // Save single rate in modal
  $(document).on('click', '.btn-save-single-rate', function(){
    var $btn = $(this);
    var pid = $btn.data('id');
    var $row = $btn.closest('.bulk-rate-row');
    var $input = $row.find('.bulk-rate-input');
    var rate = $.trim($input.val());

    if (rate === '' || isNaN(parseFloat(rate)) || parseFloat(rate) < 0) {
      alert('Please enter a valid rate of 0 or greater.');
      $input.focus();
      return;
    }

    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
    $.ajax({
      url: 'ajax_product_update_rate.php',
      method: 'POST',
      data: {id: pid, field: 'purchase_price', rate: rate},
      dataType: 'json'
    }).done(function(res){
      if (res && res.ok) {
        $input.data('current', rate);
        $input.removeClass('border-warning text-primary');
        $row.find('.bulk-current-rate-text').text('PKR ' + numberWithCommas(parseFloat(rate).toFixed(2)));
        $btn.removeClass('btn-outline-primary').addClass('btn-success').html('<i class="fas fa-check"></i>');
        setTimeout(function(){
          $btn.removeClass('btn-success').addClass('btn-outline-primary').html('<i class="fas fa-save"></i> Save');
        }, 1800);
        // Also update the main product table on the page
        var $mainCell = $('.purchase-price-cell .quick-rate[data-id="' + pid + '"][data-field="purchase_price"]').closest('.purchase-price-cell');
        if ($mainCell.length) {
          $mainCell.attr('data-rate', rate);
          $mainCell.find('.purchase-rate-val').text('PKR ' + numberWithCommas(parseFloat(rate).toFixed(2)));
          $mainCell.find('.quick-rate').data('rate', rate);
        }
        updateModifiedCount();
      } else {
        alert((res && res.error) || 'Could not update rate.');
        $btn.html('<i class="fas fa-save"></i> Save');
      }
    }).fail(function(){
      alert('Network error. Could not update rate.');
      $btn.html('<i class="fas fa-save"></i> Save');
    }).always(function(){
      $btn.prop('disabled', false);
    });
  });

  // Save all changed rates
  $('#btnSaveAllBulkRates').on('click', function(){
    var rates = [];
    $('.bulk-rate-input').each(function(){
      var cur = parseFloat($(this).data('current')) || 0;
      var val = parseFloat($(this).val()) || 0;
      if (Math.abs(cur - val) > 0.001) {
        rates.push({id: $(this).data('id'), rate: val});
      }
    });

    if (!rates.length) {
      alert('No rates were changed.');
      return;
    }

    var $saveAll = $(this);
    $saveAll.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving ' + rates.length + ' rate(s)...');

    $.ajax({
      url: 'ajax_product_update_rate.php',
      method: 'POST',
      data: {rates: rates},
      dataType: 'json'
    }).done(function(res){
      if (res && res.ok) {
        $.each(rates, function(i, item){
          var $inp = $('.bulk-rate-input[data-id="' + item.id + '"]');
          $inp.data('current', item.rate);
          $inp.removeClass('border-warning text-primary');
          var $row = $inp.closest('.bulk-rate-row');
          $row.find('.bulk-current-rate-text').text('PKR ' + numberWithCommas(parseFloat(item.rate).toFixed(2)));
          // update main page table cell
          var $mainCell = $('.purchase-price-cell .quick-rate[data-id="' + item.id + '"][data-field="purchase_price"]').closest('.purchase-price-cell');
          if ($mainCell.length) {
            $mainCell.attr('data-rate', item.rate);
            $mainCell.find('.purchase-rate-val').text('PKR ' + numberWithCommas(parseFloat(item.rate).toFixed(2)));
            $mainCell.find('.quick-rate').data('rate', item.rate);
          }
        });
        updateModifiedCount();
        $('#bulkRateAlert').html('<div class="alert alert-success py-2 px-3 mb-2"><i class="fas fa-check-circle mr-1"></i> ' + (res.message || 'Rates updated successfully') + '</div>');
        setTimeout(function(){ $('#bulkRateAlert').empty(); }, 3500);
      } else {
        alert((res && res.error) || 'Failed to save rates.');
      }
    }).fail(function(){
      alert('Network error. Failed to save rates.');
    }).always(function(){
      $saveAll.prop('disabled', false).html('<i class="fas fa-check-double mr-1"></i> Save All Changed');
    });
  });

  $('#bulkRatesModal').on('shown.bs.modal', function(){
    $('#bulkRateSearch').val('').trigger('input').focus();
    updateModifiedCount();
  });
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