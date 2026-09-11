<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Edit Supplier';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$id = (int)($_GET['id'] ?? 0);
$supplier = $id ? getById('suppliers', $id) : null;
if (!$supplier) redirect('suppliers.php', 'Supplier not found', 'error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') { redirect('supplier_edit.php?id=' . $id, 'Supplier name is required', 'error'); }

    update('suppliers', [
        'name' => $name,
        'contact_person' => trim($_POST['contact_person'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'cnic' => trim($_POST['cnic'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'notes' => trim($_POST['notes'] ?? ''),
        'updated_at' => date('Y-m-d'),
    ], $id);
    logActivity($pdo, 'edit', 'supplier', $id, 'Edited supplier: ' . $name);
    redirect('suppliers.php', 'Supplier "' . $name . '" updated successfully');
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
$bal = (float)$supplier['current_balance'];
$balClass = $bal > 0 ? 'balance-negative' : ($bal < 0 ? 'balance-positive' : 'balance-zero');
$balLabel = $bal > 0 ? 'PKR ' . formatCurrency($bal) . ' (payable)' : ($bal < 0 ? 'Advance PKR ' . formatCurrency(abs($bal)) : 'PKR 0.00');
?>

<div class="card shadow">
  <div class="card-header">
    <h6><i class="fas fa-user-edit"></i> Edit Supplier</h6>
  </div>
  <div class="card-body">
    <form method="post">
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label font-weight-bold">Supplier Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" required value="<?=htmlspecialchars($supplier['name'])?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label font-weight-bold">Contact Person</label>
          <input type="text" name="contact_person" class="form-control" value="<?=htmlspecialchars($supplier['contact_person'] ?? '')?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label font-weight-bold">Phone</label>
          <input type="text" name="phone" class="form-control" value="<?=htmlspecialchars($supplier['phone'] ?? '')?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label font-weight-bold">CNIC</label>
          <input type="text" name="cnic" class="form-control" placeholder="xxxxx-xxxxxxx-x" value="<?=htmlspecialchars($supplier['cnic'] ?? '')?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label font-weight-bold">Email</label>
          <input type="email" name="email" class="form-control" value="<?=htmlspecialchars($supplier['email'] ?? '')?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label font-weight-bold">City</label>
          <input type="text" name="city" class="form-control" value="<?=htmlspecialchars($supplier['city'] ?? '')?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label font-weight-bold">Address</label>
          <input type="text" name="address" class="form-control" value="<?=htmlspecialchars($supplier['address'] ?? '')?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label font-weight-bold">Current Balance</label>
          <div class="form-control font-weight-bold <?=$balClass?>"><?=$balLabel?></div>
          <small class="text-muted d-block mt-1">Changes through purchases and payments only.</small>
        </div>
        <div class="col-md-12 mb-3">
          <label class="form-label font-weight-bold">Notes</label>
          <textarea name="notes" class="form-control" rows="2"><?=htmlspecialchars($supplier['notes'] ?? '')?></textarea>
        </div>
        <div class="col-12 mt-2 d-flex justify-content-between">
          <a href="suppliers.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back</a>
          <button type="submit" class="btn btn-primary px-5"><i class="fas fa-save"></i> Update Supplier</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>