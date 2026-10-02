<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Take Order';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

$products = $pdo->query("SELECT id, code, name, unit, boxes_per_carton, sale_price, stock_quantity FROM products WHERE status = 1 ORDER BY name")->fetchAll();
$bank_accounts = $pdo->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id")->fetchAll();
$recent_counter_customers = $pdo->query("SELECT DISTINCT full_name FROM customers WHERE LOWER(area) = 'counter' ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_COLUMN);

// ===== Areas available to the current user =====
// Admin: all registered areas (+ any customer-only areas). Order Booker: his assigned 3-8 areas.
$my_areas = currentUserAreas($pdo); // null = admin (all areas)
$all_known = allKnownAreas($pdo);

if (isAdmin()) {
    $allowed_areas = $all_known;
} else {
    $allowed_areas = $my_areas;
}

$view_area = trim($_GET['area'] ?? '');
if ($view_area !== '' && !in_array($view_area, $allowed_areas, true)) {
    $view_area = '';
}

// Salesmen covering each area (employees.area is a comma list)
$salesmen_all = $pdo->query("SELECT id, full_name, area FROM employees WHERE employee_type = 'salesman' AND status = 1 ORDER BY full_name")->fetchAll();
function salesmenForArea($salesmen_all, $area) {
    $area_l = strtolower(trim($area));
    $out = [];
    foreach ($salesmen_all as $e) {
        $parts = array_map('strtolower', array_map('trim', explode(',', $e['area'] ?? '')));
        if (in_array($area_l, $parts, true)) $out[] = $e;
    }
    return $out;
}

// Area cards (each with shop count + covering salesmen)
$area_cards = [];
foreach ($allowed_areas as $an) {
    $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE LOWER(area) = LOWER(?)");
    $cnt_stmt->execute([$an]);
    $area_cards[] = [
        'name' => $an,
        'shops' => (int)$cnt_stmt->fetchColumn(),
        'salesmen' => salesmenForArea($salesmen_all, $an),
    ];
}

$customers = [];
$page_area_salesmen = [];
if ($view_area !== '') {
    $cust_stmt = $pdo->prepare("SELECT id, full_name, phone, city, area, current_balance FROM customers WHERE LOWER(area) = LOWER(?) ORDER BY full_name");
    $cust_stmt->execute([$view_area]);
    $customers = $cust_stmt->fetchAll();
    $page_area_salesmen = salesmenForArea($salesmen_all, $view_area);
}

// ===== SAVE ORDER (CREDIT ONLY for regular shops, or COUNTER SALE with cash/bank/credit) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_counter = !empty($_POST['is_counter_sale']);

    if ($is_counter) {
        $counter_name = trim($_POST['counter_customer_name'] ?? '');
        if ($counter_name === '') $counter_name = 'Counter Customer';
        $counter_phone = trim($_POST['counter_customer_phone'] ?? '');
        if ($counter_phone === '') $counter_phone = '-';

        // Reuse or create counter customer
        $c_stmt = $pdo->prepare("SELECT id FROM customers WHERE LOWER(full_name) = LOWER(?) AND LOWER(area) = 'counter' LIMIT 1");
        $c_stmt->execute([$counter_name]);
        $customer_id = (int)$c_stmt->fetchColumn();
        if (!$customer_id) {
            $cust_no = generateCustomerNo();
            $customer_id = insert('customers', [
                'customer_no' => $cust_no,
                'full_name' => $counter_name,
                'phone' => $counter_phone,
                'address' => 'Counter / Walk-in',
                'city' => 'Counter',
                'area' => 'Counter',
                'opening_balance' => 0,
                'current_balance' => 0,
                'notes' => 'Counter Customer',
                'branch_id' => currentBranchId($pdo),
                'created_by' => $_SESSION['user_id'],
                'created_at' => date('Y-m-d'),
            ]);
        }
        $customer = getById('customers', $customer_id);
    } else {
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        if (!$customer_id) redirect('index.php', 'Select a shop (customer) first', 'error');

        $customer = getById('customers', $customer_id);
        if (!$customer) redirect('index.php', 'Customer not found', 'error');

        // Order bookers may only take orders from customers inside their assigned areas
        if (!isAdmin() && $my_areas !== null) {
            if (empty($my_areas)) redirect('index.php', 'No areas are assigned to your login. Contact the admin.', 'error');
            $cust_areas_lower = strtolower($customer['area'] ?? '');
            $in_area = false;
            foreach ($my_areas as $ma) {
                if ($cust_areas_lower === strtolower($ma)) { $in_area = true; break; }
            }
            if (!$in_area) {
                redirect('index.php', 'You can only take orders in your assigned areas', 'error');
            }
        }
    }

    $salesman_id = !empty($_POST['salesman_id']) ? (int)$_POST['salesman_id'] : null;
    $sale_date = !empty($_POST['sale_date']) ? trim($_POST['sale_date']) : date('Y-m-d');
    $delivery_date = !empty($_POST['delivery_date']) ? trim($_POST['delivery_date']) : $sale_date;
    $discount = (float)($_POST['discount_amount'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $rates = $_POST['rate'] ?? [];

    if (!count($product_ids) || !$product_ids[0]) {
        redirect('index.php', 'Add at least one product', 'error');
    }

    $total = 0;
    $items = [];
    $stock_errors = [];
    foreach ($product_ids as $i => $pid) {
        if (!$pid) continue;
        $qty = (float)($quantities[$i] ?? 0);
        $rate = (float)($rates[$i] ?? 0);
        if ($qty <= 0) continue;
        $prod = null;
        foreach ($products as $pp) if ($pp['id'] == $pid) { $prod = $pp; break; }
        if ($prod && $qty > (float)$prod['stock_quantity']) {
            $stock_errors[] = $prod['name'] . ' (only ' . (int)$prod['stock_quantity'] . ' boxes in stock)';
        }
        $subtotal = $qty * $rate;
        $total += $subtotal;
        $items[] = ['product_id' => (int)$pid, 'qty' => $qty, 'rate' => $rate, 'subtotal' => $subtotal];
    }

    if (count($stock_errors)) redirect('index.php', 'Insufficient stock: ' . implode(', ', $stock_errors), 'error');
    if (!count($items)) redirect('index.php', 'Add at least one product with quantity', 'error');

    if ($discount > $total) $discount = $total;
    $net_total = $total - $discount;

    $invoice_no = trim($_POST['invoice_no'] ?? '');
    if ($invoice_no !== '') {
        $chk = $pdo->prepare("SELECT id FROM sales WHERE invoice_no = ?");
        $chk->execute([$invoice_no]);
        if ($chk->fetch()) $invoice_no = '';
    }
    if ($invoice_no === '') $invoice_no = generateSaleNo();

    if ($is_counter) {
        $payment_method = in_array($_POST['payment_method'] ?? '', ['cash','bank','credit'], true) ? $_POST['payment_method'] : 'cash';
        $bank_account_id = ($payment_method === 'bank' && !empty($_POST['bank_account_id'])) ? (int)$_POST['bank_account_id'] : null;
        $paid_input = isset($_POST['paid_amount']) ? (float)$_POST['paid_amount'] : 0;
        if ($payment_method === 'credit') {
            $paid_amount = 0;
        } else {
            $paid_amount = max(0, min($net_total, $paid_input));
        }
        $due_amount = max(0, $net_total - $paid_amount);
        $status = ($due_amount <= 0) ? 'completed' : 'active';
    } else {
        $payment_method = 'credit';
        $bank_account_id = null;
        $paid_amount = 0;
        $due_amount = $net_total;
        $status = 'active';
    }

    $pdo->beginTransaction();
    try {
        $sale_id = insert('sales', [
            'invoice_no' => $invoice_no,
            'customer_id' => $customer_id,
            'salesman_id' => $salesman_id ? (int)$salesman_id : null,
            'sale_date' => $sale_date,
            'delivery_date' => $delivery_date,
            'total_amount' => $net_total,
            'discount_amount' => $discount,
            'initial_paid' => $paid_amount,
            'paid_amount' => $paid_amount,
            'due_amount' => $due_amount,
            'payment_method' => $payment_method,
            'bank_account_id' => $bank_account_id,
            'status' => $status,
            'notes' => $notes,
            'branch_id' => currentBranchId($pdo),
            'created_by' => $_SESSION['user_id'],
            'created_at' => date('Y-m-d'),
        ]);

        foreach ($items as $it) {
            insert('sale_items', [
                'sale_id' => $sale_id,
                'product_id' => $it['product_id'],
                'quantity' => $it['qty'],
                'price' => $it['rate'],
                'subtotal' => $it['subtotal'],
            ]);
            $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?")
                ->execute([$it['qty'], $it['product_id']]);
        }

        if ($paid_amount > 0) {
            $cust_display_name = $customer['full_name'] ?? $counter_name;
            $inflow_desc = 'Counter sale payment: ' . $cust_display_name . ' (Invoice #' . $invoice_no . ')';
            if ($payment_method === 'bank') {
                recordBankInflow($pdo, $sale_date, $paid_amount, $inflow_desc, 'sale', $sale_id, $_SESSION['user_id'], $bank_account_id);
            } else {
                recordCashInflow($pdo, $sale_date, $paid_amount, $inflow_desc, 'sale', $sale_id, $_SESSION['user_id']);
            }
        }

        updateCustomerBalance($pdo, $customer_id);

        $pdo->commit();
        if ($is_counter) {
            logActivity($pdo, 'create', 'sale', $sale_id, 'Counter sale ' . $invoice_no . ' total ' . $net_total . ' (paid ' . $paid_amount . ', due ' . $due_amount . ')');
            $msg = 'Counter sale saved: ' . $invoice_no;
            if ($paid_amount > 0) $msg .= ' · Paid: PKR ' . formatCurrency($paid_amount);
            if ($due_amount > 0) $msg .= ' · Due: PKR ' . formatCurrency($due_amount);
            redirect('invoice.php?id=' . $sale_id, $msg);
        } else {
            logActivity($pdo, 'create', 'sale', $sale_id, 'Took order ' . $invoice_no . ' total ' . $net_total . ' (credit)');
            redirect('invoice.php?id=' . $sale_id, 'Order saved: ' . $invoice_no . ' (credit — collect at delivery), Stock updated.');
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('index.php', 'Error saving sale: ' . $e->getMessage(), 'error');
    }
}

$next_invoice_no = generateSaleNo();

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6 class="mb-0"><i class="fas fa-clipboard-check"></i> Take Order
      <?php if ($view_area): ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary ml-2"><i class="fas fa-undo"></i> All Areas</a>
      <?php endif; ?>
    </h6>
    <div class="d-flex flex-wrap">
      <button type="button" class="btn btn-sm btn-warning font-weight-bold mr-2" id="btnOpenCounterSale"><i class="fas fa-cash-register mr-1"></i> Counter Sale</button>
      <?php if (isAdmin() || isSalesTeam()): ?>
      <a href="invoices.php" class="btn btn-sm btn-outline-primary mr-2"><i class="fas fa-file-invoice"></i> Invoices</a>
      <?php endif; ?>
      <?php if ($view_area): ?>
      <a href="../customers/customers.php?add=1<?= '&area=' . urlencode($view_area) ?>&return_to=take_order" class="btn btn-sm btn-outline-success"><i class="fas fa-user-plus"></i> Add Customer</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body">

    <?php if (!isAdmin() && $my_areas !== null && empty($my_areas)): ?>
      <div class="alert alert-danger mb-0">
        <i class="fas fa-exclamation-triangle"></i> No areas are assigned to your login yet.
        <strong>Area assignment:</strong> the admin must add you as an Order Booker employee and tick your 3-8 areas
        (Admin → Employees → Add/Edit Employee → Assigned Areas).
      </div>
    <?php elseif (empty($allowed_areas)): ?>
      <div class="alert alert-warning mb-0">
        <i class="fas fa-info-circle"></i> No areas are registered yet.
        <strong>Admin:</strong> add areas under <strong>Areas</strong>, then assign them to order bookers and salesmen in <strong>Employees</strong>.
      </div>
    <?php elseif (!$view_area): ?>

      <!-- STEP 1: choose your area -->
      <div class="row mb-3 align-items-center">
        <div class="col-md-5 col-sm-6 mb-2 mb-sm-0">
          <div class="input-group">
            <div class="input-group-prepend">
              <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
            </div>
            <input type="text" id="areaFilterInput" class="form-control" placeholder="Search area or salesman..." autocomplete="off">
          </div>
        </div>
        <div class="col-md-7 col-sm-6 text-sm-right text-muted small">
          <i class="fas fa-map-marker-alt text-primary mr-1"></i> <span id="areaCount"><?=count($area_cards)?></span> area<?= count($area_cards) == 1 ? '' : 's' ?> available
        </div>
      </div>

      <div class="row" id="areaCardsContainer">
        <?php foreach ($area_cards as $ac): ?>
        <div class="col-md-4 col-sm-6 mb-3 area-col" data-area="<?=htmlspecialchars(strtolower($ac['name']))?>">
          <a href="index.php?area=<?=urlencode($ac['name'])?>" class="area-card d-block h-100 text-decoration-none">
            <div class="area-card-icon"><i class="fas fa-map-marked-alt"></i></div>
            <div class="area-card-name"><?=htmlspecialchars($ac['name'])?></div>
            <div class="area-card-meta"><?=$ac['shops']?> customer<?= $ac['shops'] == 1 ? '' : 's' ?></div>
            <?php if ($ac['salesmen']): ?>
            <div class="area-card-sales">
              <?php foreach ($ac['salesmen'] as $sm): ?>
              <span class="badge badge-light border text-dark mr-1"><i class="fas fa-truck-loading"></i> <?=htmlspecialchars($sm['full_name'])?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </a>
        </div>
        <?php endforeach; ?>
      </div>

      <div id="noAreaFound" class="alert alert-light border text-center py-4 d-none">
        <i class="fas fa-search text-muted fa-2x mb-2 d-block"></i>
        <span class="text-muted">No areas match your search.</span>
      </div>

    <?php else: ?>

      <!-- STEP 2: customers in the selected area -->
      <div class="row mb-3 align-items-center">
        <div class="col-md-7 col-sm-12 mb-2 mb-md-0">
          <span class="badge badge-primary badge-lg"><i class="fas fa-map-marker-alt"></i> <?=htmlspecialchars($view_area)?></span>
          <span class="badge badge-light border text-dark ml-1"><span id="customerVisibleCount"><?=count($customers)?></span> customer<?= count($customers) == 1 ? '' : 's' ?></span>
          <?php foreach ($page_area_salesmen as $sm): ?>
          <span class="badge badge-light border text-dark ml-1"><i class="fas fa-truck-loading"></i> Salesman: <?=htmlspecialchars($sm['full_name'])?></span>
          <?php endforeach; ?>
        </div>
        <div class="col-md-5 col-sm-12 text-md-right">
          <?php if (!empty($customers)): ?>
          <div class="input-group input-group-sm d-inline-flex" style="max-width:320px;">
            <div class="input-group-prepend">
              <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
            </div>
            <input type="text" id="shopSearch" class="form-control d-print-none" placeholder="Search customer / phone..." autocomplete="off" autocorrect="off" spellcheck="false">
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-bordered table-hover" id="shopTable">
          <thead>
            <tr><th>#</th><th>Customer Name</th><th class="d-none d-sm-table-cell">Phone</th><th class="text-right">Balance</th><th class="text-center">Action</th></tr>
          </thead>
          <tbody>
            <?php if (empty($customers)): ?>
              <tr><td colspan="5" class="text-center text-muted py-4">No customers in this area yet.
                <a href="../customers/customers.php?add=1<?= '&area=' . urlencode($view_area) ?>&return_to=take_order" class="btn btn-sm btn-outline-success ml-2"><i class="fas fa-user-plus"></i> Add Customer</a></td></tr>
            <?php else: $i = 0; foreach ($customers as $ct): $i++; ?>
              <tr>
                <td><?=$i?></td>
                <td class="font-weight-bold"><?=htmlspecialchars($ct['full_name'])?>
                  <small class="text-muted d-block d-sm-none"><?=htmlspecialchars($ct['phone'] ?? '')?></small>
                </td>
                <td class="d-none d-sm-table-cell"><?=htmlspecialchars($ct['phone'] ?? '-')?></td>
                <td class="text-right <?= (float)$ct['current_balance'] > 0 ? 'text-danger font-weight-bold' : 'text-muted' ?>">
                  PKR <?=formatCurrency($ct['current_balance'])?>
                </td>
                <td class="text-center">
                  <button type="button" class="btn btn-sm btn-success btn-take-order"
                    data-cust-id="<?=$ct['id']?>"
                    data-cust-name="<?=htmlspecialchars($ct['full_name'])?>"
                    data-cust-phone="<?=htmlspecialchars($ct['phone'] ?? '')?>"
                    data-cust-balance="<?=(float)$ct['current_balance']?>">
                    <i class="fas fa-plus-circle"></i> Order
                  </button>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

    <?php endif; ?>

  </div>
</div>

<!-- ===== TAKE ORDER MODAL (per shop) ===== -->
<div class="modal fade" id="orderModal" tabindex="-1" role="dialog" aria-labelledby="orderModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content" style="position: relative;">
      <div id="floatingProductAcList" class="ac-list" style="display:none; position:absolute; z-index:1075; box-shadow: 0 10px 25px rgba(0,0,0,0.2);"></div>
      <form method="post" id="orderForm" novalidate>
        <input type="hidden" name="customer_id" id="orderCustomerId">
        <input type="hidden" name="invoice_no" value="<?=htmlspecialchars($next_invoice_no)?>">
        <div class="modal-header">
          <h5 class="modal-title" id="orderModalLabel"><i class="fas fa-clipboard-check text-success"></i> New Order</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info py-2 mb-3">
            <div class="font-weight-bold" id="orderCustName">-</div>
            <small class="text-muted" id="orderCustMeta"></small>
          </div>

          <div class="row">
            <div class="col-md-3 col-sm-6 mb-3">
              <label class="form-label font-weight-bold small">Invoice No (Auto)</label>
              <input type="text" class="form-control font-weight-bold text-success bg-light" value="<?=htmlspecialchars($next_invoice_no)?>" readonly>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
              <label class="form-label font-weight-bold small">Order Date *</label>
              <input type="date" name="sale_date" class="form-control bg-light" value="<?=date('Y-m-d')?>" required readonly>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
              <label class="form-label font-weight-bold small text-primary"><i class="fas fa-truck mr-1"></i> Delivery Date *</label>
              <input type="date" name="delivery_date" class="form-control font-weight-bold border-primary text-dark" value="<?=date('Y-m-d', strtotime('+1 day'))?>" required>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
              <label class="form-label font-weight-bold small">Delivery Man <small class="text-muted">(Optional)</small></label>
              <select name="salesman_id" class="form-control">
                <option value="">-- Select Salesman --</option>
                <?php if ($view_area): foreach ($page_area_salesmen as $sm): ?>
                <option value="<?=$sm['id']?>"><?=htmlspecialchars($sm['full_name'])?></option>
                <?php endforeach; endif; ?>
              </select>
            </div>
          </div>

          <h6 class="mb-2 text-secondary"><i class="fas fa-box"></i> Products <small class="text-muted">(enter rate manually — per box)</small></h6>
          <div id="productRows">
            <div class="product-row">
              <div class="row g-2">
                <div class="col-md-5">
                  <label class="form-label">Product</label>
                  <div class="ac-wrap">
                    <input type="text" class="form-control product-search" placeholder="Type product name or code..." autocomplete="off">
                    <input type="hidden" name="product_id[]" class="product-id">
                    <div class="ac-list"></div>
                  </div>
                </div>
                <div class="col-md-2">
                  <label class="form-label">Qty (Boxes)</label>
                  <input type="number" name="quantity[]" class="form-control qty" min="0" placeholder="0">
                </div>
                <div class="col-md-2">
                  <label class="form-label">Rate</label>
                  <input type="number" name="rate[]" class="form-control rate" step="0.01" min="0" placeholder="0.00">
                </div>
                <div class="col-md-2">
                  <label class="form-label">Subtotal</label>
                  <input type="text" class="form-control subtotal" readonly value="0.00">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                  <button type="button" class="btn btn-outline-danger remove-row"><i class="fas fa-times"></i></button>
                </div>
              </div>
            </div>
          </div>

          <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="addRow"><i class="fas fa-plus"></i> Add Another Product</button>

          <div class="row">
            <div class="col-md-4">
              <label class="form-label">Total Amount</label>
              <input type="text" class="form-control font-weight-bold" id="totalAmount" value="0.00" readonly>
            </div>
            <div class="col-md-4">
              <label class="form-label">Discount</label>
              <input type="number" name="discount_amount" id="discountAmount" class="form-control" min="0" placeholder="0">
            </div>
            <div class="col-md-4">
              <label class="form-label">Due (Credit)</label>
              <input type="text" class="form-control font-weight-bold text-danger" id="dueAmount" value="0.00" readonly>
            </div>
          </div>
          <small class="text-muted d-block mt-1">Payment method is always CREDIT — the amount is collected by the salesman at delivery.</small>

          <div class="row mt-3">
            <div class="col-md-12">
              <label class="form-label">Notes</label>
              <input type="text" name="notes" class="form-control" placeholder="Optional notes (e.g. shop timing, employee name)">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Save Order</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ===== COUNTER SALE MODAL (Walk-in Customer) ===== -->
<div class="modal fade" id="counterSaleModal" tabindex="-1" role="dialog" aria-labelledby="counterSaleModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content" style="position: relative;">
      <div id="floatingCounterProductAcList" class="ac-list" style="display:none; position:absolute; z-index:1075; box-shadow: 0 10px 25px rgba(0,0,0,0.2);"></div>
      <form method="post" id="counterSaleForm" novalidate>
        <input type="hidden" name="is_counter_sale" value="1">
        <input type="hidden" name="invoice_no" value="<?=htmlspecialchars($next_invoice_no)?>">
        <div class="modal-header bg-light border-bottom">
          <h5 class="modal-title font-weight-bold text-dark" id="counterSaleModalLabel">
            <i class="fas fa-cash-register text-warning mr-2"></i> Counter Sale / Walk-in Customer
          </h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-5 col-sm-12 mb-3">
              <label class="form-label font-weight-bold small">Customer Name *</label>
              <input type="text" name="counter_customer_name" id="counterCustomerName" class="form-control font-weight-bold" list="counterCustomerList" placeholder="e.g. Walk-in Customer / Ali Khan" autocomplete="off" required>
              <datalist id="counterCustomerList">
                <?php foreach ($recent_counter_customers as $rcc): ?>
                <option value="<?=htmlspecialchars($rcc)?>"></option>
                <?php endforeach; ?>
              </datalist>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
              <label class="form-label font-weight-bold small">Customer Phone </label>
              <input type="text" name="counter_customer_phone" id="counterCustomerPhone" class="form-control" placeholder="0300-1234567">
            </div>
            <div class="col-md-2 col-sm-6 mb-3">
              <label class="form-label font-weight-bold small">Sale Date *</label>
              <input type="date" name="sale_date" class="form-control" value="<?=date('Y-m-d')?>" required>
            </div>
            <div class="col-md-2 col-sm-6 mb-3">
              <label class="form-label font-weight-bold small">Invoice #</label>
              <input type="text" class="form-control font-weight-bold text-success bg-light" value="<?=htmlspecialchars($next_invoice_no)?>" readonly>
            </div>
          </div>

          <div class="row mb-2">
            <div class="col-md-6 mb-2">
              <label class="form-label font-weight-bold small">Salesman / Delivery Man <small class="text-muted">(Optional)</small></label>
              <select name="salesman_id" class="form-control">
                <option value="">-- Direct Counter Sale (No Salesman) --</option>
                <?php foreach ($salesmen_all as $sm): ?>
                <option value="<?=$sm['id']?>"><?=htmlspecialchars($sm['full_name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6 mb-2">
              <label class="form-label font-weight-bold small">Delivery Date <small class="text-muted">(Optional)</small></label>
              <input type="date" name="delivery_date" class="form-control" value="<?=date('Y-m-d')?>">
            </div>
          </div>

          <h6 class="mb-2 text-secondary"><i class="fas fa-box"></i> Products <small class="text-muted">(enter rate manually — per box)</small></h6>
          <div id="counterProductRows" style="max-height: 280px; overflow-y: auto; overflow-x: hidden; padding-right: 6px; margin-bottom: 0.5rem;">
            <div class="counter-product-row mb-2">
              <div class="row g-2">
                <div class="col-md-5">
                  <label class="form-label small font-weight-bold">Product</label>
                  <div class="ac-wrap">
                    <input type="text" class="form-control counter-product-search" placeholder="Type product name or code..." autocomplete="off">
                    <input type="hidden" name="product_id[]" class="counter-product-id">
                    <div class="ac-list"></div>
                  </div>
                </div>
                <div class="col-md-2">
                  <label class="form-label small font-weight-bold">Qty (Boxes)</label>
                  <input type="number" name="quantity[]" class="form-control counter-qty" min="0" placeholder="0">
                </div>
                <div class="col-md-2">
                  <label class="form-label small font-weight-bold">Rate</label>
                  <input type="number" name="rate[]" class="form-control counter-rate" step="0.01" min="0" placeholder="0.00">
                </div>
                <div class="col-md-2">
                  <label class="form-label small font-weight-bold">Subtotal</label>
                  <input type="text" class="form-control counter-subtotal" readonly value="0.00">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                  <button type="button" class="btn btn-outline-danger remove-counter-row"><i class="fas fa-times"></i></button>
                </div>
              </div>
            </div>
          </div>

          <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="addCounterRow"><i class="fas fa-plus"></i> Add Another Product</button>

          <!-- Totals and Payment section -->
          <div class="card bg-light border p-3 mb-3">
            <div class="row">
              <div class="col-md-4 mb-2">
                <label class="form-label font-weight-bold small">Gross Total</label>
                <input type="text" class="form-control font-weight-bold bg-white" id="counterGrossTotal" value="0.00" readonly>
              </div>
              <div class="col-md-4 mb-2">
                <label class="form-label font-weight-bold small">Discount</label>
                <input type="number" name="discount_amount" id="counterDiscountAmount" class="form-control bg-white" min="0" placeholder="0">
              </div>
              <div class="col-md-4 mb-2">
                <label class="form-label font-weight-bold small text-primary">Net Total</label>
                <input type="text" class="form-control font-weight-heavy text-primary bg-white" id="counterNetTotal" value="0.00" readonly>
              </div>
            </div>

            <hr class="my-2">

            <div class="row">
              <div class="col-md-4 mb-2">
                <label class="form-label font-weight-bold small">Payment Method</label>
                <select name="payment_method" id="counterPaymentMethod" class="form-control font-weight-bold">
                  <option value="cash" selected>Cash</option>
                  <option value="bank">Bank</option>
                  <option value="credit">Credit (Full Due)</option>
                </select>
              </div>
              <div class="col-md-4 mb-2">
                <label class="form-label font-weight-bold small text-success">Paid Amount</label>
                <input type="number" step="0.01" name="paid_amount" id="counterPaidAmount" class="form-control font-weight-bold text-success bg-white" min="0" placeholder="0.00" value="0.00">
              </div>
              <div class="col-md-4 mb-2">
                <label class="form-label font-weight-bold small text-danger">Remaining Due</label>
                <input type="text" id="counterDueAmount" class="form-control font-weight-bold text-danger bg-white" readonly value="0.00">
              </div>
            </div>

            <div class="row" id="counterBankGroup" style="display:none;">
              <div class="col-md-12 mb-2">
                <label class="form-label font-weight-bold small text-primary"><i class="fas fa-university mr-1"></i> Bank Account *</label>
                <select name="bank_account_id" id="counterBankSelect" class="form-control font-weight-bold border-primary">
                  <?php foreach ($bank_accounts as $ba): ?>
                  <option value="<?=$ba['id']?>"><?=htmlspecialchars($ba['account_name'] . ' (' . $ba['bank_name'] . ')')?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>

          <div class="row">
            <div class="col-12">
              <label class="form-label small font-weight-bold">Notes</label>
              <input type="text" name="notes" id="counterNotes" class="form-control" placeholder="Optional notes for counter invoice">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success font-weight-bold"><i class="fas fa-check-circle mr-1"></i> Save Counter Sale</button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
.area-card { border: 1px solid #dbe1ea; border-radius: 10px; padding: 18px 16px; transition: all .15s ease; background: #fff; }
.area-card:hover { border-color: var(--primary); box-shadow: 0 4px 14px rgba(13,110,253,.12); transform: translateY(-2px); }
.area-card-icon { width: 44px; height: 44px; border-radius: 12px; background: rgba(13,110,253,.1); color: var(--primary); display: flex; align-items: center; justify-content: center; margin-bottom: 10px; }
.area-card-name { font-weight: 700; font-size: 1.05rem; color: #0f172a; }
.area-card-meta { color: #64748b; font-size: .85rem; margin-bottom: 6px; }
.badge-lg { font-size: 1rem; padding: .5em .8em; }
#productRows {
  max-height: 380px;
  overflow-y: auto;
  overflow-x: hidden;
  padding-right: 6px;
  margin-bottom: 0.5rem;
}
#productRows::-webkit-scrollbar { width: 6px; }
#productRows::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
</style>

<script>
$(document).ready(function(){

  // ===== TAKE ORDER MODAL OPEN =====
  $(document).on('click', '.btn-take-order', function(){
    var $b = $(this);
    $('#orderCustomerId').val($b.data('cust-id'));
    $('#orderCustName').text($b.data('cust-name'));
    var meta = [];
    if ($b.data('cust-phone')) meta.push('Phone: ' + $b.data('cust-phone'));
    var bal = Number($b.data('cust-balance'));
    meta.push(bal > 0 ? 'Balance due: PKR ' + bal.toLocaleString(undefined, {minimumFractionDigits:2}) : 'Balance settled');
    $('#orderCustMeta').text(meta.join(' · '));
    // reset products to a single clean row
    $('#productRows .product-row').slice(1).remove();
    var first = $('#productRows .product-row').first();
    first.find('.product-search').val(''); first.find('.product-id').val('');
    first.find('.qty,.rate').val(''); first.find('.subtotal').val('0.00');
    first.find('.stock-warning').remove();
    $('#discountAmount').val(''); $('#totalAmount').val('0.00'); $('#dueAmount').val('0.00');
    $('#floatingProductAcList').empty().hide();
    $('#productRows')[0].scrollTop = 0;
    $('#orderModal').modal('show');
  });

  <?php if ($view_area): ?>
  // Pre-select first covering salesman for convenience
  var areaSalesmen = <?=json_encode(array_map(fn($sm) => ['id' => (int)$sm['id'], 'name' => $sm['full_name']], $page_area_salesmen))?>;
  if (areaSalesmen.length === 1) {
    $('select[name="salesman_id"]').val(areaSalesmen[0].id);
  }
  <?php endif; ?>

  // ===== CALC =====
  function recalc(){
    var total = 0;
    $('#productRows .product-row').each(function(){
      var rate = parseFloat($(this).find('.rate').val()) || 0;
      var qty = parseFloat($(this).find('.qty').val()) || 0;
      var sub = rate * qty;
      $(this).find('.subtotal').val(sub.toFixed(2));
      total += sub;
    });
    $('#totalAmount').val(total.toFixed(2));
    var disc = parseFloat($('#discountAmount').val()) || 0;
    var net = Math.max(total - disc, 0);
    $('#dueAmount').val(net.toFixed(2));
  }

  $('#productRows').on('input', '.qty, .rate', recalc);
  $('#discountAmount').on('input', recalc);

  $('#addRow').click(function(){
    var first = $('#productRows .product-row').first().clone();
    first.find('.product-search').val('');
    first.find('.product-id').val('');
    first.find('.qty, .rate').val('');
    first.find('.subtotal').val('0.00');
    first.find('.stock-warning').remove();
    $('#productRows').append(first);
    $('#productRows').animate({scrollTop: $('#productRows')[0].scrollHeight}, 200);
    recalc();
    first.find('.product-search').focus();
  });

  $('#productRows').on('click', '.remove-row', function(){
    if ($('#productRows .product-row').length > 1) {
      $(this).closest('.product-row').remove();
      $('#floatingProductAcList').empty().hide();
      recalc();
    } else {
      alert('At least one product row is required.');
    }
  });

  // ===== FLOATING PRODUCT AUTOCOMPLETE =====
  function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }
  var $activeProductInput = null;
  var $floatingList = $('#floatingProductAcList');

  function positionFloatingList($input) {
    if (!$input || !$input.length || !$input.is(':visible')) {
      $floatingList.empty().hide();
      return;
    }
    var $modalContent = $('#orderModal .modal-content');
    var inOff = $input.offset();
    var moOff = $modalContent.offset();
    var top = (inOff.top - moOff.top) + $input.outerHeight();
    var left = (inOff.left - moOff.left);
    var width = $input.outerWidth();
    $floatingList.css({
      top: top + 'px',
      left: left + 'px',
      width: width + 'px',
      display: 'block'
    });
  }

  $('#productRows').on('input', '.product-search', function(){
    $activeProductInput = $(this);
    var $row = $activeProductInput.closest('.product-row');
    var q = $.trim(this.value);
    clearTimeout($(this).data('timer'));
    if (!q) {
      $row.find('.product-id').val('');
      $floatingList.empty().hide();
      return;
    }
    var $inp = $(this);
    $inp.data('timer', setTimeout(function(){
      $.get('ajax_product_search.php', {q: q}, function(data){
        $floatingList.empty();
        if (!data || !data.length) {
          $floatingList.append('<div class="ac-item ac-empty">No matching product found</div>');
        } else {
          $.each(data, function(i, it){
            var bpc = parseInt(it.boxes_per_carton) || 1;
            if (bpc < 1) bpc = 1;
            var stock = parseInt(it.stock_quantity) || 0;
            var ctns = Math.floor(stock / bpc);
            var remBoxes = stock % bpc;
            var stockText = stock + ' Boxes';
            if (bpc > 1) {
              stockText += ' (' + ctns + ' Carton' + (ctns === 1 ? '' : 's') + (remBoxes > 0 ? ' + ' + remBoxes + ' Box' + (remBoxes === 1 ? '' : 'es') : '') + ')';
            }
            var meta = [];
            if (it.code) meta.push('Code: ' + esc(it.code));
            if (bpc > 1) meta.push('1 Carton = ' + bpc + ' Boxes');
            meta.push('<span class="text-info font-weight-bold"><i class="fas fa-boxes"></i> Stock: ' + stockText + '</span>');
            $floatingList.append('<div class="ac-item" data-id="' + it.id + '" data-sale="' + it.sale_price + '" data-bpc="' + bpc + '" data-stock="' + stock + '">' +
              '<span class="ac-name">' + esc(it.name) + '</span>' +
              '<small class="ac-sub">' + meta.join(' &middot; ') + '</small>' +
              '</div>');
          });
        }
        positionFloatingList($inp);
      });
    }, 250));
  });

  // Reposition floating dropdown on scroll of product rows
  $('#productRows').on('scroll', function(){
    if ($floatingList.is(':visible') && $activeProductInput) {
      var rowsTop = $('#productRows').offset().top;
      var rowsBottom = rowsTop + $('#productRows').outerHeight();
      var inputTop = $activeProductInput.offset().top;
      if (inputTop < rowsTop - 30 || inputTop > rowsBottom) {
        $floatingList.hide();
      } else {
        positionFloatingList($activeProductInput);
      }
    }
  });

  // Pick product from floating dropdown
  $floatingList.on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    if (!$activeProductInput || !$activeProductInput.length) return;
    var $row = $activeProductInput.closest('.product-row');
    var bpc = parseInt($(this).data('bpc')) || 1;
    if (bpc < 1) bpc = 1;
    var stock = parseInt($(this).data('stock')) || 0;
    $row.find('.product-id').val($(this).data('id'));
    $activeProductInput.val($(this).find('.ac-name').text());
    // Do not auto-fill rate: client explicitly enters rate manually
    $row.find('.rate').val('');
    $row.data('stock', stock);
    $floatingList.empty().hide();
    recalc();
    checkStock($row);
    // Focus on Qty so user can proceed directly
    $row.find('.qty').focus();
  });

  function checkStock($row){
    var stock = $row.data('stock') || 0;
    var qty = parseFloat($row.find('.qty').val()) || 0;
    var $warn = $row.find('.stock-warning');
    if (qty > 0 && stock > 0 && qty > stock) {
      if (!$warn.length) {
        $warn = $('<small class="text-danger font-weight-bold stock-warning"><i class="fas fa-exclamation-triangle"></i> Only ' + stock + ' boxes in stock!</small>');
        $row.find('.qty').after($warn);
      } else {
        $warn.html('<i class="fas fa-exclamation-triangle"></i> Only ' + stock + ' boxes in stock!');
      }
    } else {
      $warn.remove();
    }
  }

  $('#productRows').on('input', '.qty', function(){
    checkStock($(this).closest('.product-row'));
  });

  // Keyboard navigation on active product input
  $(document).on('keydown', '.product-search', function(e){
    if (!$floatingList.is(':visible')) return;
    var items = $floatingList.find('.ac-item:not(.ac-empty)');
    if (!items.length) return;
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
      $floatingList.empty().hide();
    }
  });

  $floatingList.on('mouseover', '.ac-item', function(){
    $(this).addClass('active').siblings().removeClass('active');
  });

  $(document).on('mousedown', function(e){
    if (!$(e.target).closest('#floatingProductAcList, .product-search').length) {
      $floatingList.empty().hide();
    }
  });

  // ===== SUBMIT GUARDS =====
  $('#orderForm').on('submit', function(e){
    if (!$('#orderCustomerId').val()) {
      e.preventDefault();
      alert('Please select a shop first.');
      return;
    }
    var filled = false;
    var hasStockError = false;
    $('#productRows .product-row').each(function(){
      if ($(this).find('.product-id').val()) {
        filled = true;
        var stock = $(this).data('stock') || 0;
        var qty = parseFloat($(this).find('.qty').val()) || 0;
        if (stock > 0 && qty > stock) {
          hasStockError = true;
          var name = $(this).find('.product-search').val() || 'Product';
          alert(name + ': Only ' + stock + ' in stock! Cannot order ' + qty + '.');
        }
      }
    });
    if (!filled) {
      e.preventDefault();
      alert('Please add at least one product.');
      return;
    }
    if (hasStockError) {
      e.preventDefault();
    }
  });

  // ===== AREA CARDS SEARCH =====
  $('#areaFilterInput').on('keyup input', function(){
    var q = $(this).val().toLowerCase().trim();
    var visible = 0;
    $('.area-col').each(function(){
      var text = $(this).text().toLowerCase();
      if (q === '' || text.indexOf(q) > -1) {
        $(this).show();
        visible++;
      } else {
        $(this).hide();
      }
    });
    $('#areaCount').text(visible);
    if (visible === 0) {
      $('#noAreaFound').removeClass('d-none');
    } else {
      $('#noAreaFound').addClass('d-none');
    }
  });

  // ===== SHOP SEARCH =====
  $('#shopSearch').on('keyup input', function(){
    var q = $(this).val().toLowerCase().trim();
    var visible = 0;
    $('#shopTable tbody tr:not(#noShopFoundRow)').each(function(){
      var text = $(this).text().toLowerCase();
      var show = (q === '' || text.indexOf(q) > -1);
      $(this).toggle(show);
      if (show) visible++;
    });
    $('#customerVisibleCount').text(visible);
    if (visible === 0 && q !== '') {
      if (!$('#noShopFoundRow').length) {
        $('#shopTable tbody').append('<tr id="noShopFoundRow"><td colspan="5" class="text-center text-muted py-4"><i class="fas fa-search fa-2x mb-2 d-block text-muted"></i>No customer matches "<b>' + esc(q) + '</b>"</td></tr>');
      } else {
        $('#noShopFoundRow td').html('<i class="fas fa-search fa-2x mb-2 d-block text-muted"></i>No customer matches "<b>' + esc(q) + '</b>"');
        $('#noShopFoundRow').show();
      }
    } else {
      $('#noShopFoundRow').remove();
    }
  });

  // ===== COUNTER SALE MODAL =====
  var $counterModal = $('#counterSaleModal');
  var $counterFloatingList = $('#floatingCounterProductAcList');
  var $activeCounterProductInput = null;

  $(document).on('click', '#btnOpenCounterSale', function(){
    $('#counterCustomerName').val('');
    $('#counterCustomerPhone').val('');
    $('#counterNotes').val('');
    $('#counterDiscountAmount').val('');
    $('#counterPaymentMethod').val('cash');
    $('#counterBankGroup').hide();
    
    // reset product rows to single empty row
    $('#counterProductRows .counter-product-row').slice(1).remove();
    var first = $('#counterProductRows .counter-product-row').first();
    first.find('.counter-product-search').val('');
    first.find('.counter-product-id').val('');
    first.find('.counter-qty, .counter-rate').val('');
    first.find('.counter-subtotal').val('0.00');
    first.find('.stock-warning').remove();
    first.removeData('stock');

    $('#counterGrossTotal').val('0.00');
    $('#counterNetTotal').val('0.00');
    $('#counterPaidAmount').val('0.00').removeData('user-edited');
    $('#counterDueAmount').val('0.00');
    $counterFloatingList.empty().hide();

    $counterModal.modal('show');
    setTimeout(function(){ $('#counterCustomerName').focus(); }, 400);
  });

  function recalcCounter(mode){
    var gross = 0;
    $('#counterProductRows .counter-product-row').each(function(){
      var qty = parseFloat($(this).find('.counter-qty').val()) || 0;
      var rate = parseFloat($(this).find('.counter-rate').val()) || 0;
      var sub = qty * rate;
      $(this).find('.counter-subtotal').val(sub.toFixed(2));
      gross += sub;
    });
    $('#counterGrossTotal').val(gross.toFixed(2));

    var disc = parseFloat($('#counterDiscountAmount').val()) || 0;
    var net = Math.max(gross - disc, 0);
    $('#counterNetTotal').val(net.toFixed(2));

    var pmethod = $('#counterPaymentMethod').val();
    var paidInp = $('#counterPaidAmount');
    var paid = parseFloat(paidInp.val()) || 0;

    if (mode === 'full') {
      paid = net;
      paidInp.val(paid.toFixed(2));
    } else if (mode === 'half') {
      paid = Math.round((net / 2) * 100) / 100;
      paidInp.val(paid.toFixed(2));
    } else if (mode === 'zero') {
      paid = 0;
      paidInp.val('0.00');
    } else if (pmethod === 'credit') {
      paid = 0;
      paidInp.val('0.00');
    } else if (!paidInp.data('user-edited') || mode === 'recalc-default') {
      paid = net;
      paidInp.val(paid.toFixed(2));
    }

    var due = Math.max(net - paid, 0);
    $('#counterDueAmount').val(due.toFixed(2));
  }

  $('#counterProductRows').on('input', '.counter-qty, .counter-rate', function(){
    recalcCounter('recalc-default');
  });
  $('#counterDiscountAmount').on('input', function(){
    recalcCounter('recalc-default');
  });

  $('#counterPaidAmount').on('input change', function(){
    $(this).data('user-edited', true);
    var net = parseFloat($('#counterNetTotal').val()) || 0;
    var paid = parseFloat($(this).val()) || 0;
    var due = Math.max(net - paid, 0);
    $('#counterDueAmount').val(due.toFixed(2));
  });

  $('#counterPaymentMethod').on('change', function(){
    var val = $(this).val();
    if (val === 'bank') {
      $('#counterBankGroup').show();
      $('#counterPaidAmount').removeData('user-edited');
      recalcCounter('full');
    } else if (val === 'credit') {
      $('#counterBankGroup').hide();
      recalcCounter('zero');
    } else {
      $('#counterBankGroup').hide();
      $('#counterPaidAmount').removeData('user-edited');
      recalcCounter('full');
    }
  });

  $('#addCounterRow').on('click', function(){
    var first = $('#counterProductRows .counter-product-row').first().clone();
    first.find('.counter-product-search').val('');
    first.find('.counter-product-id').val('');
    first.find('.counter-qty, .counter-rate').val('');
    first.find('.counter-subtotal').val('0.00');
    first.find('.stock-warning').remove();
    first.removeData('stock');
    $('#counterProductRows').append(first);
    $('#counterProductRows').animate({scrollTop: $('#counterProductRows')[0].scrollHeight}, 200);
    recalcCounter();
    first.find('.counter-product-search').focus();
  });

  $('#counterProductRows').on('click', '.remove-counter-row', function(){
    if ($('#counterProductRows .counter-product-row').length > 1) {
      $(this).closest('.counter-product-row').remove();
      $counterFloatingList.empty().hide();
      recalcCounter('recalc-default');
    } else {
      alert('At least one product row is required.');
    }
  });

  function positionCounterFloatingList($input) {
    if (!$input || !$input.length || !$input.is(':visible')) {
      $counterFloatingList.empty().hide();
      return;
    }
    var $modalContent = $('#counterSaleModal .modal-content');
    var inOff = $input.offset();
    var moOff = $modalContent.offset();
    var top = (inOff.top - moOff.top) + $input.outerHeight();
    var left = (inOff.left - moOff.left);
    var width = $input.outerWidth();
    $counterFloatingList.css({
      top: top + 'px',
      left: left + 'px',
      width: width + 'px',
      display: 'block'
    });
  }

  $('#counterProductRows').on('input', '.counter-product-search', function(){
    $activeCounterProductInput = $(this);
    var $row = $activeCounterProductInput.closest('.counter-product-row');
    var q = $.trim(this.value);
    clearTimeout($(this).data('timer'));
    if (!q) {
      $row.find('.counter-product-id').val('');
      $counterFloatingList.empty().hide();
      return;
    }
    var $inp = $(this);
    $inp.data('timer', setTimeout(function(){
      $.get('ajax_product_search.php', {q: q}, function(data){
        $counterFloatingList.empty();
        if (!data || !data.length) {
          $counterFloatingList.append('<div class="ac-item ac-empty">No matching product found</div>');
        } else {
          $.each(data, function(i, it){
            var bpc = parseInt(it.boxes_per_carton) || 1;
            if (bpc < 1) bpc = 1;
            var stock = parseInt(it.stock_quantity) || 0;
            var ctns = Math.floor(stock / bpc);
            var remBoxes = stock % bpc;
            var stockText = stock + ' Boxes';
            if (bpc > 1) {
              stockText += ' (' + ctns + ' Carton' + (ctns === 1 ? '' : 's') + (remBoxes > 0 ? ' + ' + remBoxes + ' Box' + (remBoxes === 1 ? '' : 'es') : '') + ')';
            }
            var meta = [];
            if (it.code) meta.push('Code: ' + esc(it.code));
            if (bpc > 1) meta.push('1 Carton = ' + bpc + ' Boxes');
            meta.push('<span class="text-info font-weight-bold"><i class="fas fa-boxes"></i> Stock: ' + stockText + '</span>');
            $counterFloatingList.append('<div class="ac-item" data-id="' + it.id + '" data-sale="' + it.sale_price + '" data-bpc="' + bpc + '" data-stock="' + stock + '">' +
              '<span class="ac-name">' + esc(it.name) + '</span>' +
              '<small class="ac-sub">' + meta.join(' &middot; ') + '</small>' +
              '</div>');
          });
        }
        positionCounterFloatingList($inp);
      });
    }, 250));
  });

  $('#counterProductRows').on('scroll', function(){
    if ($counterFloatingList.is(':visible') && $activeCounterProductInput) {
      var rowsTop = $('#counterProductRows').offset().top;
      var rowsBottom = rowsTop + $('#counterProductRows').outerHeight();
      var inputTop = $activeCounterProductInput.offset().top;
      if (inputTop < rowsTop - 30 || inputTop > rowsBottom) {
        $counterFloatingList.hide();
      } else {
        positionCounterFloatingList($activeCounterProductInput);
      }
    }
  });

  $counterFloatingList.on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    if (!$activeCounterProductInput || !$activeCounterProductInput.length) return;
    var $row = $activeCounterProductInput.closest('.counter-product-row');
    var stock = parseInt($(this).data('stock')) || 0;
    $row.find('.counter-product-id').val($(this).data('id'));
    $activeCounterProductInput.val($(this).find('.ac-name').text());
    $row.find('.counter-rate').val('');
    $row.data('stock', stock);
    $counterFloatingList.empty().hide();
    recalcCounter('recalc-default');
    checkCounterStock($row);
    $row.find('.counter-qty').focus();
  });

  function checkCounterStock($row){
    var stock = $row.data('stock') || 0;
    var qty = parseFloat($row.find('.counter-qty').val()) || 0;
    var $warn = $row.find('.stock-warning');
    if (qty > 0 && stock > 0 && qty > stock) {
      if (!$warn.length) {
        $warn = $('<small class="text-danger font-weight-bold stock-warning"><i class="fas fa-exclamation-triangle"></i> Only ' + stock + ' boxes in stock!</small>');
        $row.find('.counter-qty').after($warn);
      } else {
        $warn.html('<i class="fas fa-exclamation-triangle"></i> Only ' + stock + ' boxes in stock!');
      }
    } else {
      $warn.remove();
    }
  }

  $('#counterProductRows').on('input', '.counter-qty', function(){
    checkCounterStock($(this).closest('.counter-product-row'));
  });

  $(document).on('mousedown', function(e){
    if (!$(e.target).closest('#floatingCounterProductAcList, .counter-product-search').length) {
      $counterFloatingList.empty().hide();
    }
  });

  $('#counterSaleForm').on('submit', function(e){
    var cname = $.trim($('#counterCustomerName').val());
    if (!cname) {
      e.preventDefault();
      alert('Please enter a Customer Name.');
      $('#counterCustomerName').focus();
      return;
    }
    var filled = false;
    var hasStockError = false;
    $('#counterProductRows .counter-product-row').each(function(){
      if ($(this).find('.counter-product-id').val()) {
        filled = true;
        var stock = $(this).data('stock') || 0;
        var qty = parseFloat($(this).find('.counter-qty').val()) || 0;
        if (stock > 0 && qty > stock) {
          hasStockError = true;
          var name = $(this).find('.counter-product-search').val() || 'Product';
          alert(name + ': Only ' + stock + ' in stock! Cannot order ' + qty + '.');
        }
      }
    });
    if (!filled) {
      e.preventDefault();
      alert('Please add at least one product with quantity.');
      return;
    }
    if (hasStockError) {
      e.preventDefault();
    }
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>