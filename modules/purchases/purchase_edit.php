<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Edit Purchase';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    redirect('index.php', 'Invalid purchase ID', 'error');
}
$purchase_id = (int)$_GET['id'];

$purchase = $pdo->prepare("SELECT * FROM purchases WHERE id = ?");
$purchase->execute([$purchase_id]);
$purchase = $purchase->fetch();
if (!$purchase) {
    redirect('index.php', 'Purchase not found', 'error');
}

$supplier_name = '';
$supplier_id_val = '';
if ($purchase['supplier_id']) {
    $stmt = $pdo->prepare("SELECT id, name, phone, city FROM suppliers WHERE id = ?");
    $stmt->execute([$purchase['supplier_id']]);
    $supplier = $stmt->fetch();
    if ($supplier) {
        $supplier_name = $supplier['name'];
        $supplier_id_val = $supplier['id'];
    }
}

$existing_items_stmt = $pdo->prepare("SELECT pi.*, p.name AS product_name FROM purchase_items pi JOIN products p ON p.id = pi.product_id WHERE pi.purchase_id = ?");
$existing_items_stmt->execute([$purchase_id]);
$existing_rows = $existing_items_stmt->fetchAll();

$existing_items = [];
foreach ($existing_rows as $er) {
    $existing_items[] = [
        'product_id' => (int)$er['product_id'],
        'product_name' => $er['product_name'],
        'cartons' => (int)$er['cartons'],
        'bpc' => (int)$er['boxes_per_carton'],
        'loose_boxes' => (int)$er['loose_boxes'],
        'quantity' => (int)$er['quantity'],
        'rate' => (float)$er['subtotal'] > 0 && (int)$er['cartons'] > 0 ? round(((float)$er['subtotal'] - ((int)$er['loose_boxes'] * (float)$er['purchase_price'])) / (int)$er['cartons'], 2) : (float)$er['purchase_price'] * (int)$er['boxes_per_carton'],
        'subtotal' => (float)$er['subtotal'],
    ];
}
$existing_items_json = json_encode($existing_items);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old_purchase = $pdo->prepare("SELECT * FROM purchases WHERE id = ?");
    $old_purchase->execute([$purchase_id]);
    $old_purchase = $old_purchase->fetch();
    if (!$old_purchase) {
        redirect('index.php', 'Purchase not found', 'error');
    }

    $old_items_stmt = $pdo->prepare("SELECT * FROM purchase_items WHERE purchase_id = ?");
    $old_items_stmt->execute([$purchase_id]);
    $old_items = $old_items_stmt->fetchAll();

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
        redirect("purchase_edit.php?id=$purchase_id", 'Add at least one product', 'error');
    }

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
        redirect("purchase_edit.php?id=$purchase_id", 'Add at least one product with quantity', 'error');
    }

    $paid_amount = (float)($_POST['paid_amount'] ?? 0);
    $discount = (float)($_POST['discount_amount'] ?? 0);
    if ($discount > $total) $discount = $total;
    $net_total = $total - $discount;
    if ($paid_amount > $net_total) $paid_amount = $net_total;
    $due_amount = $net_total - $paid_amount;

    $pdo->beginTransaction();
    try {
        foreach ($old_items as $oi) {
            $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?")
                ->execute([$oi['quantity'], $oi['product_id']]);
        }

        $old_affected_dates = [];
        if ($old_purchase['paid_amount'] > 0) {
            $old_cb = $pdo->prepare("SELECT transaction_date FROM cash_book WHERE reference_type = 'purchase' AND reference_id = ? AND transaction_type = 'outflow'");
            $old_cb->execute([$purchase_id]);
            foreach ($old_cb->fetchAll() as $ocb) { $old_affected_dates[] = $ocb['transaction_date']; }
            $pdo->prepare("DELETE FROM cash_book WHERE reference_type = 'purchase' AND reference_id = ?")
                ->execute([$purchase_id]);
            if ($old_purchase['payment_method'] == 'bank' && $old_purchase['bank_account_id']) {
                $btn = $pdo->prepare("SELECT id, amount, bank_account_id FROM bank_transactions WHERE reference_type = 'purchase' AND reference_id = ?");
                $btn->execute([$purchase_id]);
                foreach ($btn->fetchAll() as $b) {
                    $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")
                        ->execute([$b['amount'], $b['bank_account_id']]);
                }
                $pdo->prepare("DELETE FROM bank_transactions WHERE reference_type = 'purchase' AND reference_id = ?")
                    ->execute([$purchase_id]);
            }
        }

        $pdo->prepare("DELETE FROM purchase_items WHERE purchase_id = ?")->execute([$purchase_id]);

        $invoice_no = $old_purchase['invoice_no'];

        $pdo->prepare("UPDATE purchases SET supplier_id = ?, purchase_date = ?, total_amount = ?, discount_amount = ?, paid_amount = ?, due_amount = ?, payment_method = ?, bank_account_id = ?, notes = ?, updated_at = ? WHERE id = ?")
            ->execute([$supplier_id, $purchase_date, $net_total, $discount, $paid_amount, $due_amount, $payment_method, $payment_method == 'bank' ? $bank_account_id : null, $notes, date('Y-m-d'), $purchase_id]);

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
            $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ?, purchase_price = ?, boxes_per_carton = ? WHERE id = ?")
                ->execute([$it['qty'], round($it['rate_ctn'], 2), $it['bpc'], $it['product_id']]);
        }

        if ($paid_amount > 0) {
            $desc = "Purchase #{$invoice_no}";
            if ($payment_method == 'bank') {
                recordBankOutflow($pdo, $purchase_date, $paid_amount, $desc, 'purchase', $purchase_id, $_SESSION['user_id'], $bank_account_id);
            } else {
                recordCashOutflow($pdo, $purchase_date, $paid_amount, $desc, 'purchase', $purchase_id, $_SESSION['user_id']);
            }
        }

        foreach (array_unique(array_merge([$purchase_date], $old_affected_dates)) as $d) {
            $day = $pdo->prepare("SELECT id FROM cash_book_daily WHERE date = ?");
            $day->execute([$d]);
            $did = $day->fetchColumn();
            if ($did) recomputeCashDayTotals($pdo, (int)$did);
        }
        if ($old_affected_dates) recomputeCashDailyFrom($pdo, min($old_affected_dates));

        if ($old_purchase['supplier_id']) updateSupplierBalance($pdo, $old_purchase['supplier_id']);
        if ($supplier_id && $supplier_id != ($old_purchase['supplier_id'] ?? null)) updateSupplierBalance($pdo, $supplier_id);

        $pdo->commit();
        logActivity($pdo, 'update', 'purchase', $purchase_id, 'Updated purchase ' . $invoice_no . ' total ' . $net_total . ' (' . $total_boxes . ' boxes)');
        redirect('index.php', 'Purchase updated: ' . $invoice_no . ', Stock adjusted (' . $total_boxes . ' boxes).');
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect("purchase_edit.php?id=$purchase_id", 'Error updating purchase: ' . $e->getMessage(), 'error');
    }
}

$bank_accounts = $pdo->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id")->fetchAll();
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6><i class="fas fa-cart-arrow-down"></i> Edit Purchase (Carton / Box System)</h6>
    <a href="index.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-list"></i> Purchase List</a>
  </div>
  <div class="card-body">
    <form method="post" id="purchaseForm" action="purchase_edit.php?id=<?=(int)$purchase_id?>">
      <input type="hidden" name="id" value="<?=htmlspecialchars($purchase_id)?>">

      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">Supplier *</label>
          <div class="ac-wrap" id="supplierWrap">
            <input type="text" id="supplierSearch" class="form-control" placeholder="Type supplier name to search..." autocomplete="off" value="<?=htmlspecialchars($supplier_name)?>">
            <input type="hidden" name="supplier_id" id="supplier_id" value="<?=htmlspecialchars($supplier_id_val)?>">
            <div class="ac-list" id="supplierList"></div>
          </div>
          <small class="text-danger d-none" id="supplierError"><i class="fas fa-exclamation-circle"></i> Please select a supplier from the suggestions.</small>
          <small class="text-muted" id="supplierBalance"></small>
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Purchase Date *</label>
          <input type="date" name="purchase_date" class="form-control datepicker" value="<?=htmlspecialchars($purchase['purchase_date'])?>" required>
        </div>
        <div class="col-md-2 mb-3">
          <label class="form-label">Payment Method</label>
          <select name="payment_method" id="payMethod" class="form-control">
            <option value="cash"<?= $purchase['payment_method'] == 'cash' ? ' selected' : ''?>>Cash</option>
            <option value="bank"<?= $purchase['payment_method'] == 'bank' ? ' selected' : ''?>>Bank</option>
          </select>
        </div>
        <div class="col-md-3 mb-3" id="bankDiv"<?= $purchase['payment_method'] != 'bank' ? ' style="display:none;"' : ''?>>
          <label class="form-label">Bank Account</label>
          <select name="bank_account_id" class="form-control">
            <?php foreach ($bank_accounts as $ba): ?>
            <option value="<?=$ba['id']?>"<?= (int)$purchase['bank_account_id'] === (int)$ba['id'] ? ' selected' : ''?>><?=htmlspecialchars($ba['account_name'])?> - <?=htmlspecialchars($ba['bank_name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <hr>
      <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
        <h6 class="mb-0 text-secondary"><i class="fas fa-boxes"></i> Products (Carton / Box Entry)</h6>
        <small class="text-muted">Total boxes are calculated automatically</small>
      </div>

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

      <div class="alert alert-info py-2 mb-3" id="qtySummary">
        <i class="fas fa-calculator"></i> <strong>Total Quantity:</strong>
        <span id="sumBoxes">0 Boxes</span> &nbsp;|&nbsp; <span id="sumCartons">0 Cartons + 0 Boxes</span>
      </div>

      <hr>

      <div class="row">
        <div class="col-md-3">
          <label class="form-label">Total Amount</label>
          <input type="text" class="form-control font-weight-bold" id="totalAmount" value="0.00" readonly>
        </div>
        <div class="col-md-3">
          <label class="form-label">Discount</label>
          <input type="number" name="discount_amount" id="discountAmount" class="form-control" value="<?=htmlspecialchars($purchase['discount_amount'])?>" min="0">
        </div>
        <div class="col-md-3">
          <label class="form-label">Paid Amount</label>
          <input type="number" name="paid_amount" id="paidAmount" class="form-control" value="<?=htmlspecialchars($purchase['paid_amount'])?>" min="0">
        </div>
        <div class="col-md-3">
          <label class="form-label">Due Amount</label>
          <input type="text" class="form-control font-weight-bold text-danger" id="dueAmount" value="0.00" readonly>
        </div>
      </div>

      <div class="row mt-3">
        <div class="col-md-8">
          <label class="form-label">Notes</label>
          <input type="text" name="notes" class="form-control" placeholder="Optional notes" value="<?=htmlspecialchars($purchase['notes'] ?? '')?>">
        </div>
        <div class="col-md-4 d-flex align-items-end">
          <button type="submit" class="btn btn-primary btn-block py-2"><i class="fas fa-save"></i> Update Purchase</button>
        </div>
      </div>

    </form>
  </div>
</div>

<script>
var existing_items = <?=$existing_items_json?>;
$(document).ready(function(){
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
    var gbpc = 1;
    var first = $('#productRows .product-row').first();
    gbpc = parseInt(first.find('.bpc').val()) || 1;
    var grand = sumBoxes === 0 ? '0 Cartons + 0 Boxes' : cartonText(sumBoxes, gbpc);
    $('#sumCartons').text(grand);
  }

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
    var psub = [];
    if (item.code) psub.push('Code: ' + esc(item.code));
    if (item.unit) psub.push('Unit: ' + esc(item.unit));
    return $('<div class="ac-item" data-id="' + item.id + '" data-bpc="' + item.boxes_per_carton + '" data-rate="' + item.purchase_price + '" data-stock="' + item.stock_quantity + '">' +
      '<span class="ac-name">' + name + '</span>' +
      '<small class="ac-sub">' + psub.join(' &middot; ') + '</small>' +
      '<small class="ac-sub"><i class="fas fa-boxes"></i> In stock: ' + item.stock_quantity + '</small>' +
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

  $('#purchaseForm').on('submit', function(e){
    if (!$('#supplier_id').val()) {
      e.preventDefault();
      $('#supplierError').removeClass('d-none');
      $('#supplierSearch').focus();
    }
  });

  if (existing_items.length > 0) {
    var firstRow = $('#productRows .product-row').first();
    $.each(existing_items, function(idx, item){
      var $row;
      if (idx === 0) {
        $row = firstRow;
      } else {
        $row = firstRow.clone();
        $('#productRows').append($row);
      }
      $row.find('.product-search').val(item.product_name);
      $row.find('.product-id').val(item.product_id);
      $row.find('.bpc').val(item.bpc);
      $row.find('.cartons').val(item.cartons);
      $row.find('.loose').val(item.loose_boxes);
      $row.find('.rate').val(item.rate);
      rowTotals($row);
    });
    recalc();
  } else {
    rowTotals($('#productRows .product-row').first());
    recalc();
  }
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
