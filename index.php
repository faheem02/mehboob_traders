<?php
require_once 'includes/functions.php';
$page_title = 'Dashboard';
require_once 'includes/auth.php';

// ===== TODAY =====
$today_purchases = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM purchases WHERE purchase_date = CURDATE() AND status <> 'cancelled'")->fetchColumn();
$today_purchase_count = $pdo->query("SELECT COUNT(*) FROM purchases WHERE purchase_date = CURDATE() AND status <> 'cancelled'")->fetchColumn();
if (isAdmin()) {
    $today_sales = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date = CURDATE() AND status <> 'cancelled'")->fetchColumn();
    $today_sale_count = $pdo->query("SELECT COUNT(*) FROM sales WHERE sale_date = CURDATE() AND status <> 'cancelled'")->fetchColumn();
} else {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $st = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date = CURDATE() AND status <> 'cancelled' AND created_by = ?");
    $st->execute([$uid]);
    $today_sales = $st->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE sale_date = CURDATE() AND status <> 'cancelled' AND created_by = ?");
    $st->execute([$uid]);
    $today_sale_count = $st->fetchColumn();
}
$today_expenses = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date = CURDATE()")->fetchColumn();
$today_cash_in = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE transaction_date = CURDATE() AND transaction_type = 'inflow'")->fetchColumn();
$today_cash_out = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE transaction_date = CURDATE() AND transaction_type = 'outflow'")->fetchColumn();

// ===== CASH / BANK =====
$cash_in_hand = $pdo->query("SELECT closing_balance FROM cash_book_daily ORDER BY date DESC LIMIT 1")->fetchColumn();
if (!$cash_in_hand) $cash_in_hand = 0;
$bank_total = $pdo->query("SELECT COALESCE(SUM(current_balance),0) FROM bank_accounts WHERE status = 1")->fetchColumn();

// ===== UDHARR / BALANCES =====
$cbal = $pdo->query("SELECT COALESCE(SUM(current_balance),0) FROM customers")->fetchColumn();
$customer_receivable = $cbal > 0 ? $cbal : 0;    // we are to receive
$customer_advance = $cbal < 0 ? abs($cbal) : 0;  // we owe customer

$sbal = $pdo->query("SELECT COALESCE(SUM(current_balance),0) FROM suppliers")->fetchColumn();
$supplier_payable = $sbal > 0 ? $sbal : 0;       // we owe supplier
$supplier_advance = $sbal < 0 ? abs($sbal) : 0;  // supplier owes us

// ===== COUNTS =====
$total_customers = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$total_suppliers = $pdo->query("SELECT COUNT(*) FROM suppliers WHERE status = 1")->fetchColumn();
$total_products = $pdo->query("SELECT COUNT(*) FROM products WHERE status = 1")->fetchColumn();
$total_employees = $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 1")->fetchColumn();

// ===== STOCK =====
$low_stock_products = $pdo->query("
    SELECT p.id, p.code, p.name, p.stock_quantity, p.min_stock_level, p.sale_price,
           c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.status = 1 AND p.min_stock_level > 0 AND p.stock_quantity <= p.min_stock_level
    ORDER BY (p.min_stock_level - p.stock_quantity) DESC
    LIMIT 8
")->fetchAll();
$low_stock_count = count($low_stock_products);
$out_of_stock = $pdo->query("SELECT COUNT(*) FROM products WHERE status = 1 AND stock_quantity <= 0")->fetchColumn();

$stock_value = $pdo->query("SELECT COALESCE(SUM(stock_quantity * purchase_price / NULLIF(boxes_per_carton, 0)),0) FROM products WHERE status = 1")->fetchColumn();

// ===== RECENT =====
$recent_purchases = $pdo->query("
    SELECT p.id, p.invoice_no, p.total_amount, p.paid_amount, p.due_amount, p.purchase_date, s.name AS supplier_name
    FROM purchases p LEFT JOIN suppliers s ON p.supplier_id = s.id
    WHERE p.status <> 'cancelled' ORDER BY p.id DESC LIMIT 5
")->fetchAll();

if (isAdmin()) {
    $recent_sales = $pdo->query("
        SELECT s.id, s.invoice_no, s.total_amount, s.paid_amount, s.due_amount, s.sale_date, c.full_name
        FROM sales s LEFT JOIN customers c ON s.customer_id = c.id
        WHERE s.status <> 'cancelled' ORDER BY s.id DESC LIMIT 5
    ")->fetchAll();
} else {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $st = $pdo->prepare("
        SELECT s.id, s.invoice_no, s.total_amount, s.paid_amount, s.due_amount, s.sale_date, c.full_name
        FROM sales s LEFT JOIN customers c ON s.customer_id = c.id
        WHERE s.status <> 'cancelled' AND s.created_by = ? ORDER BY s.id DESC LIMIT 5
    ");
    $st->execute([$uid]);
    $recent_sales = $st->fetchAll();
}

$recent_cash = $pdo->query("
    SELECT transaction_type, amount, description, transaction_date
    FROM cash_book ORDER BY id DESC LIMIT 6
")->fetchAll();

// ===== 7-DAY CHART DATA =====
$days_labels = []; $days_sales = []; $days_purchases = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $days_labels[] = date('D d', strtotime($d));
    $days_sales[] = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date = '$d' AND status <> 'cancelled'")->fetchColumn();
    $days_purchases[] = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM purchases WHERE purchase_date = '$d' AND status <> 'cancelled'")->fetchColumn();
}

// ===== EXPENSE BY CATEGORY (this month) =====
$expense_cats = $pdo->query("
    SELECT ec.name, COALESCE(SUM(e.amount),0) AS total
    FROM expense_categories ec
    LEFT JOIN expenses e ON e.category_id = ec.id AND e.expense_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
    GROUP BY ec.id, ec.name
    HAVING total > 0
    ORDER BY total DESC
")->fetchAll();

require_once 'includes/header.php';
$today_dt = date('l, d M Y');
$greeting = (int)date('H') < 12 ? 'Good Morning' : ((int)date('H') < 17 ? 'Good Afternoon' : 'Good Evening');
$user_name = $_SESSION['user_name'] ?? 'Admin';
?>

<!-- Greeting / Quick actions -->
<div class="greet-bar mb-4">
  <div class="row align-items-center">
    <div class="col-lg-7 mb-3 mb-lg-0">
      <h4 class="mb-1"><?= $greeting ?>, <?= htmlspecialchars($user_name) ?>!</h4>
      <p class="mb-0"><i class="far fa-calendar-alt"></i> <?= $today_dt ?>
        <span class="mx-2 d-none d-sm-inline">|</span>
        <span class="text-muted d-none d-sm-inline">Mehboob Traders — Wholesale</span>
      </p>
    </div>
    <div class="col-lg-5 text-lg-right">
      <?php if (isAdmin()): ?>
      <a href="<?= $base_url ?? '' ?>modules/sales/index.php" class="btn btn-light btn-sm mr-1 mb-1"><i class="fas fa-shopping-cart text-success"></i> New Sale</a>
      <a href="<?= $base_url ?? '' ?>modules/purchases/create.php" class="btn btn-light btn-sm mr-1 mb-1"><i class="fas fa-cart-arrow-down text-info"></i> Purchase</a>
      <a href="<?= $base_url ?? '' ?>modules/transactions/pay_supplier.php" class="btn btn-light btn-sm mr-1 mb-1"><i class="fas fa-money-bill-wave text-danger"></i> Pay Amount</a>
      <a href="<?= $base_url ?? '' ?>modules/transactions/receive_customer.php" class="btn btn-light btn-sm mb-1"><i class="fas fa-hand-holding-usd text-success"></i> Receive</a>
      <?php elseif (isSalesTeam()): ?>
      <a href="<?= $base_url ?? '' ?>modules/sales/index.php" class="btn btn-light btn-sm mr-1 mb-1"><i class="fas fa-shopping-cart text-success"></i> New Sale</a>
      <a href="<?= $base_url ?? '' ?>modules/sales/invoices.php" class="btn btn-light btn-sm mb-1"><i class="fas fa-file-invoice text-info"></i> Invoices</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- KPI Row 1: Money -->
<div class="row">
  <?php if (isAdmin() || isSalesTeam()): ?>
  <div class="col-xl-3 col-md-6 mb-3">
    <div class="card stat-card border-left-primary">
      <div class="card-body py-3">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="stat-label">Today's Sales</div>
            <div class="stat-value"><?= formatCurrency($today_sales) ?></div>
            <div class="stat-sub"><i class="fas fa-shopping-bag"></i> <?= (int)$today_sale_count ?> invoice(s)</div>
          </div>
          <div class="col-auto icon-circle icon-emerald"><i class="fas fa-shopping-cart"></i></div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php if (isAdmin()): ?>
  <div class="col-xl-3 col-md-6 mb-3">
    <div class="card stat-card border-left-info">
      <div class="card-body py-3">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="stat-label">Today's Purchases</div>
            <div class="stat-value"><?= formatCurrency($today_purchases) ?></div>
            <div class="stat-sub"><i class="fas fa-box"></i> <?= (int)$today_purchase_count ?> purchase(s)</div>
          </div>
          <div class="col-auto icon-circle icon-info"><i class="fas fa-cart-arrow-down"></i></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6 mb-3">
    <div class="card stat-card border-left-success">
      <div class="card-body py-3">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="stat-label">Cash in Hand</div>
            <div class="stat-value"><?= formatCurrency($cash_in_hand) ?></div>
            <div class="stat-sub">
              <span class="text-success">+<?= formatCurrency($today_cash_in) ?></span>
              <span class="mx-1 text-muted">/</span>
              <span class="text-danger">-<?= formatCurrency($today_cash_out) ?></span> today
            </div>
          </div>
          <div class="col-auto icon-circle icon-cash"><i class="fas fa-money-bill-wave"></i></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6 mb-3">
    <div class="card stat-card border-left-secondary">
      <div class="card-body py-3">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="stat-label">Bank Balance</div>
            <div class="stat-value"><?= formatCurrency($bank_total) ?></div>
            <div class="stat-sub"><i class="fas fa-university"></i> <?= count($pdo->query("SELECT id FROM bank_accounts WHERE status = 1")->fetchAll()) ?> account(s)</div>
          </div>
          <div class="col-auto icon-circle icon-bank"><i class="fas fa-piggy-bank"></i></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- KPI Row 2: Position -->
<div class="row">
  <div class="col-xl-3 col-md-6 mb-3">
    <div class="card stat-card border-left-warning">
      <div class="card-body py-3">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="stat-label">Customer Receivable</div>
            <div class="stat-value text-warning"><?= formatCurrency($customer_receivable) ?></div>
            <div class="stat-sub"><?= (int)$total_customers ?> customer(s) <a href="<?= $base_url ?? '' ?>modules/transactions/receive_customer.php" class="link-sub">Receive</a></div>
          </div>
          <div class="col-auto icon-circle icon-amber"><i class="fas fa-hand-holding-usd"></i></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6 mb-3">
    <div class="card stat-card border-left-danger">
      <div class="card-body py-3">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="stat-label">Supplier Payable</div>
            <div class="stat-value text-danger"><?= formatCurrency($supplier_payable) ?></div>
            <div class="stat-sub"><?= (int)$total_suppliers ?> supplier(s) <a href="<?= $base_url ?? '' ?>modules/transactions/pay_supplier.php" class="link-sub">Pay</a></div>
          </div>
          <div class="col-auto icon-circle icon-red"><i class="fas fa-truck-loading"></i></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6 mb-3">
    <div class="card stat-card border-left-danger">
      <div class="card-body py-3">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="stat-label">Today's Expenses</div>
            <div class="stat-value"><?= formatCurrency($today_expenses) ?></div>
            <div class="stat-sub"><i class="fas fa-receipt"></i> <a href="<?= $base_url ?? '' ?>modules/expenses/index.php" class="link-sub">View expenses</a></div>
          </div>
          <div class="col-auto icon-circle icon-rose"><i class="fas fa-file-invoice-dollar"></i></div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <div class="col-xl-3 col-md-6 mb-3">
    <div class="card stat-card border-left-warning">
      <div class="card-body py-3">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="stat-label">Low / Out of Stock</div>
            <div class="stat-value text-warning"><?= (int)$low_stock_count ?> low / <?= (int)$out_of_stock ?> out</div>
            <?php if (isAdmin()): ?>
            <div class="stat-sub"><?= formatCurrency($stock_value) ?> stock value</div>
            <?php else: ?>
            <div class="stat-sub"><?= (int)$total_products ?> total products</div>
            <?php endif; ?>
          </div>
          <div class="col-auto icon-circle icon-orange"><i class="fas fa-exclamation-triangle"></i></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Charts (Admin only) -->
<div class="row">
<?php if (isAdmin()): ?>
  <div class="col-xl-7 col-lg-6 mb-3">
    <div class="card shadow h-100">
      <div class="card-header"><h6><i class="fas fa-chart-bar text-primary"></i> Sales vs Purchases — Last 7 Days</h6></div>
      <div class="card-body"><canvas id="salesChart" height="240"></canvas></div>
    </div>
  </div>
  <div class="col-xl-5 col-lg-6 mb-3">
    <div class="card shadow h-100">
      <div class="card-header"><h6><i class="fas fa-chart-pie text-primary"></i> Expense by Category — This Month</h6></div>
      <div class="card-body">
        <div style="height:240px; display:flex; justify-content:center;">
          <canvas id="expenseChart" width="240" height="240"></canvas>
        </div>
        <div id="expenseLegend" class="mt-3"></div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Recent Activity -->
<div class="row">
  <?php if (isAdmin()): ?>
  <div class="col-lg-6 mb-3">
    <div class="card shadow h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6><i class="fas fa-cart-arrow-down text-info"></i> Recent Purchases</h6>
        <a href="<?= $base_url ?? '' ?>modules/purchases/index.php" class="btn btn-sm btn-outline-info">View All</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr><th>Invoice</th><th>Supplier</th><th class="text-right">Total</th><th class="text-right">Due</th></tr></thead>
            <tbody>
              <?php if (count($recent_purchases)): foreach ($recent_purchases as $p): ?>
                <tr>
                  <td class="font-weight-bold"><?= htmlspecialchars($p['invoice_no']) ?></td>
                  <td><?= htmlspecialchars($p['supplier_name'] ?? 'N/A') ?></td>
                  <td class="text-right"><?= formatCurrency($p['total_amount']) ?></td>
                  <td class="text-right <?= $p['due_amount'] > 0 ? 'text-danger font-weight-bold' : 'text-success' ?>"><?= formatCurrency($p['due_amount']) ?></td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="4" class="text-center text-muted py-4">No purchases yet</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php if (isAdmin() || isSalesTeam()): ?>
  <div class="col-lg-6 mb-3">
    <div class="card shadow h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6><i class="fas fa-shopping-cart text-success"></i> Recent Sales</h6>
        <a href="<?= $base_url ?? '' ?>modules/sales/invoices.php" class="btn btn-sm btn-outline-success">View All</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr><th>Invoice</th><th>Customer</th><th class="text-right">Total</th><th class="text-right">Due</th></tr></thead>
            <tbody>
              <?php if (count($recent_sales)): foreach ($recent_sales as $s): ?>
                <tr>
                  <td class="font-weight-bold"><?= htmlspecialchars($s['invoice_no']) ?></td>
                  <td><?= htmlspecialchars($s['full_name'] ?? 'N/A') ?></td>
                  <td class="text-right"><?= formatCurrency($s['total_amount']) ?></td>
                  <td class="text-right <?= $s['due_amount'] > 0 ? 'text-danger font-weight-bold' : 'text-success' ?>"><?= formatCurrency($s['due_amount']) ?></td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="4" class="text-center text-muted py-4">No sales yet</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Low stock + Cash activity -->
<div class="row">
  <div class="col-lg-7 mb-3">
    <div class="card shadow border-left-warning h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6><i class="fas fa-exclamation-triangle text-warning"></i> Low Stock Alerts</h6>
        <a href="<?= $base_url ?? '' ?>modules/inventory/products.php" class="btn btn-sm btn-outline-warning">All Products</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr><th>Product</th><th>Category</th><th class="text-center">In Stock (Boxes)</th><th class="text-center">Min Level (Boxes)</th><th class="text-center">Status</th></tr></thead>
            <tbody>
              <?php if (count($low_stock_products)): foreach ($low_stock_products as $p): ?>
                <tr>
                  <td class="font-weight-bold"><?= htmlspecialchars($p['name']) ?></td>
                  <td><?= htmlspecialchars($p['category_name'] ?? 'N/A') ?></td>
                  <td class="text-center font-weight-bold"><?= (int)$p['stock_quantity'] ?> Boxes</td>
                  <td class="text-center"><?= (int)$p['min_stock_level'] ?></td>
                  <td class="text-center">
                    <?php if ($p['stock_quantity'] <= 0): ?>
                      <span class="badge badge-danger">Out of Stock</span>
                    <?php else: ?>
                      <span class="badge badge-warning">Low</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="5" class="text-center text-success py-4"><i class="fas fa-check-circle"></i> All products are in healthy stock</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <?php if (isAdmin()): ?>
  <div class="col-lg-5 mb-3">
    <div class="card shadow h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6><i class="fas fa-money-bill-wave text-primary"></i> Recent Cash Book</h6>
        <a href="<?= $base_url ?? '' ?>modules/cashbook/index.php" class="btn btn-sm btn-outline-primary">Open</a>
      </div>
      <div class="card-body p-0">
        <ul class="activity-list list-unstyled mb-0">
          <?php if (count($recent_cash)): foreach ($recent_cash as $c): ?>
            <li class="activity-item">
              <span class="activity-dot <?= $c['transaction_type'] == 'inflow' ? 'dot-in' : 'dot-out' ?>">
                <i class="fas <?= $c['transaction_type'] == 'inflow' ? 'fa-arrow-down' : 'fa-arrow-up' ?>"></i>
              </span>
              <div class="flex-grow-1">
                <div class="activity-text"><?= htmlspecialchars($c['description']) ?></div>
                <div class="activity-date"><i class="far fa-clock"></i> <?= formatDate($c['transaction_date']) ?></div>
              </div>
              <span class="activity-amount <?= $c['transaction_type'] == 'inflow' ? 'text-success' : 'text-danger' ?>">
                <?= $c['transaction_type'] == 'inflow' ? '+' : '-' ?><?= formatCurrency(abs($c['amount'])) ?>
              </span>
            </li>
          <?php endforeach; else: ?>
            <li class="text-center text-muted py-4"><i class="far fa-list-alt"></i> No cash transactions yet</li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Quick summary chips -->
<div class="row mt-1">
  <div class="col-md-3 col-6 mb-2"><a href="<?= $base_url ?? '' ?>modules/inventory/products.php" class="chip-chip"><i class="fas fa-box"></i><span class="chip-num"><?= (int)$total_products ?></span> Products</a></div>
  <?php if (isAdmin() || isSalesTeam()): ?>
  <div class="col-md-3 col-6 mb-2"><a href="<?= $base_url ?? '' ?>modules/customers/index.php" class="chip-chip"><i class="fas fa-users"></i><span class="chip-num"><?= (int)$total_customers ?></span> Customers</a></div>
  <?php endif; ?>
  <?php if (isAdmin()): ?>
  <div class="col-md-3 col-6 mb-2"><a href="<?= $base_url ?? '' ?>modules/inventory/suppliers.php" class="chip-chip"><i class="fas fa-truck-loading"></i><span class="chip-num"><?= (int)$total_suppliers ?></span> Suppliers</a></div>
  <div class="col-md-3 col-6 mb-2"><a href="<?= $base_url ?? '' ?>modules/employees/index.php" class="chip-chip"><i class="fas fa-user-tie"></i><span class="chip-num"><?= (int)$total_employees ?></span> Employees</a></div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
$(document).ready(function(){
  // Sales vs Purchases
  new Chart(document.getElementById('salesChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($days_labels) ?>,
      datasets: [
        { label: 'Sales', data: <?= json_encode($days_sales) ?>, backgroundColor: '#10b981', borderRadius: 4, maxBarThickness: 28 },
        { label: 'Purchases', data: <?= json_encode($days_purchases) ?>, backgroundColor: '#3b82f6', borderRadius: 4, maxBarThickness: 28 }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8 } } },
      scales: {
        x: { grid: { display: false } },
        y: { beginAtZero: true, ticks: { callback: function(v){ return v >= 1000 ? (v/1000)+'k' : v; } } }
      }
    }
  });

  // Expense doughnut
  var expLabels = <?= json_encode(array_column($expense_cats, 'name')) ?>;
  var expData = <?= json_encode(array_map('floatval', array_column($expense_cats, 'total'))) ?>;
  if (expLabels.length) {
    var expColors = ['#10b981','#3b82f6','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#ec4899','#64748b'];
    var exChart = new Chart(document.getElementById('expenseChart'), {
      type: 'doughnut',
      data: { labels: expLabels, datasets: [{ data: expData, backgroundColor: expColors, borderWidth: 2, borderColor: '#fff' }] },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } }
      }
    });
    var legendHtml = '';
    expLabels.forEach(function(l, i){
      legendHtml += '<span class="exp-legend-item"><span class="exp-legend-dot" style="background:'+expColors[i%expColors.length]+'"></span> '+l+' <b>'+Number(expData[i]).toLocaleString()+'</b></span>';
    });
    document.getElementById('expenseLegend').innerHTML = legendHtml;
  } else {
    var p = document.getElementById('expenseChart').parentElement;
    p.innerHTML = '<div class="text-center text-muted py-4"><i class="far fa-chart-pie fa-2x mb-2"></i><br>No expenses recorded this month</div>';
    document.getElementById('expenseLegend').innerHTML = '';
  }
});
</script>

<?php require_once 'includes/footer.php'; ?>