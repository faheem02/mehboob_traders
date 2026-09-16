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
    if (!in_array($employee_type, ['salesman','order_booker','loader'])) { $employee_type = $emp['employee_type']; }

    $pdo->beginTransaction();
    try {
        update('employees', [
            'full_name' => $full_name,
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
        redirect('index.php', 'Employee "' . $full_name . '" updated successfully');
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
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">Employee Name *</label>
          <input type="text" name="full_name" class="form-control" required value="<?=htmlspecialchars($emp['full_name'])?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Employee Type *</label>
          <select name="employee_type" class="form-control" required>
            <option value="salesman" <?= $emp['employee_type']=='salesman'?'selected':'' ?>>Salesman</option>
            <option value="order_booker" <?= $emp['employee_type']=='order_booker'?'selected':'' ?>>Order Booker</option>
            <option value="loader" <?= $emp['employee_type']=='loader'?'selected':'' ?>>Loader</option>
          </select>
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

<script>
document.addEventListener('DOMContentLoaded', function(){
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
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>