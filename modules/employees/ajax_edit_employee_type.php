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

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid employee type ID.']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, code, name, is_system FROM employee_types WHERE id = ?");
$stmt->execute([$id]);
$type = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$type) {
    echo json_encode(['success' => false, 'message' => 'Employee type not found.']);
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

// Format display name nicely (e.g. "driver" -> "Driver")
$display_name = ucwords(strtolower($raw_name));

// Check if another type has this exact name
$chk = $pdo->prepare("SELECT id FROM employee_types WHERE LOWER(name) = LOWER(?) AND id != ? LIMIT 1");
$chk->execute([$display_name, $id]);
if ($chk->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Another employee type already uses the name "' . $display_name . '".']);
    exit;
}

try {
    $upd = $pdo->prepare("UPDATE employee_types SET name = ? WHERE id = ?");
    $upd->execute([$display_name, $id]);

    logActivity($pdo, 'update', 'employee_type', $id, 'Renamed employee type "' . $type['name'] . '" to "' . $display_name . '"');

    echo json_encode([
        'success' => true,
        'id' => $id,
        'code' => $type['code'],
        'name' => $display_name,
        'message' => 'Employee type updated successfully.'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}

