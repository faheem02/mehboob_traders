<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Delivery Loading Sheet';
$compact_page_heading = true;
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin', 'order_booker']);

$my_areas = currentUserAreas($pdo); // null = admin (all areas)
$all_known = allKnownAreas($pdo);
$allowed_areas = isAdmin() ? $all_known : (array)$my_areas;

// Filters - the sheet is always ONE day (the delivery/report day), never a range
$today = date('Y-m-d');
$date = isset($_GET['date']) ? trim($_GET['date']) : $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date,5,2), (int)substr($date,8,2), (int)substr($date,0,4))) {
    $date = $today;
}
$area = trim($_GET['area'] ?? '');
$salesman_id = (int)($_GET['salesman_id'] ?? 0);
$ob = normalizeIdList($_GET['order_booker_id'] ?? []);
$product_id = (int)($_GET['product_id'] ?? 0);

if ($area !== '' && !in_array($area, $allowed_areas, true)) {
    $area = '';
}

// Dropdown options
$all_products = $pdo->query("SELECT id, name FROM products WHERE status = 1 ORDER BY name ASC")->fetchAll();
$all_salesmen = $pdo->query("SELECT id, full_name FROM employees WHERE employee_type = 'salesman' AND status = 1 ORDER BY full_name ASC")->fetchAll();

// Build aggregation query
$sql = "SELECT 
            COALESCE(c.id, 0) AS category_id,
            COALESCE(c.name, 'General') AS category_name,
            p.id AS product_id,
            p.name AS product_name,
            p.code AS product_code,
            p.unit,
            p.boxes_per_carton,
            b.name AS brand_name,
            si.price AS sale_rate,
            SUM(si.quantity) AS total_quantity
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        JOIN products p ON si.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN customers cust ON s.customer_id = cust.id
        WHERE s.status <> 'cancelled'";

$params = [];
// Filters that only reference sales/customers - shared with the header date lookup below
$where = '';

if ($date !== '') {
    $where .= " AND COALESCE(s.delivery_date, s.sale_date) = ?";
    $params[] = $date;
}
if ($area !== '') {
    $where .= " AND LOWER(cust.area) = LOWER(?)";
    $params[] = $area;
} elseif ($my_areas !== null) {
    if (empty($my_areas)) {
        $where .= " AND 1=0";
    } else {
        $in_placeholders = implode(',', array_fill(0, count($my_areas), '?'));
        $where .= " AND cust.area IN ($in_placeholders)";
        $params = array_merge($params, $my_areas);
    }
}
if ($salesman_id > 0) {
    $where .= " AND s.salesman_id = ?";
    $params[] = $salesman_id;
}
if (!isAdmin()) {
    $where .= " AND s.created_by = ?";
    $params[] = (int)$_SESSION['user_id'];
} elseif ($ob) {
    $where .= " AND s.created_by IN (" . implode(',', array_fill(0, count($ob), '?')) . ")";
    $params = array_merge($params, $ob);
}
$sql .= $where;
$sales_params = $params;
if ($product_id > 0) {
    $sql .= " AND p.id = ?";
    $params[] = $product_id;
}

$sql .= " GROUP BY c.id, c.name, p.id, p.name, p.code, p.unit, p.boxes_per_carton, b.name, si.price
          ORDER BY COALESCE(c.name, 'ZZZ') ASC, p.name ASC, si.price ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$raw_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Which order / delivery dates does this sheet cover? Same sales-level filters as above,
// so the header tells the truth for a single-day sheet as well as for a date range.
$date_stmt = $pdo->prepare("
    SELECT MIN(s.sale_date) AS od_min, MAX(s.sale_date) AS od_max,
           MIN(COALESCE(s.delivery_date, s.sale_date)) AS dd_min,
           MAX(COALESCE(s.delivery_date, s.sale_date)) AS dd_max
    FROM sales s
    LEFT JOIN customers cust ON s.customer_id = cust.id
    WHERE s.status <> 'cancelled'" . $where);
$date_stmt->execute($sales_params);
$date_row = $date_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$order_date_str = '';
$delivery_date_str = '';
if (!empty($date_row['od_min'])) {
    $order_date_str = ($date_row['od_min'] === $date_row['od_max'])
        ? date('d-m-Y', strtotime($date_row['od_min']))
        : date('d-m-Y', strtotime($date_row['od_min'])) . ' to ' . date('d-m-Y', strtotime($date_row['od_max']));
}
if (!empty($date_row['dd_min'])) {
    $delivery_date_str = ($date_row['dd_min'] === $date_row['dd_max'])
        ? date('d-m-Y', strtotime($date_row['dd_min']))
        : date('d-m-Y', strtotime($date_row['dd_min'])) . ' to ' . date('d-m-Y', strtotime($date_row['dd_max']));
}

// Totals computation
$items_list = [];
$total_products_count = 0;
$grand_total_cartons = 0;
$grand_total_loose = 0;
$grand_total_boxes = 0;
$grand_total_amount = 0.00;

foreach ($raw_items as $item) {
    $bpc = max((int)($item['boxes_per_carton'] ?? 1), 1);
    $qty = (int)$item['total_quantity'];
    $rate = (float)$item['sale_rate'];
    $amount = $qty * $rate;

    if ($bpc > 1) {
        $cartons = intdiv($qty, $bpc);
        $loose = $qty % $bpc;
        if ($cartons > 0 && $loose > 0) {
            $info_text = "1x{$bpc} ({$cartons}C+{$loose}B)";
        } elseif ($cartons > 0) {
            $info_text = "1x{$bpc} ({$cartons}C)";
        } else {
            $info_text = "1x{$bpc} ({$loose}B)";
        }
    } else {
        $cartons = 0;
        $loose = $qty;
        $info_text = "1x1";
    }

    $item['cartons'] = $cartons;
    $item['loose'] = $loose;
    $item['bpc'] = $bpc;
    $item['info_text'] = $info_text;
    $item['sale_rate'] = $rate;
    $item['subtotal'] = $amount;

    $items_list[] = $item;
    $total_products_count++;
    $grand_total_cartons += $cartons;
    $grand_total_loose += $loose;
    $grand_total_boxes += $qty;
    $grand_total_amount += $amount;
}

// Salesman label
$salesman_label = 'All Salesmen';
if ($salesman_id > 0) {
    foreach ($all_salesmen as $sm) {
        if ((int)$sm['id'] === $salesman_id) {
            $salesman_label = $sm['full_name'];
            break;
        }
    }
} else {
    $sm_check_sql = "SELECT DISTINCT e.full_name 
                     FROM sales s 
                     JOIN employees e ON s.salesman_id = e.id 
                     LEFT JOIN customers cust ON s.customer_id = cust.id
                     WHERE s.status <> 'cancelled'";
    $sm_params = [];
    if ($date !== '') { $sm_check_sql .= " AND COALESCE(s.delivery_date, s.sale_date) = ?"; $sm_params[] = $date; }
    if ($area !== '') { $sm_check_sql .= " AND LOWER(cust.area) = LOWER(?)"; $sm_params[] = $area; }
    if (!isAdmin()) { $sm_check_sql .= " AND s.created_by = ?"; $sm_params[] = (int)$_SESSION['user_id']; }
    $sm_stmt = $pdo->prepare($sm_check_sql);
    $sm_stmt->execute($sm_params);
    $found_sm = $sm_stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($found_sm) === 1 && !empty($found_sm[0])) {
        $salesman_label = $found_sm[0];
    }
}

// Order Booker label
$ob_label = 'All Bookers';
$ob_name = '';
if ($ob && isAdmin()) {
    $ph = implode(',', array_fill(0, count($ob), '?'));
    $on = $pdo->prepare("SELECT full_name FROM users WHERE id IN ($ph) ORDER BY full_name");
    $on->execute($ob);
    $ob_name = implode(', ', array_filter($on->fetchAll(PDO::FETCH_COLUMN)));
    if ($ob_name) {
        $ob_label = count($ob) > 1 ? count($ob) . ' Bookers: ' . $ob_name : $ob_name;
    }
} elseif (!isAdmin()) {
    $ob_label = $_SESSION['full_name'] ?? 'Order Booker';
} else {
    $ob_check_sql = "SELECT DISTINCT u.full_name 
                     FROM sales s 
                     JOIN users u ON s.created_by = u.id 
                     LEFT JOIN customers cust ON s.customer_id = cust.id
                     WHERE s.status <> 'cancelled'";
    $ob_params = [];
    if ($date !== '') { $ob_check_sql .= " AND COALESCE(s.delivery_date, s.sale_date) = ?"; $ob_params[] = $date; }
    if ($area !== '') { $ob_check_sql .= " AND LOWER(cust.area) = LOWER(?)"; $ob_params[] = $area; }
    if ($salesman_id > 0) { $ob_check_sql .= " AND s.salesman_id = ?"; $ob_params[] = $salesman_id; }
    $ob_stmt = $pdo->prepare($ob_check_sql);
    $ob_stmt->execute($ob_params);
    $found_ob = $ob_stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($found_ob) === 1 && !empty($found_ob[0])) {
        $ob_label = $found_ob[0];
    }
}

// Sale Area label
$area_label = $area !== '' ? $area : 'All Areas';
if ($area === '') {
    $area_check_sql = "SELECT DISTINCT cust.area 
                       FROM sales s 
                       JOIN customers cust ON s.customer_id = cust.id 
                       WHERE s.status <> 'cancelled' AND cust.area IS NOT NULL AND cust.area <> ''";
    $area_params = [];
    if ($date !== '') { $area_check_sql .= " AND COALESCE(s.delivery_date, s.sale_date) = ?"; $area_params[] = $date; }
    if ($salesman_id > 0) { $area_check_sql .= " AND s.salesman_id = ?"; $area_params[] = $salesman_id; }
    if (!isAdmin()) { $area_check_sql .= " AND s.created_by = ?"; $area_params[] = (int)$_SESSION['user_id']; }
    $area_stmt = $pdo->prepare($area_check_sql);
    $area_stmt->execute($area_params);
    $found_areas = $area_stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($found_areas) === 1 && !empty($found_areas[0])) {
        $area_label = $found_areas[0];
    }
}

// Voucher Number
$voucher_date = date('ymd', strtotime($date));
$voucher_suffix = $salesman_id > 0 ? ('-' . str_pad($salesman_id, 2, '0', STR_PAD_LEFT)) : '';
$voucher_no = 'DSR/' . $voucher_date . $voucher_suffix;

// Dated display - always a single day
$dated_str = date('d-m-Y', strtotime($date));

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow-sm border-0 mb-4">
  <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center d-print-none border-bottom">
    <div class="d-flex align-items-center">
      <div class="icon-circle bg-primary-soft text-primary mr-3">
        <i class="fas fa-truck-loading fa-lg"></i>
      </div>
      <div>
        <h5 class="mb-0 font-weight-bold text-dark">
          Delivery Loading Sheet
        </h5>
        <small class="text-muted">Consolidated loading list for delivery van, driver & warehouse loaders</small>
      </div>
    </div>
    <div class="d-flex flex-wrap mt-2 mt-md-0">
      <?php if ($total_products_count > 0): ?>
      <button type="button" class="btn btn-sm btn-primary shadow-sm mr-2" onclick="window.print()">
        <i class="fas fa-print mr-1"></i> Print
      </button>
      <?php endif; ?>
      <a href="total_sale_invoices.php" class="btn btn-sm btn-outline-info mr-2">
        <i class="fas fa-file-invoice-dollar mr-1"></i> Total Sale Invoices
      </a>
      <a href="invoices.php" class="btn btn-sm btn-outline-secondary mr-2">
        <i class="fas fa-file-invoice mr-1"></i> Invoices
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
        <div class="col-lg-3 col-md-4 col-sm-6 mb-2">
          <label class="form-label font-weight-bold text-xs text-uppercase text-muted mb-1">Report Date</label>
          <input type="date" name="date" class="form-control form-control-sm" value="<?=htmlspecialchars($date)?>">
        </div>
        <div class="col-lg-2 col-md-3 col-sm-6 mb-2">
          <label class="form-label font-weight-bold text-xs text-uppercase text-muted mb-1">Area</label>
          <select name="area" class="form-control form-control-sm">
            <option value="">-- All Areas --</option>
            <?php foreach ($allowed_areas as $an): ?>
            <option value="<?=htmlspecialchars($an)?>" <?= $area === $an ? 'selected' : '' ?>><?=htmlspecialchars($an)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-2 col-md-3 col-sm-6 mb-2">
          <label class="form-label font-weight-bold text-xs text-uppercase text-muted mb-1">Delivery Man</label>
          <select name="salesman_id" class="form-control form-control-sm">
            <option value="">-- All Salesmen --</option>
            <?php foreach ($all_salesmen as $se): ?>
            <option value="<?=$se['id']?>" <?= $salesman_id === (int)$se['id'] ? 'selected' : '' ?>><?=htmlspecialchars($se['full_name'])?></option>
            <?php endforeach; ?>
          </select>
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
        <div class="col-lg-2 col-md-3 col-sm-6 mb-2">
          <label class="form-label font-weight-bold text-xs text-uppercase text-muted mb-1">Product</label>
          <select name="product_id" id="productSelect" class="form-control form-control-sm">
            <option value="">-- All Products --</option>
            <?php foreach ($all_products as $p): ?>
            <option value="<?=$p['id']?>" <?= $product_id === (int)$p['id'] ? 'selected' : '' ?>><?=htmlspecialchars($p['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="d-flex justify-content-between align-items-center pt-2 border-top mt-2">
        <small class="text-muted"><i class="fas fa-info-circle mr-1"></i> Showing consolidated stock quantities across active orders.</small>
        <div>
          <a href="packlist.php" class="btn btn-sm btn-outline-secondary mr-2"><i class="fas fa-undo mr-1"></i> Reset</a>
          <button type="submit" class="btn btn-sm btn-primary px-3 shadow-sm"><i class="fas fa-filter mr-1"></i> Load Stock Sheet</button>
        </div>
      </div>
    </form>

    <?php if ($total_products_count === 0): ?>
      <div class="alert alert-light border text-center py-5 shadow-sm rounded">
        <i class="fas fa-boxes text-muted fa-3x mb-3 d-block"></i>
        <h5 class="text-muted font-weight-bold">No stock items to load</h5>
        <p class="text-muted small mb-0">No active delivery orders were found for the selected date, area, or salesman filters.</p>
      </div>
    <?php else: ?>

      <!-- Order / delivery dates for this sheet (Screen Only).
           The chosen day is already visible in the Report Date filter above, so it is not repeated here. -->
      <div class="d-print-none mb-3 px-3 py-2 rounded border bg-white shadow-sm d-flex flex-wrap align-items-center">
        <span class="small mr-4">
          <span class="text-muted font-weight-bold">Order Date:</span>
          <span class="font-weight-bold text-dark"><?=$order_date_str !== '' ? htmlspecialchars($order_date_str) : '—'?></span>
        </span>
        <span class="small">
          <span class="text-muted font-weight-bold">Delivery Date:</span>
          <span class="font-weight-bold text-dark"><?=$delivery_date_str !== '' ? htmlspecialchars($delivery_date_str) : '—'?></span>
        </span>
      </div>

      <!-- Modern On-Screen KPI Stat Cards Strip (Screen Only) -->
      <div class="row g-3 mb-4 d-print-none">
        <div class="col-xl-3 col-md-6 mb-3">
          <div class="card border-0 shadow-sm text-white h-100 rounded-lg overflow-hidden" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%);">
            <div class="card-body p-3 d-flex align-items-center justify-content-between">
              <div>
                <div class="text-xs text-uppercase font-weight-bold text-white-50 mb-1">Total Booking Value</div>
                <div class="h4 mb-0 font-weight-bold">PKR <?=formatCurrency($grand_total_amount)?></div>
                <small class="text-white-50">Delivery load amount</small>
              </div>
              <div class="rounded-circle p-3" style="background: rgba(255,255,255,0.2);">
                <i class="fas fa-money-bill-wave fa-2x"></i>
              </div>
            </div>
          </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
          <div class="card border-0 shadow-sm text-white h-100 rounded-lg overflow-hidden" style="background: linear-gradient(135deg, #1d4ed8 0%, #3b82f6 100%);">
            <div class="card-body p-3 d-flex align-items-center justify-content-between">
              <div>
                <div class="text-xs text-uppercase font-weight-bold text-white-50 mb-1">Total Quantity to Load</div>
                <div class="h4 mb-0 font-weight-bold"><?=$grand_total_boxes?> Boxes</div>
                <small class="text-white-50"><?=$grand_total_cartons?> Cartons + <?=$grand_total_loose?> Loose</small>
              </div>
              <div class="rounded-circle p-3" style="background: rgba(255,255,255,0.2);">
                <i class="fas fa-boxes fa-2x"></i>
              </div>
            </div>
          </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
          <div class="card border-0 shadow-sm text-white h-100 rounded-lg overflow-hidden" style="background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);">
            <div class="card-body p-3 d-flex align-items-center justify-content-between">
              <div>
                <div class="text-xs text-uppercase font-weight-bold text-white-50 mb-1">Distinct Products</div>
                <div class="h4 mb-0 font-weight-bold"><?=$total_products_count?> Items</div>
                <small class="text-white-50">Rate-wise delivery items</small>
              </div>
              <div class="rounded-circle p-3" style="background: rgba(255,255,255,0.2);">
                <i class="fas fa-tags fa-2x"></i>
              </div>
            </div>
          </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
          <div class="card border-0 shadow-sm text-white h-100 rounded-lg overflow-hidden" style="background: linear-gradient(135deg, #1e293b 0%, #334155 100%);">
            <div class="card-body p-3 d-flex align-items-center justify-content-between">
              <div>
                <div class="text-xs text-uppercase font-weight-bold text-white-50 mb-1">Delivery Route & Man</div>
                <div class="h5 mb-0 font-weight-bold text-truncate" style="max-width: 170px;" title="<?=htmlspecialchars($salesman_label)?>"><?=htmlspecialchars($salesman_label)?></div>
                <small class="text-white-50 text-truncate d-block" style="max-width: 170px;"><?=htmlspecialchars($area_label)?> &middot; <?=htmlspecialchars($dated_str)?></small>
              </div>
              <div class="rounded-circle p-3" style="background: rgba(255,255,255,0.2);">
                <i class="fas fa-truck fa-2x"></i>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick on-screen search toolbar (Screen Only) -->
      <div class="mb-3 d-flex flex-wrap justify-content-between align-items-center d-print-none bg-white p-2 border rounded-lg shadow-sm">
        <div style="max-width: 380px;" class="w-100 mb-2 mb-md-0">
          <div class="input-group input-group-sm">
            <div class="input-group-prepend"><span class="input-group-text bg-white border-right-0"><i class="fas fa-search text-muted"></i></span></div>
            <input type="text" id="loaderItemSearch" class="form-control border-left-0" placeholder="Search product name, code, brand, rate...">
          </div>
        </div>
        <div>
          <span class="badge badge-light border px-3 py-2 text-dark" style="font-size: 12.5px;">
            Showing <strong><?=$total_products_count?></strong> items &middot; <strong><?=$grand_total_boxes?></strong> Boxes (<?=$grand_total_cartons?> Cartons + <?=$grand_total_loose?> Loose)
          </span>
        </div>
      </div>

      <!-- PRINT-ONLY DSR LOAD FORM HEADER (Hidden on Screen, 1:1 on Paper) -->
      <div class="d-none d-print-block">
        <!-- Top Grey Header Box -->
        <div class="dsr-header-box">
          <div class="dsr-brand-title">MEHBOOB TRADERS</div>
          <div class="dsr-brand-subtitle">DSR LOAD FORM</div>
        </div>

        <!-- 3-Column Metadata Header Grid -->
        <div class="dsr-meta-grid">
          <!-- Left Column -->
          <div class="dsr-meta-col dsr-meta-left">
            <div class="dsr-meta-row">
              <span class="meta-lbl">Salesman:</span>
              <span class="meta-val"><?=htmlspecialchars($salesman_label)?></span>
            </div>
            <div class="dsr-meta-row">
              <span class="meta-lbl">Sales Officer:</span>
              <span class="meta-val"><?=htmlspecialchars($ob_label)?></span>
            </div>
            <div class="dsr-meta-row">
              <span class="meta-lbl">Sale Area:</span>
              <span class="meta-val"><?=htmlspecialchars($area_label)?></span>
            </div>
          </div>

          <!-- Middle Column -->
          <div class="dsr-meta-col dsr-meta-mid text-center">
            <div class="dsr-booking-box">
              <span class="dsr-booking-lbl">Booking:</span>
              <span class="dsr-booking-val"><?=formatCurrency($grand_total_amount)?></span>
            </div>
            <div class="dsr-meta-row mt-1">
              <span class="meta-lbl">Order Date:</span>
              <span class="meta-val"><?=$order_date_str !== '' ? htmlspecialchars($order_date_str) : '&nbsp;'?></span>
            </div>
            <div class="dsr-meta-row">
              <span class="meta-lbl">Delivery Date:</span>
              <span class="meta-val"><?=$delivery_date_str !== '' ? htmlspecialchars($delivery_date_str) : '&nbsp;'?></span>
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
              <span class="meta-lbl">Sale Type:</span>
              <span class="meta-val">Retail Sale</span>
            </div>
          </div>
        </div>
      </div>
      <!-- /Print-Only Header -->

      <!-- FULL WIDTH DATA TABLE (Expands 100% on Screen, Ultra-Compact 1:1 in Print) -->
      <div class="table-responsive w-100" style="overflow: visible;">
        <table class="table table-bordered table-hover dsr-load-table w-100 mb-0" id="dsrTable">
          <thead>
            <tr>
              <th class="text-left">Item/Product Name</th>
              <th style="width: 16%;" class="text-center">Info</th>
              <th style="width: 10%;" class="text-center">Issued</th>
              <th style="width: 12%;" class="text-right">Rate</th>
              <th style="width: 18%;" class="text-right">Amount</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items_list as $item): ?>
            <tr class="dsr-item-row">
              <td class="dsr-col-name text-left">
                <?php if (!empty($item['product_code'])): ?>
                  <span class="font-weight-bold text-dark code-badge"><?=htmlspecialchars($item['product_code'])?>:</span>
                <?php endif; ?>
                <span class="product-title"><?=htmlspecialchars($item['product_name'])?></span>
              </td>
              <td class="dsr-col-info text-center">
                <span class="info-pill"><?=htmlspecialchars($item['info_text'])?></span>
              </td>
              <td class="dsr-col-issued text-center font-weight-bold">
                <span class="issued-badge"><?=$item['total_quantity']?></span>
              </td>
              <td class="dsr-col-rate text-right">
                <span class="rate-val"><?=formatCurrency($item['sale_rate'])?></span>
              </td>
              <td class="dsr-col-amount text-right font-weight-bold">
                <span class="amount-val"><?=formatCurrency($item['subtotal'])?></span>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="dsr-total-row">
              <th class="text-left font-weight-bold">TOTAL (<?=$total_products_count?> items)</th>
              <th class="text-center font-weight-bold text-secondary"><?=$grand_total_cartons?>C + <?=$grand_total_loose?>B</th>
              <th class="text-center font-weight-bold text-dark" style="font-size: 1.15rem;"><?=$grand_total_boxes?></th>
              <th class="text-right"></th>
              <th class="text-right font-weight-bold text-success" style="font-size: 1.15rem;"><?=formatCurrency($grand_total_amount)?></th>
            </tr>
          </tfoot>
        </table>
        <div id="noItemSearchAlert" class="alert alert-warning text-center my-3 py-2 d-none">
          No matching items found for your search.
        </div>
      </div>

      <!-- Printable Signatures Footer (Print Only) -->
      <div class="dsr-signatures-grid mt-4 d-none d-print-block">
        <div class="row text-center">
          <div class="col-4">
            <div class="sig-line">Salesman / Driver Signature</div>
          </div>
          <div class="col-4">
            <div class="sig-line">Warehouse Loader Signature</div>
          </div>
          <div class="col-4">
            <div class="sig-line">Verified / Office Signature</div>
          </div>
        </div>
      </div>

      <!-- Bottom Page Timestamp & Pagination Stamp -->
      <div class="dsr-sheet-footer d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
        <div class="small text-muted font-weight-bold d-print-none">Page 1 of 1</div>
      </div>

    <?php endif; ?>
  </div>
</div>

<style>
/* ============================================================
   SCREEN STYLES (Full Width, Modern ERP Experience)
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
  font-size: 12px !important;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  vertical-align: middle !important;
}

.dsr-load-table td {
  border: 1px solid #e2e8f0 !important;
  padding: 8px 8px !important;
  color: #0f172a !important;
  vertical-align: middle !important;
  font-size: 13px !important;
}

.dsr-load-table tr:hover td {
  background-color: #f8fafc;
}

.code-badge {
  color: #0f172a !important;
  font-size: 12.5px;
}

.product-title {
  font-size: 1.05rem;
  font-weight: 700;
  color: #0f172a;
}

.info-pill {
  background: #f1f5f9;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  padding: 2px 6px;
  font-size: 11.5px;
  font-weight: 600;
  color: #334155;
  display: inline-block;
}

.issued-badge {
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  color: #1d4ed8;
  border-radius: 4px;
  padding: 2px 8px;
  font-size: 13px;
  font-weight: 700;
  display: inline-block;
}

.rate-val {
  font-weight: 600;
  color: #334155;
}

.amount-val {
  font-weight: 700;
  color: #059669;
  font-size: 13.5px;
}


.dsr-total-row th {
  background: #f1f5f9 !important;
  border-top: 2px solid #334155 !important;
  font-size: 13px !important;
  padding: 10px 8px !important;
}

/* ============================================================
   PRINT STYLES (Ultra-Compact 1:1 Match with Physical Sheet)
   ============================================================ */
@media print {
  @page {
    size: A4 portrait;
    margin: 8mm 6mm 14mm 6mm;
    @bottom-left {
      content: "Mehboob Traders \B7  Delivery Loading Sheet";
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

  body {
    background: #ffffff !important;
    color: #000000 !important;
    font-size: 12px !important;
    line-height: 1.2 !important;
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
    font-size: 17px !important;
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
    font-size: 10.5px !important;
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
    font-size: 14.5px !important;
    font-weight: 900 !important;
    color: #000000 !important;
    margin-left: 4px !important;
  }

  /* Compact 5-Column Table in Print */
  .dsr-load-table {
    border: 1.5px solid #000000 !important;
    margin-bottom: 6px !important;
    table-layout: fixed !important;
    width: 100% !important;
  }
  .dsr-load-table thead {
    display: table-header-group !important;
  }
  /* Fixed column widths — product name tighter so Info sits closer to text */
  .dsr-load-table th:nth-child(1) { width: 36% !important; }
  .dsr-load-table th:nth-child(2) { width: 18% !important; }
  .dsr-load-table th:nth-child(3) { width: 10% !important; }
  .dsr-load-table th:nth-child(4) { width: 14% !important; }
  .dsr-load-table th:nth-child(5) { width: 22% !important; }
  .dsr-load-table th {
    background-color: #f1f5f9 !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    border: 1px solid #000000 !important;
    padding: 5px 7px !important;
    font-size: 12.5px !important;
    font-weight: 800 !important;
    color: #000000 !important;
    text-transform: uppercase !important;
  }
  .dsr-load-table td {
    border: 1px solid #000000 !important;
    padding: 5px 7px !important;
    font-size: 13px !important;
    color: #000000 !important;
    height: 26px !important;
    background: transparent !important;
  }
  .dsr-item-row {
    page-break-inside: avoid !important;
  }

  .code-badge {
    color: #000000 !important;
    font-size: 12px !important;
  }
  .product-title {
    color: #000000 !important;
    font-size: 14.5px !important;
    font-weight: 700 !important;
  }
  .info-pill {
    background: transparent !important;
    border: none !important;
    padding: 0 !important;
    font-size: 12px !important;
    color: #000000 !important;
  }
  .issued-badge {
    background: transparent !important;
    border: none !important;
    padding: 0 !important;
    font-size: 14px !important;
    font-weight: 800 !important;
    color: #000000 !important;
  }
  .rate-val, .amount-val {
    color: #000000 !important;
    font-size: 13px !important;
    font-weight: normal !important;
  }

  .dsr-total-row th {
    border-top: 1.5px solid #000000 !important;
    border-bottom: 1.5px solid #000000 !important;
    background-color: #f1f5f9 !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    font-size: 13.5px !important;
    padding: 5px 7px !important;
    color: #000000 !important;
  }

  /* Signatures */
  .dsr-signatures-grid {
    margin-top: 10px !important;
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

  /* Footer */
  .dsr-sheet-footer {
    border-top: 1px solid #000000 !important;
    margin-top: 6px !important;
    padding-top: 3px !important;
    font-size: 9.5px !important;
    color: #000000 !important;
    page-break-inside: avoid !important;
  }

  /* ============================================================
     TWO-COLUMN PRINT LAYOUT
     ============================================================ */
  .dsr-print-twocol {
    display: flex !important;
    align-items: flex-start !important;
    gap: 0 !important;
    width: 100% !important;
  }
  .dsr-print-col {
    flex: 1 !important;
    min-width: 0 !important;
    overflow: hidden !important;
  }
  .dsr-print-divider {
    width: 2px !important;
    background: #000000 !important;
    align-self: stretch !important;
    flex-shrink: 0 !important;
    margin: 0 3mm !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }
  .dsr-print-table {
    width: 100% !important;
    table-layout: auto !important;
    border-collapse: collapse !important;
    border: 1px solid #000000 !important;
    margin: 0 !important;
  }
  .dsr-print-table th {
    background-color: #f1f5f9 !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    border: 1px solid #000000 !important;
    padding: 4px 5px !important;
    font-size: 10px !important;
    font-weight: 800 !important;
    color: #000000 !important;
    text-transform: uppercase !important;
    white-space: nowrap !important;
  }
  .dsr-print-table td {
    border: 1px solid #000000 !important;
    padding: 4px 5px !important;
    font-size: 11px !important;
    color: #000000 !important;
    background: transparent !important;
    vertical-align: middle !important;
  }
  /* Product name: allow wrap; all other cols: nowrap */
  .dsr-print-table td:first-child {
    white-space: normal !important;
  }
  .dsr-print-table td:not(:first-child),
  .dsr-print-table th:not(:first-child) {
    white-space: nowrap !important;
  }
  .dsr-print-table .dsr-total-row th {
    border-top: 1.5px solid #000000 !important;
    border-bottom: 1.5px solid #000000 !important;
    background-color: #e2e8f0 !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    font-size: 11px !important;
    font-weight: 900 !important;
    padding: 4px 5px !important;
    color: #000000 !important;
  }
  .dsr-print-table .product-title {
    font-size: 11px !important;
    font-weight: 700 !important;
    color: #000000 !important;
  }
  .dsr-print-table .code-badge {
    font-size: 10px !important;
    color: #000000 !important;
    font-weight: 700 !important;
  }
  .dsr-print-table .info-pill,
  .dsr-print-table .issued-badge,
  .dsr-print-table .rate-val,
  .dsr-print-table .amount-val {
    background: transparent !important;
    border: none !important;
    padding: 0 !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    color: #000000 !important;
  }
}
</style>

<script>
$(document).ready(function(){
  function esc(s){ return $('<div>').text(s||'').html(); }
  function hideList($list){ $list.empty().hide(); }
  hideList($('#obList'));

  // Order Booker Autocomplete
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
          $list.append('<div class="ac-item ac-empty">No order booker found</div>');
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

  $(document).on('keydown', '#obSearch', function(e){
    var $list = $('#obList');
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

  // Instant on-screen search for products
  $('#loaderItemSearch').on('keyup', function(){
    var q = $(this).val().toLowerCase().trim();
    var matchCount = 0;
    $('.dsr-item-row').each(function(){
      var text = $(this).text().toLowerCase();
      if (q === '' || text.indexOf(q) > -1) {
        $(this).show();
        matchCount++;
      } else {
        $(this).hide();
      }
    });
    if (matchCount === 0 && q !== '') {
      $('#noItemSearchAlert').removeClass('d-none');
    } else {
      $('#noItemSearchAlert').addClass('d-none');
    }
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>