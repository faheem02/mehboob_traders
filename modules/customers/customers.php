<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Customers';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_customer') {
    if (!isAdmin() && !isSalesTeam()) {
        redirect('customers.php', 'Only administrators and order bookers can add new customers', 'error');
    }
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    if ($full_name === '') {
        redirect('customers.php', 'Customer name is required', 'error');
    }
    if ($phone === '') {
        redirect('customers.php', 'Phone number is required', 'error');
    }
    $opening = (float)($_POST['opening_balance'] ?? 0);
    $customer_no = generateCustomerNo();

    $id = insert('customers', [
        'customer_no' => $customer_no,
        'full_name' => $full_name,
        'phone' => $phone,
        'cnic' => trim($_POST['cnic'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
        'area' => trim($_POST['area'] ?? ''),
        'opening_balance' => $opening,
        'current_balance' => $opening,
        'notes' => trim($_POST['notes'] ?? ''),
        'branch_id' => currentBranchId($pdo),
        'created_by' => $_SESSION['user_id'] ?? 1,
        'created_at' => date('Y-m-d'),
    ]);
    logActivity($pdo, 'create', 'customer', $id, 'Created customer: ' . $full_name . ' (' . $customer_no . ')');
    
    $return_to = trim($_POST['return_to'] ?? '');
    if ($return_to === 'take_order') {
        $ret_area = trim($_POST['area'] ?? '');
        redirect('../sales/index.php' . ($ret_area !== '' ? '?area=' . urlencode($ret_area) : ''), 'Shop added successfully: ' . $full_name);
    }
    redirect('customers.php', 'Customer added successfully: ' . $customer_no);
}

// Refresh all customer balances
foreach ($pdo->query("SELECT id FROM customers")->fetchAll() as $c) {
    updateCustomerBalance($pdo, $c['id']);
}

$my_areas = currentUserAreas($pdo);
$my_area_label = currentUserArea($pdo);

$all_areas = $pdo->query("SELECT id, name, city FROM areas WHERE status = 1 ORDER BY name ASC")->fetchAll();

$q = trim($_GET['q'] ?? '');
$sql = "SELECT * FROM customers WHERE 1=1";
$params = [];
if ($q) {
    $sql .= " AND (full_name LIKE ? OR phone LIKE ? OR customer_no LIKE ?)";
    $params = array_merge($params, ["%$q%", "%$q%", "%$q%"]);
}
if ($my_areas !== null) {
    if (empty($my_areas)) {
        $sql .= " AND 1=0";
    } else {
        $in_placeholders = implode(',', array_fill(0, count($my_areas), '?'));
        $sql .= " AND area IN ($in_placeholders)";
        $params = array_merge($params, $my_areas);
    }
}
$sql .= " ORDER BY full_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

$total_due = 0; $total_advance = 0;
foreach ($customers as $c) {
    $b = (float)$c['current_balance'];
    if ($b > 0) $total_due += $b;
    elseif ($b < 0) $total_advance += abs($b);
}

$printed_by = '';
if (!empty($_SESSION['user_id'])) {
    $pu = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $pu->execute([(int)$_SESSION['user_id']]);
    $printed_by = (string)$pu->fetchColumn();
}
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="row mb-3 d-print-none">
  <div class="col-md-6">
    <div class="card border-left-danger shadow stat-card">
      <div class="card-body py-2">
        <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Total Receivable (To Receive)</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($total_due)?></div>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card border-left-success shadow stat-card">
      <div class="card-body py-2">
        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Advance (We Owe Customer)</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($total_advance)?></div>
      </div>
    </div>
  </div>
</div>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6><i class="fas fa-users"></i> Customers (<?=count($customers)?>)</h6>
    <div class="d-flex flex-wrap align-items-center">
      <?php if (isAdmin() || isSalesTeam()): ?>
      <button type="button" class="btn btn-sm btn-success mr-2" data-toggle="modal" data-target="#addCustomerModal">
        <i class="fas fa-plus"></i> Add Customer
      </button>
      <?php endif; ?>
      <button type="button" class="btn btn-sm btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
      <?php if (!empty($my_area_label)): ?>
      <span class="badge badge-info ml-2"><i class="fas fa-map-marker-alt"></i> Your area: <?=htmlspecialchars($my_area_label)?></span>
      <?php endif; ?>
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
            <div class="report-title">Customers Record</div>
            <div class="report-meta"><?= !empty($my_area_label) ? 'Area: ' . htmlspecialchars($my_area_label) . ' &middot; ' : '' ?><?= $q ? 'Search: ' . htmlspecialchars($q) . ' &middot; ' : '' ?>Listed: <?=count($customers)?> customers</div>
          </div>
        </div>
      </div>
      <table class="report-summary-table">
        <tr>
          <td class="rs-cell"><span class="rs-label">Total Customers</span><span class="rs-val"><?=count($customers)?></span></td>
          <td class="rs-cell"><span class="rs-label">Total Receivable</span><span class="rs-val" style="color:#b91c1c;">PKR <?=formatCurrency($total_due)?></span></td>
          <td class="rs-cell"><span class="rs-label">Total Advance</span><span class="rs-val" style="color:#0f766e;">PKR <?=formatCurrency($total_advance)?></span></td>
        </tr>
      </table>
    </div>

    <form method="get" class="row g-2 mb-3 d-print-none">
      <div class="col-md-4"><input type="text" name="q" class="form-control" placeholder="Search name / phone / customer no" value="<?=htmlspecialchars($q)?>"></div>
      <div class="col-md-2"><button class="btn btn-outline-primary btn-block"><i class="fas fa-search"></i> Search</button></div>
    </form>

    <div class="table-responsive">
      <table class="table table-bordered table-hover report-table">
        <thead>
          <tr>
            <th style="white-space: nowrap;">Customer No</th>
            <th>Name</th>
            <th style="white-space: nowrap;">Phone</th>
            <th>City</th>
            <th>Area</th>
            <th style="min-width: 220px;">Notes</th>
            <th class="text-right text-nowrap" style="width: 140px;">Balance</th>
            <th class="no-print text-center" style="width: 110px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($customers as $c):
            $bal = (float)$c['current_balance'];
            $balClass = $bal > 0 ? 'balance-negative' : ($bal < 0 ? 'balance-positive' : 'balance-zero');
            $balLabel = $bal > 0 ? 'Receivable PKR ' . formatCurrency($bal) : ($bal < 0 ? 'Advance PKR ' . formatCurrency(abs($bal)) : 'PKR 0.00');
          ?>
          <tr>
            <td class="text-muted text-nowrap"><?=htmlspecialchars($c['customer_no'])?></td>
            <td class="font-weight-bold"><?=htmlspecialchars($c['full_name'])?></td>
            <td class="text-nowrap"><?=htmlspecialchars($c['phone'])?></td>
            <td><?=htmlspecialchars($c['city'] ?? '-')?></td>
            <td><?=htmlspecialchars($c['area'] ?? '-')?></td>
            <td style="max-width:320px; white-space:normal; word-break:break-word;" title="<?=htmlspecialchars($c['notes'] ?? '')?>"><?=htmlspecialchars($c['notes'] ?: '-')?></td>
            <td class="<?=$balClass?> font-weight-bold text-right text-nowrap"><?= $balLabel ?></td>
            <td class="text-nowrap text-center">
              <a href="customer_edit.php?id=<?=$c['id']?>" class="btn btn-sm btn-outline-warning" title="Edit"><i class="fas fa-edit"></i></a>
              <form method="post" action="customer_delete.php" class="d-inline" onsubmit="return confirm('Delete this customer? This will remove their sales/receipt history.');">
                <input type="hidden" name="id" value="<?=$c['id']?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
              </form>
              <a href="customer_view.php?id=<?=$c['id']?>" class="btn btn-sm btn-outline-primary" title="Ledger / History"><i class="fas fa-book"></i></a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($customers)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No customers yet. <?php if (isAdmin() || isSalesTeam()): ?><a href="#" data-toggle="modal" data-target="#addCustomerModal">Add your first customer</a><?php endif; ?></td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot class="report-tfoot">
          <tr>
            <td colspan="6">TOTAL (<?=count($customers)?> customers)</td>
            <td class="text-right text-nowrap">Receivable PKR <?=formatCurrency($total_due)?> &middot; Advance PKR <?=formatCurrency($total_advance)?></td>
            <td class="no-print"></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="report-foot d-none d-print-block">
      <div><strong>Prepared by:</strong> <?=htmlspecialchars($printed_by ?: '—')?></div>
      <div><strong>Printed on:</strong> <?=date('d-m-Y H:i')?></div>
      <div>Mehboob Traders &middot; Customers Record</div>
    </div>
  </div>
</div>

<?php if (isAdmin() || isSalesTeam()): ?>
<!-- Add Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1" role="dialog" aria-labelledby="addCustomerModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <form method="post" action="customers.php">
        <input type="hidden" name="action" value="add_customer">
        <input type="hidden" name="return_to" value="<?=htmlspecialchars($_GET['return_to'] ?? '')?>">
        <div class="modal-header">
          <h5 class="modal-title font-weight-bold" id="addCustomerModalLabel"><i class="fas fa-user-plus text-primary mr-2"></i> Add New Customer</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="full_name" class="form-control" required placeholder="Customer name">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Phone <span class="text-danger">*</span></label>
              <input type="text" name="phone" class="form-control" required placeholder="0300-1234567">
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
              <label class="form-label font-weight-bold">Area / Town</label>
              <?php 
                $prefill_area = trim($_GET['area'] ?? '');
              ?>
              <?php if ($my_areas !== null && count($my_areas) > 1): ?>
                <select name="area" class="form-control" required>
                  <?php foreach ($my_areas as $ma): ?>
                  <option value="<?=htmlspecialchars($ma)?>" <?= (strcasecmp($prefill_area, $ma) === 0) ? 'selected' : '' ?>><?=htmlspecialchars($ma)?></option>
                  <?php endforeach; ?>
                </select>
                <small class="text-muted d-block mt-1">Select from your assigned areas.</small>
              <?php elseif ($my_areas !== null && count($my_areas) === 1): ?>
                <input type="text" name="area" class="form-control" value="<?=htmlspecialchars($my_areas[0])?>" readonly>
                <small class="text-muted d-block mt-1">Area is locked to your assigned area (<?=htmlspecialchars($my_areas[0])?>).</small>
              <?php else: ?>
                <input type="text" name="area" class="form-control" list="areaSuggestions" value="<?=htmlspecialchars($prefill_area)?>" placeholder="e.g. Johar Town, Gulberg" autocomplete="off">
                <datalist id="areaSuggestions">
                  <?php foreach ($all_areas as $ar): ?>
                  <option value="<?=htmlspecialchars($ar['name'])?>"><?=htmlspecialchars($ar['city'])?></option>
                  <?php endforeach; ?>
                </datalist>
                <small class="text-muted d-block mt-1">Type to select from registered areas or enter new.</small>
              <?php endif; ?>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Opening Balance</label>
              <input type="number" name="opening_balance" step="0.01" class="form-control" value="0">
              <small class="text-muted d-block mt-1">+ = credit already given (to receive)</small>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Notes</label>
              <input type="text" name="notes" class="form-control" placeholder="Optional">
            </div>
            <div class="col-md-12 mb-3">
              <label class="form-label font-weight-bold">Address</label>
              <input type="text" name="address" class="form-control" placeholder="Optional">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal"><i class="fas fa-times mr-1"></i> Cancel</button>
          <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save mr-1"></i> Save Customer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  var urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('add') === '1' || urlParams.get('action') === 'add') {
    $('#addCustomerModal').modal('show');
  }
});
</script>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>