<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Edit Employee';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$id = (int)($_GET['id'] ?? 0);
$emp = $id ? getById('employees', $id) : null;
if (!$emp) redirect('index.php', 'Employee not found', 'error');

$emp_user = !empty($emp['user_id']) ? getById('users', $emp['user_id']) : null;
$all_areas = $pdo->query("SELECT id, name, city FROM areas WHERE status = 1 ORDER BY name ASC")->fetchAll();
$employee_types = allEmployeeTypes($pdo);
$all_emp_types_detail = $pdo->query("SELECT id, code, name, is_system FROM employee_types ORDER BY is_system DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$current_emp_areas = array_map('trim', explode(',', $emp['area'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $employee_type = $_POST['employee_type'] ?? $emp['employee_type'];
    $phone = trim($_POST['phone'] ?? '');
    
    // Process multiple selected areas
    $selected_areas = $_POST['areas'] ?? [];
    if (!is_array($selected_areas)) { $selected_areas = []; }
    $selected_areas = array_filter(array_map('trim', $selected_areas));
    $area = implode(', ', $selected_areas);
    $cnic = trim($_POST['cnic'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $joining_date = $_POST['joining_date'] ?: null;
    $salary = (float)($_POST['salary'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($full_name === '') { redirect('edit.php?id=' . $id, 'Please enter employee name', 'error'); }
    $valid_types = array_keys($employee_types);
    if (!in_array($employee_type, $valid_types, true)) { $employee_type = $emp['employee_type']; }

    $emp_code = trim($_POST['emp_code'] ?? $emp['emp_code'] ?? '');
    if ($emp_code !== '') {
        $chk = $pdo->prepare("SELECT id FROM employees WHERE emp_code = ? AND id != ?");
        $chk->execute([$emp_code, $id]);
        if ($chk->fetch()) $emp_code = '';
    }
    if ($emp_code === '') $emp_code = generateEmployeeCode();

    $pdo->beginTransaction();
    try {
        update('employees', [
            'full_name' => $full_name,
            'emp_code' => $emp_code,
            'employee_type' => $employee_type,
            'phone' => $phone,
            'area' => $area,
            'cnic' => $cnic,
            'address' => $address,
            'joining_date' => $joining_date,
            'salary' => $salary,
            'updated_at' => date('Y-m-d'),
        ], $id);

        if ($employee_type === 'order_booker') {
            if ($emp_user) {
                $user_data = ['username' => $username ?: $emp_user['username'], 'role' => 'order_booker', 'phone' => $phone];
                if ($password !== '') {
                    if (strlen($password) < 4) throw new Exception('Password must be at least 4 characters');
                    $user_data['password'] = $password;
                }
                update('users', $user_data, $emp_user['id']);
            } else {
                if ($username === '') throw new Exception('Please enter a login username');
                if (strlen($password) < 4) throw new Exception('Password must be at least 4 characters');
                $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $chk->execute([$username]);
                if ($chk->fetch()) throw new Exception('Username "' . $username . '" already exists');
                insert('users', [
                    'username' => $username, 'password' => $password, 'full_name' => $full_name,
                    'phone' => $phone, 'role' => 'order_booker', 'status' => 1, 'created_at' => date('Y-m-d'),
                ]);
                update('employees', ['user_id' => $pdo->lastInsertId()], $id);
            }
        } elseif ($employee_type !== 'order_booker' && $emp_user) {
            // Salesman/Loader never get a login account (only admin + order taker have users)
            delete('users', $emp_user['id']);
            update('employees', ['user_id' => null], $id);
        }

        logActivity($pdo, 'edit', 'employee', $id, 'Edited employee ' . $full_name);
        $pdo->commit();
        redirect('edit.php?id=' . $id, 'Employee "' . $full_name . '" updated successfully');
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('edit.php?id=' . $id, 'Error: ' . $e->getMessage(), 'error');
    }
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header">
    <h6><i class="fas fa-user-edit"></i> Edit Employee</h6>
  </div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="emp_code" value="<?=htmlspecialchars($emp['emp_code'] ?? '')?>">
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">Employee ID (Auto) *</label>
          <input type="text" class="form-control font-weight-bold text-success bg-light" value="<?=htmlspecialchars($emp['emp_code'] ?? '')?>" readonly>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Employee Name *</label>
          <input type="text" name="full_name" class="form-control" required value="<?=htmlspecialchars($emp['full_name'])?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Employee Type *</label>
          <div class="input-group">
            <select name="employee_type" class="form-control" required id="empTypeSelect">
              <?php foreach ($employee_types as $k => $v): ?>
              <option value="<?=htmlspecialchars($k)?>" <?= $emp['employee_type'] === $k ? 'selected' : '' ?>><?=htmlspecialchars($v)?></option>
              <?php endforeach; ?>
            </select>
            <div class="input-group-append">
              <button type="button" class="btn btn-outline-primary" data-toggle="modal" data-target="#addEmpTypeModal" title="Add New Employee Type">
                <i class="fas fa-plus"></i>
              </button>
            </div>
          </div>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Phone</label>
          <input type="text" name="phone" class="form-control" value="<?=htmlspecialchars($emp['phone'] ?? '')?>">
        </div>
        <div class="col-md-12 mb-3">
          <label class="form-label font-weight-bold">Assigned Areas / Territories <small class="text-muted">(Order Booker / Salesman can have multiple areas)</small></label>
          <div class="border rounded p-3 bg-light">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="small text-muted">Check one or more assigned areas:</span>
              <div>
                <button type="button" class="btn btn-xs btn-outline-primary btn-sm py-0 px-2" id="selectAllAreas">Select All</button>
                <button type="button" class="btn btn-xs btn-outline-secondary btn-sm py-0 px-2 ml-1" id="clearAllAreas">Clear</button>
              </div>
            </div>
            <div class="row">
              <?php foreach ($all_areas as $ar): 
                $is_checked = false;
                foreach ($current_emp_areas as $cea) {
                    if (strcasecmp($cea, $ar['name']) === 0) { $is_checked = true; break; }
                }
              ?>
              <div class="col-md-3 col-sm-6 mb-2">
                <div class="custom-control custom-checkbox">
                  <input type="checkbox" name="areas[]" value="<?=htmlspecialchars($ar['name'])?>" class="custom-control-input area-checkbox" id="area_check_<?=$ar['id']?>" <?= $is_checked ? 'checked' : '' ?>>
                  <label class="custom-control-label font-weight-normal" for="area_check_<?=$ar['id']?>">
                    <?=htmlspecialchars($ar['name'])?> <small class="text-muted">(<?=htmlspecialchars($ar['city'])?>)</small>
                  </label>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <small class="text-muted d-block mt-1">The Order Booker / Salesman will only see customers belonging to their assigned areas. To add more areas, use the <strong>Areas</strong> page in the sidebar.</small>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">CNIC</label>
          <input type="text" name="cnic" class="form-control" value="<?=htmlspecialchars($emp['cnic'] ?? '')?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Joining Date</label>
          <input type="date" name="joining_date" class="form-control" value="<?=$emp['joining_date']??date('Y-m-d')?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Monthly Salary (PKR)</label>
          <input type="number" name="salary" step="0.01" min="0" class="form-control" value="<?=htmlspecialchars($emp['salary'])?>">
        </div>
        <div class="col-md-12 mb-3">
          <label class="form-label">Address</label>
          <input type="text" name="address" class="form-control" value="<?=htmlspecialchars($emp['address'] ?? '')?>">
        </div>
        <div class="col-12">
          <hr>
          <h6 class="text-primary"><i class="fas fa-lock"></i> Login Account</h6>
          <p class="text-muted small"><?php if ($emp['employee_type'] === 'order_booker'): ?>
            Order Bookers get a mobile login to take orders. Enter a new password only to change it.
          <?php elseif ($emp_user): ?>
            This employee is being changed to a Salesman/Loader — their login account will be removed (only Order Bookers have logins).
          <?php else: ?>
            Salesman / Loader employees do not get a login account. They work from the printed delivery list.
          <?php endif; ?></p>
        </div>
        <div class="col-md-6 mb-3" id="loginUsernameDiv">
          <label class="form-label">Login Username</label>
          <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($emp_user['username'] ?? '') ?>" <?= $emp['employee_type'] === 'order_booker' && $emp_user ? 'required' : '' ?>>
        </div>
        <div class="col-md-6 mb-3" id="loginPasswordDiv">
          <label class="form-label"><?= $emp_user ? 'New Password (optional)' : 'Password *' ?></label>
          <input type="text" name="password" class="form-control" placeholder="<?= $emp_user ? 'Leave blank to keep current' : 'Min 4 characters' ?>" minlength="4">
        </div>
        <div class="col-12 mt-2 d-flex justify-content-between">
          <a href="index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back</a>
          <button type="submit" class="btn btn-primary px-5"><i class="fas fa-save"></i> Update Employee</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Add / Manage Employee Type -->
<div class="modal fade" id="addEmpTypeModal" tabindex="-1" role="dialog" aria-labelledby="addEmpTypeModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title font-weight-bold text-dark" id="addEmpTypeModalLabel">
          <i class="fas fa-id-badge text-primary mr-2"></i> Employee Types & Categories
        </h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <!-- Editing Banner (Hidden by default, shown when editing) -->
        <div id="editingTypeBanner" class="alert alert-warning py-1 px-3 small d-none d-flex justify-content-between align-items-center mb-3">
          <span><i class="fas fa-edit mr-1"></i> Editing: <strong id="editingTypeNameDisplay">-</strong></span>
          <button type="button" class="btn btn-xs btn-outline-dark" id="btnCancelEditType"><i class="fas fa-times mr-1"></i> Cancel</button>
        </div>

        <input type="hidden" id="editEmpTypeId" value="">

        <div class="form-group mb-2">
          <label class="form-label font-weight-bold" id="empTypeInputLabel">Type Name *</label>
          <div class="input-group">
            <input type="text" id="newEmpTypeName" class="form-control" placeholder="e.g. Driver, Delivery Staff, Helper" autocomplete="off" maxlength="50">
            <div class="input-group-append">
              <button type="button" class="btn btn-primary font-weight-bold" id="btnSaveNewEmpType">
                <i class="fas fa-plus mr-1"></i> <span id="btnSaveTypeText">Add Type</span>
              </button>
            </div>
          </div>
          <small class="text-muted" id="empTypeInputHelp">Enter a role name. To fix a mistake in an existing name, click <strong>Edit</strong> in the list below.</small>
        </div>

        <div id="addTypeAlert" class="alert alert-danger py-2 small d-none mb-2"></div>
        <div id="addTypeSuccess" class="alert alert-success py-2 small d-none mb-2"></div>

        <div class="mt-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <label class="form-label font-weight-bold text-xs text-uppercase text-muted mb-0">Existing Types / Categories:</label>
            <small class="text-muted"><i class="fas fa-pencil-alt text-primary"></i> Click Edit to rename</small>
          </div>
          <div class="table-responsive border rounded bg-white" style="max-height: 220px; overflow-y: auto;">
            <table class="table table-sm table-hover mb-0" id="empTypesTable">
              <thead class="bg-light text-muted small">
                <tr>
                  <th style="width: 50%;">Type Name</th>
                  <th style="width: 25%;" class="text-center">Role</th>
                  <th style="width: 25%;" class="text-center">Action</th>
                </tr>
              </thead>
              <tbody id="empTypesTableBody">
                <?php foreach ($all_emp_types_detail as $td): ?>
                <tr id="empTypeRow_<?=$td['id']?>" data-id="<?=$td['id']?>" data-code="<?=htmlspecialchars($td['code'])?>" data-name="<?=htmlspecialchars($td['name'])?>">
                  <td class="align-middle">
                    <span class="font-weight-bold text-dark emp-type-name-text"><?=htmlspecialchars($td['name'])?></span>
                  </td>
                  <td class="align-middle text-center">
                    <?php if (!empty($td['is_system'])): ?>
                      <span class="badge badge-secondary" style="font-size: 10px;">Core Role</span>
                    <?php else: ?>
                      <span class="badge badge-info" style="font-size: 10px;">Custom</span>
                    <?php endif; ?>
                  </td>
                  <td class="align-middle text-center">
                    <button type="button" class="btn btn-xs btn-outline-primary btn-edit-emp-type py-0 px-2" data-id="<?=$td['id']?>" data-code="<?=htmlspecialchars($td['code'])?>" data-name="<?=htmlspecialchars($td['name'])?>" title="Edit Name">
                      <i class="fas fa-edit"></i> Edit
                    </button>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="modal-footer bg-light py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var typeSelect = document.getElementById('empTypeSelect');
  var selectAllBtn = document.getElementById('selectAllAreas');
  var clearAllBtn = document.getElementById('clearAllAreas');
  if (selectAllBtn) {
    selectAllBtn.addEventListener('click', function(){
      document.querySelectorAll('.area-checkbox').forEach(function(cb){ cb.checked = true; });
    });
  }
  if (clearAllBtn) {
    clearAllBtn.addEventListener('click', function(){
      document.querySelectorAll('.area-checkbox').forEach(function(cb){ cb.checked = false; });
    });
  }

  // ===== AJAX MANAGE EMPLOYEE TYPE (ADD & EDIT) =====
  var btnSaveType = document.getElementById('btnSaveNewEmpType');
  var inputTypeName = document.getElementById('newEmpTypeName');
  var editIdInput = document.getElementById('editEmpTypeId');
  var alertBox = document.getElementById('addTypeAlert');
  var successBox = document.getElementById('addTypeSuccess');
  var banner = document.getElementById('editingTypeBanner');
  var bannerName = document.getElementById('editingTypeNameDisplay');
  var cancelEditBtn = document.getElementById('btnCancelEditType');
  var inputLabel = document.getElementById('empTypeInputLabel');

  function resetTypeForm() {
    if (editIdInput) editIdInput.value = '';
    if (inputTypeName) inputTypeName.value = '';
    if (banner) banner.classList.add('d-none');
    if (alertBox) alertBox.classList.add('d-none');
    if (successBox) successBox.classList.add('d-none');
    if (inputLabel) inputLabel.textContent = 'Type Name *';
    if (btnSaveType) {
      btnSaveType.className = 'btn btn-primary font-weight-bold';
      btnSaveType.innerHTML = '<i class="fas fa-plus mr-1"></i> <span id="btnSaveTypeText">Add Type</span>';
    }
  }

  if (cancelEditBtn) {
    cancelEditBtn.addEventListener('click', function(e) {
      e.preventDefault();
      resetTypeForm();
      inputTypeName.focus();
    });
  }

  $(document).on('click', '.btn-edit-emp-type', function(e) {
    e.preventDefault();
    var id = $(this).data('id');
    var name = $(this).data('name');
    editIdInput.value = id;
    inputTypeName.value = name;
    bannerName.textContent = name;
    banner.classList.remove('d-none');
    alertBox.classList.add('d-none');
    successBox.classList.add('d-none');
    inputLabel.textContent = 'Edit Type Name *';
    btnSaveType.className = 'btn btn-warning font-weight-bold text-dark';
    btnSaveType.innerHTML = '<i class="fas fa-save mr-1"></i> <span id="btnSaveTypeText">Update Name</span>';
    inputTypeName.focus();
    inputTypeName.select();
  });

  if (btnSaveType && inputTypeName && typeSelect) {
    function submitType() {
      var val = (inputTypeName.value || '').trim();
      var editId = (editIdInput.value || '').trim();
      if (!val) {
        alertBox.textContent = 'Please enter an Employee Type name.';
        alertBox.classList.remove('d-none');
        successBox.classList.add('d-none');
        inputTypeName.focus();
        return;
      }
      alertBox.classList.add('d-none');
      successBox.classList.add('d-none');
      btnSaveType.disabled = true;
      btnSaveType.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Saving...';

      var url = editId ? 'ajax_edit_employee_type.php' : 'ajax_add_employee_type.php';
      var postData = editId ? { id: editId, name: val } : { name: val };

      $.ajax({
        url: url,
        method: 'POST',
        dataType: 'json',
        data: postData,
        success: function(res) {
          btnSaveType.disabled = false;
          if (res && res.success) {
            if (editId) {
              // Update dropdown option text
              for (var i = 0; i < typeSelect.options.length; i++) {
                if (typeSelect.options[i].value === res.code) {
                  typeSelect.options[i].textContent = res.name;
                  break;
                }
              }
              // Update table row
              var $row = $('#empTypeRow_' + res.id);
              if ($row.length) {
                $row.find('.emp-type-name-text').text(res.name);
                $row.find('.btn-edit-emp-type').data('name', res.name).attr('data-name', res.name);
              }
              successBox.textContent = res.message || 'Employee type updated successfully!';
              successBox.classList.remove('d-none');
              resetTypeForm();
            } else {
              // Add new type
              var exists = false;
              for (var j = 0; j < typeSelect.options.length; j++) {
                if (typeSelect.options[j].value === res.code) {
                  typeSelect.selectedIndex = j;
                  typeSelect.options[j].textContent = res.name;
                  exists = true;
                  break;
                }
              }
              if (!exists) {
                var opt = document.createElement('option');
                opt.value = res.code;
                opt.textContent = res.name;
                opt.selected = true;
                typeSelect.appendChild(opt);
                typeSelect.value = res.code;
              }
              typeSelect.dispatchEvent(new Event('change'));

              // Append to table if not exists
              if (!$('#empTypeRow_' + res.id).length) {
                var newRow = '<tr id="empTypeRow_' + res.id + '" data-id="' + res.id + '" data-code="' + res.code + '" data-name="' + res.name + '">' +
                  '<td class="align-middle"><span class="font-weight-bold text-dark emp-type-name-text">' + res.name + '</span></td>' +
                  '<td class="align-middle text-center"><span class="badge badge-info" style="font-size: 10px;">Custom</span></td>' +
                  '<td class="align-middle text-center">' +
                  '<button type="button" class="btn btn-xs btn-outline-primary btn-edit-emp-type py-0 px-2" data-id="' + res.id + '" data-code="' + res.code + '" data-name="' + res.name + '" title="Edit Name">' +
                  '<i class="fas fa-edit"></i> Edit' +
                  '</button>' +
                  '</td>' +
                  '</tr>';
                $('#empTypesTableBody').append(newRow);
              }

              successBox.textContent = res.message || 'Employee type added successfully!';
              successBox.classList.remove('d-none');
              resetTypeForm();
            }
          } else {
            btnSaveType.className = editId ? 'btn btn-warning font-weight-bold text-dark' : 'btn btn-primary font-weight-bold';
            btnSaveType.innerHTML = editId
              ? '<i class="fas fa-save mr-1"></i> <span id="btnSaveTypeText">Update Name</span>'
              : '<i class="fas fa-plus mr-1"></i> <span id="btnSaveTypeText">Add Type</span>';
            alertBox.textContent = res && res.message ? res.message : 'Error processing employee type.';
            alertBox.classList.remove('d-none');
          }
        },
        error: function(xhr) {
          btnSaveType.disabled = false;
          btnSaveType.className = editId ? 'btn btn-warning font-weight-bold text-dark' : 'btn btn-primary font-weight-bold';
          btnSaveType.innerHTML = editId
            ? '<i class="fas fa-save mr-1"></i> <span id="btnSaveTypeText">Update Name</span>'
            : '<i class="fas fa-plus mr-1"></i> <span id="btnSaveTypeText">Add Type</span>';
          alertBox.textContent = 'Server error. Please try again.';
          alertBox.classList.remove('d-none');
        }
      });
    }

    btnSaveType.addEventListener('click', submitType);
    inputTypeName.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        submitType();
      }
    });
    $('#addEmpTypeModal').on('hidden.bs.modal', function() {
      resetTypeForm();
    });
  }
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>