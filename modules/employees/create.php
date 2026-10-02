<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Add Employee';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$auto_username = '';
$all_areas = $pdo->query("SELECT id, name, city FROM areas WHERE status = 1 ORDER BY name ASC")->fetchAll();
$employee_types = allEmployeeTypes($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $employee_type = $_POST['employee_type'] ?? 'salesman';
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
    $password = $_POST['password'] ?? '';

    if ($full_name === '') { redirect('create.php', 'Please enter employee name', 'error'); }
    $valid_types = array_keys($employee_types);
    if (!in_array($employee_type, $valid_types, true)) { $employee_type = 'salesman'; }

    $emp_code = trim($_POST['emp_code'] ?? '');
    if ($emp_code !== '') {
        $chk = $pdo->prepare("SELECT id FROM employees WHERE emp_code = ?");
        $chk->execute([$emp_code]);
        if ($chk->fetch()) $emp_code = '';
    }
    if ($emp_code === '') $emp_code = generateEmployeeCode();

    $needs_login = $employee_type === 'order_booker';
    if ($needs_login) {
        if ($username === '') { redirect('create.php', 'Please enter a login username', 'error'); }
        if (strlen($password) < 4) { redirect('create.php', 'Password must be at least 4 characters', 'error'); }
        $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $chk->execute([$username]);
        if ($chk->fetch()) { redirect('create.php', 'Username "' . $username . '" already exists. Choose another.', 'error'); }
    }

    $pdo->beginTransaction();
    try {
        $user_id = null;
        if ($needs_login) {
            insert('users', [
                'username' => $username,
                'password' => $password,
                'full_name' => $full_name,
                'phone' => $phone,
                'role' => 'order_booker',
                'status' => 1,
                'created_at' => date('Y-m-d'),
            ]);
            $user_id = $pdo->lastInsertId();
        }
        insert('employees', [
            'user_id' => $user_id,
            'emp_code' => $emp_code,
            'full_name' => $full_name,
            'employee_type' => $employee_type,
            'phone' => $phone,
            'area' => $area,
            'cnic' => $cnic,
            'address' => $address,
            'joining_date' => $joining_date,
            'salary' => $salary,
            'status' => 1,
            'created_at' => date('Y-m-d'),
        ]);
        $login_note = $needs_login ? ' with login: ' . $username : ' (no login)';
        logActivity($pdo, 'add', 'employee', $pdo->lastInsertId(), 'Added employee ' . $full_name . ' (' . $employee_type . ')' . $login_note);
        $pdo->commit();
        $success_msg = 'Employee "' . $full_name . '" added successfully.';
        if ($needs_login) $success_msg .= ' Login: ' . $username;
        redirect('index.php', $success_msg);
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('create.php', 'Error: ' . $e->getMessage(), 'error');
    }
}

$next_emp_code = generateEmployeeCode();
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header">
    <h6><i class="fas fa-user-plus"></i> Add Employee</h6>
  </div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="emp_code" value="<?=htmlspecialchars($next_emp_code)?>">
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">Employee ID (Auto) *</label>
          <input type="text" class="form-control font-weight-bold text-success bg-light" value="<?=htmlspecialchars($next_emp_code)?>" readonly>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Employee Name *</label>
          <input type="text" name="full_name" class="form-control" required placeholder="e.g. Muhammad Ali">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Employee Type *</label>
          <div class="input-group">
            <select name="employee_type" class="form-control" required id="empTypeSelect">
              <?php foreach ($employee_types as $k => $v): ?>
              <option value="<?=htmlspecialchars($k)?>" <?= ($employee_type ?? 'salesman') === $k ? 'selected' : '' ?>><?=htmlspecialchars($v)?></option>
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
          <input type="text" name="phone" class="form-control" placeholder="03xx-xxxxxxx">
        </div>
        <div class="col-md-12 mb-3">
          <label class="form-label font-weight-bold">Assigned Areas / Territories <small class="text-muted">(Order Booker / Salesman can have multiple areas)</small></label>
          <div class="border rounded p-3 bg-light">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="small text-muted">Select one or more areas from below:</span>
              <div>
                <button type="button" class="btn btn-xs btn-outline-primary btn-sm py-0 px-2" id="selectAllAreas">Select All</button>
                <button type="button" class="btn btn-xs btn-outline-secondary btn-sm py-0 px-2 ml-1" id="clearAllAreas">Clear</button>
              </div>
            </div>
            <div class="row">
              <?php foreach ($all_areas as $ar): ?>
              <div class="col-md-3 col-sm-6 mb-2">
                <div class="custom-control custom-checkbox">
                  <input type="checkbox" name="areas[]" value="<?=htmlspecialchars($ar['name'])?>" class="custom-control-input area-checkbox" id="area_check_<?=$ar['id']?>">
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
          <input type="text" name="cnic" class="form-control" placeholder="xxxxx-xxxxxxx-x">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Joining Date</label>
          <input type="date" name="joining_date" class="form-control" value="<?=date('Y-m-d')?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Monthly Salary (PKR)</label>
          <input type="number" name="salary" step="0.01" min="0" class="form-control" placeholder="0.00">
        </div>
        <div class="col-md-12 mb-3">
          <label class="form-label">Address</label>
          <input type="text" name="address" class="form-control" placeholder="Full address">
        </div>
        <div class="col-12">
          <hr>
          <h6 class="text-primary"><i class="fas fa-lock"></i> Login Account Details</h6>
          <p class="text-muted small" id="loginHint">For <strong>Order Booker</strong>, login is required. For <strong>Salesman</strong> and <strong>Loader</strong>, no login is needed.</p>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Login Username <span id="usernameReq" class="text-danger">*</span></label>
          <input type="text" name="username" class="form-control" id="usernameInput" placeholder="e.g. ali_theekan" value="<?= htmlspecialchars($auto_username) ?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Password <span id="passwordReq" class="text-danger">*</span></label>
          <input type="text" name="password" class="form-control" id="passwordInput" placeholder="Min 4 characters" minlength="4">
        </div>
        <div class="col-12 mt-2 d-flex justify-content-between">
          <a href="index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back</a>
          <button type="submit" class="btn btn-primary px-5"><i class="fas fa-save"></i> Save Employee</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Add New Employee Type -->
<div class="modal fade" id="addEmpTypeModal" tabindex="-1" role="dialog" aria-labelledby="addEmpTypeModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title font-weight-bold text-dark" id="addEmpTypeModalLabel">
          <i class="fas fa-id-badge text-primary mr-2"></i> Add Employee Type
        </h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <div class="form-group mb-3">
          <label class="form-label font-weight-bold">Type Name *</label>
          <input type="text" id="newEmpTypeName" class="form-control" placeholder="e.g. Driver, Helper, Accountant, Manager" autocomplete="off" maxlength="50">
          <small class="text-muted">Enter a custom role or designation for your staff.</small>
        </div>
        <div id="addTypeAlert" class="alert alert-danger py-2 small d-none mb-0"></div>
        <div class="mt-3">
          <label class="form-label font-weight-bold text-xs text-uppercase text-muted mb-1">Existing Types:</label>
          <div class="d-flex flex-wrap" id="existingTypesBadgeList">
            <?php foreach ($employee_types as $k => $v): ?>
            <span class="badge badge-light border text-dark mr-1 mb-1"><?=htmlspecialchars($v)?></span>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="modal-footer bg-light py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-sm btn-primary font-weight-bold" id="btnSaveNewEmpType">
          <i class="fas fa-check mr-1"></i> Add Type
        </button>
      </div>
    </div>
  </div>
</div>

<script>
  (function() {
    var typeSelect = document.getElementById('empTypeSelect');
    var usernameInput = document.getElementById('usernameInput');
    var passwordInput = document.getElementById('passwordInput');
    var usernameReq = document.getElementById('usernameReq');
    var passwordReq = document.getElementById('passwordReq');
    var loginHint = document.getElementById('loginHint');

    function updateLoginFields() {
      var needsLogin = typeSelect.value === 'order_booker';
      usernameInput.required = needsLogin;
      passwordInput.required = needsLogin;
      usernameReq.style.display = needsLogin ? 'inline' : 'none';
      passwordReq.style.display = needsLogin ? 'inline' : 'none';
      var selText = typeSelect.options[typeSelect.selectedIndex] ? typeSelect.options[typeSelect.selectedIndex].text : 'Other';
      loginHint.innerHTML = needsLogin
        ? 'For <strong>Order Booker</strong>, login is required.'
        : 'For <strong>' + selText + '</strong>, no login is needed.';
    }

    typeSelect.addEventListener('change', updateLoginFields);
    updateLoginFields();

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

    // ===== AJAX ADD EMPLOYEE TYPE =====
    var btnSaveType = document.getElementById('btnSaveNewEmpType');
    var inputTypeName = document.getElementById('newEmpTypeName');
    var alertBox = document.getElementById('addTypeAlert');

    if (btnSaveType && inputTypeName) {
      function submitNewType() {
        var val = (inputTypeName.value || '').trim();
        if (!val) {
          alertBox.textContent = 'Please enter an Employee Type name.';
          alertBox.classList.remove('d-none');
          inputTypeName.focus();
          return;
        }
        alertBox.classList.add('d-none');
        btnSaveType.disabled = true;
        btnSaveType.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Saving...';

        $.ajax({
          url: 'ajax_add_employee_type.php',
          method: 'POST',
          dataType: 'json',
          data: { name: val },
          success: function(res) {
            btnSaveType.disabled = false;
            btnSaveType.innerHTML = '<i class="fas fa-check mr-1"></i> Add Type';
            if (res && res.success) {
              var exists = false;
              for (var i = 0; i < typeSelect.options.length; i++) {
                if (typeSelect.options[i].value === res.code) {
                  typeSelect.selectedIndex = i;
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

              var badgeList = document.getElementById('existingTypesBadgeList');
              if (badgeList && !exists) {
                var badge = document.createElement('span');
                badge.className = 'badge badge-light border text-dark mr-1 mb-1';
                badge.textContent = res.name;
                badgeList.appendChild(badge);
              }

              inputTypeName.value = '';
              $('#addEmpTypeModal').modal('hide');
            } else {
              alertBox.textContent = res && res.message ? res.message : 'Error adding employee type.';
              alertBox.classList.remove('d-none');
            }
          },
          error: function(xhr) {
            btnSaveType.disabled = false;
            btnSaveType.innerHTML = '<i class="fas fa-check mr-1"></i> Add Type';
            alertBox.textContent = 'Server error. Please try again.';
            alertBox.classList.remove('d-none');
          }
        });
      }

      btnSaveType.addEventListener('click', submitNewType);
      inputTypeName.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          submitNewType();
        }
      });
      $('#addEmpTypeModal').on('shown.bs.modal', function() {
        alertBox.classList.add('d-none');
        inputTypeName.value = '';
        inputTypeName.focus();
      });
    }
  })();
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>