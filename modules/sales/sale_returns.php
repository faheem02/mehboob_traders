<?php
$page_title = 'Sale Returns History';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin', 'order_booker']);

$from = isset($_GET['from']) ? trim($_GET['from']) : '';
$to   = isset($_GET['to']) ? trim($_GET['to']) : '';
$q    = isset($_GET['q']) ? trim($_GET['q']) : '';
$ob_id_filter = isset($_GET['order_booker_id']) ? intval($_GET['order_booker_id']) : 0;
$ob_ids = [];
if ($ob_id_filter > 0) {
    $ob_ids[] = $ob_id_filter;
} elseif (isset($_GET['order_booker_id']) && is_array($_GET['order_booker_id'])) {
    foreach ($_GET['order_booker_id'] as $oid) {
        $oidv = intval($oid);
        if ($oidv > 0) {
            $ob_ids[] = $oidv;
        }
    }
}
$ob_ids = array_values(array_unique($ob_ids));

$bank_accounts = $pdo->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$sql = "
    SELECT 
        sr.*,
        c.full_name AS customer_name,
        c.area AS customer_area,
        s.invoice_no AS original_invoice_no,
        s.created_by AS sale_created_by,
        ob.full_name AS order_taker
    FROM sale_returns sr
    LEFT JOIN customers c ON c.id = sr.customer_id
    LEFT JOIN sales s ON s.id = COALESCE(sr.invoice_id, sr.sale_id)
    LEFT JOIN users ob ON ob.id = s.created_by
    WHERE 1=1
";

$params = [];

if ($from !== '') {
    $sql .= " AND sr.return_date >= ?";
    $params[] = $from;
}
if ($to !== '') {
    $sql .= " AND sr.return_date <= ?";
    $params[] = $to;
}

if (!isAdmin()) {
    $sql .= " AND s.created_by = ?";
    $params[] = (int)$_SESSION['user_id'];
} elseif (!empty($ob_ids)) {
    $placeholders_ob = implode(',', array_fill(0, count($ob_ids), '?'));
    $sql .= " AND s.created_by IN ($placeholders_ob)";
    foreach ($ob_ids as $oid) {
        $params[] = $oid;
    }
}

if ($q !== '') {
    $sql .= " AND (sr.return_no LIKE ? OR c.full_name LIKE ? OR s.invoice_no LIKE ? OR sr.reason LIKE ? OR sr.refund_type LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$sql .= " ORDER BY sr.return_date DESC, sr.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_returns = 0;
$completed_returns = 0;
foreach ($returns as $r) {
    $amt = (float)$r['total_amount'];
    $total_returns += $amt;
    if (strtolower($r['status']) === 'completed') {
        $completed_returns += $amt;
    }
}

$ob_name = '';
if (count($ob_ids) === 1) {
    $on = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $on->execute([$ob_ids[0]]);
    $ob_name = (string)$on->fetchColumn();
}

$period_label = 'All Returns';
$period_desc = 'All dates';
if ($from && $to) {
    $period_label = 'Filtered Returns';
    $period_desc = formatDate($from) . ' to ' . formatDate($to);
} elseif ($from) {
    $period_label = 'Filtered Returns';
    $period_desc = 'From ' . formatDate($from);
} elseif ($to) {
    $period_label = 'Filtered Returns';
    $period_desc = 'Until ' . formatDate($to);
}

$filter_note = [];
if ($ob_name !== '') {
    $filter_note[] = 'Order Taker: ' . $ob_name;
}
if ($q !== '') {
    $filter_note[] = 'Search: ' . $q;
}
$filter_note = $filter_note ? ' &middot; ' . implode(' &middot; ', $filter_note) : '';

$printed_by = '';
if (!empty($_SESSION['user_id'])) {
    $pu = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $pu->execute([(int)$_SESSION['user_id']]);
    $printed_by = (string)$pu->fetchColumn();
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<style>
@media print {
  .report-table tr { page-break-inside: avoid !important; break-inside: avoid !important; }
}
</style>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center d-print-none">
    <h6 class="mb-0"><i class="fas fa-undo text-danger"></i> Sale Returns History (<span id="topHeaderCount"><?= count($returns) ?></span>)</h6>
    <div class="d-flex flex-wrap">
      <?php if (isAdmin()): ?>
      <button type="button" class="btn btn-sm btn-danger mr-2" data-toggle="modal" data-target="#refundInvoiceModal"><i class="fas fa-undo"></i> Refund by Invoice</button>
      <?php endif; ?>
      <a href="invoices.php" class="btn btn-sm btn-outline-primary mr-2"><i class="fas fa-file-invoice"></i> Invoices</a>
      <button type="button" class="btn btn-sm btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    </div>
  </div>
  <div class="card-body">
    <div class="d-none d-print-block mb-3">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <h4 class="mb-0">MEHBOOB TRADERS</h4>
          <div class="text-muted">Sale Returns History</div>
          <div class="small text-muted mt-1"><?= $period_label ?><?= $filter_note ?></div>
        </div>
        <div class="text-right">
          <div class="small">Printed: <?= date('d-M-Y h:i A') ?></div>
          <?php if ($printed_by): ?><div class="small">By: <?= htmlspecialchars($printed_by) ?></div><?php endif; ?>
        </div>
      </div>
      <hr>
    </div>

    <form method="get" class="form-row align-items-end mb-3 d-print-none">
      <div class="form-group col-md-2 col-6">
        <label class="small mb-1">From Date</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars($from) ?>">
      </div>
      <div class="form-group col-md-2 col-6">
        <label class="small mb-1">To Date</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars($to) ?>">
      </div>
      <?php if (isAdmin()): ?>
      <div class="form-group col-md-3 col-12">
        <label class="small mb-1">Order Taker</label>
        <select name="order_booker_id" class="form-control form-control-sm">
          <option value="0">All Order Takers</option>
          <?php
          $obList = $pdo->query("SELECT id, full_name FROM users WHERE role='order_booker' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
          foreach ($obList as $ob) {
              $sel = in_array((int)$ob['id'], $ob_ids) ? 'selected' : '';
              echo '<option value="'.(int)$ob['id'].'" '.$sel.'>'.htmlspecialchars($ob['full_name']).'</option>';
          }
          ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="form-group col-md-3 col-12">
        <label class="small mb-1">Search</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Return No, Invoice, Customer, Reason..." value="<?= htmlspecialchars($q) ?>">
      </div>
      <div class="form-group col-md-2 col-12">
        <div class="d-flex" style="gap: 4px;">
          <button type="submit" class="btn btn-sm btn-primary flex-fill"><i class="fas fa-filter"></i> Apply</button>
          <a href="sale_returns.php" class="btn btn-sm btn-light flex-fill"><i class="fas fa-undo"></i> Reset</a>
        </div>
      </div>
    </form>

    <div class="row mb-3">
      <div class="col-xl-3 col-md-6 col-12 mb-2">
        <div class="card border-left-danger shadow-sm h-100">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Total Returns</div>
            <div class="h5 mb-0 font-weight-bold text-danger">PKR <?= formatCurrency($total_returns) ?></div>
            <div class="small text-muted"><?= count($returns) ?> record(s)</div>
          </div>
        </div>
      </div>
      <div class="col-xl-3 col-md-6 col-12 mb-2">
        <div class="card border-left-success shadow-sm h-100">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Completed Returns</div>
            <div class="h5 mb-0 font-weight-bold text-success">PKR <?= formatCurrency($completed_returns) ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-bordered table-hover report-table">
        <thead class="thead-light">
          <tr>
            <th>Return No</th>
            <th>Return Date</th>
            <th>Original Invoice</th>
            <th>Customer</th>
            <th>Order Taker</th>
            <th>Refund Type</th>
            <th>Status</th>
            <th>Reason</th>
            <th class="text-right">Amount</th>
            <th class="text-center no-print">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($returns)): ?>
          <tr>
            <td colspan="10" class="text-center text-muted py-4">No sale returns found.</td>
          </tr>
          <?php else: ?>
          <?php foreach ($returns as $r): ?>
          <tr>
            <td class="font-weight-bold text-danger"><?= htmlspecialchars($r['return_no']) ?></td>
            <td><?= formatDate($r['return_date']) ?></td>
            <td>
              <?php if (!empty($r['original_invoice_no'])): ?>
                <a href="invoice.php?id=<?= (int)($r['invoice_id'] ?: $r['sale_id']) ?>"><?= htmlspecialchars($r['original_invoice_no']) ?></a>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="font-weight-bold text-dark"><?= htmlspecialchars($r['customer_name'] ?? 'Counter Customer') ?></div>
              <?php if (!empty($r['customer_area'])): ?>
                <span class="badge badge-light border text-muted" style="font-size: 11px;"><i class="fas fa-map-marker-alt text-danger"></i> <?= htmlspecialchars($r['customer_area']) ?></span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($r['order_taker'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['refund_type'] ?: '—') ?></td>
            <td>
              <?php
              $st = strtolower($r['status'] ?? '');
              $badge = 'badge-secondary';
              if ($st === 'completed') $badge = 'badge-success';
              if ($st === 'pending') $badge = 'badge-warning';
              if ($st === 'cancelled') $badge = 'badge-danger';
              ?>
              <span class="badge <?= $badge ?>"><?= htmlspecialchars($r['status'] ?: '—') ?></span>
            </td>
            <td class="text-muted small"><?= htmlspecialchars($r['reason'] ?: '—') ?></td>
            <td class="text-right font-weight-bold text-danger">PKR <?= formatCurrency($r['total_amount']) ?></td>
            <td class="text-center no-print" nowrap>
              <button type="button" class="btn btn-sm btn-outline-info btn-view-items" data-return-id="<?= (int)$r['id'] ?>" data-return-no="<?= htmlspecialchars($r['return_no']) ?>" title="View Items"><i class="fas fa-list"></i> Items</button>
              <?php if (isAdmin()): ?>
                <a href="sale_return_edit.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-warning" title="Edit Sale Return"><i class="fas fa-edit"></i> Edit</a>
              <?php endif; ?>
              <a href="print_return.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-danger" target="_blank" title="View / Print Return Voucher"><i class="fas fa-print"></i> Print</a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
        <?php if (!empty($returns)): ?>
        <tfoot>
          <tr class="font-weight-bold">
            <td colspan="8" class="text-right">Total:</td>
            <td class="text-right text-danger">PKR <?= formatCurrency($total_returns) ?></td>
            <td class="no-print"></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>

    <div class="report-foot d-none d-print-block mt-3">
      <div><strong>Prepared by:</strong> <?= htmlspecialchars($printed_by ?: '—') ?></div>
    </div>
  </div>
</div>

<!-- Modal: View Returned Items -->
<div class="modal fade" id="viewItemsModal" tabindex="-1" role="dialog" aria-labelledby="viewItemsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title font-weight-bold" id="viewItemsModalLabel"><i class="fas fa-list mr-2"></i>Returned Products (<span id="viReturnNo">—</span>)</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body p-0">
        <div id="viItemsContainer" class="table-responsive">
          <div class="p-4 text-center text-muted"><i class="fas fa-spinner fa-spin mr-1"></i> Loading items...</div>
        </div>
      </div>
      <div class="modal-footer bg-light py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php if (isAdmin()): ?>
<!-- Modal: Refund by Invoice -->
<div class="modal fade" id="refundInvoiceModal" tabindex="-1" role="dialog" aria-labelledby="refundInvoiceModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
    <form method="post" action="ajax_sale_return_refund.php" id="refundInvoiceForm" class="modal-content border-0 shadow">
      <input type="hidden" name="save_invoice_return" value="1">
      <input type="hidden" name="return_to" value="sale_returns">
      <input type="hidden" name="customer_id" id="rifCustomerId">
      
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title font-weight-bold" id="refundInvoiceModalLabel">
          <i class="fas fa-undo mr-2"></i>Refund by Invoice
        </h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      
      <div class="modal-body">
        <div class="row">
          <div class="col-md-5 form-group">
            <label class="small font-weight-bold text-muted mb-1">Customer *</label>
            <div class="ac-wrap">
              <input type="text" id="rifCustomerSearch" class="form-control form-control-sm" placeholder="Type customer name, phone, area..." autocomplete="off">
              <div id="rifCustomerList" class="ac-list" style="display:none;"></div>
            </div>
            <div id="rifSelectedCustomerName" class="small text-success font-weight-bold mt-1"></div>
          </div>
          <div class="col-md-4 form-group">
            <label class="small font-weight-bold text-muted mb-1">Select Invoice *</label>
            <select name="invoice_id" id="rifInvoiceSelect" class="form-control form-control-sm" disabled>
              <option value="">-- Choose Customer First --</option>
            </select>
          </div>
          <div class="col-md-3 form-group">
            <label class="small font-weight-bold text-muted mb-1">Return Date</label>
            <input type="date" name="return_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
          </div>
        </div>

        <div id="rifInvoiceAlert" class="alert alert-info py-2 small d-none">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <strong>Invoice: </strong><span id="rifInvNo">—</span> | 
              <strong>Date: </strong><span id="rifInvDate">—</span> | 
              <strong>Total: </strong>PKR <span id="rifInvTotal">0.00</span> | 
              <strong>Remaining Due: </strong>PKR <span id="rifInvDue">0.00</span>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" id="rifBtnReturnAll">Return All Items</button>
          </div>
        </div>

        <div id="rifItemsWrapper" class="border rounded mb-3" style="min-height: 120px; max-height: 280px; overflow-y: auto;">
          <div class="p-4 text-center text-muted"><i class="fas fa-info-circle mr-1"></i> Select a customer and invoice to view refundable products.</div>
        </div>

        <div class="row">
          <div class="col-md-7 form-group mb-0">
            <label class="small font-weight-bold text-muted mb-1">Return Reason <small class="text-muted">(Optional)</small></label>
            <textarea name="reason" class="form-control form-control-sm" rows="2" placeholder="e.g. Defective stock, expired, customer over-ordered"></textarea>
          </div>
          <div class="col-md-5 form-group mb-0">
            <label class="small font-weight-bold text-muted mb-1">Refund Method</label>
            <select name="refund_type" id="rifRefundType" class="form-control form-control-sm">
              <option value="Credit Note">Credit Note (Reduce Customer Balance)</option>
              <option value="Cash Refund">Cash Refund</option>
              <option value="Bank Refund">Bank Refund</option>
            </select>
            <select name="bank_account_id" id="rifBankSelect" class="form-control form-control-sm mt-2 d-none">
              <?php foreach ($bank_accounts as $ba): ?>
              <option value="<?= $ba['id'] ?>"><?= htmlspecialchars($ba['account_name'] . ' (' . $ba['bank_name'] . ')') ?></option>
              <?php endforeach; ?>
            </select>
            <div class="text-right mt-2">
              <span class="small text-muted font-weight-bold">Total Refund:</span>
              <span class="h5 font-weight-bold text-danger ml-1" id="rifTotalAmount">PKR 0.00</span>
            </div>
          </div>
        </div>
      </div>
      
      <div class="modal-footer bg-light py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-danger btn-sm font-weight-bold" id="rifSubmitBtn" disabled><i class="fas fa-check mr-1"></i> Save Refund</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function(){
  function esc(s){ return $('<div>').text(s||'').html(); }
  function formatMoney(num) {
    return 'PKR ' + Number(num || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }

  // ===== VIEW ITEMS MODAL =====
  $('.btn-view-items').on('click', function(){
    var retId = $(this).data('return-id');
    var retNo = $(this).data('return-no');
    $('#viReturnNo').text(retNo);
    $('#viItemsContainer').html('<div class="p-4 text-center text-muted"><i class="fas fa-spinner fa-spin mr-1"></i> Loading items...</div>');
    $('#viewItemsModal').modal('show');

    fetch('ajax_sale_return_refund.php?action=return_items&return_id=' + encodeURIComponent(retId))
      .then(function(res){ return res.json(); })
      .then(function(items){
        if (!items || !items.length) {
          $('#viItemsContainer').html('<div class="p-4 text-center text-muted">No items recorded for this return.</div>');
          return;
        }
        var html = '<table class="table table-sm table-hover mb-0"><thead class="thead-light"><tr>' +
          '<th>Product</th><th class="text-center">Returned Qty</th><th class="text-right">Unit Rate</th><th class="text-right">Total</th><th>Condition</th></tr></thead><tbody>';
        var total = 0;
        items.forEach(function(it){
          var line = parseFloat(it.total_price) || 0;
          total += line;
          var cond = it.condition || 'Good / Resalable';
          var isGood = (cond.indexOf('Good') !== -1);
          var badge = isGood ? '<span class="badge badge-success">Good (Restocked)</span>' : '<span class="badge badge-danger">Damaged / Expiry</span>';
          html += '<tr>' +
            '<td><strong>' + esc(it.product_name || 'Product') + '</strong><small class="text-muted d-block">' + esc(it.product_code || '') + '</small></td>' +
            '<td class="text-center font-weight-bold text-danger">' + (parseInt(it.quantity, 10) || 0) + ' Boxes</td>' +
            '<td class="text-right">PKR ' + (parseFloat(it.unit_price) || 0).toFixed(2) + '</td>' +
            '<td class="text-right font-weight-bold text-dark">PKR ' + line.toFixed(2) + '</td>' +
            '<td>' + badge + '</td>' +
          '</tr>';
        });
        html += '</tbody><tfoot class="thead-light"><tr><th colspan="3" class="text-right">Total:</th><th class="text-right font-weight-bold text-danger">PKR ' + total.toFixed(2) + '</th><th></th></tr></tfoot></table>';
        $('#viItemsContainer').html(html);
      })
      .catch(function(){
        $('#viItemsContainer').html('<div class="p-4 text-center text-danger">Failed to load return items.</div>');
      });
  });

  // ===== REFUND BY INVOICE MODAL =====
  var customerSearchTimer = null;
  var $custSearch = $('#rifCustomerSearch');
  var $custList = $('#rifCustomerList');

  $custSearch.on('input', function(){
    var q = $.trim($(this).val());
    clearTimeout(customerSearchTimer);
    if (q.length < 1) {
      $custList.hide().empty();
      return;
    }
    customerSearchTimer = setTimeout(function(){
      fetch('ajax_sale_return_refund.php?action=search_customer&q=' + encodeURIComponent(q))
        .then(function(res){ return res.json(); })
        .then(function(customers){
          $custList.empty();
          if (!customers || !customers.length) {
            $custList.html('<div class="ac-item ac-empty">No customers found</div>').show();
            return;
          }
          customers.forEach(function(c){
            var subParts = [];
            if (c.customer_no) subParts.push(c.customer_no);
            if (c.area) subParts.push(c.area);
            if (c.phone) subParts.push(c.phone);
            var sub = subParts.join(' &middot; ');
            var $item = $('<div class="ac-item">')
              .attr('data-id', c.id)
              .attr('data-name', c.full_name)
              .html('<div class="ac-name">' + esc(c.full_name) + '</div>' + (sub ? '<div class="ac-sub">' + esc(sub) + '</div>' : ''));
            $custList.append($item);
          });
          $custList.show();
        });
    }, 250);
  });

  $custList.on('mousedown click', '.ac-item:not(.ac-empty)', function(e){
    e.preventDefault();
    var cid = $(this).attr('data-id');
    var cname = $(this).attr('data-name');
    $('#rifCustomerId').val(cid);
    $custSearch.val(cname);
    $('#rifSelectedCustomerName').html('<i class="fas fa-check-circle mr-1"></i> ' + esc(cname));
    $custList.hide().empty();
    loadCustomerInvoices(cid);
  });

  $(document).on('click', function(e){
    if (!$(e.target).closest('.ac-wrap').length) {
      $custList.hide();
    }
  });

  function loadCustomerInvoices(customerId) {
    var $sel = $('#rifInvoiceSelect');
    $sel.prop('disabled', true).html('<option value="">Loading invoices...</option>');
    $('#rifInvoiceAlert').addClass('d-none');
    $('#rifItemsWrapper').html('<div class="p-4 text-center text-muted"><i class="fas fa-info-circle mr-1"></i> Choose an invoice above.</div>');
    $('#rifSubmitBtn').prop('disabled', true);
    $('#rifTotalAmount').text('PKR 0.00');

    fetch('ajax_sale_return_refund.php?action=customer_invoices&customer_id=' + encodeURIComponent(customerId))
      .then(function(res){ return res.json(); })
      .then(function(invoices){
        if (!invoices || !invoices.length) {
          $sel.html('<option value="">No sales found for this customer</option>');
          return;
        }
        var optHtml = '<option value="">-- Select an Invoice (' + invoices.length + ') --</option>';
        invoices.forEach(function(inv){
          optHtml += '<option value="' + inv.id + '">' + esc(inv.invoice_no) + ' (' + inv.sale_date + ') - PKR ' + (parseFloat(inv.total_amount)||0).toFixed(2) + '</option>';
        });
        $sel.html(optHtml).prop('disabled', false);
      })
      .catch(function(){
        $sel.html('<option value="">Error loading invoices</option>');
      });
  }

  $('#rifInvoiceSelect').on('change', function(){
    var invId = $(this).val();
    if (!invId) {
      $('#rifInvoiceAlert').addClass('d-none');
      $('#rifItemsWrapper').html('<div class="p-4 text-center text-muted"><i class="fas fa-info-circle mr-1"></i> Select an invoice.</div>');
      $('#rifSubmitBtn').prop('disabled', true);
      $('#rifTotalAmount').text('PKR 0.00');
      return;
    }

    $('#rifItemsWrapper').html('<div class="p-4 text-center text-muted"><i class="fas fa-spinner fa-spin mr-1"></i> Loading invoice items...</div>');
    fetch('ajax_sale_return_refund.php?action=invoice_details&invoice_id=' + encodeURIComponent(invId))
      .then(function(res){ return res.json(); })
      .then(function(data){
        var inv = data.invoice;
        var items = data.items || [];
        if (inv) {
          $('#rifInvNo').text(inv.invoice_no);
          $('#rifInvDate').text(inv.sale_date);
          $('#rifInvTotal').text((parseFloat(inv.total_amount)||0).toFixed(2));
          $('#rifInvDue').text((parseFloat(inv.due_amount)||0).toFixed(2));
          $('#rifInvoiceAlert').removeClass('d-none');
        }

        if (!items.length) {
          $('#rifItemsWrapper').html('<div class="p-4 text-center text-danger">No items found for this invoice.</div>');
          $('#rifSubmitBtn').prop('disabled', true);
          return;
        }

        var html = '<table class="table table-sm table-hover mb-0"><thead class="thead-light"><tr>' +
          '<th>Product</th><th class="text-center">Sold</th><th class="text-center">Returned</th>' +
          '<th style="width:120px" class="text-center">Return Qty</th><th class="text-right">Rate</th><th style="width:160px">Condition</th></tr></thead><tbody>';
        
        items.forEach(function(item, idx){
          var max = parseInt(item.returnable_qty, 10) || 0;
          var price = parseFloat(item.unit_price) || 0;
          html += '<tr><td><strong>' + esc(item.product_name || 'Product') + '</strong><small class="text-muted d-block">' + esc(item.product_code || '') + '</small>' +
            '<input type="hidden" name="inv_items[' + idx + '][product_id]" value="' + item.product_id + '">' +
            '<input type="hidden" name="inv_items[' + idx + '][unit_price]" value="' + price + '"></td>' +
            '<td class="text-center">' + (parseInt(item.sold_qty, 10) || 0) + '</td><td class="text-center text-muted">' + (parseInt(item.returned_qty, 10) || 0) + '</td>' +
            '<td><input type="number" name="inv_items[' + idx + '][quantity]" class="form-control form-control-sm text-center rif-item-qty" value="0" min="0" max="' + max + '" data-price="' + price + '"' + (max <= 0 ? ' disabled' : '') + '></td>' +
            '<td class="text-right">PKR ' + price.toFixed(2) + '</td>' +
            '<td><select name="inv_items[' + idx + '][condition]" class="form-control form-control-sm"><option value="Good / Resalable">Good (Restock)</option><option value="Damaged / Expiry Claim">Damaged / Expiry</option></select></td></tr>';
        });
        html += '</tbody></table>';
        $('#rifItemsWrapper').html(html);
        updateRifTotal();
      })
      .catch(function(){
        $('#rifItemsWrapper').html('<div class="p-4 text-center text-danger">Failed to load invoice items.</div>');
      });
  });

  function updateRifTotal() {
    var total = 0;
    $('#rifItemsWrapper .rif-item-qty').each(function(){
      var q = parseInt($(this).val(), 10) || 0;
      var p = parseFloat($(this).data('price')) || 0;
      total += (q * p);
    });
    $('#rifTotalAmount').text(formatMoney(total));
    $('#rifSubmitBtn').prop('disabled', (total <= 0));
    return total;
  }

  $(document).on('input change', '.rif-item-qty', function(){
    var max = parseInt($(this).attr('max'), 10) || 0;
    var cur = parseInt($(this).val(), 10) || 0;
    if (cur > max) $(this).val(max);
    if (cur < 0) $(this).val(0);
    updateRifTotal();
  });

  $('#rifBtnReturnAll').on('click', function(e){
    e.preventDefault();
    $('#rifItemsWrapper .rif-item-qty').each(function(){
      var max = parseInt($(this).attr('max'), 10) || 0;
      $(this).val(max);
    });
    updateRifTotal();
  });

  $('#rifRefundType').on('change', function(){
    if ($(this).val() === 'Bank Refund') {
      $('#rifBankSelect').removeClass('d-none').prop('required', true);
    } else {
      $('#rifBankSelect').addClass('d-none').prop('required', false);
    }
  });

  $('#refundInvoiceForm').on('submit', function(e){
    var total = updateRifTotal();
    if (total <= 0) {
      e.preventDefault();
      alert('Please enter return quantity greater than 0.');
    }
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
