<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw_name = trim($_POST['name'] ?? '');
if ($raw_name === '') {
    echo json_encode(['success' => false, 'message' => 'Please enter an Employee Type name.']);
    exit;
}

if (mb_strlen($raw_name) > 50) {
    echo json_encode(['success' => false, 'message' => 'Employee Type name cannot exceed 50 characters.']);
    exit;
}

// Format display name nicely (e.g. "driver" -> "Driver", "delivery boy" -> "Delivery Boy")
$display_name = ucwords(strtolower($raw_name));

// Compute slug/code (e.g. "delivery_boy")
$code = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $raw_name), '_'));
if ($code === '') {
    $code = strtolower($raw_name);
}

// Check if already exists by code or by name
$stmt = $pdo->prepare("SELECT id, code, name FROM employee_types WHERE LOWER(code) = LOWER(?) OR LOWER(name) = LOWER(?) LIMIT 1");
$stmt->execute([$code, $display_name]);
$existing = $stmt->fetch();

if ($existing) {
    echo json_encode([
        'success' => true,
        'code' => $existing['code'],
        'name' => $existing['name'],
        'already_existed' => true,
        'message' => 'Employee type "' . $existing['name'] . '" already exists and has been selected.'
    ]);
    exit;
}

try {
    $ins = $pdo->prepare("INSERT INTO employee_types (code, name, is_system, created_at) VALUES (?, ?, 0, CURDATE())");
    $ins->execute([$code, $display_name]);
    $new_id = (int)$pdo->lastInsertId();

    logActivity($pdo, 'add', 'employee_type', $new_id, 'Added employee type ' . $display_name);

    echo json_encode([
        'success' => true,
        'id' => $new_id,
        'code' => $code,
        'name' => $display_name,
        'message' => 'Employee type "' . $display_name . '" added successfully.'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
