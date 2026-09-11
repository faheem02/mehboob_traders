<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $product = getById('products', $id);
    if (!$product) redirect('products.php', 'Product not found', 'error');

    $chk = $pdo->prepare("SELECT
        (SELECT COUNT(*) FROM purchase_items WHERE product_id = ?) AS purchases,
        (SELECT COUNT(*) FROM sale_items WHERE product_id = ?) AS sales");
    $chk->execute([$id, $id]);
    $r = $chk->fetch();
    if ($r['purchases'] > 0 || $r['sales'] > 0) {
        redirect('products.php', 'Cannot delete "' . $product['name'] . '": it has purchase/sale history', 'error');
    }

    delete('products', $id);
    logActivity($pdo, 'delete', 'product', $id, 'Deleted product: ' . $product['name'] . ' (code: ' . $product['code'] . ')');
    redirect('products.php', 'Product deleted');
}
redirect('products.php');