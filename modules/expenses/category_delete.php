<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    delete('expense_categories', $id);
    logActivity($pdo, 'delete', 'expense_category', $id, 'Deleted expense category id: ' . $id);
    redirect('categories.php', 'Category deleted');
}
redirect('categories.php');