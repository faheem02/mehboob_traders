<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Pay Supplier';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$bank_accounts = $pdo->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id")->fetchAll();

foreach ($pdo->query("SELECT id FROM suppliers")->fetchAll() as $s) updateSupplierBalance($pdo, $s['id']);
$suppliers = $pdo->query("SELECT id, name, current_balance FROM suppliers WHERE status = 1 ORDER BY name")->fetchAll();

$payments = $pdo->query("SELECT r.*, s.name AS supp_name, ba.account_name
    FROM supplier_payments r
    JOIN suppliers s ON s.id = r.supplier_id
    LEFT JOIN bank_accounts ba ON ba.id = r.bank_account_id
    ORDER BY r.payment_date DESC, r.id DESC")->fetchAll();

$preselect = (int)($_GET['supplier_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_id = (int)($_POST['supplier_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $tdate = $_POST['transaction_date'] ?: date('Y-m-d');
    $payment_method = $_POST['payment_method'] ?: 'cash';
    $bank_id = $payment_method == 'bank' ? ($_POST['bank_account_id'] ?: null) : null;
    $description = trim($_POST['description'] ?? '');

    if (!$supplier_id) { redirect('pay_supplier.php', 'Select a supplier', 'error'); }
    if ($amount <= 0) { redirect('pay_supplier.php', 'Enter a valid amount', 'error'); }

    $pdo->beginTransaction();
    try {
        $supplier = getById('suppliers', $supplier_id);
        if (!$supplier) throw new Exception('Supplier not found');
        insert('supplier_payments', [
            'supplier_id' => $supplier_id,
            'amount' => $amount,
            'payment_method' => $payment_method,
            'bank_account_id' => $bank_id,
            'description' => $description ?: 'Supplier payment',
            'payment_date' => $tdate,
            'created_by' => $_SESSION['user_id'],
            'created_at' => date('Y-m-d'),
        ]);
        $desc = 'Supplier payment: ' . $supplier['name'] . ' (PKR ' . formatCurrency($amount) . ')';
        if ($payment_method == 'bank') {
            recordBankOutflow($pdo, $tdate, $amount, $desc, 'supplier_payment', $supplier_id, $_SESSION['user_id'], $bank_id);
        } else {
            recordCashOutflow($pdo, $tdate, $amount, $desc, 'supplier_payment', $supplier_id, $_SESSION['user_id']);
        }
        updateSupplierBalance($pdo, $supplier_id);
        logActivity($pdo, 'pay', 'supplier', $supplier_id, 'Paid supplier ' . $supplier['name'] . ' PKR ' . $amount);
        $pdo->commit();
        redirect('pay_supplier.php', 'Paid PKR ' . formatCurrency($amount) . ' to ' . $supplier['name']);
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('pay_supplier.php', 'Error: ' . $e->getMessage(), 'error');
    }
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="row mb-3 d-print-none">
  <div class="col-md-8">
    <div class="alert alert-danger alert-dismissible fade show py-2 mb-0" role="alert">
      <i class="fas fa-arrow-up"></i> <strong>Pay Supplier</strong> &nbsp;Record payment for goods purchased from the supplier.
      <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span>&times;</span></button>
    </div>
  </div>
  <div class="col-md-4 text-md-right">
    <button type="button" class="btn btn-outline-secondary shadow-sm mr-2" onclick="window.print()">
      <i class="fas fa-print"></i> Print
    </button>
    <button type="button" class="btn btn-danger shadow-sm" data-toggle="modal" data-target="#payModal">
      <i class="fas fa-money-bill-wave"></i> Pay Amount
    </button>
  </div>
</div>

<!-- Printable header -->
<div class="d-none d-print-block mb-3 text-center">
  <h4 class="font-weight-bold mb-0" style="color:#0f172a;">Mehboob Traders</h4>
  <small class="text-muted">Wholesale Business</small>
  <h5 class="font-weight-bold text-primary mt-2 mb-0">SUPPLIER PAYMENTS</h5>
  <small>Printed on <?=formatDate(date('Y-m-d'))?></small>
</div>

<div class="modal fade" id="payModal" tabindex="-1" role="dialog" aria-labelledby="payModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="method" value="pay_supplier">
        <div class="modal-header">
          <h5 class="modal-title" id="payModalLabel"><i class="fas fa-arrow-up text-danger"></i> Pay Supplier</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Select Supplier *</label>
              <div class="ac-wrap">
                <input type="text" id="supplierSearch" class="form-control" placeholder="Type supplier name to search..." autocomplete="off">
                <input type="hidden" name="supplier_id" id="supplier_id">
                <div class="ac-list" id="supplierList"></div>
              </div>
              <small class="text-danger d-none" id="supplierError"><i class="fas fa-exclamation-circle"></i> Please select a supplier from the suggestions.</small>
              <small class="text-muted" id="partyBalance"></small>
            </div>
            <div class="col-md-3 mb-3">
              <label class="form-label">Amount (PKR) *</label>
              <input type="number" name="amount" step="0.01" min="0" class="form-control" required placeholder="0.00">
            </div>
            <div class="col-md-3 mb-3">
              <label class="form-label">Date *</label>
              <input type="date" name="transaction_date" class="form-control datepicker" value="<?=date('Y-m-d')?>" required>
            </div>
          </div>
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">Method</label>
              <select name="payment_method" id="payMethod" class="form-control">
                <option value="cash">Cash</option>
                <option value="bank">Bank</option>
              </select>
            </div>
            <div class="col-md-4 mb-3" id="bankDiv" style="display:none;">
              <label class="form-label">Bank Account</label>
              <select name="bank_account_id" class="form-control">
                <?php foreach ($bank_accounts as $ba): ?>
                <option value="<?=$ba['id']?>"><?=htmlspecialchars($ba['account_name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Notes / Description</label>
              <input type="text" name="description" class="form-control" value="Supplier payment">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-check"></i> Confirm Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6 class="mb-0"><i class="fas fa-list"></i> Suppliers with Balance (To Pay)</h6>
    <input type="text" id="suppSearch" class="form-control form-control-sm d-print-none" placeholder="Search supplier" style="max-width:240px;">
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered table-hover" id="suppBalanceTable">
        <thead>
          <tr><th>Supplier</th><th>Phone</th><th>City</th><th class="text-right">Payable (PAY)</th><th class="d-print-none"></th></tr>
        </thead>
        <tbody>
          <?php $has = false; foreach ($suppliers as $s) { if ((float)$s['current_balance'] <= 0) continue; $has = true; $supp = getById('suppliers',$s['id']); ?>
            <tr>
              <td class="font-weight-bold"><?=htmlspecialchars($s['name'])?></td>
              <td><?=htmlspecialchars($supp['phone'] ?? '-')?></td>
              <td><?=htmlspecialchars($supp['city'] ?? '-')?></td>
              <td class="text-right text-danger font-weight-bold">PKR <?=formatCurrency($s['current_balance'])?></td>
              <td class="text-right d-print-none"><a href="#" data-id="<?=$s['id']?>" data-name="<?=htmlspecialchars($s['name'])?>" class="btn btn-sm btn-outline-danger pick-party"><i class="fas fa-arrow-up"></i> Pay</a></td>
            </tr>
          <?php } if (!$has): ?><tr><td colspan="5" class="text-center text-muted py-3">No supplier payable balance. Everything is settled.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card shadow mt-3">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6 class="mb-0"><i class="fas fa-history"></i> All Payments (<?=count($payments)?>)</h6>
    <input type="text" id="paySearch" class="form-control form-control-sm d-print-none" placeholder="Search supplier / date / description" style="max-width:260px;">
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered" id="payHistoryTable">
        <thead>
          <tr><th>#</th><th>Date</th><th>Supplier</th><th>Description</th><th>Method</th><th class="text-right">Amount</th></tr>
        </thead>
        <tbody>
          <?php if (empty($payments)): ?>
            <tr><td colspan="6" class="text-center text-muted py-3">No payments made yet.</td></tr>
          <?php else: $i = 0; foreach ($payments as $r): $i++; ?>
            <tr>
              <td><?=$i?></td>
              <td><?=formatDate($r['payment_date'])?></td>
              <td class="font-weight-bold"><?=htmlspecialchars($r['supp_name'])?></td>
              <td><?=htmlspecialchars($r['description'] ?? '-')?></td>
              <td>
                <?php if ($r['payment_method'] == 'bank'): ?>
                  <span class="badge badge-info">Bank</span> <?=htmlspecialchars($r['account_name'] ?? '')?>
                <?php else: ?>
                  <span class="badge badge-danger">Cash</span>
                <?php endif; ?>
              </td>
              <td class="text-right text-danger font-weight-bold">PKR <?=formatCurrency($r['amount'])?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
function mtEsc(s){
  return String(s == null ? '' : s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function mtHideList($list){ $list.empty().hide(); }
function mtShowBalance(bal){
  bal = Number(bal);
  if (bal === 0) { $('#partyBalance').text('Clear'); return; }
  $('#partyBalance').text(bal > 0 ? 'Payable: PKR ' + bal.toFixed(2) : 'Advance: PKR ' + Math.abs(bal).toFixed(2));
}
function mtRenderList($list, items){
  $list.empty();
  if (!items || !items.length) {
    $list.append('<div class="ac-item ac-empty">No matching record found</div>');
  } else {
    $.each(items, function(i, it){
      var sub = [];
      if (it.phone) sub.push('Phone: ' + mtEsc(it.phone));
      if (it.city) sub.push(mtEsc(it.city));
      $list.append($('<div class="ac-item" data-id="' + it.id + '">' +
        '<span class="ac-name">' + mtEsc(it.name) + '</span>' +
        (sub.length ? '<small class="ac-sub">' + sub.join(' &middot; ') + '</small>' : '') +
        '</div>'));
    });
  }
  $list.show();
}

$(document).ready(function(){
  var preselect = <?= $preselect ? 'true' : 'false' ?>;
  if (preselect) { $('#payModal').modal('show'); }

  function pickSupplier(id, name){
    $('#supplier_id').val(id);
    $('#supplierSearch').val(name);
    $('#supplierError').addClass('d-none');
    mtHideList($('#supplierList'));
    $.get('ajax_supplier_balance.php', {id: id}, function(data){
      mtShowBalance(data);
    });
  }

  $('#payMethod').change(function(){ $('#bankDiv').toggle(this.value === 'bank'); });

  var supTimer = null;
  $('#supplierSearch').on('input', function(){
    var q = $.trim(this.value);
    clearTimeout(supTimer);
    if (!q) {
      $('#supplier_id').val('');
      $('#partyBalance').text('');
      $('#supplierError').addClass('d-none');
      mtHideList($('#supplierList'));
      return;
    }
    supTimer = setTimeout(function(){
      $.get('ajax_supplier_search.php', {q: q}, function(data){
        mtRenderList($('#supplierList'), data);
      });
    }, 250);
  });

  $('#supplierList').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    pickSupplier($(this).data('id'), $(this).find('.ac-name').text());
  });

  $(document).on('keydown', '#supplierSearch', function(e){
    var $list = $('#supplierList');
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
      mtHideList($list);
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

  // Legacy: let supplier ledger "Pay" links also work via ?supplier_id=
  var preselectId = <?= (int)$preselect ?: 0 ?>;
  if (preselectId) {
    <?php $sel = getById('suppliers', $preselect); ?>
    pickSupplier(preselectId, <?= json_encode($sel['name'] ?? '') ?>);
  }

  // "To Pay" table rows
  $('.pick-party').click(function(e){
    e.preventDefault();
    pickSupplier($(this).data('id'), $(this).data('name'));
    $('#payModal').modal('show');
  });

  $('#payModal form').on('submit', function(e){
    if (!$('#supplier_id').val()) {
      e.preventDefault();
      $('#supplierError').removeClass('d-none');
      $('#supplierSearch').focus();
    }
  });

  $('#suppSearch').on('keyup', function(){
    var q = $(this).val().toLowerCase();
    $('#suppBalanceTable tbody tr').each(function(){
      $(this).toggle($(this).text().toLowerCase().indexOf(q) > -1);
    });
  });
  $('#paySearch').on('keyup', function(){
    var q = $(this).val().toLowerCase();
    $('#payHistoryTable tbody tr').each(function(){
      $(this).toggle($(this).text().toLowerCase().indexOf(q) > -1);
    });
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>