<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Receive from Customer';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$bank_accounts = $pdo->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id")->fetchAll();

foreach ($pdo->query("SELECT id FROM customers")->fetchAll() as $c) {
    updateCustomerBalance($pdo, $c['id']);
    syncCustomerSalesPayments($pdo, $c['id']);
}
$customers = $pdo->query("SELECT id, full_name, current_balance FROM customers ORDER BY full_name")->fetchAll();

$receipts = $pdo->query("SELECT r.*, c.full_name, ba.account_name, s.invoice_no
    FROM customer_receipts r
    JOIN customers c ON c.id = r.customer_id
    LEFT JOIN bank_accounts ba ON ba.id = r.bank_account_id
    LEFT JOIN sales s ON s.id = r.sale_id
    ORDER BY r.receipt_date DESC, r.id DESC")->fetchAll();

$merged_rows = [];
foreach ($customers as $c) {
    if ((float)$c['current_balance'] <= 0) continue;
    $cust = getById('customers', $c['id']);
    $merged_rows[] = [
        'type' => 'due',
        'date' => null,
        'customer' => $c['full_name'],
        'customer_id' => $c['id'],
        'phone' => $cust['phone'] ?? null,
        'city' => $cust['city'] ?? null,
        'amount' => (float)$c['current_balance'],
    ];
}
foreach ($receipts as $r) {
    $merged_rows[] = [
        'type' => 'receipt',
        'date' => $r['receipt_date'],
        'customer' => $r['full_name'],
        'sale_id' => $r['sale_id'],
        'invoice_no' => $r['invoice_no'],
        'description' => $r['description'],
        'payment_method' => $r['payment_method'],
        'account_name' => $r['account_name'],
        'amount' => (float)$r['amount'],
    ];
}
$due_count = 0;
foreach ($merged_rows as $mr) { if ($mr['type'] === 'due') $due_count++; }

$preselect = (int)($_GET['customer_id'] ?? 0);
$preselect_sale = (int)($_GET['sale_id'] ?? 0);

if ($preselect_sale && !$preselect) {
    $sale_check = $pdo->prepare("SELECT customer_id FROM sales WHERE id = ?");
    $sale_check->execute([$preselect_sale]);
    $preselect = (int)$sale_check->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $sale_id = !empty($_POST['sale_id']) ? (int)$_POST['sale_id'] : null;
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

        $invoice_tag = '';
        if ($sale_id) {
            $sale = getById('sales', $sale_id);
            if ($sale && $sale['customer_id'] == $customer_id) {
                $invoice_tag = ' (Invoice #' . $sale['invoice_no'] . ')';
            } else {
                $sale_id = null;
            }
        }

        insert('customer_receipts', [
            'customer_id' => $customer_id,
            'sale_id' => $sale_id,
            'amount' => $amount,
            'payment_method' => $payment_method,
            'bank_account_id' => $bank_id,
            'description' => $description ?: ('Customer payment' . $invoice_tag),
            'receipt_date' => $tdate,
            'created_by' => $_SESSION['user_id'],
            'created_at' => date('Y-m-d'),
        ]);

        $desc = 'Customer receipt: ' . $customer['full_name'] . $invoice_tag . ' (PKR ' . formatCurrency($amount) . ')';
        if ($payment_method == 'bank') {
            recordBankInflow($pdo, $tdate, $amount, $desc, 'customer_receipt', $customer_id, $_SESSION['user_id'], $bank_id);
        } else {
            recordCashInflow($pdo, $tdate, $amount, $desc, 'customer_receipt', $customer_id, $_SESSION['user_id']);
        }

        syncCustomerSalesPayments($pdo, $customer_id);
        updateCustomerBalance($pdo, $customer_id);

        logActivity($pdo, 'receive', 'customer', $customer_id, 'Received PKR ' . $amount . ' from ' . $customer['full_name'] . $invoice_tag);
        $pdo->commit();
        redirect('receive_customer.php', 'Received PKR ' . formatCurrency($amount) . ' from ' . $customer['full_name'] . $invoice_tag);
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('receive_customer.php', 'Error: ' . $e->getMessage(), 'error');
    }
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="row mb-3 d-print-none">
  <div class="col-md-8">
    <div class="alert alert-success alert-dismissible fade show py-2 mb-0" role="alert">
      <i class="fas fa-arrow-down"></i> <strong>Receive from Customer</strong> &nbsp;Record money received from the customer against credit sales or general balance.
      <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span>&times;</span></button>
    </div>
  </div>
  <div class="col-md-4 text-md-right">
    <button type="button" class="btn btn-outline-secondary shadow-sm mr-2" onclick="window.print()">
      <i class="fas fa-print"></i> Print
    </button>
    <button type="button" class="btn btn-success shadow-sm" data-toggle="modal" data-target="#receiveModal">
      <i class="fas fa-hand-holding-usd"></i> Receive Amount
    </button>
  </div>
</div>

<!-- Printable header -->
<div class="d-none d-print-block mb-3 text-center">
  <h4 class="font-weight-bold mb-0" style="color:#0f172a;">Mehboob Traders</h4>
  <small class="text-muted">Wholesale Business</small>
  <h5 class="font-weight-bold text-primary mt-2 mb-0">CUSTOMER RECEIPTS</h5>
  <small>Printed on <?=formatDate(date('Y-m-d'))?></small>
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
              <small class="text-muted font-weight-bold d-block mt-1" id="partyBalance"></small>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Against Invoice <small class="text-muted">(Optional)</small></label>
              <select name="sale_id" id="invoiceSelect" class="form-control">
                <option value="">-- General Account / Opening Balance --</option>
              </select>
              <div id="invoiceInfo" class="small mt-1" style="display:none;"></div>
            </div>
          </div>
          <div class="row">
            <div class="col-md-3 mb-3">
              <label class="form-label">Amount (PKR) *</label>
              <input type="number" name="amount" id="receiveAmount" step="0.01" min="0.01" class="form-control" required placeholder="0.00">
            </div>
            <div class="col-md-3 mb-3">
              <label class="form-label">Date *</label>
              <input type="date" name="transaction_date" class="form-control datepicker" value="<?=date('Y-m-d')?>" required>
            </div>
            <div class="col-md-3 mb-3">
              <label class="form-label">Method</label>
              <select name="payment_method" id="payMethod" class="form-control">
                <option value="cash">Cash</option>
                <option value="bank">Bank</option>
              </select>
            </div>
            <div class="col-md-3 mb-3" id="bankDiv" style="display:none;">
              <label class="form-label">Bank Account</label>
              <select name="bank_account_id" class="form-control">
                <?php foreach ($bank_accounts as $ba): ?>
                <option value="<?=$ba['id']?>"><?=htmlspecialchars($ba['account_name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="row">
            <div class="col-md-12 mb-2">
              <label class="form-label">Notes / Description</label>
              <input type="text" name="description" id="receiveDescription" class="form-control" value="Customer payment" placeholder="e.g. Payment for Invoice #INV-...">
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
    <h6 class="mb-0"><i class="fas fa-list"></i> Customers &amp; Payments
      <small class="text-muted">(<?=$due_count?> with balance to receive &middot; <?=count($receipts)?> payments)</small>
    </h6>
    <input type="text" id="tblSearch" class="form-control form-control-sm d-print-none" placeholder="Search customer / invoice / date..." style="max-width:280px;">
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered table-hover" id="mergedTable">
        <thead>
          <tr><th>#</th><th>Date</th><th>Customer</th><th>Details</th><th>Method</th><th class="text-right">Amount</th><th class="d-print-none text-center">Action</th></tr>
        </thead>
        <tbody>
          <?php if (empty($merged_rows)): ?>
            <tr><td colspan="7" class="text-center text-muted py-3">No customer receivable balance or payments yet.</td></tr>
          <?php else: $i = 0; foreach ($merged_rows as $row): $i++;
            $is_due = ($row['type'] === 'due'); ?>
            <tr>
              <td><?=$i?></td>
              <td><?=$is_due ? '-' : formatDate($row['date'])?></td>
              <td class="font-weight-bold"><?=htmlspecialchars($row['customer'])?></td>
              <td>
                <?php if ($is_due): ?>
                  <span class="badge badge-warning font-weight-normal">Outstanding Balance</span>
                  <?php
                  $info = array_filter([$row['phone'] ?? null, $row['city'] ?? null]);
                  if ($info): ?>
                  <span class="text-muted d-block small"><?=htmlspecialchars(implode(' &middot; ', $info))?></span>
                  <?php endif; ?>
                <?php else: ?>
                  <?php if (!empty($row['sale_id']) && !empty($row['invoice_no'])): ?>
                    <a href="../sales/invoice.php?id=<?=$row['sale_id']?>" class="badge badge-primary font-weight-normal" target="_blank">
                      <i class="fas fa-file-invoice"></i> <?=htmlspecialchars($row['invoice_no'])?>
                    </a>
                  <?php else: ?>
                    <span class="badge badge-secondary font-weight-normal">General / Account</span>
                  <?php endif; ?>
                  <span class="text-muted d-block small"><?=htmlspecialchars($row['description'] ?? '-')?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($is_due): ?>
                  <span class="badge badge-danger font-weight-normal">Due</span>
                <?php elseif ($row['payment_method'] == 'bank'): ?>
                  <span class="badge badge-info">Bank</span> <?=htmlspecialchars($row['account_name'] ?? '')?>
                <?php else: ?>
                  <span class="badge badge-success">Cash</span>
                <?php endif; ?>
              </td>
              <td class="text-right text-<?=$is_due ? 'danger' : 'success'?> font-weight-bold">PKR <?=formatCurrency($row['amount'])?></td>
              <td class="text-center d-print-none">
                <?php if ($is_due): ?>
                  <a href="#" data-id="<?=$row['customer_id']?>" data-name="<?=htmlspecialchars($row['customer'])?>" class="btn btn-sm btn-outline-success pick-party">
                    <i class="fas fa-hand-holding-usd"></i> Receive
                  </a>
                <?php else: ?>
                  <span class="text-muted">-</span>
                <?php endif; ?>
              </td>
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
  if (bal === 0) { $('#partyBalance').html('<span class="badge badge-secondary">Balance: Settled (PKR 0.00)</span>'); return; }
  $('#partyBalance').html(bal > 0 
    ? '<span class="badge badge-danger">Receivable: PKR ' + bal.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) + '</span>' 
    : '<span class="badge badge-success">Advance: PKR ' + Math.abs(bal).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) + '</span>');
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

var currentInvoices = [];

function loadCustomerInvoices(customerId, targetSaleId){
  var $sel = $('#invoiceSelect');
  $sel.html('<option value="">-- General Account / Opening Balance --</option>');
  $('#invoiceInfo').hide().empty();
  currentInvoices = [];

  if (!customerId) return;

  $.getJSON('ajax_customer_invoices.php', {customer_id: customerId}, function(data){
    currentInvoices = data || [];
    if (currentInvoices.length > 0) {
      $.each(currentInvoices, function(i, inv){
        var due = Number(inv.due_amount || 0);
        var tot = Number(inv.total_amount || 0);
        var label = inv.invoice_no + ' (' + inv.sale_date + ' | Total: ' + tot.toFixed(2) + ' | Due: ' + due.toFixed(2) + ')';
        var opt = $('<option></option>').val(inv.id).text(label).data('invoice', inv);
        $sel.append(opt);
      });
    }

    if (targetSaleId) {
      $sel.val(targetSaleId).trigger('change');
    }
  });
}

$(document).ready(function(){
  var preselectCust = <?= (int)$preselect ?: 0 ?>;
  var preselectSale = <?= (int)$preselect_sale ?: 0 ?>;

  function pickCustomer(id, name, targetSaleId){
    $('#customer_id').val(id);
    $('#customerSearch').val(name);
    $('#customerError').addClass('d-none');
    mtHideList($('#customerList'));
    $.get('ajax_customer_balance.php', {id: id}, function(data){
      mtShowBalance(data);
    });
    loadCustomerInvoices(id, targetSaleId);
  }

  $('#invoiceSelect').change(function(){
    var saleId = $(this).val();
    if (!saleId) {
      $('#invoiceInfo').hide().empty();
      if ($('#receiveDescription').val().indexOf('Payment against Invoice') === 0) {
        $('#receiveDescription').val('Customer payment');
      }
      return;
    }

    var selectedInv = null;
    $.each(currentInvoices, function(i, inv){
      if (inv.id == saleId) { selectedInv = inv; return false; }
    });

    if (selectedInv) {
      var due = Number(selectedInv.due_amount || 0);
      var tot = Number(selectedInv.total_amount || 0);
      var paid = Number(selectedInv.paid_amount || 0);
      $('#invoiceInfo').html('<div class="alert alert-info py-1 px-2 mb-0">' +
        '<strong>Invoice #' + mtEsc(selectedInv.invoice_no) + '</strong> &middot; ' +
        'Total: <strong>PKR ' + tot.toFixed(2) + '</strong> &middot; ' +
        'Paid: <strong>PKR ' + paid.toFixed(2) + '</strong> &middot; ' +
        'Due: <strong class="text-danger">PKR ' + due.toFixed(2) + '</strong>' +
        '</div>').show();

      if (due > 0) {
        $('#receiveAmount').val(due.toFixed(2));
      }
      $('#receiveDescription').val('Payment against Invoice #' + selectedInv.invoice_no);
    }
  });

  $('#payMethod').change(function(){ $('#bankDiv').toggle(this.value === 'bank'); });

  var custTimer = null;
  $('#customerSearch').on('input', function(){
    var q = $.trim(this.value);
    clearTimeout(custTimer);
    if (!q) {
      $('#customer_id').val('');
      $('#partyBalance').empty();
      $('#customerError').addClass('d-none');
      mtHideList($('#customerList'));
      loadCustomerInvoices(0);
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

  // Preselection support from query parameters
  if (preselectCust) {
    <?php $sel = $preselect ? getById('customers', $preselect) : null; ?>
    pickCustomer(preselectCust, <?= json_encode($sel['full_name'] ?? '') ?>, preselectSale);
    $('#receiveModal').modal('show');
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

  $('#tblSearch').on('keyup', function(){
    var q = $(this).val().toLowerCase();
    $('#mergedTable tbody tr').each(function(){
      $(this).toggle($(this).text().toLowerCase().indexOf(q) > -1);
    });
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>