<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Order Booker Invoices & Profit';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin', 'order_booker']);

// Determine target order booker
$is_admin = isAdmin();
$current_user_id = (int)$_SESSION['user_id'];

// Non-admin order bookers can only view their own invoices
if (!$is_admin) {
    $ob = (string)$current_user_id;
} else {
    $ob = isset($_GET['order_booker_id']) ? trim((string)$_GET['order_booker_id']) : '';
}

// Fetch all order bookers for selector / landing screen
$bookers_stmt = $pdo->query("
    SELECT u.id, u.username, u.full_name, u.phone, e.area,
           (SELECT COUNT(*) FROM sales s WHERE s.created_by = u.id AND s.status <> 'cancelled') AS invoice_count,
           (SELECT COALESCE(SUM(s.total_amount), 0) FROM sales s WHERE s.created_by = u.id AND s.status <> 'cancelled') AS total_sales,
           (SELECT COALESCE(SUM((SELECT COALESCE(SUM(p.purchase_price * si.quantity / GREATEST(COALESCE(p.boxes_per_carton,1),1)), 0)
                                 FROM sale_items si JOIN products p ON si.product_id = p.id WHERE si.sale_id = s2.id)), 0)
            FROM sales s2 WHERE s2.created_by = u.id AND s2.status <> 'cancelled') AS total_cost
    FROM users u
    LEFT JOIN employees e ON e.user_id = u.id
    WHERE u.role = 'order_booker' AND u.status = 1
    ORDER BY u.full_name ASC
");
$all_bookers = $bookers_stmt->fetchAll();

// If booker is selected (or 'all')
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';
$sup  = $_GET['salesman_id'] ?? '';
$area = trim($_GET['area'] ?? '');

$area_options = $is_admin ? allKnownAreas($pdo) : (array)currentUserAreas($pdo);
if ($area !== '' && !in_array($area, $area_options, true)) { $area = ''; }

$salesmen = $pdo->query("SELECT id, full_name, phone FROM employees WHERE employee_type = 'salesman' AND status = 1 ORDER BY full_name ASC")->fetchAll();

$selected_booker = null;
if ($ob !== '' && $ob !== 'all') {
    $sb_stmt = $pdo->prepare("
        SELECT u.id, u.username, u.full_name, u.phone, e.area
        FROM users u
        LEFT JOIN employees e ON e.user_id = u.id
        WHERE u.id = ?
    ");
    $sb_stmt->execute([(int)$ob]);
    $selected_booker = $sb_stmt->fetch();
}

$sales = [];
$total_sales = 0;
$total_cost  = 0;
$total_profit= 0;
$total_paid  = 0;
$total_due   = 0;

if ($ob !== '') {
    $sql = "SELECT s.*, 
                   c.full_name AS customer_name, c.area AS customer_area, c.phone AS customer_phone,
                   e.full_name AS salesman_name,
                   u.id AS ob_id, u.full_name AS order_taker_name, u.username AS order_taker_username,
                   (SELECT COALESCE(SUM(p.purchase_price * si.quantity / GREATEST(COALESCE(p.boxes_per_carton,1),1)), 0)
                    FROM sale_items si JOIN products p ON si.product_id = p.id WHERE si.sale_id = s.id) AS total_cost
            FROM sales s
            LEFT JOIN customers c ON s.customer_id = c.id
            LEFT JOIN employees e ON s.salesman_id = e.id
            LEFT JOIN users u ON s.created_by = u.id
            WHERE s.status <> 'cancelled'";
    $params = [];

    if ($ob !== 'all') {
        $sql .= " AND s.created_by = ?";
        $params[] = (int)$ob;
    }

    if ($from) { $sql .= " AND s.sale_date >= ?"; $params[] = $from; }
    if ($to)   { $sql .= " AND s.sale_date <= ?"; $params[] = $to; }
    if ($sup !== '')  { $sql .= " AND s.salesman_id = ?"; $params[] = (int)$sup; }
    if ($area !== '') { $sql .= " AND LOWER(c.area) = LOWER(?)"; $params[] = $area; }

    $sql .= " ORDER BY s.sale_date DESC, s.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sales = $stmt->fetchAll();

    foreach ($sales as &$s) {
        $sale_amt = (float)$s['total_amount'];
        $cost_amt = (float)$s['total_cost'];
        $profit   = $sale_amt - $cost_amt;
        $margin   = $sale_amt > 0 ? ($profit / $sale_amt) * 100 : 0;

        $s['calc_profit'] = $profit;
        $s['calc_margin'] = $margin;

        $total_sales  += $sale_amt;
        $total_cost   += $cost_amt;
        $total_profit += $profit;
        $total_paid   += (float)$s['paid_amount'];
        $total_due    += (float)$s['due_amount'];
    }
    unset($s);
}

$overall_margin = $total_sales > 0 ? ($total_profit / $total_sales) * 100 : 0;

$sales_name = '';
if ($sup !== '') {
    $sn = $pdo->prepare("SELECT full_name FROM employees WHERE id = ?");
    $sn->execute([(int)$sup]);
    $sales_name = (string)$sn->fetchColumn();
}

$printed_by = '';
if (!empty($_SESSION['user_id'])) {
    $pu = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $pu->execute([(int)$_SESSION['user_id']]);
    $printed_by = (string)$pu->fetchColumn();
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow mb-4">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center d-print-none">
    <div class="d-flex align-items-center">
      <h6 class="mb-0 font-weight-bold text-primary mr-3">
        <i class="fas fa-user-tag mr-1"></i> Order Booker Invoices &amp; Profit
      </h6>
      <?php if ($selected_booker): ?>
        <span class="badge badge-primary px-2 py-1 font-weight-normal">
          <i class="fas fa-user mr-1"></i> <?=htmlspecialchars($selected_booker['full_name'])?>
          <?php if (!empty($selected_booker['username'])): ?>(@<?=htmlspecialchars($selected_booker['username'])?>)<?php endif; ?>
        </span>
      <?php elseif ($ob === 'all'): ?>
        <span class="badge badge-info px-2 py-1 font-weight-normal">
          <i class="fas fa-users mr-1"></i> All Order Bookers
        </span>
      <?php endif; ?>
    </div>
    <div class="d-flex flex-wrap align-items-center mt-2 mt-sm-0">
      <?php if ($is_admin && $ob !== ''): ?>
        <a href="order_booker_invoices.php" class="btn btn-sm btn-outline-secondary mr-2">
          <i class="fas fa-exchange-alt mr-1"></i> Change Order Booker
        </a>
      <?php endif; ?>
      <a href="invoices.php" class="btn btn-sm btn-outline-primary mr-2">
        <i class="fas fa-file-invoice mr-1"></i> All Invoices
      </a>
      <a href="index.php" class="btn btn-sm btn-success mr-2">
        <i class="fas fa-plus mr-1"></i> New Order
      </a>
      <?php if ($ob !== ''): ?>
        <button type="button" class="btn btn-sm btn-primary" onclick="window.print()">
          <i class="fas fa-print mr-1"></i> Print
        </button>
      <?php endif; ?>
    </div>
  </div>

  <div class="card-body">

    <?php if ($ob === ''): ?>
      <!-- ========================================== -->
      <!-- LANDING SCREEN: ORDER BOOKER SELECTION     -->
      <!-- ========================================== -->
      <div class="py-3">
        <div class="text-center mb-4">
          <div class="d-inline-flex align-items-center justify-content-center bg-light rounded-circle mb-2" style="width: 60px; height: 60px;">
            <i class="fas fa-user-check fa-2x text-primary"></i>
          </div>
          <h4 class="font-weight-bold text-gray-800 mb-1">Select an Order Booker</h4>
          <p class="text-muted">Choose an order booker to view their sales invoices and profit margin.</p>
        </div>

        <div class="row justify-content-center mb-4">
          <div class="col-md-7 col-lg-6">
            <div class="input-group input-group-lg shadow-sm">
              <div class="input-group-prepend">
                <span class="input-group-text bg-white border-right-0"><i class="fas fa-search text-muted"></i></span>
              </div>
              <input type="text" id="bookerQuickFilter" class="form-control border-left-0" placeholder="Search order booker by name, username, or area..." autocomplete="off">
              <?php if (count($all_bookers) > 0): ?>
                <div class="input-group-append">
                  <a href="order_booker_invoices.php?order_booker_id=all" class="btn btn-outline-secondary" title="View invoices for all order bookers">
                    <i class="fas fa-users"></i> All Bookers
                  </a>
                </div>
              <?php endif; ?>
            </div>
            <small class="text-muted d-block mt-1 text-center">Type any letter to filter the list below instantly.</small>
          </div>
        </div>

        <div class="row" id="bookerCardsRow">
          <?php if (empty($all_bookers)): ?>
            <div class="col-12 text-center text-muted py-5">
              <i class="fas fa-user-slash fa-3x mb-3 text-secondary"></i>
              <p>No active order bookers found in the system.</p>
              <?php if ($is_admin): ?>
                <a href="../employees/create.php" class="btn btn-primary btn-sm"><i class="fas fa-user-plus"></i> Add Order Booker</a>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <?php foreach ($all_bookers as $b): ?>
              <?php
                $b_sales  = (float)$b['total_sales'];
                $b_cost   = (float)$b['total_cost'];
                $b_profit = $b_sales - $b_cost;
                $b_margin = $b_sales > 0 ? ($b_profit / $b_sales) * 100 : 0;
              ?>
              <div class="col-md-6 col-lg-4 mb-3 booker-card-item" data-search="<?=htmlspecialchars(strtolower($b['full_name'] . ' ' . $b['username'] . ' ' . ($b['area'] ?? '') . ' ' . ($b['phone'] ?? '')) )?>">
                <div class="card h-100 border-left-primary shadow-sm hover-shadow transition">
                  <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                      <div>
                        <h5 class="font-weight-bold mb-0 text-gray-900"><?=htmlspecialchars($b['full_name'])?></h5>
                        <small class="text-muted">@<?=htmlspecialchars($b['username'])?></small>
                      </div>
                      <span class="badge badge-primary badge-pill px-2 py-1">
                        <?=(int)$b['invoice_count']?> Invoices
                      </span>
                    </div>

                    <div class="small text-muted mb-2">
                      <?php if (!empty($b['phone'])): ?>
                        <div><i class="fas fa-phone-alt fa-fw mr-1"></i> <?=htmlspecialchars($b['phone'])?></div>
                      <?php endif; ?>
                      <?php if (!empty($b['area'])): ?>
                        <div class="mt-1"><i class="fas fa-map-marker-alt fa-fw mr-1 text-danger"></i> <?=htmlspecialchars($b['area'])?></div>
                      <?php endif; ?>
                    </div>

                    <div class="bg-light rounded p-2 mb-3">
                      <div class="d-flex justify-content-between small mb-1">
                        <span class="text-muted">Total Sales:</span>
                        <strong class="text-dark">PKR <?=formatCurrency($b_sales)?></strong>
                      </div>
                      <div class="d-flex justify-content-between small">
                        <span class="text-muted">Est. Profit:</span>
                        <strong class="<?=$b_profit >= 0 ? 'text-success' : 'text-danger'?>">
                          PKR <?=formatCurrency($b_profit)?>
                          <?php if ($b_sales > 0): ?>
                            <small>(<?=number_format($b_margin, 1)?>%)</small>
                          <?php endif; ?>
                        </strong>
                      </div>
                    </div>

                    <a href="order_booker_invoices.php?order_booker_id=<?=$b['id']?>" class="btn btn-primary btn-block btn-sm">
                      <i class="fas fa-file-invoice mr-1"></i> View Invoices &amp; Profit <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <div id="noBookersFound" class="text-center py-5 d-none">
          <i class="fas fa-search fa-2x text-muted mb-2"></i>
          <p class="text-muted mb-0">No order bookers matched your search query.</p>
        </div>
      </div>

    <?php else: ?>
      <!-- ========================================== -->
      <!-- INVOICES & PROFIT REPORT VIEW              -->
      <!-- ========================================== -->

      <!-- PRINT HEADER -->
      <div class="report-sheet d-none d-print-block mb-3">
        <div class="report-head text-center pb-2 border-bottom">
          <h3 class="font-weight-bold mb-0" style="color: #0f172a;">MEHBOOB TRADERS</h3>
          <div class="small text-muted">Wholesale Business &middot; Lahore, Pakistan</div>
          <h5 class="font-weight-bold text-primary mt-2 mb-1">ORDER BOOKER INVOICES &amp; PROFIT REPORT</h5>
          <div class="small text-dark font-weight-bold">
            Order Booker: <?=htmlspecialchars($selected_booker ? $selected_booker['full_name'] : 'All Order Bookers')?>
            <?php if ($selected_booker && !empty($selected_booker['phone'])): ?>
              &middot; Phone: <?=htmlspecialchars($selected_booker['phone'])?>
            <?php endif; ?>
            <?php if ($from || $to): ?>
              &middot; Period: <?= $from ? formatDate($from) : 'Start' ?> to <?= $to ? formatDate($to) : 'Present' ?>
            <?php endif; ?>
            <?php if ($sales_name): ?>
              &middot; Salesman: <?=htmlspecialchars($sales_name)?>
            <?php endif; ?>
            <?php if ($area): ?>
              &middot; Area: <?=htmlspecialchars($area)?>
            <?php endif; ?>
          </div>
          <div class="small text-muted mt-1">Printed on <?=date('d-m-Y H:i')?> <?php if ($printed_by): ?>by <?=htmlspecialchars($printed_by)?><?php endif; ?></div>
        </div>

        <table class="report-summary-table mt-2 mb-3">
          <tr>
            <td class="rs-cell"><span class="rs-label">Total Invoices</span><span class="rs-val"><?=count($sales)?></span></td>
            <td class="rs-cell"><span class="rs-label">Total Sales</span><span class="rs-val">PKR <?=formatCurrency($total_sales)?></span></td>
            <td class="rs-cell"><span class="rs-label">Purchase Cost</span><span class="rs-val text-secondary">PKR <?=formatCurrency($total_cost)?></span></td>
            <td class="rs-cell"><span class="rs-label">Total Profit</span><span class="rs-val font-weight-bold" style="color: <?=$total_profit >= 0 ? '#0f766e' : '#b91c1c'?>;">PKR <?=formatCurrency($total_profit)?></span></td>
            <td class="rs-cell"><span class="rs-label">Profit Margin</span><span class="rs-val font-weight-bold" style="color: <?=$overall_margin >= 0 ? '#0f766e' : '#b91c1c'?>;"><?=number_format($overall_margin, 1)?>%</span></td>
            <td class="rs-cell"><span class="rs-label">Total Due</span><span class="rs-val" style="color: #b91c1c;">PKR <?=formatCurrency($total_due)?></span></td>
          </tr>
        </table>
      </div>

      <!-- FILTER TOOLBAR (SCREEN ONLY) -->
      <form method="get" class="mb-4 d-print-none bg-light p-3 rounded border">
        <input type="hidden" name="order_booker_id" value="<?=htmlspecialchars($ob)?>">
        <div class="form-row align-items-center">
          
          <?php if ($is_admin): ?>
          <div class="col-lg-3 col-md-4 mb-2">
            <label class="small text-muted mb-1 font-weight-bold"><i class="fas fa-user-tie"></i> Switch Order Booker</label>
            <select class="form-control form-control-sm" onchange="window.location.href='order_booker_invoices.php?order_booker_id=' + this.value + '<?= $from ? '&from=' . urlencode($from) : '' ?><?= $to ? '&to=' . urlencode($to) : '' ?>'">
              <option value="all" <?=$ob === 'all' ? 'selected' : ''?>>-- All Order Bookers --</option>
              <?php foreach ($all_bookers as $b): ?>
                <option value="<?=$b['id']?>" <?=$ob == $b['id'] ? 'selected' : ''?>>
                  <?=htmlspecialchars($b['full_name'])?> (@<?=htmlspecialchars($b['username'])?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <div class="col-lg-2 col-md-4 mb-2">
            <label class="small text-muted mb-1 font-weight-bold"><i class="fas fa-calendar-alt"></i> From Date</label>
            <input type="date" name="from" class="form-control form-control-sm" value="<?=htmlspecialchars($from)?>">
          </div>

          <div class="col-lg-2 col-md-4 mb-2">
            <label class="small text-muted mb-1 font-weight-bold"><i class="fas fa-calendar-alt"></i> To Date</label>
            <input type="date" name="to" class="form-control form-control-sm" value="<?=htmlspecialchars($to)?>">
          </div>

          <div class="col-lg-2 col-md-4 mb-2">
            <label class="small text-muted mb-1 font-weight-bold"><i class="fas fa-truck"></i> Salesman</label>
            <select name="salesman_id" class="form-control form-control-sm">
              <option value="">-- All Salesmen --</option>
              <?php foreach ($salesmen as $sm): ?>
                <option value="<?=$sm['id']?>" <?=$sup == $sm['id'] ? 'selected' : ''?>><?=htmlspecialchars($sm['full_name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-lg-2 col-md-4 mb-2">
            <label class="small text-muted mb-1 font-weight-bold"><i class="fas fa-map-marker-alt"></i> Area</label>
            <select name="area" class="form-control form-control-sm">
              <option value="">-- All Areas --</option>
              <?php foreach ($area_options as $ar): ?>
                <option value="<?=htmlspecialchars($ar)?>" <?=$area === $ar ? 'selected' : ''?>><?=htmlspecialchars($ar)?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-lg-1 col-md-4 mb-2 d-flex align-items-end">
            <button type="submit" class="btn btn-sm btn-primary btn-block mt-auto">
              <i class="fas fa-filter"></i> Filter
            </button>
          </div>

        </div>

        <!-- Table live search & clear filter -->
        <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
          <?php if ($from || $to || $sup !== '' || $area !== ''): ?>
            <a href="order_booker_invoices.php?order_booker_id=<?=urlencode($ob)?>" class="btn btn-xs btn-outline-secondary">
              <i class="fas fa-times mr-1"></i> Clear Filters
            </a>
          <?php else: ?>
            <div></div>
          <?php endif; ?>
          <div>
            <input type="text" id="invoiceTableSearch" class="form-control form-control-sm" placeholder="Live search invoice/customer..." style="max-width: 250px;">
          </div>
        </div>
      </form>

      <!-- KPI METRIC CARDS (SCREEN ONLY) -->
      <div class="row mb-4 d-print-none">
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
          <div class="card border-left-primary shadow-sm h-100 py-2">
            <div class="card-body py-1">
              <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Invoices</div>
              <div class="h5 mb-0 font-weight-bold text-gray-800"><?=count($sales)?></div>
              <small class="text-muted">Total orders recorded</small>
            </div>
          </div>
        </div>

        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
          <div class="card border-left-info shadow-sm h-100 py-2">
            <div class="card-body py-1">
              <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Sales</div>
              <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($total_sales)?></div>
              <small class="text-muted">Gross sale revenue</small>
            </div>
          </div>
        </div>

        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
          <div class="card border-left-secondary shadow-sm h-100 py-2">
            <div class="card-body py-1">
              <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">Purchase Cost</div>
              <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($total_cost)?></div>
              <small class="text-muted">At custom purchase rates</small>
            </div>
          </div>
        </div>

        <div class="col-xl-3 col-md-6 col-sm-6 mb-3">
          <div class="card border-left-success shadow-sm h-100 py-2">
            <div class="card-body py-1">
              <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Profit &amp; Margin</div>
              <div class="h5 mb-0 font-weight-bold <?=$total_profit >= 0 ? 'text-success' : 'text-danger'?>">
                PKR <?=formatCurrency($total_profit)?>
                <span class="badge badge-pill <?=$overall_margin >= 0 ? 'badge-success' : 'badge-danger'?> ml-1" style="font-size: 0.75rem;">
                  <?=number_format($overall_margin, 1)?>%
                </span>
              </div>
              <small class="text-muted">Sales minus purchase cost</small>
            </div>
          </div>
        </div>

        <div class="col-xl-3 col-md-6 col-sm-12 mb-3">
          <div class="card border-left-warning shadow-sm h-100 py-2">
            <div class="card-body py-1">
              <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Collections / Due</div>
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-success font-weight-bold small">Paid: PKR <?=formatCurrency($total_paid)?></div>
                  <div class="<?=$total_due > 0 ? 'text-danger font-weight-bold' : 'text-muted'?> small">Due: PKR <?=formatCurrency($total_due)?></div>
                </div>
                <div class="text-right">
                  <span class="badge badge-light border">
                    <?= $total_sales > 0 ? number_format(($total_paid / $total_sales) * 100, 0) : 0 ?>% Recv
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- INVOICES & PROFIT TABLE -->
      <div class="table-responsive">
        <table class="table table-bordered table-hover report-table" id="invoicesTable">
          <thead class="thead-light">
            <tr>
              <th style="width: 40px;">#</th>
              <th>Invoice No</th>
              <th>Date</th>
              <?php if ($ob === 'all'): ?>
                <th>Order Booker</th>
              <?php endif; ?>
              <th>Customer</th>
              <th>Area</th>
              <th>Salesman</th>
              <th class="text-right">Sale Total</th>
              <th class="text-right">Cost Total</th>
              <th class="text-right bg-light font-weight-bold text-success" style="min-width: 140px;">
                <i class="fas fa-chart-line mr-1"></i> Profit (Margin)
              </th>
              <th class="text-right">Paid</th>
              <th class="text-right">Due</th>
              <th class="text-center d-print-none" style="min-width: 110px;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($sales)): ?>
              <tr>
                <td colspan="<?=$ob === 'all' ? 13 : 12?>" class="text-center text-muted py-5">
                  <i class="fas fa-inbox fa-3x mb-3 text-secondary"></i>
                  <p class="mb-0">No sales invoices found for the selected criteria.</p>
                </td>
              </tr>
            <?php else: ?>
              <?php $i = 1; foreach ($sales as $s): ?>
                <tr>
                  <td><?=$i++?></td>
                  <td class="font-weight-bold text-primary">
                    <a href="invoice.php?id=<?=$s['id']?>" target="_blank" title="View Full Invoice"><?=htmlspecialchars($s['invoice_no'])?></a>
                  </td>
                  <td style="white-space: nowrap;"><?=formatDate($s['sale_date'])?></td>
                  <?php if ($ob === 'all'): ?>
                    <td>
                      <strong><?=htmlspecialchars($s['order_taker_name'] ?? '—')?></strong>
                    </td>
                  <?php endif; ?>
                  <td>
                    <div class="font-weight-bold text-dark"><?=htmlspecialchars($s['customer_name'] ?? 'Walk-in Customer')?></div>
                    <?php if (!empty($s['customer_phone'])): ?>
                      <small class="text-muted"><i class="fas fa-phone-alt fa-xs"></i> <?=htmlspecialchars($s['customer_phone'])?></small>
                    <?php endif; ?>
                  </td>
                  <td><?=htmlspecialchars($s['customer_area'] ?: '—')?></td>
                  <td><?=htmlspecialchars($s['salesman_name'] ?: '—')?></td>
                  <td class="text-right font-weight-bold">
                    PKR <?=formatCurrency($s['total_amount'])?>
                  </td>
                  <td class="text-right text-muted">
                    PKR <?=formatCurrency($s['total_cost'])?>
                  </td>
                  <td class="text-right bg-light font-weight-bold <?=$s['calc_profit'] >= 0 ? 'text-success' : 'text-danger'?>">
                    PKR <?=formatCurrency($s['calc_profit'])?>
                    <br>
                    <span class="badge badge-pill <?=$s['calc_margin'] >= 0 ? 'badge-success' : 'badge-danger'?>" style="font-size: 0.7rem;">
                      <?=number_format($s['calc_margin'], 1)?>%
                    </span>
                  </td>
                  <td class="text-right text-success">
                    PKR <?=formatCurrency($s['paid_amount'])?>
                  </td>
                  <td class="text-right <?=$s['due_amount'] > 0 ? 'text-danger font-weight-bold' : 'text-muted'?>">
                    PKR <?=formatCurrency($s['due_amount'])?>
                  </td>
                  <td class="text-center d-print-none" nowrap>
                    <a href="invoice.php?id=<?=$s['id']?>&print=1" class="btn btn-xs btn-outline-success" target="_blank" title="Print Invoice">
                      <i class="fas fa-print"></i>
                    </a>
                    <a href="invoice.php?id=<?=$s['id']?>" class="btn btn-xs btn-outline-primary" target="_blank" title="View Invoice">
                      <i class="fas fa-eye"></i>
                    </a>
                    <button type="button" class="btn btn-xs btn-outline-info btn-profit-breakdown" data-id="<?=$s['id']?>" data-inv="<?=htmlspecialchars($s['invoice_no'])?>" title="View Profit Breakdown">
                      <i class="fas fa-chart-pie"></i>
                    </button>
                    <?php if ($is_admin): ?>
                      <a href="sale_edit.php?id=<?=$s['id']?>" class="btn btn-xs btn-outline-warning" title="Edit Sale">
                        <i class="fas fa-edit"></i>
                      </a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          <tfoot class="report-tfoot font-weight-bold bg-light">
            <tr>
              <td colspan="<?=$ob === 'all' ? 7 : 6?>" class="text-right text-uppercase">
                Grand Total (<?=count($sales)?> Invoices):
              </td>
              <td class="text-right text-dark">
                PKR <?=formatCurrency($total_sales)?>
              </td>
              <td class="text-right text-muted">
                PKR <?=formatCurrency($total_cost)?>
              </td>
              <td class="text-right <?=$total_profit >= 0 ? 'text-success' : 'text-danger'?> bg-white">
                PKR <?=formatCurrency($total_profit)?>
                <br>
                <span class="badge badge-pill <?=$overall_margin >= 0 ? 'badge-success' : 'badge-danger'?>" style="font-size: 0.72rem;">
                  <?=number_format($overall_margin, 1)?>% Margin
                </span>
              </td>
              <td class="text-right text-success">
                PKR <?=formatCurrency($total_paid)?>
              </td>
              <td class="text-right <?=$total_due > 0 ? 'text-danger' : 'text-muted'?>">
                PKR <?=formatCurrency($total_due)?>
              </td>
              <td class="d-print-none"></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- PRINT FOOTER -->
      <div class="report-foot d-none d-print-block mt-4 pt-3 border-top">
        <div class="d-flex justify-content-between">
          <div>
            <strong>Prepared by:</strong> <?=htmlspecialchars($printed_by ?: 'Administrator')?><br>
            <small class="text-muted">Mehboob Traders Management System</small>
          </div>
          <div class="text-center" style="min-width: 180px;">
            <div class="border-bottom pb-4 mb-1"></div>
            <strong>Order Booker Signature</strong>
          </div>
          <div class="text-right" style="min-width: 180px;">
            <div class="border-bottom pb-4 mb-1"></div>
            <strong>Authorized Signature</strong>
          </div>
        </div>
      </div>

    <?php endif; ?>

  </div>
</div>

<!-- ========================================== -->
<!-- PROFIT BREAKDOWN MODAL                     -->
<!-- ========================================== -->
<div class="modal fade" id="profitBreakdownModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white py-2">
        <h6 class="modal-title font-weight-bold" id="profitModalTitle">
          <i class="fas fa-chart-pie mr-1"></i> Invoice Profit Margin Breakdown
        </h6>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body p-3" id="profitModalBody">
        <div class="text-center py-4" id="profitModalLoader">
          <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
          <p class="text-muted mt-2 mb-0">Loading invoice profit details...</p>
        </div>
        <div id="profitModalContent" class="d-none">
          <div class="row mb-3 pb-2 border-bottom">
            <div class="col-sm-6">
              <div><strong>Invoice:</strong> <span id="pmInvNo" class="text-primary font-weight-bold"></span></div>
              <div><strong>Customer:</strong> <span id="pmCustomer"></span></div>
              <div class="small text-muted" id="pmCustInfo"></div>
            </div>
            <div class="col-sm-6 text-sm-right">
              <div><strong>Date:</strong> <span id="pmDate"></span></div>
              <div><strong>Order Booker:</strong> <span id="pmBooker"></span></div>
              <div><strong>Salesman:</strong> <span id="pmSalesman"></span></div>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-sm table-bordered">
              <thead class="thead-light">
                <tr>
                  <th>Product</th>
                  <th class="text-center">Qty (Boxes)</th>
                  <th class="text-right">Sale Price</th>
                  <th class="text-right">Sale Total</th>
                  <th class="text-right">Purchase Rate</th>
                  <th class="text-right">Cost Total</th>
                  <th class="text-right font-weight-bold text-success">Profit</th>
                  <th class="text-center font-weight-bold">Margin</th>
                </tr>
              </thead>
              <tbody id="pmItemsTbody">
              </tbody>
              <tfoot class="font-weight-bold bg-light" id="pmTfoot">
              </tfoot>
            </table>
          </div>

          <div class="alert alert-secondary py-2 px-3 small mb-0">
            <i class="fas fa-info-circle text-primary mr-1"></i>
            <strong>Note:</strong> Profit is calculated dynamically using each item's sale rate minus the product's custom purchase rate (cost per box).
          </div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<style>
.hover-shadow:hover {
  transform: translateY(-2px);
  box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}
.transition {
  transition: all 0.2s ease-in-out;
}
.btn-xs {
  padding: 0.15rem 0.4rem;
  font-size: 0.75rem;
  line-height: 1.4;
  border-radius: 0.2rem;
}
@media print {
  @page { size: A4 landscape; margin: 8mm; }
  .report-table th { font-size: 11px !important; padding: 5px 6px !important; }
  .report-table td { font-size: 11px !important; padding: 4px 6px !important; }
  .report-tfoot td { font-size: 11px !important; padding: 6px !important; }
  .rs-label { font-size: 9px !important; letter-spacing: 0.5px !important; }
  .rs-val   { font-size: 14px !important; }
}
</style>

<script>
$(document).ready(function(){
  // Fast filter on landing page booker cards
  $('#bookerQuickFilter').on('input', function(){
    var q = $.trim($(this).val()).toLowerCase();
    var matchCount = 0;
    $('.booker-card-item').each(function(){
      var text = $(this).data('search') || '';
      if (!q || text.indexOf(q) !== -1) {
        $(this).show();
        matchCount++;
      } else {
        $(this).hide();
      }
    });
    if (matchCount === 0) {
      $('#noBookersFound').removeClass('d-none');
    } else {
      $('#noBookersFound').addClass('d-none');
    }
  });

  // Fast live filter on invoices table
  $('#invoiceTableSearch').on('input', function(){
    var q = $.trim($(this).val()).toLowerCase();
    $('#invoicesTable tbody tr').each(function(){
      var text = $(this).text().toLowerCase();
      if (!q || text.indexOf(q) !== -1) {
        $(this).show();
      } else {
        $(this).hide();
      }
    });
  });

  // Profit breakdown modal
  $('.btn-profit-breakdown').on('click', function(){
    var saleId = $(this).data('id');
    var invNo = $(this).data('inv');

    $('#profitModalTitle').html('<i class="fas fa-chart-pie mr-1"></i> Invoice #' + invNo + ' Profit Margin Breakdown');
    $('#profitModalLoader').show();
    $('#profitModalContent').addClass('d-none');
    $('#profitBreakdownModal').modal('show');

    $.getJSON('ajax_invoice_profit_breakdown.php', {id: saleId}, function(res){
      $('#profitModalLoader').hide();
      if (res.error) {
        alert(res.error);
        $('#profitBreakdownModal').modal('hide');
        return;
      }
      $('#profitModalContent').removeClass('d-none');

      $('#pmInvNo').text(res.invoice_no);
      $('#pmCustomer').text(res.customer_name);
      var custInfo = [];
      if (res.customer_phone) custInfo.push('Phone: ' + res.customer_phone);
      if (res.customer_area) custInfo.push('Area: ' + res.customer_area);
      $('#pmCustInfo').text(custInfo.join(' | '));

      $('#pmDate').text(res.sale_date);
      $('#pmBooker').text(res.order_taker_name);
      $('#pmSalesman').text(res.salesman_name);

      var $tbody = $('#pmItemsTbody').empty();
      $.each(res.items, function(i, item){
        var profitClass = item.profit >= 0 ? 'text-success' : 'text-danger';
        var badgeClass = item.margin_pct >= 0 ? 'badge-success' : 'badge-danger';
        var tr = '<tr>' +
          '<td><strong>' + item.product_name + '</strong> <small class="text-muted">(' + item.product_code + ')</small></td>' +
          '<td class="text-center">' + item.quantity + ' <br><small class="text-muted">' + item.packaging_label + '</small></td>' +
          '<td class="text-right">PKR ' + parseFloat(item.sale_price).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>' +
          '<td class="text-right font-weight-bold">PKR ' + parseFloat(item.sale_subtotal).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>' +
          '<td class="text-right">PKR ' + parseFloat(item.cost_per_box).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + ' <br><small class="text-muted">Rate: ' + parseFloat(item.purchase_price).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '/ctn</small></td>' +
          '<td class="text-right text-muted">PKR ' + parseFloat(item.cost_total).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>' +
          '<td class="text-right font-weight-bold ' + profitClass + '">PKR ' + parseFloat(item.profit).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>' +
          '<td class="text-center"><span class="badge ' + badgeClass + '">' + item.margin_pct + '%</span></td>' +
          '</tr>';
        $tbody.append(tr);
      });

      var netProfitClass = res.net_profit >= 0 ? 'text-success' : 'text-danger';
      var netBadgeClass = res.margin_pct >= 0 ? 'badge-success' : 'badge-danger';
      var tfootHtml = '<tr>' +
        '<td colspan="3" class="text-right text-uppercase">Total:</td>' +
        '<td class="text-right">PKR ' + parseFloat(res.total_sale).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>' +
        '<td></td>' +
        '<td class="text-right">PKR ' + parseFloat(res.total_cost).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>' +
        '<td class="text-right ' + netProfitClass + '">PKR ' + parseFloat(res.net_profit).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>' +
        '<td class="text-center"><span class="badge ' + netBadgeClass + '">' + res.margin_pct + '%</span></td>' +
        '</tr>';

      if (res.discount_amount > 0) {
        tfootHtml += '<tr class="text-muted small">' +
          '<td colspan="3" class="text-right">Invoice Discount Applied:</td>' +
          '<td class="text-right text-danger">- PKR ' + parseFloat(res.discount_amount).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>' +
          '<td colspan="4"></td>' +
          '</tr>';
      }

      $('#pmTfoot').html(tfootHtml);
    }).fail(function(){
      $('#profitModalLoader').hide();
      alert('Failed to load profit details. Please try again.');
      $('#profitBreakdownModal').modal('hide');
    });
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
