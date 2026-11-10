<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Sale Report';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

// ── Filters ──────────────────────────────────────────────────
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');

$sale_type = trim($_GET['sale_type'] ?? '');
if (!in_array($sale_type, ['counter', 'shop'], true)) { $sale_type = ''; }

// Order bookers
$all_order_bookers = $pdo->query("SELECT id, full_name, username FROM users WHERE role = 'order_booker' AND status = 1 ORDER BY full_name ASC")->fetchAll();
$ob_ids = normalizeIdList($_GET['order_booker_id'] ?? []);
$ob_ids = array_values(array_intersect($ob_ids, array_map('intval', array_column($all_order_bookers, 'id'))));
$ob_names = [];
foreach ($all_order_bookers as $obk) {
    if (in_array((int)$obk['id'], $ob_ids, true)) { $ob_names[] = $obk['full_name']; }
}
$ob_name = implode(', ', $ob_names);
$ob_label = $ob_name !== '' ? $ob_name : 'All Bookers';

// ── Load sale lines for the selected filters ────────────────
$sql = "SELECT si.sale_id, si.product_id, si.quantity, si.price, si.subtotal,
               si.cost_price,
               p.code AS product_code, p.name AS product_name,
               p.boxes_per_carton, p.purchase_price AS current_purchase_price,
               COALESCE(cat.name, 'Uncategorized') AS category_name,
               s.invoice_no, COALESCE(s.delivery_date, s.sale_date) AS report_date,
               s.created_by AS ob_id, COALESCE(u.full_name, '—') AS ob_name,
               c.area AS customer_area,
               CASE WHEN LOWER(c.area) = 'counter' THEN 1 ELSE 0 END AS is_counter
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        JOIN products p ON si.product_id = p.id
        LEFT JOIN categories cat ON p.category_id = cat.id
        LEFT JOIN users u ON u.id = s.created_by
        LEFT JOIN customers c ON c.id = s.customer_id
        WHERE s.status <> 'cancelled'";
$params = [];
if ($from !== '') { $sql .= " AND COALESCE(s.delivery_date, s.sale_date) >= ?"; $params[] = $from; }
if ($to !== '')   { $sql .= " AND COALESCE(s.delivery_date, s.sale_date) <= ?"; $params[] = $to; }
if ($sale_type === 'counter') {
    $sql .= " AND LOWER(c.area) = 'counter'";
} elseif ($sale_type === 'shop') {
    $sql .= " AND (LOWER(c.area) <> 'counter' OR c.area IS NULL)";
}
if ($ob_ids) {
    $sql .= " AND s.created_by IN (" . implode(',', array_fill(0, count($ob_ids), '?')) . ")";
    $params = array_merge($params, $ob_ids);
}
$sql .= " ORDER BY p.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$lines = $stmt->fetchAll();

// ── Completed returns per sale+product (so net qty is correct) ──
$sale_ids = array_values(array_unique(array_map('intval', array_column($lines, 'sale_id'))));
$returns = [];
if ($sale_ids) {
    foreach (array_chunk($sale_ids, 1000) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $rs = $pdo->prepare("
            SELECT COALESCE(sr.invoice_id, sr.sale_id) AS sale_id,
                   sri.product_id,
                   SUM(sri.quantity) AS ret_qty
            FROM sale_returns sr
            JOIN sale_return_items sri ON sri.return_id = sr.id
            WHERE sr.status = 'Completed'
              AND COALESCE(sr.invoice_id, sr.sale_id) IN ($ph)
            GROUP BY COALESCE(sr.invoice_id, sr.sale_id), sri.product_id
        ");
        $rs->execute($chunk);
        foreach ($rs->fetchAll() as $rrow) {
            $returns[$rrow['sale_id'] . ':' . $rrow['product_id']] = (int)$rrow['ret_qty'];
        }
    }
}

// ── Build rows grouped strictly by Product (One single row per product) ──
$remaining_returns = $returns;
$product_rows = [];
$total_sale = 0;
$total_cost = 0;
$total_qty  = 0;
$total_cartons = 0;
$total_loose = 0;

foreach ($lines as $ln) {
    $line_key = $ln['sale_id'] . ':' . $ln['product_id'];
    $qty = (int)$ln['quantity'];

    // Allocate any completed return against this line (FIFO per sale+product)
    $ret = $remaining_returns[$line_key] ?? 0;
    $take = min($qty, $ret);
    $remaining_returns[$line_key] = $ret - $take;
    $net = $qty - $take;
    if ($net <= 0) { continue; }

    $bpc = max(1, (int)$ln['boxes_per_carton']);
    $cost_per_box = $ln['cost_price'] !== null
        ? (float)$ln['cost_price']
        : ((float)$ln['current_purchase_price'] / $bpc);

    $rate = (float)$ln['price'];
    $pid  = (int)$ln['product_id'];

    if (!isset($product_rows[$pid])) {
        $product_rows[$pid] = [
            'product_id'    => $pid,
            'product_code'  => $ln['product_code'],
            'product_name'  => $ln['product_name'],
            'category_name' => $ln['category_name'],
            'bpc'           => $bpc,
            'qty'           => 0,
            'sale'          => 0,
            'cost'          => 0,
        ];
    }
    $line_sale = $net * $rate;
    $line_cost = $net * $cost_per_box;

    $product_rows[$pid]['qty']  += $net;
    $product_rows[$pid]['sale'] += $line_sale;
    $product_rows[$pid]['cost'] += $line_cost;

    $total_sale += $line_sale;
    $total_cost += $line_cost;
    $total_qty  += $net;
}

$rows = array_values($product_rows);

// Calculate Profit Margin %, Sale Margin %, cartons and loose
foreach ($rows as &$row) {
    $profit = $row['sale'] - $row['cost'];
    $row['profit_margin'] = $row['sale'] > 0 ? ($profit / $row['sale']) * 100 : 0;
    $row['sale_margin']   = $total_sale > 0 ? ($row['sale'] / $total_sale) * 100 : 0;

    $bpc = $row['bpc'];
    $qty = $row['qty'];
    if ($bpc > 1) {
        $c = intdiv($qty, $bpc);
        $l = $qty % $bpc;
        if ($c > 0 && $l > 0) {
            $info_text = "1x{$bpc} ({$c}C+{$l}B)";
        } elseif ($c > 0) {
            $info_text = "1x{$bpc} ({$c}C)";
        } else {
            $info_text = "1x{$bpc} ({$l}B)";
        }
    } else {
        $c = 0;
        $l = $qty;
        $info_text = "1x1";
    }
    $row['cartons']   = $c;
    $row['loose']     = $l;
    $row['info_text'] = $info_text;

    $total_cartons += $c;
    $total_loose   += $l;
}
unset($row);

// Sort alphabetically by Product Name
usort($rows, function ($a, $b) {
    return strcasecmp($a['product_name'], $b['product_name']);
});

$total_products = count($rows);
$overall_profit = $total_sale - $total_cost;
$overall_profit_margin = $total_sale > 0 ? ($overall_profit / $total_sale) * 100 : 0;

// Invoice discounts for the same filtered sales
$discount_sql = "SELECT COALESCE(SUM(s.discount_amount), 0) FROM sales s WHERE s.status <> 'cancelled'";
$discount_params = [];
if ($from !== '') { $discount_sql .= " AND COALESCE(s.delivery_date, s.sale_date) >= ?"; $discount_params[] = $from; }
if ($to !== '') { $discount_sql .= " AND COALESCE(s.delivery_date, s.sale_date) <= ?"; $discount_params[] = $to; }
if ($sale_type === 'counter') { $discount_sql .= " AND s.is_counter = 1"; }
elseif ($sale_type === 'shop') { $discount_sql .= " AND s.is_counter = 0"; }
if ($ob_ids) {
    $discount_sql .= " AND s.created_by IN (" . implode(',', array_fill(0, count($ob_ids), '?')) . ")";
    $discount_params = array_merge($discount_params, $ob_ids);
}
$discount_stmt = $pdo->prepare($discount_sql);
$discount_stmt->execute($discount_params);
$total_discount = (float)$discount_stmt->fetchColumn();
$net_total_sale = max(0, $total_sale - $total_discount);

// ── Filter labels for the header ──────────────────────
$dated_str = 'All Dates';
if ($from && $to) {
    $dated_str = ($from === $to) ? date('d-m-Y', strtotime($from)) : date('d-m-Y', strtotime($from)) . ' to ' . date('d-m-Y', strtotime($to));
} elseif ($from) {
    $dated_str = 'From ' . date('d-m-Y', strtotime($from));
} elseif ($to) {
    $dated_str = 'Until ' . date('d-m-Y', strtotime($to));
}

$sale_type_label = 'All Sales';
if ($sale_type === 'counter') {
    $sale_type_label = 'Counter Sales';
} elseif ($sale_type === 'shop') {
    $sale_type_label = 'Shop Orders';
}

$voucher_no = 'SALE/' . date('ymd') . ($ob_ids ? '-' . implode(',', $ob_ids) : '');

$printed_by = '';
if (!empty($_SESSION['user_id'])) {
    $pu = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $pu->execute([(int)$_SESSION['user_id']]);
    $printed_by = (string)$pu->fetchColumn();
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<style>
/* ============================================================
   SCREEN STYLES
   ============================================================ */
.kpi-stat-card { border-radius: 8px; }
.sale-share-badge {
  background-color: #f1f5f9;
  color: #0f172a;
  border-radius: 4px;
  padding: 3px 8px;
  font-weight: 600;
  display: inline-block;
  font-size: 0.85rem;
}

/* ============================================================
   PRINT STYLES (Packlist 1:1 Matching Ultra-Compact Header & Table)
   ============================================================ */
@media print {
  @page {
    size: A4 portrait;
    margin: 8mm 6mm 10mm 6mm;
    @bottom-left {
      content: "Mehboob Traders \B7  Sale Report";
      font-size: 8pt;
      font-family: Arial, Helvetica, sans-serif;
      color: #64748b;
    }
    @bottom-right {
      content: "Page " counter(page) " of " counter(pages);
      font-size: 8.5pt;
      font-weight: bold;
      font-family: Arial, Helvetica, sans-serif;
      color: #0f172a;
    }
  }

  html, body {
    width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    background: #ffffff !important;
    color: #000000 !important;
    font-size: 11px !important;
    line-height: 1.2 !important;
    font-family: Arial, Helvetica, sans-serif !important;
  }

  #wrapper, #content-wrapper, .content, .container-fluid {
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    display: block !important;
  }

  .content > .d-sm-flex {
    display: none !important;
  }

  .table-responsive {
    overflow: visible !important;
    display: block !important;
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    border: none !important;
  }

  .sidebar, .navbar, .topbar, .card-header, form, .btn, .d-print-none, #scrollToTop, footer, .sticky-footer, .sidebar-overlay {
    display: none !important;
  }

  .card {
    border: none !important;
    box-shadow: none !important;
    padding: 0 !important;
    margin: 0 !important;
    background: transparent !important;
  }
  .card-body {
    padding: 0 !important;
    margin: 0 !important;
  }

  /* Header Box (Packlist Grey Box Style) */
  .dsr-header-box {
    background-color: #f1f5f9 !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    border: 1.5px solid #000000 !important;
    padding: 5px 8px !important;
    text-align: center !important;
    margin-bottom: 6px !important;
    width: 100% !important;
    box-sizing: border-box !important;
  }
  .dsr-brand-title {
    font-size: 16px !important;
    font-weight: 900 !important;
    color: #000000 !important;
    letter-spacing: 0.5px !important;
    line-height: 1.2 !important;
  }
  .dsr-brand-subtitle {
    font-size: 12.5px !important;
    font-weight: 800 !important;
    color: #000000 !important;
    line-height: 1.2 !important;
  }

  /* 3-Column Metadata Header Grid (Packlist Style) */
  .dsr-meta-grid {
    display: flex !important;
    justify-content: space-between !important;
    align-items: flex-start !important;
    border-bottom: 1.5px solid #000000 !important;
    padding-bottom: 5px !important;
    margin-bottom: 6px !important;
    font-size: 10px !important;
    line-height: 1.35 !important;
    width: 100% !important;
    box-sizing: border-box !important;
  }
  .dsr-meta-col {
    flex: 1 !important;
  }
  .dsr-meta-row {
    margin-bottom: 2px !important;
  }
  .dsr-meta-col .meta-lbl {
    color: #000000 !important;
    font-weight: 700 !important;
    display: inline-block !important;
    min-width: 75px !important;
  }
  .dsr-meta-col .meta-val {
    color: #000000 !important;
    font-size: 11px !important;
    font-weight: 700 !important;
  }

  .dsr-booking-box {
    border: 1.5px solid #000000 !important;
    padding: 2px 8px !important;
    background: #ffffff !important;
    display: inline-block !important;
  }
  .dsr-booking-lbl {
    font-size: 10.5px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
  }
  .dsr-booking-val {
    font-size: 13.5px !important;
    font-weight: 900 !important;
    color: #000000 !important;
    margin-left: 4px !important;
  }

  /* Packlist Print Table Format */
  .dsr-load-table {
    border: 1.5px solid #000000 !important;
    margin-bottom: 6px !important;
    table-layout: fixed !important;
    width: 100% !important;
    max-width: 100% !important;
    border-collapse: collapse !important;
    box-sizing: border-box !important;
  }
  .dsr-load-table thead {
    display: table-header-group !important;
  }
  .dsr-load-table tbody {
    display: table-row-group !important;
  }

  /* Fixed column widths strictly for thead th (Sums exactly to 100%) */
  .dsr-load-table thead th:nth-child(1) { width: 4% !important; text-align: center !important; }
  .dsr-load-table thead th:nth-child(2) { width: 33% !important; text-align: left !important; }
  .dsr-load-table thead th:nth-child(3) { width: 16% !important; text-align: center !important; }
  .dsr-load-table thead th:nth-child(4) { width: 11% !important; text-align: center !important; }
  .dsr-load-table thead th:nth-child(5) { width: 18% !important; text-align: right !important; }
  .dsr-load-table thead th:nth-child(6) { width: 9% !important; text-align: right !important; }
  .dsr-load-table thead th:nth-child(7) { width: 9% !important; text-align: right !important; }

  .dsr-load-table th {
    background-color: #f1f5f9 !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    border: 1px solid #000000 !important;
    padding: 4px 3px !important;
    font-size: 9.5px !important;
    font-weight: 800 !important;
    color: #000000 !important;
    text-transform: uppercase !important;
    vertical-align: middle !important;
    white-space: normal !important;
    word-break: normal !important;
    line-height: 1.2 !important;
    overflow: hidden !important;
    box-sizing: border-box !important;
  }
  .dsr-load-table td {
    border: 1px solid #000000 !important;
    padding: 3px 4px !important;
    font-size: 10.5px !important;
    color: #000000 !important;
    background: transparent !important;
    vertical-align: middle !important;
    white-space: normal !important;
    word-break: break-word !important;
    line-height: 1.2 !important;
    box-sizing: border-box !important;
  }
  .dsr-item-row {
    page-break-inside: avoid !important;
  }

  .code-badge {
    color: #000000 !important;
    font-size: 10.5px !important;
    font-weight: 700 !important;
  }
  .product-title {
    color: #000000 !important;
    font-size: 11px !important;
    font-weight: 700 !important;
  }
  .info-pill {
    background: transparent !important;
    border: none !important;
    padding: 0 !important;
    font-size: 10.5px !important;
    color: #000000 !important;
    white-space: nowrap !important;
  }
  .issued-badge {
    background: transparent !important;
    border: none !important;
    padding: 0 !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    color: #000000 !important;
  }
  .amount-val {
    color: #000000 !important;
    font-size: 10.5px !important;
    font-weight: 700 !important;
    white-space: nowrap !important;
  }
  .margin-val, .share-val {
    color: #000000 !important;
    font-size: 10.5px !important;
    font-weight: 700 !important;
    white-space: nowrap !important;
  }
  .sale-share-badge {
    background: transparent !important;
    border: none !important;
    padding: 0 !important;
    color: #000000 !important;
    font-weight: 700 !important;
  }

  .dsr-total-row th {
    border-top: 1.5px solid #000000 !important;
    border-bottom: 1.5px solid #000000 !important;
    background-color: #f1f5f9 !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    font-size: 10.5px !important;
    padding: 4px 4px !important;
    color: #000000 !important;
    font-weight: 900 !important;
    white-space: nowrap !important;
  }

  /* Signatures Grid (Packlist Style) */
  .dsr-signatures-grid {
    margin-top: 12px !important;
    border-top: none !important;
    padding-top: 0 !important;
    page-break-inside: avoid !important;
  }
  .sig-line {
    border-top: 1px dashed #000000 !important;
    padding-top: 3px !important;
    font-size: 10px !important;
    font-weight: 700 !important;
    color: #000000 !important;
  }

  /* Sheet Footer (Packlist Style) */
  .dsr-sheet-footer {
    border-top: 1px solid #000000 !important;
    margin-top: 6px !important;
    padding-top: 3px !important;
    font-size: 9.5px !important;
    color: #000000 !important;
    page-break-inside: avoid !important;
  }
}
</style>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center d-print-none">
    <h6 class="mb-0 font-weight-bold text-primary">
      <i class="fas fa-chart-pie mr-1"></i> Sale Report
      <span class="badge badge-primary ml-1"><?=count($rows)?> products</span>
    </h6>
    <div class="d-flex flex-wrap align-items-center mt-2 mt-sm-0">
      <?php if ($from || $to || $sale_type !== '' || $ob_ids): ?>
      <a href="sale_report.php" class="btn btn-sm btn-outline-danger mr-2"><i class="fas fa-undo mr-1"></i> Reset Filters</a>
      <?php endif; ?>
      <a href="packlist.php" class="btn btn-sm btn-outline-success mr-2"><i class="fas fa-truck-loading mr-1"></i> Delivery List</a>
      <a href="item_wise_report.php" class="btn btn-sm btn-outline-info mr-2"><i class="fas fa-boxes mr-1"></i> Item-wise Report</a>
      <a href="invoices.php" class="btn btn-sm btn-outline-secondary mr-2"><i class="fas fa-file-invoice mr-1"></i> Invoices</a>
      <button type="button" class="btn btn-sm btn-primary" onclick="window.print()"><i class="fas fa-print mr-1"></i> Print Report</button>
    </div>
  </div>

  <div class="card-body">

    <!-- PRINT-ONLY HEADER (Exact Packlist Format: Top Header Box + 3-Column Meta Grid) -->
    <div class="d-none d-print-block">
      <!-- Top Grey Header Box -->
      <div class="dsr-header-box">
        <div class="dsr-brand-title">MEHBOOB TRADERS</div>
        <div class="dsr-brand-subtitle">SALE REPORT (ORDER BOOKER WISE)</div>
      </div>

      <!-- 3-Column Metadata Header Grid -->
      <div class="dsr-meta-grid">
        <!-- Left Column -->
        <div class="dsr-meta-col dsr-meta-left">
          <div class="dsr-meta-row">
            <span class="meta-lbl">Order Booker:</span>
            <span class="meta-val"><?=htmlspecialchars($ob_label)?></span>
          </div>
          <div class="dsr-meta-row">
            <span class="meta-lbl">Sale Type:</span>
            <span class="meta-val"><?=htmlspecialchars($sale_type_label)?></span>
          </div>
          <div class="dsr-meta-row">
            <span class="meta-lbl">Total Items:</span>
            <span class="meta-val"><?=number_format($total_products)?> Products</span>
          </div>
        </div>

        <!-- Middle Column -->
        <div class="dsr-meta-col dsr-meta-mid text-center">
          <div class="dsr-booking-box">
            <span class="dsr-booking-lbl">Total Sale:</span>
            <span class="dsr-booking-val">PKR <?=formatCurrency($total_sale)?></span>
            <?php if ($total_discount > 0): ?>
            <span class="small font-weight-bold ml-1" style="font-size: 11px;">(Net: PKR <?=formatCurrency($net_total_sale)?>)</span>
            <?php endif; ?>
          </div>
          <div class="dsr-meta-row mt-1">
            <span class="meta-lbl">Total Boxes:</span>
            <span class="meta-val font-weight-bold"><?=number_format($total_qty)?> (<?=$total_cartons?>C + <?=$total_loose?>B)</span>
          </div>
          <div class="dsr-meta-row">
            <span class="meta-lbl">Avg Profit Margin:</span>
            <span class="meta-val"><?=number_format($overall_profit_margin, 1)?>%</span>
          </div>
        </div>

        <!-- Right Column -->
        <div class="dsr-meta-col dsr-meta-right text-right">
          <div class="dsr-meta-row">
            <span class="meta-lbl">Dated:</span>
            <span class="meta-val"><?=htmlspecialchars($dated_str)?></span>
          </div>
          <div class="dsr-meta-row">
            <span class="meta-lbl">Voucher No:</span>
            <span class="meta-val"><?=htmlspecialchars($voucher_no)?></span>
          </div>
          <div class="dsr-meta-row">
            <span class="meta-lbl">Printed On:</span>
            <span class="meta-val"><?=date('d-m-Y H:i')?></span>
          </div>
        </div>
      </div>
    </div>
    <!-- /Print-Only Header -->

    <!-- Filter Card (Screen Only) -->
    <div class="card bg-light border shadow-sm mb-4 d-print-none">
      <div class="card-body py-3 px-3">
        <form method="get" id="saleReportFilterForm" class="row g-2 align-items-end">
          <div class="col-lg-2 col-md-4 col-6 mb-2">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="far fa-calendar-alt"></i> From Date</label>
            <input type="date" name="from" class="form-control" style="height: 38px;" value="<?=htmlspecialchars($from)?>" onchange="document.getElementById('saleReportFilterForm').submit();">
          </div>
          <div class="col-lg-2 col-md-4 col-6 mb-2">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="far fa-calendar-alt"></i> To Date</label>
            <input type="date" name="to" class="form-control" style="height: 38px;" value="<?=htmlspecialchars($to)?>" onchange="document.getElementById('saleReportFilterForm').submit();">
          </div>
          <div class="col-lg-2 col-md-4 col-6 mb-2">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="fas fa-cash-register text-warning"></i> Sale Type</label>
            <select name="sale_type" class="form-control" style="height: 38px;" onchange="document.getElementById('saleReportFilterForm').submit();">
              <option value="">-- All Sale Types --</option>
              <option value="counter" <?= $sale_type === 'counter' ? 'selected' : '' ?>>Counter Sales</option>
              <option value="shop" <?= $sale_type === 'shop' ? 'selected' : '' ?>>Shop Orders</option>
            </select>
          </div>
          <div class="col-lg-3 col-md-5 col-12 mb-2">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="fas fa-user-tag text-info"></i> Order Booker <small class="font-weight-normal">(select one or more)</small></label>
            <div class="dropdown w-100">
              <button class="btn btn-outline-secondary dropdown-toggle w-100 text-truncate text-left" type="button" id="obFilterBtn" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="height: 38px;" title="<?=htmlspecialchars($ob_name !== '' ? $ob_name : 'All Order Bookers')?>">
                <i class="fas fa-user-tag"></i> <span id="obFilterLabel"><?php if (!$ob_ids) { echo '-- All Order Bookers --'; } elseif (count($ob_ids) === 1) { echo htmlspecialchars($ob_name); } else { echo count($ob_ids) . ' Selected'; } ?></span>
              </button>
              <div class="dropdown-menu dropdown-menu-right p-0 w-100" style="min-width: 240px;">
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                  <span class="small font-weight-bold text-muted text-uppercase">Order Bookers</span>
                  <a href="#" class="small text-primary" id="obFilterClear">Clear</a>
                </div>
                <div class="p-1" style="max-height: 220px; overflow-y: auto;">
                  <?php foreach ($all_order_bookers as $obk): ?>
                  <label class="d-flex align-items-center px-2 py-1 mb-0" style="cursor:pointer;">
                    <input class="form-check-input mr-2 mt-0 ob-filter-cb" type="checkbox" name="order_booker_id[]" value="<?=(int)$obk['id']?>" <?= in_array((int)$obk['id'], $ob_ids, true) ? 'checked' : '' ?>>
                    <span class="small text-truncate"><?=htmlspecialchars($obk['full_name'])?></span>
                  </label>
                  <?php endforeach; ?>
                  <?php if (!$all_order_bookers): ?>
                  <div class="small text-muted px-2 py-2">No active order bookers</div>
                  <?php endif; ?>
                </div>
                <div class="px-3 py-2 border-top">
                  <button type="submit" class="btn btn-primary btn-sm btn-block"><i class="fas fa-check mr-1"></i> Apply</button>
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-3 col-md-3 col-12 mb-2">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="fas fa-search text-primary"></i> Search Product</label>
            <input type="text" id="reportSearch" class="form-control" style="height: 38px;" placeholder="Search product name, code, category..." autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault();}">
          </div>
        </form>
      </div>
    </div>

    <!-- KPI Cards Row (Screen Only) -->
    <div class="row g-3 mb-4 d-print-none">
      <div class="col-xl-3 col-md-6 col-6 mb-3">
        <div class="card border-left-primary shadow-sm h-100 kpi-stat-card">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Products Sold</div>
            <div class="h5 mb-0 font-weight-bold text-gray-800"><?=number_format($total_products)?></div>
          </div>
        </div>
      </div>
      <div class="col-xl-3 col-md-6 col-6 mb-3">
        <div class="card border-left-secondary shadow-sm h-100 kpi-stat-card">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">Total Quantity Sold</div>
            <div class="h5 mb-0 font-weight-bold text-gray-800"><?=number_format($total_qty)?> Boxes</div>
            <small class="text-muted"><?=$total_cartons?> Cartons + <?=$total_loose?> Loose</small>
          </div>
        </div>
      </div>
      <div class="col-xl-3 col-md-6 col-6 mb-3">
        <div class="card border-left-info shadow-sm h-100 kpi-stat-card">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Sale</div>
            <div class="h5 mb-0 font-weight-bold text-primary">PKR <?=formatCurrency($total_sale)?></div>
            <?php if ($total_discount > 0): ?>
            <div class="small font-weight-bold text-muted">Net: <span class="text-dark font-weight-bold">PKR <?=formatCurrency($net_total_sale)?></span> <small class="text-muted">(Disc: PKR <?=formatCurrency($total_discount)?>)</small></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-xl-3 col-md-6 col-6 mb-3">
        <div class="card border-left-success shadow-sm h-100 kpi-stat-card">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Avg Profit Margin</div>
            <div class="h5 mb-0 font-weight-bold <?= $overall_profit_margin >= 0 ? 'text-success' : 'text-danger' ?>"><?=number_format($overall_profit_margin, 1)?>%</div>
          </div>
        </div>
      </div>
    </div>

    <?php if (!$rows): ?>
      <div class="alert alert-light border text-center py-5 shadow-sm rounded">
        <i class="fas fa-boxes text-muted fa-3x mb-3 d-block"></i>
        <h5 class="text-muted font-weight-bold">No sales records found</h5>
        <p class="text-muted small mb-0">No active sales records were found for the selected date, sale type, or order booker filters.</p>
      </div>
    <?php else: ?>
    <!-- Table Styled to Packlist Format in Print -->
    <div class="table-responsive w-100" style="overflow: visible;">
      <table class="table table-bordered table-hover dsr-load-table w-100 mb-0" id="saleReportTable">
        <thead class="bg-light text-dark">
          <tr>
            <th class="text-center" style="width: 4%;">#</th>
            <th style="width: 33%;">Item / Product Name</th>
            <th class="text-center" style="width: 16%;">Packaging<span class="d-none d-print-inline"><br></span><span class="d-print-none"> </span>Info</th>
            <th class="text-center" style="width: 11%;">Qty<span class="d-none d-print-inline"><br></span><span class="d-print-none"> </span>(Boxes)</th>
            <th class="text-right" style="width: 18%;">Sale<span class="d-none d-print-inline"><br></span><span class="d-print-none"> </span>Amount</th>
            <th class="text-right" style="width: 9%;">Profit<span class="d-none d-print-inline"><br></span><span class="d-print-none"> </span>Margin %</th>
            <th class="text-right" style="width: 9%;">Sale<span class="d-none d-print-inline"><br></span><span class="d-print-none"> </span>Margin %</th>
          </tr>
        </thead>
        <tbody id="saleReportTableBody">
          <?php foreach ($rows as $idx => $row): ?>
          <tr class="report-row dsr-item-row"
              data-search="<?=htmlspecialchars(strtolower($row['product_code'] . ' ' . $row['product_name'] . ' ' . $row['category_name']))?>">
            <td class="text-center text-muted small"><?=$idx + 1?></td>
            <td class="text-left">
              <?php if (!empty($row['product_code'])): ?>
                <span class="font-weight-bold code-badge"><?=htmlspecialchars($row['product_code'])?>:</span>
              <?php endif; ?>
              <span class="product-title"><?=htmlspecialchars($row['product_name'])?></span>
              <small class="text-muted d-block d-print-none"><?=htmlspecialchars($row['category_name'])?></small>
            </td>
            <td class="text-center">
              <span class="info-pill"><?=htmlspecialchars($row['info_text'])?></span>
            </td>
            <td class="text-center font-weight-bold">
              <span class="issued-badge"><?=number_format($row['qty'])?></span>
            </td>
            <td class="text-right font-weight-bold text-dark">
              <span class="amount-val">PKR <?=formatCurrency($row['sale'])?></span>
            </td>
            <td class="text-right font-weight-bold <?= $row['profit_margin'] >= 0 ? 'text-success' : 'text-danger' ?>">
              <span class="margin-val"><?=number_format($row['profit_margin'], 1)?>%</span>
            </td>
            <td class="text-right">
              <span class="sale-share-badge share-val text-primary font-weight-bold">
                <?=number_format($row['sale_margin'], 2)?>%
              </span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="dsr-total-row">
            <th class="text-center font-weight-bold"></th>
            <th class="text-left font-weight-bold">TOTAL (<?=number_format($total_products)?> Products)</th>
            <th class="text-center font-weight-bold text-secondary"><?=$total_cartons?>C + <?=$total_loose?>B</th>
            <th class="text-center font-weight-bold text-dark" style="font-size: 1.05rem;"><?=number_format($total_qty)?></th>
            <th class="text-right font-weight-bold text-success" style="font-size: 1.05rem;">PKR <?=formatCurrency($total_sale)?></th>
            <th class="text-right font-weight-bold <?= $overall_profit_margin >= 0 ? 'text-success' : 'text-danger' ?>">
              <?=number_format($overall_profit_margin, 1)?>%
            </th>
            <th class="text-right text-primary font-weight-bold">100.00%</th>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- Printable Signatures Grid (Packlist Style) -->
    <div class="dsr-signatures-grid mt-4 d-none d-print-block">
      <div class="row text-center">
        <div class="col-4">
          <div class="sig-line">Order Booker Signature</div>
        </div>
        <div class="col-4">
          <div class="sig-line">Accounts / Audit Signature</div>
        </div>
        <div class="col-4">
          <div class="sig-line">Authorized Signature</div>
        </div>
      </div>
    </div>

    <!-- Printable Sheet Footer (Packlist Style) -->
    <div class="dsr-sheet-footer d-flex justify-content-between align-items-center mt-3 pt-2 d-none d-print-block">
      <div><strong>Prepared by:</strong> <?=htmlspecialchars($printed_by ?: 'Admin')?></div>
      <div>Mehboob Traders &middot; Sale Report</div>
      <div>Page 1 of 1</div>
    </div>
    <?php endif; ?>

  </div>
</div>

<script>
$(document).ready(function(){
  // ── Order booker multi-select: keep button label in sync ──
  function refreshObFilterLabel() {
    var checked = $('.ob-filter-cb:checked');
    var n = checked.length;
    var label;
    if (n === 0) {
      label = '-- All Order Bookers --';
    } else if (n === 1) {
      label = $.trim(checked.closest('label').find('span').text());
    } else {
      label = n + ' Selected';
    }
    $('#obFilterLabel').text(label);
    $('#obFilterBtn').attr('title', n === 0 ? 'All Order Bookers' : checked.closest('label').find('span').text());
  }

  $('.ob-filter-cb').on('change', refreshObFilterLabel);

  $('#obFilterClear').on('click', function(e){
    e.preventDefault();
    e.stopPropagation();
    $('.ob-filter-cb').prop('checked', false);
    refreshObFilterLabel();
  });

  $(document).on('click', '.dropdown-menu label, .dropdown-menu .ob-filter-cb', function(e){
    e.stopPropagation();
  });

  refreshObFilterLabel();

  // ── Live product search ──
  $('#reportSearch').on('input keyup paste', function(){
    var terms = $.trim($(this).val()).toLowerCase().split(/\s+/).filter(Boolean);
    $('#saleReportTableBody tr.report-row').each(function(){
      var haystack = ($(this).data('search') || $(this).text()).toString().toLowerCase();
      var match = true;
      for (var i = 0; i < terms.length; i++) {
        if (haystack.indexOf(terms[i]) === -1) { match = false; break; }
      }
      $(this).toggle(match);
    });
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
