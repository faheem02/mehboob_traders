<?php
/**
 * Backend endpoints used by the "Refund Invoice" modal on invoices.php.
 * - action=customer_invoices&customer_id= : invoices of a customer (with returned amounts)
 * - action=invoice_details&invoice_id=    : items of one invoice (with already returned qty)
 */
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_invoice_return'])) {
    $return_no = trim($_POST['return_no'] ?? '');
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $return_date = trim($_POST['return_date'] ?? date('Y-m-d'));
    $refund_type = trim($_POST['refund_type'] ?? 'Credit Note');
    $bank_account_id = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
    $reason = trim($_POST['reason'] ?? '');
    $items = $_POST['inv_items'] ?? [];

    try {
        if ($invoice_id <= 0 || $customer_id <= 0 || !is_array($items)) {
            throw new Exception('Please select an invoice and enter a return quantity.');
        }

        $pdo->beginTransaction();
        $ret_map = [];
        $stmt = $pdo->prepare("SELECT sri.product_id, COALESCE(SUM(sri.quantity), 0) AS q
            FROM sale_return_items sri JOIN sale_returns sr ON sr.id = sri.return_id
            WHERE sr.status = 'Completed' AND (sr.invoice_id = ? OR sr.sale_id = ?) GROUP BY sri.product_id");
        $stmt->execute([$invoice_id, $invoice_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ret_map[(int)$row['product_id']] = (int)$row['q'];
        }

        $sold_map = [];
        $stmt = $pdo->prepare('SELECT product_id, quantity, price FROM sale_items WHERE sale_id = ?');
        $stmt->execute([$invoice_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int)$row['product_id'];
            if (!isset($sold_map[$pid])) {
                $sold_map[$pid] = ['qty' => 0, 'price' => (float)$row['price']];
            }
            $sold_map[$pid]['qty'] += (int)$row['quantity'];
        }

        $validated = [];
        $total_return_amount = 0;
        foreach ($items as $item) {
            $pid = (int)($item['product_id'] ?? 0);
            $qty = (int)($item['quantity'] ?? 0);
            if ($pid <= 0 || $qty <= 0 || !isset($sold_map[$pid])) continue;
            $qty = min($qty, max(0, $sold_map[$pid]['qty'] - ($ret_map[$pid] ?? 0)));
            if ($qty <= 0) continue;
            $price = (float)($item['unit_price'] ?? 0);
            if ($price <= 0) $price = $sold_map[$pid]['price'];
            $line = round($qty * $price, 2);
            $total_return_amount += $line;
            $validated[] = [
                'product_id' => $pid,
                'quantity' => $qty,
                'price' => $price,
                'total' => $line,
                'condition' => trim($item['condition'] ?? 'Good / Resalable')
            ];
        }
        if (!$validated) throw new Exception('No valid return quantities entered.');
        if ($return_no === '') $return_no = generateSaleReturnNo();
        $user_id = (int)($_SESSION['user_id'] ?? 1);

        $stmt = $pdo->prepare("INSERT INTO sale_returns
            (return_no, sale_id, invoice_id, return_date, customer_id, refund_type, total_amount, reason, bank_account_id, status, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Completed', ?, ?, ?)");
        $stmt->execute([$return_no, $invoice_id, $invoice_id, $return_date, $customer_id, $refund_type,
            $total_return_amount, $reason, $bank_account_id, $user_id, date('Y-m-d'), date('Y-m-d')]);
        $return_id = (int)$pdo->lastInsertId();

        $item_stmt = $pdo->prepare("INSERT INTO sale_return_items
            (return_id, product_id, quantity, unit_price, total_price, `condition`) VALUES (?, ?, ?, ?, ?, ?)");
        $stock_stmt = $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?');
        foreach ($validated as $item) {
            $item_stmt->execute([$return_id, $item['product_id'], $item['quantity'], $item['price'], $item['total'], $item['condition']]);
            if ($item['condition'] === 'Good / Resalable' || $item['condition'] === 'Good') {
                $stock_stmt->execute([$item['quantity'], $item['product_id']]);
            }
        }

        if ($refund_type === 'Cash Refund') {
            recordCashOutflow($pdo, $return_date, $total_return_amount,
                'Cash Refund for Sale Return #' . $return_no, 'sale_return', $return_id, $user_id);
        } elseif ($refund_type === 'Bank Refund' && $bank_account_id) {
            $pdo->prepare('UPDATE bank_accounts SET current_balance = GREATEST(0, current_balance - ?) WHERE id = ?')
                ->execute([$total_return_amount, $bank_account_id]);
        }
        $pdo->commit();
        updateCustomerBalance($pdo, $customer_id);
        $_SESSION['flash_message'] = "Invoice refund #{$return_no} saved successfully and stock restored! <a href='print_return.php?id={$return_id}' target='_blank' class='font-weight-bold text-dark text-decoration-underline ml-2'><i class='fas fa-print'></i> Print Voucher</a>";
        $_SESSION['flash_type'] = 'success';
        header('Location: invoices.php');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash_message'] = 'Refund error: ' . $e->getMessage();
        $_SESSION['flash_type'] = 'danger';
        header('Location: invoices.php');
        exit;
    }
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$result = [];

if ($action === 'customer_invoices') {
    $customer_id = (int)($_GET['customer_id'] ?? 0);
    if ($customer_id <= 0) {
        echo json_encode([]);
        exit;
    }
    $sql = "
        SELECT s.id, s.invoice_no, s.sale_date, s.total_amount, s.paid_amount, s.due_amount,
               (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS item_count,
               (SELECT COALESCE(SUM(sri.total_price), 0)
                  FROM sale_return_items sri
                  JOIN sale_returns sr ON sr.id = sri.return_id
                 WHERE sr.status = 'Completed' AND sr.invoice_id = s.id) AS returned_amount
        FROM sales s
        WHERE s.customer_id = ? AND s.status <> 'cancelled'
        ORDER BY s.sale_date DESC, s.id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$customer_id]);
    $result = $stmt->fetchAll();
} elseif ($action === 'invoice_details') {
    $invoice_id = (int)($_GET['invoice_id'] ?? 0);
    if ($invoice_id <= 0) {
        echo json_encode(['invoice' => null, 'items' => []]);
        exit;
    }
    $stmt = $pdo->prepare("
        SELECT s.*, c.full_name AS customer_name, c.customer_no AS customer_no,
               e.full_name AS salesman_name
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN employees e ON s.salesman_id = e.id
        WHERE s.id = ?
    ");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch();
    if (!$invoice) {
        echo json_encode(['invoice' => null, 'items' => []]);
        exit;
    }

    $returned_map = [];
    $stmt_r = $pdo->prepare("
        SELECT sri.product_id, COALESCE(SUM(sri.quantity), 0) AS q
        FROM sale_return_items sri
        JOIN sale_returns sr ON sr.id = sri.return_id
        WHERE sr.status = 'Completed' AND sr.invoice_id = ?
        GROUP BY sri.product_id
    ");
    $stmt_r->execute([$invoice_id]);
    foreach ($stmt_r->fetchAll() as $row) {
        $returned_map[(int)$row['product_id']] = (int)$row['q'];
    }

    $items = [];
    $stmt_i = $pdo->prepare("
        SELECT si.product_id, p.name AS product_name, p.code AS product_code,
               si.quantity, si.price AS unit_price
        FROM sale_items si
        JOIN products p ON p.id = si.product_id
        WHERE si.sale_id = ?
        ORDER BY si.id ASC
    ");
    $stmt_i->execute([$invoice_id]);
    foreach ($stmt_i->fetchAll() as $row) {
        $pid = (int)$row['product_id'];
        $sold_qty = (int)$row['quantity'];
        $already = (int)($returned_map[$pid] ?? 0);
        $items[] = [
            'product_id' => $pid,
            'product_name' => $row['product_name'],
            'product_code' => $row['product_code'],
            'sold_qty' => $sold_qty,
            'returned_qty' => $already,
            'returnable_qty' => max(0, $sold_qty - $already),
            'unit_price' => (float)$row['unit_price'],
        ];
    }
    $result = [
        'invoice' => $invoice,
        'items' => $items,
    ];
}

echo json_encode($result);
