<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $product = getById('products', $id);
    if (!$product) {
        redirect('products.php', 'Product not found', 'error');
    }

    $new_status = $product['status'] ? 0 : 1;
    $status_label = $new_status ? 'Active' : 'Inactive';

    $stmt = $pdo->prepare("UPDATE products SET status = ?, updated_at = ? WHERE id = ?");
    $stmt->execute([$new_status, date('Y-m-d'), $id]);

    logActivity($pdo, 'status_change', 'product', $id, 'Changed product "' . $product['name'] . '" status to ' . $status_label);

    $return_url = trim($_POST['return_url'] ?? '');
    if ($return_url === '' || !str_contains($return_url, 'products.php')) {
        $return_url = 'products.php';
    }

    redirect($return_url, 'Product "' . $product['name'] . '" marked as ' . $status_label . ' successfully.');
}

redirect('products.php');
