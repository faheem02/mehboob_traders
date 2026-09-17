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
$area_options = isAdmin() ? allKnownAreas($pdo) : (array)currentUserAreas($pdo);
if ($area !== '' && !in_array($area, $area_options, true)) { $area = ''; }

$sql = "SELECT s.*, c.full_name, e.full_name AS salesman_name, u.full_name AS order_taker,
        (SELECT COALESCE(SUM(p.purchase_price * si.quantity / GREATEST(COALESCE(p.boxes_per_carton,1),1)), 0)
         FROM sale_items si JOIN products p ON si.product_id = p.id WHERE si.sale_id = s.id) AS total_cost
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN employees e ON s.salesman_id = e.id
        LEFT JOIN users u ON s.created_by = u.id
        WHERE 1=1";
$params = [];
if ($from) { $sql .= " AND s.sale_date >= ?"; $params[] = $from; }
if ($to) { $sql .= " AND s.sale_date <= ?"; $params[] = $to; }
if ($sup !== '') { $sql .= " AND s.salesman_id = ?"; $params[] = $sup; }
if ($ob !== '' && isAdmin()) { $sql .= " AND s.created_by = ?"; $params[] = $ob; }
if ($area !== '') { $sql .= " AND LOWER(c.area) = LOWER(?)"; $params[] = $area; }
if (!isAdmin()) {
    $sql .= " AND s.created_by = ?";
    $params[] = $_SESSION['user_id'];
}
$sql .= " ORDER BY s.id DESC";
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
if ($sum_parts) $sum_qs = '?' . implode('&', $sum_parts);
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center d-print-none">
    <h6><i class="fas fa-file-invoice"></i> Sales / Invoices (<?=count($sales)?>)</h6>
    <div class="d-flex flex-wrap">
      <a href="index.php" class="btn btn-sm btn-success mr-2"><i class="fas fa-plus"></i> New Sale</a>
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

    <form method="get" class="row g-2 mb-3 d-print-none">
      <div class="col-md-2">
        <input type="date" name="from" class="form-control datepicker" value="<?=htmlspecialchars($from)?>" placeholder="From">
      </div>
      <div class="col-md-2">
        <input type="date" name="to" class="form-control datepicker" value="<?=htmlspecialchars($to)?>" placeholder="To">
      </div>
      <div class="col-md-2">
        <select name="area" class="form-control">
          <option value="">-- All Areas --</option>
          <?php foreach ($area_options as $ar): ?>
          <option value="<?=htmlspecialchars($ar)?>" <?= $area === $ar ? 'selected' : '' ?>><?=htmlspecialchars($ar)?></option>
          <?php endforeach; ?>
        </select>
        <small class="text-muted">Filter by area</small>
      </div>
      <?php if (isAdmin()): ?>
      <div class="col-md-2">
        <div class="ac-wrap">
          <input type="text" id="obSearch" class="form-control" placeholder="Order taker..." autocomplete="off" value="<?=htmlspecialchars($ob_name)?>">
          <input type="hidden" name="order_booker_id" id="order_booker_id" value="<?=htmlspecialchars($ob)?>">
          <div class="ac-list" id="obList"></div>
        </div>
        <small class="text-muted">Filter by order taker</small>
      </div>
      <?php endif; ?>
      <div class="col-md-2">
        <div class="ac-wrap">
          <input type="text" id="salesmanSearch" class="form-control" placeholder="Search salesman..." autocomplete="off" value="<?=htmlspecialchars($sales_name)?>">
          <input type="hidden" name="salesman_id" id="salesman_id" value="<?=htmlspecialchars($sup)?>">
          <div class="ac-list" id="salesmanList"></div>
        </div>
        <small class="text-muted"><?= $sup !== '' ? '<i class="fas fa-filter"></i> Filtered by salesman' : 'Filter by salesman (optional)' ?></small>
      </div>
      <div class="col-md-2">
        <button class="btn btn-outline-primary btn-block"><i class="fas fa-filter"></i> Filter</button>
      </div>
      <div class="col-md-2">
        <?php if ($area !== ''): ?>
        <a href="packlist.php?area=<?=urlencode($area)?><?= $from ? '&from=' . urlencode($from) : '' ?><?= $to ? '&to=' . urlencode($to) : '' ?>" class="btn btn-success btn-block" target="_blank"><i class="fas fa-print"></i> Delivery List</a>
        <?php elseif ($sup !== ''): ?>
        <a href="packlist.php?salesman_id=<?=(int)$sup?><?= $from ? '&from=' . urlencode($from) : '' ?><?= $to ? '&to=' . urlencode($to) : '' ?>" class="btn btn-success btn-block" target="_blank"><i class="fas fa-print"></i> Delivery List</a>
        <?php endif; ?>
      </div>
    </form>

    <div class="row mb-3 d-print-none">
      <div class="col-md-4 text-center"><strong>Total Sales:</strong> <span class="text-primary">PKR <?=formatCurrency($total_sales)?></span></div>
      <div class="col-md-4 text-center"><strong>Total Paid:</strong> <span class="text-success">PKR <?=formatCurrency($total_paid)?></span></div>
      <div class="col-md-4 text-center"><strong>Total Due:</strong> <span class="text-danger">PKR <?=formatCurrency($total_due)?></span></div>
    </div>

    <div class="table-responsive">
      <table class="table table-bordered table-hover report-table">
        <thead>
          <tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Order Taker</th><th>Salesman</th><th class="text-right">Total</th><th class="text-right">Paid</th><th class="text-right">Due</th><th class="no-print">Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($sales as $s): ?>
          <tr>
            <td class="font-weight-bold"><?=htmlspecialchars($s['invoice_no'])?></td>
            <td><?=formatDate($s['sale_date'])?></td>
            <td><?=htmlspecialchars($s['full_name'] ?? 'N/A')?></td>
            <td><?=htmlspecialchars($s['order_taker'] ?? '—')?></td>
            <td><?=htmlspecialchars($s['salesman_name'] ?? '—')?></td>
            <td>PKR <?=formatCurrency($s['total_amount'])?></td>
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
          <tr><td colspan="9" class="text-center text-muted py-4">No sales found. <a href="index.php">Make your first sale</a></td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot class="report-tfoot">
          <tr>
            <td colspan="5">TOTAL (<?=count($sales)?> invoices)</td>
            <td class="text-right">PKR <?=formatCurrency($total_sales)?></td>
            <td class="text-right">PKR <?=formatCurrency($total_paid)?></td>
            <td class="text-right">PKR <?=formatCurrency($total_due)?></td>
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
  function hideList($list){ $list.empty().hide(); }

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
      $.getJSON('ajax_salesman_search.php', {q: q}, function(data){
        var $list = $('#salesmanList');
        $list.empty();
        if (!data || !data.length) {
          $list.append('<div class="ac-item ac-empty">No salesman found</div>');
        } else {
          $.each(data, function(i, it){
            var sub = [];
            if (it.area) sub.push('Area: ' + esc(it.area));
            if (it.phone) sub.push('Phone: ' + esc(it.phone));
            $list.append(
              '<div class="ac-item" data-id="' + it.id + '">' +
              '<span class="ac-name">' + esc(it.full_name) + '</span>' +
              (sub.length ? '<small class="ac-sub">' + sub.join(' &middot; ') + '</small>' : '') +
              '</div>'
            );
          });
        }
        $list.show();
      });
    }, 250);
  });

  $('#salesmanList').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    $('#salesman_id').val($(this).data('id'));
    $('#salesmanSearch').val($(this).find('.ac-name').text());
    hideList($('#salesmanList'));
  });

  // ===== ORDER TAKER SEARCH =====
  var obTimer = null;
  $('#obSearch').on('input', function(){
    var q = $.trim(this.value);
    clearTimeout(obTimer);
    if (!q) {
      $('#order_booker_id').val('');
      hideList($('#obList'));
      return;
    }
    obTimer = setTimeout(function(){
      $.getJSON('ajax_order_booker_search.php', {q: q}, function(data){
        var $list = $('#obList');
        $list.empty();
        if (!data || !data.length) {
          $list.append('<div class="ac-item ac-empty">No order taker found</div>');
        } else {
          $.each(data, function(i, it){
            var sub = [];
            if (it.phone) sub.push('Phone: ' + esc(it.phone));
            $list.append(
              '<div class="ac-item" data-id="' + it.id + '">' +
              '<span class="ac-name">' + esc(it.full_name) + '</span>' +
              (sub.length ? '<small class="ac-sub">' + sub.join(' &middot; ') + '</small>' : '') +
              '</div>'
            );
          });
        }
        $list.show();
      });
    }, 250);
  });

  $('#obList').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    $('#order_booker_id').val($(this).data('id'));
    $('#obSearch').val($(this).find('.ac-name').text());
    hideList($('#obList'));
  });

  $(document).on('keydown', '#salesmanSearch, #obSearch', function(e){
    var $list = $(this).attr('id') === 'salesmanSearch' ? $('#salesmanList') : $('#obList');
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
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>