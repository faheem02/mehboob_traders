<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Receive from Customer';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$bank_accounts = $pdo->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id")->fetchAll();

foreach ($pdo->query("SELECT id FROM customers")->fetchAll() as $c) updateCustomerBalance($pdo, $c['id']);
$customers = $pdo->query("SELECT id, full_name, current_balance FROM customers ORDER BY full_name")->fetchAll();

$receipts = $pdo->query("SELECT r.*, c.full_name, ba.account_name
    FROM customer_receipts r
    JOIN customers c ON c.id = r.customer_id
    LEFT JOIN bank_accounts ba ON ba.id = r.bank_account_id
    ORDER BY r.receipt_date DESC, r.id DESC")->fetchAll();

$preselect = (int)($_GET['customer_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $tdate = $_POST['transaction_date'] ?: date('Y-m-d');
    $payment_method = $_POST['payment_method'] ?: 'cash';
    $bank_id = $payment_method == 'bank' ? ($_POST['bank_account_id'] ?: null) : null;
    $description = trim($_POST['description'] ?? '');

    if (!$customer_id) { redirect('receive_customer.php', 'Select a customer', 'error'); }
    if ($amount <= 0) { redirect('receive_customer.php', 'Enter a valid amount', 'error'); }

    $pdo->beginTransaction();
    try {
        $customer = getById('customers', $customer_id);
        if (!$customer) throw new Exception('Customer not found');
        insert('customer_receipts', [
            'customer_id' => $customer_id,
            'amount' => $amount,
            'payment_method' => $payment_method,
            'bank_account_id' => $bank_id,
            'description' => $description ?: 'Customer payment',
            'receipt_date' => $tdate,
            'created_by' => $_SESSION['user_id'],
            'created_at' => date('Y-m-d'),
        ]);
        $desc = 'Customer receipt: ' . $customer['full_name'] . ' (PKR ' . formatCurrency($amount) . ')';
        if ($payment_method == 'bank') {
            recordBankInflow($pdo, $tdate, $amount, $desc, 'customer_receipt', $customer_id, $_SESSION['user_id'], $bank_id);
        } else {
            recordCashInflow($pdo, $tdate, $amount, $desc, 'customer_receipt', $customer_id, $_SESSION['user_id']);
        }
        updateCustomerBalance($pdo, $customer_id);
        allocateReceiptsToSales($pdo, $customer_id);
        logActivity($pdo, 'receive', 'customer', $customer_id, 'Received PKR ' . $amount . ' from ' . $customer['full_name']);
        $pdo->commit();
        redirect('receive_customer.php', 'Received PKR ' . formatCurrency($amount) . ' from ' . $customer['full_name']);
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('receive_customer.php', 'Error: ' . $e->getMessage(), 'error');
    }
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="row mb-3">
  <div class="col-md-8">
    <div class="alert alert-success alert-dismissible fade show py-2 mb-0" role="alert">
      <i class="fas fa-arrow-down"></i> <strong>Receive from Customer</strong> &nbsp;Record money received from the customer against credit sales.
      <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span>&times;</span></button>
    </div>
  </div>
  <div class="col-md-4 text-md-right">
    <button type="button" class="btn btn-success shadow-sm" data-toggle="modal" data-target="#receiveModal">
      <i class="fas fa-hand-holding-usd"></i> Receive Amount
    </button>
  </div>
</div>

<div class="modal fade" id="receiveModal" tabindex="-1" role="dialog" aria-labelledby="receiveModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="method" value="receive_customer">
        <div class="modal-header">
          <h5 class="modal-title" id="receiveModalLabel"><i class="fas fa-arrow-down text-success"></i> Receive from Customer</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Select Customer *</label>
              <div class="ac-wrap">
                <input type="text" id="customerSearch" class="form-control" placeholder="Type customer name to search..." autocomplete="off">
                <input type="hidden" name="customer_id" id="customer_id">
                <div class="ac-list" id="customerList"></div>
              </div>
              <small class="text-danger d-none" id="customerError"><i class="fas fa-exclamation-circle"></i> Please select a customer from the suggestions.</small>
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
              <input type="text" name="description" class="form-control" value="Customer payment">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Confirm Receipt</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6 class="mb-0"><i class="fas fa-list"></i> Customers with Balance (To Receive)</h6>
    <input type="text" id="custSearch" class="form-control form-control-sm" placeholder="Search customer" style="max-width:240px;">
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered table-hover" id="custBalanceTable">
        <thead>
          <tr><th>Customer</th><th>Phone</th><th>City</th><th class="text-right">Receivable (RECEIVE)</th><th></th></tr>
        </thead>
        <tbody>
          <?php $has = false; foreach ($customers as $c) { if ((float)$c['current_balance'] <= 0) continue; $has = true; $cust = getById('customers',$c['id']); ?>
            <tr>
              <td class="font-weight-bold"><?=htmlspecialchars($c['full_name'])?></td>
              <td><?=htmlspecialchars($cust['phone'] ?? '-')?></td>
              <td><?=htmlspecialchars($cust['city'] ?? '-')?></td>
              <td class="text-right text-danger font-weight-bold">PKR <?=formatCurrency($c['current_balance'])?></td>
              <td class="text-right"><a href="#" data-id="<?=$c['id']?>" data-name="<?=htmlspecialchars($c['full_name'])?>" class="btn btn-sm btn-outline-success pick-party"><i class="fas fa-arrow-down"></i> Receive</a></td>
            </tr>
          <?php } if (!$has): ?><tr><td colspan="5" class="text-center text-muted py-3">No customer receivable balance. Everything is settled.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card shadow mt-3">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6 class="mb-0"><i class="fas fa-history"></i> All Payments (<?=count($receipts)?>)</h6>
    <input type="text" id="paySearch" class="form-control form-control-sm" placeholder="Search customer / date / description" style="max-width:260px;">
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered" id="payHistoryTable">
        <thead>
          <tr><th>#</th><th>Date</th><th>Customer</th><th>Description</th><th>Method</th><th class="text-right">Amount</th></tr>
        </thead>
        <tbody>
          <?php if (empty($receipts)): ?>
            <tr><td colspan="6" class="text-center text-muted py-3">No payments received yet.</td></tr>
          <?php else: $i = 0; foreach ($receipts as $r): $i++; ?>
            <tr>
              <td><?=$i?></td>
              <td><?=formatDate($r['receipt_date'])?></td>
              <td class="font-weight-bold"><?=htmlspecialchars($r['full_name'])?></td>
              <td><?=htmlspecialchars($r['description'] ?? '-')?></td>
              <td>
                <?php if ($r['payment_method'] == 'bank'): ?>
                  <span class="badge badge-info">Bank</span> <?=htmlspecialchars($r['account_name'] ?? '')?>
                <?php else: ?>
                  <span class="badge badge-success">Cash</span>
                <?php endif; ?>
              </td>
              <td class="text-right text-success font-weight-bold">PKR <?=formatCurrency($r['amount'])?></td>
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
  $('#partyBalance').text(bal > 0 ? 'Receivable: PKR ' + bal.toFixed(2) : 'Advance: PKR ' + Math.abs(bal).toFixed(2));
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
  if (preselect) { $('#receiveModal').modal('show'); }

  function pickCustomer(id, name){
    $('#customer_id').val(id);
    $('#customerSearch').val(name);
    $('#customerError').addClass('d-none');
    mtHideList($('#customerList'));
    $.get('ajax_customer_balance.php', {id: id}, function(data){
      mtShowBalance(data);
    });
  }

  $('#payMethod').change(function(){ $('#bankDiv').toggle(this.value === 'bank'); });

  var custTimer = null;
  $('#customerSearch').on('input', function(){
    var q = $.trim(this.value);
    clearTimeout(custTimer);
    if (!q) {
      $('#customer_id').val('');
      $('#partyBalance').text('');
      $('#customerError').addClass('d-none');
      mtHideList($('#customerList'));
      return;
    }
    custTimer = setTimeout(function(){
      $.get('ajax_customer_search.php', {q: q}, function(data){
        mtRenderList($('#customerList'), data);
      });
    }, 250);
  });

  $('#customerList').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    pickCustomer($(this).data('id'), $(this).find('.ac-name').text());
  });

  $(document).on('keydown', '#customerSearch', function(e){
    var $list = $('#customerList');
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

  // Legacy: let ledger "Receive Payment" links also work via ?customer_id=
  var preselectId = <?= (int)$preselect ?: 0 ?>;
  if (preselectId) {
    <?php $sel = getById('customers', $preselect); ?>
    pickCustomer(preselectId, <?= json_encode($sel['full_name'] ?? '') ?>);
  }

  // "To Receive" table rows
  $('.pick-party').click(function(e){
    e.preventDefault();
    pickCustomer($(this).data('id'), $(this).data('name'));
    $('#receiveModal').modal('show');
  });

  $('#receiveModal form').on('submit', function(e){
    if (!$('#customer_id').val()) {
      e.preventDefault();
      $('#customerError').removeClass('d-none');
      $('#customerSearch').focus();
    }
  });

  $('#custSearch').on('keyup', function(){
    var q = $(this).val().toLowerCase();
    $('#custBalanceTable tbody tr').each(function(){
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