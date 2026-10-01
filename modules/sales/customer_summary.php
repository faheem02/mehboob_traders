<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Customer Sales Summary';
$compact_page_heading = true;
$hide_topbar_title = true;
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$sup = $_GET['salesman_id'] ?? '';
$ob = normalizeIdList($_GET['order_booker_id'] ?? []);
$area = trim($_GET['area'] ?? '');
$area_options = isAdmin() ? allKnownAreas($pdo) : (array)currentUserAreas($pdo);
if ($area !== '' && !in_array($area, $area_options, true)) { $area = ''; }

$sql = "SELECT s.id AS sale_id, s.invoice_no, s.sale_date, s.delivery_date, s.total_amount, s.discount_amount, s.paid_amount, s.due_amount,
               c.id AS customer_id, c.customer_no, c.full_name, c.area, c.phone
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        WHERE s.status <> 'cancelled'";
$params = [];
if ($from) { $sql .= " AND COALESCE(s.delivery_date, s.sale_date) >= ?"; $params[] = $from; }
if ($to) { $sql .= " AND COALESCE(s.delivery_date, s.sale_date) <= ?"; $params[] = $to; }
if ($sup !== '') { $sql .= " AND s.salesman_id = ?"; $params[] = $sup; }
if ($area !== '') { $sql .= " AND LOWER(c.area) = LOWER(?)"; $params[] = $area; }
if (!isAdmin()) {
    $sql .= " AND s.created_by = ?";
    $params[] = $_SESSION['user_id'];
} elseif ($ob) {
    $sql .= " AND s.created_by IN (" . implode(',', array_fill(0, count($ob), '?')) . ")";
    $params = array_merge($params, $ob);
}
$sql .= " ORDER BY COALESCE(c.full_name, '') ASC, c.id ASC, COALESCE(s.delivery_date, s.sale_date) ASC, s.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

$groups = [];
$total_sales = 0; $total_discount = 0; $total_paid = 0; $total_due = 0; $inv_count = 0;
foreach ($sales as $s) {
    $key = $s['customer_id'] ? (int)$s['customer_id'] : 'walkin';
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'name' => $s['full_name'] ?: 'Walk-in Customer',
            'customer_no' => $s['customer_no'] ?? '',
            'area' => $s['area'],
            'phone' => $s['phone'],
            'rows' => []
        ];
    }
    $groups[$key]['rows'][] = $s;
    $total_sales += (float)$s['total_amount'];
    $total_discount += (float)($s['discount_amount'] ?? 0);
    $total_paid += (float)$s['paid_amount'];
    $total_due += (float)$s['due_amount'];
    $inv_count++;
}

$sales_name = '';
if ($sup !== '') {
    $sn = $pdo->prepare("SELECT full_name FROM employees WHERE id = ?");
    $sn->execute([$sup]);
    $sales_name = (string)$sn->fetchColumn();
}

$ob_name = '';
if ($ob) {
    $ph = implode(',', array_fill(0, count($ob), '?'));
    $on = $pdo->prepare("SELECT full_name FROM users WHERE id IN ($ph) ORDER BY full_name");
    $on->execute($ob);
    $ob_name = implode(', ', array_filter($on->fetchAll(PDO::FETCH_COLUMN)));
}

$period_label = 'All invoices';
$period_desc = 'All dates';
if ($from && $to) { $period_label = 'Filtered invoices'; $period_desc = formatDate($from) . ' to ' . formatDate($to); }
elseif ($from) { $period_label = 'Filtered invoices'; $period_desc = 'From ' . formatDate($from); }
elseif ($to) { $period_label = 'Filtered invoices'; $period_desc = 'Until ' . formatDate($to); }
$filter_note = [];
if ($sup !== '') $filter_note[] = 'Salesman: ' . $sales_name;
if ($ob_name !== '') $filter_note[] = 'Order taker: ' . $ob_name;
if ($area !== '') $filter_note[] = 'Area: ' . $area;
$filter_note = $filter_note ? ' &middot; ' . implode(' &middot; ', $filter_note) : '';
$printed_by = '';
if (!empty($_SESSION['user_id'])) {
    $pu = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $pu->execute([(int)$_SESSION['user_id']]);
    $printed_by = (string)$pu->fetchColumn();
}
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow-sm border-0 mb-4">
  <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center d-print-none border-bottom">
    <div class="d-flex align-items-center">
      <div class="icon-circle bg-primary-soft text-primary mr-3">
        <i class="fas fa-chart-bar fa-lg"></i>
      </div>
      <div>
        <h5 class="mb-0 font-weight-bold text-dark">Customer Sales Summary</h5>
        <small class="text-muted"><?=count($groups)?> Customers &middot; <?=$inv_count?> Invoices</small>
      </div>
    </div>
    <div class="d-flex flex-wrap mt-2 mt-md-0">
      <?php if ($inv_count > 0): ?>
      <button type="button" class="btn btn-sm btn-primary shadow-sm mr-2" onclick="window.print()">
        <i class="fas fa-print mr-1"></i> Print Summary
      </button>
      <?php endif; ?>
      <a href="invoices.php" class="btn btn-sm btn-outline-secondary mr-2">
        <i class="fas fa-file-invoice mr-1"></i> Invoices
      </a>
      <a href="packlist.php" class="btn btn-sm btn-outline-info mr-2">
        <i class="fas fa-truck-loading mr-1"></i> Delivery List
      </a>
      <a href="index.php" class="btn btn-sm btn-success">
        <i class="fas fa-plus mr-1"></i> Take Order
      </a>
    </div>
  </div>

  <div class="card-body p-3 p-md-4">

    <!-- Filters Form (Screen Only) -->
    <form method="get" class="d-print-none mb-4 p-3 rounded-lg border bg-light shadow-sm">
      <div class="row g-2 align-items-end">
        <div class="col-lg-2 col-md-3 col-sm-6 mb-2">
          <label class="form-label font-weight-bold text-xs text-uppercase text-muted mb-1">From Date</label>
          <input type="date" name="from" class="form-control form-control-sm" value="<?=htmlspecialchars($from)?>">
        </div>
        <div class="col-lg-2 col-md-3 col-sm-6 mb-2">
          <label class="form-label font-weight-bold text-xs text-uppercase text-muted mb-1">To Date</label>
          <input type="date" name="to" class="form-control form-control-sm" value="<?=htmlspecialchars($to)?>">
        </div>
        <div class="col-lg-2 col-md-3 col-sm-6 mb-2">
          <label class="form-label font-weight-bold text-xs text-uppercase text-muted mb-1">Area</label>
          <select name="area" class="form-control form-control-sm">
            <option value="">-- All Areas --</option>
            <?php foreach ($area_options as $ar): ?>
            <option value="<?=htmlspecialchars($ar)?>" <?= $area === $ar ? 'selected' : '' ?>><?=htmlspecialchars($ar)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-2 col-md-3 col-sm-6 mb-2">
          <label class="form-label font-weight-bold text-xs text-uppercase text-muted mb-1">Delivery Man</label>
          <div class="ac-wrap">
            <input type="text" id="salesmanSearch" class="form-control form-control-sm" placeholder="Search salesman..." autocomplete="off" value="<?=htmlspecialchars($sales_name)?>">
            <input type="hidden" name="salesman_id" id="salesman_id" value="<?=htmlspecialchars($sup)?>">
            <div class="ac-list" id="salesmanList"></div>
          </div>
        </div>
        <?php if (isAdmin()): ?>
        <div class="col-lg-2 col-md-3 col-sm-6 mb-2">
          <label class="form-label font-weight-bold text-xs text-uppercase text-muted mb-1">Order Booker</label>
          <div class="ac-wrap">
            <input type="text" id="obSearch" class="form-control form-control-sm" placeholder="Search booker..." autocomplete="off" value="<?=htmlspecialchars($ob_name)?>">
            <span id="obFilterHolder"><?php foreach ($ob as $oid): ?><input type="hidden" class="ob-filter-id" name="order_booker_id[]" value="<?=(int)$oid?>"><?php endforeach; ?></span>
            <div class="ac-list" id="obList"></div>
          </div>
        </div>
        <?php endif; ?>
        <div class="col-lg-2 col-md-3 col-sm-6 mb-2 d-flex">
          <a href="customer_summary.php" class="btn btn-sm btn-outline-secondary mr-2" title="Reset Filters"><i class="fas fa-undo"></i></a>
          <button type="submit" class="btn btn-sm btn-primary px-3 shadow-sm flex-fill"><i class="fas fa-filter mr-1"></i> Filter</button>
        </div>
      </div>
    </form>

    <?php if (empty($sales)): ?>
      <div class="alert alert-light border text-center py-5 shadow-sm rounded">
        <i class="fas fa-chart-bar text-muted fa-3x mb-3 d-block"></i>
        <h5 class="text-muted font-weight-bold">No sales records found</h5>
        <p class="text-muted small mb-0">No active invoices were found for the selected date range or filter criteria.</p>
      </div>
    <?php else: ?>

      <!-- Modern On-Screen KPI Stat Cards Strip (Screen Only) -->
      <div class="row g-3 mb-4 d-print-none">
        <div class="col-xl-3 col-md-6 mb-3">
          <div class="card border-0 shadow-sm text-white h-100 rounded-lg overflow-hidden" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%);">
            <div class="card-body p-3 d-flex align-items-center justify-content-between">
              <div>
                <div class="text-xs text-uppercase font-weight-bold text-white-50 mb-1">Total Sales</div>
                <div class="h4 mb-0 font-weight-bold">PKR <?=formatCurrency($total_sales)?></div>
                <small class="text-white-50"><?=$inv_count?> Invoices</small>
              </div>
              <div class="rounded-circle p-3" style="background: rgba(255,255,255,0.2);">
                <i class="fas fa-file-invoice-dollar fa-2x"></i>
              </div>
            </div>
          </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
          <div class="card border-0 shadow-sm text-white h-100 rounded-lg overflow-hidden" style="background: linear-gradient(135deg, #0f766e 0%, #14b8a6 100%);">
            <div class="card-body p-3 d-flex align-items-center justify-content-between">
              <div>
                <div class="text-xs text-uppercase font-weight-bold text-white-50 mb-1">Total Paid</div>
                <div class="h4 mb-0 font-weight-bold">PKR <?=formatCurrency($total_paid)?></div>
                <small class="text-white-50">Received Amount</small>
              </div>
              <div class="rounded-circle p-3" style="background: rgba(255,255,255,0.2);">
                <i class="fas fa-hand-holding-usd fa-2x"></i>
              </div>
            </div>
          </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
          <div class="card border-0 shadow-sm text-white h-100 rounded-lg overflow-hidden" style="background: linear-gradient(135deg, #b91c1c 0%, #ef4444 100%);">
            <div class="card-body p-3 d-flex align-items-center justify-content-between">
              <div>
                <div class="text-xs text-uppercase font-weight-bold text-white-50 mb-1">Total Due</div>
                <div class="h4 mb-0 font-weight-bold">PKR <?=formatCurrency($total_due)?></div>
                <small class="text-white-50">Pending Balance</small>
              </div>
              <div class="rounded-circle p-3" style="background: rgba(255,255,255,0.2);">
                <i class="fas fa-clock fa-2x"></i>
              </div>
            </div>
          </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
          <div class="card border-0 shadow-sm text-white h-100 rounded-lg overflow-hidden" style="background: linear-gradient(135deg, #1d4ed8 0%, #3b82f6 100%);">
            <div class="card-body p-3 d-flex align-items-center justify-content-between">
              <div>
                <div class="text-xs text-uppercase font-weight-bold text-white-50 mb-1">Customers</div>
                <div class="h4 mb-0 font-weight-bold"><?=count($groups)?></div>
                <small class="text-white-50"><?=$inv_count?> Invoices</small>
              </div>
              <div class="rounded-circle p-3" style="background: rgba(255,255,255,0.2);">
                <i class="fas fa-users fa-2x"></i>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- PRINT-ONLY DSR-STYLE HEADER (Hidden on Screen, Ultra-Compact 1:1 in Print) -->
      <div class="d-none d-print-block">
        <div class="dsr-header-box">
          <div class="dsr-brand-title">MEHBOOB TRADERS</div>
          <div class="dsr-brand-subtitle">CUSTOMER SALES SUMMARY</div>
        </div>

        <div class="dsr-meta-grid">
          <!-- Left Column -->
          <div class="dsr-meta-col dsr-meta-left">
            <div class="dsr-meta-row">
              <span class="meta-lbl">Salesman:</span>
              <span class="meta-val"><?=htmlspecialchars($sales_name ?: 'All Salesmen')?></span>
            </div>
            <div class="dsr-meta-row">
              <span class="meta-lbl">Sales Officer:</span>
              <span class="meta-val"><?=htmlspecialchars($ob_name ?: 'All Bookers')?></span>
            </div>
            <div class="dsr-meta-row">
              <span class="meta-lbl">Sale Area:</span>
              <span class="meta-val"><?=htmlspecialchars($area !== '' ? $area : 'All Areas')?></span>
            </div>
          </div>

          <!-- Middle Column -->
          <div class="dsr-meta-col dsr-meta-mid text-center">
            <div class="dsr-booking-box">
              <span class="dsr-booking-lbl">Total Sales:</span>
              <span class="dsr-booking-val">PKR <?=formatCurrency($total_sales)?></span>
            </div>
            <?php if ($total_discount > 0): ?>
            <div class="dsr-meta-row mt-1">
              <span class="meta-lbl">Total Discount:</span>
              <span class="meta-val text-danger">PKR <?=formatCurrency($total_discount)?></span>
            </div>
            <?php endif; ?>
            <div class="dsr-meta-row mt-1">
              <span class="meta-lbl">Total Paid:</span>
              <span class="meta-val text-success">PKR <?=formatCurrency($total_paid)?></span>
            </div>
            <div class="dsr-meta-row">
              <span class="meta-lbl">Total Due:</span>
              <span class="meta-val text-danger">PKR <?=formatCurrency($total_due)?></span>
            </div>
          </div>

          <!-- Right Column -->
          <div class="dsr-meta-col dsr-meta-right text-right">
            <div class="dsr-meta-row">
              <span class="meta-lbl">Period:</span>
              <span class="meta-val"><?=htmlspecialchars($period_desc)?></span>
            </div>
            <div class="dsr-meta-row">
              <span class="meta-lbl">Customers:</span>
              <span class="meta-val"><?=count($groups)?></span>
            </div>
            <div class="dsr-meta-row">
              <span class="meta-lbl">Invoices:</span>
              <span class="meta-val"><?=$inv_count?></span>
            </div>
          </div>
        </div>
      </div>
      <!-- /Print-Only Header -->

      <!-- Search Filter Bar (Screen Only) -->
      <div class="d-flex justify-content-between align-items-center mb-2 d-print-none">
        <div class="text-muted small">Showing <strong><?=count($groups)?></strong> customers</div>
        <div class="input-group input-group-sm" style="max-width: 280px;">
          <div class="input-group-prepend">
            <span class="input-group-text bg-white border-right-0"><i class="fas fa-search text-muted"></i></span>
          </div>
          <input type="text" id="tableQuickSearch" class="form-control border-left-0" placeholder="Filter customer, area, phone...">
        </div>
      </div>

      <!-- FULL WIDTH ULTRA-COMPACT DATA TABLE -->
      <div class="table-responsive w-100" style="overflow: visible;">
        <table class="table table-bordered table-hover dsr-load-table w-100 mb-0" id="summaryTable">
          <thead>
            <tr>
              <th style="width: 3%;" class="text-center">#</th>
              <th style="width: 21%;" class="text-left">Customer Name</th>
              <th style="width: 10%;" class="text-left">Area</th>
              <th style="width: 10%;" class="text-center">Phone</th>
              <th style="width: 13%;" class="text-center">Invoices</th>
              <th style="width: 14%;" class="text-center">Booking / Delivery</th>
              <th style="width: 8%;" class="text-right">Total</th>
              <th style="width: 7%;" class="text-right">Discount</th>
              <th style="width: 7%;" class="text-right">Paid</th>
              <th style="width: 7%;" class="text-right">Due</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $i = 0;
            foreach ($groups as $g):
              $i++;
              $g_sales = 0; $g_discount = 0; $g_paid = 0; $g_due = 0;
              $inv_links = [];
              $inv_texts = [];
              $b_dates = [];
              $d_dates = [];
              foreach ($g['rows'] as $r) {
                $g_sales += (float)$r['total_amount'];
                $g_discount += (float)($r['discount_amount'] ?? 0);
                $g_paid += (float)$r['paid_amount'];
                $g_due += (float)$r['due_amount'];

                $inv_links[] = '<a href="invoice.php?id=' . (int)$r['sale_id'] . '" class="text-dark font-weight-bold text-decoration-none" target="_blank">' . htmlspecialchars($r['invoice_no']) . '</a>';
                $inv_texts[] = htmlspecialchars($r['invoice_no']);

                $b_raw = $r['sale_date'] ?: '';
                $d_raw = $r['delivery_date'] ?: $r['sale_date'];

                if ($b_raw) {
                  $bf = date('d-m-Y', strtotime($b_raw));
                  if (!in_array($bf, $b_dates, true)) $b_dates[] = $bf;
                }
                if ($d_raw) {
                  $df = date('d-m-Y', strtotime($d_raw));
                  if (!in_array($df, $d_dates, true)) $d_dates[] = $df;
                }
              }

              // Booking Date formatting
              if (empty($b_dates)) {
                $b_display = '—';
              } elseif (count($b_dates) === 1) {
                $b_display = $b_dates[0];
              } elseif (count($b_dates) === 2) {
                $b_display = date('d/m', strtotime($b_dates[0])) . ', ' . date('d/m', strtotime($b_dates[1]));
              } else {
                $b_vals = array_filter(array_map(fn($r) => $r['sale_date'], $g['rows']));
                $min_b = $b_vals ? min($b_vals) : '';
                $max_b = $b_vals ? max($b_vals) : '';
                $b_display = ($min_b && $max_b) ? date('d/m', strtotime($min_b)) . ' to ' . date('d/m', strtotime($max_b)) : '—';
              }

              // Delivery Date formatting
              if (empty($d_dates)) {
                $d_display = '—';
              } elseif (count($d_dates) === 1) {
                $d_display = $d_dates[0];
              } elseif (count($d_dates) === 2) {
                $d_display = date('d/m', strtotime($d_dates[0])) . ', ' . date('d/m', strtotime($d_dates[1]));
              } else {
                $min_d = min(array_map(fn($r) => $r['delivery_date'] ?: $r['sale_date'], $g['rows']));
                $max_d = max(array_map(fn($r) => $r['delivery_date'] ?: $r['sale_date'], $g['rows']));
                $d_display = date('d/m', strtotime($min_d)) . ' to ' . date('d/m', strtotime($max_d));
              }
            ?>
            <tr class="dsr-item-row">
              <td class="text-center font-weight-bold text-muted"><?=$i?></td>
              <td class="text-left font-weight-bold text-dark customer-col">
                <?php if (!empty($g['customer_no'])): ?>
                  <span class="code-badge text-muted"><?=htmlspecialchars($g['customer_no'])?>:</span>
                <?php endif; ?>
                <?=htmlspecialchars($g['name'])?>
              </td>
              <td class="text-left area-col"><?=htmlspecialchars($g['area'] ?? '—')?></td>
              <td class="text-center text-nowrap phone-col"><?=htmlspecialchars($g['phone'] ?? '—')?></td>
              <td class="text-center invoice-col">
                <span class="d-print-none"><?=implode(', ', $inv_links)?></span>
                <span class="d-none d-print-inline"><?=implode(', ', $inv_texts)?></span>
                <?php if (count($g['rows']) > 1): ?>
                  <span class="badge badge-secondary ml-1" style="font-size: 10px;"><?=count($g['rows'])?></span>
                <?php endif; ?>
              </td>
              <td class="text-center text-nowrap date-col">
                <div class="date-entry" title="Booking Date">
                  <span class="date-tag">Book:</span><span class="date-val text-dark"><?=$b_display?></span>
                </div>
                <div class="date-entry" title="Delivery Date">
                  <span class="date-tag">Del:</span><span class="date-val text-primary"><?=$d_display?></span>
                </div>
              </td>
              <td class="text-right font-weight-bold text-dark text-nowrap amount-col"><?=formatCurrency($g_sales)?></td>
              <td class="text-right font-weight-bold text-nowrap amount-col <?= $g_discount > 0 ? 'text-danger' : 'text-muted'?>"><?= $g_discount > 0 ? formatCurrency($g_discount) : '—' ?></td>
              <td class="text-right font-weight-bold text-success text-nowrap amount-col"><?=formatCurrency($g_paid)?></td>
              <td class="text-right font-weight-bold text-nowrap amount-col <?= $g_due > 0 ? 'text-danger' : 'text-success'?>"><?=formatCurrency($g_due)?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Printable Signatures Footer (Print Only) -->
      <div class="dsr-signatures-grid mt-4 d-none d-print-block">
        <div class="row text-center">
          <div class="col-4">
            <div class="sig-line">Salesman / Driver Signature</div>
          </div>
          <div class="col-4">
            <div class="sig-line">Prepared By (<?=htmlspecialchars($printed_by ?: 'Office')?>)</div>
          </div>
          <div class="col-4">
            <div class="sig-line">Verified / Office Signature</div>
          </div>
        </div>
      </div>

      <!-- Bottom Page Timestamp & Pagination Stamp -->
      <div class="dsr-sheet-footer d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
        <div class="small text-muted font-weight-bold"><?=date('h:i A, d-m-Y')?></div>
        <div class="small text-muted d-none d-print-block">Mehboob Traders &middot; Customer Sales Summary</div>
        <div class="small text-muted font-weight-bold">Summary Report</div>
      </div>

    <?php endif; ?>

  </div>
</div>

<style>
/* ============================================================
   SCREEN STYLES (Modern, High-Contrast & Highly Readable)
   ============================================================ */
.dsr-load-table {
  width: 100% !important;
  border-collapse: collapse !important;
  border: 1px solid #cbd5e1 !important;
  background: #ffffff;
}
.dsr-load-table th {
  background: #f8fafc !important;
  color: #1e293b !important;
  border: 1px solid #cbd5e1 !important;
  padding: 10px 8px !important;
  font-weight: 700 !important;
  font-size: 13px !important;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  vertical-align: middle !important;
}
.dsr-load-table td {
  border: 1px solid #e2e8f0 !important;
  padding: 8px 8px !important;
  color: #0f172a !important;
  vertical-align: middle !important;
  font-size: 13.5px !important;
  line-height: 1.3 !important;
}
.dsr-load-table tr:hover td {
  background-color: #f8fafc;
}
.dsr-load-table .customer-col {
  font-size: 14px !important;
  font-weight: 700 !important;
}
.dsr-load-table .date-col {
  font-size: 12px !important;
  line-height: 1.25 !important;
}
.dsr-load-table .date-entry {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
}
.dsr-load-table .date-tag {
  font-weight: 700;
  color: #64748b;
  font-size: 10.5px;
  text-transform: uppercase;
}
.dsr-load-table .date-val {
  font-weight: 700;
}
.dsr-load-table .amount-col {
  font-size: 13.5px !important;
  font-weight: 700 !important;
}
.dsr-total-row th {
  background: #f1f5f9 !important;
  border-top: 2px solid #334155 !important;
  font-size: 13.5px !important;
  padding: 10px 8px !important;
}

/* ============================================================
   PRINT STYLES (Ultra-Readable, 1 Line Per Customer, Low Page Count)
   ============================================================ */
@media print {
  @page {
    size: A4 portrait;
    margin: 8mm 6mm 6mm 6mm;
  }

  body {
    background: #ffffff !important;
    color: #000000 !important;
    font-size: 12px !important;
    line-height: 1.25 !important;
    font-family: Arial, Helvetica, sans-serif !important;
    margin: 0 !important;
    padding: 0 !important;
  }

  .sidebar, .navbar, .card-header, form, .btn, .d-print-none, #scrollToTop, footer, .sticky-footer {
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
  }

  /* Header Box */
  .dsr-header-box {
    background-color: #f1f5f9 !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    border: 1.5px solid #000000 !important;
    padding: 5px 8px !important;
    text-align: center !important;
    margin-bottom: 6px !important;
  }
  .dsr-brand-title {
    font-size: 18px !important;
    font-weight: 900 !important;
    color: #000000 !important;
    letter-spacing: 0.5px !important;
    line-height: 1.2 !important;
  }
  .dsr-brand-subtitle {
    font-size: 13px !important;
    font-weight: 800 !important;
    color: #000000 !important;
    line-height: 1.2 !important;
  }

  /* 3-Column Metadata */
  .dsr-meta-grid {
    display: flex !important;
    justify-content: space-between !important;
    align-items: flex-start !important;
    border-bottom: 1.5px solid #000000 !important;
    padding-bottom: 5px !important;
    margin-bottom: 6px !important;
    font-size: 11px !important;
    line-height: 1.35 !important;
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
    font-size: 11px !important;
  }
  .dsr-meta-col .meta-val {
    color: #000000 !important;
    font-size: 12px !important;
    font-weight: 700 !important;
  }

  .dsr-booking-box {
    border: 1.5px solid #000000 !important;
    padding: 2px 8px !important;
    background: #ffffff !important;
    display: inline-block !important;
  }
  .dsr-booking-lbl {
    font-size: 11px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
  }
  .dsr-booking-val {
    font-size: 15px !important;
    font-weight: 900 !important;
    color: #000000 !important;
    margin-left: 4px !important;
  }

  /* Full Width Table in Print */
  .dsr-load-table {
    border: 1.5px solid #000000 !important;
    margin-bottom: 6px !important;
    table-layout: fixed !important;
    width: 100% !important;
  }
  .dsr-load-table thead {
    display: table-header-group !important;
  }
  .dsr-load-table th {
    background-color: #f1f5f9 !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    border: 1px solid #000000 !important;
    padding: 5px 6px !important;
    font-size: 11.5px !important;
    font-weight: 800 !important;
    color: #000000 !important;
    text-transform: uppercase !important;
    line-height: 1.2 !important;
  }
  .dsr-load-table td {
    border: 1px solid #000000 !important;
    padding: 5px 6px !important;
    font-size: 12px !important;
    line-height: 1.25 !important;
    color: #000000 !important;
    background: transparent !important;
  }
  .dsr-load-table tr {
    page-break-inside: avoid !important;
  }

  .dsr-load-table .customer-col {
    font-size: 12.5px !important;
    font-weight: 700 !important;
    color: #000000 !important;
  }
  .dsr-load-table .date-col {
    font-size: 11px !important;
    line-height: 1.2 !important;
  }
  .dsr-load-table .date-entry {
    display: block !important;
    white-space: nowrap !important;
  }
  .dsr-load-table .date-tag {
    color: #000000 !important;
    font-size: 10px !important;
    font-weight: 800 !important;
    display: inline-block !important;
    min-width: 32px !important;
    text-align: right !important;
    margin-right: 2px !important;
  }
  .dsr-load-table .date-val {
    font-weight: 700 !important;
    color: #000000 !important;
  }
  .dsr-load-table .amount-col {
    font-size: 12.5px !important;
    font-weight: 700 !important;
    color: #000000 !important;
  }

  .dsr-total-row th {
    border-top: 1.5px solid #000000 !important;
    border-bottom: 1.5px solid #000000 !important;
    background-color: #f1f5f9 !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    font-size: 12.5px !important;
    padding: 6px 6px !important;
    color: #000000 !important;
  }

  /* Signatures */
  .dsr-signatures-grid {
    margin-top: 15px !important;
    page-break-inside: avoid !important;
  }
  .sig-line {
    border-top: 1px dashed #000000 !important;
    padding-top: 4px !important;
    font-size: 10.5px !important;
    font-weight: 700 !important;
    color: #000000 !important;
  }

  /* Footer */
  .dsr-sheet-footer {
    border-top: 1px solid #000000 !important;
    margin-top: 6px !important;
    padding-top: 3px !important;
    font-size: 10px !important;
    color: #000000 !important;
    page-break-inside: avoid !important;
  }
}
</style>

<script>
$(document).ready(function(){
  function esc(s){ return $('<div>').text(s||'').html(); }
  function hideList($list){ $list.empty().hide(); }
  hideList($('#salesmanList'));
  hideList($('#obList'));

  // ===== LIVE TABLE FILTER =====
  $('#tableQuickSearch').on('keyup input', function(){
    var val = $.trim($(this).val()).toLowerCase();
    $('#summaryTable tbody tr.dsr-item-row').each(function(){
      var rowText = $(this).text().toLowerCase();
      $(this).toggle(rowText.indexOf(val) > -1);
    });
  });

  // ===== SALESMAN SEARCH =====
  var salesTimer = null;
  $('#salesmanSearch').on('input', function(){
    var q = $.trim(this.value);
    clearTimeout(salesTimer);
    if (!q) {
      $('#salesman_id').val('');
      hideList($('#salesmanList'));
      return;
    }
    salesTimer = setTimeout(function(){
      $.getJSON('ajax_salesman_search.php', {q: q}, function(data){
        var $list = $('#salesmanList');
        $list.empty();
        if (!data || !data.length) {
          $list.append('<div class="ac-item ac-empty">No salesman found</div>');
        } else {
          $.each(data, function(i, it){
            var sub = [];
            if (it.area) sub.push('Area: ' + esc(it.area));
            if (it.phone) sub.push('Phone: ' + esc(it.phone));
            $list.append(
              '<div class="ac-item" data-id="' + it.id + '">' +
              '<span class="ac-name">' + esc(it.full_name) + '</span>' +
              (sub.length ? '<small class="ac-sub">' + sub.join(' &middot; ') + '</small>' : '') +
              '</div>'
            );
          });
        }
        $list.show();
      });
    }, 250);
  });

  $('#salesmanList').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    $('#salesman_id').val($(this).data('id'));
    $('#salesmanSearch').val($(this).find('.ac-name').text());
    hideList($('#salesmanList'));
  });

  // ===== ORDER TAKER SEARCH =====
  var obTimer = null;
  $('#obSearch').on('input', function(){
    var q = $.trim(this.value);
    clearTimeout(obTimer);
    if (!q) {
      $('.ob-filter-id').remove();
      hideList($('#obList'));
      return;
    }
    obTimer = setTimeout(function(){
      $.getJSON('ajax_order_booker_search.php', {q: q}, function(data){
        var $list = $('#obList');
        $list.empty();
        if (!data || !data.length) {
          $list.append('<div class="ac-item ac-empty">No order taker found</div>');
        } else {
          $.each(data, function(i, it){
            var sub = [];
            if (it.phone) sub.push('Phone: ' + esc(it.phone));
            $list.append(
              '<div class="ac-item" data-id="' + it.id + '">' +
              '<span class="ac-name">' + esc(it.full_name) + '</span>' +
              (sub.length ? '<small class="ac-sub">' + sub.join(' &middot; ') + '</small>' : '') +
              '</div>'
            );
          });
        }
        $list.show();
      });
    }, 250);
  });

  $('#obList').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    $('.ob-filter-id').remove();
    $('<input>', { type: 'hidden', name: 'order_booker_id[]', 'class': 'ob-filter-id', value: $(this).data('id') }).appendTo($('#obFilterHolder'));
    $('#obSearch').val($(this).find('.ac-name').text());
    hideList($('#obList'));
  });

  $(document).on('keydown', '#salesmanSearch, #obSearch', function(e){
    var $list = $(this).attr('id') === 'salesmanSearch' ? $('#salesmanList') : $('#obList');
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
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>