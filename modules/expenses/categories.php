<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Expense Categories';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    if ($name !== '') {
        insert('expense_categories', [
            'name' => $name,
            'description' => trim($_POST['description'] ?? ''),
            'status' => 1,
            'created_at' => date('Y-m-d'),
        ]);
        logActivity($pdo, 'create', 'expense_category', null, 'Created expense category: ' . $name);
        redirect('categories.php', 'Expense category added');
    } else {
        redirect('categories.php', 'Category name is required', 'error');
    }
}

$categories = $pdo->query("SELECT * FROM expense_categories ORDER BY name")->fetchAll();
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="row mb-3">
  <div class="col-lg-8">
    <div class="card shadow">
      <div class="card-header"><h6><i class="fas fa-tags"></i> Add Expense Category</h6></div>
      <div class="card-body">
        <form method="post" class="row g-2">
          <div class="col-md-5">
            <label class="form-label">Category Name *</label>
            <input type="text" name="name" class="form-control" required placeholder="e.g. Rent">
          </div>
          <div class="col-md-5">
            <label class="form-label">Description</label>
            <input type="text" name="description" class="form-control" placeholder="Optional">
          </div>
          <div class="col-md-2 d-flex align-items-end">
            <button class="btn btn-primary btn-block"><i class="fas fa-plus"></i> Add</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="card shadow">
  <div class="card-header"><h6><i class="fas fa-list"></i> All Categories</h6></div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered table-hover">
        <thead><tr><th>#</th><th>Name</th><th>Description</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach ($categories as $i => $c): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td class="font-weight-bold"><?=htmlspecialchars($c['name'])?></td>
            <td><?=htmlspecialchars($c['description'] ?? '-')?></td>
            <td><?= $c['status'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-secondary">Inactive</span>' ?></td>
            <td>
              <form method="post" action="category_delete.php" class="d-inline" onsubmit="return confirm('Delete category?')">
                <input type="hidden" name="id" value="<?=$c['id']?>">
                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>