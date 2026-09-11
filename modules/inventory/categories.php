<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Product Categories';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    if ($name !== '') {
        insert('categories', [
            'name' => $name,
            'description' => $description,
            'status' => 1,
            'created_at' => date('Y-m-d'),
        ]);
        logActivity($pdo, 'create', 'category', null, 'Created category: ' . $name);
        redirect('categories.php', 'Category added successfully');
    } else {
        redirect('categories.php', 'Category name is required', 'error');
    }
}

$categories = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) as product_count FROM categories c ORDER BY c.name ASC")->fetchAll();
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="row mb-3">
  <div class="col-lg-8">
    <div class="card shadow">
      <div class="card-header"><h6><i class="fas fa-tags"></i> Add Category</h6></div>
      <div class="card-body">
        <form method="post" class="row g-2">
          <div class="col-md-5">
            <label class="form-label">Category Name *</label>
            <input type="text" name="name" class="form-control" required placeholder="e.g. Biscuits">
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
  <div class="card-header"><h6><i class="fas fa-list"></i> All Categories (<?=count($categories)?>)</h6></div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered table-hover">
        <thead>
          <tr><th>#</th><th>Name</th><th>Description</th><th>Products</th><th>Status</th><th>Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($categories as $i => $c): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td class="font-weight-bold"><?=htmlspecialchars($c['name'])?></td>
            <td><?=htmlspecialchars($c['description'] ?? '-')?></td>
            <td><span class="badge badge-info"><?=(int)$c['product_count']?></span></td>
            <td><?= $c['status'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-secondary">Inactive</span>' ?></td>
            <td>
              <form method="post" action="category_delete.php" class="d-inline" onsubmit="return confirm('Delete this category? Products will be uncategorized.')">
                <input type="hidden" name="id" value="<?=$c['id']?>">
                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($categories)): ?>
          <tr><td colspan="6" class="text-center text-muted py-3">No categories yet. Add one above.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>