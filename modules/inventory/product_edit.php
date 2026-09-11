<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Edit Product';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$id = (int)($_GET['id'] ?? 0);
$product = $id ? getById('products', $id) : null;
if (!$product) redirect('products.php', 'Product not found', 'error');

$categories = $pdo->query("SELECT id, name FROM categories WHERE status = 1 ORDER BY name")->fetchAll();
$brands = $pdo->query("SELECT id, name FROM brands WHERE status = 1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') { redirect('product_edit.php?id=' . $id, 'Product name is required', 'error'); }
    $code = trim($_POST['code'] ?? '') ?: $product['code'];
    $chk = $pdo->prepare("SELECT id FROM products WHERE code = ? AND id <> ?");
    $chk->execute([$code, $id]);
    if ($chk->fetch()) { redirect('product_edit.php?id=' . $id, 'Product code already exists', 'error'); }

    update('products', [
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
        'updated_at' => date('Y-m-d'),
    ], $id);
    logActivity($pdo, 'edit', 'product', $id, 'Edited product: ' . $name . ' (code: ' . $code . ')');
    redirect('products.php', 'Product "' . $name . '" updated successfully');
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h6><i class="fas fa-user-edit"></i> Edit Product</h6>
    <a href="products.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-arrow-left"></i> Back to Products</a>
  </div>
  <div class="card-body">
    <form method="post">
      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">Product Name *</label>
          <input type="text" name="name" class="form-control" required value="<?=htmlspecialchars($product['name'])?>">
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Item Code</label>
          <input type="text" name="code" class="form-control" value="<?=htmlspecialchars($product['code'])?>">
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Unit</label>
          <select name="unit" class="form-control">
            <option value="pcs" <?= $product['unit']=='pcs' ? 'selected' : '' ?>>Piece (pcs)</option>
            <option value="dozen" <?= $product['unit']=='dozen' ? 'selected' : '' ?>>Dozen</option>
            <option value="box" <?= $product['unit']=='box' ? 'selected' : '' ?>>Box</option>
            <option value="carton" <?= $product['unit']=='carton' ? 'selected' : '' ?>>Carton</option>
            <option value="kg" <?= $product['unit']=='kg' ? 'selected' : '' ?>>Kg</option>
            <option value="pack" <?= $product['unit']=='pack' ? 'selected' : '' ?>>Pack</option>
            <option value="gadda" <?= $product['unit']=='gadda' ? 'selected' : '' ?>>Gadda</option>
          </select>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Category</label>
          <select name="category_id" class="form-control">
            <option value="">-- Select --</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?=$c['id']?>" <?= (int)$product['category_id']==(int)$c['id'] ? 'selected' : '' ?>><?=htmlspecialchars($c['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Brand</label>
          <select name="brand_id" class="form-control">
            <option value="">-- Select --</option>
            <?php foreach ($brands as $b): ?>
            <option value="<?=$b['id']?>" <?= (int)$product['brand_id']==(int)$b['id'] ? 'selected' : '' ?>><?=htmlspecialchars($b['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Description</label>
          <input type="text" name="description" class="form-control" value="<?=htmlspecialchars($product['description'] ?? '')?>">
        </div>
      </div>

      <hr>
      <h6 class="mb-3 text-secondary">Pricing & Stock</h6>
      <div class="row">
        <div class="col-md-3 mb-3">
          <label class="form-label">Purchase Price *</label>
          <input type="number" step="0.01" min="0" name="purchase_price" class="form-control" required value="<?=$product['purchase_price']?>">
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Sale Price *</label>
          <input type="number" step="0.01" min="0" name="sale_price" class="form-control" required value="<?=$product['sale_price']?>">
        </div>
        <div class="col-md-2 mb-3">
          <label class="form-label">Boxes per Carton</label>
          <input type="number" min="1" name="boxes_per_carton" id="bpcInput" class="form-control" value="<?=(int)$product['boxes_per_carton']?>" required>
          <small class="text-muted">Boxes in 1 carton</small>
          <small class="d-block mt-1 font-weight-bold text-success" id="bpcPreview">1 Carton = <?=(int)$product['boxes_per_carton']?> Boxes</small>
        </div>
        <div class="col-md-2 mb-3">
          <label class="form-label">Stock (Boxes) *</label>
          <input type="number" min="0" name="stock_quantity" class="form-control" required value="<?=$product['stock_quantity']?>">
          <small class="text-muted">Adjust after purchase/sale</small>
        </div>
        <div class="col-md-2 mb-3">
          <label class="form-label">Min Stock Alert Level</label>
          <input type="number" min="0" name="min_stock_level" class="form-control" value="<?=$product['min_stock_level']?>">
        </div>
      </div>

      <div class="d-flex justify-content-between mt-2">
        <a href="products.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back</a>
        <button type="submit" class="btn btn-primary px-5"><i class="fas fa-save"></i> Update Product</button>
      </div>
    </form>
  </div>
</div>

<script>
$(document).ready(function(){
  function updateBpcPreview(){
    var v = parseInt($('#bpcInput').val()) || 1;
    if (v < 1) v = 1;
    $('#bpcPreview').text('1 Carton = ' + v + ' Box' + (v === 1 ? '' : 'es'));
  }
  $('#bpcInput').on('input', updateBpcPreview);
  updateBpcPreview();
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>