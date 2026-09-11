<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Edit Customer';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

$id = (int)($_GET['id'] ?? 0);
$customer = $id ? getById('customers', $id) : null;
if (!$customer) redirect('customers.php', 'Customer not found', 'error');

if (!isAdmin()) {
    $my_area = currentUserArea($pdo);
    if ($my_area && ($customer['area'] ?? '') !== $my_area) {
        redirect('customers.php', 'You can only edit customers from your area', 'error');
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

require_once dirname(__DIR__, 2) . '/includes/header.php';
$bal = (float)$customer['current_balance'];
$balClass = $bal > 0 ? 'balance-negative' : ($bal < 0 ? 'balance-positive' : 'balance-zero');
$balLabel = $bal > 0 ? 'Receivable PKR ' . formatCurrency($bal) : ($bal < 0 ? 'Advance PKR ' . formatCurrency(abs($bal)) : 'PKR 0.00');
$my_area = !isAdmin() ? currentUserArea($pdo) : null;
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
          <input type="text" name="area" class="form-control" value="<?=htmlspecialchars($customer['area'] ?? '')?>" <?= $my_area ? 'readonly' : '' ?>>
          <?php if ($my_area): ?>
          <small class="text-muted d-block mt-1">Area is locked to your area (<?=htmlspecialchars($my_area)?>).</small>
          <?php else: ?>
          <small class="text-muted d-block mt-1">Order bookers see only customers from their own area.</small>
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