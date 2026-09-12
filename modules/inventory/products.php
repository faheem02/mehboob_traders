<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Products';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker','loader']);

// Filter
$cat_filter = $_GET['category_id'] ?? '';
$q = trim($_GET['q'] ?? '');

$sql = "SELECT p.*, c.name AS cat_name, b.name AS brand_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        WHERE 1=1";
$params = [];
if ($cat_filter !== '') {
    $sql .= " AND p.category_id = ?";
    $params[] = $cat_filter;
}
if ($q !== '') {
    $sql .= " AND (p.name LIKE ? OR p.code LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
}
$sql .= " ORDER BY p.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6><i class="fas fa-box"></i> Products (<?=count($products)?>)</h6>
    <div>
      <button type="button" class="btn btn-sm btn-outline-secondary d-print-none" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
      <?php if (isAdmin()): ?><a href="product_create.php" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Add Product</a><?php endif; ?>
    </div>
  </div>
  <div class="card-body">
    <form method="get" class="row g-2 mb-3 d-print-none">
      <div class="col-md-4">
        <input type="text" name="q" class="form-control" placeholder="Search by name or code" value="<?=htmlspecialchars($q)?>">
      </div>
      <div class="col-md-3">
        <select name="category_id" class="form-control">
          <option value="">All Categories</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?=$c['id']?>" <?= $cat_filter == $c['id'] ? 'selected' : '' ?>><?=htmlspecialchars($c['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button class="btn btn-outline-primary btn-block"><i class="fas fa-search"></i> Search</button>
      </div>
    </form>

    <!-- Printable header -->
<div class="d-none d-print-block mb-3 text-center">
  <h4 class="font-weight-bold mb-0" style="color:#0f172a;">Mehboob Traders</h4>
  <small class="text-muted">Wholesale Business</small>
  <h5 class="font-weight-bold text-primary mt-2 mb-0">PRODUCTS LIST</h5>
  <?php $pr_meta = [];
  if ($cat_filter !== '') {
      $pr_cat = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
      $pr_cat->execute([$cat_filter]);
      $pr_meta[] = 'Category: ' . $pr_cat->fetchColumn();
  }
  if ($q !== '') $pr_meta[] = 'Search: ' . $q;
  if ($pr_meta): ?><div class="mt-1 font-weight-bold"><?=implode(' &nbsp;|&nbsp; ', $pr_meta)?></div><?php endif; ?>
  <small>Printed on <?=formatDate(date('Y-m-d'))?></small>
</div>

<div class="table-responsive">
      <table class="table table-bordered table-hover">
        <thead>
          <tr><th>Code</th><th>Name</th><th>Category</th><th>Unit</th><th>Boxes per Carton</th><th>Purchase Price</th><th>Sale Price</th><th>Stock</th><th>Status</th><th class="d-print-none">Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($products as $p): ?>
          <?php
            $stock = (float)$p['stock_quantity'];
            $stockClass = $stock <= 0 ? 'stock-out' : ($stock <= (float)$p['min_stock_level'] ? 'stock-low' : 'stock-ok');
          ?>
          <tr>
            <td><?=htmlspecialchars($p['code'])?></td>
            <td class="font-weight-bold"><?=htmlspecialchars($p['name'])?></td>
            <td><?=htmlspecialchars($p['cat_name'] ?? '-')?></td>
            <td><?=htmlspecialchars($p['unit'])?></td>
            <td><?=(int)$p['boxes_per_carton']?></td>
            <td>PKR <?=formatCurrency($p['purchase_price'])?></td>
            <td>PKR <?=formatCurrency($p['sale_price'])?></td>
            <td class="<?=$stockClass?>"><?= (int)$stock ?> Boxes
              <?php $bpc = max(1, (int)$p['boxes_per_carton']); $stock_ctns = (int)floor($stock / $bpc); $stock_rem = (int)($stock % $bpc); ?>
              <br><small class="text-muted"><?=$stock_ctns?> Carton<?=$stock_ctns==1?'':'s'?><?=$stock_rem>0 ? ' + '.$stock_rem.' Box'.($stock_rem==1?'':'es') : ''?></small>
              <?php if ($stock <= 0): ?><br><small class="text-danger"><i class="fas fa-exclamation-circle"></i> Out of stock</small>
              <?php elseif ($stock <= (float)$p['min_stock_level'] && (float)$p['min_stock_level'] > 0): ?><br><small class="text-warning"><i class="fas fa-exclamation-triangle"></i> Low stock</small>
              <?php endif; ?>
            </td>
            <td><?= $p['status'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-secondary">Inactive</span>' ?></td>
            <td class="text-center d-print-none" nowrap>
              <button type="button" class="btn btn-sm btn-outline-info view-product" data-id="<?=$p['id']?>" title="View Product"><i class="fas fa-eye"></i></button>
              <?php if (isAdmin()): ?>
              <a href="product_edit.php?id=<?=$p['id']?>" class="btn btn-sm btn-outline-warning" title="Edit Product"><i class="fas fa-edit"></i></a>
              <form method="post" action="product_delete.php" class="d-inline" onsubmit="return confirm('Delete this product?');">
                <input type="hidden" name="id" value="<?=$p['id']?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Product"><i class="fas fa-trash-alt"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($products)): ?>
          <tr><td colspan="10" class="text-center text-muted py-4">No products found. <a href="product_create.php">Add your first product</a></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Product View Modal -->
<div class="modal fade" id="productViewModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title"><i class="fas fa-box"></i> Product Details</h6>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body" id="productViewBody">
        <div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
      </div>
    </div>
  </div>
</div>

<script>
$(document).ready(function(){
  $(document).on('click', '.view-product', function(){
    var id = $(this).data('id');
    $('#productViewBody').html('<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
    $('#productViewModal').modal('show');
    $.ajax({
      url: 'ajax_product_view.php',
      data: {id: id},
      dataType: 'html'
    }).done(function(html){
      $('#productViewBody').html(html);
    }).fail(function(){
      $('#productViewBody').html('<div class="alert alert-danger mb-0">Could not load product details. Please refresh and try again.</div>');
    });
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>