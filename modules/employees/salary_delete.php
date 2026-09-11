<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $salary = getById('employee_salaries', $id);
    if (!$salary) redirect('salary.php', 'Salary payment not found', 'error');

    $emp = getById('employees', $salary['employee_id']);

    $pdo->beginTransaction();
    try {
        removeSalaryLedger($pdo, $id);
        delete('employee_salaries', $id);
        logActivity($pdo, 'delete', 'salary', $id,
            'Deleted salary payment ' . ($emp['full_name'] ?? '') . ' slip ' . $salary['slip_no'] . ' PKR ' . $salary['amount']);
        $pdo->commit();
        redirect('salary.php', 'Salary payment deleted: slip ' . $salary['slip_no']);
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('salary.php', 'Error: ' . $e->getMessage(), 'error');
    }
}
redirect('salary.php');