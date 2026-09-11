<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    // Don't hard delete if category has products, just deactivate style - but set products category null via FK
    delete('categories', $id);
    logActivity($pdo, 'delete', 'category', $id, 'Deleted category id: ' . $id);
    redirect('categories.php', 'Category deleted');
}
redirect('categories.php');