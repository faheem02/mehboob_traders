<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Invoices (Sales)';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$sup = $_GET['salesman_id'] ?? '';
$ob = $_GET['order_booker_id'] ?? '';
$area = trim($_GET['area'] ?? '');
$q = trim($_GET['q'] ?? '');
$area_options = isAdmin() ? allKnownAreas($pdo) : (array)currentUserAreas($pdo);
if ($area !== '' && !in_array($area, $area_options, true)) { $area = ''; }

$sql = "SELECT s.*, c.full_name, c.area AS customer_area, e.full_name AS salesman_name, u.full_name AS order_taker,
        (SELECT COALESCE(SUM(p.purchase_price * si.quantity / GREATEST(COALESCE(p.boxes_per_carton,1),1)), 0)
         FROM sale_items si JOIN products p ON si.product_id = p.id WHERE si.sale_id = s.id) AS total_cost
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN employees e ON s.salesman_id = e.id
        LEFT JOIN users u ON s.created_by = u.id
        WHERE 1=1";
$params = [];
if ($from) { $sql .= " AND COALESCE(s.delivery_date, s.sale_date) >= ?"; $params[] = $from; }
if ($to) { $sql .= " AND COALESCE(s.delivery_date, s.sale_date) <= ?"; $params[] = $to; }
if ($sup !== '') { $sql .= " AND s.salesman_id = ?"; $params[] = $sup; }
if ($area !== '') { $sql .= " AND LOWER(c.area) = LOWER(?)"; $params[] = $area; }
if (!isAdmin()) {
    $sql .= " AND s.created_by = ?";
    $params[] = (int)$_SESSION['user_id'];
} elseif ($ob !== '') {
    $sql .= " AND s.created_by = ?";
    $params[] = $ob;
}
if ($q !== '') {
    $sql .= " AND (s.invoice_no LIKE ? OR c.full_name LIKE ? OR e.full_name LIKE ? OR u.full_name LIKE ? OR c.area LIKE ?)";
    $params = array_merge($params, ["%$q%", "%$q%", "%$q%", "%$q%", "%$q%"]);
}
$sql .= " ORDER BY COALESCE(s.delivery_date, s.sale_date) DESC, s.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

$total_sales = 0; $total_paid = 0; $total_due = 0;
foreach ($sales as $s) { if ($s['status'] != 'cancelled') { $total_sales += $s['total_amount']; $total_paid += $s['paid_amount']; $total_due += $s['due_amount']; } }

$sales_name = '';
if ($sup !== '') {
    $sn = $pdo->prepare("SELECT full_name FROM employees WHERE id = ?");
    $sn->execute([$sup]);
    $sales_name = (string)$sn->fetchColumn();
}

$ob_name = '';
if ($ob !== '' && isAdmin()) {
    $on = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $on->execute([$ob]);
    $ob_name = (string)$on->fetchColumn();
}

$period_label = 'All invoices';
$period_desc = 'All dates';
if ($from && $to) { $period_label = 'Filtered invoices'; $period_desc = formatDate($from) . ' to ' . formatDate($to); }
elseif ($from) { $period_label = 'Filtered invoices'; $period_desc = 'From ' . formatDate($from); }
elseif ($to) { $period_label = 'Filtered invoices'; $period_desc = 'Until ' . formatDate($to); }
$filter_note = [];
if ($sup !== '') $filter_note[] = 'Salesman: ' . $sales_name;
if ($ob !== '' && $ob_name) $filter_note[] = 'Order taker: ' . $ob_name;
if ($area !== '') $filter_note[] = 'Area: ' . $area;
if ($q !== '') $filter_note[] = 'Search: ' . $q;
$filter_note = $filter_note ? ' &middot; ' . implode(' &middot; ', $filter_note) : '';
$printed_by = '';
if (!empty($_SESSION['user_id'])) {
    $pu = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $pu->execute([(int)$_SESSION['user_id']]);
    $printed_by = (string)$pu->fetchColumn();
}
$sum_qs = '';
$sum_parts = [];
if ($from) $sum_parts[] = 'from=' . urlencode($from);
if ($to) $sum_parts[] = 'to=' . urlencode($to);
if ($sup !== '') $sum_parts[] = 'salesman_id=' . urlencode($sup);
if ($ob !== '') $sum_parts[] = 'order_booker_id=' . urlencode($ob);
if ($area !== '') $sum_parts[] = 'area=' . urlencode($area);
if ($q !== '') $sum_parts[] = 'q=' . urlencode($q);
if ($sum_parts) $sum_qs = '?' . implode('&', $sum_parts);
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center d-print-none">
    <h6><i class="fas fa-file-invoice"></i> Sales / Invoices (<span id="topHeaderCount"><?=count($sales)?></span>)</h6>
    <div class="d-flex flex-wrap">
      <a href="index.php" class="btn btn-sm btn-success mr-2"><i class="fas fa-plus"></i> New Sale</a>
      <?php if (isAdmin()): ?>
      <a href="order_booker_invoices.php" class="btn btn-sm btn-outline-info mr-2"><i class="fas fa-user-tag"></i> Order Booker Invoices</a>
      <?php endif; ?>
      <a href="customer_summary.php<?=$sum_qs?>" class="btn btn-sm btn-outline-success mr-2"><i class="fas fa-chart-bar"></i> Summary</a>
      <button type="button" class="btn btn-sm btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    </div>
  </div>
  <div class="card-body">

    <div class="report-sheet d-none d-print-block">
      <div class="report-head">
        <div class="report-brand-line">
          <div class="report-brand">
            <div class="report-brand-name">Mehboob Traders</div>
            <div class="report-brand-sub">Wholesale Business &middot; Lahore, Pakistan &middot; GST No: --</div>
          </div>
          <div class="report-title-box">
            <div class="report-title">Sale Invoices Record</div>
            <div class="report-meta"><?=$period_label?>: <?=$period_desc?><?=$filter_note?></div>
          </div>
        </div>
      </div>

      <table class="report-summary-table">
        <tr>
          <td class="rs-cell"><span class="rs-label">Total Invoices</span><span class="rs-val"><?=count($sales)?></span></td>
          <td class="rs-cell"><span class="rs-label">Total Sales</span><span class="rs-val">PKR <?=formatCurrency($total_sales)?></span></td>
          <td class="rs-cell"><span class="rs-label">Total Paid</span><span class="rs-val" style="color:#0f766e;">PKR <?=formatCurrency($total_paid)?></span></td>
          <td class="rs-cell"><span class="rs-label">Total Due</span><span class="rs-val" style="color:#b91c1c;">PKR <?=formatCurrency($total_due)?></span></td>
        </tr>
      </table>
    </div>

    <!-- Filters & Live Search Bar -->
    <div class="card bg-light border shadow-sm mb-3 d-print-none">
      <div class="card-body py-3 px-3">
        <form method="get" id="filterForm" class="row g-2 align-items-end">
          <div class="col-lg-3 col-md-6 mb-2 mb-lg-0">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="fas fa-search text-primary"></i> Search</label>
            <div class="input-group">
              <input type="text" id="liveInvoiceSearch" name="q" class="form-control" style="height: 38px;" placeholder="Search invoice #, customer..." value="<?=htmlspecialchars($q)?>" autocomplete="off" autocorrect="off" spellcheck="false">
              <div class="input-group-append">
                <button type="button" class="btn btn-outline-secondary bg-white" id="clearSearchBtn" title="Clear Search" <?= $q === '' ? 'style="display:none;"' : '' ?>><i class="fas fa-times"></i></button>
              </div>
            </div>
          </div>
          <div class="col-lg-2 col-md-3 col-6 mb-2 mb-lg-0">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="far fa-calendar-alt"></i> From Date</label>
            <input type="date" name="from" class="form-control" style="height: 38px;" value="<?=htmlspecialchars($from)?>" onchange="document.getElementById('filterForm').submit();">
          </div>
          <div class="col-lg-2 col-md-3 col-6 mb-2 mb-lg-0">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="far fa-calendar-alt"></i> To Date</label>
            <input type="date" name="to" class="form-control" style="height: 38px;" value="<?=htmlspecialchars($to)?>" onchange="document.getElementById('filterForm').submit();">
          </div>
          <div class="col-lg-2 col-md-4 col-6 mb-2 mb-lg-0">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="fas fa-map-marker-alt text-danger"></i> Area</label>
            <select name="area" class="form-control" style="height: 38px;" onchange="document.getElementById('filterForm').submit();">
              <option value="">-- All Areas --</option>
              <?php foreach ($area_options as $ar): ?>
              <option value="<?=htmlspecialchars($ar)?>" <?= $area === $ar ? 'selected' : '' ?>><?=htmlspecialchars($ar)?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-lg-3 col-md-8 col-6 mb-2 mb-lg-0">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="fas fa-cog text-secondary"></i> Actions</label>
            <div class="d-flex align-items-center w-100">
              <?php if ($from || $to || $area || $q): ?>
              <a href="invoices.php" class="btn btn-outline-danger mr-2 text-nowrap" style="height: 38px; line-height: 24px; padding: 6px 14px;" title="Reset All Filters"><i class="fas fa-undo"></i> Reset</a>
              <?php endif; ?>
              <a href="packlist.php<?= $from ? '?from=' . urlencode($from) : '' ?><?= $to ? ($from ? '&' : '?') . 'to=' . urlencode($to) : '' ?><?= $area ? ($from || $to ? '&' : '?') . 'area=' . urlencode($area) : '' ?>" class="btn btn-success flex-grow-1 text-nowrap" style="height: 38px; line-height: 24px; padding: 6px 14px;" target="_blank" title="Print Delivery List"><i class="fas fa-print mr-1"></i> Delivery List</a>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Live KPI Summary Stats Row -->
    <div class="row g-2 mb-3 d-print-none">
      <div class="col-xl-3 col-md-6 col-6 mb-2 mb-xl-0">
        <div class="card border-left-primary shadow-sm h-100">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Invoices</div>
            <div class="h5 mb-0 font-weight-bold text-gray-800" id="statCount"><?=count($sales)?> Invoices</div>
          </div>
        </div>
      </div>
      <div class="col-xl-3 col-md-6 col-6 mb-2 mb-xl-0">
        <div class="card border-left-info shadow-sm h-100">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Sales</div>
            <div class="h5 mb-0 font-weight-bold text-primary" id="statTotalSales">PKR <?=formatCurrency($total_sales)?></div>
          </div>
        </div>
      </div>
      <div class="col-xl-3 col-md-6 col-6 mb-2 mb-xl-0">
        <div class="card border-left-success shadow-sm h-100">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Paid</div>
            <div class="h5 mb-0 font-weight-bold text-success" id="statTotalPaid">PKR <?=formatCurrency($total_paid)?></div>
          </div>
        </div>
      </div>
      <div class="col-xl-3 col-md-6 col-6 mb-2 mb-xl-0">
        <div class="card border-left-danger shadow-sm h-100">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Total Due</div>
            <div class="h5 mb-0 font-weight-bold text-danger" id="statTotalDue">PKR <?=formatCurrency($total_due)?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-bordered table-hover report-table">
        <thead>
          <tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Order Taker</th><th>Salesman</th><th class="text-right">Total</th><th class="text-right">Paid</th><th class="text-right">Due</th><th class="no-print">Action</th></tr>
        </thead>
        <tbody id="invoiceTableBody">
          <?php foreach ($sales as $s): ?>
          <tr class="invoice-row"
              data-invoice="<?=htmlspecialchars(strtolower($s['invoice_no']))?>"
              data-customer="<?=htmlspecialchars(strtolower($s['full_name'] ?? ''))?>"
              data-ordertaker="<?=htmlspecialchars(strtolower($s['order_taker'] ?? ''))?>"
              data-salesman="<?=htmlspecialchars(strtolower($s['salesman_name'] ?? ''))?>"
              data-area="<?=htmlspecialchars(strtolower($s['customer_area'] ?? ''))?>"
              data-total="<?=(float)$s['total_amount']?>"
              data-paid="<?=(float)$s['paid_amount']?>"
              data-due="<?=(float)$s['due_amount']?>"
              data-status="<?=$s['status']?>">
            <td class="font-weight-bold"><a href="invoice.php?id=<?=$s['id']?>"><?=htmlspecialchars($s['invoice_no'])?></a></td>
            <td>
              <div class="font-weight-bold text-dark"><i class="fas fa-truck text-primary mr-1" style="font-size: 0.75rem;"></i> <?=formatDate($s['delivery_date'] ?: $s['sale_date'])?></div>
              <?php if (!empty($s['delivery_date']) && $s['delivery_date'] !== $s['sale_date']): ?>
                <div class="text-muted small" style="font-size: 0.75rem;">Booked: <?=formatDate($s['sale_date'])?></div>
              <?php endif; ?>
            </td>
            <td>
              <?=htmlspecialchars($s['full_name'] ?? 'N/A')?>
              <?php if (!empty($s['customer_area'])): ?>
                <br><span class="badge badge-light border text-muted" style="font-size: 11px;"><i class="fas fa-map-marker-alt text-danger"></i> <?=htmlspecialchars($s['customer_area'])?></span>
              <?php endif; ?>
            </td>
            <td><?=htmlspecialchars($s['order_taker'] ?? '—')?></td>
            <td><?=htmlspecialchars($s['salesman_name'] ?? '—')?></td>
            <td class="text-right">PKR <?=formatCurrency($s['total_amount'])?></td>
            <td class="text-right text-success">PKR <?=formatCurrency($s['paid_amount'])?></td>
            <td class="text-right <?= $s['due_amount'] > 0 ? 'text-danger font-weight-bold' : 'text-success'?>">PKR <?=formatCurrency($s['due_amount'])?></td>
            <td class="text-center no-print" nowrap>
              <a href="invoice.php?id=<?=$s['id']?>" class="btn btn-sm btn-outline-primary" title="View Invoice"><i class="fas fa-eye"></i></a>
              <?php if (isAdmin()): ?>
              <?php if ($s['due_amount'] > 0): ?>
              <a href="../transactions/receive_customer.php?customer_id=<?=$s['customer_id']?>&sale_id=<?=$s['id']?>" class="btn btn-sm btn-outline-success" title="Receive Payment for Invoice #<?=htmlspecialchars($s['invoice_no'])?>"><i class="fas fa-money-bill-wave"></i></a>
              <?php endif; ?>
              <?php endif; ?>
              <a href="sale_edit.php?id=<?=$s['id']?>" class="btn btn-sm btn-outline-warning" title="Edit Sale"><i class="fas fa-edit"></i></a>
              <form method="post" action="sale_delete.php" class="d-inline" onsubmit="return confirm('Delete this sale? This will reverse stock &amp; payments.');">
                <input type="hidden" name="id" value="<?=$s['id']?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Sale"><i class="fas fa-trash-alt"></i></button>
              </form>
              <a href="invoice.php?id=<?=$s['id']?>" class="btn btn-sm btn-outline-success" title="Print Invoice" target="_blank"><i class="fas fa-print"></i></a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($sales)): ?>
          <tr id="emptySalesRow"><td colspan="9" class="text-center text-muted py-4">No sales found. <a href="index.php">Make your first sale</a></td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot class="report-tfoot">
          <tr>
            <td colspan="5" id="tfootCount">TOTAL (<?=count($sales)?> invoices)</td>
            <td class="text-right" id="tfootTotalSales">PKR <?=formatCurrency($total_sales)?></td>
            <td class="text-right" id="tfootTotalPaid">PKR <?=formatCurrency($total_paid)?></td>
            <td class="text-right" id="tfootTotalDue">PKR <?=formatCurrency($total_due)?></td>
            <td class="no-print"></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="report-foot d-none d-print-block">
      <div><strong>Prepared by:</strong> <?=htmlspecialchars($printed_by ?: '—')?></div>
      <div><strong>Printed on:</strong> <?=date('d-m-Y H:i')?></div>
      <div>Mehboob Traders &middot; Sale Invoices Record</div>
    </div>
  </div>
</div>

<script>
$(document).ready(function(){
  function esc(s){ return $('<div>').text(s||'').html(); }

  // ===== LIVE INSTANT INVOICE SEARCH =====
  function formatMoney(num) {
    return 'PKR ' + Number(num || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }

  function filterInvoices() {
    var raw = $('#liveInvoiceSearch').val() || '';
    var q = $.trim(raw).toLowerCase();
    
    if (q.length > 0) {
      $('#clearSearchBtn').show();
    } else {
      $('#clearSearchBtn').hide();
    }

    var terms = q.split(/\s+/).filter(Boolean);
    var visibleCount = 0;
    var totalSales = 0;
    var totalPaid = 0;
    var totalDue = 0;

    $('.invoice-row').each(function(){
      var $tr = $(this);
      var hay = (
        ($tr.data('invoice') || '') + ' ' +
        ($tr.data('customer') || '') + ' ' +
        ($tr.data('ordertaker') || '') + ' ' +
        ($tr.data('salesman') || '') + ' ' +
        ($tr.data('area') || '') + ' ' +
        $tr.text()
      ).toLowerCase();

      var match = true;
      for (var i = 0; i < terms.length; i++) {
        if (hay.indexOf(terms[i]) === -1) {
          match = false;
          break;
        }
      }

      if (match) {
        $tr.show();
        visibleCount++;
        if ($tr.data('status') !== 'cancelled') {
          totalSales += parseFloat($tr.data('total')) || 0;
          totalPaid += parseFloat($tr.data('paid')) || 0;
          totalDue += parseFloat($tr.data('due')) || 0;
        }
      } else {
        $tr.hide();
      }
    });

    if (visibleCount === 0 && $('.invoice-row').length > 0) {
      if ($('#noLiveMatchRow').length === 0) {
        $('#invoiceTableBody').append('<tr id="noLiveMatchRow"><td colspan="9" class="text-center text-muted py-4"><i class="fas fa-search mr-1"></i> No matching invoices found for "<strong>' + esc(q) + '</strong>"</td></tr>');
      } else {
        $('#noLiveMatchRow').html('<td colspan="9" class="text-center text-muted py-4"><i class="fas fa-search mr-1"></i> No matching invoices found for "<strong>' + esc(q) + '</strong>"</td>').show();
      }
    } else {
      $('#noLiveMatchRow').hide();
    }

    // Update real-time counters & totals
    $('#statCount').text(visibleCount + ' Invoices');
    $('#topHeaderCount').text(visibleCount);
    $('#statTotalSales, #tfootTotalSales').text(formatMoney(totalSales));
    $('#statTotalPaid, #tfootTotalPaid').text(formatMoney(totalPaid));
    $('#statTotalDue, #tfootTotalDue').text(formatMoney(totalDue));
    $('#tfootCount').text('TOTAL (' + visibleCount + ' invoices)');
  }

  $('#liveInvoiceSearch').on('input keyup paste change', filterInvoices);
  $('#liveInvoiceSearch').on('keydown', function(e){
    if (e.key === 'Enter') {
      e.preventDefault();
      filterInvoices();
    }
  });
  $('#clearSearchBtn').on('click', function(){
    $('#liveInvoiceSearch').val('').focus();
    filterInvoices();
  });

  // Run once on load if initial search term exists
  if ($.trim($('#liveInvoiceSearch').val())) {
    filterInvoices();
  }
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>