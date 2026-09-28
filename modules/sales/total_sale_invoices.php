<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Total Sale Invoices';
$compact_page_heading = true; // hide the redundant global h1 (title already in topbar + card header)
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin', 'order_booker']);

$my_areas = currentUserAreas($pdo); // null = admin (all areas)
$all_known = allKnownAreas($pdo);
$allowed_areas = isAdmin() ? $all_known : (array)$my_areas;

// Filters
$today = date('Y-m-d');
$from = isset($_GET['from']) ? trim($_GET['from']) : '';
$to = isset($_GET['to']) ? trim($_GET['to']) : '';
$area = trim($_GET['area'] ?? '');
$salesman_id = (int)($_GET['salesman_id'] ?? 0);
$ob = $_GET['order_booker_id'] ?? '';
$q = trim($_GET['q'] ?? '');

if ($area !== '' && !in_array($area, $allowed_areas, true)) {
    $area = '';
}

// Dropdown options
$all_salesmen = $pdo->query("SELECT id, full_name FROM employees WHERE employee_type = 'salesman' AND status = 1 ORDER BY full_name ASC")->fetchAll();
$all_order_bookers = $pdo->query("SELECT id, full_name, username FROM users WHERE role = 'order_booker' AND status = 1 ORDER BY full_name ASC")->fetchAll();

// Build Sales Query
$sql = "SELECT 
            s.id AS sale_id,
            s.invoice_no,
            s.sale_date,
            s.delivery_date,
            s.total_amount,
            s.discount_amount,
            s.paid_amount,
            s.due_amount,
            s.status,
            s.notes,
            s.created_by,
            c.id AS customer_id,
            c.customer_no,
            c.full_name AS customer_name,
            c.phone AS customer_phone,
            c.cnic AS customer_cnic,
            c.address AS customer_address,
            c.city AS customer_city,
            c.area AS customer_area,
            e.id AS salesman_id,
            e.full_name AS salesman_name,
            u.id AS order_booker_id,
            u.full_name AS order_booker_name,
            u.username AS order_booker_username
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN employees e ON s.salesman_id = e.id
        LEFT JOIN users u ON s.created_by = u.id
        WHERE s.status <> 'cancelled'";

$params = [];

if ($from !== '') {
    $sql .= " AND COALESCE(s.delivery_date, s.sale_date) >= ?";
    $params[] = $from;
}
if ($to !== '') {
    $sql .= " AND COALESCE(s.delivery_date, s.sale_date) <= ?";
    $params[] = $to;
}
if ($area !== '') {
    $sql .= " AND LOWER(c.area) = LOWER(?)";
    $params[] = $area;
} elseif ($my_areas !== null) {
    if (empty($my_areas)) {
        $sql .= " AND 1=0";
    } else {
        $in_placeholders = implode(',', array_fill(0, count($my_areas), '?'));
        $sql .= " AND c.area IN ($in_placeholders)";
        $params = array_merge($params, $my_areas);
    }
}
if ($salesman_id > 0) {
    $sql .= " AND s.salesman_id = ?";
    $params[] = $salesman_id;
}
if (!isAdmin()) {
    $sql .= " AND s.created_by = ?";
    $params[] = (int)$_SESSION['user_id'];
} elseif ($ob !== '') {
    $sql .= " AND s.created_by = ?";
    $params[] = $ob;
}
if ($q !== '') {
    $sql .= " AND (s.invoice_no LIKE ? OR c.full_name LIKE ? OR c.customer_no LIKE ? OR c.phone LIKE ? OR c.area LIKE ? OR e.full_name LIKE ?)";
    $params = array_merge($params, ["%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%"]);
}

$sql .= " ORDER BY COALESCE(s.delivery_date, s.sale_date) ASC, s.id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch items for all matched sales
$sale_ids = array_column($sales, 'sale_id');
$items_by_sale = [];

if (!empty($sale_ids)) {
    $in_sale_ids = implode(',', array_map('intval', $sale_ids));
    $items_sql = "SELECT 
                    si.id,
                    si.sale_id,
                    si.product_id,
                    si.quantity,
                    si.price,
                    si.subtotal,
                    p.code AS product_code,
                    p.name AS product_name,
                    p.boxes_per_carton,
                    p.unit
                  FROM sale_items si
                  JOIN products p ON si.product_id = p.id
                  WHERE si.sale_id IN ($in_sale_ids)
                  ORDER BY si.id ASC";
    $items_stmt = $pdo->query($items_sql);
    $all_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_items as $it) {
        $sid = $it['sale_id'];
        $bpc = max((int)($it['boxes_per_carton'] ?? 1), 1);
        $qty = (int)$it['quantity'];

        if ($bpc > 1) {
            $cartons = intdiv($qty, $bpc);
            $loose = $qty % $bpc;
        } else {
            $cartons = 0;
            $loose = $qty;
        }

        $it['cartons'] = $cartons;
        $it['loose'] = $loose;
        $it['bpc'] = $bpc;

        $items_by_sale[$sid][] = $it;
    }
}

// Attach items to each sale and calculate totals
$grand_total_vouchers = count($sales);
$grand_total_items = 0;
$grand_total_cartons = 0;
$grand_total_boxes = 0;
$grand_total_amount = 0;

foreach ($sales as &$s) {
    $sid = $s['sale_id'];
    $s['items'] = $items_by_sale[$sid] ?? [];

    $s_cartons = 0;
    $s_boxes = 0;
    $s_amount = 0;
    $prod_names = [];

    foreach ($s['items'] as $it) {
        $s_cartons += $it['cartons'];
        $s_boxes += $it['loose'];
        $s_amount += (float)$it['subtotal'];
        $grand_total_items++;
        $prod_names[] = $it['product_name'] . ' ' . $it['product_code'];
    }

    $s['total_cartons'] = $s_cartons;
    $s['total_boxes'] = $s_boxes;
    $s['calculated_amount'] = $s_amount;
    $s['product_search_blob'] = implode(' ', $prod_names);

    $grand_total_cartons += $s_cartons;
    $grand_total_boxes += $s_boxes;
    $grand_total_amount += (float)$s['total_amount'];
}
unset($s);

// Format helper for numbers (trade price / amount)
function fmtNum($n) {
    $f = (float)$n;
    if (floor($f) == $f) {
        return number_format($f, 0);
    }
    return number_format($f, 2);
}

// Labels for filter summary
$period_desc = 'All Dates';
if ($from && $to) {
    $period_desc = ($from === $to) ? formatDate($from) : formatDate($from) . ' to ' . formatDate($to);
} elseif ($from) {
    $period_desc = 'From ' . formatDate($from);
} elseif ($to) {
    $period_desc = 'Until ' . formatDate($to);
}

$salesman_label = 'All Salesmen';
if ($salesman_id > 0) {
    foreach ($all_salesmen as $sm) {
        if ((int)$sm['id'] === $salesman_id) {
            $salesman_label = $sm['full_name'];
            break;
        }
    }
}

$area_label = $area !== '' ? $area : 'All Areas';

$ob_name = '';
if ($ob !== '' && isAdmin()) {
    $on = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $on->execute([$ob]);
    $ob_name = (string)$on->fetchColumn();
}

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
          <i class="fas fa-file-invoice-dollar fa-lg"></i>
        </div>
        <div>
          <h5 class="mb-0 font-weight-bold text-dark">
            Total Sale Invoices
          </h5>
        </div>
      </div>
    <div class="d-flex flex-wrap mt-2 mt-md-0">
      <?php if (!empty($sales)): ?>
      <button type="button" class="btn btn-sm btn-primary shadow-sm mr-2" onclick="window.print()">
        <i class="fas fa-print mr-1"></i> Print Invoices (<?=count($sales)?>)
      </button>
      <?php endif; ?>
      <a href="invoices.php" class="btn btn-sm btn-outline-secondary mr-2">
        <i class="fas fa-file-invoice mr-1"></i> Invoices
      </a>
      <a href="index.php" class="btn btn-sm btn-success">
        <i class="fas fa-plus mr-1"></i> Take Order
      </a>
    </div>
  </div>

  <div class="card-body p-3 p-md-4">

    <!-- Filters Form -->
    <form method="get" id="totalSaleInvoicesFilterForm" class="d-print-none mb-4 p-3 rounded-lg border bg-light shadow-sm">
      <div class="row g-2 align-items-end">
        <div class="col-lg-2 col-md-4 col-sm-6 mb-2">
          <label class="form-label font-weight-bold text-xs text-uppercase text-muted mb-1">From Date</label>
          <input type="date" name="from" id="fromDate" class="form-control form-control-sm" value="<?=htmlspecialchars($from)?>">
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-2">
          <label class="form-label font-weight-bold text-xs text-uppercase text-muted mb-1">To Date</label>
          <input type="date" name="to" id="toDate" class="form-control form-control-sm" value="<?=htmlspecialchars($to)?>">
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-2">
          <label class="form-label font-weight-bold text-xs text-uppercase text-muted mb-1">Area</label>
          <select name="area" class="form-control form-control-sm auto-submit-select">
            <option value="">-- All Areas --</option>
            <?php foreach ($allowed_areas as $an): ?>
            <option value="<?=htmlspecialchars($an)?>" <?= $area === $an ? 'selected' : '' ?>><?=htmlspecialchars($an)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-2">
          <label class="form-label font-weight-bold text-xs text-uppercase text-muted mb-1">Delivery Man</label>
          <select name="salesman_id" class="form-control form-control-sm auto-submit-select">
            <option value="">-- All Salesmen --</option>
            <?php foreach ($all_salesmen as $se): ?>
            <option value="<?=$se['id']?>" <?= $salesman_id === (int)$se['id'] ? 'selected' : '' ?>><?=htmlspecialchars($se['full_name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if (isAdmin()): ?>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-2">
          <label class="form-label font-weight-bold text-xs text-uppercase text-muted mb-1">Order Booker</label>
          <select name="order_booker_id" class="form-control form-control-sm auto-submit-select">
            <option value="">-- All Bookers --</option>
            <?php foreach ($all_order_bookers as $ob_user): ?>
            <option value="<?=$ob_user['id']?>" <?= (string)$ob === (string)$ob_user['id'] ? 'selected' : '' ?>><?=htmlspecialchars($ob_user['full_name'])?> (<?=htmlspecialchars($ob_user['username'])?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="col-lg-<?=isAdmin() ? '2' : '4'?> col-md-4 col-sm-6 mb-2">
          <label class="form-label font-weight-bold text-xs text-uppercase text-muted mb-1">Search Keywords</label>
          <input type="text" name="q" class="form-control form-control-sm" placeholder="Invoice / Customer / Phone..." value="<?=htmlspecialchars($q)?>">
        </div>
      </div>

      <div class="d-flex justify-content-end align-items-center pt-2 border-top mt-2">
        <a href="total_sale_invoices.php" class="btn btn-sm btn-outline-secondary mr-2"><i class="fas fa-undo mr-1"></i> Reset</a>
        <button type="submit" class="btn btn-sm btn-primary px-3 shadow-sm"><i class="fas fa-filter mr-1"></i> Apply Filter</button>
      </div>
    </form>

    <!-- On-Screen KPI Summary Cards -->
    <div class="row mb-4 d-print-none">
      <div class="col-xl-2 col-md-4 col-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100 kpi-stat-card border-left-primary">
          <div class="card-body p-3">
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="text-xs font-weight-bold text-muted text-uppercase mb-1">Vouchers</div>
                <div class="h5 mb-0 font-weight-bold text-gray-800"><?=$grand_total_vouchers?></div>
              </div>
              <div class="stat-icon text-primary bg-primary-soft">
                <i class="fas fa-receipt"></i>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-xl-2 col-md-4 col-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100 kpi-stat-card border-left-info">
          <div class="card-body p-3">
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="text-xs font-weight-bold text-muted text-uppercase mb-1">Total Items</div>
                <div class="h5 mb-0 font-weight-bold text-gray-800"><?=$grand_total_items?></div>
              </div>
              <div class="stat-icon text-info bg-info-soft">
                <i class="fas fa-boxes"></i>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-xl-2 col-md-4 col-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100 kpi-stat-card border-left-success">
          <div class="card-body p-3">
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="text-xs font-weight-bold text-muted text-uppercase mb-1">Full Cartons</div>
                <div class="h5 mb-0 font-weight-bold text-success"><?=$grand_total_cartons?></div>
              </div>
              <div class="stat-icon text-success bg-success-soft">
                <i class="fas fa-archive"></i>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-xl-3 col-md-6 col-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100 kpi-stat-card border-left-warning">
          <div class="card-body p-3">
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="text-xs font-weight-bold text-muted text-uppercase mb-1">Loose Boxes</div>
                <div class="h5 mb-0 font-weight-bold text-warning"><?=$grand_total_boxes?></div>
              </div>
              <div class="stat-icon text-warning bg-warning-soft">
                <i class="fas fa-cube"></i>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-xl-3 col-md-6 col-12 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100 kpi-stat-card border-left-dark">
          <div class="card-body p-3">
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="text-xs font-weight-bold text-muted text-uppercase mb-1">Total Bill Amount</div>
                <div class="h5 mb-0 font-weight-bold text-primary"><?=formatCurrency($grand_total_amount)?></div>
              </div>
              <div class="stat-icon text-dark bg-dark-soft">
                <i class="fas fa-money-bill-wave"></i>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Live on-screen search filter -->
    <?php if (!empty($sales)): ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 p-2 bg-white rounded border d-print-none shadow-sm">
      <div class="d-flex align-items-center" style="max-width: 380px; width: 100%;">
        <div class="input-group input-group-sm">
          <div class="input-group-prepend"><span class="input-group-text bg-white border-right-0"><i class="fas fa-search text-muted"></i></span></div>
          <input type="text" id="liveVoucherSearch" class="form-control border-left-0" placeholder="Type customer, product, invoice, salesman...">
          <div class="input-group-append">
            <button class="btn btn-outline-secondary" type="button" id="clearLiveSearch" title="Clear"><i class="fas fa-times"></i></button>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if (empty($sales)): ?>
      <div class="alert alert-light border text-center py-5 shadow-sm">
        <div class="text-muted mb-3"><i class="fas fa-truck-loading fa-3x"></i></div>
        <h5 class="text-dark font-weight-bold">No delivery orders found</h5>
        <p class="text-muted small mb-0">No active delivery orders matched your selected date range or filter criteria.</p>
      </div>
    <?php else: ?>

      <!-- VOUCHERS CONTAINER (EXACT SAMPLE LAYOUT FOR SCREEN + PRINT) -->
      <div id="vouchersList">
        <?php foreach ($sales as $sale): 
          $cust_code = $sale['customer_no'] ?: ('CUS-' . str_pad($sale['customer_id'], 4, '0', STR_PAD_LEFT));
          $cust_area_bracket = !empty($sale['customer_area']) ? ' (' . htmlspecialchars($sale['customer_area']) . ')' : '';
          $cust_line1 = htmlspecialchars($cust_code . ': ' . ($sale['customer_name'] ?: 'Walk-in Customer')) . $cust_area_bracket;
          $cust_line2 = htmlspecialchars($sale['customer_address'] ?: ($sale['customer_area'] ?: $sale['customer_city'] ?: ''));
          $cust_phone = htmlspecialchars($sale['customer_phone'] ?: '');
          $cust_cnic = htmlspecialchars($sale['customer_cnic'] ?: '');

          $deliv_raw = $sale['delivery_date'] ?: $sale['sale_date'];
          $info_delivery_date = date('d-m-Y', strtotime($deliv_raw));
          $info_order_date = date('d-m-Y', strtotime($sale['sale_date']));
          $info_voucher = htmlspecialchars($sale['invoice_no']);
          $info_salesman = htmlspecialchars($sale['salesman_name'] ?: 'Unassigned');
          $info_ob = htmlspecialchars($sale['order_booker_name'] ?: ($sale['order_booker_username'] ?: 'Office'));
          
          $item_count = count($sale['items']);
          $search_text = strtolower($cust_line1 . ' ' . $cust_line2 . ' ' . $info_voucher . ' ' . $info_salesman . ' ' . $info_ob . ' ' . $cust_phone . ' ' . ($sale['product_search_blob'] ?? ''));
        ?>

        <div class="voucher-wrapper mb-3" data-search="<?=htmlspecialchars($search_text)?>" id="voucher-sale-<?=$sale['sale_id']?>">
          
          <!-- On-Screen Action Bar for Shortcuts (Screen Only) -->
          <div class="voucher-screen-toolbar d-flex justify-content-between align-items-center px-3 py-1 bg-white border border-bottom-0 rounded-top d-print-none">
            <div class="d-flex align-items-center">
              <span class="badge badge-light border text-dark mr-2"><i class="fas fa-hashtag text-muted mr-1"></i><?=$info_voucher?></span>
              <span class="badge badge-primary-soft mr-2"><i class="fas fa-calendar-check mr-1"></i>Delivery: <?=$info_delivery_date?></span>
              <?php if (!empty($sale['customer_phone'])): ?>
              <a href="tel:<?=$cust_phone?>" class="small text-muted mr-2 text-decoration-none" title="Call Customer">
                <i class="fas fa-phone text-success mr-1"></i><?=$cust_phone?>
              </a>
              <?php endif; ?>
            </div>
            <div class="d-flex align-items-center">
              <a href="invoice.php?id=<?=$sale['sale_id']?>" target="_blank" class="btn btn-xs btn-outline-primary mr-1" title="View Full Invoice">
                <i class="fas fa-eye mr-1"></i> View Invoice
              </a>
              <a href="invoice.php?id=<?=$sale['sale_id']?>&print=1" target="_blank" class="btn btn-xs btn-outline-secondary" title="Print Single Invoice">
                <i class="fas fa-print mr-1"></i> Print Invoice
              </a>
            </div>
          </div>

          <div class="voucher-box">
            
            <!-- HEADER SECTION (Customer & Voucher Details) -->
            <table class="voucher-head-table">
              <tr>
                <!-- LEFT: CUSTOMER DETAILS -->
                <td class="vh-left" style="width: 58%;">
                  <div class="vh-content">
                    <div class="vh-line vh-cust-name font-weight-bold"><?=$cust_line1?></div>
                    <div class="vh-line vh-address"><?=$cust_line2 !== '' ? $cust_line2 : '&nbsp;'?></div>
                    <div class="vh-line">Cell No: <span class="font-weight-bold"><?=$cust_phone !== '' ? $cust_phone : 'N/A'?></span></div>
                    <div class="vh-line">CNIC: <span class="font-weight-bold"><?=$cust_cnic !== '' ? $cust_cnic : 'N/A'?></span></div>
                  </div>
                </td>

                <!-- RIGHT: VOUCHER DETAILS -->
                <td class="vh-right" style="width: 42%;">
                  <div class="vh-content">
                    <div class="vh-line">Voucher No: <span class="font-weight-bold text-primary-print"><?=$info_voucher?></span></div>
                    <div class="vh-line">Delivery Date: <span class="font-weight-bold"><?=$info_delivery_date?></span></div>
                    <div class="vh-line">Order Date: <span class="font-weight-bold"><?=$info_order_date?></span></div>
                    <div class="vh-line">Delivery Man: <span class="font-weight-bold"><?=$info_salesman?></span></div>
                    <div class="vh-line">Order Booker: <span class="font-weight-bold"><?=$info_ob?></span></div>
                  </div>
                </td>
              </tr>
            </table>

            <!-- ITEMS TABLE -->
            <table class="voucher-items-table">
              <thead>
                <tr>
                  <th class="col-item text-left">Item / Product Name</th>
                  <th class="col-carton text-center" style="width: 70px;">Carton</th>
                  <th class="col-box text-center" style="width: 70px;">Box</th>
                  <th class="col-price text-right" style="width: 100px;">Trade Price</th>
                  <th class="col-amount text-right" style="width: 110px;">Amount</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($sale['items'])): ?>
                <tr>
                  <td colspan="5" class="text-center text-muted py-2">No items in this voucher</td>
                </tr>
                <?php else: ?>
                  <?php foreach ($sale['items'] as $it): 
                    $prod_label = ($it['product_code'] ? htmlspecialchars($it['product_code']) . ': ' : '') . htmlspecialchars($it['product_name']);
                  ?>
                  <tr>
                    <td class="col-item font-weight-bold"><?=$prod_label?></td>
                    <td class="col-carton text-center"><?=$it['cartons']?></td>
                    <td class="col-box text-center"><?=$it['loose']?></td>
                    <td class="col-price text-right"><?=fmtNum($it['price'])?></td>
                    <td class="col-amount text-right"><?=fmtNum($it['subtotal'])?></td>
                  </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
              <tfoot>
                <tr class="voucher-total-row font-weight-bold">
                  <td class="col-item text-left"><?=$item_count?> &lt;--- T O T A L ---&gt;</td>
                  <td class="col-carton text-center"><?=$sale['total_cartons']?></td>
                  <td class="col-box text-center"><?=$sale['total_boxes']?></td>
                  <td class="col-price text-right"></td>
                  <td class="col-amount text-right"><?=fmtNum($sale['total_amount'])?></td>
                </tr>
              </tfoot>
            </table>

          </div>
        </div>

        <?php endforeach; ?>
      </div>

      <!-- Printable Footer -->
      <div class="delivery-print-footer d-none d-print-block mt-4 pt-2">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div style="width: 45%; border-top: 1px dashed #000; text-align: center; padding-top: 4px;">
            <strong>Salesman / Delivery Man Signature</strong>
          </div>
          <div style="width: 45%; border-top: 1px dashed #000; text-align: center; padding-top: 4px;">
            <strong>Customer / Receiver Signature</strong>
          </div>
        </div>
        <div class="d-flex justify-content-between align-items-center border-top pt-1">
          <div class="footer-powered font-weight-bold">POWERED BY: MEHBOOB TRADERS &middot; Wholesale Management System</div>
          <div class="footer-meta text-muted">Printed on <?=date('d-m-Y H:i')?> &middot; <?=$period_desc?></div>
        </div>
      </div>

    <?php endif; ?>

  </div>
</div>

<style>
/* Base Screen Styling */
.icon-circle {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.bg-primary-soft { background-color: rgba(13, 110, 253, 0.10); color: #0d6efd; }
.bg-info-soft    { background-color: rgba(13, 202, 240, 0.12); color: #0dcaf0; }
.bg-success-soft { background-color: rgba(255, 193, 7, 0.15); color: #198754; }
.bg-warning-soft { background-color: rgba(255, 193, 7, 0.15); color: #b45309; }
.bg-dark-soft    { background-color: rgba(33, 37, 41, 0.10); color: #212529; }

.border-left-primary { border-left: 4px solid #0d6efd !important; }
.border-left-info    { border-left: 4px solid #0dcaf0 !important; }
.border-left-success { border-left: 4px solid #198754 !important; }
.border-left-warning { border-left: 4px solid #f59e0b !important; }
.border-left-dark    { border-left: 4px solid #343a40 !important; }

.kpi-stat-card {
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.kpi-stat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 .5rem 1rem rgba(0,0,0,.08) !important;
}

.stat-icon {
  width: 38px;
  height: 38px;
  border-radius: 8px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
}

.btn-xs {
  padding: 0.2rem 0.5rem;
  font-size: 0.75rem;
  line-height: 1.2;
}

.voucher-screen-toolbar {
  font-size: 12px;
  background-color: #f8fafc !important;
  border-color: #cbd5e1 !important;
}

.voucher-box {
  background: #ffffff;
  border: 1.5px solid #334155;
  border-radius: 0 0 2px 2px;
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.voucher-head-table {
  width: 100%;
  border-collapse: collapse;
  border-bottom: 1.5px solid #334155;
}

.voucher-head-table td {
  vertical-align: top;
  padding: 0;
  border: none;
}

.voucher-head-table td.vh-left {
  border-right: 1.5px solid #334155;
}

.vh-title-bar {
  background-color: #e2e8f0;
  color: #0f172a;
  font-weight: 800;
  font-size: 11px;
  letter-spacing: 0.5px;
  text-align: center;
  padding: 3px 6px;
  border-bottom: 1px solid #cbd5e1;
  text-transform: uppercase;
}

.vh-content {
  padding: 8px 12px;
  font-size: 12.5px;
  line-height: 1.45;
  color: #0f172a;
}

.vh-line {
  margin-bottom: 2px;
}

.vh-cust-name {
  font-size: 13px;
  color: #000000;
}

.voucher-items-table {
  width: 100%;
  border-collapse: collapse;
}

.voucher-items-table th {
  background-color: #f1f5f9;
  color: #0f172a;
  font-size: 11.5px;
  font-weight: 700;
  padding: 4px 8px;
  border: 1px solid #94a3b8;
  vertical-align: middle;
}

.voucher-items-table td {
  font-size: 12px;
  padding: 4px 8px;
  border: 1px solid #cbd5e1;
  color: #0f172a;
  vertical-align: middle;
}

.voucher-items-table tfoot td {
  background-color: #f8fafc;
  border-top: 1.5px solid #475569;
  border-bottom: 1px solid #475569;
  font-size: 12px;
  padding: 4px 8px;
  color: #000000;
}

.voucher-total-row td {
  font-size: 12.5px !important;
}

/* Print Specific Rules matching exact sample paper */
@media print {
  @page {
    size: A4 portrait;
    margin: 8mm 6mm;
  }

  body {
    background: #ffffff !important;
    color: #000000 !important;
    font-size: 11px !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }

  .card {
    border: none !important;
    box-shadow: none !important;
    padding: 0 !important;
    margin: 0 !important;
  }

  .card-body {
    padding: 0 !important;
  }

  .d-print-none {
    display: none !important;
  }

  .voucher-wrapper {
    page-break-inside: avoid !important;
    break-inside: avoid !important;
    margin-bottom: 14px !important;
  }

  .voucher-box {
    border: 1.5px solid #000000 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    background: #ffffff !important;
  }

  .voucher-head-table {
    border-bottom: 1.5px solid #000000 !important;
  }

  .voucher-head-table td.vh-left {
    border-right: 1.5px solid #000000 !important;
  }

  .vh-title-bar {
    background-color: #e5e7eb !important;
    color: #000000 !important;
    border-bottom: 1px solid #000000 !important;
    font-weight: 800 !important;
    font-size: 11px !important;
    padding: 3px 6px !important;
  }

  .vh-content {
    font-size: 11.5px !important;
    line-height: 1.4 !important;
    color: #000000 !important;
    padding: 6px 10px !important;
  }

  .vh-cust-name {
    font-size: 12.5px !important;
    font-weight: 800 !important;
    color: #000000 !important;
  }

  .voucher-items-table th {
    background-color: #f3f4f6 !important;
    color: #000000 !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    border: 1px solid #000000 !important;
    padding: 3px 6px !important;
  }

  .voucher-items-table td {
    font-size: 11.5px !important;
    color: #000000 !important;
    border: 1px solid #4b5563 !important;
    padding: 3px 6px !important;
  }

  .voucher-items-table tfoot td {
    background-color: #f3f4f6 !important;
    border: 1px solid #000000 !important;
    border-top: 1.5px solid #000000 !important;
    font-size: 12px !important;
    font-weight: 800 !important;
    padding: 3px 6px !important;
  }

  .text-primary-print {
    color: #000000 !important;
  }

  .delivery-print-footer {
    border-top: 1px solid #9ca3af !important;
    padding-top: 6px !important;
    font-size: 10px !important;
    color: #000000 !important;
  }
}
</style>

<script>
$(document).ready(function(){
  // Auto-submit dropdown filters when changed
  $('.auto-submit-select').on('change', function(){
    $('#packlistFilterForm').submit();
  });

  // Live on-screen search filter
  function filterVouchers() {
    var q = $.trim($('#liveVoucherSearch').val()).toLowerCase();

    if (!q) {
      $('.voucher-wrapper').show();
      return;
    }

    $('.voucher-wrapper').each(function(){
      var data = $(this).attr('data-search') || '';
      if (data.indexOf(q) !== -1) {
        $(this).show();
      } else {
        $(this).hide();
      }
    });
  }

  $('#liveVoucherSearch').on('keyup input', filterVouchers);

  $('#clearLiveSearch').on('click', function(){
    $('#liveVoucherSearch').val('');
    filterVouchers();
    $('#liveVoucherSearch').focus();
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>