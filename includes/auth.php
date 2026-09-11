<?php
session_start();

// Never cache authenticated pages (XAMPP sends no cache headers, so browsers
// heuristically cache PHP output — caused stale page versions to be served)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $redirect_url = ($base_url ?? '/mehboob_traders/') . 'login.php';
    header("Location: " . $redirect_url);
    exit;
}

$user_role = $_SESSION['user_role'] ?? '';
$user_name = $_SESSION['user_name'] ?? '';
$branch_id = $_SESSION['branch_id'] ?? 1;