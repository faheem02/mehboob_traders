<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Customer Credits (Order Booker Wise)';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin', 'order_booker']);

$is_admin = isAdmin();
$current_uid = (int)($_SESSION['user_id'] ?? 0);

// Active Order Bookers
$bookers = $pdo->query("
    SELECT u.id, u.full_name, u.phone, e.area
    FROM users u
    LEFT JOIN employees e ON e.user_id = u.id
    WHERE u.role = 'order_booker' AND u.status = 1
    ORDER BY u.full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$booker_map = [];
foreach ($bookers as $b) {
    $booker_map[(int)$b['id']] = $b;
}

// Compute credit summary stats for each booker
$booker_stats = [];
foreach ($bookers as $b) {
    $bid = (int)$b['id'];
    $b_areas = array_filter(array_map('trim', explode(',', (string)$b['area'])));
    $conds = [
        "c.created_by = $bid",
        "c.id IN (SELECT customer_id FROM sales WHERE created_by = $bid AND status <> 'cancelled')"
    ];
    if ($b_areas) {
        $ph = implode(',', array_map(fn($a) => $pdo->quote(strtolower($a)), $b_areas));
        $conds[] = "LOWER(c.area) IN ($ph)";
    }
    $c_sql = "SELECT COUNT(DISTINCT c.id) AS cnt, COALESCE(SUM(c.current_balance), 0) AS bal
              FROM customers c
              WHERE c.status = 1 AND c.current_balance > 0 AND (" . implode(' OR ', $conds) . ")";
    $st = $pdo->query($c_sql)->fetch(PDO::FETCH_ASSOC);
    $booker_stats[$bid] = [
        'count' => (int)($st['cnt'] ?? 0),
        'total' => (float)($st['bal'] ?? 0),
    ];
}

// Selected Order Booker
$selected_ob = null;
if (!$is_admin) {
    $ob_id = $current_uid;
    $selected_ob = $booker_map[$ob_id] ?? null;
} else {
    $ob_id = isset($_GET['order_booker_id']) && $_GET['order_booker_id'] !== '' ? (int)$_GET['order_booker_id'] : 0;
    if ($ob_id > 0 && isset($booker_map[$ob_id])) {
        $selected_ob = $booker_map[$ob_id];
    } else {
        $ob_id = 0;
    }
}

// Available Areas for the current filter
$available_areas = [];
if ($selected_ob && !empty($selected_ob['area'])) {
    $available_areas = array_values(array_filter(array_map('trim', explode(',', $selected_ob['area']))));
    sort($available_areas);
} else {
    $available_areas = allKnownAreas($pdo);
}

$area = trim($_GET['area'] ?? '');
if ($area !== '' && !in_array($area, $available_areas, true)) {
    // Check case-insensitive
    $matched_area = '';
    foreach ($available_areas as $avail) {
        if (strcasecmp($avail, $area) === 0) {
            $matched_area = $avail;
            break;
        }
    }
    $area = $matched_area;
}

// Build query
$where = ["c.status = 1", "c.current_balance > 0"];
$params = [];

if ($ob_id > 0 && $selected_ob) {
    $ob_areas = array_filter(array_map('trim', explode(',', (string)$selected_ob['area'])));
    $ob_conds = [
        "c.created_by = ?",
        "c.id IN (SELECT customer_id FROM sales WHERE created_by = ? AND status <> 'cancelled')"
    ];
    $params[] = $ob_id;
    $params[] = $ob_id;

    if ($ob_areas) {
        $ph = implode(',', array_fill(0, count($ob_areas), 'LOWER(?)'));
        $ob_conds[] = "LOWER(c.area) IN ($ph)";
        foreach ($ob_areas as $oa) {
            $params[] = strtolower($oa);
        }
    }
    $where[] = "(" . implode(" OR ", $ob_conds) . ")";
}

if ($area !== '') {
    $where[] = "LOWER(c.area) = LOWER(?)";
    $params[] = $area;
}

$where_clause = implode(" AND ", $where);

$sql = "SELECT c.id, c.customer_no, c.full_name, c.phone, c.area, c.current_balance, c.created_by,
               COUNT(DISTINCT CASE WHEN s.due_amount > 0 THEN s.id END) AS due_invoices_cnt,
               MAX(s.sale_date) AS last_sale_date,
               (
                   SELECT u.full_name
                   FROM sales s2
                   JOIN users u ON u.id = s2.created_by
                   WHERE s2.customer_id = c.id AND s2.status <> 'cancelled'
                   ORDER BY s2.id DESC
                   LIMIT 1
               ) AS last_booker_name
        FROM customers c
        LEFT JOIN sales s ON s.customer_id = c.id AND s.status <> 'cancelled'
        WHERE $where_clause
        GROUP BY c.id
        ORDER BY c.current_balance DESC, c.full_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totals
$total_credit_customers = count($customers);
$total_credit_amount = 0.0;
$total_due_invoices = 0;
foreach ($customers as $c) {
    $total_credit_amount += (float)$c['current_balance'];
    $total_due_invoices += (int)$c['due_invoices_cnt'];
}
$avg_balance = $total_credit_customers > 0 ? ($total_credit_amount / $total_credit_customers) : 0.0;

// Filter labels for header / print
$ob_label = $selected_ob ? $selected_ob['full_name'] : 'All Order Bookers';
$area_label = $area !== '' ? $area : 'All Areas';
$voucher_no = 'REC/' . date('ymd') . ($ob_id > 0 ? '-' . $ob_id : '');

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
.badge-area {
  background-color: #f1f5f9;
  color: #334155;
  border: 1px solid #cbd5e1;
  font-weight: 600;
  padding: 3px 8px;
  font-size: 0.8rem;
  border-radius: 4px;
}
.balance-val {
  font-size: 1.05rem;
  font-weight: 800;
  color: #b91c1c;
}

/* ============================================================
   PRINT STYLES (Packlist 1:1 Matching Ultra-Compact Header & Table)
   ============================================================ */
@media print {
  @page {
    size: A4 portrait;
    margin: 8mm 6mm 10mm 6mm;
    @bottom-left {
      content: "Mehboob Traders \B7  Customer Credit / Recovery Sheet";
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
    min-width: 80px !important;
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

  /* Fixed column widths strictly for thead th (Exact 100% Sum) */
  .dsr-load-table thead th:nth-child(1) { width: 4% !important; text-align: center !important; }
  .dsr-load-table thead th:nth-child(2) { width: 13% !important; text-align: center !important; }
  .dsr-load-table thead th:nth-child(3) { width: 30% !important; text-align: left !important; }
  .dsr-load-table thead th:nth-child(4) { width: 18% !important; text-align: left !important; }
  .dsr-load-table thead th:nth-child(5) { width: 15% !important; text-align: center !important; }
  .dsr-load-table thead th:nth-child(6) { width: 7% !important; text-align: center !important; }
  .dsr-load-table thead th:nth-child(7) { width: 13% !important; text-align: right !important; }

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

  .badge-area {
    background: transparent !important;
    border: none !important;
    padding: 0 !important;
    font-size: 10px !important;
    color: #000000 !important;
  }
  .balance-val {
    color: #000000 !important;
    font-size: 11px !important;
    font-weight: 900 !important;
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
    margin-top: 14px !important;
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
      <i class="fas fa-file-invoice-dollar mr-1"></i> Customer Credits (Order Booker Wise)
      <span class="badge badge-danger ml-1"><?=number_format($total_credit_customers)?> Customers with Credit</span>
    </h6>
    <div class="d-flex flex-wrap align-items-center mt-2 mt-sm-0">
      <?php if ($ob_id > 0 || $area !== ''): ?>
      <a href="customer_credits.php" class="btn btn-sm btn-outline-danger mr-2"><i class="fas fa-undo mr-1"></i> Reset Filters</a>
      <?php endif; ?>
      <a href="../transactions/receive_customer.php" class="btn btn-sm btn-outline-success mr-2"><i class="fas fa-hand-holding-usd mr-1"></i> Receive Payment</a>
      <a href="../sales/customer_summary.php" class="btn btn-sm btn-outline-info mr-2"><i class="fas fa-chart-bar mr-1"></i> Customer Summary</a>
      <a href="customers.php" class="btn btn-sm btn-outline-secondary mr-2"><i class="fas fa-users mr-1"></i> View Customers</a>
      <button type="button" class="btn btn-sm btn-primary" onclick="window.print()"><i class="fas fa-print mr-1"></i> Print Credit List</button>
    </div>
  </div>

  <div class="card-body">

    <!-- PRINT-ONLY HEADER (Packlist Format) -->
    <div class="d-none d-print-block">
      <!-- Top Grey Header Box -->
      <div class="dsr-header-box">
        <div class="dsr-brand-title">MEHBOOB TRADERS</div>
        <div class="dsr-brand-subtitle">CUSTOMER CREDIT / OUTSTANDING RECOVERY SHEET</div>
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
            <span class="meta-lbl">Area Scoped:</span>
            <span class="meta-val"><?=htmlspecialchars($area_label)?></span>
          </div>
          <div class="dsr-meta-row">
            <span class="meta-lbl">Credit Accounts:</span>
            <span class="meta-val"><?=number_format($total_credit_customers)?> Customers</span>
          </div>
        </div>

        <!-- Middle Column -->
        <div class="dsr-meta-col dsr-meta-mid text-center">
          <div class="dsr-booking-box">
            <span class="dsr-booking-lbl">Total Outstanding:</span>
            <span class="dsr-booking-val text-danger">PKR <?=formatCurrency($total_credit_amount)?></span>
          </div>
          <div class="dsr-meta-row mt-1">
            <span class="meta-lbl">Due Invoices:</span>
            <span class="meta-val font-weight-bold"><?=number_format($total_due_invoices)?> Invoices</span>
          </div>
          <div class="dsr-meta-row">
            <span class="meta-lbl">Avg Outstanding:</span>
            <span class="meta-val">PKR <?=formatCurrency($avg_balance)?></span>
          </div>
        </div>

        <!-- Right Column -->
        <div class="dsr-meta-col dsr-meta-right text-right">
          <div class="dsr-meta-row">
            <span class="meta-lbl">Dated:</span>
            <span class="meta-val"><?=date('d-m-Y')?></span>
          </div>
          <div class="dsr-meta-row">
            <span class="meta-lbl">Sheet Ref:</span>
            <span class="meta-val"><?=htmlspecialchars($voucher_no)?></span>
          </div>
          <div class="dsr-meta-row">
            <span class="meta-lbl">Printed At:</span>
            <span class="meta-val"><?=date('d-m-Y H:i')?></span>
          </div>
        </div>
      </div>
    </div>
    <!-- /Print-Only Header -->

    <!-- Filter Card (Screen Only) -->
    <div class="card bg-light border shadow-sm mb-4 d-print-none">
      <div class="card-body py-3 px-3">
        <form method="get" id="creditFilterForm" class="row g-2 align-items-end">
          <?php if ($is_admin): ?>
          <div class="col-lg-4 col-md-5 col-12 mb-2">
            <label class="small font-weight-bold text-muted mb-1 d-block">
              <i class="fas fa-user-tie text-primary mr-1"></i> Select Order Booker
            </label>
            <select name="order_booker_id" class="form-control" style="height: 38px;" onchange="document.getElementById('creditFilterForm').submit();">
              <option value="">-- All Order Bookers (<?=array_sum(array_column($booker_stats, 'count'))?> Credit Cust) --</option>
              <?php foreach ($bookers as $bk): ?>
                <?php
                  $b_id = (int)$bk['id'];
                  $b_count = $booker_stats[$b_id]['count'] ?? 0;
                  $b_total = $booker_stats[$b_id]['total'] ?? 0;
                ?>
                <option value="<?=$b_id?>" <?= $ob_id === $b_id ? 'selected' : '' ?>>
                  <?=htmlspecialchars($bk['full_name'])?> (<?=$b_count?> Cust &middot; PKR <?=formatCurrency($b_total)?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php else: ?>
          <div class="col-lg-4 col-md-5 col-12 mb-2">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="fas fa-user-tie text-primary mr-1"></i> Order Booker</label>
            <input type="text" class="form-control" style="height: 38px;" readonly value="<?=htmlspecialchars($selected_ob['full_name'] ?? 'Order Booker')?>">
            <input type="hidden" name="order_booker_id" value="<?=$ob_id?>">
          </div>
          <?php endif; ?>

          <div class="col-lg-3 col-md-3 col-6 mb-2">
            <label class="small font-weight-bold text-muted mb-1 d-block">
              <i class="fas fa-map-marker-alt text-danger mr-1"></i> Area
            </label>
            <select name="area" class="form-control" style="height: 38px;" onchange="document.getElementById('creditFilterForm').submit();">
              <option value="">-- All Areas --</option>
              <?php foreach ($available_areas as $ar): ?>
              <option value="<?=htmlspecialchars($ar)?>" <?= $area === $ar ? 'selected' : '' ?>><?=htmlspecialchars($ar)?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-lg-5 col-md-4 col-12 mb-2">
            <label class="small font-weight-bold text-muted mb-1 d-block">
              <i class="fas fa-search text-info mr-1"></i> Search Customer
            </label>
            <input type="text" id="creditSearch" class="form-control" style="height: 38px;" placeholder="Search shop name, customer no, phone, area..." autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault();}">
          </div>
        </form>
      </div>
    </div>

    <!-- KPI Summary Cards Row (Screen Only) -->
    <div class="row g-3 mb-4 d-print-none">
      <div class="col-xl-3 col-md-6 col-6 mb-3">
        <div class="card border-left-danger shadow-sm h-100 kpi-stat-card" style="background:#fffafa;">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Total Outstanding Credit</div>
            <div class="h5 mb-0 font-weight-bold text-danger">PKR <?=formatCurrency($total_credit_amount)?></div>
            <small class="text-muted font-weight-bold">Amount to receive</small>
          </div>
        </div>
      </div>
      <div class="col-xl-3 col-md-6 col-6 mb-3">
        <div class="card border-left-primary shadow-sm h-100 kpi-stat-card">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Credit Customers</div>
            <div class="h5 mb-0 font-weight-bold text-gray-800"><?=number_format($total_credit_customers)?></div>
            <small class="text-muted"><?=htmlspecialchars($ob_label)?></small>
          </div>
        </div>
      </div>
      <div class="col-xl-3 col-md-6 col-6 mb-3">
        <div class="card border-left-warning shadow-sm h-100 kpi-stat-card">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Unpaid Invoices</div>
            <div class="h5 mb-0 font-weight-bold text-dark"><?=number_format($total_due_invoices)?></div>
            <small class="text-muted">Invoices with due balance</small>
          </div>
        </div>
      </div>
      <div class="col-xl-3 col-md-6 col-6 mb-3">
        <div class="card border-left-secondary shadow-sm h-100 kpi-stat-card">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">Average Balance</div>
            <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($avg_balance)?></div>
            <small class="text-muted">Per credit customer</small>
          </div>
        </div>
      </div>
    </div>

    <?php if (!$customers): ?>
      <div class="alert alert-light border text-center py-5 shadow-sm rounded">
        <i class="fas fa-check-circle text-success fa-3x mb-3 d-block"></i>
        <h5 class="text-dark font-weight-bold">No Outstanding Credits Found</h5>
        <p class="text-muted small mb-0">There are no customers with outstanding balances for the selected Order Booker or Area filter.</p>
      </div>
    <?php else: ?>
    <!-- Customers Credit Table -->
    <div class="table-responsive w-100" style="overflow: visible;">
      <table class="table table-bordered table-hover dsr-load-table w-100 mb-0" id="creditCustomerTable">
        <thead class="bg-light text-dark">
          <tr>
            <th class="text-center" style="width: 4%;">#</th>
            <th class="text-center" style="width: 13%;">Customer<span class="d-none d-print-inline"><br></span><span class="d-print-none"> </span>No</th>
            <th style="width: 30%;">Customer / Shop Name</th>
            <th style="width: 18%;">Area</th>
            <th class="text-center" style="width: 15%;">Phone</th>
            <th class="text-center" style="width: 7%;">Due<span class="d-none d-print-inline"><br></span><span class="d-print-none"> </span>Invoices</th>
            <th class="text-right" style="width: 13%;">Credit<span class="d-none d-print-inline"><br></span><span class="d-print-none"> </span>Balance</th>
            <th class="text-center d-print-none" style="width: 140px;">Actions</th>
          </tr>
        </thead>
        <tbody id="creditCustomerTableBody">
          <?php foreach ($customers as $idx => $c): ?>
          <tr class="credit-row dsr-item-row"
              data-search="<?=htmlspecialchars(strtolower($c['customer_no'] . ' ' . $c['full_name'] . ' ' . $c['phone'] . ' ' . $c['area'] . ' ' . $c['last_booker_name']))?>">
            <td class="text-center text-muted small"><?=$idx + 1?></td>
            <td class="text-center font-weight-bold">
              <span class="code-badge"><?=htmlspecialchars($c['customer_no'])?></span>
            </td>
            <td class="text-left">
              <div class="font-weight-bold text-dark product-title"><?=htmlspecialchars($c['full_name'])?></div>
              <small class="text-muted d-block d-print-none">
                <?php if ($c['last_sale_date']): ?>
                  Last Sale: <?=date('d-m-Y', strtotime($c['last_sale_date']))?>
                <?php endif; ?>
                <?php if ($ob_id === 0 && !empty($c['last_booker_name'])): ?>
                  &middot; Booker: <span class="text-primary font-weight-bold"><?=htmlspecialchars($c['last_booker_name'])?></span>
                <?php endif; ?>
              </small>
            </td>
            <td class="text-left">
              <span class="badge-area"><?=htmlspecialchars($c['area'] ?: '-')?></span>
            </td>
            <td class="text-center">
              <?php if (!empty($c['phone'])): ?>
                <a href="tel:<?=htmlspecialchars($c['phone'])?>" class="text-dark font-weight-bold text-decoration-none d-print-none">
                  <i class="fas fa-phone-alt text-muted small mr-1"></i><?=htmlspecialchars($c['phone'])?>
                </a>
                <span class="d-none d-print-inline"><?=htmlspecialchars($c['phone'])?></span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center font-weight-bold">
              <?php if ((int)$c['due_invoices_cnt'] > 0): ?>
                <span class="badge badge-warning text-dark font-weight-bold"><?=(int)$c['due_invoices_cnt']?></span>
              <?php else: ?>
                <span class="badge badge-light border">0</span>
              <?php endif; ?>
            </td>
            <td class="text-right font-weight-bold">
              <span class="balance-val">PKR <?=formatCurrency($c['current_balance'])?></span>
            </td>
            <td class="text-center d-print-none text-nowrap">
              <a href="../transactions/receive_customer.php?customer_id=<?=$c['id']?>" class="btn btn-xs btn-outline-success mr-1" title="Receive payment from this customer">
                <i class="fas fa-hand-holding-usd mr-1"></i> Receive
              </a>
              <a href="customer_view.php?id=<?=$c['id']?>" class="btn btn-xs btn-outline-secondary" title="View customer profile & ledger">
                <i class="fas fa-eye"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="dsr-total-row">
            <th class="text-center"></th>
            <th class="text-center font-weight-bold"><?=number_format($total_credit_customers)?> Cust</th>
            <th class="text-left font-weight-bold">TOTAL OUTSTANDING</th>
            <th class="text-left"><?=htmlspecialchars($area_label)?></th>
            <th class="text-center"></th>
            <th class="text-center font-weight-bold text-dark"><?=number_format($total_due_invoices)?></th>
            <th class="text-right font-weight-bold text-danger" style="font-size: 1.05rem;">PKR <?=formatCurrency($total_credit_amount)?></th>
            <th class="d-print-none"></th>
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
          <div class="sig-line">Recovery / Cashier Signature</div>
        </div>
        <div class="col-4">
          <div class="sig-line">Authorized Signature</div>
        </div>
      </div>
    </div>

    <!-- Printable Sheet Footer (Packlist Style) -->
    <div class="dsr-sheet-footer d-flex justify-content-between align-items-center mt-3 pt-2 d-none d-print-block">
      <div><strong>Prepared by:</strong> <?=htmlspecialchars($printed_by ?: 'Admin')?></div>
      <div>Mehboob Traders &middot; Customer Credits / Recovery Sheet</div>
      <div>Printed on <?=date('d-m-Y H:i')?></div>
    </div>
    <?php endif; ?>

  </div>
</div>

<script>
$(document).ready(function(){
  // Live Customer Search
  $('#creditSearch').on('input keyup paste', function(){
    var terms = $.trim($(this).val()).toLowerCase().split(/\s+/).filter(Boolean);
    var visibleCount = 0;
    var visibleTotal = 0;

    $('#creditCustomerTableBody tr.credit-row').each(function(){
      var haystack = ($(this).data('search') || $(this).text()).toString().toLowerCase();
      var match = true;
      for (var i = 0; i < terms.length; i++) {
        if (haystack.indexOf(terms[i]) === -1) { match = false; break; }
      }
      $(this).toggle(match);
      if (match) {
        visibleCount++;
      }
    });
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>

