<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Packing List (Salesman-wise)';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

$sup = (int)($_GET['salesman_id'] ?? 0);
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');

$salesman = null;
$sales = [];
$details = [];
$total_amount = 0;
$total_sales = 0;
$total_boxes_all = 0;

if ($sup) {
    $salesman = getById('employees', $sup);

    $sql = "SELECT s.id, s.invoice_no, s.sale_date, s.total_amount, s.notes, s.payment_method,
                   c.full_name, c.area, c.phone, c.address
            FROM sales s
            LEFT JOIN customers c ON s.customer_id = c.id
            WHERE s.salesman_id = ? AND s.status <> 'cancelled'";
    $params = [$sup];
    if ($from) { $sql .= " AND s.sale_date >= ?"; $params[] = $from; }
    if ($to) { $sql .= " AND s.sale_date <= ?"; $params[] = $to; }
    $sql .= " ORDER BY c.area, c.full_name, s.id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sales = $stmt->fetchAll();

    foreach ($sales as $s) {
        $total_amount += (float)$s['total_amount'];
        $total_sales++;
    }

    $sql3 = "SELECT s.invoice_no, s.sale_date, c.full_name, c.area, c.phone, c.address,
                    si.quantity, si.price, si.subtotal, p.name AS product_name, p.unit, p.boxes_per_carton, b.name AS brand_name
             FROM sale_items si
             JOIN sales s ON si.sale_id = s.id
             LEFT JOIN customers c ON s.customer_id = c.id
             JOIN products p ON si.product_id = p.id
             LEFT JOIN brands b ON p.brand_id = b.id
             WHERE s.salesman_id = ? AND s.status <> 'cancelled'";
    $params3 = [$sup];
    if ($from) { $sql3 .= " AND s.sale_date >= ?"; $params3[] = $from; }
    if ($to) { $sql3 .= " AND s.sale_date <= ?"; $params3[] = $to; }
    $sql3 .= " ORDER BY c.area, c.full_name, s.id, p.name";
    $stmt3 = $pdo->prepare($sql3);
    $stmt3->execute($params3);
    $details = $stmt3->fetchAll();

    $total_boxes_all = 0;
    foreach ($details as $d) { $total_boxes_all += (int)$d['quantity']; }
}

$period_desc = 'All dates';
if ($from && $to) { $period_desc = formatDate($from) . ' to ' . formatDate($to); }
elseif ($from) { $period_desc = 'From ' . formatDate($from); }
elseif ($to) { $period_desc = 'Until ' . formatDate($to); }
$printed_by = '';
if (!empty($_SESSION['user_id'])) {
    $pu = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $pu->execute([(int)$_SESSION['user_id']]);
    $printed_by = (string)$pu->fetchColumn();
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center d-print-none">
    <h6><i class="fas fa-truck-loading"></i> Salesman Delivery Packing List</h6>
    <div class="d-flex flex-wrap">
      <?php if ($sup && $salesman): ?>
      <button type="button" class="btn btn-sm btn-primary mr-2" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
      <?php endif; ?>
      <a href="invoices.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-file-invoice"></i> Invoices</a>
    </div>
  </div>
  <div class="card-body">
    <form method="get" class="row g-2 mb-3 d-print-none">
      <div class="col-md-4">
        <div class="ac-wrap">
          <input type="text" id="salesmanSearch" class="form-control" placeholder="Select salesman..." autocomplete="off" value="<?=htmlspecialchars($salesman['full_name'] ?? '')?>" <?= $salesman ? 'readonly' : '' ?>>
          <input type="hidden" name="salesman_id" id="salesman_id" value="<?=$sup?>">
          <div class="ac-list" id="salesmanList"></div>
        </div>
        <small class="text-muted">Salesman area = delivery route</small>
      </div>
      <div class="col-md-2">
        <label class="form-label">From</label>
        <input type="date" name="from" class="form-control" value="<?=htmlspecialchars($from)?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">To</label>
        <input type="date" name="to" class="form-control" value="<?=htmlspecialchars($to)?>">
      </div>
      <div class="col-md-4 d-flex align-items-end">
        <button class="btn btn-outline-primary btn-block"><i class="fas fa-list"></i> Load List</button>
      </div>
    </form>

    <?php if (!$salesman): ?>
      <p class="text-muted text-center py-4 mb-0">Select a salesman above and press <strong>Load List</strong>.</p>
    <?php elseif (!$total_sales): ?>
      <p class="text-muted text-center py-4 mb-0">No invoices found for this salesman in the selected period.</p>
    <?php else: ?>

    <div class="report-sheet d-none d-print-block">
      <div class="report-head">
        <div class="report-brand-line">
          <div class="report-brand">
            <div class="report-brand-name">Mehboob Traders</div>
            <div class="report-brand-sub">Wholesale Business &middot; Lahore, Pakistan &middot; GST No: --</div>
          </div>
          <div class="report-title-box">
            <div class="report-title">Delivery Packing List</div>
            <div class="report-meta"><?=htmlspecialchars($salesman['full_name'])?> &middot; <?=$period_desc?><?= !empty($salesman['area']) ? ' &middot; Area: ' . htmlspecialchars($salesman['area']) : '' ?></div>
          </div>
        </div>
      </div>

      <table class="report-summary-table">
        <tr>
          <td class="rs-cell"><span class="rs-label">Salesman</span><span class="rs-val"><?=htmlspecialchars($salesman['full_name'])?></span></td>
          <td class="rs-cell"><span class="rs-label">Area</span><span class="rs-val"><?=htmlspecialchars($salesman['area'] ?? '—')?></span></td>
          <td class="rs-cell"><span class="rs-label">Total Invoices</span><span class="rs-val"><?=$total_sales?></span></td>
          <td class="rs-cell"><span class="rs-label">Total Boxes</span><span class="rs-val"><?=$total_boxes_all?></span></td>
          <td class="rs-cell"><span class="rs-label">Total Amount</span><span class="rs-val">PKR <?=formatCurrency($total_amount)?></span></td>
        </tr>
      </table>
    </div>

    <div class="row mb-3 d-print-none">
      <div class="col-md-3 text-center"><strong>Salesman:</strong> <span class="text-primary"><?=htmlspecialchars($salesman['full_name'])?><?= !empty($salesman['area']) ? ' (' . htmlspecialchars($salesman['area']) . ')' : '' ?></span></div>
      <div class="col-md-3 text-center"><strong>Invoices:</strong> <span class="text-primary"><?=$total_sales?></span></div>
      <div class="col-md-3 text-center"><strong>Total Boxes:</strong> <span class="text-primary"><?=$total_boxes_all?></span></div>
      <div class="col-md-3 text-center"><strong>Total Amount:</strong> <span class="text-success">PKR <?=formatCurrency($total_amount)?></span></div>
    </div>

    <div class="table-responsive mb-2">
      <table class="table table-bordered table-sm report-table">
        <thead class="thead-light">
          <tr><th>#</th><th>Invoice</th><th>Customer</th><th>Area</th><th>Phone</th><th>Product (Brand)</th><th class="text-right">Cartons</th><th class="text-right">Loose Boxes</th><th class="text-right">Total Boxes</th><th class="text-right">Amount</th></tr>
        </thead>
        <tbody>
          <?php
          $prev_inv = '';
          $i = 0;
          foreach ($details as $d):
            $bpc = max((int)($d['boxes_per_carton'] ?? 1), 1);
            $qty = (int)$d['quantity'];
            $ctn = intdiv($qty, $bpc);
            $loose = $qty % $bpc;
            $is_new_party = $d['invoice_no'] !== $prev_inv;
            $prev_inv = $d['invoice_no'];
            $i++;
          ?>
          <tr>
            <td><?=$i?></td>
            <td class="font-weight-bold"><?=htmlspecialchars($d['invoice_no'])?></td>
            <td class="font-weight-bold"><?=$is_new_party ? htmlspecialchars($d['full_name'] ?? 'N/A') : ''?></td>
            <td><?=$is_new_party ? htmlspecialchars($d['area'] ?? '—') : ''?></td>
            <td><?=$is_new_party ? htmlspecialchars($d['phone'] ?? '—') : ''?></td>
            <td><?=htmlspecialchars($d['product_name'])?><small class="text-muted"><?= $d['brand_name'] ? ' (' . htmlspecialchars($d['brand_name']) . ')' : ''?></small></td>
            <td class="text-right"><?=$ctn?></td>
            <td class="text-right"><?=$loose?></td>
            <td class="text-right font-weight-bold"><?=$qty?></td>
            <td class="text-right">PKR <?=formatCurrency($d['subtotal'])?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="report-tfoot">
          <tr>
            <td colspan="8">TOTAL (<?=$total_sales?> invoices)</td>
            <td class="text-right font-weight-bold"><?=$total_boxes_all?></td>
            <td class="text-right font-weight-bold">PKR <?=formatCurrency($total_amount)?></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="report-foot d-none d-print-block">
      <div><strong>Prepared by:</strong> <?=htmlspecialchars($printed_by ?: '—')?></div>
      <div><strong>Printed on:</strong> <?=date('d-m-Y H:i')?></div>
      <div>Mehboob Traders &middot; Delivery Packing List</div>
    </div>

    <div class="mt-3 text-muted small d-print-none">
      <strong>Note:</strong> Total Boxes per product use its own carton size (boxes per carton). Cartons + Loose Boxes = Total Boxes to pack.
    </div>

    <?php endif; ?>
  </div>
</div>

<script>
$(document).ready(function(){
  function esc(s){ return $('<div>').text(s||'').html(); }
  function hideList($list){ $list.empty().hide(); }
  hideList($('#salesmanList'));

  $('#salesmanSearch').on('focus', function(){ $(this).prop('readonly', false); });

  var timer = null;
  $('#salesmanSearch').on('input', function(){
    var q = $.trim(this.value);
    clearTimeout(timer);
    if (!q) {
      $('#salesman_id').val('');
      hideList($('#salesmanList'));
      return;
    }
    timer = setTimeout(function(){
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
    $('#salesmanSearch').val($(this).find('.ac-name').text()).prop('readonly', true);
    hideList($('#salesmanList'));
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
});
</script>

<style>
@media print {
  @page { size: A4 landscape; margin: 10mm; }
  .report-sheet .report-brand-name { font-size: 24px !important; }
  .report-sheet .report-brand-sub  { font-size: 11px !important; }
  .report-sheet .report-title     { font-size: 16px !important; }
  .report-sheet .report-meta      { font-size: 11px !important; }
  .rs-label { font-size: 10px !important; letter-spacing: 0.6px !important; }
  .rs-val   { font-size: 16px !important; }
  .report-table th { font-size: 11.5px !important; padding: 7px 8px !important; }
  .report-table td { font-size: 12.5px !important; padding: 6px 8px !important; }
  tfoot.report-tfoot td { font-size: 12.5px !important; padding: 7px 8px !important; }
  .report-foot { font-size: 10px !important; }
}
</style>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>