<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Supplier Report & Summary';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

// Refresh supplier balances
$all_sup_ids = $pdo->query("SELECT id FROM suppliers")->fetchAll(PDO::FETCH_COLUMN);
foreach ($all_sup_ids as $sid) {
    syncSupplierPurchasePayments($pdo, (int)$sid);
    updateSupplierBalance($pdo, (int)$sid);
}

// Filters
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$filter_supplier = trim($_GET['supplier_id'] ?? '');
$status_filter = trim($_GET['status_filter'] ?? 'all'); // 'all', 'payable', 'advance', 'settled'
$q = trim($_GET['q'] ?? '');

// Base query for suppliers
$sup_sql = "SELECT * FROM suppliers WHERE status = 1";
$sup_params = [];
if ($filter_supplier !== '') {
    $sup_sql .= " AND id = ?";
    $sup_params[] = (int)$filter_supplier;
}
$sup_sql .= " ORDER BY name ASC";
$sup_stmt = $pdo->prepare($sup_sql);
$sup_stmt->execute($sup_params);
$suppliers_raw = $sup_stmt->fetchAll();

// Fetch purchases in period
$p_sql = "SELECT p.*, s.name AS supplier_name 
          FROM purchases p 
          JOIN suppliers s ON p.supplier_id = s.id 
          WHERE p.status <> 'cancelled'";
$p_params = [];
if ($from !== '') { $p_sql .= " AND p.purchase_date >= ?"; $p_params[] = $from; }
if ($to !== '') { $p_sql .= " AND p.purchase_date <= ?"; $p_params[] = $to; }
if ($filter_supplier !== '') { $p_sql .= " AND p.supplier_id = ?"; $p_params[] = (int)$filter_supplier; }
$p_sql .= " ORDER BY p.purchase_date DESC, p.id DESC";
$p_stmt = $pdo->prepare($p_sql);
$p_stmt->execute($p_params);
$all_purchases = $p_stmt->fetchAll();

// Group purchases by supplier_id
$purchases_by_supplier = [];
$total_purchases_amount = 0.0;
$total_purchases_paid = 0.0;
$total_purchases_due = 0.0;
foreach ($all_purchases as $pur) {
    $sid = (int)$pur['supplier_id'];
    if (!isset($purchases_by_supplier[$sid])) {
        $purchases_by_supplier[$sid] = [
            'count' => 0,
            'total' => 0.0,
            'paid' => 0.0,
            'due' => 0.0,
            'rows' => []
        ];
    }
    $purchases_by_supplier[$sid]['count']++;
    $purchases_by_supplier[$sid]['total'] += (float)$pur['total_amount'];
    $purchases_by_supplier[$sid]['paid'] += (float)$pur['paid_amount'];
    $purchases_by_supplier[$sid]['due'] += (float)$pur['due_amount'];
    $purchases_by_supplier[$sid]['rows'][] = $pur;

    $total_purchases_amount += (float)$pur['total_amount'];
    $total_purchases_paid += (float)$pur['paid_amount'];
    $total_purchases_due += (float)$pur['due_amount'];
}

// Fetch payments in period
$pay_sql = "SELECT sp.*, s.name AS supplier_name 
            FROM supplier_payments sp 
            JOIN suppliers s ON sp.supplier_id = s.id 
            WHERE 1=1";
$pay_params = [];
if ($from !== '') { $pay_sql .= " AND sp.payment_date >= ?"; $pay_params[] = $from; }
if ($to !== '') { $pay_sql .= " AND sp.payment_date <= ?"; $pay_params[] = $to; }
if ($filter_supplier !== '') { $pay_sql .= " AND sp.supplier_id = ?"; $pay_params[] = (int)$filter_supplier; }
$pay_sql .= " ORDER BY sp.payment_date DESC, sp.id DESC";
$pay_stmt = $pdo->prepare($pay_sql);
$pay_stmt->execute($pay_params);
$all_payments = $pay_stmt->fetchAll();

// Group payments by supplier_id
$payments_by_supplier = [];
$total_payments_amount = 0.0;
foreach ($all_payments as $pm) {
    $sid = (int)$pm['supplier_id'];
    if (!isset($payments_by_supplier[$sid])) {
        $payments_by_supplier[$sid] = [
            'total' => 0.0,
            'rows' => []
        ];
    }
    $payments_by_supplier[$sid]['total'] += (float)$pm['amount'];
    $payments_by_supplier[$sid]['rows'][] = $pm;
    $total_payments_amount += (float)$pm['amount'];
}

// Aggregate supplier rows
$suppliers = [];
$total_overall_payable = 0.0;
$total_overall_advance = 0.0;
$count_payable = 0;
$count_advance = 0;
$count_settled = 0;

foreach ($suppliers_raw as $s) {
    $sid = (int)$s['id'];
    $bal = (float)$s['current_balance'];
    $open = (float)($s['opening_balance'] ?? 0) + (float)($s['adjustment'] ?? 0);

    $pur_info = $purchases_by_supplier[$sid] ?? ['count' => 0, 'total' => 0.0, 'paid' => 0.0, 'due' => 0.0, 'rows' => []];
    $pay_info = $payments_by_supplier[$sid] ?? ['total' => 0.0, 'rows' => []];

    if ($bal > 0) {
        $st = 'payable';
        $total_overall_payable += $bal;
        $count_payable++;
    } elseif ($bal < 0) {
        $st = 'advance';
        $total_overall_advance += abs($bal);
        $count_advance++;
    } else {
        $st = 'settled';
        $count_settled++;
    }

    $s['opening_net'] = $open;
    $s['period_purchases_count'] = $pur_info['count'];
    $s['period_purchases_total'] = $pur_info['total'];
    $s['period_purchases_paid'] = $pur_info['paid'];
    $s['period_purchases_due'] = $pur_info['due'];
    $s['period_payments_total'] = $pay_info['total'];
    $s['purchases_rows'] = $pur_info['rows'];
    $s['payments_rows'] = $pay_info['rows'];
    $s['balance_status'] = $st;

    $suppliers[] = $s;
}

$all_suppliers_list = $pdo->query("SELECT id, name FROM suppliers WHERE status = 1 ORDER BY name ASC")->fetchAll();

$period_label = 'All Time';
if ($from && $to) $period_label = formatDate($from) . ' to ' . formatDate($to);
elseif ($from) $period_label = 'From ' . formatDate($from);
elseif ($to) $period_label = 'Until ' . formatDate($to);

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<style>
.sup-pill-btn {
  border-radius: 20px;
  padding: 0.25rem 0.85rem;
  font-size: 0.82rem;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid #cbd5e1;
  background: #fff;
  color: #475569;
  transition: all 0.2s;
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}
.sup-pill-btn:hover { background: #f8fafc; color: #1e293b; text-decoration: none; }
.sup-pill-btn.active {
  background: var(--primary);
  border-color: var(--primary);
  color: #fff;
}
.sup-pill-btn.active-danger {
  background: #ef4444 !important;
  border-color: #dc2626 !important;
  color: #fff !important;
}
.sup-pill-btn.active-success {
  background: #10b981 !important;
  border-color: #059669 !important;
  color: #fff !important;
}
.val-cell { font-family: 'Consolas', 'Courier New', monospace; }
@media print {
  @page { size: A4 landscape; margin: 8mm; }
  .report-table th, .report-table td { padding: 4px 6px !important; font-size: 10px !important; }
  .report-tfoot td { font-size: 10.5px !important; }
}
</style>

<!-- Top Action Header -->
<div class="card shadow-sm border-0 mb-3">
  <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center border-bottom">
    <div class="d-flex align-items-center">
      <div class="icon-circle text-primary mr-3" style="width:44px; height:44px; border-radius:12px; background:rgba(13,110,253,.1); display:flex; align-items:center; justify-content:center;">
        <i class="fas fa-truck-moving fa-lg text-primary"></i>
      </div>
      <div>
        <h5 class="mb-0 font-weight-bold text-dark">Supplier Report & Summary</h5>
        <small class="text-muted">Purchases &middot; Payments &middot; Payables &middot; Balances Overview</small>
      </div>
    </div>
    <div class="d-flex flex-wrap align-items-center mt-2 mt-md-0 gap-2">
      <button type="button" class="btn btn-sm btn-outline-secondary shadow-sm mr-2" onclick="window.print()">
        <i class="fas fa-print mr-1"></i> Print Report
      </button>
      <a href="<?=($base_url ?? '/mehboob_traders/')?>modules/transactions/pay_supplier.php" class="btn btn-sm btn-outline-success shadow-sm mr-2">
        <i class="fas fa-coins mr-1"></i> Pay Supplier
      </a>
      <a href="suppliers.php" class="btn btn-sm btn-outline-primary shadow-sm mr-2">
        <i class="fas fa-list mr-1"></i> View Suppliers
      </a>
      <a href="../purchases/create.php" class="btn btn-sm btn-primary shadow-sm">
        <i class="fas fa-plus mr-1"></i> New Purchase
      </a>
    </div>
  </div>
</div>

<!-- KPI Summary Cards Row -->
<div class="row g-2 mb-3 d-print-none">
  <!-- Total Suppliers -->
  <div class="col-xl-2 col-md-4 col-6 mb-2">
    <div class="card border-left-primary shadow-sm h-100 stat-card">
      <div class="card-body py-2 px-3">
        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Suppliers</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800"><?=count($suppliers)?> Active</div>
        <small class="text-muted font-weight-bold"><?=$count_payable?> with Payable</small>
      </div>
    </div>
  </div>

  <!-- Purchases in Period -->
  <div class="col-xl-3 col-md-4 col-6 mb-2">
    <div class="card border-left-info shadow-sm h-100 stat-card">
      <div class="card-body py-2 px-3">
        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Purchases (Bills)</div>
        <div class="h5 mb-0 font-weight-bold text-primary">PKR <?=formatCurrency($total_purchases_amount)?></div>
        <small class="text-muted font-weight-bold"><?=count($all_purchases)?> Invoices in period</small>
      </div>
    </div>
  </div>

  <!-- Payments in Period -->
  <div class="col-xl-2 col-md-4 col-6 mb-2">
    <div class="card border-left-success shadow-sm h-100 stat-card">
      <div class="card-body py-2 px-3">
        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Payments Made</div>
        <div class="h5 mb-0 font-weight-bold text-success">PKR <?=formatCurrency($total_payments_amount)?></div>
        <small class="text-muted font-weight-bold"><?=count($all_payments)?> Payments in period</small>
      </div>
    </div>
  </div>

  <!-- Total Payable (We Owe) -->
  <div class="col-xl-3 col-md-6 col-6 mb-2">
    <div class="card border-left-danger shadow-sm h-100 stat-card" style="background:#fffafa;">
      <div class="card-body py-2 px-3">
        <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Total Payable (We Owe)</div>
        <div class="h5 mb-0 font-weight-bold text-danger">PKR <?=formatCurrency($total_overall_payable)?></div>
        <small class="text-danger font-weight-bold"><?=$count_payable?> Suppliers to be paid</small>
      </div>
    </div>
  </div>

  <!-- Advance Balance -->
  <div class="col-xl-2 col-md-6 col-6 mb-2">
    <div class="card border-left-secondary shadow-sm h-100 stat-card">
      <div class="card-body py-2 px-3">
        <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">Advance (They Owe)</div>
        <div class="h5 mb-0 font-weight-bold text-dark">PKR <?=formatCurrency($total_overall_advance)?></div>
        <small class="text-muted font-weight-bold"><?=$count_advance?> Advance accounts</small>
      </div>
    </div>
  </div>
</div>

<!-- Filters Bar -->
<div class="card shadow-sm border-0 mb-3 d-print-none">
  <div class="card-body py-2 px-3">
    <form method="get" id="supplierFilterForm" class="row g-2 align-items-end">
      <!-- Date From -->
      <div class="col-md-2 col-sm-6 mb-2 mb-md-0">
        <label class="small font-weight-bold text-muted mb-1"><i class="fas fa-calendar-alt text-primary mr-1"></i> From Date</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?=htmlspecialchars($from)?>">
      </div>

      <!-- Date To -->
      <div class="col-md-2 col-sm-6 mb-2 mb-md-0">
        <label class="small font-weight-bold text-muted mb-1"><i class="fas fa-calendar-alt text-primary mr-1"></i> To Date</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?=htmlspecialchars($to)?>">
      </div>

      <!-- Supplier Dropdown -->
      <div class="col-md-3 col-sm-6 mb-2 mb-md-0">
        <label class="small font-weight-bold text-muted mb-1"><i class="fas fa-user-tag text-secondary mr-1"></i> Supplier</label>
        <select name="supplier_id" class="form-control form-control-sm select2">
          <option value="">All Suppliers</option>
          <?php foreach ($all_suppliers_list as $sup): ?>
          <option value="<?=$sup['id']?>" <?=$filter_supplier == $sup['id'] ? 'selected' : ''?>><?=htmlspecialchars($sup['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Live Search -->
      <div class="col-md-3 col-sm-6 mb-2 mb-md-0">
        <label class="small font-weight-bold text-muted mb-1"><i class="fas fa-search text-info mr-1"></i> Quick Search</label>
        <div class="input-group input-group-sm">
          <input type="text" id="liveSupplierSearch" class="form-control" placeholder="Search supplier, phone, city..." value="<?=htmlspecialchars($q)?>" autocomplete="off">
          <div class="input-group-append">
            <button type="button" class="btn btn-outline-secondary" id="btnClearSupSearch" title="Clear Search" style="display:none;"><i class="fas fa-times"></i></button>
          </div>
        </div>
      </div>

      <!-- Filter Buttons -->
      <div class="col-md-2 col-sm-12 text-right">
        <div class="d-flex gap-1 justify-content-end">
          <button type="submit" class="btn btn-sm btn-primary flex-grow-1"><i class="fas fa-filter mr-1"></i> Filter</button>
          <?php if ($from !== '' || $to !== '' || $filter_supplier !== '' || $q !== ''): ?>
          <a href="supplier_report.php" class="btn btn-sm btn-outline-danger" title="Reset Filters"><i class="fas fa-undo"></i></a>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Quick Status Pills -->
<div class="mb-3 d-print-none d-flex flex-wrap align-items-center justify-content-between">
  <div class="d-flex flex-wrap align-items-center gap-1 mb-2 mb-md-0">
    <button type="button" class="sup-pill-btn active" data-filter="all">
      <i class="fas fa-list"></i> All Suppliers (<span id="pillCountAll"><?=count($suppliers)?></span>)
    </button>
    <button type="button" class="sup-pill-btn" data-filter="payable">
      <i class="fas fa-exclamation-circle text-danger"></i> Payable Only (<span id="pillCountPayable"><?=$count_payable?></span>)
    </button>
    <button type="button" class="sup-pill-btn" data-filter="advance">
      <i class="fas fa-wallet text-success"></i> Advance Accounts (<span id="pillCountAdvance"><?=$count_advance?></span>)
    </button>
    <button type="button" class="sup-pill-btn" data-filter="settled">
      <i class="fas fa-check-circle text-muted"></i> Settled / Zero (<span id="pillCountSettled"><?=$count_settled?></span>)
    </button>
  </div>

  <!-- Nav Tabs for Summary vs Details -->
  <ul class="nav nav-pills" id="reportTabs" role="tablist">
    <li class="nav-item">
      <a class="nav-link active font-weight-bold py-1 px-3" id="tab-summary-tab" data-toggle="pill" href="#tab-summary" role="tab" aria-selected="true" style="font-size: 0.85rem;">
        <i class="fas fa-table mr-1"></i> Balance Summary
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link font-weight-bold py-1 px-3" id="tab-details-tab" data-toggle="pill" href="#tab-details" role="tab" aria-selected="false" style="font-size: 0.85rem;">
        <i class="fas fa-receipt mr-1"></i> Activity Breakdown
      </a>
    </li>
  </ul>
</div>

<!-- Printable Header / Letterhead -->
<div class="report-sheet d-none d-print-block mb-3">
  <div class="report-head">
    <div class="report-brand-line">
      <div class="report-brand">
        <div class="report-brand-name">Mehboob Traders</div>
        <div class="report-brand-sub">Wholesale Business &middot; Lahore, Pakistan &middot; GST No: --</div>
      </div>
      <div class="report-title-box">
        <div class="report-title">SUPPLIER SUMMARY & BALANCE REPORT</div>
        <div class="report-meta">Period: <?=$period_label?> &middot; Printed on <?=formatDate(date('Y-m-d'))?> <?=date('h:i A')?></div>
      </div>
    </div>
  </div>

  <table class="report-summary-table">
    <tr>
      <td class="rs-cell"><span class="rs-label">Total Suppliers</span><span class="rs-val"><?=count($suppliers)?></span></td>
      <td class="rs-cell"><span class="rs-label">Total Purchases (Bills)</span><span class="rs-val">PKR <?=formatCurrency($total_purchases_amount)?></span></td>
      <td class="rs-cell"><span class="rs-label">Total Payments</span><span class="rs-val" style="color:#0f766e;">PKR <?=formatCurrency($total_payments_amount)?></span></td>
      <td class="rs-cell"><span class="rs-label">Net Payable (We Owe)</span><span class="rs-val" style="color:#b91c1c;">PKR <?=formatCurrency($total_overall_payable)?></span></td>
      <td class="rs-cell"><span class="rs-label">Net Advance</span><span class="rs-val" style="color:#0369a1;">PKR <?=formatCurrency($total_overall_advance)?></span></td>
    </tr>
  </table>
</div>

<!-- Tab Content -->
<div class="tab-content" id="reportTabsContent">
  <!-- TAB 1: SUMMARY TABLE -->
  <div class="tab-pane fade show active" id="tab-summary" role="tabpanel">
    <div class="card shadow-sm border-0 mb-4">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-bordered table-hover mb-0 report-table" id="supplierSummaryTable">
            <thead class="bg-light text-dark">
              <tr>
                <th class="text-center" style="width: 40px;">#</th>
                <th>Supplier Name</th>
                <th>Contact & Phone</th>
                <th>City</th>
                <th class="text-right" style="width: 130px;">Opening Balance</th>
                <th class="text-right" style="width: 140px;">Purchases (Period)</th>
                <th class="text-right" style="width: 130px;">Paid (Period)</th>
                <th class="text-right" style="width: 160px;">Current Balance</th>
                <th class="text-center d-print-none" style="width: 120px;">Actions</th>
              </tr>
            </thead>
            <tbody id="supplierTableBody">
              <?php if (empty($suppliers)): ?>
              <tr id="emptySupRow">
                <td colspan="9" class="text-center text-muted py-5">
                  <i class="fas fa-truck-loading fa-3x text-muted mb-2 d-block"></i>
                  No suppliers found matching the selected criteria.
                </td>
              </tr>
              <?php else: ?>
              <?php foreach ($suppliers as $idx => $s):
                $bal = (float)$s['current_balance'];
                $st = $s['balance_status'];
                if ($bal > 0) {
                    $balBadge = '<span class="badge badge-danger font-weight-bold px-2 py-1">Payable PKR ' . formatCurrency($bal) . '</span>';
                } elseif ($bal < 0) {
                    $balBadge = '<span class="badge badge-success font-weight-bold px-2 py-1">Advance PKR ' . formatCurrency(abs($bal)) . '</span>';
                } else {
                    $balBadge = '<span class="badge badge-light border text-muted px-2 py-1">PKR 0.00 (Settled)</span>';
                }
              ?>
              <tr class="sup-row"
                  data-id="<?=$s['id']?>"
                  data-name="<?=htmlspecialchars(strtolower($s['name']))?>"
                  data-contact="<?=htmlspecialchars(strtolower($s['contact_person'] ?? ''))?>"
                  data-phone="<?=htmlspecialchars(strtolower($s['phone'] ?? ''))?>"
                  data-city="<?=htmlspecialchars(strtolower($s['city'] ?? ''))?>"
                  data-purchases="<?=$s['period_purchases_total']?>"
                  data-payments="<?=$s['period_payments_total']?>"
                  data-balance="<?=$bal?>"
                  data-status="<?=$st?>">
                <td class="text-center text-muted small sup-row-num"><?=($idx + 1)?></td>
                <td>
                  <div class="font-weight-bold text-dark">
                    <a href="supplier_view.php?id=<?=$s['id']?>" class="text-dark hover-primary"><?=htmlspecialchars($s['name'])?></a>
                  </div>
                  <?php if (!empty($s['notes'])): ?>
                    <small class="text-muted"><i class="fas fa-info-circle mr-1"></i><?=htmlspecialchars($s['notes'])?></small>
                  <?php endif; ?>
                </td>
                <td class="small">
                  <?php if (!empty($s['contact_person'])): ?>
                    <div class="font-weight-bold text-secondary"><?=htmlspecialchars($s['contact_person'])?></div>
                  <?php endif; ?>
                  <div><?=htmlspecialchars($s['phone'] ?? '—')?></div>
                </td>
                <td class="small text-muted"><?=htmlspecialchars($s['city'] ?: '—')?></td>
                <td class="text-right small val-cell">
                  <?php if ($s['opening_net'] > 0): ?>
                    <span class="text-danger font-weight-bold">PKR <?=formatCurrency($s['opening_net'])?></span>
                  <?php elseif ($s['opening_net'] < 0): ?>
                    <span class="text-success font-weight-bold">PKR <?=formatCurrency(abs($s['opening_net']))?> (Adv)</span>
                  <?php else: ?>
                    <span class="text-muted">PKR 0.00</span>
                  <?php endif; ?>
                </td>
                <td class="text-right val-cell">
                  <?php if ($s['period_purchases_total'] > 0): ?>
                    <div class="font-weight-bold text-primary">PKR <?=formatCurrency($s['period_purchases_total'])?></div>
                    <small class="text-muted"><?=$s['period_purchases_count']?> Invoice<?= $s['period_purchases_count'] === 1 ? '' : 's' ?></small>
                  <?php else: ?>
                    <span class="text-muted">0.00</span>
                  <?php endif; ?>
                </td>
                <td class="text-right val-cell">
                  <?php if ($s['period_payments_total'] > 0): ?>
                    <span class="font-weight-bold text-success">PKR <?=formatCurrency($s['period_payments_total'])?></span>
                  <?php else: ?>
                    <span class="text-muted">0.00</span>
                  <?php endif; ?>
                </td>
                <td class="text-right val-cell">
                  <?=$balBadge?>
                </td>
                <td class="text-center d-print-none" nowrap>
                  <a href="supplier_view.php?id=<?=$s['id']?>" class="btn btn-sm btn-outline-info py-0 px-2" title="View Ledger">
                    <i class="fas fa-book"></i> Ledger
                  </a>
                  <a href="<?=($base_url ?? '/mehboob_traders/')?>modules/transactions/pay_supplier.php?supplier_id=<?=$s['id']?>" class="btn btn-sm btn-outline-success py-0 px-2 ml-1" title="Pay Supplier">
                    <i class="fas fa-coins"></i>
                  </a>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
            <tfoot class="bg-light font-weight-bold report-tfoot" style="border-top: 2px solid #cbd5e1;">
              <tr>
                <td colspan="5" class="text-right">TOTAL (<span id="visibleSupCount"><?=count($suppliers)?></span> Suppliers):</td>
                <td class="text-right text-primary val-cell" id="tfootPurchases">PKR <?=formatCurrency($total_purchases_amount)?></td>
                <td class="text-right text-success val-cell" id="tfootPayments">PKR <?=formatCurrency($total_payments_amount)?></td>
                <td class="text-right text-danger val-cell" id="tfootNetPayable">PKR <?=formatCurrency($total_overall_payable)?> (Payable)</td>
                <td class="d-print-none"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- TAB 2: DETAILED INVOICES & PAYMENTS BY SUPPLIER -->
  <div class="tab-pane fade" id="tab-details" role="tabpanel">
    <?php
    $has_activity = false;
    foreach ($suppliers as $s):
      if (empty($s['purchases_rows']) && empty($s['payments_rows'])) continue;
      $has_activity = true;
      $bal = (float)$s['current_balance'];
    ?>
    <div class="card shadow-sm border-0 mb-3">
      <div class="card-header bg-light d-flex justify-content-between align-items-center py-2 px-3">
        <div>
          <span class="font-weight-bold text-dark h6 mb-0"><?=htmlspecialchars($s['name'])?></span>
          <span class="text-muted small ml-2"><i class="fas fa-phone-alt"></i> <?=htmlspecialchars($s['phone'] ?? '—')?></span>
          <?php if (!empty($s['city'])): ?><span class="badge badge-light border ml-1"><?=htmlspecialchars($s['city'])?></span><?php endif; ?>
        </div>
        <div>
          <span class="small text-muted mr-2">Current Balance:</span>
          <?php if ($bal > 0): ?>
            <span class="badge badge-danger font-weight-bold px-2 py-1">Payable PKR <?=formatCurrency($bal)?></span>
          <?php elseif ($bal < 0): ?>
            <span class="badge badge-success font-weight-bold px-2 py-1">Advance PKR <?=formatCurrency(abs($bal))?></span>
          <?php else: ?>
            <span class="badge badge-light border text-muted px-2 py-1">Settled (0.00)</span>
          <?php endif; ?>
          <a href="supplier_view.php?id=<?=$s['id']?>" class="btn btn-sm btn-outline-primary py-0 px-2 ml-2 d-print-none"><i class="fas fa-eye"></i> Ledger</a>
        </div>
      </div>
      <div class="card-body p-0">
        <div class="row no-gutters">
          <!-- Purchases Column -->
          <div class="col-md-7 border-right">
            <div class="p-2 bg-white border-bottom font-weight-bold small text-primary">
              <i class="fas fa-shopping-cart mr-1"></i> Purchases (<?=count($s['purchases_rows'])?> Invoices &middot; PKR <?=formatCurrency($s['period_purchases_total'])?>)
            </div>
            <?php if (empty($s['purchases_rows'])): ?>
              <div class="p-3 text-center text-muted small">No purchases in this period</div>
            <?php else: ?>
              <table class="table table-sm table-hover mb-0" style="font-size: 0.82rem;">
                <thead class="bg-light">
                  <tr><th>Date</th><th>Invoice #</th><th class="text-right">Total</th><th class="text-right">Paid</th><th class="text-right">Due</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($s['purchases_rows'] as $pr): ?>
                  <tr>
                    <td><?=formatDate($pr['purchase_date'])?></td>
                    <td class="font-weight-bold text-dark"><?=htmlspecialchars($pr['invoice_no'])?></td>
                    <td class="text-right">PKR <?=formatCurrency($pr['total_amount'])?></td>
                    <td class="text-right text-success">PKR <?=formatCurrency($pr['paid_amount'])?></td>
                    <td class="text-right font-weight-bold <?= (float)$pr['due_amount'] > 0 ? 'text-danger' : 'text-muted' ?>">PKR <?=formatCurrency($pr['due_amount'])?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>

          <!-- Payments Column -->
          <div class="col-md-5">
            <div class="p-2 bg-white border-bottom font-weight-bold small text-success">
              <i class="fas fa-money-bill-wave mr-1"></i> Direct Payments (<?=count($s['payments_rows'])?> Payments &middot; PKR <?=formatCurrency($s['period_payments_total'])?>)
            </div>
            <?php if (empty($s['payments_rows'])): ?>
              <div class="p-3 text-center text-muted small">No direct payments in this period</div>
            <?php else: ?>
              <table class="table table-sm table-hover mb-0" style="font-size: 0.82rem;">
                <thead class="bg-light">
                  <tr><th>Date</th><th>Method</th><th>Description</th><th class="text-right">Amount</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($s['payments_rows'] as $pyr): ?>
                  <tr>
                    <td><?=formatDate($pyr['payment_date'])?></td>
                    <td><span class="badge badge-light border text-capitalize"><?=htmlspecialchars($pyr['payment_method'])?></span></td>
                    <td class="text-muted small"><?=htmlspecialchars($pyr['description'] ?: 'Payment')?></td>
                    <td class="text-right font-weight-bold text-success">PKR <?=formatCurrency($pyr['amount'])?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if (!$has_activity): ?>
    <div class="card shadow-sm border-0 py-5 text-center text-muted">
      <i class="fas fa-folder-open fa-3x mb-2 text-muted"></i>
      <div>No purchase or payment activity recorded in this selected period.</div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Print Signatures Footer -->
<div class="report-foot d-none d-print-block mt-4">
  <div style="width: 30%; border-top: 1px solid #334155; padding-top: 4px; text-align: center;">
    <strong>Prepared By</strong><br><small><?=htmlspecialchars($_SESSION['user_name'] ?? 'Staff')?></small>
  </div>
  <div style="width: 30%; border-top: 1px solid #334155; padding-top: 4px; text-align: center;">
    <strong>Accounts Officer / Verified By</strong><br><small>Mehboob Traders</small>
  </div>
  <div style="width: 30%; border-top: 1px solid #334155; padding-top: 4px; text-align: center;">
    <strong>Authorized Sign</strong><br><small>Management</small>
  </div>
</div>

<script>
$(document).ready(function(){
  var activeStatusFilter = 'all'; // 'all', 'payable', 'advance', 'settled'

  function filterSupplierRows(){
    var q = $.trim($('#liveSupplierSearch').val().toLowerCase());
    $('#btnClearSupSearch').toggle(q.length > 0);

    var visibleCount = 0;
    var sumPurchases = 0;
    var sumPayments = 0;
    var sumPayable = 0;

    $('.sup-row').each(function(){
      var $r = $(this);
      var name = $r.data('name') || '';
      var contact = $r.data('contact') || '';
      var phone = $r.data('phone') || '';
      var city = $r.data('city') || '';
      var status = $r.data('status') || '';
      var purchases = parseFloat($r.data('purchases')) || 0;
      var payments = parseFloat($r.data('payments')) || 0;
      var balance = parseFloat($r.data('balance')) || 0;

      // Status pill match
      var statusMatch = true;
      if (activeStatusFilter === 'payable') {
        statusMatch = (status === 'payable');
      } else if (activeStatusFilter === 'advance') {
        statusMatch = (status === 'advance');
      } else if (activeStatusFilter === 'settled') {
        statusMatch = (status === 'settled');
      }

      // Query text match
      var textMatch = true;
      if (q !== '') {
        textMatch = (name.indexOf(q) > -1 || contact.indexOf(q) > -1 || phone.indexOf(q) > -1 || city.indexOf(q) > -1);
      }

      if (statusMatch && textMatch) {
        $r.show();
        visibleCount++;
        sumPurchases += purchases;
        sumPayments += payments;
        if (balance > 0) sumPayable += balance;
        $r.find('.sup-row-num').text(visibleCount);
      } else {
        $r.hide();
      }
    });

    // Update footer totals
    $('#visibleSupCount').text(visibleCount);
    $('#tfootPurchases').text('PKR ' + sumPurchases.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}));
    $('#tfootPayments').text('PKR ' + sumPayments.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}));
    $('#tfootNetPayable').text('PKR ' + sumPayable.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + ' (Payable)');

    // Toggle empty row
    if (visibleCount === 0) {
      if (!$('#noMatchSupRow').length) {
        $('#supplierTableBody').append('<tr id="noMatchSupRow"><td colspan="9" class="text-center text-muted py-5"><i class="fas fa-search fa-2x mb-2 d-block text-muted"></i>No suppliers match the selected criteria.</td></tr>');
      } else {
        $('#noMatchSupRow').show();
      }
    } else {
      $('#noMatchSupRow').remove();
    }
  }

  // Live text search
  $('#liveSupplierSearch').on('input', function(){
    filterSupplierRows();
  });

  $('#btnClearSupSearch').on('click', function(){
    $('#liveSupplierSearch').val('').focus();
    filterSupplierRows();
  });

  // Status pill click
  $('.sup-pill-btn').on('click', function(){
    $('.sup-pill-btn').removeClass('active active-danger active-success');
    $(this).addClass('active');
    activeStatusFilter = $(this).data('filter');
    filterSupplierRows();
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
