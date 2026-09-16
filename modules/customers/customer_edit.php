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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    if ($full_name === '') { redirect('customer_edit.php?id=' . $id, 'Customer name is required', 'error'); }
    if ($phone === '') { redirect('customer_edit.php?id=' . $id, 'Phone number is required', 'error'); }

    update('customers', [
        'full_name' => $full_name,
        'phone' => $phone,
        'cnic' => trim($_POST['cnic'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
        'area' => trim($_POST['area'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'notes' => trim($_POST['notes'] ?? ''),
        'updated_at' => date('Y-m-d'),
    ], $id);
    logActivity($pdo, 'edit', 'customer', $id, 'Edited customer: ' . $full_name);
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
            <input type="text" name="area" class="form-control" list="areaSuggestions" value="<?=htmlspecialchars($customer['area'] ?? '')?>" placeholder="e.g. Johar Town, Gulberg" autocomplete="off">
            <datalist id="areaSuggestions">
              <?php foreach ($all_areas as $ar): ?>
              <option value="<?=htmlspecialchars($ar['name'])?>"><?=htmlspecialchars($ar['city'])?></option>
              <?php endforeach; ?>
            </datalist>
            <small class="text-muted d-block mt-1">Type to select from registered areas or enter new.</small>
          <?php endif; ?>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label font-weight-bold">Current Balance</label>
          <div class="form-control font-weight-bold <?=$balClass?>"><?=$balLabel?></div>
          <small class="text-muted d-block mt-1">Changes through sales and receipts only.</small>
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

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>