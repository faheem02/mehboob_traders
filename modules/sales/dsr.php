<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Daily Sales Report';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) $date = date('Y-m-d');
$salesman_id = (int)($_GET['salesman_id'] ?? 0);

$products = $pdo->query("SELECT id, code, name, unit, boxes_per_carton, sale_price, purchase_price, stock_quantity FROM products WHERE status = 1 ORDER BY name")->fetchAll();
$bank_accounts = $pdo->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id")->fetchAll();
$all_salesmen = $pdo->query("SELECT id, full_name, area FROM employees WHERE employee_type = 'salesman' AND status = 1 ORDER BY full_name ASC")->fetchAll();

$selected_salesman_name = '';
if ($salesman_id > 0) {
    foreach ($all_salesmen as $sm) {
        if ((int)$sm['id'] === $salesman_id) {
            $selected_salesman_name = $sm['full_name'];
            break;
        }
    }
}

// ==================== POST HANDLERS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---------- ADD ENTRY (creates a new sale) ----------
    if ($action === 'add') {
        $customer_id    = $_POST['customer_id'] ?: null;
        $salesman_post  = !empty($_POST['salesman_id']) ? (int)$_POST['salesman_id'] : null;
        $product_id     = $_POST['product_id'] ?: null;
        $qty            = (float)($_POST['quantity'] ?? 0);
        $rate           = (float)($_POST['rate'] ?? 0);
        $sale_date      = $_POST['sale_date'] ?: date('Y-m-d');
        $payment_method = $_POST['payment_method'] ?: 'credit';
        $bank_account_id = $payment_method == 'bank' ? ($_POST['bank_account_id'] ?: null) : null;
        $notes          = trim($_POST['notes'] ?? '');
        $paid_amount    = $payment_method == 'credit' ? 0 : (float)($_POST['paid_amount'] ?? 0);
        
        $back = 'dsr.php?date=' . urlencode($sale_date) . ($salesman_post ? '&salesman_id=' . $salesman_post : '');

        if (!$customer_id) redirect($back, 'Select a customer from the suggestions', 'error');
        if (!$product_id)  redirect($back, 'Select a product from the suggestions', 'error');
        if ($qty <= 0)     redirect($back, 'Quantity must be greater than 0', 'error');
        if ($rate <= 0)    redirect($back, 'Rate must be greater than 0', 'error');

        $prod = null;
        foreach ($products as $pp) if ($pp['id'] == $product_id) { $prod = $pp; break; }
        if (!$prod) redirect($back, 'Product not found', 'error');
        if ($qty > (float)$prod['stock_quantity']) {
            redirect($back, 'Insufficient stock: ' . $prod['name'] . ' (only ' . (int)$prod['stock_quantity'] . ' in stock)', 'error');
        }

        $subtotal   = $qty * $rate;
        $net_total  = $subtotal;
        if ($paid_amount > $net_total) $paid_amount = $net_total;
        $due_amount = $net_total - $paid_amount;

        $invoice_no = generateSaleNo();
        $pdo->beginTransaction();
        try {
            $sale_id = insert('sales', [
                'invoice_no'      => $invoice_no,
                'customer_id'     => $customer_id,
                'salesman_id'     => $salesman_post,
                'sale_date'       => $sale_date,
                'total_amount'    => $net_total,
                'discount_amount' => 0,
                'initial_paid'    => $paid_amount,
                'paid_amount'     => $paid_amount,
                'due_amount'      => $due_amount,
                'payment_method'  => $payment_method,
                'bank_account_id' => $bank_account_id,
                'status'          => $due_amount > 0 ? 'active' : 'completed',
                'notes'           => $notes,
                'branch_id'       => currentBranchId($pdo),
                'created_by'      => $_SESSION['user_id'],
                'created_at'      => date('Y-m-d'),
            ]);
            insert('sale_items', [
                'sale_id'   => $sale_id,
                'product_id'=> (int)$product_id,
                'quantity'  => $qty,
                'price'     => $rate,
                'subtotal'  => $subtotal,
            ]);
            $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?")
                ->execute([$qty, (int)$product_id]);

            if ($paid_amount > 0) {
                $desc = "Sale #{$invoice_no}";
                if ($payment_method == 'bank') {
                    recordBankInflow($pdo, $sale_date, $paid_amount, $desc, 'sale', $sale_id, $_SESSION['user_id'], $bank_account_id);
                } else {
                    recordCashInflow($pdo, $sale_date, $paid_amount, $desc, 'sale', $sale_id, $_SESSION['user_id']);
                }
            }
            updateCustomerBalance($pdo, $customer_id);
            $pdo->commit();
            logActivity($pdo, 'create', 'sale', $sale_id, 'Created sale (DSR) ' . $invoice_no . ' total ' . $net_total);
            redirect($back, 'Sale saved: ' . $invoice_no . ', Stock updated.');
        } catch (Exception $e) {
            $pdo->rollBack();
            redirect($back, 'Error saving sale: ' . $e->getMessage(), 'error');
        }
    }

    // ---------- EDIT ENTRY (edit an existing sale / returns & settlement) ----------
    if ($action === 'edit') {
        $sale_id = (int)($_POST['id'] ?? 0);
        $old_sale = getById('sales', $sale_id);
        if (!$old_sale) redirect('dsr.php', 'Sale not found', 'error');
        if (!isAdmin() && (int)$old_sale['created_by'] !== (int)$_SESSION['user_id']) {
            redirect('dsr.php', 'You can only edit your own invoices', 'error');
        }

        // Presets
        $customer_id     = $_POST['customer_id'] ?: null;
        $salesman_post   = !empty($_POST['salesman_id']) ? (int)$_POST['salesman_id'] : null;
        $sale_date       = $_POST['sale_date'] ?: date('Y-m-d');
        $payment_method  = $_POST['payment_method'] ?: 'credit';
        $bank_account_id = $payment_method == 'bank' ? ($_POST['bank_account_id'] ?: null) : null;
        $notes           = trim($_POST['notes'] ?? '');
        $back            = 'dsr.php?date=' . urlencode($sale_date) . ($salesman_post ? '&salesman_id=' . $salesman_post : '');

        $product_ids = (array)($_POST['product_id'] ?? []);
        $quantities  = (array)($_POST['quantity'] ?? []);
        $rates       = (array)($_POST['rate'] ?? []);

        if (!$customer_id) redirect($back, 'Select a customer', 'error');
        if (!count($product_ids) || !$product_ids[0]) redirect($back, 'Add at least one product', 'error');

        $old_items_stmt = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
        $old_items_stmt->execute([$sale_id]);
        $old_items = $old_items_stmt->fetchAll();

        function dsr_old_qty_for($pdo, $sid, $pid) {
            $st = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM sale_items WHERE sale_id = ? AND product_id = ?");
            $st->execute([$sid, $pid]);
            return (float)$st->fetchColumn();
        }

        $total = 0; $items = []; $stock_errors = [];
        foreach ($product_ids as $i => $pid) {
            if (!$pid) continue;
            $qty = (float)($quantities[$i] ?? 0);
            $rate = (float)($rates[$i] ?? 0);
            if ($qty <= 0) continue;
            $prod = null;
            foreach ($products as $pp) if ($pp['id'] == $pid) { $prod = $pp; break; }
            if ($prod && $qty > (float)$prod['stock_quantity'] + dsr_old_qty_for($pdo, $sale_id, (int)$pid)) {
                $stock_errors[] = $prod['name'] . ' (only ' . (int)((float)$prod['stock_quantity'] + dsr_old_qty_for($pdo, $sale_id, (int)$pid)) . ' in stock)';
            }
            $subtotal = $qty * $rate;
            $total += $subtotal;
            $items[] = ['product_id' => (int)$pid, 'qty' => $qty, 'rate' => $rate, 'subtotal' => $subtotal];
        }

        if (count($stock_errors)) redirect($back, 'Insufficient stock: ' . implode(', ', $stock_errors), 'error');
        if (!count($items)) redirect($back, 'Add at least one product with quantity', 'error');

        $paid_amount = (float)($_POST['paid_amount'] ?? 0);
        // If money was collected/paid, ensure payment method reflects cash/bank
        if ($paid_amount > 0 && $payment_method === 'credit') {
            $payment_method = 'cash';
        }
        if ($payment_method === 'credit') {
            $paid_amount = 0;
        }

        $discount = (float)($_POST['discount_amount'] ?? 0);
        if ($discount > $total) $discount = $total;
        $net_total = $total - $discount;
        if ($paid_amount > $net_total) $paid_amount = $net_total;
        $due_amount = $net_total - $paid_amount;

        $pdo->beginTransaction();
        try {
            // 1. Reverse old stock (returns previous boxes back to inventory)
            foreach ($old_items as $oi) {
                $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?")
                    ->execute([(float)$oi['quantity'], $oi['product_id']]);
            }

            // 2. Reverse old cash/bank inflow
            $old_affected_dates = [];
            if ((float)$old_sale['paid_amount'] > 0) {
                $old_cb = $pdo->prepare("SELECT transaction_date FROM cash_book WHERE reference_type = 'sale' AND reference_id = ? AND transaction_type = 'inflow'");
                $old_cb->execute([$sale_id]);
                foreach ($old_cb->fetchAll() as $ocb) { $old_affected_dates[] = $ocb['transaction_date']; }
                $pdo->prepare("DELETE FROM cash_book WHERE reference_type = 'sale' AND reference_id = ?")
                    ->execute([$sale_id]);
                if ($old_sale['payment_method'] == 'bank' || $old_sale['bank_account_id']) {
                    $btn = $pdo->prepare("SELECT id, amount, bank_account_id FROM bank_transactions WHERE reference_type = 'sale' AND reference_id = ?");
                    $btn->execute([$sale_id]);
                    foreach ($btn->fetchAll() as $b) {
                        $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")
                            ->execute([$b['amount'], $b['bank_account_id']]);
                    }
                    $pdo->prepare("DELETE FROM bank_transactions WHERE reference_type = 'sale' AND reference_id = ?")
                        ->execute([$sale_id]);
                }
            }

            // 3. Delete old sale_items
            $pdo->prepare("DELETE FROM sale_items WHERE sale_id = ?")->execute([$sale_id]);

            // 4. Update sale row
            $status = $due_amount > 0 ? 'active' : 'completed';
            $pdo->prepare("UPDATE sales SET customer_id = ?, salesman_id = ?, sale_date = ?, total_amount = ?, discount_amount = ?, initial_paid = ?, paid_amount = ?, due_amount = ?, payment_method = ?, bank_account_id = ?, status = ?, notes = ?, updated_at = ? WHERE id = ?")
                ->execute([$customer_id, $salesman_post, $sale_date, $net_total, $discount, $paid_amount, $paid_amount, $due_amount, $payment_method, $bank_account_id, $status, $notes, date('Y-m-d'), $sale_id]);

            // 5. Insert new items + deduct new stock
            foreach ($items as $it) {
                insert('sale_items', [
                    'sale_id'   => $sale_id,
                    'product_id'=> $it['product_id'],
                    'quantity'  => $it['qty'],
                    'price'     => $it['rate'],
                    'subtotal'  => $it['subtotal'],
                ]);
                $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?")
                    ->execute([$it['qty'], $it['product_id']]);
            }

            // 6. Record new inflow if payment was made
            if ($paid_amount > 0) {
                $desc = "Sale #{$old_sale['invoice_no']}";
                if ($payment_method == 'bank') {
                    recordBankInflow($pdo, $sale_date, $paid_amount, $desc, 'sale', $sale_id, $_SESSION['user_id'], $bank_account_id);
                } else {
                    recordCashInflow($pdo, $sale_date, $paid_amount, $desc, 'sale', $sale_id, $_SESSION['user_id']);
                }
            }

            // 7. Recompute cash daily totals
            foreach (array_unique(array_merge([$sale_date], $old_affected_dates)) as $d) {
                $day = $pdo->prepare("SELECT id FROM cash_book_daily WHERE date = ?");
                $day->execute([$d]);
                $did = $day->fetchColumn();
                if ($did) recomputeCashDayTotals($pdo, (int)$did);
            }
            if ($old_affected_dates) recomputeCashDailyFrom($pdo, min($old_affected_dates));

            // 8. Recalculate customer balances
            if ($old_sale['customer_id']) updateCustomerBalance($pdo, $old_sale['customer_id']);
            if ($customer_id && $customer_id != ($old_sale['customer_id'] ?? null)) updateCustomerBalance($pdo, $customer_id);

            $pdo->commit();
            logActivity($pdo, 'update', 'sale', $sale_id, 'Updated sale (DSR) ' . $old_sale['invoice_no'] . ' total ' . $net_total . ' (Paid: ' . $paid_amount . ')');
            redirect($back, 'Sale updated: ' . $old_sale['invoice_no'] . ', Stock & Accounts adjusted.');
        } catch (Exception $e) {
            $pdo->rollBack();
            redirect($back, 'Error updating sale: ' . $e->getMessage(), 'error');
        }
    }

    // ---------- DELETE ENTRY ----------
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $sale = getById('sales', $id);
        if (!$sale) redirect('dsr.php', 'Sale not found', 'error');
        if (!isAdmin() && (int)$sale['created_by'] !== (int)$_SESSION['user_id']) {
            redirect('dsr.php', 'You can only delete your own invoices', 'error');
        }
        if ($sale['status'] === 'cancelled') redirect('dsr.php', 'Sale already cancelled', 'error');
        $back = 'dsr.php?date=' . urlencode($sale['sale_date']);

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT product_id, quantity FROM sale_items WHERE sale_id = ?");
            $st->execute([$id]);
            foreach ($st->fetchAll() as $it) {
                $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?")
                    ->execute([(float)$it['quantity'], $it['product_id']]);
            }

            $cb = $pdo->prepare("SELECT id, daily_id, transaction_date, amount FROM cash_book WHERE reference_type = 'sale' AND reference_id = ?");
            $cb->execute([$id]);
            $cash_rows = $cb->fetchAll();
            $min_date = null;
            foreach ($cash_rows as $cr) {
                $pdo->prepare("DELETE FROM cash_book WHERE id = ?")->execute([$cr['id']]);
                $day = $pdo->prepare("SELECT id, opening_balance, total_outflow FROM cash_book_daily WHERE date = ?");
                $day->execute([$cr['transaction_date']]);
                $d = $day->fetch();
                if ($d) {
                    $new_inflow = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE transaction_date = ? AND transaction_type = 'inflow'");
                    $new_inflow->execute([$cr['transaction_date']]);
                    $new_inflow = (float)$new_inflow->fetchColumn();
                    $pdo->prepare("UPDATE cash_book_daily SET total_inflow = ?, closing_balance = ? WHERE id = ?")
                        ->execute([$new_inflow, (float)$d['opening_balance'] + $new_inflow - (float)$d['total_outflow'], $d['id']]);
                }
                if ($min_date === null || $cr['transaction_date'] < $min_date) $min_date = $cr['transaction_date'];
            }
            if ($min_date) recomputeCashDailyFrom($pdo, $min_date);

            $bt = $pdo->prepare("SELECT id, amount, bank_account_id FROM bank_transactions WHERE reference_type = 'sale' AND reference_id = ?");
            $bt->execute([$id]);
            foreach ($bt->fetchAll() as $br) {
                $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")
                    ->execute([(float)$br['amount'], $br['bank_account_id']]);
                $pdo->prepare("DELETE FROM bank_transactions WHERE id = ?")->execute([$br['id']]);
            }

            $pdo->prepare("DELETE FROM sale_items WHERE sale_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM sales WHERE id = ?")->execute([$id]);

            if ($sale['customer_id']) updateCustomerBalance($pdo, $sale['customer_id']);
            $pdo->commit();
            logActivity($pdo, 'delete', 'sale', $id, 'Deleted sale (DSR) ' . $sale['invoice_no'] . ' (total ' . $sale['total_amount'] . ')');
            redirect($back, 'Sale deleted successfully');
        } catch (Exception $e) {
            $pdo->rollBack();
            redirect($back, 'Error deleting sale: ' . $e->getMessage(), 'error');
        }
    }

    redirect('dsr.php?date=' . urlencode($date));
}

// ==================== READ DAY DATA & PROFIT CALCULATION ====================
$sql = "SELECT s.id AS sale_id, s.invoice_no, s.sale_date, s.total_amount, s.discount_amount, s.paid_amount, s.due_amount, s.notes, s.status, s.salesman_id,
               c.id AS customer_id, c.full_name AS customer_name, c.phone AS customer_phone, c.area AS customer_area,
               e.full_name AS salesman_name,
               si.id AS item_id, si.quantity, si.price, si.subtotal,
               p.id AS product_id, p.name AS product_name, p.code AS product_code, p.unit, p.boxes_per_carton, p.purchase_price
        FROM sales s
        JOIN sale_items si ON si.sale_id = s.id
        LEFT JOIN customers c ON c.id = s.customer_id
        LEFT JOIN employees e ON e.id = s.salesman_id
        LEFT JOIN products p ON p.id = si.product_id
        WHERE s.sale_date = ? AND s.status <> 'cancelled'";

$params = [$date];

if ($salesman_id > 0) {
    $sql .= " AND s.salesman_id = ?";
    $params[] = $salesman_id;
}

if (!isAdmin()) {
    $sql .= " AND s.created_by = ?";
    $params[] = $_SESSION['user_id'];
}

$sql .= " ORDER BY s.id ASC, si.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$groups = [];
foreach ($rows as $r) {
    $sid = (int)$r['sale_id'];
    
    // Product packaging & cost math
    $bpc = max((int)($r['boxes_per_carton'] ?? 1), 1);
    $purchase_price = (float)($r['purchase_price'] ?? 0);
    $unit_cost = $purchase_price / $bpc; // Cost per box
    $qty = (float)$r['quantity'];
    $item_cost = $unit_cost * $qty;
    $item_profit = (float)$r['subtotal'] - $item_cost;

    $r['unit_cost']   = $unit_cost;
    $r['item_cost']   = $item_cost;
    $r['item_profit'] = $item_profit;

    if (!isset($groups[$sid])) {
        $groups[$sid] = [
            'sale_id'         => $sid,
            'invoice_no'      => $r['invoice_no'],
            'sale_date'       => $r['sale_date'],
            'salesman_id'     => $r['salesman_id'],
            'salesman_name'   => $r['salesman_name'] ?: 'Direct / Counter',
            'customer_name'   => $r['customer_name'] ?: 'Walk-in Customer',
            'customer_phone'  => $r['customer_phone'],
            'customer_area'   => $r['customer_area'],
            'total_amount'    => (float)$r['total_amount'],
            'discount_amount' => (float)$r['discount_amount'],
            'paid_amount'     => (float)$r['paid_amount'],
            'due_amount'      => (float)$r['due_amount'],
            'notes'           => $r['notes'],
            'status'          => $r['status'],
            'total_cost'      => 0,
            'total_profit'    => 0,
            'items'           => []
        ];
    }
    $groups[$sid]['items'][] = $r;
    $groups[$sid]['total_cost']   += $item_cost;
    $groups[$sid]['total_profit'] += $item_profit;
}

// Adjust discount from invoice profit
foreach ($groups as $sid => $g) {
    $groups[$sid]['total_profit'] -= $g['discount_amount'];
}

$inv_count  = count($groups);
$item_count = count($rows);
$day_total  = 0; 
$day_paid   = 0; 
$day_due    = 0;
$day_cost   = 0;
$day_profit = 0;

foreach ($groups as $g) {
    $day_total  += $g['total_amount'];
    $day_paid   += $g['paid_amount'];
    $day_due    += $g['due_amount'];
    $day_cost   += $g['total_cost'];
    $day_profit += $g['total_profit'];
}

$prev_day = date('Y-m-d', strtotime($date . ' -1 day'));
$next_day = date('Y-m-d', strtotime($date . ' +1 day'));
$today    = date('Y-m-d');

$printed_by = '';
if (!empty($_SESSION['user_id'])) {
    $pu = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $pu->execute([(int)$_SESSION['user_id']]);
    $printed_by = (string)$pu->fetchColumn();
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow mb-4">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center d-print-none py-3">
    <div>
      <h5 class="mb-0 font-weight-bold text-gray-800">
        <i class="fas fa-calendar-check text-primary mr-2"></i> Daily Sales Report (DSR)
        <small class="text-muted ml-1">&middot; <?=formatDate($date)?></small>
      </h5>
    </div>
    <div class="d-flex flex-wrap align-items-center mt-2 mt-sm-0">
      <button type="button" class="btn btn-sm btn-success mr-2 shadow-sm" data-toggle="modal" data-target="#addModal"><i class="fas fa-plus mr-1"></i> Add Entry</button>
      <button type="button" class="btn btn-sm btn-primary shadow-sm mr-2" onclick="window.print()"><i class="fas fa-print mr-1"></i> Print DSR</button>
      <a href="invoices.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-file-invoice mr-1"></i> Invoices</a>
    </div>
  </div>
  <div class="card-body pt-4">

    <!-- Filter & Date Navigation Toolbar with generous spacing -->
    <div class="card bg-light border p-3 rounded mb-4 d-print-none shadow-sm">
      <div class="d-flex flex-wrap justify-content-between align-items-center" style="gap: 12px;">
        <!-- Quick Date Navigation -->
        <div class="d-flex align-items-center flex-wrap my-1">
          <span class="small font-weight-bold text-muted mr-2 text-uppercase"><i class="fas fa-calendar-day text-primary mr-1"></i> Quick Date:</span>
          <div class="btn-group btn-group-sm">
            <a href="dsr.php?date=<?=$prev_day?><?= $salesman_id ? '&salesman_id='.$salesman_id : '' ?>" class="btn btn-outline-primary" title="Previous day"><i class="fas fa-chevron-left mr-1"></i> Prev</a>
            <a href="dsr.php?date=<?=$today?><?= $salesman_id ? '&salesman_id='.$salesman_id : '' ?>" class="btn btn-outline-primary<?= $date == $today ? ' active font-weight-bold' : ''?>">Today</a>
            <a href="dsr.php?date=<?=$next_day?><?= $salesman_id ? '&salesman_id='.$salesman_id : '' ?>" class="btn btn-outline-primary" title="Next day">Next <i class="fas fa-chevron-right ml-1"></i></a>
          </div>
        </div>

        <!-- Salesman & Date Filter Form -->
        <form method="get" action="dsr.php" class="form-inline mb-0 d-flex flex-wrap align-items-center my-1">
          <div class="input-group input-group-sm mr-2 my-1">
            <div class="input-group-prepend"><span class="input-group-text bg-white"><i class="fas fa-user-tie text-primary"></i></span></div>
            <select name="salesman_id" class="form-control font-weight-bold" onchange="this.form.submit()">
              <option value="0">-- All Salesmen --</option>
              <?php foreach ($all_salesmen as $sm): ?>
              <option value="<?=$sm['id']?>" <?= $salesman_id === (int)$sm['id'] ? 'selected' : '' ?>>
                <?=htmlspecialchars($sm['full_name'])?><?= $sm['area'] ? ' (' . htmlspecialchars($sm['area']) . ')' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="input-group input-group-sm mr-2 my-1">
            <input type="date" name="date" class="form-control" value="<?=htmlspecialchars($date)?>">
          </div>
          <button type="submit" class="btn btn-sm btn-primary my-1 mr-1 px-3"><i class="fas fa-filter mr-1"></i> Filter</button>
          <?php if ($salesman_id || $date !== date('Y-m-d')): ?>
          <a href="dsr.php" class="btn btn-sm btn-outline-secondary my-1" title="Reset Filters"><i class="fas fa-undo"></i></a>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <!-- Printable Header -->
    <div class="report-sheet d-none d-print-block">
      <div class="report-head">
        <div class="report-brand-line">
          <div class="report-brand">
            <div class="report-brand-name">Mehboob Traders</div>
            <div class="report-brand-sub">Wholesale Business &middot; Lahore, Pakistan &middot; Whole Sale Items</div>
          </div>
          <div class="report-title-box text-right">
            <div class="report-title">Daily Sales Report &amp; Settlement</div>
            <div class="report-meta">Date: <strong><?=formatDate($date)?></strong><?= $selected_salesman_name ? ' &middot; Salesman: <strong>' . htmlspecialchars($selected_salesman_name) . '</strong>' : ' &middot; All Salesmen' ?></div>
          </div>
        </div>
      </div>
      <table class="report-summary-table mb-3">
        <tr>
          <td class="rs-cell"><span class="rs-label">Invoices</span><span class="rs-val"><?=$inv_count?></span></td>
          <td class="rs-cell"><span class="rs-label">Total Sales</span><span class="rs-val">PKR <?=formatCurrency($day_total)?></span></td>
          <td class="rs-cell"><span class="rs-label">Cash Collected</span><span class="rs-val" style="color:#0f766e;">PKR <?=formatCurrency($day_paid)?></span></td>
          <td class="rs-cell"><span class="rs-label">Remaining Due</span><span class="rs-val" style="color:#b91c1c;">PKR <?=formatCurrency($day_due)?></span></td>
          <td class="rs-cell"><span class="rs-label">Estimated Profit</span><span class="rs-val" style="color:<?= $day_profit >= 0 ? '#0f766e' : '#b91c1c' ?>;">PKR <?=formatCurrency($day_profit)?></span></td>
        </tr>
      </table>
    </div>

    <!-- Active Salesman Banner -->
    <?php if ($salesman_id > 0): ?>
    <div class="alert alert-info border-left-info py-2 px-3 mb-3 d-flex flex-wrap justify-content-between align-items-center d-print-none shadow-sm">
      <div>
        <i class="fas fa-user-check fa-lg mr-2 text-info"></i>
        <strong>Salesman Settlement View:</strong> <span class="badge badge-info px-2 py-1 ml-1" style="font-size: 0.95rem;"><?=htmlspecialchars($selected_salesman_name)?></span>
        <span class="text-muted ml-2">(Showing <?=count($groups)?> delivered order<?= count($groups) == 1 ? '' : 's' ?>)</span>
      </div>
      <div class="mt-2 mt-md-0">
        <span class="mr-3">Cash to Collect: <strong class="text-success font-weight-bold" style="font-size: 1.1rem;">PKR <?=formatCurrency($day_paid)?></strong></span>
        <span class="mr-3">Total Sales: <strong>PKR <?=formatCurrency($day_total)?></strong></span>
        <span class="mr-3">Salesman Profit: <strong class="<?= $day_profit >= 0 ? 'text-success' : 'text-danger' ?>">PKR <?=formatCurrency($day_profit)?></strong></span>
        <a href="dsr.php?date=<?=urlencode($date)?>" class="btn btn-xs btn-outline-secondary ml-1"><i class="fas fa-times mr-1"></i> Clear Filter</a>
      </div>
    </div>
    <?php endif; ?>

    <!-- 5 KPI Stat Cards Row -->
    <div class="row mb-4 d-print-none">
      <div class="col-6 col-md">
        <div class="card border-left-primary shadow-sm stat-card h-100">
          <div class="card-body py-3 text-center">
            <div class="text-xs text-uppercase font-weight-bold text-muted mb-1"><i class="fas fa-file-invoice text-primary mr-1"></i> Invoices</div>
            <div class="h5 mb-0 font-weight-bold text-primary"><?=$inv_count?></div>
            <small class="text-muted"><?=$item_count?> items sold</small>
          </div>
        </div>
      </div>
      <div class="col-6 col-md">
        <div class="card border-left-info shadow-sm stat-card h-100">
          <div class="card-body py-3 text-center">
            <div class="text-xs text-uppercase font-weight-bold text-muted mb-1"><i class="fas fa-shopping-bag text-info mr-1"></i> Total Sales</div>
            <div class="h5 mb-0 font-weight-bold text-info">PKR <?=formatCurrency($day_total)?></div>
            <small class="text-muted">Delivered bill total</small>
          </div>
        </div>
      </div>
      <div class="col-6 col-md">
        <div class="card border-left-success shadow-sm stat-card h-100">
          <div class="card-body py-3 text-center">
            <div class="text-xs text-uppercase font-weight-bold text-muted mb-1"><i class="fas fa-money-bill-wave text-success mr-1"></i> Cash Collected</div>
            <div class="h5 mb-0 font-weight-bold text-success">PKR <?=formatCurrency($day_paid)?></div>
            <small class="text-muted">Take from salesman</small>
          </div>
        </div>
      </div>
      <div class="col-6 col-md">
        <div class="card border-left-danger shadow-sm stat-card h-100">
          <div class="card-body py-3 text-center">
            <div class="text-xs text-uppercase font-weight-bold text-muted mb-1"><i class="fas fa-clock text-danger mr-1"></i> Remaining Due</div>
            <div class="h5 mb-0 font-weight-bold text-danger">PKR <?=formatCurrency($day_due)?></div>
            <small class="text-muted">Shop credit (Udhaar)</small>
          </div>
        </div>
      </div>
      <div class="col-12 col-md mt-2 mt-md-0">
        <div class="card border-left-emerald shadow-sm stat-card h-100" style="border-left: 4px solid #10b981 !important;">
          <div class="card-body py-3 text-center">
            <div class="text-xs text-uppercase font-weight-bold text-muted mb-1"><i class="fas fa-chart-line text-success mr-1"></i> Estimated Profit</div>
            <div class="h5 mb-0 font-weight-bold <?= $day_profit >= 0 ? 'text-success' : 'text-danger' ?>" style="color: #059669 !important;">
              PKR <?=formatCurrency($day_profit)?>
            </div>
            <small class="text-muted">Wholesale margin</small>
          </div>
        </div>
      </div>
    </div>

    <?php if (!$groups): ?>
      <div class="alert alert-light border text-center py-5 my-4">
        <i class="fas fa-calendar-times text-muted fa-3x mb-3 d-block"></i>
        <h5 class="text-muted font-weight-bold">No sales recorded for <?=formatDate($date)?></h5>
        <p class="text-muted small mb-3">
          <?= $salesman_id ? 'No orders were delivered by ' . htmlspecialchars($selected_salesman_name) . ' on this date.' : 'No active orders were found for this date. Click Add Entry to record a sale.' ?>
        </p>
        <button type="button" class="btn btn-sm btn-success" data-toggle="modal" data-target="#addModal"><i class="fas fa-plus mr-1"></i> Add Sales Entry</button>
      </div>
    <?php else: ?>

    <!-- Search bar & Count -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 d-print-none">
      <div class="input-group input-group-sm" style="max-width: 320px;">
        <div class="input-group-prepend"><span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span></div>
        <input type="text" id="dsrSearch" class="form-control" placeholder="Search invoice, customer, salesman, item..." onkeydown="if(event.key==='Enter')event.preventDefault();">
      </div>
      <small class="text-muted font-weight-bold mt-2 mt-sm-0">
        <i class="fas fa-check-circle text-success mr-1"></i> Showing <?=$inv_count?> invoice<?= $inv_count == 1 ? '' : 's' ?> (<?=$item_count?> items)
      </small>
    </div>

    <!-- DSR Table -->
    <div class="table-responsive">
      <table class="table table-bordered table-hover report-table" id="dsrTable">
        <thead class="bg-light">
          <tr>
            <th style="width: 40px;" class="text-center">#</th>
            <th style="width: 120px;">Invoice</th>
            <th style="width: 130px;">Salesman</th>
            <th>Customer / Shop</th>
            <th>Product Name</th>
            <th style="width: 90px;" class="text-right">Qty (Boxes)</th>
            <th style="width: 95px;" class="text-right">Rate</th>
            <th style="width: 105px;" class="text-right">Item Total</th>
            <th style="width: 110px;" class="text-right">Bill Total</th>
            <th style="width: 110px;" class="text-right">Paid (Cash)</th>
            <th style="width: 100px;" class="text-right">Due</th>
            <th style="width: 105px;" class="text-right">Profit</th>
            <th style="width: 95px;" class="text-center no-print">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          $serial = 0; 
          foreach ($groups as $g): 
            $serial++;
            $n = count($g['items']); 
            foreach ($g['items'] as $j => $r): 
          ?>
          <tr class="dsr-row">
            <?php if ($j === 0): ?>
            <!-- Rowspanned Invoice Meta Columns -->
            <td rowspan="<?=$n?>" class="text-center align-middle text-muted"><?=$serial?></td>
            <td rowspan="<?=$n?>" class="align-middle font-weight-bold">
              <a href="invoice.php?id=<?=(int)$g['sale_id']?>" target="_blank" class="text-primary" title="View Single Invoice">
                <?=htmlspecialchars($g['invoice_no'])?>
              </a>
              <small class="text-muted d-block"><?=formatDate($g['sale_date'])?></small>
            </td>
            <td rowspan="<?=$n?>" class="align-middle">
              <span class="badge badge-light border text-dark font-weight-bold p-1 d-block text-truncate" style="max-width: 125px;" title="<?=htmlspecialchars($g['salesman_name'])?>">
                <i class="fas fa-user-tie text-secondary mr-1"></i> <?=htmlspecialchars($g['salesman_name'])?>
              </span>
            </td>
            <td rowspan="<?=$n?>" class="align-middle">
              <div class="font-weight-bold text-dark"><?=htmlspecialchars($g['customer_name'])?></div>
              <?php if ($g['customer_area']): ?>
                <small class="text-muted d-block">
                  <i class="fas fa-map-marker-alt text-danger mr-1"></i> <?=htmlspecialchars($g['customer_area'])?>
                  <?= $g['customer_phone'] ? ' &middot; ' . htmlspecialchars($g['customer_phone']) : '' ?>
                </small>
              <?php endif; ?>
            </td>
            <?php endif; ?>

            <!-- Per-Item Columns -->
            <td class="align-middle">
              <?=htmlspecialchars($r['product_name'] ?: ('#' . (int)$r['product_id']))?>
              <?php if ($r['product_code']): ?>
                <small class="text-muted d-block">[<?=htmlspecialchars($r['product_code'])?>] &middot; <?=(int)$r['boxes_per_carton']?> <?=$r['unit']?>/ctn</small>
              <?php endif; ?>
            </td>
            <td class="text-right align-middle font-weight-bold text-dark"><?=(float)$r['quantity']?></td>
            <td class="text-right align-middle text-muted">PKR <?=formatCurrency($r['price'])?></td>
            <td class="text-right align-middle font-weight-bold text-dark">
              PKR <?=formatCurrency($r['subtotal'])?>
              <?php if ((float)$r['item_profit'] != 0): ?>
                <small class="d-block <?= (float)$r['item_profit'] >= 0 ? 'text-success' : 'text-danger' ?>" style="font-size: 0.75rem;" title="Item profit">
                  P: <?=formatCurrency($r['item_profit'])?>
                </small>
              <?php endif; ?>
            </td>

            <?php if ($j === 0): ?>
            <!-- Rowspanned Financial Columns -->
            <td rowspan="<?=$n?>" class="text-right align-middle font-weight-bold text-primary" style="font-size: 1.05rem;">
              PKR <?=formatCurrency($g['total_amount'])?>
              <?php if ($g['discount_amount'] > 0): ?>
                <small class="text-danger d-block font-weight-normal">-Disc: <?=formatCurrency($g['discount_amount'])?></small>
              <?php endif; ?>
            </td>
            <td rowspan="<?=$n?>" class="text-right align-middle font-weight-bold text-success" style="font-size: 1.05rem;">
              PKR <?=formatCurrency($g['paid_amount'])?>
            </td>
            <td rowspan="<?=$n?>" class="text-right align-middle font-weight-bold text-danger">
              PKR <?=formatCurrency($g['due_amount'])?>
            </td>
            <td rowspan="<?=$n?>" class="text-right align-middle font-weight-bold <?= $g['total_profit'] >= 0 ? 'text-success' : 'text-danger' ?>" style="font-size: 1.05rem;">
              PKR <?=formatCurrency($g['total_profit'])?>
            </td>
            <td rowspan="<?=$n?>" class="text-center align-middle no-print nowrap">
              <button type="button" class="btn btn-sm btn-outline-warning btn-edit mr-1" data-id="<?=(int)$g['sale_id']?>" data-toggle="modal" data-target="#editModal" title="Edit Sale / Returns / Payment">
                <i class="fas fa-pencil-alt"></i>
              </button>
              <a href="invoice.php?id=<?=(int)$g['sale_id']?>" target="_blank" class="btn btn-sm btn-outline-primary mr-1" title="View &amp; Print Invoice">
                <i class="fas fa-eye"></i>
              </a>
              <form method="post" action="dsr.php" class="d-inline" onsubmit="return confirm('Delete this sale (<?=htmlspecialchars($g['invoice_no'])?>)? This reverses stock back to inventory and reverses any cash entry.');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?=(int)$g['sale_id']?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Sale"><i class="fas fa-trash-alt"></i></button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; endforeach; ?>
        </tbody>
        <tfoot class="report-tfoot bg-light font-weight-bold">
          <tr>
            <td colspan="8" class="text-right">TOTAL (<?=$inv_count?> Invoices &middot; <?=$item_count?> Items):</td>
            <td class="text-right text-primary">PKR <?=formatCurrency($day_total)?></td>
            <td class="text-right text-success">PKR <?=formatCurrency($day_paid)?></td>
            <td class="text-right text-danger">PKR <?=formatCurrency($day_due)?></td>
            <td class="text-right <?= $day_profit >= 0 ? 'text-success' : 'text-danger' ?>">PKR <?=formatCurrency($day_profit)?></td>
            <td class="no-print text-center">-</td>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- Printable Signature Footer -->
    <div class="report-foot d-none d-print-block mt-4 pt-4 border-top">
      <div class="row text-center">
        <div class="col-4">
          <div class="border-top pt-2" style="border-top: 1px dashed #475569 !important;">
            <strong>Prepared by (Cashier / Accounts)</strong>
            <div class="small text-muted"><?=htmlspecialchars($printed_by ?: 'Cashier Office')?></div>
          </div>
        </div>
        <div class="col-4">
          <div class="border-top pt-2" style="border-top: 1px dashed #475569 !important;">
            <strong>Salesman Handover / Signature</strong>
            <div class="small text-muted"><?= $selected_salesman_name ? htmlspecialchars($selected_salesman_name) : 'Delivering Salesman' ?></div>
          </div>
        </div>
        <div class="col-4">
          <div class="border-top pt-2" style="border-top: 1px dashed #475569 !important;">
            <strong>Verified By (Manager / Admin)</strong>
            <div class="small text-muted">Signature: ____________________</div>
          </div>
        </div>
      </div>
      <div class="text-center mt-3 small text-muted">
        Mehboob Traders &middot; Daily Sales Report &middot; Printed on <?=date('d-m-Y H:i')?>
      </div>
    </div>

    <?php endif; ?>

  </div>
</div>

<!-- ============ ADD ENTRY MODAL ============ -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <form method="post" action="dsr.php" id="addForm" novalidate>
      <input type="hidden" name="action" value="add">
      <div class="modal-content shadow-lg">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title font-weight-bold"><i class="fas fa-plus-circle mr-2"></i> Add Sales Entry</h5>
          <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label font-weight-bold">Date *</label>
              <input type="date" name="sale_date" class="form-control" value="<?=htmlspecialchars($date)?>">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label font-weight-bold">Salesman (Deliver By)</label>
              <select name="salesman_id" class="form-control">
                <option value="">-- Select Salesman --</option>
                <?php foreach ($all_salesmen as $sm): ?>
                <option value="<?=$sm['id']?>" <?= $salesman_id === (int)$sm['id'] ? 'selected' : '' ?>>
                  <?=htmlspecialchars($sm['full_name'])?><?= $sm['area'] ? ' (' . htmlspecialchars($sm['area']) . ')' : '' ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label font-weight-bold">Payment Method</label>
              <select name="payment_method" id="addPayMethod" class="form-control">
                <option value="credit" selected>Credit (Unpaid)</option>
                <option value="cash">Cash Received</option>
                <option value="bank">Bank Transfer</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label font-weight-bold">Customer / Shop *</label>
            <div class="ac-wrap" id="addCustomerWrap">
              <input type="text" id="addCustomerSearch" class="form-control" placeholder="Type customer name, area, or phone to search..." autocomplete="off">
              <input type="hidden" name="customer_id" id="addCustomer_id">
              <div class="ac-list" id="addCustomerList"></div>
            </div>
            <small class="text-danger d-none" id="addCustomerError"><i class="fas fa-exclamation-circle"></i> Please select a customer from the suggestions.</small>
          </div>
          <div class="form-group">
            <label class="form-label font-weight-bold">Product *</label>
            <div class="ac-wrap" id="addProductWrap">
              <input type="text" id="addProductSearch" class="form-control" placeholder="Type product name or code..." autocomplete="off">
              <input type="hidden" name="product_id" id="addProduct_id">
              <div class="ac-list" id="addProductList"></div>
            </div>
            <small class="text-danger d-none" id="addProductError"><i class="fas fa-exclamation-circle"></i> Please select a product from the suggestions.</small>
          </div>
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label font-weight-bold">Qty (Boxes) *</label>
              <input type="number" name="quantity" id="addQty" class="form-control font-weight-bold" min="1" step="1" placeholder="Boxes count">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label font-weight-bold">Rate (Per Box)</label>
              <input type="number" name="rate" id="addRate" class="form-control" min="0" step="0.01" placeholder="0.00">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label font-weight-bold">Item Total</label>
              <input type="text" id="addAmount" class="form-control font-weight-bold text-primary" readonly value="0.00">
            </div>
          </div>
          <div class="row" id="addPaidRow" style="display:none;">
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Paid Amount (Cash)</label>
              <input type="number" name="paid_amount" id="addPaid" class="form-control" min="0" step="0.01" value="0">
            </div>
            <div class="col-md-6 mb-3" id="addBankDiv" style="display:none;">
              <label class="form-label font-weight-bold">Bank Account</label>
              <select name="bank_account_id" class="form-control">
                <?php foreach ($bank_accounts as $ba): ?>
                <option value="<?=$ba['id']?>"><?=htmlspecialchars($ba['account_name'])?> - <?=htmlspecialchars($ba['bank_name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label font-weight-bold">Notes</label>
            <input type="text" name="notes" class="form-control" placeholder="Optional delivery notes">
          </div>
        </div>
        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-save mr-1"></i> Save Entry</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ============ EDIT ENTRY MODAL (Returns & Cash Settlement) ============ -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-xl" role="document">
    <form method="post" action="dsr.php" id="editForm" novalidate>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="edit_id">
      <div class="modal-content shadow-lg">
        <div class="modal-header bg-warning text-dark">
          <h5 class="modal-title font-weight-bold">
            <i class="fas fa-edit mr-2"></i> Edit Sale / Returns &amp; Settlement <span id="editInvoiceNo" class="badge badge-dark ml-2"></span>
          </h5>
          <button type="button" class="close text-dark" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
          <div class="alert alert-light border py-2 px-3 mb-3 text-muted small">
            <i class="fas fa-info-circle text-primary mr-1"></i> <strong>How it works:</strong> If the shop returned boxes, decrease the <strong>Qty (Boxes)</strong> below. The returned boxes will automatically go back into your stock. Enter any cash paid by the shop under <strong>Paid Amount</strong> to adjust the customer's balance.
          </div>

          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label font-weight-bold">Customer / Shop *</label>
              <div class="ac-wrap" id="editCustomerWrap">
                <input type="text" id="editCustomerSearch" class="form-control" placeholder="Type customer name..." autocomplete="off">
                <input type="hidden" name="customer_id" id="editCustomer_id">
                <div class="ac-list" id="editCustomerList"></div>
              </div>
              <small class="text-danger d-none" id="editCustomerError"><i class="fas fa-exclamation-circle"></i> Please select a customer.</small>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label font-weight-bold">Salesman (Deliver By)</label>
              <div class="ac-wrap" id="editSalesmanWrap">
                <input type="text" id="editSalesmanSearch" class="form-control" placeholder="Type salesman name..." autocomplete="off">
                <input type="hidden" name="salesman_id" id="editSalesman_id">
                <div class="ac-list" id="editSalesmanList"></div>
              </div>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label font-weight-bold">Sale Date *</label>
              <input type="date" name="sale_date" id="editDate" class="form-control">
            </div>
          </div>

          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label font-weight-bold">Payment Method</label>
              <select name="payment_method" id="editPayMethod" class="form-control font-weight-bold">
                <option value="cash">Cash (Collected by Salesman)</option>
                <option value="credit">Credit (Unpaid / Udhaar)</option>
                <option value="bank">Bank Transfer</option>
              </select>
            </div>
            <div class="col-md-4 mb-3" id="editBankDiv" style="display:none;">
              <label class="form-label font-weight-bold">Bank Account</label>
              <select name="bank_account_id" id="editBankAccount" class="form-control">
                <?php foreach ($bank_accounts as $ba): ?>
                <option value="<?=$ba['id']?>"><?=htmlspecialchars($ba['account_name'])?> - <?=htmlspecialchars($ba['bank_name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <hr class="my-2">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0 font-weight-bold text-dark"><i class="fas fa-boxes text-primary mr-2"></i> Delivered Products (Adjust for Returns)</h6>
            <button type="button" class="btn btn-sm btn-outline-primary" id="editAddRow"><i class="fas fa-plus mr-1"></i> Add Another Product</button>
          </div>

          <!-- Product Rows Container -->
          <div id="editProductRows" class="mb-3"></div>

          <!-- Financial Calculation Block -->
          <div class="card bg-light border p-3">
            <div class="row align-items-center">
              <div class="col-md-3 mb-2 mb-md-0">
                <label class="form-label font-weight-bold small text-muted mb-1">Discount (PKR)</label>
                <input type="number" name="discount_amount" id="editDiscount" class="form-control form-control-sm" min="0" step="0.01" value="0">
              </div>
              <div class="col-md-3 mb-2 mb-md-0">
                <label class="form-label font-weight-bold small text-muted mb-1">Total Bill Amount</label>
                <input type="text" id="editTotal" class="form-control form-control-sm font-weight-bold text-primary bg-white" readonly value="0.00">
              </div>
              <div class="col-md-3 mb-2 mb-md-0" id="editPaidRow">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <label class="form-label font-weight-bold small text-muted mb-0">Paid (Cash from Salesman)</label>
                  <button type="button" class="btn btn-link btn-xs p-0 text-success font-weight-bold" id="editFullPaidBtn" title="Set to Full Bill">Full Paid</button>
                </div>
                <input type="number" name="paid_amount" id="editPaid" class="form-control form-control-sm font-weight-bold text-success" min="0" step="0.01" value="0">
              </div>
              <div class="col-md-3 mb-2 mb-md-0">
                <label class="form-label font-weight-bold small text-muted mb-1">Remaining Due (Udhaar)</label>
                <input type="text" id="editDue" class="form-control form-control-sm font-weight-bold text-danger bg-white" readonly value="0.00">
              </div>
            </div>
          </div>

          <div class="form-group mt-3 mb-0">
            <label class="form-label font-weight-bold small">Notes / Settlement Remarks</label>
            <input type="text" name="notes" id="editNotes" class="form-control form-control-sm" placeholder="e.g. Returned 2 boxes due to damaged packaging; collected cash">
          </div>
        </div>
        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success font-weight-bold"><i class="fas fa-save mr-1"></i> Save &amp; Update Settlement</button>
        </div>
      </div>
    </form>
  </div>
</div>

<style>
@media print {
  @page { size: A4 landscape; margin: 10mm; }
  .report-table th { font-size: 11px !important; padding: 6px 6px !important; }
  .report-table td { font-size: 11.5px !important; padding: 5px 6px !important; }
  tfoot.report-tfoot td { font-size: 12px !important; padding: 6px 6px !important; }
  .rs-label { font-size: 10px !important; letter-spacing: 0.6px !important; }
  .rs-val   { font-size: 15px !important; }
}
</style>

<script>
$(document).ready(function(){
  function esc(s){
    return String(s == null ? '' : s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')
      .replace(/'/g,'&#39;');
  }
  function hideList($list){ $list.empty().hide(); }

  // =================== ADD MODAL ===================
  hideList($('#addCustomerList')); hideList($('#addProductList'));

  var addCustTimer = null;
  $('#addCustomerSearch').on('input', function(){
    var q = $.trim(this.value);
    clearTimeout(addCustTimer);
    if (!q) { $('#addCustomer_id').val(''); $('#addCustomerError').addClass('d-none'); hideList($('#addCustomerList')); return; }
    addCustTimer = setTimeout(function(){
      $.getJSON('ajax_customer_search.php', {q: q}, function(data){
        var $list = $('#addCustomerList');
        $list.empty();
        if (!data || !data.length) {
          $list.append('<div class="ac-item ac-empty">No matching customer found</div>');
        } else {
          $.each(data, function(i, it){
            var sub = [];
            if (it.phone) sub.push('Phone: ' + esc(it.phone));
            if (it.area) sub.push('Area: ' + esc(it.area));
            $list.append('<div class="ac-item" data-id="' + it.id + '">' +
              '<span class="ac-name">' + esc(it.full_name) + '</span>' +
              (sub.length ? '<small class="ac-sub">' + sub.join(' &middot; ') + '</small>' : '') +
              '</div>');
          });
        }
        $list.show();
      });
    }, 250);
  });

  $('#addCustomerList').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    $('#addCustomer_id').val($(this).data('id'));
    $('#addCustomerSearch').val($(this).find('.ac-name').text());
    $('#addCustomerError').addClass('d-none');
    hideList($('#addCustomerList'));
  });

  var addProdTimer = null;
  $('#addProductSearch').on('input', function(){
    var q = $.trim(this.value);
    clearTimeout(addProdTimer);
    if (!q) { $('#addProduct_id').val(''); $('#addRate').val(''); hideList($('#addProductList')); return; }
    addProdTimer = setTimeout(function(){
      $.getJSON('ajax_product_search.php', {q: q}, function(data){
        var $list = $('#addProductList');
        $list.empty();
        if (!data || !data.length) {
          $list.append('<div class="ac-item ac-empty">No matching product found</div>');
        } else {
          $.each(data, function(i, it){
            var sub = [];
            if (it.code) sub.push('Code: ' + esc(it.code));
            if (it.unit) sub.push('Unit: ' + esc(it.unit));
            $list.append('<div class="ac-item" data-id="' + it.id + '" data-sale="' + it.sale_price + '" data-bpc="' + it.boxes_per_carton + '">' +
              '<span class="ac-name">' + esc(it.name) + '</span>' +
              '<small class="ac-sub">' + sub.join(' &middot; ') + '</small>' +
              '<small class="ac-sub"><i class="fas fa-boxes"></i> In stock: ' + it.stock_quantity + '</small>' +
              '</div>');
          });
        }
        $list.show();
      });
    }, 250);
  });

  $('#addProductList').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    var bpc = parseInt($(this).data('bpc')) || 1;
    if (bpc < 1) bpc = 1;
    var sale = parseFloat($(this).data('sale')) || 0;
    $('#addProduct_id').val($(this).data('id'));
    $('#addProductSearch').val($(this).find('.ac-name').text());
    $('#addProductError').addClass('d-none');
    $('#addRate').val((sale / bpc).toFixed(2));
    hideList($('#addProductList'));
    recalcAdd();
  });

  function recalcAdd(){
    var qty = parseFloat($('#addQty').val()) || 0;
    var rate = parseFloat($('#addRate').val()) || 0;
    var total = qty * rate;
    $('#addAmount').val(total.toFixed(2));
    var paid = $('#addPayMethod').val() === 'credit' ? 0 : (parseFloat($('#addPaid').val()) || 0);
    if (paid > total) $('#addPaid').val(total);
  }
  $('#addQty, #addRate, #addPaid').on('input', recalcAdd);

  $('#addPayMethod').change(function(){
    var v = $(this).val();
    $('#addPaidRow').toggle(v !== 'credit');
    $('#addBankDiv').toggle(v === 'bank');
    if (v === 'credit') $('#addPaid').val(0);
    recalcAdd();
  });

  $('#addForm').on('submit', function(e){
    if (!$('#addCustomer_id').val()) {
      e.preventDefault();
      $('#addCustomerError').removeClass('d-none');
      $('#addCustomerSearch').focus();
      return;
    }
    if (!$('#addProduct_id').val()) {
      e.preventDefault();
      $('#addProductError').removeClass('d-none');
      $('#addProductSearch').focus();
      return;
    }
    if ((parseFloat($('#addQty').val()) || 0) <= 0) {
      e.preventDefault();
      alert('Quantity must be greater than 0.');
      $('#addQty').focus();
      return;
    }
  });

  // =================== EDIT MODAL (Returns & Settlement) ===================
  hideList($('#editCustomerList')); hideList($('#editSalesmanList'));

  var editCustTimer = null;
  $('#editCustomerSearch').on('input', function(){
    var q = $.trim(this.value);
    clearTimeout(editCustTimer);
    if (!q) { $('#editCustomer_id').val(''); $('#editCustomerError').addClass('d-none'); hideList($('#editCustomerList')); return; }
    editCustTimer = setTimeout(function(){
      $.getJSON('ajax_customer_search.php', {q: q}, function(data){
        var $list = $('#editCustomerList');
        $list.empty();
        if (!data || !data.length) {
          $list.append('<div class="ac-item ac-empty">No matching customer found</div>');
        } else {
          $.each(data, function(i, it){
            var sub = [];
            if (it.phone) sub.push('Phone: ' + esc(it.phone));
            if (it.area) sub.push('Area: ' + esc(it.area));
            $list.append('<div class="ac-item" data-id="' + it.id + '">' +
              '<span class="ac-name">' + esc(it.full_name) + '</span>' +
              (sub.length ? '<small class="ac-sub">' + sub.join(' &middot; ') + '</small>' : '') +
              '</div>');
          });
        }
        $list.show();
      });
    }, 250);
  });

  $('#editCustomerList').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    $('#editCustomer_id').val($(this).data('id'));
    $('#editCustomerSearch').val($(this).find('.ac-name').text());
    $('#editCustomerError').addClass('d-none');
    hideList($('#editCustomerList'));
  });

  var editSalesTimer = null;
  $('#editSalesmanSearch').on('input', function(){
    var q = $.trim(this.value);
    clearTimeout(editSalesTimer);
    if (!q) { $('#editSalesman_id').val(''); hideList($('#editSalesmanList')); return; }
    editSalesTimer = setTimeout(function(){
      $.getJSON('ajax_salesman_search.php', {q: q}, function(data){
        var $list = $('#editSalesmanList');
        $list.empty();
        if (!data || !data.length) {
          $list.append('<div class="ac-item ac-empty">No matching salesman found</div>');
        } else {
          $.each(data, function(i, it){
            var sub = [];
            if (it.area) sub.push('Area: ' + esc(it.area));
            if (it.phone) sub.push('Phone: ' + esc(it.phone));
            $list.append('<div class="ac-item" data-id="' + it.id + '">' +
              '<span class="ac-name">' + esc(it.full_name) + '</span>' +
              (sub.length ? '<small class="ac-sub">' + sub.join(' &middot; ') + '</small>' : '') +
              '</div>');
          });
        }
        $list.show();
      });
    }, 250);
  });

  $('#editSalesmanList').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    $('#editSalesman_id').val($(this).data('id'));
    $('#editSalesmanSearch').val($(this).find('.ac-name').text());
    hideList($('#editSalesmanList'));
  });

  $('#editPayMethod').change(function(){
    var v = $(this).val();
    $('#editBankDiv').toggle(v === 'bank');
    if (v === 'credit') {
      $('#editPaid').val(0);
    }
    recalcEdit();
  });

  // Automatically switch to cash if cashier types a paid amount > 0 while method is credit
  $('#editPaid').on('input', function(){
    var val = parseFloat($(this).val()) || 0;
    if (val > 0 && $('#editPayMethod').val() === 'credit') {
      $('#editPayMethod').val('cash');
      $('#editBankDiv').hide();
    }
    recalcEdit();
  });

  $('#editFullPaidBtn').click(function(e){
    e.preventDefault();
    var net = parseFloat($('#editTotal').val()) || 0;
    $('#editPaid').val(net.toFixed(2));
    if ($('#editPayMethod').val() === 'credit') {
      $('#editPayMethod').val('cash');
      $('#editBankDiv').hide();
    }
    recalcEdit();
  });

  function newEditRow(item){
    var $row = $('<div class="product-row card mb-2 p-2 bg-white border">' +
      '<div class="row align-items-center g-2">' +
        '<div class="col-md-5">' +
          '<label class="small font-weight-bold text-muted mb-0">Product</label>' +
          '<div class="ac-wrap">' +
            '<input type="text" class="form-control form-control-sm product-search" placeholder="Type product name or code..." autocomplete="off">' +
            '<input type="hidden" name="product_id[]" class="product-id">' +
            '<div class="ac-list"></div>' +
          '</div>' +
        '</div>' +
        '<div class="col-md-2">' +
          '<label class="small font-weight-bold text-muted mb-0">Qty (Delivered)</label>' +
          '<input type="number" name="quantity[]" class="form-control form-control-sm qty font-weight-bold" min="0" step="1" placeholder="Qty">' +
        '</div>' +
        '<div class="col-md-2">' +
          '<label class="small font-weight-bold text-muted mb-0">Rate / Box</label>' +
          '<input type="number" name="rate[]" class="form-control form-control-sm rate" min="0" step="0.01" placeholder="Rate">' +
        '</div>' +
        '<div class="col-md-2">' +
          '<label class="small font-weight-bold text-muted mb-0">Subtotal</label>' +
          '<input type="text" class="form-control form-control-sm subtotal font-weight-bold text-primary bg-light" readonly value="0.00">' +
        '</div>' +
        '<div class="col-md-1 text-right pt-3">' +
          '<button type="button" class="btn btn-sm btn-outline-danger remove-row" title="Remove item"><i class="fas fa-trash"></i></button>' +
        '</div>' +
      '</div>' +
    '</div>');

    if (item) {
      $row.find('.product-id').val(item.product_id);
      $row.find('.product-search').val(item.product_name + (item.product_code ? ' [' + item.product_code + ']' : ''));
      $row.find('.qty').val(item.quantity);
      $row.find('.rate').val(item.price);
      $row.find('.subtotal').val((item.quantity * item.price).toFixed(2));
    }
    return $row;
  }

  var editProdTimer = null;
  $('#editProductRows').on('input', '.product-search', function(){
    var $row = $(this).closest('.product-row');
    var $list = $row.find('.ac-list');
    var q = $.trim(this.value);
    clearTimeout(editProdTimer);
    if (!q) { $row.find('.product-id').val(''); hideList($list); recalcEdit(); return; }
    editProdTimer = setTimeout(function(){
      $.getJSON('ajax_product_search.php', {q: q}, function(data){
        $list.empty();
        if (!data || !data.length) {
          $list.append('<div class="ac-item ac-empty">No matching product found</div>');
        } else {
          $.each(data, function(i, it){
            var sub = [];
            if (it.code) sub.push('Code: ' + esc(it.code));
            if (it.unit) sub.push('Unit: ' + esc(it.unit));
            $list.append('<div class="ac-item" data-id="' + it.id + '" data-sale="' + it.sale_price + '" data-bpc="' + it.boxes_per_carton + '">' +
              '<span class="ac-name">' + esc(it.name) + '</span>' +
              '<small class="ac-sub">' + sub.join(' &middot; ') + '</small>' +
              '<small class="ac-sub"><i class="fas fa-boxes"></i> In stock: ' + it.stock_quantity + '</small>' +
              '</div>');
          });
        }
        $list.show();
      });
    }, 250);
  });

  $('#editProductRows').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    var $row = $(this).closest('.product-row');
    var bpc = parseInt($(this).data('bpc')) || 1;
    if (bpc < 1) bpc = 1;
    var sale = parseFloat($(this).data('sale')) || 0;
    $row.find('.product-id').val($(this).data('id'));
    $row.find('.product-search').val($(this).find('.ac-name').text());
    $row.find('.rate').val((sale / bpc).toFixed(2));
    hideList($row.find('.ac-list'));
    recalcEdit();
  });

  $('#editAddRow').click(function(){
    $('#editProductRows').append(newEditRow(null));
    recalcEdit();
  });

  $('#editProductRows').on('click', '.remove-row', function(){
    if ($('#editProductRows .product-row').length > 1) {
      $(this).closest('.product-row').remove();
      recalcEdit();
    } else {
      alert('At least one product row is required.');
    }
  });

  $('#editProductRows').on('input', '.qty, .rate', recalcEdit);
  $('#editDiscount').on('input', recalcEdit);

  function recalcEdit(){
    var total = 0;
    $('#editProductRows .product-row').each(function(){
      var rate = parseFloat($(this).find('.rate').val()) || 0;
      var qty = parseFloat($(this).find('.qty').val()) || 0;
      var sub = rate * qty;
      $(this).find('.subtotal').val(sub.toFixed(2));
      total += sub;
    });
    var disc = parseFloat($('#editDiscount').val()) || 0;
    var net = Math.max(total - disc, 0);
    $('#editTotal').val(net.toFixed(2));
    var paid = $('#editPayMethod').val() === 'credit' ? 0 : (parseFloat($('#editPaid').val()) || 0);
    if (paid > net) {
      paid = net;
      $('#editPaid').val(net.toFixed(2));
    }
    $('#editDue').val(Math.max(net - paid, 0).toFixed(2));
  }

  function openEdit(id){
    $('#edit_id').val(id);
    $.getJSON('ajax_sale_detail.php', {id: id}, function(data){
      if (data.error) {
        alert(data.error);
        $('#editModal').modal('hide');
        return;
      }
      $('#editInvoiceNo').text(data.invoice_no ? data.invoice_no : '');
      $('#editDate').val(data.sale_date);
      $('#editCustomer_id').val(data.customer_id || '');
      $('#editCustomerSearch').val(data.customer_name || '');
      $('#editSalesman_id').val(data.salesman_id || '');
      $('#editSalesmanSearch').val(data.salesman_name || '');
      $('#editNotes').val(data.notes || '');
      $('#editDiscount').val(data.discount_amount || 0);

      var pm = data.payment_method || 'credit';
      if ((parseFloat(data.paid_amount) || 0) > 0 && pm === 'credit') {
        pm = 'cash';
      }
      $('#editPayMethod').val(pm);

      if (data.bank_account_id) $('#editBankAccount').val(data.bank_account_id);
      $('#editPaid').val(data.paid_amount || 0);
      
      $('#editBankDiv').toggle(pm === 'bank');

      $('#editProductRows').empty();
      var items = data.items && data.items.length ? data.items : [{product_id:'', quantity:'', price:''}];
      $.each(items, function(i, it){ $('#editProductRows').append(newEditRow(it)); });
      recalcEdit();
    }).fail(function(){
      alert('Could not load sale details.');
      $('#editModal').modal('hide');
    });
  }

  $('.btn-edit').on('click', function(){
    openEdit($(this).data('id'));
  });

  $('#editForm').on('submit', function(e){
    if (!$('#editCustomer_id').val()) {
      e.preventDefault();
      $('#editCustomerError').removeClass('d-none');
      $('#editCustomerSearch').focus();
      return;
    }
    var filled = false;
    $('#editProductRows .product-row').each(function(){
      if ($(this).find('.product-id').val()) filled = true;
    });
    if (!filled) {
      e.preventDefault();
      alert('Please add at least one product.');
    }
  });

  $('#editModal').on('hidden.bs.modal', function(){
    $('#editProductRows').empty();
  });

  // Keyboard navigation & search clickaway
  $(document).on('keydown', '#addCustomerSearch, #addProductSearch', function(e){
    var $list = $(this).parent().find('.ac-list');
    var items = $list.find('.ac-item:not(.ac-empty)');
    if (!$list.is(':visible') || !items.length) return;
    var idx = items.index(items.filter('.active'));
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      var dir = e.key === 'ArrowDown' ? 1 : -1;
      idx = (idx + dir + items.length) % items.length;
      items.removeClass('active').eq(idx).addClass('active');
    } else if (e.key === 'Enter') {
      e.preventDefault();
      var target = idx >= 0 ? items.eq(idx) : items.first();
      if (target.length) target.trigger('mousedown');
    } else if (e.key === 'Escape') {
      hideList($list);
    }
  });

  $(document).on('keydown', '#editCustomerSearch, #editSalesmanSearch, #editProductRows .product-search', function(e){
    var $list = $(this).closest('.ac-wrap').find('.ac-list');
    var items = $list.find('.ac-item:not(.ac-empty)');
    if (!$list.is(':visible') || !items.length) return;
    var idx = items.index(items.filter('.active'));
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      var dir = e.key === 'ArrowDown' ? 1 : -1;
      idx = (idx + dir + items.length) % items.length;
      items.removeClass('active').eq(idx).addClass('active');
    } else if (e.key === 'Enter') {
      e.preventDefault();
      var target = idx >= 0 ? items.eq(idx) : items.first();
      if (target.length) target.trigger('mousedown');
    } else if (e.key === 'Escape') {
      hideList($list);
    }
  });

  $(document).on('mouseover', '.ac-item', function(){
    $(this).addClass('active').siblings().removeClass('active');
  });

  $(document).on('mousedown', function(e){
    if (!$(e.target).closest('.ac-wrap').length) {
      $('.ac-list').empty().hide();
    }
  });

  // Live filter on DSR table
  $('#dsrSearch').on('input', function(){
    var q = $.trim($(this).val()).toLowerCase();
    var matched = 0;
    $('#dsrTable tbody tr.dsr-row').each(function(){
      var txt = $(this).text().toLowerCase();
      var show = !q || txt.indexOf(q) > -1;
      $(this).toggle(show);
      if (show) matched++;
    });
    var $none = $('#dsrNoMatch');
    if (!matched) {
      if (!$none.length) {
        $none = $('<tr id="dsrNoMatch"><td colspan="13" class="text-center text-muted py-4"><i class="fas fa-search mr-2"></i> No sales entries match your search.</td></tr>');
        $('#dsrTable tbody').append($none);
      }
    } else if ($none.length) {
      $none.remove();
    }
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>