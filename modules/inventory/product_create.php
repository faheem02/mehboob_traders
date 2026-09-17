<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Add Product';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$categories = $pdo->query("SELECT id, name FROM categories WHERE status = 1 ORDER BY name")->fetchAll();
$brands = $pdo->query("SELECT id, name FROM brands WHERE status = 1 ORDER BY name")->fetchAll();
$auto_code = generateProductCode();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '') ?: generateProductCode();
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        redirect('product_create.php', 'Product name is required', 'error');
    }
    // Check unique code
    $chk = $pdo->prepare("SELECT id FROM products WHERE code = ?");
    $chk->execute([$code]);
    if ($chk->fetch()) {
        redirect('product_create.php', 'Product code already exists', 'error');
    }

    insert('products', [
        'code' => $code,
        'name' => $name,
        'description' => trim($_POST['description'] ?? ''),
        'category_id' => $_POST['category_id'] ?: null,
        'brand_id' => $_POST['brand_id'] ?: null,
        'unit' => $_POST['unit'] ?: 'pcs',
        'boxes_per_carton' => (int)($_POST['boxes_per_carton'] ?? 1) > 0 ? (int)$_POST['boxes_per_carton'] : 1,
        'purchase_price' => $_POST['purchase_price'] ?: 0,
        'sale_price' => $_POST['sale_price'] ?: 0,
        'stock_quantity' => $_POST['stock_quantity'] ?: 0,
        'min_stock_level' => $_POST['min_stock_level'] ?: 0,
        'status' => 1,
        'created_at' => date('Y-m-d'),
    ]);
    logActivity($pdo, 'create', 'product', null, 'Created product: ' . $name . ' (code: ' . $code . ')');
    redirect('products.php', 'Product added successfully');
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h6><i class="fas fa-box"></i> Add New Product</h6>
    <a href="products.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-arrow-left"></i> Back to Products</a>
  </div>
  <div class="card-body">
    <form method="post">
      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">Product Name *</label>
          <input type="text" name="name" class="form-control" required placeholder="e.g. Peek Freans Biscuit">
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Item Code</label>
          <div class="input-group">
            <input type="text" name="code" class="form-control" value="<?=htmlspecialchars($auto_code)?>" aria-describedby="codeHint">
            <div class="input-group-append">
              <button type="button" class="btn btn-outline-secondary" id="autoCodeBtn" title="Auto-generate"><i class="fas fa-sync"></i></button>
            </div>
          </div>
          <small class="text-muted" id="codeHint">Auto: <?=htmlspecialchars($auto_code)?> — change it if you want</small>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Unit</label>
          <select name="unit" class="form-control">
            <option value="pcs">Piece (pcs)</option>
            <option value="dozen">Dozen</option>
            <option value="box">Box</option>
            <option value="carton">Carton</option>
            <option value="kg">Kg</option>
            <option value="pack">Pack</option>
            <option value="gadda">Gadda</option>
          </select>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Category</label>
          <select name="category_id" class="form-control">
            <option value="">-- Select --</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?=$c['id']?>"><?=htmlspecialchars($c['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Brand</label>
          <select name="brand_id" class="form-control">
            <option value="">-- Select --</option>
            <?php foreach ($brands as $b): ?>
            <option value="<?=$b['id']?>"><?=htmlspecialchars($b['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Description</label>
          <input type="text" name="description" class="form-control" placeholder="Optional">
        </div>
      </div>

      <hr>
      <h6 class="mb-3 text-secondary">Pricing & Stock</h6>
      <div class="row">
        <div class="col-md-3 mb-3">
          <label class="form-label">Purchase Price *</label>
          <input type="number" step="0.01" min="0" name="purchase_price" class="form-control" required placeholder="0.00">
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Sale Price *</label>
          <input type="number" step="0.01" min="0" name="sale_price" class="form-control" required placeholder="0.00">
        </div>
        <div class="col-md-2 mb-3">
          <label class="form-label">Boxes per Carton</label>
          <input type="number" min="1" name="boxes_per_carton" id="bpcInput" class="form-control" value="1" required>
          <small class="text-muted">Boxes in 1 carton</small>
          <small class="d-block mt-1 font-weight-bold text-success" id="bpcPreview">1 Carton = 1 Box</small>
        </div>
        <div class="col-md-2 mb-3">
          <label class="form-label">Opening Stock (Boxes) *</label>
          <input type="number" min="0" name="stock_quantity" class="form-control" required value="0">
        </div>
        <div class="col-md-2 mb-3">
          <label class="form-label">Min Stock Alert Level</label>
          <input type="number" min="0" name="min_stock_level" class="form-control" value="0">
        </div>
      </div>

      <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save"></i> Save Product</button>
    </form>
  </div>
</div>

<script>
$(document).ready(function(){
  var autoCode = <?= json_encode($auto_code) ?>;
  function updateBpcPreview(){
    var v = parseInt($('#bpcInput').val()) || 1;
    if (v < 1) v = 1;
    $('#bpcPreview').text('1 Carton = ' + v + ' Box' + (v === 1 ? '' : 'es'));
  }
  $('#bpcInput').on('input', updateBpcPreview);
  updateBpcPreview();
  $('#autoCodeBtn').on('click', function(){
    $('input[name="code"]').val(autoCode);
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>