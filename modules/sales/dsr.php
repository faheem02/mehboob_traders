<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Daily Sales Report';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) $date = date('Y-m-d');

$products = $pdo->query("SELECT id, code, name, unit, boxes_per_carton, sale_price, purchase_price, stock_quantity FROM products WHERE status = 1 ORDER BY name")->fetchAll();
$bank_accounts = $pdo->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id")->fetchAll();

// ==================== POST HANDLERS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---------- ADD ENTRY (creates a new sale) ----------
    if ($action === 'add') {
        $customer_id = $_POST['customer_id'] ?: null;
        $product_id  = $_POST['product_id'] ?: null;
        $qty         = (float)($_POST['quantity'] ?? 0);
        $rate        = (float)($_POST['rate'] ?? 0);
        $sale_date   = $_POST['sale_date'] ?: date('Y-m-d');
        $payment_method = $_POST['payment_method'] ?: 'credit';
        $bank_account_id = $payment_method == 'bank' ? ($_POST['bank_account_id'] ?: null) : null;
        $notes       = trim($_POST['notes'] ?? '');
        $paid_amount = $payment_method == 'credit' ? 0 : (float)($_POST['paid_amount'] ?? 0);
        $back        = 'dsr.php?date=' . urlencode($sale_date);

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
                'invoice_no'    => $invoice_no,
                'customer_id'   => $customer_id,
                'salesman_id'   => null,
                'sale_date'     => $sale_date,
                'total_amount'  => $net_total,
                'discount_amount' => 0,
                'initial_paid'  => $paid_amount,
                'paid_amount'   => $paid_amount,
                'due_amount'    => $due_amount,
                'payment_method'=> $payment_method,
                'bank_account_id'=> $bank_account_id,
                'status'        => $due_amount > 0 ? 'active' : 'completed',
                'notes'         => $notes,
                'branch_id'     => currentBranchId($pdo),
                'created_by'    => $_SESSION['user_id'],
                'created_at'    => date('Y-m-d'),
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

    // ---------- EDIT ENTRY (edit an existing sale) ----------
    if ($action === 'edit') {
        $sale_id = (int)($_POST['id'] ?? 0);
        $old_sale = getById('sales', $sale_id);
        if (!$old_sale) redirect('dsr.php', 'Sale not found', 'error');
        if (!isAdmin() && (int)$old_sale['created_by'] !== (int)$_SESSION['user_id']) {
            redirect('dsr.php', 'You can only edit your own invoices', 'error');
        }

        // Presets
        $customer_id = $_POST['customer_id'] ?: null;
        $salesman_id = $_POST['salesman_id'] ?: null;
        $sale_date   = $_POST['sale_date'] ?: date('Y-m-d');
        $payment_method = $_POST['payment_method'] ?: 'credit';
        $bank_account_id = $payment_method == 'bank' ? ($_POST['bank_account_id'] ?: null) : null;
        $notes       = trim($_POST['notes'] ?? '');
        $back        = 'dsr.php?date=' . urlencode($sale_date);

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

        $paid_amount = $payment_method == 'credit' ? 0 : (float)($_POST['paid_amount'] ?? 0);
        $discount = (float)($_POST['discount_amount'] ?? 0);
        if ($discount > $total) $discount = $total;
        $net_total = $total - $discount;
        if ($paid_amount > $net_total) $paid_amount = $net_total;
        $due_amount = $net_total - $paid_amount;

        $pdo->beginTransaction();
        try {
            // 1. Reverse old stock
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

            // 4. Update sale row (preserve invoice_no / created info)
            $status = $due_amount > 0 ? 'active' : 'completed';
            $pdo->prepare("UPDATE sales SET customer_id = ?, salesman_id = ?, sale_date = ?, total_amount = ?, discount_amount = ?, initial_paid = ?, paid_amount = ?, due_amount = ?, payment_method = ?, bank_account_id = ?, status = ?, notes = ?, updated_at = ? WHERE id = ?")
                ->execute([$customer_id, $salesman_id ? (int)$salesman_id : null, $sale_date, $net_total, $discount, $paid_amount, $paid_amount, $due_amount, $payment_method, $bank_account_id, $status, $notes, date('Y-m-d'), $sale_id]);

            // 5. Insert new items + deduct stock
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

            // 6. Record new inflow
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
            logActivity($pdo, 'update', 'sale', $sale_id, 'Updated sale (DSR) ' . $old_sale['invoice_no'] . ' total ' . $net_total);
            redirect($back, 'Sale updated: ' . $old_sale['invoice_no'] . ', Stock adjusted.');
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

    // unknown action -> stay on page
    redirect('dsr.php?date=' . urlencode($date));
}

// ==================== READ DAY DATA ====================
$sql = "SELECT s.id AS sale_id, s.invoice_no, s.sale_date, s.total_amount, s.paid_amount, s.due_amount, s.notes, s.status,
               c.id AS customer_id, c.full_name AS customer_name, c.phone AS customer_phone, c.area AS customer_area,
               si.id AS item_id, si.quantity, si.price, si.subtotal,
               p.name AS product_name, p.code AS product_code, p.unit
        FROM sales s
        JOIN sale_items si ON si.sale_id = s.id
        LEFT JOIN customers c ON c.id = s.customer_id
        LEFT JOIN products p ON p.id = si.product_id
        WHERE s.sale_date = ? AND s.status <> 'cancelled'";
$params = [$date];
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
    if (!isset($groups[$sid])) {
        $groups[$sid] = ['invoice_no' => $r['invoice_no'], 'sale_date' => $r['sale_date'], 'total_amount' => (float)$r['total_amount'], 'paid_amount' => (float)$r['paid_amount'], 'due_amount' => (float)$r['due_amount'], 'notes' => $r['notes'], 'customer_name' => $r['customer_name'] ?: 'Walk-in Customer', 'customer_phone' => $r['customer_phone'], 'customer_area' => $r['customer_area'], 'status' => $r['status'], 'items' => []];
    }
    $groups[$sid]['items'][] = $r;
}

$inv_count = count($groups);
$item_count = count($rows);
$day_total = 0; $day_paid = 0; $day_due = 0;
foreach ($groups as $g) {
    $day_total += $g['total_amount'];
    $day_paid += $g['paid_amount'];
    $day_due += $g['due_amount'];
}

$prev_day = date('Y-m-d', strtotime($date . ' -1 day'));
$next_day = date('Y-m-d', strtotime($date . ' +1 day'));
$today = date('Y-m-d');

$printed_by = '';
if (!empty($_SESSION['user_id'])) {
    $pu = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $pu->execute([(int)$_SESSION['user_id']]);
    $printed_by = (string)$pu->fetchColumn();
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center d-print-none">
    <h6><i class="fas fa-calendar-day"></i> Daily Sales Report — <?=formatDate($date)?></h6>
    <div class="d-flex flex-wrap align-items-center">
      <div class="btn-group mr-2">
        <a href="dsr.php?date=<?=$prev_day?>" class="btn btn-sm btn-outline-primary" title="Previous day"><i class="fas fa-chevron-left"></i></a>
        <a href="dsr.php?date=<?=$today?>" class="btn btn-sm btn-outline-primary<?= $date == $today ? ' active' : ''?>">Today</a>
        <a href="dsr.php?date=<?=$next_day?>" class="btn btn-sm btn-outline-primary" title="Next day"><i class="fas fa-chevron-right"></i></a>
      </div>
      <form method="get" action="dsr.php" class="form-inline mr-2">
        <input type="date" name="date" class="form-control form-control-sm mr-1" value="<?=htmlspecialchars($date)?>">
        <button class="btn btn-sm btn-primary"><i class="fas fa-search"></i> View</button>
      </form>
      <button type="button" class="btn btn-sm btn-success mr-2" data-toggle="modal" data-target="#addModal"><i class="fas fa-plus"></i> Add Entry</button>
      <button type="button" class="btn btn-sm btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    </div>
  </div>
  <div class="card-body">

    <div class="report-sheet d-none d-print-block">
      <div class="report-head">
        <div class="report-brand-line">
          <div class="report-brand">
            <div class="report-brand-name">Mehboob Traders</div>
            <div class="report-brand-sub">Wholesale Business &middot; Lahore, Pakistan &middot; GST No: --</div>
          </div>
          <div class="report-title-box">
            <div class="report-title">Daily Sales Report</div>
            <div class="report-meta"><?=formatDate($date)?></div>
          </div>
        </div>
      </div>
      <table class="report-summary-table">
        <tr>
          <td class="rs-cell"><span class="rs-label">Invoices</span><span class="rs-val"><?=$inv_count?></span></td>
          <td class="rs-cell"><span class="rs-label">Items</span><span class="rs-val"><?=$item_count?></span></td>
          <td class="rs-cell"><span class="rs-label">Total Sales</span><span class="rs-val">PKR <?=formatCurrency($day_total)?></span></td>
          <td class="rs-cell"><span class="rs-label">Total Paid</span><span class="rs-val" style="color:#0f766e;">PKR <?=formatCurrency($day_paid)?></span></td>
          <td class="rs-cell"><span class="rs-label">Total Due</span><span class="rs-val" style="color:#b91c1c;">PKR <?=formatCurrency($day_due)?></span></td>
        </tr>
      </table>
    </div>

    <div class="row mb-3 d-print-none">
      <div class="col-6 col-md">
        <div class="card border-left-primary shadow stat-card">
          <div class="card-body py-3 text-center">
            <div class="text-xs text-uppercase text-muted">Invoices</div>
            <div class="h5 mb-0 font-weight-bold text-primary"><?=$inv_count?></div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md">
        <div class="card border-left-info shadow stat-card">
          <div class="card-body py-3 text-center">
            <div class="text-xs text-uppercase text-muted">Items</div>
            <div class="h5 mb-0 font-weight-bold text-info"><?=$item_count?></div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md">
        <div class="card border-left-primary shadow stat-card">
          <div class="card-body py-3 text-center">
            <div class="text-xs text-uppercase text-muted">Total Sales</div>
            <div class="h5 mb-0 font-weight-bold text-primary">PKR <?=formatCurrency($day_total)?></div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md">
        <div class="card border-left-success shadow stat-card">
          <div class="card-body py-3 text-center">
            <div class="text-xs text-uppercase text-muted">Total Paid</div>
            <div class="h5 mb-0 font-weight-bold text-success">PKR <?=formatCurrency($day_paid)?></div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md">
        <div class="card border-left-danger shadow stat-card">
          <div class="card-body py-3 text-center">
            <div class="text-xs text-uppercase text-muted">Total Due</div>
            <div class="h5 mb-0 font-weight-bold text-danger">PKR <?=formatCurrency($day_due)?></div>
          </div>
        </div>
      </div>
    </div>

    <?php if (!$groups): ?>
      <p class="text-muted text-center py-4 mb-0">No sales recorded for <?=formatDate($date)?>. Click <strong>Add Entry</strong> to record one.</p>
    <?php else: ?>
    <div class="d-flex justify-content-between align-items-center mb-2 d-print-none">
      <input type="text" id="dsrSearch" class="form-control col-md-4 col-lg-3" placeholder="Search invoice / customer / product..." onkeydown="if(event.key==='Enter')event.preventDefault();">
      <small class="text-muted"><?=$item_count?> item<?=$item_count==1?'':'s'?> in <?=$inv_count?> invoice<?=$inv_count==1?'':'s'?></small>
    </div>
    <div class="table-responsive">
      <table class="table table-bordered table-hover report-table" id="dsrTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Invoice</th>
            <th>Date</th>
            <th>Customer</th>
            <th>Product</th>
            <th class="text-right">Qty (Boxes)</th>
            <th class="text-right">Rate</th>
            <th class="text-right">Amount</th>
            <th>Notes</th>
            <th class="no-print">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php $i = 0; foreach ($groups as $g): $n = count($g['items']); foreach ($g['items'] as $j => $r): $i++; ?>
          <tr class="dsr-row">
            <?php if ($j === 0): ?>
            <td rowspan="<?=$n?>" class="align-middle"><?=$i?></td>
            <td rowspan="<?=$n?>" class="align-middle font-weight-bold">
              <a href="invoice.php?id=<?=(int)$r['sale_id']?>" target="_blank"><?=htmlspecialchars($g['invoice_no'])?></a>
            </td>
            <td rowspan="<?=$n?>" class="align-middle"><?=formatDate($g['sale_date'])?></td>
            <td rowspan="<?=$n?>" class="align-middle">
              <span class="font-weight-bold"><?=htmlspecialchars($g['customer_name'])?></span>
              <?php if ($g['customer_area']): ?><small class="d-block text-muted"><?=htmlspecialchars($g['customer_area'])?><?= $g['customer_phone'] ? ' &middot; ' . htmlspecialchars($g['customer_phone']) : ''?></small><?php endif; ?>
            </td>
            <?php endif; ?>
            <td><?=htmlspecialchars($r['product_name'] ?: ('#' . (int)$r['product_id']))?><?= $r['product_code'] ? ' <small class="text-muted">[' . htmlspecialchars($r['product_code']) . ']</small>' : ''?></td>
            <td class="text-right"><?=(float)$r['quantity']?></td>
            <td class="text-right">PKR <?=formatCurrency($r['price'])?></td>
            <td class="text-right font-weight-bold">PKR <?=formatCurrency($r['subtotal'])?></td>
            <td><?=htmlspecialchars($r['notes'] ?? '')?></td>
            <?php if ($j === 0): ?>
            <td rowspan="<?=$n?>" class="align-middle no-print text-center nowrap">
              <button type="button" class="btn btn-sm btn-outline-warning btn-edit" data-id="<?=(int)$r['sale_id']?>" data-toggle="modal" data-target="#editModal" title="Edit"><i class="fas fa-pencil-alt"></i></button>
              <form method="post" action="dsr.php" class="d-inline" onsubmit="return confirm('Delete this sale (<?=htmlspecialchars($g['invoice_no'])?>)? This reverses the stock and any cash/bank entry.');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?=(int)$r['sale_id']?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash-alt"></i></button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; endforeach; ?>
        </tbody>
        <tfoot class="report-tfoot">
          <tr>
            <td colspan="7">TOTAL (<?=$inv_count?> invoices &middot; <?=$item_count?> items)</td>
            <td class="text-right">PKR <?=formatCurrency($day_total)?></td>
            <td></td>
            <td class="no-print"></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="report-foot d-none d-print-block">
      <div><strong>Prepared by:</strong> <?=htmlspecialchars($printed_by ?: '—')?></div>
      <div><strong>Printed on:</strong> <?=date('d-m-Y H:i')?></div>
      <div>Mehboob Traders &middot; Daily Sales Report</div>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- ============ ADD ENTRY MODAL ============ -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <form method="post" action="dsr.php" id="addForm" novalidate>
      <input type="hidden" name="action" value="add">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fas fa-plus-circle text-success"></i> Add Sales Entry</h5>
          <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Date *</label>
              <input type="date" name="sale_date" class="form-control" value="<?=htmlspecialchars($date)?>">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Payment Method</label>
              <select name="payment_method" id="addPayMethod" class="form-control">
                <option value="credit" selected>Credit</option>
                <option value="cash">Cash</option>
                <option value="bank">Bank</option>
              </select>
              <small class="text-muted" id="addPayHint">No amount field for credit — full amount goes to due.</small>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Customer *</label>
            <div class="ac-wrap" id="addCustomerWrap">
              <input type="text" id="addCustomerSearch" class="form-control" placeholder="Type customer name / phone to search..." autocomplete="off">
              <input type="hidden" name="customer_id" id="addCustomer_id">
              <div class="ac-list" id="addCustomerList"></div>
            </div>
            <small class="text-danger d-none" id="addCustomerError"><i class="fas fa-exclamation-circle"></i> Please select a customer from the suggestions.</small>
          </div>
          <div class="form-group">
            <label class="form-label">Product *</label>
            <div class="ac-wrap" id="addProductWrap">
              <input type="text" id="addProductSearch" class="form-control" placeholder="Type product name or code..." autocomplete="off">
              <input type="hidden" name="product_id" id="addProduct_id">
              <div class="ac-list" id="addProductList"></div>
            </div>
            <small class="text-danger d-none" id="addProductError"><i class="fas fa-exclamation-circle"></i> Please select a product from the suggestions.</small>
          </div>
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">Qty (Boxes) *</label>
              <input type="number" name="quantity" id="addQty" class="form-control" min="0" step="0.01">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Rate</label>
              <input type="number" name="rate" id="addRate" class="form-control" min="0" step="0.01">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Amount</label>
              <input type="text" id="addAmount" class="form-control font-weight-bold" readonly value="0.00">
            </div>
          </div>
          <div class="row" id="addPaidRow" style="display:none;">
            <div class="col-md-6 mb-3">
              <label class="form-label">Paid Amount</label>
              <input type="number" name="paid_amount" id="addPaid" class="form-control" min="0" value="0">
            </div>
            <div class="col-md-6 mb-3" id="addBankDiv" style="display:none;">
              <label class="form-label">Bank Account</label>
              <select name="bank_account_id" class="form-control">
                <?php foreach ($bank_accounts as $ba): ?>
                <option value="<?=$ba['id']?>"><?=htmlspecialchars($ba['account_name'])?> - <?=htmlspecialchars($ba['bank_name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control" placeholder="Optional notes">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Entry</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ============ EDIT ENTRY MODAL ============ -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-xl" role="document">
    <form method="post" action="dsr.php" id="editForm" novalidate>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="edit_id">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fas fa-pencil-alt text-warning"></i> Edit Sales Entry <small class="text-muted" id="editInvoiceNo"></small></h5>
          <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">Customer *</label>
              <div class="ac-wrap" id="editCustomerWrap">
                <input type="text" id="editCustomerSearch" class="form-control" placeholder="Type customer name / phone to search..." autocomplete="off">
                <input type="hidden" name="customer_id" id="editCustomer_id">
                <div class="ac-list" id="editCustomerList"></div>
              </div>
              <small class="text-danger d-none" id="editCustomerError"><i class="fas fa-exclamation-circle"></i> Please select a customer from the suggestions.</small>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Salesman (Deliver By) <small class="text-muted">optional</small></label>
              <div class="ac-wrap" id="editSalesmanWrap">
                <input type="text" id="editSalesmanSearch" class="form-control" placeholder="Type salesman name to search..." autocomplete="off">
                <input type="hidden" name="salesman_id" id="editSalesman_id">
                <div class="ac-list" id="editSalesmanList"></div>
              </div>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Date *</label>
              <input type="date" name="sale_date" id="editDate" class="form-control">
            </div>
          </div>
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">Payment Method</label>
              <select name="payment_method" id="editPayMethod" class="form-control">
                <option value="credit">Credit</option>
                <option value="cash">Cash</option>
                <option value="bank">Bank</option>
              </select>
              <small class="text-muted" id="editPayHint">No amount field for credit — full amount goes to due.</small>
            </div>
            <div class="col-md-4 mb-3" id="editBankDiv" style="display:none;">
              <label class="form-label">Bank Account</label>
              <select name="bank_account_id" id="editBankAccount" class="form-control">
                <?php foreach ($bank_accounts as $ba): ?>
                <option value="<?=$ba['id']?>"><?=htmlspecialchars($ba['account_name'])?> - <?=htmlspecialchars($ba['bank_name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <hr>
          <h6 class="mb-3 text-secondary"><i class="fas fa-box"></i> Products</h6>
          <div id="editProductRows"></div>
          <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="editAddRow"><i class="fas fa-plus"></i> Add Another Product</button>
          <div class="row">
            <div class="col-md-3">
              <label class="form-label">Discount</label>
              <input type="number" name="discount_amount" id="editDiscount" class="form-control" min="0" value="0">
            </div>
            <div class="col-md-3" id="editPaidRow" style="display:none;">
              <label class="form-label">Paid Amount</label>
              <input type="number" name="paid_amount" id="editPaid" class="form-control" min="0" value="0">
            </div>
            <div class="col-md-3">
              <label class="form-label">Total Amount</label>
              <input type="text" id="editTotal" class="form-control font-weight-bold" readonly value="0.00">
            </div>
            <div class="col-md-3">
              <label class="form-label">Due Amount</label>
              <input type="text" id="editDue" class="form-control font-weight-bold text-danger" readonly value="0.00">
            </div>
          </div>
          <div class="form-group mt-3">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" id="editNotes" class="form-control" placeholder="Optional notes">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Update Entry</button>
        </div>
      </div>
    </form>
  </div>
</div>

<style>
@media print {
  .report-table th { font-size: 11.5px !important; padding: 7px 8px !important; }
  .report-table td { font-size: 12.5px !important; padding: 6px 8px !important; }
  tfoot.report-tfoot td { font-size: 12.5px !important; padding: 7px 8px !important; }
  .rs-label { font-size: 10px !important; letter-spacing: 0.6px !important; }
  .rs-val   { font-size: 16px !important; }
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

  // customer search
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
            if (it.city) sub.push(esc(it.city));
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

  // product search
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
    $('#addAmount').val((qty * rate).toFixed(2));
    var paid = $('#addPayMethod').val() === 'credit' ? 0 : (parseFloat($('#addPaid').val()) || 0);
    var total = qty * rate;
    if (paid > total) $('#addPaid').val(total);
  }
  $('#addQty, #addRate, #addPaid').on('input', recalcAdd);

  $('#addPayMethod').change(function(){
    var v = $(this).val();
    $('#addPaidRow').toggle(v !== 'credit');
    $('#addBankDiv').toggle(v === 'bank');
    $('#addPayHint').toggle(v === 'credit');
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

  // =================== EDIT MODAL ===================
  hideList($('#editCustomerList')); hideList($('#editSalesmanList'));

  // customer search
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
            if (it.city) sub.push(esc(it.city));
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

  // salesman search
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

  // edit payment method toggle
  $('#editPayMethod').change(function(){
    var v = $(this).val();
    $('#editBankDiv').toggle(v === 'bank');
    $('#editPaidRow').toggle(v !== 'credit');
    $('#editPayHint').toggle(v === 'credit');
    if (v === 'credit') $('#editPaid').val(0);
    recalcEdit();
  });

  // build a single edit product row (in jQuery DOM for safe data binding)
  function newEditRow(item){
    var $row = $('<div class="product-row"><div class="row g-2 mb-2">' +
      '<div class="col-md-5"><div class="ac-wrap">' +
        '<input type="text" class="form-control product-search" placeholder="Type product name or code..." autocomplete="off">' +
        '<input type="hidden" name="product_id[]" class="product-id">' +
        '<div class="ac-list"></div>' +
      '</div></div>' +
      '<div class="col-md-2"><input type="number" name="quantity[]" class="form-control qty" min="0" step="0.01" placeholder="Qty"></div>' +
      '<div class="col-md-2"><input type="number" name="rate[]" class="form-control rate" min="0" step="0.01" placeholder="Rate"></div>' +
      '<div class="col-md-2"><input type="text" class="form-control subtotal" readonly value="0.00"></div>' +
      '<div class="col-md-1"><button type="button" class="btn btn-outline-danger remove-row"><i class="fas fa-times"></i></button></div>' +
    '</div></div>');

    if (item) {
      $row.find('.product-id').val(item.product_id);
      $row.find('.product-search').val(item.product_name);
      $row.find('.qty').val(item.quantity);
      $row.find('.rate').val(item.price);
      $row.find('.subtotal').val((item.quantity * item.price).toFixed(2));
    }
    return $row;
  }

  // product search within edit rows (event delegation on container)
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
  $('#editDiscount, #editPaid').on('input', recalcEdit);

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
    if (paid > net) $('#editPaid').val(net);
    $('#editDue').val(Math.max(net - paid, 0).toFixed(2));
  }

  // open edit: fetch sale details
  function openEdit(id){
    $('#edit_id').val(id);
    $.getJSON('ajax_sale_detail.php', {id: id}, function(data){
      if (data.error) {
        alert(data.error);
        $('#editModal').modal('hide');
        return;
      }
      $('#editInvoiceNo').text(data.invoice_no ? '(' + data.invoice_no + ')' : '');
      $('#editDate').val(data.sale_date);
      $('#editCustomer_id').val(data.customer_id || '');
      $('#editCustomerSearch').val(data.customer_name || '');
      $('#editSalesman_id').val(data.salesman_id || '');
      $('#editSalesmanSearch').val(data.salesman_name || '');
      $('#editNotes').val(data.notes || '');
      $('#editDiscount').val(data.discount_amount || 0);

      $('#editPayMethod').val(data.payment_method || 'credit');
      if (data.bank_account_id) $('#editBankAccount').val(data.bank_account_id);
      $('#editPaid').val(data.paid_amount || 0);
      if (data.payment_method === 'bank') { $('#editBankDiv').show(); $('#editPaidRow').show(); $('#editPayHint').hide(); }
      else if (data.payment_method === 'cash') { $('#editBankDiv').hide(); $('#editPaidRow').show(); $('#editPayHint').hide(); }
      else { $('#editBankDiv').hide(); $('#editPaidRow').hide(); $('#editPayHint').show(); }

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

  // reload modal state on close so add row states are sane
  $('#editModal').on('hidden.bs.modal', function(){
    $('#editProductRows').empty();
  });

  // =================== KEYBOARD NAV ===================
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

  // =================== LIVE SEARCH ===================
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
        $none = $('<tr id="dsrNoMatch"><td colspan="10" class="text-center text-muted py-3">No entries match your search.</td></tr>');
        $('#dsrTable tbody').append($none);
      }
    } else if ($none.length) {
      $none.remove();
    }
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>