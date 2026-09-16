<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Take Order';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

$products = $pdo->query("SELECT id, code, name, unit, boxes_per_carton, sale_price, purchase_price, stock_quantity FROM products WHERE status = 1 ORDER BY name")->fetchAll();

// ===== Areas available to the current user =====
// Admin: all registered areas (+ any customer-only areas). Order Booker: his assigned 3-8 areas.
$my_areas = currentUserAreas($pdo); // null = admin (all areas)
$all_known = allKnownAreas($pdo);

if (isAdmin()) {
    $allowed_areas = $all_known;
} else {
    $allowed_areas = $my_areas;
}

$view_area = trim($_GET['area'] ?? '');
if ($view_area !== '' && !in_array($view_area, $allowed_areas, true)) {
    $view_area = '';
}

// Salesmen covering each area (employees.area is a comma list)
$salesmen_all = $pdo->query("SELECT id, full_name, area FROM employees WHERE employee_type = 'salesman' AND status = 1 ORDER BY full_name")->fetchAll();
function salesmenForArea($salesmen_all, $area) {
    $area_l = strtolower(trim($area));
    $out = [];
    foreach ($salesmen_all as $e) {
        $parts = array_map('strtolower', array_map('trim', explode(',', $e['area'] ?? '')));
        if (in_array($area_l, $parts, true)) $out[] = $e;
    }
    return $out;
}

// Area cards (each with shop count + covering salesmen)
$area_cards = [];
foreach ($allowed_areas as $an) {
    $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE LOWER(area) = LOWER(?)");
    $cnt_stmt->execute([$an]);
    $area_cards[] = [
        'name' => $an,
        'shops' => (int)$cnt_stmt->fetchColumn(),
        'salesmen' => salesmenForArea($salesmen_all, $an),
    ];
}

$customers = [];
$page_area_salesmen = [];
if ($view_area !== '') {
    $cust_stmt = $pdo->prepare("SELECT id, full_name, phone, city, area, current_balance FROM customers WHERE LOWER(area) = LOWER(?) ORDER BY full_name");
    $cust_stmt->execute([$view_area]);
    $customers = $cust_stmt->fetchAll();
    $page_area_salesmen = salesmenForArea($salesmen_all, $view_area);
}

// ===== SAVE ORDER (CREDIT ONLY — payment collected at delivery) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $salesman_id = !empty($_POST['salesman_id']) ? (int)$_POST['salesman_id'] : null;
    $sale_date = $_POST['sale_date'] ?: date('Y-m-d');
    $discount = (float)($_POST['discount_amount'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $rates = $_POST['rate'] ?? [];

    if (!$customer_id) redirect('index.php', 'Select a shop (customer) first', 'error');

    $customer = getById('customers', $customer_id);
    if (!$customer) redirect('index.php', 'Customer not found', 'error');

    // Order bookers may only take orders from customers inside their assigned areas
    if (!isAdmin() && $my_areas !== null) {
        if (empty($my_areas)) redirect('index.php', 'No areas are assigned to your login. Contact the admin.', 'error');
        $cust_areas_lower = strtolower($customer['area'] ?? '');
        $in_area = false;
        foreach ($my_areas as $ma) {
            if ($cust_areas_lower === strtolower($ma)) { $in_area = true; break; }
        }
        if (!$in_area) {
            redirect('index.php', 'You can only take orders in your assigned areas', 'error');
        }
    }

    if (!count($product_ids) || !$product_ids[0]) {
        redirect('index.php', 'Add at least one product', 'error');
    }

    $total = 0;
    $items = [];
    $stock_errors = [];
    foreach ($product_ids as $i => $pid) {
        if (!$pid) continue;
        $qty = (float)($quantities[$i] ?? 0);
        $rate = (float)($rates[$i] ?? 0);
        if ($qty <= 0) continue;
        $prod = null;
        foreach ($products as $pp) if ($pp['id'] == $pid) { $prod = $pp; break; }
        if ($prod && $qty > (float)$prod['stock_quantity']) {
            $stock_errors[] = $prod['name'] . ' (only ' . (int)$prod['stock_quantity'] . ' ' . $prod['unit'] . ' in stock)';
        }
        $subtotal = $qty * $rate;
        $total += $subtotal;
        $items[] = ['product_id' => (int)$pid, 'qty' => $qty, 'rate' => $rate, 'subtotal' => $subtotal];
    }

    if (count($stock_errors)) redirect('index.php', 'Insufficient stock: ' . implode(', ', $stock_errors), 'error');
    if (!count($items)) redirect('index.php', 'Add at least one product with quantity', 'error');

    if ($discount > $total) $discount = $total;
    $net_total = $total - $discount;

    $invoice_no = generateSaleNo();

    $pdo->beginTransaction();
    try {
        $sale_id = insert('sales', [
            'invoice_no' => $invoice_no,
            'customer_id' => $customer_id,
            'salesman_id' => $salesman_id ? (int)$salesman_id : null,
            'sale_date' => $sale_date,
            'total_amount' => $net_total,
            'discount_amount' => $discount,
            'initial_paid' => 0,
            'paid_amount' => 0,
            'due_amount' => $net_total,
            'payment_method' => 'credit',
            'bank_account_id' => null,
            'status' => 'active',
            'notes' => $notes,
            'branch_id' => currentBranchId($pdo),
            'created_by' => $_SESSION['user_id'],
            'created_at' => date('Y-m-d'),
        ]);

        foreach ($items as $it) {
            insert('sale_items', [
                'sale_id' => $sale_id,
                'product_id' => $it['product_id'],
                'quantity' => $it['qty'],
                'price' => $it['rate'],
                'subtotal' => $it['subtotal'],
            ]);
            $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?")
                ->execute([$it['qty'], $it['product_id']]);
        }

        updateCustomerBalance($pdo, $customer_id);

        $pdo->commit();
        logActivity($pdo, 'create', 'sale', $sale_id, 'Took order ' . $invoice_no . ' total ' . $net_total . ' (credit)');
        redirect('invoice.php?id=' . $sale_id, 'Order saved: ' . $invoice_no . ' (credit — collect at delivery), Stock updated.');
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('index.php', 'Error saving order: ' . $e->getMessage(), 'error');
    }
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6 class="mb-0"><i class="fas fa-clipboard-check"></i> Take Order
      <?php if ($view_area): ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary ml-2"><i class="fas fa-undo"></i> All Areas</a>
      <?php endif; ?>
    </h6>
    <div class="d-flex flex-wrap">
      <?php if (isAdmin() || isSalesTeam()): ?>
      <a href="invoices.php" class="btn btn-sm btn-outline-primary mr-2"><i class="fas fa-file-invoice"></i> Invoices</a>
      <?php endif; ?>
      <?php if ($view_area): ?>
      <a href="../customers/customers.php?add=1<?= '&area=' . urlencode($view_area) ?>&return_to=take_order" class="btn btn-sm btn-outline-success"><i class="fas fa-user-plus"></i> Add Customer</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body">

    <?php if (!isAdmin() && $my_areas !== null && empty($my_areas)): ?>
      <div class="alert alert-danger mb-0">
        <i class="fas fa-exclamation-triangle"></i> No areas are assigned to your login yet.
        <strong>Area assignment:</strong> the admin must add you as an Order Booker employee and tick your 3-8 areas
        (Admin → Employees → Add/Edit Employee → Assigned Areas).
      </div>
    <?php elseif (empty($allowed_areas)): ?>
      <div class="alert alert-warning mb-0">
        <i class="fas fa-info-circle"></i> No areas are registered yet.
        <strong>Admin:</strong> add areas under <strong>Areas</strong>, then assign them to order bookers and salesmen in <strong>Employees</strong>.
      </div>
    <?php elseif (!$view_area): ?>

      <!-- STEP 1: choose your area -->
      <div class="row mb-3 align-items-center">
        <div class="col-md-5 col-sm-6 mb-2 mb-sm-0">
          <div class="input-group">
            <div class="input-group-prepend">
              <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
            </div>
            <input type="text" id="areaFilterInput" class="form-control" placeholder="Search area or salesman..." autocomplete="off">
          </div>
        </div>
        <div class="col-md-7 col-sm-6 text-sm-right text-muted small">
          <i class="fas fa-map-marker-alt text-primary mr-1"></i> <span id="areaCount"><?=count($area_cards)?></span> area<?= count($area_cards) == 1 ? '' : 's' ?> available
        </div>
      </div>

      <div class="row" id="areaCardsContainer">
        <?php foreach ($area_cards as $ac): ?>
        <div class="col-md-4 col-sm-6 mb-3 area-col" data-area="<?=htmlspecialchars(strtolower($ac['name']))?>">
          <a href="index.php?area=<?=urlencode($ac['name'])?>" class="area-card d-block h-100 text-decoration-none">
            <div class="area-card-icon"><i class="fas fa-map-marked-alt"></i></div>
            <div class="area-card-name"><?=htmlspecialchars($ac['name'])?></div>
            <div class="area-card-meta"><?=$ac['shops']?> customer<?= $ac['shops'] == 1 ? '' : 's' ?></div>
            <?php if ($ac['salesmen']): ?>
            <div class="area-card-sales">
              <?php foreach ($ac['salesmen'] as $sm): ?>
              <span class="badge badge-light border text-dark mr-1"><i class="fas fa-truck-loading"></i> <?=htmlspecialchars($sm['full_name'])?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </a>
        </div>
        <?php endforeach; ?>
      </div>

      <div id="noAreaFound" class="alert alert-light border text-center py-4 d-none">
        <i class="fas fa-search text-muted fa-2x mb-2 d-block"></i>
        <span class="text-muted">No areas match your search.</span>
      </div>

    <?php else: ?>

      <!-- STEP 2: customers in the selected area -->
      <div class="mb-3">
        <span class="badge badge-primary badge-lg"><i class="fas fa-map-marker-alt"></i> <?=htmlspecialchars($view_area)?></span>
        <span class="badge badge-light border text-dark"><?=count($customers)?> customer<?= count($customers) == 1 ? '' : 's' ?></span>
        <?php foreach ($page_area_salesmen as $sm): ?>
        <span class="badge badge-light border text-dark"><i class="fas fa-truck-loading"></i> Salesman: <?=htmlspecialchars($sm['full_name'])?></span>
        <?php endforeach; ?>
      </div>

      <div class="table-responsive">
        <table class="table table-bordered table-hover" id="shopTable">
          <thead>
            <tr><th>#</th><th>Customer Name</th><th class="d-none d-sm-table-cell">Phone</th><th class="text-right">Balance</th><th class="text-center">Action</th></tr>
          </thead>
          <tbody>
            <?php if (empty($customers)): ?>
              <tr><td colspan="5" class="text-center text-muted py-4">No customers in this area yet.
                <a href="../customers/customers.php?add=1<?= '&area=' . urlencode($view_area) ?>&return_to=take_order" class="btn btn-sm btn-outline-success ml-2"><i class="fas fa-user-plus"></i> Add Customer</a></td></tr>
            <?php else: $i = 0; foreach ($customers as $ct): $i++; ?>
              <tr>
                <td><?=$i?></td>
                <td class="font-weight-bold"><?=htmlspecialchars($ct['full_name'])?>
                  <small class="text-muted d-block d-sm-none"><?=htmlspecialchars($ct['phone'] ?? '')?></small>
                </td>
                <td class="d-none d-sm-table-cell"><?=htmlspecialchars($ct['phone'] ?? '-')?></td>
                <td class="text-right <?= (float)$ct['current_balance'] > 0 ? 'text-danger font-weight-bold' : 'text-muted' ?>">
                  PKR <?=formatCurrency($ct['current_balance'])?>
                </td>
                <td class="text-center">
                  <button type="button" class="btn btn-sm btn-success btn-take-order"
                    data-cust-id="<?=$ct['id']?>"
                    data-cust-name="<?=htmlspecialchars($ct['full_name'])?>"
                    data-cust-phone="<?=htmlspecialchars($ct['phone'] ?? '')?>"
                    data-cust-balance="<?=(float)$ct['current_balance']?>">
                    <i class="fas fa-plus-circle"></i> Order
                  </button>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <?php if (!empty($customers)): ?>
      <input type="text" id="shopSearch" class="form-control form-control-sm d-print-none" placeholder="Search shop in this area..." style="max-width:280px;">
      <?php endif; ?>

    <?php endif; ?>

  </div>
</div>

<!-- ===== TAKE ORDER MODAL (per shop) ===== -->
<div class="modal fade" id="orderModal" tabindex="-1" role="dialog" aria-labelledby="orderModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <form method="post" id="orderForm" novalidate>
        <input type="hidden" name="customer_id" id="orderCustomerId">
        <div class="modal-header">
          <h5 class="modal-title" id="orderModalLabel"><i class="fas fa-clipboard-check text-success"></i> New Order</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info py-2 mb-3">
            <div class="font-weight-bold" id="orderCustName">-</div>
            <small class="text-muted" id="orderCustMeta"></small>
          </div>

          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">Order Date *</label>
              <input type="date" name="sale_date" class="form-control datepicker" value="<?=date('Y-m-d')?>" required>
            </div>
            <div class="col-md-8 mb-3">
              <label class="form-label">Salesman (Deliver) <small class="text-muted">optional</small></label>
              <select name="salesman_id" class="form-control">
                <option value="">-- Select salesman for this area --</option>
                <?php if ($view_area): foreach ($page_area_salesmen as $sm): ?>
                <option value="<?=$sm['id']?>"><?=htmlspecialchars($sm['full_name'])?></option>
                <?php endforeach; endif; ?>
              </select>
              <?php if (empty($page_area_salesmen)): ?>
              <small class="text-muted">No salesman is assigned to this area yet — please pick "Not assigned" or update employee areas.</small>
              <?php else: ?>
              <small class="text-muted">Only salesmen assigned to this area are listed.</small>
              <?php endif; ?>
            </div>
          </div>

          <h6 class="mb-2 text-secondary"><i class="fas fa-box"></i> Products <small class="text-muted">(prices auto-fill — boxes)</small></h6>
          <div id="productRows">
            <div class="product-row">
              <div class="row g-2">
                <div class="col-md-5">
                  <label class="form-label">Product</label>
                  <div class="ac-wrap">
                    <input type="text" class="form-control product-search" placeholder="Type product name or code..." autocomplete="off">
                    <input type="hidden" name="product_id[]" class="product-id">
                    <div class="ac-list"></div>
                  </div>
                </div>
                <div class="col-md-2">
                  <label class="form-label">Qty (Boxes)</label>
                  <input type="number" name="quantity[]" class="form-control qty" min="0" placeholder="0">
                </div>
                <div class="col-md-2">
                  <label class="form-label">Rate</label>
                  <input type="number" name="rate[]" class="form-control rate" step="0.01" min="0">
                </div>
                <div class="col-md-2">
                  <label class="form-label">Subtotal</label>
                  <input type="text" class="form-control subtotal" readonly value="0.00">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                  <button type="button" class="btn btn-outline-danger remove-row"><i class="fas fa-times"></i></button>
                </div>
              </div>
            </div>
          </div>

          <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="addRow"><i class="fas fa-plus"></i> Add Another Product</button>

          <div class="row">
            <div class="col-md-4">
              <label class="form-label">Total Amount</label>
              <input type="text" class="form-control font-weight-bold" id="totalAmount" value="0.00" readonly>
            </div>
            <div class="col-md-4">
              <label class="form-label">Discount</label>
              <input type="number" name="discount_amount" id="discountAmount" class="form-control" min="0" placeholder="0">
            </div>
            <div class="col-md-4">
              <label class="form-label">Due (Credit)</label>
              <input type="text" class="form-control font-weight-bold text-danger" id="dueAmount" value="0.00" readonly>
            </div>
          </div>
          <small class="text-muted d-block mt-1">Payment method is always CREDIT — the amount is collected by the salesman at delivery.</small>

          <div class="row mt-3">
            <div class="col-md-12">
              <label class="form-label">Notes</label>
              <input type="text" name="notes" class="form-control" placeholder="Optional notes (e.g. shop timing, employee name)">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Save Order</button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
.area-card { border: 1px solid #dbe1ea; border-radius: 10px; padding: 18px 16px; transition: all .15s ease; background: #fff; }
.area-card:hover { border-color: var(--primary); box-shadow: 0 4px 14px rgba(13,110,253,.12); transform: translateY(-2px); }
.area-card-icon { width: 44px; height: 44px; border-radius: 12px; background: rgba(13,110,253,.1); color: var(--primary); display: flex; align-items: center; justify-content: center; margin-bottom: 10px; }
.area-card-name { font-weight: 700; font-size: 1.05rem; color: #0f172a; }
.area-card-meta { color: #64748b; font-size: .85rem; margin-bottom: 6px; }
.badge-lg { font-size: 1rem; padding: .5em .8em; }
</style>

<script>
$(document).ready(function(){

  // ===== TAKE ORDER MODAL OPEN =====
  $('.btn-take-order').click(function(){
    var $b = $(this);
    $('#orderCustomerId').val($b.data('cust-id'));
    $('#orderCustName').text($b.data('cust-name'));
    var meta = [];
    if ($b.data('cust-phone')) meta.push('Phone: ' + $b.data('cust-phone'));
    var bal = Number($b.data('cust-balance'));
    meta.push(bal > 0 ? 'Balance due: PKR ' + bal.toLocaleString(undefined, {minimumFractionDigits:2}) : 'Balance settled');
    $('#orderCustMeta').text(meta.join(' · '));
    // reset products to a single clean row
    $('#productRows .product-row').slice(1).remove();
    var first = $('#productRows .product-row').first();
    first.find('.product-search').val(''); first.find('.product-id').val('');
    first.find('.ac-list').empty().hide(); first.find('.qty,.rate').val(''); first.find('.subtotal').val('0.00');
    $('#discountAmount').val(''); $('#totalAmount').val('0.00'); $('#dueAmount').val('0.00');
    $('#orderModal').modal('show');
  });

  <?php if ($view_area): ?>
  // Pre-select first covering salesman for convenience
  var areaSalesmen = <?=json_encode(array_map(fn($sm) => ['id' => (int)$sm['id'], 'name' => $sm['full_name']], $page_area_salesmen))?>;
  if (areaSalesmen.length === 1) {
    $('select[name="salesman_id"]').val(areaSalesmen[0].id);
  }
  <?php endif; ?>

  // ===== CALC =====
  function recalc(){
    var total = 0;
    $('#productRows .product-row').each(function(){
      var rate = parseFloat($(this).find('.rate').val()) || 0;
      var qty = parseFloat($(this).find('.qty').val()) || 0;
      var sub = rate * qty;
      $(this).find('.subtotal').val(sub.toFixed(2));
      total += sub;
    });
    $('#totalAmount').val(total.toFixed(2));
    var disc = parseFloat($('#discountAmount').val()) || 0;
    var net = Math.max(total - disc, 0);
    $('#dueAmount').val(net.toFixed(2));
  }

  $('#productRows').on('input', '.qty, .rate', recalc);
  $('#discountAmount').on('input', recalc);

  $('#addRow').click(function(){
    var first = $('#productRows .product-row').first().clone();
    first.find('.product-search').val('');
    first.find('.product-id').val('');
    first.find('.ac-list').empty().hide();
    first.find('.qty, .rate').val('');
    first.find('.subtotal').val('0.00');
    $('#productRows').append(first);
    recalc();
  });

  $('#productRows').on('click', '.remove-row', function(){
    if ($('#productRows .product-row').length > 1) {
      $(this).closest('.product-row').remove();
      recalc();
    } else {
      alert('At least one product row is required.');
    }
  });

  // ===== PRODUCT AUTOCOMPLETE (per row) =====
  function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }
  function hideList($list){ $list.empty().hide(); }

  $('#productRows').on('input', '.product-search', function(){
    var $row = $(this).closest('.product-row');
    var $list = $row.find('.ac-list');
    var q = $.trim(this.value);
    clearTimeout($(this).data('timer'));
    if (!q) {
      $row.find('.product-id').val('');
      hideList($list);
      return;
    }
    $(this).data('timer', setTimeout(function(){
      $.get('ajax_product_search.php', {q: q}, function(data){
        $list.empty();
        if (!data || !data.length) {
          $list.append('<div class="ac-item ac-empty">No matching product found</div>');
        } else {
          $.each(data, function(i, it){
            $list.append('<div class="ac-item" data-id="' + it.id + '" data-sale="' + it.sale_price + '" data-bpc="' + it.boxes_per_carton + '">' +
              '<span class="ac-name">' + esc(it.name) + '</span>' +
              '<small class="ac-sub">Stock: ' + it.stock_quantity + ' ' + esc(it.unit || '') + '</small>' +
              '</div>');
          });
        }
        $list.show();
      });
    }, 250));
  });

  function pickProduct($item){
    var $row = $item.closest('.product-row');
    var bpc = parseInt($item.data('bpc')) || 1;
    if (bpc < 1) bpc = 1;
    var sale = parseFloat($item.data('sale')) || 0;
    $row.find('.product-id').val($item.data('id'));
    $row.find('.product-search').val($item.find('.ac-name').text());
    $row.find('.rate').val((sale / bpc).toFixed(2));
    hideList($row.find('.ac-list'));
    recalc();
  }

  $('#productRows').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    pickProduct($(this));
  });

  $(document).on('keydown', '.product-search', function(e){
    var $list = $(this).closest('.ac-wrap').find('.ac-list');
    var items = $list.find('.ac-item:not(.ac-empty)');
    if (!$list.is(':visible') || !items.length) return;
    var idx = items.index(items.filter('.active'));
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      var dir = e.key === 'ArrowDown' ? 1 : -1;
      idx = (idx + dir + items.length) % items.length;
      items.removeClass('active').eq(idx).addClass('active');
    } else if (e.key === 'Enter') {
      e.preventDefault();
      var target = idx >= 0 ? items.eq(idx) : items.first();
      if (target.length) target.trigger('mousedown');
    } else if (e.key === 'Escape') {
      hideList($list);
    }
  });

  $(document).on('mouseover', '.ac-item', function(){
    $(this).addClass('active').siblings().removeClass('active');
  });

  $(document).on('mousedown', function(e){
    if (!$(e.target).closest('.ac-wrap').length) {
      $('.ac-list').empty().hide();
    }
  });

  // ===== SUBMIT GUARDS =====
  $('#orderForm').on('submit', function(e){
    if (!$('#orderCustomerId').val()) {
      e.preventDefault();
      alert('Please select a shop first.');
      return;
    }
    var filled = false;
    $('#productRows .product-row').each(function(){
      if ($(this).find('.product-id').val()) filled = true;
    });
    if (!filled) {
      e.preventDefault();
      alert('Please add at least one product.');
    }
  });

  // ===== AREA CARDS SEARCH =====
  $('#areaFilterInput').on('keyup input', function(){
    var q = $(this).val().toLowerCase().trim();
    var visible = 0;
    $('.area-col').each(function(){
      var text = $(this).text().toLowerCase();
      if (q === '' || text.indexOf(q) > -1) {
        $(this).show();
        visible++;
      } else {
        $(this).hide();
      }
    });
    $('#areaCount').text(visible);
    if (visible === 0) {
      $('#noAreaFound').removeClass('d-none');
    } else {
      $('#noAreaFound').addClass('d-none');
    }
  });

  // ===== SHOP SEARCH =====
  $('#shopSearch').on('keyup', function(){
    var q = $(this).val().toLowerCase();
    $('#shopTable tbody tr').each(function(){
      $(this).toggle($(this).text().toLowerCase().indexOf(q) > -1);
    });
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>