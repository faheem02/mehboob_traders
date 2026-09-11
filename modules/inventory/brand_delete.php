<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    delete('brands', $id);
    logActivity($pdo, 'delete', 'brand', $id, 'Deleted brand id: ' . $id);
    redirect('brands.php', 'Brand deleted');
}
redirect('brands.php');