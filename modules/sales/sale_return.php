<?php
/**
 * Mehboob Traders - Sales Returns
 * Clean ERP Table: Customer column, dedicated View Products modal, and streamlined header.
 * Only ONE "Refund by Invoice" button on the top right, and no direct "new Return" button.
 */
$page_title = "Sales Returns";
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

// AJAX: live customer search (for invoice-based refund)
if (isset($_GET['action']) && $_GET['action'] === 'search_customer') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    $res = [];
    if ($pdo) {
        $where = ["(status = 1 OR status IS NULL)"];
        $params = [];

        if ($q !== '') {
            $words = preg_split('/\s+/', $q);
            $wordClauses = [];
            foreach ($words as $idx => $word) {
                if ($word === '') continue;
                $p = ":w" . $idx;
                $wordClauses[] = "(CONCAT_WS(' ', full_name, customer_no, phone, area) LIKE $p)";
                $params[$p] = "%$word%";
            }
            if (!empty($wordClauses)) {
                $where[] = '(' . implode(' AND ', $wordClauses) . ')';
            }

            $sql = "SELECT id, full_name, customer_no, phone, area, current_balance
                    FROM customers
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY 
                      CASE 
                        WHEN full_name LIKE :exact THEN 1 
                        WHEN full_name LIKE :start THEN 2 
                        ELSE 3 
                      END, full_name ASC 
                    LIMIT 50";
            $params[':exact'] = $q;
            $params[':start'] = "$q%";
        } else {
            $sql = "SELECT id, full_name, customer_no, phone, area, current_balance
                    FROM customers
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY full_name ASC
                    LIMIT 50";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode($res);
    exit;
}

// AJAX: list a customer's invoices
if (isset($_GET['action']) && $_GET['action'] === 'customer_invoices') {
    header('Content-Type: application/json');
    $customer_id = intval($_GET['customer_id'] ?? 0);
    $res = [];
    if ($pdo && $customer_id > 0) {
        $sql = "SELECT s.id, s.invoice_no, s.sale_date, s.total_amount, s.paid_amount, s.due_amount,
                       (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS item_count,
                       (SELECT COALESCE(SUM(sri.total_price), 0)
                          FROM sale_return_items sri
                          JOIN sale_returns sr ON sr.id = sri.return_id
                         WHERE sr.status = 'Completed' AND (sr.invoice_id = s.id OR sr.sale_id = s.id)) AS returned_amount
                FROM sales s
                WHERE s.customer_id = ? AND s.status <> 'cancelled'
                ORDER BY s.sale_date DESC, s.id DESC
                LIMIT 100";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$customer_id]);
        $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode($res);
    exit;
}

// AJAX: invoice header + items with sold / already-returned / returnable qty
if (isset($_GET['action']) && $_GET['action'] === 'invoice_details') {
    header('Content-Type: application/json');
    $invoice_id = intval($_GET['invoice_id'] ?? 0);
    $out = ['invoice' => null, 'items' => []];
    if ($pdo && $invoice_id > 0) {
        $stmt = $pdo->prepare("SELECT s.*, c.full_name AS customer_name, c.customer_no, c.phone, c.area,
                                      e.full_name AS salesman_name
                               FROM sales s
                               LEFT JOIN customers c ON c.id = s.customer_id
                               LEFT JOIN employees e ON e.id = s.salesman_id
                               WHERE s.id = ?");
        $stmt->execute([$invoice_id]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($inv) {
            $out['invoice'] = $inv;

            $ret_map = [];
            $stmt_r = $pdo->prepare("SELECT sri.product_id, COALESCE(SUM(sri.quantity), 0) AS q
                                     FROM sale_return_items sri
                                     JOIN sale_returns sr ON sr.id = sri.return_id
                                     WHERE sr.status = 'Completed' AND (sr.invoice_id = ? OR sr.sale_id = ?)
                                     GROUP BY sri.product_id");
            $stmt_r->execute([$invoice_id, $invoice_id]);
            foreach ($stmt_r->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ret_map[(int)$r['product_id']] = (int)$r['q'];
            }

            $stmt_i = $pdo->prepare("SELECT si.*, p.name AS product_name, p.code AS product_code, p.boxes_per_carton, p.stock_quantity
                                     FROM sale_items si
                                     LEFT JOIN products p ON p.id = si.product_id
                                     WHERE si.sale_id = ?
                                     ORDER BY si.id ASC");
            $stmt_i->execute([$invoice_id]);
            foreach ($stmt_i->fetchAll(PDO::FETCH_ASSOC) as $it) {
                $pid = (int)$it['product_id'];
                $sold = (int)$it['quantity'];
                $already = (int)($ret_map[$pid] ?? 0);
                $it['sold_qty'] = $sold;
                $it['returned_qty'] = $already;
                $it['returnable_qty'] = max(0, $sold - $already);
                $it['unit_price'] = (float)$it['price'];
                $out['items'][] = $it;
            }
        }
    }
    echo json_encode($out);
    exit;
}

requireRole(['admin']);

$message = "";
$msg_type = "";

// Flash message support
if (!empty($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $msg_type = $_SESSION['flash_type'] ?? 'info';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// Handle Delete Return Action (Revert Stock & Accounts)
if (isset($_GET['action']) && $_GET['action'] === 'delete' && !empty($_GET['id'])) {
    $del_id = intval($_GET['id']);
    if ($pdo && $del_id > 0) {
        try {
            $pdo->beginTransaction();

            $stmt_ret = $pdo->prepare("SELECT * FROM sale_returns WHERE id = ?");
            $stmt_ret->execute([$del_id]);
            $ret_data = $stmt_ret->fetch(PDO::FETCH_ASSOC);

            if ($ret_data) {
                // 1. Fetch return items to revert stock
                $stmt_ritems = $pdo->prepare("SELECT product_id, quantity, `condition` FROM sale_return_items WHERE return_id = ?");
                $stmt_ritems->execute([$del_id]);
                $ritems = $stmt_ritems->fetchAll(PDO::FETCH_ASSOC);

                $stmt_sub_stock = $pdo->prepare("UPDATE products SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE id = ?");

                foreach ($ritems as $ri) {
                    $cond = $ri['condition'] ?? 'Good / Resalable';
                    if ($cond === 'Good / Resalable' || $cond === 'Good') {
                        $stmt_sub_stock->execute([$ri['quantity'], $ri['product_id']]);
                    }
                }

                // 2. Revert refund if bank or cash or customer balance
                $ret_amt = floatval($ret_data['total_amount']);
                $refund_type = $ret_data['refund_type'] ?? '';
                $bank_acc_id = !empty($ret_data['bank_account_id']) ? intval($ret_data['bank_account_id']) : 0;
                $cust_id = intval($ret_data['customer_id'] ?? 0);

                if ($refund_type === 'Bank Refund' && $bank_acc_id > 0) {
                    $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$ret_amt, $bank_acc_id]);
                } elseif ($refund_type === 'Cash Refund') {
                    // Record cash inflow to reverse outflow
                    recordCashInflow(
                        $pdo, date('Y-m-d'), $ret_amt,
                        'Reversal of deleted Sale Return #' . $ret_data['return_no'],
                        'sale_return_delete', $del_id, $_SESSION['user_id'] ?? null
                    );
                }

                // 3. Delete items & return record
                $pdo->prepare("DELETE FROM sale_return_items WHERE return_id = ?")->execute([$del_id]);
                $pdo->prepare("DELETE FROM sale_returns WHERE id = ?")->execute([$del_id]);

                $pdo->commit();

                // 4. Sync customer balance if linked (Credit Note returns are automatically subtracted, so deleting restores balance)
                if ($cust_id > 0 && function_exists('updateCustomerBalance')) {
                    try { updateCustomerBalance($pdo, $cust_id); } catch (Exception $e) {}
                }

                $_SESSION['flash_message'] = "Sale Return #{$ret_data['return_no']} deleted successfully and stock reverted.";
                $_SESSION['flash_type'] = "success";
                header("Location: sale_return.php");
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = "Delete error: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// Auto-generate Return No (e.g. SRTN-yymmdd-001)
$auto_return_no = generateSaleReturnNo();

// Fetch Bank Accounts
$bank_accounts = [];
if ($pdo) {
    try {
        $bank_accounts = $pdo->query("SELECT id, bank_name, account_name, account_no FROM bank_accounts WHERE status = 1 ORDER BY bank_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Handle Form Submission: Create Invoice-Based Return (Refund by Invoice)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_invoice_return'])) {
    $return_no       = trim($_POST['return_no'] ?? '');
    $invoice_id      = intval($_POST['invoice_id'] ?? 0);
    $customer_id     = intval($_POST['customer_id'] ?? 0);
    $return_date     = trim($_POST['return_date'] ?? date('Y-m-d'));
    $refund_type     = trim($_POST['refund_type'] ?? 'Credit Note');
    $bank_account_id = !empty($_POST['bank_account_id']) ? intval($_POST['bank_account_id']) : null;
    $reason          = trim($_POST['reason'] ?? '');
    $items           = $_POST['inv_items'] ?? [];

    if ($invoice_id <= 0 || $customer_id <= 0) {
        $message = "Please select a customer and an invoice to refund.";
        $msg_type = "danger";
    } elseif (empty($items) || !is_array($items)) {
        $message = "Please enter a return quantity for at least one product.";
        $msg_type = "danger";
    } else {
        try {
            $pdo->beginTransaction();

            // Already-returned quantities for this invoice (per product)
            $ret_map = [];
            $stmt_r = $pdo->prepare("SELECT sri.product_id, COALESCE(SUM(sri.quantity), 0) AS q
                                     FROM sale_return_items sri
                                     JOIN sale_returns sr ON sr.id = sri.return_id
                                     WHERE sr.status = 'Completed' AND (sr.invoice_id = ? OR sr.sale_id = ?)
                                     GROUP BY sri.product_id");
            $stmt_r->execute([$invoice_id, $invoice_id]);
            foreach ($stmt_r->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ret_map[(int)$r['product_id']] = (int)$r['q'];
            }

            // Sold quantities for this invoice (per product)
            $sold_map = [];
            $stmt_s = $pdo->prepare("SELECT product_id, quantity, price FROM sale_items WHERE sale_id = ?");
            $stmt_s->execute([$invoice_id]);
            foreach ($stmt_s->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $pid = (int)$s['product_id'];
                if (!isset($sold_map[$pid])) {
                    $sold_map[$pid] = ['qty' => 0, 'price' => (float)$s['price']];
                }
                $sold_map[$pid]['qty'] += (int)$s['quantity'];
                $sold_map[$pid]['price'] = (float)$s['price'];
            }

            $total_return_amount = 0;
            $validated = [];

            foreach ($items as $itm) {
                $pid       = intval($itm['product_id'] ?? 0);
                $qty       = intval($itm['quantity'] ?? 0);
                $price     = floatval($itm['unit_price'] ?? 0);
                $condition = trim($itm['condition'] ?? 'Good / Resalable');

                if ($pid <= 0 || $qty <= 0 || !isset($sold_map[$pid])) continue;

                $returnable = max(0, (int)$sold_map[$pid]['qty'] - ($ret_map[$pid] ?? 0));
                if ($qty > $returnable) $qty = $returnable;
                if ($qty <= 0) continue;
                if ($price <= 0) $price = (float)$sold_map[$pid]['price'];

                $line = round($qty * $price, 2);
                $total_return_amount += $line;

                $validated[] = [
                    'product_id' => $pid,
                    'quantity'   => $qty,
                    'price'      => $price,
                    'total'      => $line,
                    'condition'  => $condition,
                ];
            }

            if (empty($validated)) {
                throw new Exception("No valid return quantities entered (items may already be fully returned).");
            }

            // Fallback return number if not supplied
            if ($return_no === '') {
                $return_no = generateSaleReturnNo();
            }

            $user_id = $_SESSION['user_id'] ?? 1;

            // Link return to the invoice (sale_id kept equal to invoice_id)
            $stmt_ret = $pdo->prepare("
                INSERT INTO sale_returns (
                    return_no, sale_id, invoice_id, return_date, customer_id, refund_type,
                    total_amount, reason, bank_account_id, status, created_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Completed', ?, ?, ?)
            ");
            $stmt_ret->execute([
                $return_no, $invoice_id, $invoice_id, $return_date, $customer_id, $refund_type,
                $total_return_amount, $reason, $bank_account_id, $user_id, date('Y-m-d'), date('Y-m-d')
            ]);
            $return_id = $pdo->lastInsertId();

            $stmt_item = $pdo->prepare("
                INSERT INTO sale_return_items (return_id, product_id, quantity, unit_price, total_price, `condition`)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt_stock = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?");

            foreach ($validated as $vi) {
                $stmt_item->execute([
                    $return_id, $vi['product_id'],
                    $vi['quantity'], $vi['price'], $vi['total'], $vi['condition']
                ]);

                if ($vi['condition'] === 'Good / Resalable' || $vi['condition'] === 'Good') {
                    $stmt_stock->execute([$vi['quantity'], $vi['product_id']]);
                }
            }

            // Settle refund: cash / bank accounts
            if ($refund_type === 'Cash Refund') {
                recordCashOutflow(
                    $pdo, $return_date, $total_return_amount,
                    'Cash Refund for Sale Return #' . $return_no,
                    'sale_return', $return_id, $user_id
                );
            } elseif ($refund_type === 'Bank Refund' && $bank_account_id) {
                $pdo->prepare("UPDATE bank_accounts SET current_balance = GREATEST(0, current_balance - ?) WHERE id = ?")->execute([$total_return_amount, $bank_account_id]);
            }
            // 'Credit Note' customer-balance reduction is handled by updateCustomerBalance() below.

            $pdo->commit();

            // Recompute customer balance
            if (function_exists('updateCustomerBalance')) {
                try { updateCustomerBalance($pdo, $customer_id); } catch (Exception $e) {}
            }

            $_SESSION['flash_message'] = "Invoice refund #{$return_no} saved successfully and stock restored! <a href='print_return.php?id={$return_id}' target='_blank' class='font-weight-bold text-dark text-decoration-underline ml-2'><i class='fas fa-print'></i> Print Voucher</a>";
            $_SESSION['flash_type'] = "success";
            header("Location: sale_return.php");
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = "Error: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// Search & Date Filter Parameters
$search    = trim($_GET['search'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');

$where_clauses = ["1=1"];
$params = [];

if ($search !== '') {
    $where_clauses[] = "(sr.return_no LIKE :s1 
        OR sr.reason LIKE :s2 
        OR c.full_name LIKE :s3 
        OR c.customer_no LIKE :s4 
        OR c.area LIKE :s5
        OR sr.id IN (
            SELECT sri_sub.return_id FROM sale_return_items sri_sub 
            JOIN products p_sub ON p_sub.id = sri_sub.product_id 
            WHERE p_sub.name LIKE :s6 OR p_sub.code LIKE :s7
        )
    )";
    $like = "%$search%";
    $params[':s1'] = $like;
    $params[':s2'] = $like;
    $params[':s3'] = $like;
    $params[':s4'] = $like;
    $params[':s5'] = $like;
    $params[':s6'] = $like;
    $params[':s7'] = $like;
}

if ($date_from !== '') {
    $where_clauses[] = "sr.return_date >= :df";
    $params[':df'] = $date_from;
}

if ($date_to !== '') {
    $where_clauses[] = "sr.return_date <= :dt";
    $params[':dt'] = $date_to;
}

$where_sql = implode(' AND ', $where_clauses);

// Pagination
$per_page = 40;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

$total_records = 0;
$all_returns = [];
$items_by_return = [];

if ($pdo) {
    try {
        $stmt_cnt = $pdo->prepare("SELECT COUNT(*) FROM sale_returns sr LEFT JOIN customers c ON c.id = sr.customer_id WHERE $where_sql");
        $stmt_cnt->execute($params);
        $total_records = intval($stmt_cnt->fetchColumn());

        $sql_list = "
            SELECT sr.*, 
                   c.full_name, c.customer_no, c.phone, c.area
            FROM sale_returns sr
            LEFT JOIN customers c ON c.id = sr.customer_id
            WHERE $where_sql
            ORDER BY sr.return_date DESC, sr.id DESC 
            LIMIT $per_page OFFSET $offset
        ";
        $stmt_list = $pdo->prepare($sql_list);
        $stmt_list->execute($params);
        $all_returns = $stmt_list->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($all_returns)) {
            $r_ids = array_column($all_returns, 'id');
            $in_placeholders = implode(',', array_fill(0, count($r_ids), '?'));
            $stmt_items = $pdo->prepare("
                SELECT sri.*, 
                       COALESCE(NULLIF(p.name, ''), 'Product') as product_name, 
                       p.code as product_code, 
                       p.boxes_per_carton
                FROM sale_return_items sri
                LEFT JOIN products p ON p.id = sri.product_id
                WHERE sri.return_id IN ($in_placeholders)
                ORDER BY sri.id ASC
            ");
            $stmt_items->execute($r_ids);
            foreach ($stmt_items->fetchAll(PDO::FETCH_ASSOC) as $row_item) {
                $rid = intval($row_item['return_id']);
                $items_by_return[$rid][] = $row_item;
            }
        }
    } catch (Exception $e) {}
}

$total_pages = (int)ceil($total_records / $per_page);

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<style>
    /* Table Card */
    .table-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 4px 16px rgba(15, 23, 42, 0.03);
        overflow: hidden;
    }

    /* Table Header & Rows */
    .table-returns thead th {
        background-color: #f8fafc;
        color: #475569;
        font-size: 0.78rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        border-bottom: 2px solid #e2e8f0;
        padding: 12px 14px;
        vertical-align: middle;
        white-space: nowrap;
    }
    .table-returns tbody td {
        padding: 11px 14px;
        vertical-align: middle;
        border-bottom: 1px solid #f1f5f9;
        font-size: 0.88rem;
    }
    .table-returns tbody tr:hover {
        background-color: #fcfdfe;
    }

    /* Action Buttons */
    .action-btn {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.82rem;
        transition: all 0.15s ease;
        padding: 0;
    }

    /* Autocomplete dropdown in modal */
    .product-search-box {
        position: relative;
    }
    .product-results-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        z-index: 1070;
        max-height: 280px;
        overflow-y: auto;
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.15);
    }
    .product-search-item {
        padding: 10px 14px;
        border-bottom: 1px solid #f1f5f9;
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .product-search-item:hover {
        background: #fee2e2;
        border-left: 4px solid #dc2626;
    }

    /* Refund by Invoice Modal: Ensure dialog fits screen and footer with Save button is always visible */
    #refundInvoiceModal .modal-dialog {
        max-height: calc(100vh - 2rem);
        margin-top: 1rem;
        margin-bottom: 1rem;
    }
    #refundInvoiceModal .modal-content,
    #refundInvoiceModal form.modal-content {
        max-height: calc(100vh - 2rem);
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }
    #refundInvoiceModal .modal-body {
        overflow-y: auto;
        flex: 1 1 auto;
    }
    #refundInvoiceModal .modal-footer {
        flex-shrink: 0;
        background-color: #f8fafc;
        border-top: 1px solid #e2e8f0;
    }

    /* Refund by Invoice Modal: Items Scrollable Container & Sticky Header/Footer */
    #rifItemsWrapper .table-responsive {
        max-height: 240px;
        overflow-y: auto;
        overflow-x: auto;
    }
    #rifItemsWrapper .table-responsive thead th {
        position: sticky;
        top: 0;
        background-color: #f8fafc;
        z-index: 3;
        box-shadow: inset 0 -1px 0 #dee2e6;
    }
    #rifItemsWrapper .table-responsive tfoot td {
        position: sticky;
        bottom: 0;
        background-color: #f8fafc;
        z-index: 3;
        box-shadow: inset 0 1px 0 #dee2e6;
    }
</style>

<!-- Top Action Bar (Single "Refund by Invoice" button, top right) -->
<div class="d-flex justify-content-end align-items-center mb-3">
    <div class="d-flex flex-wrap" style="gap: 8px;">
        <a href="invoices.php" class="btn btn-sm btn-outline-secondary bg-white shadow-sm px-3 rounded-2">
            <i class="fas fa-file-invoice mr-1"></i> Sales Invoices
        </a>
        <button type="button" class="btn btn-sm btn-success font-weight-bold shadow-sm px-3 rounded-2" onclick="openRefundInvoice(0, '', '')">
            <i class="fas fa-file-invoice-dollar mr-1"></i> Refund by Invoice
        </button>
    </div>
</div>

<!-- Alert Notifications -->
<?php if (!empty($message)): ?>
    <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show border-0 shadow-sm rounded-3 d-flex align-items-center mb-3" role="alert">
        <i class="fas <?= $msg_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> mr-2" style="font-size: 1.15rem;"></i>
        <div class="font-weight-bold"><?= $message ?></div>
        <button type="button" class="close ml-auto" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
<?php endif; ?>

<!-- Returns Table Card with Filter Bar (No duplicate Refund button, No new return button) -->
<div class="table-card mb-4">
    <div class="p-3 border-bottom bg-white d-flex flex-wrap align-items-center justify-content-between">
        <!-- Search & Date Filter Form -->
        <form method="GET" action="sale_return.php" class="d-flex flex-wrap align-items-center flex-grow-1" style="gap: 8px;">
            <div class="input-group input-group-sm" style="max-width: 320px;">
                <div class="input-group-prepend">
                    <span class="input-group-text bg-light text-muted border-right-0"><i class="fas fa-search"></i></span>
                </div>
                <input type="text" name="search" class="form-control border-left-0" placeholder="Search voucher, customer, remarks..." value="<?= htmlspecialchars($search) ?>" autocomplete="off">
            </div>
            <div class="d-flex align-items-center" style="gap: 6px;">
                <input type="date" name="date_from" class="form-control form-control-sm" style="width: 140px;" value="<?= htmlspecialchars($date_from) ?>" title="From Date">
                <span class="text-muted small">to</span>
                <input type="date" name="date_to" class="form-control form-control-sm" style="width: 140px;" value="<?= htmlspecialchars($date_to) ?>" title="To Date">
                <button type="submit" class="btn btn-sm btn-danger font-weight-bold px-3 ml-1" title="Filter">
                    <i class="fas fa-filter mr-1"></i> Filter
                </button>
                <?php if ($search !== '' || $date_from !== '' || $date_to !== ''): ?>
                    <a href="sale_return.php" class="btn btn-sm btn-outline-secondary ml-1" title="Reset Filters">
                        <i class="fas fa-times"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Main Records Table -->
    <div class="table-responsive">
        <table class="table table-returns mb-0">
            <thead>
                <tr>
                    <th style="width: 50px;" class="text-center">#</th>
                    <th style="width: 150px;">Voucher No</th>
                    <th style="width: 110px;">Date</th>
                    <th>Customer</th>
                    <th>Remarks</th>
                    <th style="width: 140px;" class="text-right">Total Amount</th>
                    <th style="width: 150px;" class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($all_returns)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="fas fa-box-open mb-3 d-block text-secondary" style="font-size: 2.5rem;"></i>
                            <h6 class="font-weight-bold text-dark">No Sales Return records found.</h6>
                            <p class="small text-muted mb-3">Customer returns will appear here after refunding an invoice.</p>
                            <button type="button" class="btn btn-sm btn-success font-weight-bold px-3 shadow-sm" onclick="openRefundInvoice(0, '', '')">
                                <i class="fas fa-file-invoice-dollar mr-1"></i> Refund by Invoice
                            </button>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $row_num = $offset;
                    foreach ($all_returns as $rr): 
                        $row_num++;
                        $c_display = !empty($rr['full_name']) ? $rr['full_name'] : 'Counter Customer';
                        if (!empty($rr['area'])) {
                            $c_display .= ' (' . $rr['area'] . ')';
                        }
                    ?>
                        <tr>
                            <!-- Row Index -->
                            <td class="text-center text-muted font-weight-bold"><?= $row_num ?></td>

                            <!-- Voucher No -->
                            <td class="text-nowrap">
                                <span class="font-weight-bold text-danger" style="font-family: monospace; font-size: 0.95rem;"><?= htmlspecialchars($rr['return_no']) ?></span>
                            </td>

                            <!-- Date -->
                            <td class="text-nowrap text-secondary">
                                <?= formatDate($rr['return_date']) ?>
                            </td>

                            <!-- Customer Column -->
                            <td>
                                <div class="font-weight-bold text-dark">
                                    <?= htmlspecialchars($rr['full_name'] ?: 'Counter Customer') ?>
                                    <?php if (!empty($rr['area'])): ?>
                                        <span class="badge badge-light border text-muted ml-1" style="font-size: 10px;"><i class="fas fa-map-marker-alt text-danger mr-1"></i><?= htmlspecialchars($rr['area']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- Remarks Column -->
                            <td>
                                <?php if (!empty($rr['reason'])): ?>
                                    <div class="text-secondary small" style="line-height: 1.35; max-width: 320px;">
                                        <?= nl2br(htmlspecialchars($rr['reason'])) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small">&mdash;</span>
                                <?php endif; ?>
                            </td>

                            <!-- Total Amount Column -->
                            <td class="text-right text-nowrap">
                                <span class="font-weight-bold text-dark" style="font-family: monospace; font-size: 0.95rem;">
                                    PKR <?= formatCurrency((float)$rr['total_amount']) ?>
                                </span>
                            </td>

                            <!-- Action Column -->
                            <td class="text-center text-nowrap">
                                <button type="button"
                                        class="btn btn-danger btn-sm action-btn border"
                                        title="Refund by Invoice"
                                        onclick="openRefundInvoice(<?= intval($rr['customer_id'] ?? 0) ?>, '<?= htmlspecialchars(addslashes($rr['full_name'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($rr['area'] ?? '')) ?>')">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                </button>
                                <button type="button" 
                                        class="btn btn-light btn-sm action-btn text-info border" 
                                        title="View Returned Products"
                                        onclick="viewReturnItems(<?= $rr['id'] ?>, '<?= htmlspecialchars($rr['return_no']) ?>', '<?= formatDate($rr['return_date']) ?>', '<?= htmlspecialchars(addslashes($c_display)) ?>', '<?= htmlspecialchars(addslashes($rr['reason'] ?? '')) ?>')">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <a href="print_return.php?id=<?= $rr['id'] ?>" target="_blank" class="btn btn-light btn-sm action-btn text-primary border" title="Print Return Voucher">
                                    <i class="fas fa-print"></i>
                                </a>
                                <a href="sale_return.php?action=delete&id=<?= $rr['id'] ?>" class="btn btn-light btn-sm action-btn text-danger border" onclick="return confirm('Are you sure you want to delete Sale Return #<?= htmlspecialchars($rr['return_no']) ?>? Restored stock will be deducted back.')" title="Delete Return">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top bg-light">
            <span class="text-muted small">Showing page <?= $page ?> of <?= $total_pages ?> (<?= $total_records ?> total returns)</span>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="sale_return.php?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>">Previous</a>
                        </li>
                    <?php endif; ?>
                    
                    <?php 
                    $start_p = max(1, $page - 2);
                    $end_p   = min($total_pages, $page + 2);
                    for ($p = $start_p; $p <= $end_p; $p++): 
                    ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="sale_return.php?page=<?= $p ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="sale_return.php?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>">Next</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<!-- ======================================================== -->
<!-- MODAL: REFUND BY INVOICE                                 -->
<!-- ======================================================== -->
<div class="modal fade" id="refundInvoiceModal" tabindex="-1" role="dialog" aria-labelledby="refundInvoiceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
        <form method="POST" action="sale_return.php" id="refundInvoiceForm" class="modal-content border-0 shadow-lg rounded-3">
            <input type="hidden" name="invoice_id" id="rif_invoice_id" value="">
            <input type="hidden" name="customer_id" id="rif_customer_id" value="">

            <!-- Modal Header -->
            <div class="modal-header bg-success text-white py-3 px-4">
                <div class="d-flex align-items-center">
                    <i class="fas fa-file-invoice-dollar mr-2" style="font-size: 1.25rem;"></i>
                    <h5 class="modal-title font-weight-bold mb-0" id="refundInvoiceModalLabel">Refund by Invoice</h5>
                </div>
                <button type="button" class="close text-white ml-auto" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body p-4">

                <!-- Row 1: Customer Search & Return Date & Return No -->
                <div class="row mb-3">
                    <div class="col-md-5">
                        <label class="form-label small font-weight-bold text-muted mb-1">Customer *</label>
                        <div class="product-search-box">
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text bg-light text-muted"><i class="fas fa-user"></i></span>
                                </div>
                                <input type="text"
                                       id="rifCustomerSearch"
                                       class="form-control"
                                       placeholder="Type customer name / phone / code..."
                                       autocomplete="off">
                            </div>
                            <div id="rifCustomerDropdown" class="product-results-dropdown d-none"></div>
                        </div>
                        <small class="text-success font-weight-bold d-block mt-1" id="rifSelectedCustomer"></small>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small font-weight-bold text-muted mb-1">Return Date *</label>
                        <input type="date" name="return_date" id="rifReturnDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small font-weight-bold text-muted mb-1">Return Voucher #</label>
                        <input type="text" name="return_no" class="form-control bg-light text-danger font-weight-bold" style="font-family: monospace;" value="<?= htmlspecialchars($auto_return_no) ?>" readonly>
                    </div>
                </div>

                <!-- Row 2: Customer Invoices List -->
                <div class="mb-3">
                    <label class="form-label small font-weight-bold text-muted mb-1">Select Invoice to Refund *</label>
                    <div id="rifInvoiceList" class="border rounded-3 p-2 bg-light" style="max-height: 150px; overflow-y: auto;">
                        <div class="p-3 text-muted small">Select a customer above to view their invoices.</div>
                    </div>
                </div>

                <!-- Row 3: Items in Selected Invoice (Hidden until invoice chosen) -->
                <div id="rifItemsWrapper" class="mb-3 d-none">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong class="text-dark small"><i class="fas fa-boxes text-success mr-1"></i> Invoice Products &amp; Return Quantities</strong>
                        <button type="button" class="btn btn-sm btn-outline-success font-weight-bold" onclick="rifSelectAllFull()">
                            <i class="fas fa-check-double mr-1"></i> Return Full Invoice
                        </button>
                    </div>

                    <div class="table-responsive border rounded-3 bg-white">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th style="width: 75px;" class="text-center">Sold</th>
                                    <th style="width: 85px;" class="text-center">Returned</th>
                                    <th style="width: 100px;" class="text-center">Return Qty</th>
                                    <th style="width: 110px;" class="text-right">Unit Rate</th>
                                    <th style="width: 120px;" class="text-right">Amount</th>
                                    <th style="width: 140px;">Condition</th>
                                </tr>
                            </thead>
                            <tbody id="rifItemsBody">
                                <!-- Populated dynamically -->
                            </tbody>
                            <tfoot class="table-light font-weight-bold">
                                <tr>
                                    <td colspan="5" class="text-right text-dark">Total Refund Amount:</td>
                                    <td class="text-right text-danger font-weight-bold" style="font-family: monospace;" id="rifGrandTotal">PKR 0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Row 4: Settlement & Remarks -->
                <div class="row">
                    <div class="col-md-7">
                        <label class="form-label small font-weight-bold text-muted mb-1">Remarks / Return Reason</label>
                        <textarea name="reason" class="form-control" rows="3" placeholder="e.g. Near-expiry batch returned, damaged cartons replaced, customer returned overstock..."></textarea>
                    </div>

                    <div class="col-md-5">
                        <div class="p-3 bg-light rounded-3 border">
                            <div class="mb-3">
                                <label class="form-label small font-weight-bold text-muted mb-1">Refund Settlement Method *</label>
                                <select name="refund_type" id="rifRefundType" class="form-control" onchange="rifToggleAccounts()">
                                    <option value="Credit Note" selected>Credit Note (Deduct / Reduce Customer Balance)</option>
                                    <option value="Cash Refund">Cash Refund (Pay Cash to Customer)</option>
                                    <option value="Bank Refund">Bank Refund (Transfer from Bank Account)</option>
                                    <option value="Direct Restock">Direct Restock Only</option>
                                </select>
                            </div>

                            <div class="mb-3 d-none" id="rifBankBox">
                                <label class="form-label small font-weight-bold text-muted mb-1">Bank Account *</label>
                                <select name="bank_account_id" class="form-control">
                                    <?php foreach ($bank_accounts as $ba): ?>
                                        <option value="<?= $ba['id'] ?>"><?= htmlspecialchars($ba['bank_name']) ?> - <?= htmlspecialchars($ba['account_name']) ?> (<?= htmlspecialchars($ba['account_no']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                                <span class="font-weight-bold text-dark">Grand Refund Total:</span>
                                <span class="font-weight-bold text-danger" style="font-family: monospace; font-size: 1.15rem;" id="rifGrandTotal2">PKR 0.00</span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Modal Footer -->
            <div class="modal-footer bg-light px-4 py-3">
                <button type="button" class="btn btn-outline-secondary px-4 font-weight-bold" data-dismiss="modal">Cancel</button>
                <button type="submit" name="save_invoice_return" id="rifSaveBtn" class="btn btn-success px-4 font-weight-bold shadow-sm">
                    <i class="fas fa-check mr-1"></i> Save Refund
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL: VIEW RETURNED PRODUCTS (EYE BUTTON)               -->
<!-- ======================================================== -->
<div class="modal fade" id="viewItemsModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-3">
            <div class="modal-header bg-light py-3 px-4 border-bottom">
                <div>
                    <h5 class="modal-title font-weight-bold mb-0 text-dark" id="viewModalReturnNoTitle">Sale Return Details</h5>
                    <small class="text-muted" id="viewModalMetaInfo"></small>
                </div>
                <button type="button" class="close ml-auto" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4">
                <!-- Voucher Meta Summary -->
                <div class="row p-3 bg-light rounded-3 mb-3 border">
                    <div class="col-md-4">
                        <span class="text-muted small d-block">Return Voucher:</span>
                        <strong class="text-danger font-weight-bold" style="font-family: monospace; font-size: 1rem;" id="viewModalReturnNo"></strong>
                    </div>
                    <div class="col-md-4">
                        <span class="text-muted small d-block">Return Date:</span>
                        <strong class="text-dark" id="viewModalDate"></strong>
                    </div>
                    <div class="col-md-4">
                        <span class="text-muted small d-block">Customer:</span>
                        <strong class="text-dark" id="viewModalCustomer"></strong>
                    </div>
                    <div class="col-12 mt-2 pt-2 border-top">
                        <span class="text-muted small d-block">Remarks / Reason:</span>
                        <span class="text-secondary small font-italic" id="viewModalRemarks"></span>
                    </div>
                </div>

                <!-- Products Table -->
                <div class="table-responsive border rounded-3">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 45px;" class="text-center">#</th>
                                <th>Product Name</th>
                                <th>Code</th>
                                <th class="text-center">Condition</th>
                                <th class="text-center">Quantity</th>
                                <th class="text-right">Unit Rate</th>
                                <th class="text-right">Total Price</th>
                            </tr>
                        </thead>
                        <tbody id="viewItemsModalTbody">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                        <tfoot class="table-light font-weight-bold">
                            <tr>
                                <td colspan="4" class="text-right">Total Refund:</td>
                                <td class="text-center text-danger font-weight-bold" style="font-family: monospace;" id="viewItemsTotalQty">0</td>
                                <td></td>
                                <td class="text-right text-danger font-weight-bold" style="font-family: monospace; font-size: 1rem;" id="viewItemsTotalPrice">PKR 0.00</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light px-4 py-2">
                <button type="button" class="btn btn-secondary btn-sm px-4 font-weight-bold" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
    const returnItemsStore = <?= json_encode($items_by_return, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // View products modal when eye icon is clicked
    function viewReturnItems(returnId, returnNo, returnDate, customerName, remarks) {
        const items = returnItemsStore[returnId] || [];

        document.getElementById('viewModalReturnNoTitle').textContent = 'Sale Return #' + returnNo + ' Details';
        document.getElementById('viewModalMetaInfo').textContent = returnDate + ' • ' + (items.length) + ' product item(s)';
        document.getElementById('viewModalReturnNo').textContent = returnNo;
        document.getElementById('viewModalDate').textContent = returnDate;
        document.getElementById('viewModalCustomer').textContent = customerName || 'Counter Customer';
        document.getElementById('viewModalRemarks').textContent = remarks ? remarks : '— None —';

        const tbody = document.getElementById('viewItemsModalTbody');
        const totalQtyEl = document.getElementById('viewItemsTotalQty');
        const totalPriceEl = document.getElementById('viewItemsTotalPrice');

        if (!tbody) return;
        tbody.innerHTML = '';

        let grandQty = 0;
        let grandTotal = 0;

        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted">No items recorded for this return voucher.</td></tr>';
        } else {
            items.forEach((it, idx) => {
                const qty = parseInt(it.quantity) || 0;
                const price = parseFloat(it.unit_price) || 0;
                const lineTotal = parseFloat(it.total_price) || (qty * price);

                grandQty += qty;
                grandTotal += lineTotal;

                const cond = it.condition || 'Good / Resalable';
                const isGood = cond.toLowerCase().indexOf('good') !== -1 || cond.toLowerCase().indexOf('resalable') !== -1;
                const condBadge = isGood 
                    ? '<span class="badge badge-success px-2 py-1"><i class="fas fa-check mr-1"></i>Good</span>' 
                    : '<span class="badge badge-danger px-2 py-1"><i class="fas fa-exclamation-triangle mr-1"></i>Damaged</span>';

                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="text-center text-muted font-weight-bold">${idx + 1}</td>
                    <td>
                        <strong class="text-dark">${escapeHtml(it.product_name || 'Product')}</strong>
                    </td>
                    <td>
                        <span class="badge badge-light border text-secondary font-weight-bold" style="font-family: monospace;">${escapeHtml(it.product_code || '')}</span>
                    </td>
                    <td class="text-center">${condBadge}</td>
                    <td class="text-center font-weight-bold text-danger" style="font-family: monospace;">${qty}</td>
                    <td class="text-right font-weight-bold" style="font-family: monospace;">PKR ${price.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                    <td class="text-right font-weight-bold text-dark" style="font-family: monospace;">PKR ${lineTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                `;
                tbody.appendChild(tr);
            });
        }

        if (totalQtyEl) totalQtyEl.textContent = grandQty;
        if (totalPriceEl) totalPriceEl.textContent = 'PKR ' + grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        $('#viewItemsModal').modal('show');
    }

    // ============================================================
    // REFUND BY INVOICE
    // ============================================================
    function openRefundInvoice(customerId, custName, custArea) {
        const form = document.getElementById('refundInvoiceForm');
        if (form) form.reset();

        document.getElementById('rif_invoice_id').value = '';
        document.getElementById('rif_customer_id').value = customerId > 0 ? customerId : '';
        document.getElementById('rifSelectedCustomer').textContent = '';
        document.getElementById('rifInvoiceList').innerHTML = '<div class="p-3 text-muted small">Select a customer to view their invoices.</div>';
        document.getElementById('rifItemsWrapper').classList.add('d-none');
        document.getElementById('rifItemsBody').innerHTML = '';
        rifRecalc();
        rifToggleAccounts();

        const label = custArea ? (custName + ' (' + custArea + ')') : custName;
        if (customerId > 0) {
            document.getElementById('rifCustomerSearch').value = label;
            document.getElementById('rifSelectedCustomer').textContent = 'Selected: ' + label;
            loadCustomerInvoices(customerId);
        } else {
            document.getElementById('rifCustomerSearch').value = '';
        }

        $('#refundInvoiceModal').modal('show');
    }

    function rifToggleAccounts() {
        const typeSelect = document.getElementById('rifRefundType');
        if (!typeSelect) return;
        const type = typeSelect.value;
        const bBox = document.getElementById('rifBankBox');
        if (bBox) {
            if (type === 'Bank Refund') {
                bBox.classList.remove('d-none');
            } else {
                bBox.classList.add('d-none');
            }
        }
    }

    let rifSearchTimeout = null;
    function rifSearchCustomers(query) {
        const dd = document.getElementById('rifCustomerDropdown');
        if (!dd) return;
        fetch('sale_return.php?action=search_customer&q=' + encodeURIComponent(query))
            .then(res => res.json())
            .then(data => {
                dd.innerHTML = '';
                if (!data || data.length === 0) {
                    dd.innerHTML = '<div class="p-3 text-muted text-center small">No customers found.</div>';
                    dd.classList.remove('d-none');
                    return;
                }
                data.forEach(c => {
                    const item = document.createElement('div');
                    item.className = 'product-search-item';
                    const label = c.area ? (c.full_name + ' (' + c.area + ')') : c.full_name;
                    item.innerHTML = `
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong class="text-dark d-block">${escapeHtml(label)}</strong>
                                <small class="text-muted">${escapeHtml(c.customer_no || '')} ${c.phone ? '&bull; ' + escapeHtml(c.phone) : ''}</small>
                            </div>
                            <span class="badge badge-light border text-danger font-weight-bold" style="font-family: monospace;">Bal: PKR ${parseFloat(c.current_balance || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
                        </div>`;
                    item.addEventListener('click', () => {
                        document.getElementById('rif_customer_id').value = c.id;
                        document.getElementById('rifCustomerSearch').value = label;
                        document.getElementById('rifSelectedCustomer').textContent = 'Selected: ' + label;
                        dd.classList.add('d-none');
                        loadCustomerInvoices(c.id);
                    });
                    dd.appendChild(item);
                });
                dd.classList.remove('d-none');
            })
            .catch(() => {});
    }

    function loadCustomerInvoices(customerId) {
        const box = document.getElementById('rifInvoiceList');
        box.innerHTML = '<div class="p-3 text-muted small"><i class="fas fa-spinner fa-spin mr-1"></i> Loading customer invoices...</div>';
        document.getElementById('rifItemsWrapper').classList.add('d-none');
        document.getElementById('rif_invoice_id').value = '';

        fetch('sale_return.php?action=customer_invoices&customer_id=' + encodeURIComponent(customerId))
            .then(res => res.json())
            .then(invoices => {
                box.innerHTML = '';
                if (!invoices || invoices.length === 0) {
                    box.innerHTML = '<div class="p-3 text-muted small">No active sales invoices found for this customer.</div>';
                    return;
                }
                invoices.forEach(inv => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn btn-outline-secondary btn-sm text-left w-100 mb-1 d-flex justify-content-between align-items-center py-2 px-3 border';
                    btn.innerHTML = `
                        <div>
                            <strong class="text-danger font-weight-bold" style="font-family: monospace;">${escapeHtml(inv.invoice_no)}</strong>
                            <small class="text-muted ml-2">${escapeHtml(inv.sale_date)}</small>
                        </div>
                        <div class="small">
                            <span class="badge badge-light border mr-2">${parseInt(inv.item_count) || 0} items</span>
                            <strong class="text-dark" style="font-family: monospace;">PKR ${parseFloat(inv.total_amount || 0).toFixed(2)}</strong>
                        </div>`;
                    btn.addEventListener('click', () => selectRifInvoice(inv.id, btn));
                    box.appendChild(btn);
                });
            })
            .catch(() => {
                box.innerHTML = '<div class="p-3 text-danger small">Failed to load invoices.</div>';
            });
    }

    function selectRifInvoice(invoiceId, btn) {
        document.querySelectorAll('#rifInvoiceList .btn').forEach(b => b.classList.remove('active', 'bg-light'));
        if (btn) btn.classList.add('active', 'bg-light');
        document.getElementById('rif_invoice_id').value = invoiceId;
        loadInvoiceItems(invoiceId);
    }

    function loadInvoiceItems(invoiceId) {
        const wrap = document.getElementById('rifItemsWrapper');
        const body = document.getElementById('rifItemsBody');
        wrap.classList.remove('d-none');
        body.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3"><i class="fas fa-spinner fa-spin mr-1"></i> Loading invoice items...</td></tr>';

        fetch('sale_return.php?action=invoice_details&invoice_id=' + encodeURIComponent(invoiceId))
            .then(res => res.json())
            .then(data => {
                body.innerHTML = '';
                const items = (data && data.items) ? data.items : [];
                if (items.length === 0) {
                    body.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">No items found in this invoice.</td></tr>';
                    rifRecalc();
                    return;
                }
                items.forEach((it, idx) => {
                    const returnable = parseInt(it.returnable_qty) || 0;
                    const price = parseFloat(it.unit_price) || 0;
                    const disabled = returnable <= 0;
                    const initQty = disabled ? 0 : returnable;

                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>
                            <strong class="text-dark d-block">${escapeHtml(it.product_name || 'Product')}</strong>
                            <small class="text-muted">${escapeHtml(it.product_code || '')}</small>
                            <input type="hidden" name="inv_items[${idx}][product_id]" value="${it.product_id}">
                            <input type="hidden" name="inv_items[${idx}][unit_price]" value="${price}">
                        </td>
                        <td class="text-center font-weight-bold" style="font-family: monospace;">${parseInt(it.sold_qty) || 0}</td>
                        <td class="text-center font-weight-bold text-muted" style="font-family: monospace;">${parseInt(it.returned_qty) || 0}</td>
                        <td class="text-center">
                            <input type="number"
                                   name="inv_items[${idx}][quantity]"
                                   class="form-control form-control-sm text-center font-weight-bold text-success rif-qty"
                                   value="${initQty}" min="0" max="${returnable}"
                                   data-price="${price}"
                                   ${disabled ? 'disabled' : ''}
                                   oninput="rifCalcRow(this)">
                        </td>
                        <td class="text-right font-weight-bold" style="font-family: monospace;">PKR ${price.toFixed(2)}</td>
                        <td class="text-right font-weight-bold rif-amount" style="font-family: monospace;">PKR ${(initQty * price).toFixed(2)}</td>
                        <td>
                            <select name="inv_items[${idx}][condition]" class="form-control form-control-sm">
                                <option value="Good / Resalable">Good (Restock)</option>
                                <option value="Damaged / Expiry Claim">Damaged / Expiry</option>
                            </select>
                        </td>`;
                    body.appendChild(tr);
                });
                rifRecalc();
            })
            .catch(() => {
                body.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-3">Failed to load invoice items.</td></tr>';
            });
    }

    function rifCalcRow(el) {
        const tr = el.closest('tr');
        if (!tr) return;
        const max = parseInt(el.max) || 0;
        let qty = parseInt(el.value) || 0;
        if (qty < 0) qty = 0;
        if (qty > max) { qty = max; el.value = max; }
        const price = parseFloat(el.dataset.price) || 0;
        const amountEl = tr.querySelector('.rif-amount');
        if (amountEl) amountEl.textContent = 'PKR ' + (qty * price).toFixed(2);
        rifRecalc();
    }

    function rifRecalc() {
        let grand = 0;
        document.querySelectorAll('#rifItemsBody .rif-qty').forEach(inp => {
            const qty = parseInt(inp.value) || 0;
            const price = parseFloat(inp.dataset.price) || 0;
            grand += qty * price;
        });
        const formatted = 'PKR ' + grand.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const el1 = document.getElementById('rifGrandTotal');
        const el2 = document.getElementById('rifGrandTotal2');
        if (el1) el1.textContent = formatted;
        if (el2) el2.textContent = formatted;
    }

    function rifSelectAllFull() {
        document.querySelectorAll('#rifItemsBody .rif-qty').forEach(inp => {
            if (inp.disabled) return;
            inp.value = parseInt(inp.max) || 0;
            const tr = inp.closest('tr');
            const price = parseFloat(inp.dataset.price) || 0;
            const amountEl = tr ? tr.querySelector('.rif-amount') : null;
            if (amountEl) amountEl.textContent = 'PKR ' + ((parseInt(inp.value) || 0) * price).toFixed(2);
        });
        rifRecalc();
    }

    // Wire up refund-modal interactions
    (function() {
        const rifSearch = document.getElementById('rifCustomerSearch');
        const rifDrop = document.getElementById('rifCustomerDropdown');
        if (rifSearch) {
            rifSearch.addEventListener('input', function() {
                clearTimeout(rifSearchTimeout);
                const q = this.value.trim();
                rifSearchTimeout = setTimeout(() => rifSearchCustomers(q), 150);
            });
            rifSearch.addEventListener('focus', function() {
                if (!document.getElementById('rif_customer_id').value) {
                    rifSearchCustomers(this.value.trim());
                }
            });
        }
        document.addEventListener('click', function(e) {
            if (rifDrop && !rifDrop.contains(e.target) && e.target !== rifSearch) {
                rifDrop.classList.add('d-none');
            }
        });

        const rifForm = document.getElementById('refundInvoiceForm');
        if (rifForm) {
            rifForm.addEventListener('submit', function(e) {
                const invoiceId = parseInt(document.getElementById('rif_invoice_id').value) || 0;
                const customerId = parseInt(document.getElementById('rif_customer_id').value) || 0;
                if (customerId <= 0) {
                    e.preventDefault();
                    alert('Please select a customer.');
                    return false;
                }
                if (invoiceId <= 0) {
                    e.preventDefault();
                    alert('Please select an invoice to refund.');
                    return false;
                }
                let totalQty = 0;
                document.querySelectorAll('#rifItemsBody .rif-qty').forEach(inp => {
                    totalQty += (parseInt(inp.value) || 0);
                });
                if (totalQty <= 0) {
                    e.preventDefault();
                    alert('Please enter a return quantity of 1 or more for at least one product.');
                    return false;
                }
            });
        }
    })();
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
