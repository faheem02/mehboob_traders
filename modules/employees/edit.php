<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Edit Employee';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$id = (int)($_GET['id'] ?? 0);
$emp = $id ? getById('employees', $id) : null;
if (!$emp) redirect('index.php', 'Employee not found', 'error');

$emp_user = !empty($emp['user_id']) ? getById('users', $emp['user_id']) : null;
$role_map = ['salesman' => 'salesman', 'order_booker' => 'order_booker', 'loader' => 'loader'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $employee_type = $_POST['employee_type'] ?? $emp['employee_type'];
    $phone = trim($_POST['phone'] ?? '');
    $area = trim($_POST['area'] ?? '');
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

        if ($emp_user) {
            $user_data = ['username' => $username ?: $emp_user['username'], 'role' => $role_map[$employee_type], 'phone' => $phone];
            if ($password !== '') {
                if (strlen($password) < 4) throw new Exception('Password must be at least 4 characters');
                $user_data['password'] = $password;
            }
            update('users', $user_data, $emp_user['id']);
        } elseif ($username !== '' && $password !== '') {
            if (strlen($password) < 4) throw new Exception('Password must be at least 4 characters');
            $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $chk->execute([$username]);
            if ($chk->fetch()) throw new Exception('Username "' . $username . '" already exists');
            insert('users', [
                'username' => $username, 'password' => $password, 'full_name' => $full_name,
                'phone' => $phone, 'role' => $role_map[$employee_type], 'status' => 1, 'created_at' => date('Y-m-d'),
            ]);
            update('employees', ['user_id' => $pdo->lastInsertId()], $id);
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
        <div class="col-md-6 mb-3">
          <label class="form-label">Area / Territory (for Salesman)</label>
          <input type="text" name="area" class="form-control" value="<?=htmlspecialchars($emp['area'] ?? '')?>" placeholder="e.g. Johar Town">
          <small class="text-muted d-block mt-1">The salesman will only see customers of this area.</small>
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
          <p class="text-muted small"><?= $emp_user ? 'The employee login account will remain the same. Enter a new password only to change it.' : 'This employee has no login yet. Provide a username and password to create the account.' ?></p>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Login Username</label>
          <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($emp_user['username'] ?? '') ?>" <?= $emp_user ? 'required' : '' ?>>
        </div>
        <div class="col-md-6 mb-3">
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

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>