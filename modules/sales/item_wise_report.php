<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Item-wise Sales Report';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

// ── Filters ──────────────────────────────────────────────────
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');

$sale_type = trim($_GET['sale_type'] ?? '');
if (!in_array($sale_type, ['counter', 'shop'], true)) { $sale_type = ''; }

// Order bookers (same multi-select pattern used on invoices.php)
$all_order_bookers = $pdo->query("SELECT id, full_name, username FROM users WHERE role = 'order_booker' AND status = 1 ORDER BY full_name ASC")->fetchAll();
$ob_ids = normalizeIdList($_GET['order_booker_id'] ?? []);
$ob_ids = array_values(array_intersect($ob_ids, array_map('intval', array_column($all_order_bookers, 'id'))));
$ob_names = [];
foreach ($all_order_bookers as $obk) {
    if (in_array((int)$obk['id'], $ob_ids, true)) { $ob_names[] = $obk['full_name']; }
}
$ob_name = implode(', ', $ob_names);

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
$sql .= " ORDER BY p.name ASC, u.full_name ASC, si.price DESC";
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

// ── Build item rows grouped by Product + Order Booker + Rate per Box ──
$remaining_returns = $returns;
$rows = [];
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
    $key  = $ln['product_id'] . '|' . $ln['ob_id'] . '|' . number_format($rate, 2, '.', '');

    if (!isset($rows[$key])) {
        $rows[$key] = [
            'product_code'  => $ln['product_code'],
            'product_name'  => $ln['product_name'],
            'category_name' => $ln['category_name'],
            'bpc'           => $bpc,
            'ob_name'       => $ln['ob_name'],
            'rate'          => $rate,
            'qty'           => 0,
            'sale'          => 0,
            'cost'          => 0,
        ];
    }
    $rows[$key]['qty']  += $net;
    $rows[$key]['sale'] += $net * $rate;
    $rows[$key]['cost'] += $net * $cost_per_box;
}

$rows = array_values($rows);
foreach ($rows as &$row) {
    $row['profit'] = $row['sale'] - $row['cost'];
    $row['margin'] = $row['sale'] > 0 ? ($row['profit'] / $row['sale']) * 100 : 0;
}
unset($row);

usort($rows, function ($a, $b) {
    $cmp = strcasecmp($a['product_name'], $b['product_name']);
    if ($cmp !== 0) return $cmp;
    $cmp = strcasecmp($a['ob_name'], $b['ob_name']);
    if ($cmp !== 0) return $cmp;
    return $b['rate'] <=> $a['rate'];
});

// ── Totals ──────────────────────────────────────────────────
$total_sale   = 0;
$total_cost   = 0;
$total_qty    = 0;
$total_profit = 0;
$products     = [];
foreach ($rows as $row) {
    $total_sale   += $row['sale'];
    $total_cost   += $row['cost'];
    $total_qty    += $row['qty'];
    $total_profit += $row['profit'];
    $products[$row['product_code']] = true;
}
$total_products = count($products);
$overall_margin = $total_sale > 0 ? ($total_profit / $total_sale) * 100 : 0;

// Invoice discounts for matching sales
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

// ── Filter labels for the print header ──────────────────────
$period_desc = 'All dates';
if ($from && $to) { $period_desc = formatDate($from) . ' to ' . formatDate($to); }
elseif ($from) { $period_desc = 'From ' . formatDate($from); }
elseif ($to) { $period_desc = 'Until ' . formatDate($to); }

$filter_note = [];
if ($sale_type !== '') $filter_note[] = 'Type: ' . ($sale_type === 'counter' ? 'Counter Sales' : 'Shop Orders');
if ($ob_name !== '') $filter_note[] = 'Order Booker: ' . $ob_name;
$filter_note = $filter_note ? ' &middot; ' . implode(' &middot; ', $filter_note) : '';

$printed_by = '';
if (!empty($_SESSION['user_id'])) {
    $pu = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $pu->execute([(int)$_SESSION['user_id']]);
    $printed_by = (string)$pu->fetchColumn();
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<style>
@media print {
  @page {
    size: A4 landscape;
    margin: 8mm 8mm 13mm 8mm;
    @bottom-left {
      content: "Mehboob Traders \B7  Item-wise Sales Report";
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
  .report-table th { font-size: 11px !important; padding: 5px 7px !important; }
  .report-table td { font-size: 12px !important; padding: 5px 7px !important; }
  tfoot.report-tfoot { display: table-row-group !important; page-break-inside: avoid !important; }
  tfoot.report-tfoot td { font-size: 12px !important; padding: 6px 7px !important; }
  .rs-label { font-size: 10px !important; letter-spacing: 0.6px !important; }
  .rs-val   { font-size: 16px !important; }
}
.profit-pos { color: #059669 !important; font-weight: 600; }
.profit-neg { color: #dc2626 !important; font-weight: 600; }
.kpi-stat-card { border-radius: 8px; }
</style>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center d-print-none">
    <h6 class="mb-0 font-weight-bold text-primary">
      <i class="fas fa-boxes mr-1"></i> Item-wise Sales Report
      <span class="badge badge-primary ml-1"><?=count($rows)?> rows</span>
    </h6>
    <div class="d-flex flex-wrap align-items-center mt-2 mt-sm-0">
      <?php if ($from || $to || $sale_type !== '' || $ob_ids): ?>
      <a href="item_wise_report.php" class="btn btn-sm btn-outline-danger mr-2"><i class="fas fa-undo mr-1"></i> Reset Filters</a>
      <?php endif; ?>
      <a href="invoices.php" class="btn btn-sm btn-outline-secondary mr-2"><i class="fas fa-file-invoice mr-1"></i> Invoices</a>
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
            <div class="report-title">Item-wise Sales Report</div>
            <div class="report-meta"><?=$period_desc?><?=$filter_note?></div>
          </div>
        </div>
      </div>

      <table class="report-summary-table mb-3">
        <tr>
          <td class="rs-cell"><span class="rs-label">Products</span><span class="rs-val"><?=number_format($total_products)?></span></td>
          <td class="rs-cell"><span class="rs-label">Total Boxes</span><span class="rs-val"><?=number_format($total_qty)?></span></td>
          <td class="rs-cell">
            <span class="rs-label">Total Sale</span>
            <span class="rs-val text-primary">PKR <?=formatCurrency($total_sale)?><?php if ($total_discount > 0): ?><br><small style="color:#0f766e;">(Net: PKR <?=formatCurrency($net_total_sale)?> &middot; Disc: PKR <?=formatCurrency($total_discount)?>)</small><?php endif; ?></span>
          </td>
          <td class="rs-cell"><span class="rs-label">Total Cost</span><span class="rs-val text-muted">PKR <?=formatCurrency($total_cost)?></span></td>
          <td class="rs-cell"><span class="rs-label">Total Profit</span><span class="rs-val <?= $total_profit >= 0 ? 'text-success' : 'text-danger' ?>">PKR <?=formatCurrency($total_profit)?></span></td>
          <td class="rs-cell"><span class="rs-label">Margin</span><span class="rs-val <?= $overall_margin >= 0 ? 'text-success' : 'text-danger' ?>"><?=number_format($overall_margin, 1)?>%</span></td>
        </tr>
      </table>
    </div>

    <!-- Filter Card -->
    <div class="card bg-light border shadow-sm mb-4 d-print-none">
      <div class="card-body py-3 px-3">
        <form method="get" id="itemFilterForm" class="row g-2 align-items-end">
          <div class="col-lg-2 col-md-4 col-6 mb-2">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="far fa-calendar-alt"></i> From Date</label>
            <input type="date" name="from" class="form-control" style="height: 38px;" value="<?=htmlspecialchars($from)?>" onchange="document.getElementById('itemFilterForm').submit();">
          </div>
          <div class="col-lg-2 col-md-4 col-6 mb-2">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="far fa-calendar-alt"></i> To Date</label>
            <input type="date" name="to" class="form-control" style="height: 38px;" value="<?=htmlspecialchars($to)?>" onchange="document.getElementById('itemFilterForm').submit();">
          </div>
          <div class="col-lg-2 col-md-4 col-6 mb-2">
            <label class="small font-weight-bold text-muted mb-1 d-block"><i class="fas fa-cash-register text-warning"></i> Sale Type</label>
            <select name="sale_type" class="form-control" style="height: 38px;" onchange="document.getElementById('itemFilterForm').submit();">
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
            <input type="text" id="itemSearch" class="form-control" style="height: 38px;" placeholder="Search product / code / booker..." autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault();}">
          </div>
        </form>
      </div>
    </div>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4 d-print-none">
      <div class="col-xl-2 col-md-4 col-6 mb-3">
        <div class="card border-left-primary shadow-sm h-100 kpi-stat-card">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Products</div>
            <div class="h5 mb-0 font-weight-bold text-gray-800"><?=number_format($total_products)?></div>
          </div>
        </div>
      </div>
      <div class="col-xl-2 col-md-4 col-6 mb-3">
        <div class="card border-left-secondary shadow-sm h-100 kpi-stat-card">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">Total Boxes</div>
            <div class="h5 mb-0 font-weight-bold text-gray-800"><?=number_format($total_qty)?></div>
          </div>
        </div>
      </div>
      <div class="col-xl-2 col-md-4 col-6 mb-3">
        <div class="card border-left-info shadow-sm h-100 kpi-stat-card">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Sale</div>
            <div class="h5 mb-0 font-weight-bold text-primary">PKR <?=formatCurrency($total_sale)?></div>
            <?php if ($total_discount > 0): ?>
            <div class="small font-weight-bold text-muted">Net: <span class="text-dark font-weight-bold">PKR <?=formatCurrency($net_total_sale)?></span> <small class="text-danger font-weight-bold">(Disc: PKR <?=formatCurrency($total_discount)?>)</small></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-xl-2 col-md-4 col-6 mb-3">
        <div class="card border-left-dark shadow-sm h-100 kpi-stat-card">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-muted text-uppercase mb-1">Total Cost</div>
            <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($total_cost)?></div>
          </div>
        </div>
      </div>
      <div class="col-xl-2 col-md-4 col-6 mb-3">
        <div class="card border-left-success shadow-sm h-100 kpi-stat-card">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Profit</div>
            <div class="h5 mb-0 font-weight-bold <?= $total_profit >= 0 ? 'text-success' : 'text-danger' ?>">PKR <?=formatCurrency($total_profit)?></div>
          </div>
        </div>
      </div>
      <div class="col-xl-2 col-md-4 col-6 mb-3">
        <div class="card border-left-warning shadow-sm h-100 kpi-stat-card">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Margin</div>
            <div class="h5 mb-0 font-weight-bold <?= $overall_margin >= 0 ? 'text-success' : 'text-danger' ?>"><?=number_format($overall_margin, 1)?>%</div>
          </div>
        </div>
      </div>
    </div>

    <?php if (!$rows): ?>
      <p class="text-muted text-center py-4 mb-0"><i class="fas fa-inbox fa-2x d-block mb-2"></i> No item sales found for the selected filters.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-bordered table-hover report-table" id="itemTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Code</th>
            <th>Product</th>
            <th>Category</th>
            <th>Order Booker</th>
            <th class="text-right">Rate / Box</th>
            <th class="text-center">Qty (Boxes)</th>
            <th class="text-right">Sale Amount</th>
            <th class="text-right">Cost</th>
            <th class="text-right">Profit</th>
            <th class="text-right">Margin %</th>
          </tr>
        </thead>
        <tbody id="itemTableBody">
          <?php foreach ($rows as $idx => $row): ?>
          <tr class="report-row"
              data-search="<?=htmlspecialchars(strtolower($row['product_code'] . ' ' . $row['product_name'] . ' ' . $row['category_name'] . ' ' . $row['ob_name'] . ' ' . number_format($row['rate'], 2)))?>">
            <td class="text-muted small"><?=$idx + 1?></td>
            <td><code class="small"><?=htmlspecialchars($row['product_code'])?></code></td>
            <td class="font-weight-heavy text-dark"><?=htmlspecialchars($row['product_name'])?></td>
            <td><span class="badge badge-light border"><?=htmlspecialchars($row['category_name'])?></span></td>
            <td><?=htmlspecialchars($row['ob_name'])?></td>
            <td class="text-right font-weight-bold">PKR <?=formatCurrency($row['rate'])?></td>
            <td class="text-center font-weight-bold">
              <?=number_format($row['qty'])?>
              <?php
                $ctns = (int)floor($row['qty'] / $row['bpc']);
                $rem  = $row['qty'] % $row['bpc'];
                if ($row['bpc'] > 1) {
                    echo '<div class="small text-muted font-weight-normal">' . $ctns . ' ctn' . ($rem > 0 ? ' + ' . $rem . ' b' : '') . '</div>';
                }
              ?>
            </td>
            <td class="text-right font-weight-bold text-dark">PKR <?=formatCurrency($row['sale'])?></td>
            <td class="text-right text-muted">PKR <?=formatCurrency($row['cost'])?></td>
            <td class="text-right <?= $row['profit'] >= 0 ? 'profit-pos' : 'profit-neg' ?>">PKR <?=formatCurrency($row['profit'])?></td>
            <td class="text-right font-weight-bold <?= $row['margin'] >= 0 ? 'text-success' : 'text-danger' ?>"><?=number_format($row['margin'], 1)?>%</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="report-tfoot font-weight-bold bg-light">
          <tr>
            <td colspan="5" class="text-right">TOTAL (<?=number_format($total_products)?> products):</td>
            <td></td>
            <td class="text-center"><?=number_format($total_qty)?> boxes</td>
            <td class="text-right text-dark">PKR <?=formatCurrency($total_sale)?></td>
            <td class="text-right text-muted">PKR <?=formatCurrency($total_cost)?></td>
            <td class="text-right <?= $total_profit >= 0 ? 'profit-pos' : 'profit-neg' ?>">PKR <?=formatCurrency($total_profit)?></td>
            <td class="text-right <?= $overall_margin >= 0 ? 'text-success' : 'text-danger' ?>"><?=number_format($overall_margin, 1)?>%</td>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="report-foot d-none d-print-block">
      <div><strong>Prepared by:</strong> <?=htmlspecialchars($printed_by ?: '—')?></div>
      <div><strong>Printed on:</strong> <?=date('d-m-Y H:i')?></div>
      <div>Mehboob Traders &middot; Item-wise Sales Report</div>
    </div>
    <?php endif; ?>

  </div>
</div>

<script>
$(document).ready(function(){
  // ── Order booker multi-select: keep the button label in step with ticks ──
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

  refreshObFilterLabel();

  // ── Live product/code/booker search ──
  $('#itemSearch').on('input keyup paste', function(){
    var terms = $.trim($(this).val()).toLowerCase().split(/\s+/).filter(Boolean);
    $('#itemTableBody tr.report-row').each(function(){
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
