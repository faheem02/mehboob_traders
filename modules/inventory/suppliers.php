<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Suppliers';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_supplier') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        redirect('suppliers.php', 'Supplier name is required', 'error');
    }
    $opening = (float)($_POST['opening_balance'] ?? 0);
    $id = insert('suppliers', [
        'name' => $name,
        'contact_person' => trim($_POST['contact_person'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'cnic' => trim($_POST['cnic'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
        'notes' => trim($_POST['notes'] ?? ''),
        'opening_balance' => $opening,
        'adjustment' => (float)($_POST['adjustment'] ?? 0),
        'current_balance' => $opening + (float)($_POST['adjustment'] ?? 0),
        'status' => 1,
        'created_at' => date('Y-m-d'),
    ]);
    logActivity($pdo, 'create', 'supplier', $id, 'Created supplier: ' . $name);
    redirect('suppliers.php', 'Supplier added successfully');
}

// Refresh all supplier balances
foreach ($pdo->query("SELECT id FROM suppliers")->fetchAll() as $s) {
    syncSupplierPurchasePayments($pdo, $s['id']);
    updateSupplierBalance($pdo, $s['id']);
}

$q = trim($_GET['q'] ?? '');
$sql = "SELECT * FROM suppliers WHERE 1=1";
$params = [];
if ($q) {
    $sql .= " AND (name LIKE ? OR phone LIKE ? OR city LIKE ?)";
    $params = ["%$q%", "%$q%", "%$q%"];
}
$sql .= " ORDER BY name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$suppliers = $stmt->fetchAll();

$total_payable = 0; $total_advance = 0;
foreach ($suppliers as $s) {
    if ($s['current_balance'] > 0) $total_payable += $s['current_balance'];
    else $total_advance += abs($s['current_balance']);
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="row mb-3">
  <div class="col-md-6">
    <div class="card border-left-danger shadow stat-card">
      <div class="card-body py-2">
        <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Total Payable (We Owe)</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($total_payable)?></div>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card border-left-success shadow stat-card">
      <div class="card-body py-2">
        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Advance (Supplier Owes Us)</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($total_advance)?></div>
      </div>
    </div>
  </div>
</div>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6><i class="fas fa-truck-loading"></i> Suppliers (<?=count($suppliers)?>)</h6>
    <button type="button" class="btn btn-sm btn-primary" data-toggle="modal" data-target="#addSupplierModal">
      <i class="fas fa-plus"></i> Add Supplier
    </button>
  </div>
  <div class="card-body">
    <form method="get" class="row g-2 mb-3">
      <div class="col-md-4"><input type="text" name="q" class="form-control" placeholder="Search name / phone / city" value="<?=htmlspecialchars($q)?>"></div>
      <div class="col-md-2"><button class="btn btn-outline-primary btn-block"><i class="fas fa-search"></i> Search</button></div>
    </form>

    <div class="table-responsive">
      <table class="table table-bordered table-hover">
        <thead>
          <tr><th>Name</th><th>Contact Person</th><th>Phone</th><th>City</th><th>Notes</th><th>Balance</th><th>Status</th><th>Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($suppliers as $s):
            $bal = (float)$s['current_balance'];
            $balClass = $bal > 0 ? 'balance-negative' : ($bal < 0 ? 'balance-positive' : 'balance-zero');
            $balLabel = $bal > 0 ? 'PKR ' . formatCurrency($bal) . ' (payable)' : ($bal < 0 ? 'Advance PKR ' . formatCurrency(abs($bal)) : 'PKR 0.00 (clear)');
          ?>
          <tr>
            <td class="font-weight-bold"><?=htmlspecialchars($s['name'])?></td>
            <td><?=htmlspecialchars($s['contact_person'] ?? '-')?></td>
            <td><?=htmlspecialchars($s['phone'] ?? '-')?></td>
            <td><?=htmlspecialchars($s['city'] ?? '-')?></td>
            <td class="text-truncate" style="max-width:180px;" title="<?=htmlspecialchars($s['notes'] ?? '')?>"><?=htmlspecialchars($s['notes'] ?? '-')?></td>
            <td class="<?=$balClass?> font-weight-bold"><?=$balLabel?></td>
            <td><?= $s['status'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-secondary">Inactive</span>' ?></td>
            <td class="text-nowrap">
              <a href="supplier_edit.php?id=<?=$s['id']?>" class="btn btn-sm btn-outline-warning" title="Edit"><i class="fas fa-edit"></i></a>
              <a href="supplier_view.php?id=<?=$s['id']?>" class="btn btn-sm btn-outline-primary" title="Ledger / History"><i class="fas fa-book"></i></a>
              <form method="post" action="supplier_delete.php" class="d-inline" onsubmit="return confirm('Delete this supplier? This will remove its payment history.');">
                <input type="hidden" name="id" value="<?=$s['id']?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($suppliers)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No suppliers yet. <a href="#" data-toggle="modal" data-target="#addSupplierModal">Add your first supplier</a></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add Supplier Modal -->
<div class="modal fade" id="addSupplierModal" tabindex="-1" role="dialog" aria-labelledby="addSupplierModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <form method="post" action="suppliers.php">
        <input type="hidden" name="action" value="add_supplier">
        <div class="modal-header">
          <h5 class="modal-title font-weight-bold" id="addSupplierModalLabel"><i class="fas fa-truck-loading text-primary mr-2"></i> Add New Supplier</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Supplier Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required placeholder="Company / Person name">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Contact Person</label>
              <input type="text" name="contact_person" class="form-control" placeholder="Optional">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Phone</label>
              <input type="text" name="phone" class="form-control" placeholder="0300-1234567">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">CNIC</label>
              <input type="text" name="cnic" class="form-control" placeholder="xxxxx-xxxxxxx-x">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Email</label>
              <input type="email" name="email" class="form-control" placeholder="Optional">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">City</label>
              <input type="text" name="city" class="form-control" placeholder="e.g. Lahore">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Opening Balance</label>
              <input type="number" name="opening_balance" step="0.01" class="form-control" value="0" min="0">
              <small class="text-muted d-block mt-1">+ = goods already taken on credit (payable)</small>
            </div>
            <div class="col-md-12 mb-3">
              <label class="form-label font-weight-bold">Address</label>
              <input type="text" name="address" class="form-control" placeholder="Optional">
            </div>
            <div class="col-md-12 mb-3">
              <label class="form-label font-weight-bold">Notes</label>
              <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes about this supplier"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal"><i class="fas fa-times mr-1"></i> Cancel</button>
          <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save mr-1"></i> Save Supplier</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  var urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('add') === '1' || urlParams.get('action') === 'add') {
    $('#addSupplierModal').modal('show');
  }
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>