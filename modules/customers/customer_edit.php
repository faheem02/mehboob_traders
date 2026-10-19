<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Edit Customer';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

$id = (int)($_GET['id'] ?? 0);
$customer = $id ? getById('customers', $id) : null;
if (!$customer) redirect('customers.php', 'Customer not found', 'error');

$my_areas = currentUserAreas($pdo);
if (!isAdmin() && $my_areas !== null) {
    if (!empty($my_areas) && !in_array($customer['area'], $my_areas, true)) {
        redirect('customers.php', 'You can only edit customers from your assigned areas', 'error');
    }
}

// Ensure balance is refreshed before display or edit
updateCustomerBalance($pdo, $id);
$customer = getById('customers', $id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    if ($full_name === '') { redirect('customer_edit.php?id=' . $id, 'Customer name is required', 'error'); }
    if ($phone === '') { redirect('customer_edit.php?id=' . $id, 'Phone number is required', 'error'); }

    $old_balance = (float)$customer['current_balance'];
    $new_balance = isset($_POST['current_balance']) && $_POST['current_balance'] !== '' 
        ? round((float)$_POST['current_balance'], 2) 
        : $old_balance;

    // Calculate existing net transactions (sales minus initial paid, minus receipts)
    $sales_stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount),0), COALESCE(SUM(initial_paid),0)
        FROM sales WHERE customer_id = ? AND status <> 'cancelled'
    ");
    $sales_stmt->execute([$id]);
    [$total_sales, $initial_paid] = $sales_stmt->fetch(PDO::FETCH_NUM);

    $recv_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM customer_receipts WHERE customer_id = ?");
    $recv_stmt->execute([$id]);
    $total_receipts = (float)$recv_stmt->fetchColumn();

    $net_tx = (float)$total_sales - (float)$initial_paid - $total_receipts;
    $new_opening = round($new_balance - $net_tx, 2);

    update('customers', [
        'full_name' => $full_name,
        'phone' => $phone,
        'cnic' => trim($_POST['cnic'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
        'area' => trim($_POST['area'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'notes' => trim($_POST['notes'] ?? ''),
        'opening_balance' => $new_opening,
        'current_balance' => $new_balance,
        'updated_at' => date('Y-m-d'),
    ], $id);

    updateCustomerBalance($pdo, $id);

    $log_msg = 'Edited customer: ' . $full_name;
    if (abs($new_balance - $old_balance) > 0.001) {
        $log_msg .= ' (Balance adjusted: ' . formatCurrency($old_balance) . ' -> ' . formatCurrency($new_balance) . ')';
    }
    logActivity($pdo, 'edit', 'customer', $id, $log_msg);
    redirect('customers.php', 'Customer "' . $full_name . '" updated successfully');
}

$all_areas = $pdo->query("SELECT id, name, city FROM areas WHERE status = 1 ORDER BY name ASC")->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
$bal = (float)$customer['current_balance'];
$balClass = $bal > 0 ? 'balance-negative' : ($bal < 0 ? 'balance-positive' : 'balance-zero');
$balLabel = $bal > 0 ? 'Receivable PKR ' . formatCurrency($bal) : ($bal < 0 ? 'Advance PKR ' . formatCurrency(abs($bal)) : 'PKR 0.00');
?>

<div class="card shadow">
  <div class="card-header">
    <h6><i class="fas fa-user-edit"></i> Edit Customer <small class="text-muted">(<?=htmlspecialchars($customer['customer_no'])?>)</small></h6>
  </div>
  <div class="card-body">
    <form method="post">
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label font-weight-bold">Full Name <span class="text-danger">*</span></label>
          <input type="text" name="full_name" class="form-control" required value="<?=htmlspecialchars($customer['full_name'])?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label font-weight-bold">Phone <span class="text-danger">*</span></label>
          <input type="text" name="phone" class="form-control" required value="<?=htmlspecialchars($customer['phone'])?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label font-weight-bold">CNIC</label>
          <input type="text" name="cnic" class="form-control" placeholder="xxxxx-xxxxxxx-x" value="<?=htmlspecialchars($customer['cnic'] ?? '')?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label font-weight-bold">Email</label>
          <input type="email" name="email" class="form-control" value="<?=htmlspecialchars($customer['email'] ?? '')?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label font-weight-bold">City</label>
          <input type="text" name="city" class="form-control" value="<?=htmlspecialchars($customer['city'] ?? '')?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label font-weight-bold">Area / Town</label>
          <?php if ($my_areas !== null && count($my_areas) > 1): ?>
            <select name="area" class="form-control" required>
              <?php foreach ($my_areas as $ma): ?>
              <option value="<?=htmlspecialchars($ma)?>" <?= strcasecmp($customer['area'] ?? '', $ma) === 0 ? 'selected' : '' ?>><?=htmlspecialchars($ma)?></option>
              <?php endforeach; ?>
            </select>
            <small class="text-muted d-block mt-1">Select from your assigned areas.</small>
          <?php elseif ($my_areas !== null && count($my_areas) === 1): ?>
            <input type="text" name="area" class="form-control" value="<?=htmlspecialchars($my_areas[0])?>" readonly>
            <small class="text-muted d-block mt-1">Area is locked to your assigned area (<?=htmlspecialchars($my_areas[0])?>).</small>
          <?php else: ?>
            <div class="ac-wrap" id="areaWrap">
              <input type="text" name="area" id="areaInput" class="form-control" value="<?=htmlspecialchars($customer['area'] ?? '')?>" placeholder="Type area / town name" autocomplete="off">
              <div class="ac-list" id="areaList"></div>
            </div>
            <small class="text-muted d-block mt-1">Start typing &mdash; areas are suggested from the database (or enter a new one).</small>
          <?php endif; ?>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label font-weight-bold">Current Balance (PKR)</label>
          <div class="input-group">
            <div class="input-group-prepend">
              <span class="input-group-text font-weight-bold">PKR</span>
            </div>
            <input type="number" step="0.01" name="current_balance" class="form-control font-weight-bold" value="<?=htmlspecialchars(number_format((float)$customer['current_balance'], 2, '.', ''))?>">
          </div>
          <small class="text-muted d-block mt-1">
            + = Receivable (to receive), &minus; = Advance. Current: <span class="<?=$balClass?> font-weight-bold"><?=$balLabel?></span>
          </small>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label font-weight-bold">Address</label>
          <input type="text" name="address" class="form-control" value="<?=htmlspecialchars($customer['address'] ?? '')?>">
        </div>
        <div class="col-md-12 mb-3">
          <label class="form-label font-weight-bold">Notes</label>
          <textarea name="notes" class="form-control" rows="2"><?=htmlspecialchars($customer['notes'] ?? '')?></textarea>
        </div>
        <div class="col-12 mt-2 d-flex justify-content-between">
          <a href="customers.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back</a>
          <button type="submit" class="btn btn-primary px-5"><i class="fas fa-save"></i> Update Customer</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  var $input = $('#areaInput');
  var $list = $('#areaList');
  if (!$input.length) return;
  var timer = null;
  var areaUrl = '<?=$base_url?>modules/areas/ajax_area_search.php';

  function esc(s){
    return String(s == null ? '' : s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  $input.on('input focus', function(){
    var q = $.trim($(this).val());
    clearTimeout(timer);
    $list.empty().hide();
    if (q === '') return;
    timer = setTimeout(function(){
      $.getJSON(areaUrl, {q: q}, function(data){
        $list.empty();
        if (!data || !data.length) {
          $list.append('<div class="ac-item ac-empty">No matching area &mdash; you can type a new one</div>');
        } else {
          $.each(data, function(i, it){
            var sub = it.city ? '<small class="ac-sub">' + esc(it.city) + '</small>' : '';
            $list.append($('<div class="ac-item" data-name="' + esc(it.name) + '">' +
              '<span class="ac-name">' + esc(it.name) + '</span>' + sub + '</div>'));
          });
        }
        $list.show();
      });
    }, 250);
  });

  $list.on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    $input.val($(this).data('name'));
    $list.empty().hide();
  });

  $(document).on('keydown', '#areaInput', function(e){
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
      $list.empty().hide();
    }
  });

  $(document).on('mouseover', '#areaList .ac-item', function(){
    $(this).addClass('active').siblings().removeClass('active');
  });
  $(document).on('mousedown', function(e){
    if (!$(e.target).closest('#areaWrap').length) {
      $list.empty().hide();
    }
  });
})();
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>