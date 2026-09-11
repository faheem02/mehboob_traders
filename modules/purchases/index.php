<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Purchase List';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

// Filters
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$sup = $_GET['supplier_id'] ?? '';

$sql = "SELECT p.*, s.name AS supplier_name
        FROM purchases p
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        WHERE 1=1";
$params = [];
if ($from) { $sql .= " AND p.purchase_date >= ?"; $params[] = $from; }
if ($to) { $sql .= " AND p.purchase_date <= ?"; $params[] = $to; }
if ($sup !== '') { $sql .= " AND p.supplier_id = ?"; $params[] = $sup; }
$sql .= " ORDER BY p.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$purchases = $stmt->fetchAll();

$total_purchases = 0; $total_paid = 0; $total_due = 0;
foreach ($purchases as $p) { if ($p['status'] != 'cancelled') { $total_purchases += $p['total_amount']; $total_paid += $p['paid_amount']; $total_due += $p['due_amount']; } }

$sup_name = '';
if ($sup !== '') {
    $sn = $pdo->prepare("SELECT name FROM suppliers WHERE id = ?");
    $sn->execute([$sup]);
    $sup_name = (string)$sn->fetchColumn();
}
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6><i class="fas fa-cart-arrow-down"></i> Purchase List (<?=count($purchases)?>)</h6>
    <a href="create.php" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> New Purchase</a>
  </div>
  <div class="card-body">
    <form method="get" class="row g-2 mb-3">
      <div class="col-md-3">
        <input type="date" name="from" class="form-control datepicker" value="<?=htmlspecialchars($from)?>" placeholder="From">
      </div>
      <div class="col-md-3">
        <input type="date" name="to" class="form-control datepicker" value="<?=htmlspecialchars($to)?>" placeholder="To">
      </div>
      <div class="col-md-3">
        <div class="ac-wrap">
          <input type="text" id="supplierSearch" class="form-control" placeholder="Search supplier..." autocomplete="off" value="<?=htmlspecialchars($sup_name)?>">
          <input type="hidden" name="supplier_id" id="supplier_id" value="<?=htmlspecialchars($sup)?>">
          <div class="ac-list" id="supplierList"></div>
        </div>
        <small class="text-muted"><?= $sup ? '<i class="fas fa-filter"></i> Filtered by supplier' : 'Filter by supplier (optional)' ?></small>
      </div>
      <div class="col-md-3">
        <button class="btn btn-outline-primary btn-block"><i class="fas fa-filter"></i> Filter</button>
      </div>
    </form>

    <div class="row mb-3">
      <div class="col-md-4 text-center"><strong>Total Purchases:</strong> <span class="text-primary">PKR <?=formatCurrency($total_purchases)?></span></div>
      <div class="col-md-4 text-center"><strong>Total Paid:</strong> <span class="text-success">PKR <?=formatCurrency($total_paid)?></span></div>
      <div class="col-md-4 text-center"><strong>Total Due:</strong> <span class="text-danger">PKR <?=formatCurrency($total_due)?></span></div>
    </div>

    <div class="table-responsive">
      <table class="table table-bordered table-hover">
        <thead>
          <tr><th>Invoice</th><th>Date</th><th>Supplier</th><th>Total</th><th>Paid</th><th>Due</th><th>Method</th><th>Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($purchases as $p): ?>
          <tr>
            <td class="font-weight-bold"><?=htmlspecialchars($p['invoice_no'])?></td>
            <td><?=formatDate($p['purchase_date'])?></td>
            <td><?=htmlspecialchars($p['supplier_name'] ?? 'N/A')?></td>
            <td>PKR <?=formatCurrency($p['total_amount'])?></td>
            <td class="text-success">PKR <?=formatCurrency($p['paid_amount'])?></td>
            <td class="<?= $p['due_amount'] > 0 ? 'text-danger font-weight-bold' : 'text-success'?>">PKR <?=formatCurrency($p['due_amount'])?></td>
            <td><span class="badge badge-secondary"><?=ucfirst($p['payment_method'])?></span></td>
            <td class="text-center" nowrap>
              <button type="button" class="btn btn-sm btn-outline-info view-purchase" data-id="<?=$p['id']?>" title="View Purchase"><i class="fas fa-eye"></i></button>
              <a href="purchase_edit.php?id=<?=$p['id']?>" class="btn btn-sm btn-outline-warning" title="Edit Purchase"><i class="fas fa-edit"></i></a>
              <form method="post" action="purchase_delete.php" class="d-inline" onsubmit="return confirm('Delete this purchase? This will reverse stock &amp; payments.');">
                <input type="hidden" name="id" value="<?=$p['id']?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Purchase"><i class="fas fa-trash-alt"></i></button>
              </form>
              <a href="purchase_print.php?id=<?=$p['id']?>" class="btn btn-sm btn-outline-primary" title="Print Purchase" target="_blank"><i class="fas fa-print"></i></a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($purchases)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No purchases found. <a href="create.php">Make your first purchase</a></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Purchase View Modal -->
<div class="modal fade" id="purchaseViewModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title"><i class="fas fa-cart-arrow-down"></i> Purchase Details</h6>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body" id="purchaseViewBody">
        <div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
      </div>
    </div>
  </div>
</div>

<script>
$(document).ready(function(){
  $(document).on('click', '.view-purchase', function(){
    var id = $(this).data('id');
    $('#purchaseViewBody').html('<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
    $('#purchaseViewModal').modal('show');
    $.ajax({
      url: 'ajax_purchase_view.php',
      data: {id: id},
      dataType: 'html'
    }).done(function(html){
      $('#purchaseViewBody').html(html);
    }).fail(function(){
      $('#purchaseViewBody').html('<div class="alert alert-danger mb-0">Could not load purchase details. Please refresh and try again.</div>');
    });
  });

  // ===== SUPPLIER AUTOCOMPLETE FILTER =====
  function esc(s){
    return String(s == null ? '' : s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function hideList($list){ $list.empty().hide(); }

  var supTimer = null;
  $('#supplierSearch').on('input', function(){
    var q = $.trim(this.value);
    clearTimeout(supTimer);
    if (!q) {
      $('#supplier_id').val('');
      hideList($('#supplierList'));
      return;
    }
    supTimer = setTimeout(function(){
      $.getJSON('../transactions/ajax_supplier_search.php', {q: q}, function(data){
        var $list = $('#supplierList');
        $list.empty();
        if (!data || !data.length) {
          $list.append('<div class="ac-item ac-empty">No supplier found</div>');
        } else {
          $.each(data, function(i, it){
            var sub = [];
            if (it.phone) sub.push('Phone: ' + esc(it.phone));
            if (it.city) sub.push(esc(it.city));
            $list.append(
              '<div class="ac-item" data-id="' + it.id + '">' +
              '<span class="ac-name">' + esc(it.name) + '</span>' +
              (sub.length ? '<small class="ac-sub">' + sub.join(' &middot; ') + '</small>' : '') +
              '</div>'
            );
          });
        }
        $list.show();
      });
    }, 250);
  });

  $('#supplierList').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    $('#supplier_id').val($(this).data('id'));
    $('#supplierSearch').val($(this).find('.ac-name').text());
    hideList($('#supplierList'));
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