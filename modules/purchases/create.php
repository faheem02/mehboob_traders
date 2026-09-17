<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'New Purchase';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$products = $pdo->query("SELECT id, code, name, unit, boxes_per_carton, purchase_price, stock_quantity, sale_price FROM products WHERE status = 1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_id = $_POST['supplier_id'] ?: null;
    $purchase_date = $_POST['purchase_date'] ?: date('Y-m-d');
    $payment_method = $_POST['payment_method'] ?: 'cash';
    $bank_account_id = (int)($_POST['bank_account_id'] ?? 0) ?: null;
    $notes = trim($_POST['notes'] ?? '');

    $product_ids = (array)($_POST['product_id'] ?? []);
    $cartons = (array)($_POST['cartons'] ?? []);
    $boxes_per_carton = (array)($_POST['boxes_per_carton'] ?? []);
    $loose_boxes = (array)($_POST['loose_boxes'] ?? []);
    $rates = (array)($_POST['rate'] ?? []);

    if (!count($product_ids) || !$product_ids[0]) {
        redirect('create.php', 'Add at least one product', 'error');
    }

    // Rate entered by user is PER CARTON. Stock quantity is always in boxes.
    // Effective per-box price = rate / bpc. Subtotal = cartons*rate + loose*(rate/bpc).
    $total_boxes = 0;
    $total = 0;
    $items = [];
    foreach ($product_ids as $i => $pid) {
        if (!$pid) continue;
        $bpc = (int)($boxes_per_carton[$i] ?? 1);
        if ($bpc < 1) $bpc = 1;
        $ctn = (int)($cartons[$i] ?? 0);
        $loose = (int)($loose_boxes[$i] ?? 0);
        $qty = ($ctn * $bpc) + $loose;
        $rate_ctn = (float)($rates[$i] ?? 0);
        if ($qty <= 0) continue;
        $per_box = $rate_ctn / $bpc;
        $subtotal = ($ctn * $rate_ctn) + ($loose * $per_box);
        $total += $subtotal;
        $total_boxes += $qty;
        $items[] = ['product_id' => (int)$pid, 'ctn' => $ctn, 'bpc' => $bpc, 'loose' => $loose, 'qty' => $qty, 'rate_ctn' => $rate_ctn, 'per_box' => $per_box, 'subtotal' => $subtotal];
    }

    if (!count($items)) {
        redirect('create.php', 'Add at least one product with quantity', 'error');
    }

    $paid_amount = (float)($_POST['paid_amount'] ?? 0);
    $discount = (float)($_POST['discount_amount'] ?? 0);
    if ($discount > $total) $discount = $total;
    $net_total = $total - $discount;
    if ($paid_amount > $net_total) $paid_amount = $net_total;
    $due_amount = $net_total - $paid_amount;

    $invoice_no = trim($_POST['invoice_no'] ?? '');
    if ($invoice_no !== '') {
        $chk = $pdo->prepare("SELECT id FROM purchases WHERE invoice_no = ?");
        $chk->execute([$invoice_no]);
        if ($chk->fetch()) $invoice_no = '';
    }
    if ($invoice_no === '') $invoice_no = generatePurchaseNo();

    $pdo->beginTransaction();
    try {
        $purchase_id = insert('purchases', [
            'supplier_id' => $supplier_id,
            'invoice_no' => $invoice_no,
            'purchase_date' => $purchase_date,
            'total_amount' => $net_total,
            'discount_amount' => $discount,
            'paid_amount' => $paid_amount,
            'due_amount' => $due_amount,
            'payment_method' => $payment_method,
            'bank_account_id' => $payment_method == 'bank' ? $bank_account_id : null,
            'status' => 'received',
            'notes' => $notes,
            'created_by' => $_SESSION['user_id'],
            'created_at' => date('Y-m-d'),
        ]);

        foreach ($items as $it) {
            insert('purchase_items', [
                'purchase_id' => $purchase_id,
                'product_id' => $it['product_id'],
                'cartons' => $it['ctn'],
                'boxes_per_carton' => $it['bpc'],
                'loose_boxes' => $it['loose'],
                'quantity' => $it['qty'],
                'purchase_price' => round($it['per_box'], 2),
                'subtotal' => round($it['subtotal'], 2),
            ]);
            // Update stock (boxes) + last purchase price (per carton)
            $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ?, purchase_price = ?, boxes_per_carton = ? WHERE id = ?")
                ->execute([$it['qty'], round($it['rate_ctn'], 2), $it['bpc'], $it['product_id']]);
        }

        // Payment handling
        if ($paid_amount > 0) {
            $desc = "Purchase #{$invoice_no}";
            if ($payment_method == 'bank') {
                recordBankOutflow($pdo, $purchase_date, $paid_amount, $desc, 'purchase', $purchase_id, $_SESSION['user_id'], $bank_account_id);
            } else {
                recordCashOutflow($pdo, $purchase_date, $paid_amount, $desc, 'purchase', $purchase_id, $_SESSION['user_id']);
            }
        }

        if ($supplier_id) {
            syncSupplierPurchasePayments($pdo, $supplier_id);
            updateSupplierBalance($pdo, $supplier_id);
        }

        $pdo->commit();
        logActivity($pdo, 'create', 'purchase', $purchase_id, 'Created purchase ' . $invoice_no . ' total ' . $net_total . ' (' . $total_boxes . ' boxes)');
        redirect('index.php', 'Purchase saved: ' . $invoice_no . ', Stock updated (' . $total_boxes . ' boxes).');
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('create.php', 'Error saving purchase: ' . $e->getMessage(), 'error');
    }
}

$bank_accounts = $pdo->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id")->fetchAll();
$next_purchase_no = generatePurchaseNo();
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6><i class="fas fa-cart-arrow-down"></i> New Purchase (Carton / Box System)</h6>
    <a href="index.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-list"></i> Purchase List</a>
  </div>
  <div class="card-body">
    <form method="post" id="purchaseForm">
      <input type="hidden" name="invoice_no" value="<?=htmlspecialchars($next_purchase_no)?>">

      <!-- Purchase Info -->
      <div class="row">
        <div class="col-md-3 mb-3">
          <label class="form-label">Purchase No (Auto) *</label>
          <input type="text" class="form-control font-weight-bold text-success bg-light" value="<?=htmlspecialchars($next_purchase_no)?>" readonly>
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Supplier *</label>
          <div class="ac-wrap" id="supplierWrap">
            <input type="text" id="supplierSearch" class="form-control" placeholder="Type supplier name to search..." autocomplete="off">
            <input type="hidden" name="supplier_id" id="supplier_id">
            <div class="ac-list" id="supplierList"></div>
          </div>
          <small class="text-danger d-none" id="supplierError"><i class="fas fa-exclamation-circle"></i> Please select a supplier from the suggestions.</small>
          <small class="text-muted" id="supplierBalance"></small>
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Purchase Date *</label>
          <input type="date" name="purchase_date" class="form-control datepicker" value="<?=date('Y-m-d')?>" required>
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Payment Method</label>
          <select name="payment_method" id="payMethod" class="form-control">
            <option value="cash">Cash</option>
            <option value="bank">Bank</option>
          </select>
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
      <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
        <h6 class="mb-0 text-secondary"><i class="fas fa-boxes"></i> Products (Carton / Box Entry)</h6>
        <small class="text-muted">Total boxes are calculated automatically</small>
      </div>
      

      <!-- Product rows -->
      <div id="productRows">
        <div class="product-row">
          <div class="row g-2">
            <div class="col-md-4 col-lg-3">
              <label class="form-label">Product</label>
              <div class="ac-wrap">
                <input type="text" class="form-control product-search" placeholder="Type product name or code..." autocomplete="off">
                <input type="hidden" name="product_id[]" class="product-id">
                <div class="ac-list"></div>
              </div>
            </div>
            <div class="col-6 col-md-2 col-lg-2">
              <label class="form-label">Boxes in 1 Carton</label>
              <input type="number" name="boxes_per_carton[]" class="form-control bpc bg-light" min="1" value="1" readonly title="Sets automatically when you pick a product">
              <small class="text-muted">auto from product</small>
            </div>
            <div class="col-6 col-md-2 col-lg-2">
              <label class="form-label">Cartons</label>
              <input type="number" name="cartons[]" class="form-control cartons" min="0" value="0" placeholder="e.g. 10">
            </div>
            <div class="col-6 col-md-2 col-lg-2">
              <label class="form-label">Loose Boxes</label>
              <input type="number" name="loose_boxes[]" class="form-control loose" min="0" value="0" placeholder="e.g. 5">
            </div>
            <div class="col-6 col-md-2 col-lg-2">
              <label class="form-label">Total Boxes</label>
              <input type="text" class="form-control total-boxes font-weight-bold bg-light" readonly value="0">
            </div>
            <div class="col-6 col-md-2 col-lg-1 d-flex align-items-end">
              <button type="button" class="btn btn-outline-danger remove-row mt-auto w-100" title="Remove"><i class="fas fa-trash-alt"></i></button>
            </div>
          </div>
          <div class="row g-2 mt-1 align-items-end">
            <div class="col-6 col-md-3 col-lg-3">
              <label class="form-label">Rate / Carton</label>
              <input type="number" name="rate[]" class="form-control rate" step="0.01" min="0">
              <small class="text-muted eff-rate"></small>
            </div>
            <div class="col-6 col-md-3 col-lg-3">
              <label class="form-label">Subtotal</label>
              <input type="text" class="form-control subtotal font-weight-bold" readonly value="0.00">
            </div>
            <div class="col-6 col-md-3 col-lg-3 carton-total-col">
              <label class="form-label">In Cartons</label>
              <input type="text" class="form-control carton-total bg-light" readonly value="0 Cartons + 0 Boxes">
            </div>
            <div class="col-6 col-md-3 col-lg-3"></div>
          </div>
        </div>
      </div>

      <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="addRow"><i class="fas fa-plus"></i> Add Another Product</button>

      <!-- Grand quantity summary -->
      <div class="alert alert-info py-2 mb-3" id="qtySummary">
        <i class="fas fa-calculator"></i> <strong>Total Quantity:</strong>
        <span id="sumBoxes">0 Boxes</span> &nbsp;|&nbsp; <span id="sumCartons">0 Cartons + 0 Boxes</span>
      </div>

      <hr>

      <!-- Totals -->
      <div class="row">
        <div class="col-md-3">
          <label class="form-label">Total Amount</label>
          <input type="text" class="form-control font-weight-bold" id="totalAmount" value="0.00" readonly>
        </div>
        <div class="col-md-3">
          <label class="form-label">Discount</label>
          <input type="number" name="discount_amount" id="discountAmount" class="form-control" value="0" min="0">
        </div>
        <div class="col-md-3">
          <label class="form-label">Paid Amount</label>
          <input type="number" name="paid_amount" id="paidAmount" class="form-control" value="0" min="0">
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
          <button type="submit" class="btn btn-primary btn-block py-2"><i class="fas fa-save"></i> Save Purchase</button>
        </div>
      </div>

    </form>
  </div>
</div>

<script>
$(document).ready(function(){
  // Carton <-> Box conversion helper
  function cartonText(totalBoxes, bpc) {
    bpc = bpc || 1;
    var ctns = Math.floor(totalBoxes / bpc);
    var rem = totalBoxes % bpc;
    return ctns + ' Carton' + (ctns === 1 ? '' : 's') + ' + ' + rem + ' Box' + (rem === 1 ? '' : 'es');
  }

  function rowTotals(row) {
    var bpc = parseInt($(row).find('.bpc').val()) || 1;
    if (bpc < 1) bpc = 1;
    var ctn = parseInt($(row).find('.cartons').val()) || 0;
    var loose = parseInt($(row).find('.loose').val()) || 0;
    var totalBoxes = (ctn * bpc) + loose;
    $(row).find('.total-boxes').val(totalBoxes);
    var tbText = totalBoxes === 0 ? '0 Cartons + 0 Boxes' : cartonText(totalBoxes, bpc);
    $(row).find('.carton-total').val(tbText);
  }

  function recalc(){
    var total = 0, sumBoxes = 0;
    $('#productRows .product-row').each(function(){
      var ctn = parseInt($(this).find('.cartons').val()) || 0;
      var loose = parseInt($(this).find('.loose').val()) || 0;
      var bpc = parseInt($(this).find('.bpc').val()) || 1;
      if (bpc < 1) bpc = 1;
      var rateCtn = parseFloat($(this).find('.rate').val()) || 0;
      var perBox = bpc > 0 ? rateCtn / bpc : 0;
      var tb = ctn * bpc + loose;
      var sub = (ctn * rateCtn) + (loose * perBox);
      $(this).find('.subtotal').val(sub.toFixed(2));
      $(this).find('.eff-rate').text(perBox > 0 ? '= PKR ' + perBox.toFixed(2) + ' / box' : '');
      total += sub;
      sumBoxes += tb;
    });
    $('#totalAmount').val(total.toFixed(2));
    var disc = parseFloat($('#discountAmount').val()) || 0;
    var net = Math.max(total - disc, 0);
    var paid = parseFloat($('#paidAmount').val()) || 0;
    if (paid > net) $('#paidAmount').val(net);
    $('#dueAmount').val(Math.max(net - paid, 0).toFixed(2));
    $('#sumBoxes').text(sumBoxes + ' Boxes');
    // grand carton total in terms of first row's bpc (fallback 1)
    var gbpc = 1;
    var first = $('#productRows .product-row').first();
    gbpc = parseInt(first.find('.bpc').val()) || 1;
    var grand = sumBoxes === 0 ? '0 Cartons + 0 Boxes' : cartonText(sumBoxes, gbpc);
    $('#sumCartons').text(grand);
  }

  // ===== AUTOCOMPLETE HELPERS =====
  function esc(s){
    return String(s == null ? '' : s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function hideList($list){ $list.empty().hide(); }

  function buildItem(item, type){
    var name = esc(item.name);
    if (type === 'supplier') {
      var sub = [];
      if (item.phone) sub.push('Phone: ' + esc(item.phone));
      if (item.city) sub.push(esc(item.city));
      return $('<div class="ac-item" data-id="' + item.id + '">' +
        '<span class="ac-name">' + name + '</span>' +
        (sub.length ? '<small class="ac-sub">' + sub.join(' &middot; ') + '</small>' : '') +
        '</div>');
    }
    var bpc = parseInt(item.boxes_per_carton) || 1;
    if (bpc < 1) bpc = 1;
    var stock = parseInt(item.stock_quantity) || 0;
    var ctns = Math.floor(stock / bpc);
    var remBoxes = stock % bpc;
    var stockText = stock + ' Boxes';
    if (bpc > 1) {
      stockText += ' (' + ctns + ' Carton' + (ctns === 1 ? '' : 's') + (remBoxes > 0 ? ' + ' + remBoxes + ' Box' + (remBoxes === 1 ? '' : 'es') : '') + ')';
    }
    var psub = [];
    if (item.code) psub.push('Code: ' + esc(item.code));
    if (bpc > 1) psub.push('1 Carton = ' + bpc + ' Boxes');
    return $('<div class="ac-item" data-id="' + item.id + '" data-bpc="' + bpc + '" data-rate="' + item.purchase_price + '" data-stock="' + stock + '">' +
      '<span class="ac-name">' + name + '</span>' +
      (psub.length ? '<small class="ac-sub">' + psub.join(' &middot; ') + '</small>' : '') +
      '<small class="ac-sub text-info font-weight-bold"><i class="fas fa-boxes"></i> In stock: ' + stockText + '</small>' +
      '</div>');
  }

  function renderList($list, items, type){
    $list.empty();
    if (!items || !items.length) {
      $list.append('<div class="ac-item ac-empty">No matching record found</div>');
    } else {
      $.each(items, function(i, it){
        $list.append(buildItem(it, type));
      });
    }
    $list.show();
  }

  // ===== SUPPLIER SEARCH =====
  var supTimer = null;
  $('#supplierSearch').on('input', function(){
    var q = $.trim(this.value);
    clearTimeout(supTimer);
    if (!q) {
      $('#supplier_id').val('');
      $('#supplierBalance').text('');
      $('#supplierError').addClass('d-none');
      hideList($('#supplierList'));
      return;
    }
    supTimer = setTimeout(function(){
      $.get('ajax_search.php', {type: 'supplier', q: q}, function(data){
        renderList($('#supplierList'), data, 'supplier');
      });
    }, 250);
  });

  function pickSupplier($item){
    var id = $item.data('id');
    $('#supplier_id').val(id);
    $('#supplierSearch').val($item.find('.ac-name').text());
    $('#supplierError').addClass('d-none');
    hideList($('#supplierList'));
    $.get('ajax_supplier_balance.php', {id: id}, function(data){
      $('#supplierBalance').text('Current balance: PKR ' + data);
    });
  }

  $('#supplierList').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    pickSupplier($(this));
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
      $.get('ajax_search.php', {type: 'product', q: q}, function(data){
        renderList($list, data, 'product');
      });
    }, 250));
  });

  function pickProduct($item){
    var $row = $item.closest('.product-row');
    $row.find('.product-id').val($item.data('id'));
    $row.find('.product-search').val($item.find('.ac-name').text());
    $row.find('.bpc').val($item.data('bpc') || 1);
    $row.find('.rate').val($item.data('rate'));
    hideList($row.find('.ac-list'));
    rowTotals($row);
    recalc();
  }

  $('#productRows').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    pickProduct($(this));
  });

  // Keyboard navigation (up/down/enter/escape) on both search boxes
  $(document).on('keydown', '#supplierSearch, .product-search', function(e){
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

  // Any qty/box typing -> live updates
  $('#productRows').on('input', '.bpc, .cartons, .loose, .rate', function(){
    var row = $(this).closest('.product-row');
    rowTotals(row);
    recalc();
  });

  $('#discountAmount, #paidAmount').on('input', recalc);

  $('#addRow').click(function(){
    var first = $('#productRows .product-row').first().clone();
    first.find('.product-search').val('');
    first.find('.product-id').val('');
    first.find('.ac-list').empty().hide();
    first.find('.bpc').val('1');
    first.find('.cartons, .loose, .rate').val('');
    first.find('.total-boxes').val('0');
    first.find('.carton-total').val('0 Cartons + 0 Boxes');
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
    $('#bankDiv').toggle(this.value === 'bank');
  });

  // Supplier must be picked from suggestions before submit
  $('#purchaseForm').on('submit', function(e){
    if (!$('#supplier_id').val()) {
      e.preventDefault();
      $('#supplierError').removeClass('d-none');
      $('#supplierSearch').focus();
    }
  });

  // Boot recalc so first row shows proper carton text
  rowTotals($('#productRows .product-row').first());
  recalc();
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>