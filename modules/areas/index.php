<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Areas & Territories';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

// Handle POST actions: add, edit, delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $city = trim($_POST['city'] ?? 'Lahore');
        $description = trim($_POST['description'] ?? '');
        $status = isset($_POST['status']) ? (int)$_POST['status'] : 1;

        if ($name === '') {
            redirect('index.php', 'Area name is required', 'error');
        }

        $check = $pdo->prepare("SELECT id FROM areas WHERE LOWER(name) = LOWER(?)");
        $check->execute([$name]);
        if ($check->fetchColumn()) {
            redirect('index.php', 'An area with this name already exists', 'error');
        }

        $id = insert('areas', [
            'name' => $name,
            'city' => $city ?: 'Lahore',
            'description' => $description,
            'status' => $status,
            'created_at' => date('Y-m-d'),
        ]);

        logActivity($pdo, 'create', 'area', $id, 'Added area: ' . $name);
        redirect('index.php', 'Area "' . $name . '" added successfully.');
    }

    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $city = trim($_POST['city'] ?? 'Lahore');
        $description = trim($_POST['description'] ?? '');
        $status = isset($_POST['status']) ? (int)$_POST['status'] : 1;

        if (!$id || $name === '') {
            redirect('index.php', 'Invalid area data', 'error');
        }

        $old = getById('areas', $id);
        if (!$old) {
            redirect('index.php', 'Area not found', 'error');
        }

        $check = $pdo->prepare("SELECT id FROM areas WHERE LOWER(name) = LOWER(?) AND id <> ?");
        $check->execute([$name, $id]);
        if ($check->fetchColumn()) {
            redirect('index.php', 'Another area with this name already exists', 'error');
        }

        update('areas', [
            'name' => $name,
            'city' => $city ?: 'Lahore',
            'description' => $description,
            'status' => $status,
            'updated_at' => date('Y-m-d'),
        ], $id);

        // If name changed, update existing customer areas if identical
        if (strcasecmp($old['name'], $name) !== 0) {
            $pdo->prepare("UPDATE customers SET area = ? WHERE LOWER(area) = LOWER(?)")->execute([$name, $old['name']]);
        }

        logActivity($pdo, 'update', 'area', $id, 'Updated area: ' . $name);
        redirect('index.php', 'Area "' . $name . '" updated successfully.');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $area = getById('areas', $id);
        if (!$area) {
            redirect('index.php', 'Area not found', 'error');
        }

        // Check if customers exist in this area
        $cust_count = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE LOWER(area) = LOWER(?)");
        $cust_count->execute([$area['name']]);
        $c_count = (int)$cust_count->fetchColumn();

        // Check if employees are assigned to this area
        $emp_count = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE FIND_IN_SET(?, REPLACE(area, ', ', ',')) OR area LIKE ?");
        $emp_count->execute([$area['name'], '%' . $area['name'] . '%']);
        $e_count = (int)$emp_count->fetchColumn();

        if ($c_count > 0 || $e_count > 0) {
            redirect('index.php', "Cannot delete '{$area['name']}': {$c_count} customer(s) and {$e_count} employee(s) are assigned to this area. Deactivate it instead.", 'error');
        }

        $pdo->prepare("DELETE FROM areas WHERE id = ?")->execute([$id]);
        logActivity($pdo, 'delete', 'area', $id, 'Deleted area: ' . $area['name']);
        redirect('index.php', 'Area deleted successfully.');
    }
}

// Fetch all areas with customer and employee counts
$areas = $pdo->query("SELECT a.* FROM areas a ORDER BY a.name ASC")->fetchAll();

$all_customers = $pdo->query("SELECT area FROM customers WHERE area IS NOT NULL AND area <> ''")->fetchAll(PDO::FETCH_COLUMN);
$all_employees = $pdo->query("SELECT area FROM employees WHERE area IS NOT NULL AND area <> ''")->fetchAll(PDO::FETCH_COLUMN);

$total_areas = count($areas);
$active_areas = 0;
foreach ($areas as &$a) {
    if ($a['status'] == 1) $active_areas++;
    $a_name_lower = strtolower(trim($a['name']));
    
    // Count customers
    $a['cust_count'] = 0;
    foreach ($all_customers as $ca) {
        if (strtolower(trim($ca)) === $a_name_lower) $a['cust_count']++;
    }

    // Count employees (supporting multi-area comma-separated strings)
    $a['emp_count'] = 0;
    foreach ($all_employees as $ea) {
        $assigned = array_map('trim', explode(',', strtolower($ea)));
        if (in_array($a_name_lower, $assigned, true)) $a['emp_count']++;
    }
}
unset($a);

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="row mb-3 d-print-none">
  <div class="col-md-8">
    <div class="alert alert-info alert-dismissible fade show py-2 mb-0" role="alert">
      <i class="fas fa-map-marked-alt"></i> <strong>Area &amp; Territory Management</strong> &nbsp;Manage delivery zones and assign areas to Order Bookers &amp; Salesmen.
      <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span>&times;</span></button>
    </div>
  </div>
  <div class="col-md-4 text-md-right">
    <button type="button" class="btn btn-outline-secondary shadow-sm mr-2" onclick="window.print()">
      <i class="fas fa-print"></i> Print
    </button>
    <button type="button" class="btn btn-success shadow-sm" data-toggle="modal" data-target="#addAreaModal">
      <i class="fas fa-plus-circle"></i> Add Area
    </button>
  </div>
</div>

<!-- Printable header -->
<div class="d-none d-print-block mb-3 text-center">
  <h4 class="font-weight-bold mb-0" style="color:#0f172a;">Mehboob Traders</h4>
  <small class="text-muted">Wholesale Business</small>
  <h5 class="font-weight-bold text-primary mt-2 mb-0">AREAS &amp; TERRITORIES RECORD</h5>
  <small>Printed on <?=formatDate(date('Y-m-d'))?></small>
</div>

<!-- Stat Row -->
<div class="row mb-3 d-print-none">
  <div class="col-xl-3 col-md-6 mb-2">
    <div class="card stat-card shadow-sm border-0 h-100 py-2" style="border-left: 4px solid #3b82f6 !important;">
      <div class="card-body py-2">
        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Areas</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800"><?=$total_areas?></div>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6 mb-2">
    <div class="card stat-card shadow-sm border-0 h-100 py-2" style="border-left: 4px solid #10b981 !important;">
      <div class="card-body py-2">
        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Active Areas</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800"><?=$active_areas?></div>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6 mb-2">
    <div class="card stat-card shadow-sm border-0 h-100 py-2" style="border-left: 4px solid #8b5cf6 !important;">
      <div class="card-body py-2">
        <div class="text-xs font-weight-bold text-uppercase mb-1" style="color:#8b5cf6;">Assigned Customers</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800"><?=count($all_customers)?></div>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6 mb-2">
    <div class="card stat-card shadow-sm border-0 h-100 py-2" style="border-left: 4px solid #f59e0b !important;">
      <div class="card-body py-2">
        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Territory Employees</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800"><?=count($all_employees)?></div>
      </div>
    </div>
  </div>
</div>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6 class="mb-0"><i class="fas fa-map-marker-alt"></i> Registered Areas (<?=$total_areas?>)</h6>
    <input type="text" id="areaSearch" class="form-control form-control-sm d-print-none" placeholder="Search area / city / description" style="max-width:260px;">
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered table-hover" id="areaTable">
        <thead class="thead-light">
          <tr>
            <th style="width: 50px;">#</th>
            <th>Area / Territory Name</th>
            <th>City</th>
            <th>Description</th>
            <th class="text-center">Customers</th>
            <th class="text-center">Employees</th>
            <th class="text-center">Status</th>
            <th class="text-center d-print-none" style="width: 120px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($areas)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No areas added yet. Click "Add Area" to create your first territory.</td></tr>
          <?php else: $i = 0; foreach ($areas as $a): $i++; ?>
            <tr>
              <td><?=$i?></td>
              <td class="font-weight-bold text-primary"><?=htmlspecialchars($a['name'])?></td>
              <td><?=htmlspecialchars($a['city'] ?: 'Lahore')?></td>
              <td><?=htmlspecialchars($a['description'] ?: '—')?></td>
              <td class="text-center">
                <span class="badge badge-pill badge-primary px-2 py-1"><?=$a['cust_count']?></span>
              </td>
              <td class="text-center">
                <span class="badge badge-pill badge-info px-2 py-1"><?=$a['emp_count']?></span>
              </td>
              <td class="text-center">
                <?php if ($a['status'] == 1): ?>
                  <span class="badge badge-success">Active</span>
                <?php else: ?>
                  <span class="badge badge-secondary">Inactive</span>
                <?php endif; ?>
              </td>
              <td class="text-center d-print-none" nowrap>
                <button type="button" class="btn btn-sm btn-outline-warning btn-edit-area" 
                        data-id="<?=$a['id']?>" 
                        data-name="<?=htmlspecialchars($a['name'])?>" 
                        data-city="<?=htmlspecialchars($a['city'])?>" 
                        data-desc="<?=htmlspecialchars($a['description'])?>" 
                        data-status="<?=$a['status']?>" 
                        title="Edit Area">
                  <i class="fas fa-edit"></i>
                </button>
                <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete <?=htmlspecialchars($a['name'])?>?');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?=$a['id']?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Area">
                    <i class="fas fa-trash-alt"></i>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add Area Modal -->
<div class="modal fade" id="addAreaModal" tabindex="-1" role="dialog" aria-labelledby="addAreaModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="add">
        <div class="modal-header">
          <h5 class="modal-title" id="addAreaModalLabel"><i class="fas fa-plus-circle text-success"></i> Add New Area</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label font-weight-bold">Area / Territory Name *</label>
            <input type="text" name="name" class="form-control" required placeholder="e.g. Johar Town, Gulberg, Model Town">
          </div>
          <div class="form-group">
            <label class="form-label font-weight-bold">City</label>
            <input type="text" name="city" class="form-control" value="Lahore" placeholder="e.g. Lahore">
          </div>
          <div class="form-group">
            <label class="form-label font-weight-bold">Description / Details</label>
            <textarea name="description" class="form-control" rows="2" placeholder="Optional notes or landmarks"></textarea>
          </div>
          <div class="form-group mb-0">
            <label class="form-label font-weight-bold">Status</label>
            <select name="status" class="form-control">
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-save mr-1"></i> Save Area</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Area Modal -->
<div class="modal fade" id="editAreaModal" tabindex="-1" role="dialog" aria-labelledby="editAreaModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="editAreaId">
        <div class="modal-header">
          <h5 class="modal-title" id="editAreaModalLabel"><i class="fas fa-edit text-warning"></i> Edit Area</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label font-weight-bold">Area / Territory Name *</label>
            <input type="text" name="name" id="editAreaName" class="form-control" required placeholder="e.g. Johar Town">
          </div>
          <div class="form-group">
            <label class="form-label font-weight-bold">City</label>
            <input type="text" name="city" id="editAreaCity" class="form-control" placeholder="e.g. Lahore">
          </div>
          <div class="form-group">
            <label class="form-label font-weight-bold">Description / Details</label>
            <textarea name="description" id="editAreaDesc" class="form-control" rows="2" placeholder="Optional notes"></textarea>
          </div>
          <div class="form-group mb-0">
            <label class="form-label font-weight-bold">Status</label>
            <select name="status" id="editAreaStatus" class="form-control">
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning"><i class="fas fa-save mr-1"></i> Update Area</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
$(document).ready(function(){
  $('.btn-edit-area').click(function(){
    $('#editAreaId').val($(this).data('id'));
    $('#editAreaName').val($(this).data('name'));
    $('#editAreaCity').val($(this).data('city'));
    $('#editAreaDesc').val($(this).data('desc'));
    $('#editAreaStatus').val($(this).data('status'));
    $('#editAreaModal').modal('show');
  });

  $('#areaSearch').on('keyup', function(){
    var q = $(this).val().toLowerCase();
    $('#areaTable tbody tr').each(function(){
      $(this).toggle($(this).text().toLowerCase().indexOf(q) > -1);
    });
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
