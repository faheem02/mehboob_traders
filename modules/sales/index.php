<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'New Sale';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

$products = $pdo->query("SELECT id, code, name, unit, boxes_per_carton, sale_price, purchase_price, stock_quantity FROM products WHERE status = 1 ORDER BY name")->fetchAll();
$bank_accounts = $pdo->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = $_POST['customer_id'] ?: null;
    $salesman_id = $_POST['salesman_id'] ?: null;
    $sale_date = $_POST['sale_date'] ?: date('Y-m-d');
    $payment_method = $_POST['payment_method'] ?: 'credit';
    $bank_account_id = $payment_method == 'bank' ? ($_POST['bank_account_id'] ?: null) : null;
    $notes = trim($_POST['notes'] ?? '');

    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $rates = $_POST['rate'] ?? [];

    if (!$customer_id) {
        redirect('index.php', 'Select a customer', 'error');
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

    if (count($stock_errors)) {
        redirect('index.php', 'Insufficient stock: ' . implode(', ', $stock_errors), 'error');
    }
    if (!count($items)) {
        redirect('index.php', 'Add at least one product with quantity', 'error');
    }

    $paid_amount = (float)($_POST['paid_amount'] ?? 0);
    $discount = (float)($_POST['discount_amount'] ?? 0);
    if ($discount > $total) $discount = $total;
    $net_total = $total - $discount;
    if ($paid_amount > $net_total) $paid_amount = $net_total;
    $due_amount = $net_total - $paid_amount;

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
            'paid_amount' => $paid_amount,
            'due_amount' => $due_amount,
            'payment_method' => $payment_method,
            'bank_account_id' => $bank_account_id,
            'status' => $due_amount > 0 ? 'active' : 'completed',
            'notes' => $notes,
            'branch_id' => $_SESSION['branch_id'] ?? null,
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
            // Reduce stock
            $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?")
                ->execute([$it['qty'], $it['product_id']]);
        }

        if ($paid_amount > 0) {
            $desc = "Sale #{$invoice_no}";
            if ($payment_method == 'bank') {
                recordBankInflow($pdo, $sale_date, $paid_amount, $desc, 'sale', $sale_id, $_SESSION['user_id'], $bank_account_id);
            } else {
                recordCashInflow($pdo, $sale_date, $paid_amount, $desc, 'sale', $sale_id, $_SESSION['user_id']);
            }
        }

        updateCustomerBalance($pdo, $customer_id);

        $pdo->commit();
        logActivity($pdo, 'create', 'sale', $sale_id, 'Created sale ' . $invoice_no . ' total ' . $net_total);
        redirect('invoice.php?id=' . $sale_id, 'Sale saved: ' . $invoice_no . ', Stock updated.');
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('index.php', 'Error saving sale: ' . $e->getMessage(), 'error');
    }
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h6><i class="fas fa-shopping-cart"></i> New Sale</h6>
    <a href="invoices.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-file-invoice"></i> Invoices</a>
  </div>
  <div class="card-body">
    <form method="post" id="saleForm" novalidate>

      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">Customer *</label>
          <div class="ac-wrap" id="customerWrap">
            <input type="text" id="customerSearch" class="form-control" placeholder="Type customer name / phone to search..." autocomplete="off" required>
            <input type="hidden" name="customer_id" id="customer_id">
            <div class="ac-list" id="customerList"></div>
          </div>
          <small class="text-danger d-none" id="customerError"><i class="fas fa-exclamation-circle"></i> Please select a customer from the suggestions.</small>
          <small class="text-muted d-block" id="customerBalance"></small>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Salesman (Deliver By) <small class="text-muted">optional</small></label>
          <div class="ac-wrap" id="salesmanWrap">
            <input type="text" id="salesmanSearch" class="form-control" placeholder="Type salesman name to search..." autocomplete="off">
            <input type="hidden" name="salesman_id" id="salesman_id">
            <div class="ac-list" id="salesmanList"></div>
          </div>
          <small class="text-muted">The salesman who will deliver this order.</small>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Sale Date *</label>
          <input type="date" name="sale_date" class="form-control datepicker" value="<?=date('Y-m-d')?>" required>
        </div>
      </div>

      <div class="row">
        <div class="col-md-3 mb-3">
          <label class="form-label">Payment Method</label>
          <select name="payment_method" id="payMethod" class="form-control">
            <option value="credit" selected>Credit</option>
            <option value="cash">Cash</option>
            <option value="bank">Bank</option>
          </select>
          <small class="text-muted" id="payMethodHint">No amount field for credit — full amount goes to due.</small>
        </div>
        <div class="col-md-3 mb-3" id="bankDiv" style="display:none;">
          <label class="form-label">Bank Account</label>
          <select name="bank_account_id" class="form-control">
            <?php foreach ($bank_accounts as $ba): ?>
            <option value="<?=$ba['id']?>"><?=htmlspecialchars($ba['account_name'])?> - <?=htmlspecialchars($ba['bank_name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <hr>
      <h6 class="mb-3 text-secondary"><i class="fas fa-box"></i> Products</h6>

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

      <hr>

      <div class="row">
        <div class="col-md-3">
          <label class="form-label">Total Amount</label>
          <input type="text" class="form-control font-weight-bold" id="totalAmount" value="0.00" readonly>
        </div>
        <div class="col-md-3">
          <label class="form-label">Discount</label>
          <input type="number" name="discount_amount" id="discountAmount" class="form-control" min="0" placeholder="0">
        </div>
        <div class="col-md-3" id="paidDiv">
          <label class="form-label">Paid Amount</label>
          <input type="number" name="paid_amount" id="paidAmount" class="form-control" min="0" placeholder="0">
        </div>
        <div class="col-md-3">
          <label class="form-label">Due Amount</label>
          <input type="text" class="form-control font-weight-bold text-danger" id="dueAmount" value="0.00" readonly>
        </div>
      </div>

      <div class="row mt-3">
        <div class="col-md-8">
          <label class="form-label">Notes</label>
          <input type="text" name="notes" class="form-control" placeholder="Optional notes">
        </div>
        <div class="col-md-4 d-flex align-items-end">
          <button type="submit" class="btn btn-success btn-block py-2"><i class="fas fa-save"></i> Save Sale</button>
        </div>
      </div>

    </form>
  </div>
</div>

<script>
$(document).ready(function(){
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
    var paid = parseFloat($('#paidAmount').val()) || 0;
    if (paid > net) $('#paidAmount').val(net);
    $('#dueAmount').val(Math.max(net - paid, 0).toFixed(2));
  }

  $('#productRows').on('input', '.qty, .rate', recalc);
  $('#discountAmount, #paidAmount').on('input', recalc);

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

  $('#payMethod').change(function(){
    var v = $(this).val();
    $('#bankDiv').toggle(v === 'bank');
    $('#paidDiv').toggle(v !== 'credit');
    $('#payMethodHint').toggle(v === 'credit');
    if (v === 'credit') $('#paidAmount').val(0);
    recalc();
  });
  $('#payMethod').trigger('change');

  // ===== AUTOCOMPLETE HELPERS =====
  function esc(s){
    return String(s == null ? '' : s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')
      .replace(/'/g,'&#39;');
  }
  function hideList($list){ $list.empty().hide(); }

  // ===== CUSTOMER SEARCH =====
  var custTimer = null;
  $('#customerSearch').on('input', function(){
    var q = $.trim(this.value);
    clearTimeout(custTimer);
    if (!q) {
      $('#customer_id').val('');
      $('#customerBalance').text('');
      $('#customerError').addClass('d-none');
      hideList($('#customerList'));
      return;
    }
    custTimer = setTimeout(function(){
      $.get('ajax_customer_search.php', {q: q}, function(data){
        var $list = $('#customerList');
        $list.empty();
        if (!data || !data.length) {
          $list.append('<div class="ac-item ac-empty">No matching customer found</div>');
        } else {
          $.each(data, function(i, it){
            var sub = [];
            if (it.phone) sub.push('Phone: ' + esc(it.phone));
            if (it.city) sub.push(esc(it.city));
            $list.append('<div class="ac-item" data-id="' + it.id + '">' +
              '<span class="ac-name">' + esc(it.full_name) + '</span>' +
              (sub.length ? '<small class="ac-sub">' + sub.join(' &middot; ') + '</small>' : '') +
              '</div>');
          });
        }
        $list.show();
      });
    }, 250);
  });

  function pickCustomer($item){
    var id = $item.data('id');
    $('#customer_id').val(id);
    $('#customerSearch').val($item.find('.ac-name').text());
    $('#customerError').addClass('d-none');
    hideList($('#customerList'));
    $.get('ajax_customer_balance.php', {id: id}, function(data){
      $('#customerBalance').text('Current balance: ' + data);
    });
  }

  $('#customerList').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    pickCustomer($(this));
  });

  // ===== PRODUCT SEARCH (per row) =====
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
            var sub = [];
            if (it.code) sub.push('Code: ' + esc(it.code));
            if (it.unit) sub.push('Unit: ' + esc(it.unit));
            $list.append('<div class="ac-item" data-id="' + it.id + '" data-sale="' + it.sale_price + '" data-bpc="' + it.boxes_per_carton + '">' +
              '<span class="ac-name">' + esc(it.name) + '</span>' +
              '<small class="ac-sub">' + sub.join(' &middot; ') + '</small>' +
              '<small class="ac-sub"><i class="fas fa-boxes"></i> In stock: ' + it.stock_quantity + '</small>' +
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

  // ===== SALESMAN SEARCH =====
  var salesTimer = null;
  $('#salesmanSearch').on('input', function(){
    var q = $.trim(this.value);
    clearTimeout(salesTimer);
    if (!q) {
      $('#salesman_id').val('');
      hideList($('#salesmanList'));
      return;
    }
    salesTimer = setTimeout(function(){
      $.get('ajax_salesman_search.php', {q: q}, function(data){
        var $list = $('#salesmanList');
        $list.empty();
        if (!data || !data.length) {
          $list.append('<div class="ac-item ac-empty">No matching salesman found</div>');
        } else {
          $.each(data, function(i, it){
            var sub = [];
            if (it.area) sub.push('Area: ' + esc(it.area));
            if (it.phone) sub.push('Phone: ' + esc(it.phone));
            $list.append('<div class="ac-item" data-id="' + it.id + '">' +
              '<span class="ac-name">' + esc(it.full_name) + '</span>' +
              (sub.length ? '<small class="ac-sub">' + sub.join(' &middot; ') + '</small>' : '') +
              '</div>');
          });
        }
        $list.show();
      });
    }, 250);
  });

  function pickSalesman($item){
    var id = $item.data('id');
    $('#salesman_id').val(id);
    $('#salesmanSearch').val($item.find('.ac-name').text());
    hideList($('#salesmanList'));
  }

  $('#salesmanList').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    pickSalesman($(this));
  });

  // ===== KEYBOARD NAVIGATION =====
  $(document).on('keydown', '#customerSearch, .product-search', function(e){
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

  $(document).on('keydown', '#salesmanSearch', function(e){
    var $list = $('#salesmanList');
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
  $('#saleForm').on('submit', function(e){
    if (!$('#customer_id').val()) {
      e.preventDefault();
      $('#customerError').removeClass('d-none');
      $('#customerSearch').focus();
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
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>