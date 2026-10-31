<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Profit Report';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

// Date Filters (calendar range)
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');

$sale_type   = trim($_GET['sale_type'] ?? '');
if (!in_array($sale_type, ['counter', 'shop'], true)) { $sale_type = ''; }
$ob_id       = (int)($_GET['order_booker_id'] ?? 0);
$salesman_id = (int)($_GET['salesman_id'] ?? 0);
$area        = trim($_GET['area'] ?? '');
$q           = trim($_GET['q'] ?? '');
$tab         = trim($_GET['tab'] ?? 'invoices');
if (!in_array($tab, ['invoices', 'products', 'daily'], true)) { $tab = 'invoices'; }

$area_options = allKnownAreas($pdo);
if ($area !== '' && !in_array($area, $area_options, true)) { $area = ''; }

$all_order_bookers = $pdo->query("SELECT id, full_name, username FROM users WHERE role = 'order_booker' AND status = 1 ORDER BY full_name ASC")->fetchAll();
$all_salesmen = $pdo->query("SELECT id, full_name FROM employees WHERE employee_type = 'salesman' AND status = 1 ORDER BY full_name ASC")->fetchAll();

// Base SQL conditions for sales
$where_clauses = ["s.status <> 'cancelled'"];
$params = [];

if ($from !== '') {
    $where_clauses[] = "COALESCE(s.delivery_date, s.sale_date) >= ?";
    $params[] = $from;
}
if ($to !== '') {
    $where_clauses[] = "COALESCE(s.delivery_date, s.sale_date) <= ?";
    $params[] = $to;
}
if ($sale_type === 'counter') {
    $where_clauses[] = "LOWER(c.area) = 'counter'";
} elseif ($sale_type === 'shop') {
    $where_clauses[] = "(LOWER(c.area) <> 'counter' OR c.area IS NULL)";
}
if ($ob_id > 0) {
    $where_clauses[] = "s.created_by = ?";
    $params[] = $ob_id;
}
if ($salesman_id > 0) {
    $where_clauses[] = "s.salesman_id = ?";
    $params[] = $salesman_id;
}
if ($area !== '') {
    $where_clauses[] = "LOWER(c.area) = LOWER(?)";
    $params[] = $area;
}

$where_sql = implode(' AND ', $where_clauses);

// 1. Overall KPI Aggregates
$kpi_sql = "
    SELECT 
        COUNT(DISTINCT s.id) AS total_invoices,
        COALESCE(SUM(s.total_amount), 0) AS gross_sales,
        COALESCE(SUM(sr_agg.returned_amt), 0) AS total_returned_sales,
        COALESCE(SUM(cost_agg.total_cost), 0) AS gross_cost,
        COALESCE(SUM(sr_cost_agg.returned_cost), 0) AS total_returned_cost,
        COALESCE(SUM(s.paid_amount), 0) AS total_paid,
        COALESCE(SUM(s.due_amount), 0) AS total_due,
        COALESCE(SUM(cost_agg.total_boxes), 0) - COALESCE(SUM(sr_cost_agg.returned_boxes), 0) AS net_sold_boxes,
        COALESCE(SUM(cost_agg.total_cartons), 0) - COALESCE(SUM(sr_cost_agg.returned_cartons), 0) AS net_sold_cartons
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.id
    LEFT JOIN (
        SELECT COALESCE(invoice_id, sale_id) AS sale_id, SUM(total_amount) AS returned_amt
        FROM sale_returns
        WHERE status = 'Completed'
        GROUP BY COALESCE(invoice_id, sale_id)
    ) sr_agg ON sr_agg.sale_id = s.id
    LEFT JOIN (
        SELECT si.sale_id,
               SUM(COALESCE(si.cost_price, p.purchase_price / GREATEST(COALESCE(p.boxes_per_carton,1),1)) * si.quantity) AS total_cost,
               SUM(si.quantity) AS total_boxes,
               SUM(FLOOR(si.quantity / GREATEST(COALESCE(p.boxes_per_carton,1),1))) AS total_cartons
        FROM sale_items si
        JOIN products p ON si.product_id = p.id
        GROUP BY si.sale_id
    ) cost_agg ON cost_agg.sale_id = s.id
    LEFT JOIN (
        SELECT COALESCE(sr.invoice_id, sr.sale_id) AS sale_id,
               SUM(COALESCE(si.cost_price, p.purchase_price / GREATEST(COALESCE(p.boxes_per_carton,1),1)) * sri.quantity) AS returned_cost,
               SUM(sri.quantity) AS returned_boxes,
               SUM(FLOOR(sri.quantity / GREATEST(COALESCE(p.boxes_per_carton,1),1))) AS returned_cartons
        FROM sale_return_items sri
        JOIN sale_returns sr ON sr.id = sri.return_id
        JOIN products p ON p.id = sri.product_id
        LEFT JOIN sale_items si ON si.sale_id = COALESCE(sr.invoice_id, sr.sale_id) AND si.product_id = sri.product_id
        WHERE sr.status = 'Completed'
        GROUP BY COALESCE(sr.invoice_id, sr.sale_id)
    ) sr_cost_agg ON sr_cost_agg.sale_id = s.id
    WHERE $where_sql
";

$kpi_stmt = $pdo->prepare($kpi_sql);
$kpi_stmt->execute($params);
$kpi = $kpi_stmt->fetch() ?: [];

$total_invoices      = (int)($kpi['total_invoices'] ?? 0);
$gross_sales         = (float)($kpi['gross_sales'] ?? 0);
$returned_sales      = (float)($kpi['total_returned_sales'] ?? 0);
$net_sales           = max(0, $gross_sales - $returned_sales);
$gross_cost          = (float)($kpi['gross_cost'] ?? 0);
$returned_cost       = (float)($kpi['total_returned_cost'] ?? 0);
$net_cost            = max(0, $gross_cost - $returned_cost);
$gross_profit        = $net_sales - $net_cost;
$gross_margin_pct    = $net_sales > 0 ? ($gross_profit / $net_sales) * 100 : 0;
$total_paid          = (float)($kpi['total_paid'] ?? 0);
$total_due           = (float)($kpi['total_due'] ?? 0);
$net_sold_boxes      = (int)($kpi['net_sold_boxes'] ?? 0);
$net_sold_cartons    = (int)($kpi['net_sold_cartons'] ?? 0);

// Operating Expenses for the same period
$exp_sql = "SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE 1=1";
$exp_params = [];
if ($from !== '') { $exp_sql .= " AND expense_date >= ?"; $exp_params[] = $from; }
if ($to !== '')   { $exp_sql .= " AND expense_date <= ?"; $exp_params[] = $to; }
$exp_stmt = $pdo->prepare($exp_sql);
$exp_stmt->execute($exp_params);
$total_expenses = (float)$exp_stmt->fetchColumn();

$net_profit = $gross_profit - $total_expenses;
$net_profit_margin_pct = $net_sales > 0 ? ($net_profit / $net_sales) * 100 : 0;

// 2. Data for Tab 1: Invoice-wise Breakdown
$invoice_rows = [];
if ($tab === 'invoices') {
    $inv_sql = "
        SELECT 
            s.id, s.invoice_no, s.sale_date, s.delivery_date, s.total_amount, s.paid_amount, s.due_amount,
            s.discount_amount, s.payment_method,
            c.id AS customer_id, c.full_name AS customer_name, c.area AS customer_area,
            CASE WHEN LOWER(c.area) = 'counter' THEN 1 ELSE 0 END AS is_counter,
            e.full_name AS salesman_name,
            u.full_name AS order_taker_name,
            COALESCE(cost_agg.total_cost, 0) AS gross_cost,
            COALESCE(sr_agg.returned_amt, 0) AS returned_amt,
            COALESCE(sr_cost_agg.returned_cost, 0) AS returned_cost
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN employees e ON s.salesman_id = e.id
        LEFT JOIN users u ON s.created_by = u.id
        LEFT JOIN (
            SELECT COALESCE(invoice_id, sale_id) AS sale_id, SUM(total_amount) AS returned_amt
            FROM sale_returns
            WHERE status = 'Completed'
            GROUP BY COALESCE(invoice_id, sale_id)
        ) sr_agg ON sr_agg.sale_id = s.id
        LEFT JOIN (
            SELECT si.sale_id,
                   SUM(COALESCE(si.cost_price, p.purchase_price / GREATEST(COALESCE(p.boxes_per_carton,1),1)) * si.quantity) AS total_cost
            FROM sale_items si
            JOIN products p ON si.product_id = p.id
            GROUP BY si.sale_id
        ) cost_agg ON cost_agg.sale_id = s.id
        LEFT JOIN (
            SELECT COALESCE(sr.invoice_id, sr.sale_id) AS sale_id,
                   SUM(COALESCE(si.cost_price, p.purchase_price / GREATEST(COALESCE(p.boxes_per_carton,1),1)) * sri.quantity) AS returned_cost
            FROM sale_return_items sri
            JOIN sale_returns sr ON sr.id = sri.return_id
            JOIN products p ON p.id = sri.product_id
            LEFT JOIN sale_items si ON si.sale_id = COALESCE(sr.invoice_id, sr.sale_id) AND si.product_id = sri.product_id
            WHERE sr.status = 'Completed'
            GROUP BY COALESCE(sr.invoice_id, sr.sale_id)
        ) sr_cost_agg ON sr_cost_agg.sale_id = s.id
        WHERE $where_sql
        ORDER BY COALESCE(s.delivery_date, s.sale_date) DESC, s.id DESC
    ";
    $inv_stmt = $pdo->prepare($inv_sql);
    $inv_stmt->execute($params);
    $raw_invoices = $inv_stmt->fetchAll();

    foreach ($raw_invoices as $inv) {
        $inv_gross = (float)$inv['total_amount'];
        $inv_ret_amt = (float)$inv['returned_amt'];
        $inv_net_sale = max(0, $inv_gross - $inv_ret_amt);

        $inv_gross_cost = (float)$inv['gross_cost'];
        $inv_ret_cost = (float)$inv['returned_cost'];
        $inv_net_cost = max(0, $inv_gross_cost - $inv_ret_cost);

        $inv_profit = $inv_net_sale - $inv_net_cost;
        $inv_margin = $inv_net_sale > 0 ? ($inv_profit / $inv_net_sale) * 100 : 0;

        $invoice_rows[] = array_merge($inv, [
            'net_sale'   => $inv_net_sale,
            'net_cost'   => $inv_net_cost,
            'profit'     => $inv_profit,
            'margin_pct' => $inv_margin,
        ]);
    }
}

// 3. Data for Tab 2: Product-wise Profit Breakdown
$product_rows = [];
if ($tab === 'products') {
    $prod_sql = "
        SELECT 
            p.id, p.code, p.name, p.boxes_per_carton,
            COALESCE(cat.name, 'Uncategorized') AS category_name,
            COALESCE(sales_agg.sold_qty, 0) - COALESCE(ret_agg.ret_qty, 0) AS net_qty,
            COALESCE(sales_agg.sold_amt, 0) - COALESCE(ret_agg.ret_amt, 0) AS net_sales,
            COALESCE(sales_agg.sold_cost, 0) - COALESCE(ret_agg.ret_cost, 0) AS net_cost
        FROM products p
        LEFT JOIN categories cat ON p.category_id = cat.id
        JOIN (
            SELECT 
                si.product_id,
                SUM(si.quantity) AS sold_qty,
                SUM(si.subtotal) AS sold_amt,
                SUM(COALESCE(si.cost_price, prod.purchase_price / GREATEST(COALESCE(prod.boxes_per_carton,1),1)) * si.quantity) AS sold_cost
            FROM sale_items si
            JOIN sales s ON si.sale_id = s.id
            LEFT JOIN customers c ON s.customer_id = c.id
            JOIN products prod ON si.product_id = prod.id
            WHERE $where_sql
            GROUP BY si.product_id
        ) sales_agg ON sales_agg.product_id = p.id
        LEFT JOIN (
            SELECT 
                sri.product_id,
                SUM(sri.quantity) AS ret_qty,
                SUM(sri.total_price) AS ret_amt,
                SUM(COALESCE(si.cost_price, prod2.purchase_price / GREATEST(COALESCE(prod2.boxes_per_carton,1),1)) * sri.quantity) AS ret_cost
            FROM sale_return_items sri
            JOIN sale_returns sr ON sr.id = sri.return_id
            JOIN sales s ON COALESCE(sr.invoice_id, sr.sale_id) = s.id
            LEFT JOIN customers c ON s.customer_id = c.id
            JOIN products prod2 ON sri.product_id = prod2.id
            LEFT JOIN sale_items si ON si.sale_id = s.id AND si.product_id = sri.product_id
            WHERE sr.status = 'Completed' AND $where_sql
            GROUP BY sri.product_id
        ) ret_agg ON ret_agg.product_id = p.id
        ORDER BY ( (COALESCE(sales_agg.sold_amt, 0) - COALESCE(ret_agg.ret_amt, 0)) - (COALESCE(sales_agg.sold_cost, 0) - COALESCE(ret_agg.ret_cost, 0)) ) DESC
    ";
    $prod_stmt = $pdo->prepare($prod_sql);
    $prod_stmt->execute(array_merge($params, $params));
    $raw_prods = $prod_stmt->fetchAll();

    foreach ($raw_prods as $pr) {
        $pr_sales = (float)$pr['net_sales'];
        $pr_cost  = (float)$pr['net_cost'];
        $pr_profit = $pr_sales - $pr_cost;
        $pr_margin = $pr_sales > 0 ? ($pr_profit / $pr_sales) * 100 : 0;
        $product_rows[] = array_merge($pr, [
            'profit'     => $pr_profit,
            'margin_pct' => $pr_margin,
        ]);
    }
}

// 4. Data for Tab 3: Customer-wise Profit Breakdown
// 4. Data for Tab 3: Daily Trend Breakdown
$daily_rows = [];
if ($tab === 'daily') {
    $day_sql = "
        SELECT 
            COALESCE(s.delivery_date, s.sale_date) AS report_date,
            COUNT(DISTINCT s.id) AS invoice_count,
            COALESCE(SUM(s.total_amount), 0) - COALESCE(SUM(sr.total_amount), 0) AS net_sales,
            COALESCE(SUM(cost_agg.total_cost), 0) - COALESCE(SUM(sr_cost_agg.returned_cost), 0) AS net_cost
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN (
            SELECT COALESCE(invoice_id, sale_id) AS sale_id, SUM(total_amount) AS total_amount
            FROM sale_returns
            WHERE status = 'Completed'
            GROUP BY COALESCE(invoice_id, sale_id)
        ) sr ON sr.sale_id = s.id
        LEFT JOIN (
            SELECT si.sale_id, SUM(COALESCE(si.cost_price, p.purchase_price / GREATEST(COALESCE(p.boxes_per_carton,1),1)) * si.quantity) AS total_cost
            FROM sale_items si
            JOIN products p ON si.product_id = p.id
            GROUP BY si.sale_id
        ) cost_agg ON cost_agg.sale_id = s.id
        LEFT JOIN (
            SELECT COALESCE(sr2.invoice_id, sr2.sale_id) AS sale_id,
                   SUM(COALESCE(si2.cost_price, p2.purchase_price / GREATEST(COALESCE(p2.boxes_per_carton,1),1)) * sri.quantity) AS returned_cost
            FROM sale_return_items sri
            JOIN sale_returns sr2 ON sr2.id = sri.return_id
            JOIN products p2 ON p2.id = sri.product_id
            LEFT JOIN sale_items si2 ON si2.sale_id = COALESCE(sr2.invoice_id, sr2.sale_id) AND si2.product_id = sri.product_id
            WHERE sr2.status = 'Completed'
            GROUP BY COALESCE(sr2.invoice_id, sr2.sale_id)
        ) sr_cost_agg ON sr_cost_agg.sale_id = s.id
        WHERE $where_sql
        GROUP BY COALESCE(s.delivery_date, s.sale_date)
        ORDER BY report_date DESC
    ";
    $day_stmt = $pdo->prepare($day_sql);
    $day_stmt->execute($params);
    $raw_days = $day_stmt->fetchAll();

    foreach ($raw_days as $dy) {
        $dy_sales = (float)$dy['net_sales'];
        $dy_cost  = (float)$dy['net_cost'];
        $dy_profit = $dy_sales - $dy_cost;
        $dy_margin = $dy_sales > 0 ? ($dy_profit / $dy_sales) * 100 : 0;
        $daily_rows[] = array_merge($dy, [
            'profit'     => $dy_profit,
            'margin_pct' => $dy_margin,
        ]);
    }
}

// Build helper URL for changing filters/tabs
function profitUrl($changes = []) {
    $current = $_GET;
    foreach ($changes as $k => $v) {
        if ($v === null || $v === '') {
            unset($current[$k]);
        } else {
            $current[$k] = $v;
        }
    }
    $qs = http_build_query($current);
    return 'profit_report.php' . ($qs ? '?' . $qs : '');
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<style>
@media print {
  @page {
    size: A4 landscape;
    margin: 8mm;
  }
  .report-table th, .report-table td {
    padding: 4px 6px !important;
    font-size: 11px !important;
  }
  .report-tfoot {
    display: table-row-group !important;
    page-break-inside: avoid !important;
  }
}
.kpi-stat-card {
  border-radius: 8px;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.kpi-stat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.08) !important;
}
.profit-pos {
  color: #059669 !important;
  font-weight: 600;
}
.profit-neg {
  color: #dc2626 !important;
  font-weight: 600;
}
.table th {
  background-color: #f8fafc;
  color: #334155;
  font-size: 0.82rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
.nav-pills .nav-link {
  font-weight: 600;
  font-size: 0.9rem;
  color: #475569;
  border-radius: 6px;
  padding: 8px 16px;
}
.nav-pills .nav-link.active {
  background-color: #0284c7;
  color: #ffffff;
}
</style>

<div class="card shadow mb-4">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center d-print-none">
    <h6 class="mb-0 font-weight-bold text-primary">
      <i class="fas fa-chart-line mr-1"></i> Business Profit &amp; Loss Analysis
    </h6>
    <div class="d-flex flex-wrap align-items-center mt-2 mt-sm-0">
      <?php if ($from || $to || $sale_type || $ob_id || $salesman_id || $area): ?>
      <a href="profit_report.php" class="btn btn-sm btn-outline-danger mr-2"><i class="fas fa-undo mr-1"></i> Reset Filters</a>
      <?php endif; ?>
      <a href="invoices.php" class="btn btn-sm btn-outline-secondary mr-2"><i class="fas fa-arrow-left mr-1"></i> Invoices</a>
      <button type="button" class="btn btn-sm btn-primary" onclick="window.print()"><i class="fas fa-print mr-1"></i> Print Report</button>
    </div>
  </div>

  <div class="card-body">

    <!-- Print Header -->
    <div class="report-sheet d-none d-print-block">
      <div class="report-head">
        <div class="report-brand-line">
          <div class="report-brand">
            <div class="report-brand-name">Mehboob Traders</div>
            <div class="report-brand-sub">Wholesale Business &middot; Lahore, Pakistan &middot; GST No: --</div>
          </div>
          <div class="report-title-box">
            <div class="report-title">Profit &amp; Loss Analysis Report</div>
            <div class="report-meta">
              <?= $from && $to ? formatDate($from) . ' to ' . formatDate($to) : ($from ? 'From ' . formatDate($from) : ($to ? 'Until ' . formatDate($to) : 'All Time Records')) ?>
              <?= $sale_type ? ' &middot; ' . ucfirst($sale_type) . ' Sales' : '' ?>
              <?= $area ? ' &middot; Area: ' . htmlspecialchars($area) : '' ?>
            </div>
          </div>
        </div>
      </div>

      <table class="report-summary-table mb-3">
        <tr>
          <td class="rs-cell"><span class="rs-label">Total Invoices</span><span class="rs-val"><?=number_format($total_invoices)?></span></td>
          <td class="rs-cell"><span class="rs-label">Net Sales</span><span class="rs-val text-primary">PKR <?=formatCurrency($net_sales)?></span></td>
          <td class="rs-cell"><span class="rs-label">Cost of Goods (COGS)</span><span class="rs-val text-muted">PKR <?=formatCurrency($net_cost)?></span></td>
          <td class="rs-cell"><span class="rs-label">Gross Profit (Margin)</span><span class="rs-val <?= $gross_profit >= 0 ? 'text-success' : 'text-danger' ?>">PKR <?=formatCurrency($gross_profit)?> (<?=number_format($gross_margin_pct, 1)?>%)</span></td>
          <td class="rs-cell"><span class="rs-label">Operating Expenses</span><span class="rs-val text-danger">PKR <?=formatCurrency($total_expenses)?></span></td>
          <td class="rs-cell"><span class="rs-label">Net Profit</span><span class="rs-val <?= $net_profit >= 0 ? 'text-success' : 'text-danger' ?>">PKR <?=formatCurrency($net_profit)?></span></td>
        </tr>
      </table>
    </div>

    <!-- Filter Card -->
    <div class="card bg-light border shadow-sm mb-4 d-print-none">
      <div class="card-body py-3 px-3">
        <!-- Filter Form -->
        <form method="get" id="profitFilterForm" class="row g-2 align-items-end">
          <input type="hidden" name="tab" value="<?=htmlspecialchars($tab)?>">
          <div class="col-lg-2 col-md-4 col-6 mb-2">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="far fa-calendar-alt"></i> From Date</label>
            <input type="date" name="from" class="form-control" style="height: 38px;" value="<?=htmlspecialchars($from)?>" onchange="document.getElementById('profitFilterForm').submit();">
          </div>
          <div class="col-lg-2 col-md-4 col-6 mb-2">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="far fa-calendar-alt"></i> To Date</label>
            <input type="date" name="to" class="form-control" style="height: 38px;" value="<?=htmlspecialchars($to)?>" onchange="document.getElementById('profitFilterForm').submit();">
          </div>
          <div class="col-lg-2 col-md-4 col-6 mb-2">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="fas fa-cash-register text-warning"></i> Sale Type</label>
            <select name="sale_type" class="form-control" style="height: 38px;" onchange="document.getElementById('profitFilterForm').submit();">
              <option value="">-- All Sale Types --</option>
              <option value="counter" <?= $sale_type === 'counter' ? 'selected' : '' ?>>Counter Sales Only</option>
              <option value="shop" <?= $sale_type === 'shop' ? 'selected' : '' ?>>Shop Orders Only</option>
            </select>
          </div>
          <div class="col-lg-2 col-md-4 col-6 mb-2">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="fas fa-user-tag text-info"></i> Order Booker</label>
            <select name="order_booker_id" class="form-control" style="height: 38px;" onchange="document.getElementById('profitFilterForm').submit();">
              <option value="">-- All Bookers --</option>
              <?php foreach ($all_order_bookers as $ob): ?>
              <option value="<?=$ob['id']?>" <?= $ob_id === (int)$ob['id'] ? 'selected' : '' ?>><?=htmlspecialchars($ob['full_name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-lg-2 col-md-4 col-6 mb-2">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="fas fa-truck text-secondary"></i> Salesman</label>
            <select name="salesman_id" class="form-control" style="height: 38px;" onchange="document.getElementById('profitFilterForm').submit();">
              <option value="">-- All Salesmen --</option>
              <?php foreach ($all_salesmen as $sm): ?>
              <option value="<?=$sm['id']?>" <?= $salesman_id === (int)$sm['id'] ? 'selected' : '' ?>><?=htmlspecialchars($sm['full_name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-lg-2 col-md-4 col-6 mb-2">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <label class="small font-weight-bold text-muted mb-0"><i class="fas fa-map-marker-alt text-danger"></i> Area</label>
              <?php if ($from || $to || $sale_type || $ob_id || $salesman_id || $area): ?>
                <a href="profit_report.php<?= $tab !== 'invoices' ? '?tab=' . urlencode($tab) : '' ?>" class="small text-danger font-weight-bold" title="Reset all filters"><i class="fas fa-undo"></i> Reset</a>
              <?php endif; ?>
            </div>
            <select name="area" class="form-control" style="height: 38px;" onchange="document.getElementById('profitFilterForm').submit();">
              <option value="">-- All Areas --</option>
              <?php foreach ($area_options as $ar): ?>
              <option value="<?=htmlspecialchars($ar)?>" <?= $area === $ar ? 'selected' : '' ?>><?=htmlspecialchars($ar)?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
      </div>
    </div>

    <!-- Executive KPI Summary Cards Row -->
    <div class="row g-3 mb-4 d-print-none">
      <div class="col-xl col-md-6 mb-3">
        <div class="card border-left-primary shadow-sm h-100 kpi-stat-card">
          <div class="card-body py-3 px-3">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Net Sales</div>
                <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($net_sales)?></div>
                <small class="text-muted"><?=number_format($total_invoices)?> Invoices <?php if ($returned_sales > 0): ?>&middot; Ret: -PKR <?=formatCurrency($returned_sales)?><?php endif; ?></small>
              </div>
              <div class="text-primary bg-light p-3 rounded-circle"><i class="fas fa-shopping-bag fa-2x"></i></div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-xl col-md-6 mb-3">
        <div class="card border-left-secondary shadow-sm h-100 kpi-stat-card">
          <div class="card-body py-3 px-3">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">Cost of Goods (COGS)</div>
                <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($net_cost)?></div>
                <small class="text-muted">Volume: <?=number_format($net_sold_cartons)?> Cartons (<?=number_format($net_sold_boxes)?> Boxes)</small>
              </div>
              <div class="text-secondary bg-light p-3 rounded-circle"><i class="fas fa-boxes fa-2x"></i></div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-xl col-md-6 mb-3">
        <div class="card border-left-danger shadow-sm h-100 kpi-stat-card">
          <div class="card-body py-3 px-3">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Total Expenses</div>
                <div class="h5 mb-0 font-weight-bold text-danger">PKR <?=formatCurrency($total_expenses)?></div>
                <small class="text-muted">Operating expenses in this period</small>
              </div>
              <div class="text-danger bg-light p-3 rounded-circle"><i class="fas fa-receipt fa-2x"></i></div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-xl col-md-6 mb-3">
        <div class="card border-left-success shadow-sm h-100 kpi-stat-card">
          <div class="card-body py-3 px-3">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Gross Profit (Before Expenses)</div>
                <div class="h5 mb-0 font-weight-bold <?= $gross_profit >= 0 ? 'text-success' : 'text-danger' ?>">
                  PKR <?=formatCurrency($gross_profit)?>
                </div>
                <small class="font-weight-bold <?= $gross_margin_pct >= 0 ? 'text-success' : 'text-danger' ?>">
                  <i class="fas fa-percentage mr-1"></i> <?=number_format($gross_margin_pct, 2)?>% Gross Margin
                </small>
              </div>
              <div class="text-success bg-light p-3 rounded-circle"><i class="fas fa-money-bill-wave fa-2x"></i></div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-xl col-md-6 mb-3">
        <div class="card <?= $net_profit >= 0 ? 'border-left-success' : 'border-left-danger' ?> shadow-sm h-100 kpi-stat-card">
          <div class="card-body py-3 px-3">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <div class="text-xs font-weight-bold <?= $net_profit >= 0 ? 'text-success' : 'text-danger' ?> text-uppercase mb-1">Total Profit (After Expenses)</div>
                <div class="h5 mb-0 font-weight-bold <?= $net_profit >= 0 ? 'text-success' : 'text-danger' ?>">
                  PKR <?=formatCurrency($net_profit)?>
                </div>
                <small class="text-muted">
                  Gross PKR <?=formatCurrency($gross_profit)?> &minus; Expenses PKR <?=formatCurrency($total_expenses)?>
                  &middot; Margin: <?=number_format($net_profit_margin_pct, 2)?>%
                </small>
              </div>
              <div class="<?= $net_profit >= 0 ? 'text-success' : 'text-danger' ?> bg-light p-3 rounded-circle"><i class="fas fa-hand-holding-usd fa-2x"></i></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Navigation Pills / View Tabs -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 border-bottom pb-2 d-print-none">
      <ul class="nav nav-pills" id="profitTabs">
        <li class="nav-item">
          <a class="nav-link <?= $tab === 'invoices' ? 'active' : '' ?>" href="<?=profitUrl(['tab' => 'invoices'])?>">
            <i class="fas fa-file-invoice mr-1"></i> By Invoice (<?=number_format($total_invoices)?>)
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $tab === 'products' ? 'active' : '' ?>" href="<?=profitUrl(['tab' => 'products'])?>">
            <i class="fas fa-box-open mr-1"></i> By Product
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $tab === 'daily' ? 'active' : '' ?>" href="<?=profitUrl(['tab' => 'daily'])?>">
            <i class="fas fa-calendar-alt mr-1"></i> Daily Trend
          </a>
        </li>
      </ul>

      <!-- Live Search Box for Table -->
      <div class="input-group input-group-sm mt-2 mt-md-0" style="max-width: 280px;">
        <input type="text" id="reportSearch" class="form-control" placeholder="Search rows..." autocomplete="off">
        <div class="input-group-append">
          <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
        </div>
      </div>
    </div>

    <!-- TAB 1: INVOICE-WISE PROFIT -->
    <?php if ($tab === 'invoices'): ?>
    <div class="table-responsive">
      <table class="table table-bordered table-hover report-table" id="profitTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Invoice</th>
            <th>Date</th>
            <th>Customer / Shop</th>
            <th>Order Taker</th>
            <th>Salesman</th>
            <th class="text-right">Sale Amount</th>
            <th class="text-right">Cost (COGS)</th>
            <th class="text-right">Net Profit</th>
            <th class="text-right">Margin %</th>
            <th class="no-print text-center">Action</th>
          </tr>
        </thead>
        <tbody id="profitTableBody">
          <?php 
          $s_sales = 0; $s_cost = 0; $s_profit = 0;
          foreach ($invoice_rows as $idx => $inv): 
            $s_sales += $inv['net_sale'];
            $s_cost  += $inv['net_cost'];
            $s_profit += $inv['profit'];
          ?>
          <tr class="report-row <?= $inv['is_counter'] ? 'invoice-counter' : '' ?>"
              data-search="<?=htmlspecialchars(strtolower($inv['invoice_no'] . ' ' . $inv['customer_name'] . ' ' . $inv['order_taker_name'] . ' ' . $inv['salesman_name'] . ' ' . $inv['customer_area']))?>">
            <td class="text-muted small"><?= $idx + 1 ?></td>
            <td class="font-weight-bold">
              <a href="invoice.php?id=<?=$inv['id']?>"><?=htmlspecialchars($inv['invoice_no'])?></a>
              <?php if (!empty($inv['is_counter'])): ?>
                <span class="badge badge-warning text-dark ml-1" style="font-size: 10px;"><i class="fas fa-cash-register mr-1"></i>Counter</span>
              <?php endif; ?>
              <?php if ($inv['returned_amt'] > 0): ?>
                <span class="badge badge-danger ml-1" style="font-size: 10px;">Return</span>
              <?php endif; ?>
            </td>
            <td><?=formatDate($inv['delivery_date'] ?: $inv['sale_date'])?></td>
            <td>
              <span class="font-weight-heavy text-dark"><?=htmlspecialchars($inv['customer_name'] ?? 'N/A')?></span>
              <?php if (!empty($inv['is_counter'])): ?>
                <br><small class="text-warning font-weight-bold"><i class="fas fa-cash-register mr-1"></i>Counter Walk-in</small>
              <?php elseif (!empty($inv['customer_area'])): ?>
                <br><small class="text-muted"><i class="fas fa-map-marker-alt text-danger mr-1"></i><?=htmlspecialchars($inv['customer_area'])?></small>
              <?php endif; ?>
            </td>
            <td><?=htmlspecialchars($inv['order_taker_name'] ?? '—')?></td>
            <td><?=htmlspecialchars($inv['salesman_name'] ?? '—')?></td>
            <td class="text-right font-weight-bold text-dark">
              PKR <?=formatCurrency($inv['net_sale'])?>
              <?php if ($inv['returned_amt'] > 0): ?>
                <div class="small text-danger" style="font-size:10px;">-PKR <?=formatCurrency($inv['returned_amt'])?> ret</div>
              <?php endif; ?>
            </td>
            <td class="text-right text-muted">PKR <?=formatCurrency($inv['net_cost'])?></td>
            <td class="text-right <?= $inv['profit'] >= 0 ? 'profit-pos' : 'profit-neg' ?>">
              PKR <?=formatCurrency($inv['profit'])?>
            </td>
            <td class="text-right font-weight-bold <?= $inv['margin_pct'] >= 0 ? 'text-success' : 'text-danger' ?>">
              <?=number_format($inv['margin_pct'], 1)?>%
            </td>
            <td class="no-print text-center" nowrap>
              <button type="button" class="btn btn-sm btn-outline-info py-0 px-2 btn-profit-breakdown" data-sale-id="<?=$inv['id']?>" title="View Items Profit Breakdown">
                <i class="fas fa-list-alt"></i> Details
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($invoice_rows)): ?>
          <tr><td colspan="11" class="text-center text-muted py-4">No invoices found for the selected criteria.</td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot class="report-tfoot font-weight-bold bg-light">
          <tr>
            <td colspan="6" class="text-right">TOTAL (<?=count($invoice_rows)?> Invoices):</td>
            <td class="text-right text-dark">PKR <?=formatCurrency($s_sales)?></td>
            <td class="text-right text-muted">PKR <?=formatCurrency($s_cost)?></td>
            <td class="text-right <?= $s_profit >= 0 ? 'profit-pos' : 'profit-neg' ?>">PKR <?=formatCurrency($s_profit)?></td>
            <td class="text-right <?= $s_sales > 0 && ($s_profit / $s_sales) >= 0 ? 'text-success' : 'text-danger' ?>">
              <?= $s_sales > 0 ? number_format(($s_profit / $s_sales) * 100, 1) . '%' : '0.0%' ?>
            </td>
            <td class="no-print"></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>

    <!-- TAB 2: PRODUCT-WISE PROFIT -->
    <?php if ($tab === 'products'): ?>
    <div class="table-responsive">
      <table class="table table-bordered table-hover report-table" id="profitTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Product Code</th>
            <th>Product Name</th>
            <th>Category</th>
            <th>Packaging</th>
            <th class="text-center">Net Qty Sold</th>
            <th class="text-right">Total Revenue</th>
            <th class="text-right">Total Cost</th>
            <th class="text-right">Total Profit</th>
            <th class="text-right">Margin %</th>
          </tr>
        </thead>
        <tbody id="profitTableBody">
          <?php 
          $p_sales = 0; $p_cost = 0; $p_profit = 0; $p_qty = 0;
          foreach ($product_rows as $idx => $pr): 
            $p_sales  += $pr['net_sales'];
            $p_cost   += $pr['net_cost'];
            $p_profit += $pr['profit'];
            $p_qty    += $pr['net_qty'];
            $bpc = max(1, (int)$pr['boxes_per_carton']);
            $cartons = floor($pr['net_qty'] / $bpc);
            $rem_boxes = $pr['net_qty'] % $bpc;
            $pkg_label = $bpc > 1 ? ($cartons . ' ctn' . ($rem_boxes > 0 ? ' + ' . $rem_boxes . ' b' : '')) : ($pr['net_qty'] . ' b');
          ?>
          <tr class="report-row" data-search="<?=htmlspecialchars(strtolower($pr['code'] . ' ' . $pr['name'] . ' ' . $pr['category_name']))?>">
            <td class="text-muted small"><?= $idx + 1 ?></td>
            <td><code><?=htmlspecialchars($pr['code'])?></code></td>
            <td class="font-weight-heavy text-dark"><?=htmlspecialchars($pr['name'])?></td>
            <td><span class="badge badge-light border"><?=htmlspecialchars($pr['category_name'])?></span></td>
            <td><small class="text-muted"><?=$bpc?> b/ctn</small></td>
            <td class="text-center font-weight-bold">
              <?=number_format($pr['net_qty'])?> boxes
              <div class="small text-muted font-weight-normal"><?=$pkg_label?></div>
            </td>
            <td class="text-right font-weight-bold text-dark">PKR <?=formatCurrency($pr['net_sales'])?></td>
            <td class="text-right text-muted">PKR <?=formatCurrency($pr['net_cost'])?></td>
            <td class="text-right <?= $pr['profit'] >= 0 ? 'profit-pos' : 'profit-neg' ?>">
              PKR <?=formatCurrency($pr['profit'])?>
            </td>
            <td class="text-right font-weight-bold <?= $pr['margin_pct'] >= 0 ? 'text-success' : 'text-danger' ?>">
              <?=number_format($pr['margin_pct'], 1)?>%
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($product_rows)): ?>
          <tr><td colspan="10" class="text-center text-muted py-4">No product sales found for the selected period.</td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot class="report-tfoot font-weight-bold bg-light">
          <tr>
            <td colspan="5" class="text-right">TOTAL (<?=count($product_rows)?> Products):</td>
            <td class="text-center"><?=number_format($p_qty)?> boxes</td>
            <td class="text-right text-dark">PKR <?=formatCurrency($p_sales)?></td>
            <td class="text-right text-muted">PKR <?=formatCurrency($p_cost)?></td>
            <td class="text-right <?= $p_profit >= 0 ? 'profit-pos' : 'profit-neg' ?>">PKR <?=formatCurrency($p_profit)?></td>
            <td class="text-right <?= $p_sales > 0 && ($p_profit / $p_sales) >= 0 ? 'text-success' : 'text-danger' ?>">
              <?= $p_sales > 0 ? number_format(($p_profit / $p_sales) * 100, 1) . '%' : '0.0%' ?>
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>

    <!-- TAB 3: DAILY TREND -->
    <?php if ($tab === 'daily'): ?>
    <div class="table-responsive">
      <table class="table table-bordered table-hover report-table" id="profitTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Date</th>
            <th class="text-center">Invoices Delivered / Sold</th>
            <th class="text-right">Net Sales Revenue</th>
            <th class="text-right">Cost (COGS)</th>
            <th class="text-right">Daily Gross Profit</th>
            <th class="text-right">Daily Margin %</th>
          </tr>
        </thead>
        <tbody id="profitTableBody">
          <?php 
          $d_sales = 0; $d_cost = 0; $d_profit = 0; $d_inv = 0;
          foreach ($daily_rows as $idx => $dy): 
            $d_sales  += $dy['net_sales'];
            $d_cost   += $dy['net_cost'];
            $d_profit += $dy['profit'];
            $d_inv    += $dy['invoice_count'];
          ?>
          <tr class="report-row" data-search="<?=htmlspecialchars(strtolower($dy['report_date']))?>">
            <td class="text-muted small"><?= $idx + 1 ?></td>
            <td class="font-weight-heavy text-dark"><?=formatDate($dy['report_date'])?></td>
            <td class="text-center font-weight-bold"><?=$dy['invoice_count']?></td>
            <td class="text-right font-weight-bold text-dark">PKR <?=formatCurrency($dy['net_sales'])?></td>
            <td class="text-right text-muted">PKR <?=formatCurrency($dy['net_cost'])?></td>
            <td class="text-right <?= $dy['profit'] >= 0 ? 'profit-pos' : 'profit-neg' ?>">
              PKR <?=formatCurrency($dy['profit'])?>
            </td>
            <td class="text-right font-weight-bold <?= $dy['margin_pct'] >= 0 ? 'text-success' : 'text-danger' ?>">
              <?=number_format($dy['margin_pct'], 1)?>%
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($daily_rows)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No daily sales records found for this period.</td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot class="report-tfoot font-weight-bold bg-light">
          <tr>
            <td colspan="2" class="text-right">TOTAL:</td>
            <td class="text-center"><?=$d_inv?></td>
            <td class="text-right text-dark">PKR <?=formatCurrency($d_sales)?></td>
            <td class="text-right text-muted">PKR <?=formatCurrency($d_cost)?></td>
            <td class="text-right <?= $d_profit >= 0 ? 'profit-pos' : 'profit-neg' ?>">PKR <?=formatCurrency($d_profit)?></td>
            <td class="text-right <?= $d_sales > 0 && ($d_profit / $d_sales) >= 0 ? 'text-success' : 'text-danger' ?>">
              <?= $d_sales > 0 ? number_format(($d_profit / $d_sales) * 100, 1) . '%' : '0.0%' ?>
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- Modal: Interactive Item Profit Breakdown -->
<div class="modal fade" id="profitBreakdownModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
    <div class="modal-content shadow">
      <div class="modal-header bg-primary text-white py-2">
        <h6 class="modal-title font-weight-bold">
          <i class="fas fa-calculator mr-1"></i> Invoice Item-wise Profit Breakdown &middot; <span id="modalInvoiceNo">—</span>
        </h6>
        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body py-3">
        <div id="modalLoading" class="text-center py-4">
          <div class="spinner-border text-primary" role="status"></div>
          <p class="small text-muted mt-2 mb-0">Loading invoice profit details...</p>
        </div>
        <div id="modalContent" style="display:none;">
          <div class="row mb-3 bg-light p-2 rounded border mx-0">
            <div class="col-md-3"><strong>Customer:</strong> <span id="modalCustName">—</span></div>
            <div class="col-md-3"><strong>Date:</strong> <span id="modalSaleDate">—</span></div>
            <div class="col-md-3"><strong>Order Taker:</strong> <span id="modalOrderTaker">—</span></div>
            <div class="col-md-3"><strong>Salesman:</strong> <span id="modalSalesman">—</span></div>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-bordered">
              <thead class="thead-light">
                <tr>
                  <th>Product</th>
                  <th>Packaging</th>
                  <th class="text-center">Net Qty</th>
                  <th class="text-right">Sale Rate</th>
                  <th class="text-right">Cost Rate</th>
                  <th class="text-right">Total Sale</th>
                  <th class="text-right">Total Cost</th>
                  <th class="text-right">Profit</th>
                  <th class="text-right">Margin %</th>
                </tr>
              </thead>
              <tbody id="modalItemsBody"></tbody>
              <tfoot class="font-weight-bold bg-light" id="modalItemsFoot"></tfoot>
            </table>
          </div>
        </div>
      </div>
      <div class="modal-footer py-2 bg-light">
        <a href="#" id="modalViewInvoiceBtn" class="btn btn-sm btn-primary" target="_blank"><i class="fas fa-file-invoice mr-1"></i> Open Invoice</a>
        <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
$(document).ready(function(){
  // Live Instant Table Search Filter
  $('#reportSearch').on('input keyup paste', function(){
    var q = $.trim($(this).val()).toLowerCase();
    var terms = q.split(/\s+/).filter(Boolean);
    
    $('#profitTableBody tr.report-row').each(function(){
      var rowText = ($(this).data('search') || $(this).text()).toLowerCase();
      var match = true;
      for (var i = 0; i < terms.length; i++) {
        if (rowText.indexOf(terms[i]) === -1) {
          match = false;
          break;
        }
      }
      $(this).toggle(match);
    });
  });

  // Modal Profit Breakdown Loader
  $('.btn-profit-breakdown').on('click', function(){
    var saleId = $(this).data('sale-id');
    if (!saleId) return;

    $('#modalInvoiceNo').text('Loading...');
    $('#modalLoading').show();
    $('#modalContent').hide();
    $('#profitBreakdownModal').modal('show');
    $('#modalViewInvoiceBtn').attr('href', 'invoice.php?id=' + saleId);

    $.getJSON('ajax_invoice_profit_breakdown.php?id=' + saleId, function(data){
      $('#modalLoading').hide();
      $('#modalContent').show();
      $('#modalInvoiceNo').text(data.invoice_no);
      $('#modalCustName').text(data.customer_name);
      $('#modalSaleDate').text(data.sale_date);
      $('#modalOrderTaker').text(data.order_taker_name);
      $('#modalSalesman').text(data.salesman_name);

      var tbody = '';
      var totSale = 0;
      var totCost = 0;
      var totProfit = 0;

      $.each(data.items, function(i, it){
        totSale += it.sale_subtotal;
        totCost += it.cost_total;
        totProfit += it.profit;

        var profitClass = it.profit >= 0 ? 'text-success font-weight-bold' : 'text-danger font-weight-bold';
        var marginClass = it.margin_pct >= 0 ? 'text-success' : 'text-danger';

        tbody += '<tr>';
        tbody += '<td><strong>' + esc(it.product_name) + '</strong> <small class="text-muted">(' + esc(it.product_code) + ')</small></td>';
        tbody += '<td><small class="text-muted">' + esc(it.packaging_label) + '</small></td>';
        tbody += '<td class="text-center font-weight-bold">' + it.quantity + '</td>';
        tbody += '<td class="text-right">PKR ' + fmtNum(it.sale_price) + '</td>';
        tbody += '<td class="text-right text-muted">PKR ' + fmtNum(it.cost_per_box) + '</td>';
        tbody += '<td class="text-right">PKR ' + fmtNum(it.sale_subtotal) + '</td>';
        tbody += '<td class="text-right text-muted">PKR ' + fmtNum(it.cost_total) + '</td>';
        tbody += '<td class="text-right ' + profitClass + '">PKR ' + fmtNum(it.profit) + '</td>';
        tbody += '<td class="text-right font-weight-bold ' + marginClass + '">' + it.margin_pct + '%</td>';
        tbody += '</tr>';
      });

      $('#modalItemsBody').html(tbody);

      var foot = '<tr>';
      foot += '<td colspan="5" class="text-right">NET TOTALS:</td>';
      foot += '<td class="text-right text-dark">PKR ' + fmtNum(data.total_sale) + '</td>';
      foot += '<td class="text-right text-muted">PKR ' + fmtNum(data.total_cost) + '</td>';
      foot += '<td class="text-right ' + (data.net_profit >= 0 ? 'text-success' : 'text-danger') + '">PKR ' + fmtNum(data.net_profit) + '</td>';
      foot += '<td class="text-right ' + (data.margin_pct >= 0 ? 'text-success' : 'text-danger') + '">' + data.margin_pct + '%</td>';
      foot += '</tr>';
      $('#modalItemsFoot').html(foot);
    }).fail(function(){
      $('#modalLoading').html('<p class="text-danger my-3"><i class="fas fa-exclamation-triangle mr-1"></i> Failed to load invoice details.</p>');
    });
  });

  function esc(text) {
    if (!text) return '';
    return $('<div>').text(text).html();
  }
  function fmtNum(val) {
    return Number(val || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
