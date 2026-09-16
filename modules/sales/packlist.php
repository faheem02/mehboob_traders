<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Delivery Loading Sheet';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

$my_areas = currentUserAreas($pdo); // null = admin (all areas)
$all_known = allKnownAreas($pdo);
$allowed_areas = isAdmin() ? $all_known : (array)$my_areas;

// Filters
$today = date('Y-m-d');
$from = isset($_GET['from']) ? trim($_GET['from']) : $today;
$to = isset($_GET['to']) ? trim($_GET['to']) : $today;
$area = trim($_GET['area'] ?? '');
$salesman_id = (int)($_GET['salesman_id'] ?? 0);
$category_id = (int)($_GET['category_id'] ?? 0);
$product_id = (int)($_GET['product_id'] ?? 0);

if ($area !== '' && !in_array($area, $allowed_areas, true)) { $area = ''; }

// Dropdown options
$all_categories = $pdo->query("SELECT id, name FROM categories WHERE status = 1 ORDER BY name ASC")->fetchAll();
$all_products = $pdo->query("SELECT id, name, category_id FROM products WHERE status = 1 ORDER BY name ASC")->fetchAll();
$all_salesmen = $pdo->query("SELECT id, full_name FROM employees WHERE employee_type = 'salesman' AND status = 1 ORDER BY full_name ASC")->fetchAll();

// Build aggregation query
$sql = "SELECT 
            COALESCE(c.id, 0) AS category_id,
            COALESCE(c.name, 'General / Uncategorized') AS category_name,
            p.id AS product_id,
            p.name AS product_name,
            p.code AS product_code,
            p.unit,
            p.boxes_per_carton,
            b.name AS brand_name,
            SUM(si.quantity) AS total_quantity
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        JOIN products p ON si.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN customers cust ON s.customer_id = cust.id
        WHERE s.status <> 'cancelled'";

$params = [];

if ($from !== '') {
    $sql .= " AND s.sale_date >= ?";
    $params[] = $from;
}
if ($to !== '') {
    $sql .= " AND s.sale_date <= ?";
    $params[] = $to;
}
if ($area !== '') {
    $sql .= " AND LOWER(cust.area) = LOWER(?)";
    $params[] = $area;
} elseif ($my_areas !== null) {
    if (empty($my_areas)) {
        $sql .= " AND 1=0";
    } else {
        $in_placeholders = implode(',', array_fill(0, count($my_areas), '?'));
        $sql .= " AND cust.area IN ($in_placeholders)";
        $params = array_merge($params, $my_areas);
    }
}
if ($salesman_id > 0) {
    $sql .= " AND s.salesman_id = ?";
    $params[] = $salesman_id;
}
if ($category_id > 0) {
    $sql .= " AND p.category_id = ?";
    $params[] = $category_id;
}
if ($product_id > 0) {
    $sql .= " AND p.id = ?";
    $params[] = $product_id;
}

$sql .= " GROUP BY c.id, c.name, p.id, p.name, p.code, p.unit, p.boxes_per_carton, b.name
          ORDER BY category_name ASC, p.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$raw_items = $stmt->fetchAll();

// Group items category-wise and compute totals
$grouped = [];
$total_products_count = 0;
$grand_total_cartons = 0;
$grand_total_loose = 0;
$grand_total_boxes = 0;

foreach ($raw_items as $item) {
    $cat_name = $item['category_name'];
    $bpc = max((int)($item['boxes_per_carton'] ?? 1), 1);
    $qty = (int)$item['total_quantity'];
    $cartons = intdiv($qty, $bpc);
    $loose = $qty % $bpc;

    $item['cartons'] = $cartons;
    $item['loose'] = $loose;
    $item['bpc'] = $bpc;

    if (!isset($grouped[$cat_name])) {
        $grouped[$cat_name] = [
            'category_id' => $item['category_id'],
            'category_name' => $cat_name,
            'items' => [],
            'subtotal_cartons' => 0,
            'subtotal_loose' => 0,
            'subtotal_boxes' => 0,
        ];
    }

    $grouped[$cat_name]['items'][] = $item;
    $grouped[$cat_name]['subtotal_cartons'] += $cartons;
    $grouped[$cat_name]['subtotal_loose'] += $loose;
    $grouped[$cat_name]['subtotal_boxes'] += $qty;

    $total_products_count++;
    $grand_total_cartons += $cartons;
    $grand_total_loose += $loose;
    $grand_total_boxes += $qty;
}

$total_categories_count = count($grouped);

// Context labels for printable sheet
$period_desc = 'Today (' . formatDate($today) . ')';
if ($from && $to) {
    $period_desc = ($from === $to) ? formatDate($from) : formatDate($from) . ' to ' . formatDate($to);
} elseif ($from) {
    $period_desc = 'From ' . formatDate($from);
} elseif ($to) {
    $period_desc = 'Until ' . formatDate($to);
} else {
    $period_desc = 'All Dates';
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

$category_label = 'All Categories';
if ($category_id > 0) {
    foreach ($all_categories as $cat) {
        if ((int)$cat['id'] === $category_id) {
            $category_label = $cat['name'];
            break;
        }
    }
}

$printed_by = '';
if (!empty($_SESSION['user_id'])) {
    $pu = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $pu->execute([(int)$_SESSION['user_id']]);
    $printed_by = (string)$pu->fetchColumn();
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center d-print-none">
    <h6><i class="fas fa-truck-loading text-primary"></i> Delivery Loading Sheet <small class="text-muted">(Loader Copy)</small></h6>
    <div class="d-flex flex-wrap">
      <?php if ($total_products_count > 0): ?>
      <button type="button" class="btn btn-sm btn-primary mr-2" onclick="window.print()"><i class="fas fa-print"></i> Print Loading Sheet</button>
      <?php endif; ?>
      <a href="invoices.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-file-invoice"></i> Invoices</a>
    </div>
  </div>
  <div class="card-body">

    <!-- Filters Form -->
    <form method="get" class="d-print-none mb-3 bg-light p-3 rounded border">
      <div class="row g-2">
        <div class="col-md-2 col-sm-6">
          <label class="form-label font-weight-bold small">From Date</label>
          <input type="date" name="from" class="form-control form-control-sm" value="<?=htmlspecialchars($from)?>">
        </div>
        <div class="col-md-2 col-sm-6">
          <label class="form-label font-weight-bold small">To Date</label>
          <input type="date" name="to" class="form-control form-control-sm" value="<?=htmlspecialchars($to)?>">
        </div>
        <div class="col-md-2 col-sm-6">
          <label class="form-label font-weight-bold small">Area</label>
          <select name="area" class="form-control form-control-sm">
            <option value="">-- All Areas --</option>
            <?php foreach ($allowed_areas as $an): ?>
            <option value="<?=htmlspecialchars($an)?>" <?= $area === $an ? 'selected' : '' ?>><?=htmlspecialchars($an)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 col-sm-6">
          <label class="form-label font-weight-bold small">Salesman</label>
          <select name="salesman_id" class="form-control form-control-sm">
            <option value="">-- All Salesmen --</option>
            <?php foreach ($all_salesmen as $se): ?>
            <option value="<?=$se['id']?>" <?= $salesman_id === (int)$se['id'] ? 'selected' : '' ?>><?=htmlspecialchars($se['full_name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 col-sm-6">
          <label class="form-label font-weight-bold small">Category</label>
          <select name="category_id" id="categorySelect" class="form-control form-control-sm">
            <option value="">-- All Categories --</option>
            <?php foreach ($all_categories as $c): ?>
            <option value="<?=$c['id']?>" <?= $category_id === (int)$c['id'] ? 'selected' : '' ?>><?=htmlspecialchars($c['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 col-sm-6">
          <label class="form-label font-weight-bold small">Product</label>
          <select name="product_id" id="productSelect" class="form-control form-control-sm">
            <option value="">-- All Products --</option>
            <?php foreach ($all_products as $p): ?>
            <option value="<?=$p['id']?>" data-category="<?=(int)$p['category_id']?>" <?= $product_id === (int)$p['id'] ? 'selected' : '' ?>><?=htmlspecialchars($p['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="row mt-2 align-items-center">
        <div class="col-md-6 col-sm-12 mb-2 mb-md-0">
          <small class="text-muted"><i class="fas fa-info-circle"></i> Loader sheet groups stock category-wise with full cartons and loose boxes for vehicle loading.</small>
        </div>
        <div class="col-md-6 col-sm-12 text-md-right">
          <a href="packlist.php" class="btn btn-sm btn-outline-secondary mr-1"><i class="fas fa-undo"></i> Reset</a>
          <button type="submit" class="btn btn-sm btn-primary px-4"><i class="fas fa-search mr-1"></i> Load Stock Sheet</button>
        </div>
      </div>
    </form>

    <!-- Printable Header (Report Sheet) -->
    <div class="report-sheet d-none d-print-block mb-3">
      <div class="report-head">
        <div class="report-brand-line">
          <div class="report-brand">
            <div class="report-brand-name">Mehboob Traders</div>
            <div class="report-brand-sub">Wholesale Business &middot; Lahore, Pakistan &middot; GST No: --</div>
          </div>
          <div class="report-title-box">
            <div class="report-title">DELIVERY LOADING SHEET</div>
            <div class="report-meta">Loader Stock Copy &middot; <?=$period_desc?></div>
          </div>
        </div>
      </div>

      <table class="report-summary-table">
        <tr>
          <td class="rs-cell"><span class="rs-label">Date / Period</span><span class="rs-val"><?=$period_desc?></span></td>
          <td class="rs-cell"><span class="rs-label">Area</span><span class="rs-val"><?=htmlspecialchars($area_label)?></span></td>
          <td class="rs-cell"><span class="rs-label">Salesman</span><span class="rs-val"><?=htmlspecialchars($salesman_label)?></span></td>
          <td class="rs-cell"><span class="rs-label">Full Cartons</span><span class="rs-val font-weight-bold" style="color:#0f766e;"><?=$grand_total_cartons?></span></td>
          <td class="rs-cell"><span class="rs-label">Loose Boxes</span><span class="rs-val font-weight-bold" style="color:#b45309;"><?=$grand_total_loose?></span></td>
          <td class="rs-cell"><span class="rs-label">Total Units</span><span class="rs-val font-weight-bold" style="color:#1d4ed8;"><?=$grand_total_boxes?></span></td>
        </tr>
      </table>
    </div>

    <!-- On-screen Summary Strip -->
    <div class="row mb-3 d-print-none">
      <div class="col-md-3 col-6 mb-2">
        <div class="border rounded p-2 bg-light text-center">
          <div class="text-xs text-muted text-uppercase">Categories</div>
          <div class="h5 mb-0 font-weight-bold text-dark"><?=$total_categories_count?></div>
        </div>
      </div>
      <div class="col-md-3 col-6 mb-2">
        <div class="border rounded p-2 bg-light text-center">
          <div class="text-xs text-muted text-uppercase">Products</div>
          <div class="h5 mb-0 font-weight-bold text-dark"><?=$total_products_count?></div>
        </div>
      </div>
      <div class="col-md-2 col-4 mb-2">
        <div class="border rounded p-2 bg-light text-center">
          <div class="text-xs text-muted text-uppercase">Full Cartons (Patey)</div>
          <div class="h5 mb-0 font-weight-bold text-success"><?=$grand_total_cartons?></div>
        </div>
      </div>
      <div class="col-md-2 col-4 mb-2">
        <div class="border rounded p-2 bg-light text-center">
          <div class="text-xs text-muted text-uppercase">Loose (Khuli Dabbi)</div>
          <div class="h5 mb-0 font-weight-bold text-warning"><?=$grand_total_loose?></div>
        </div>
      </div>
      <div class="col-md-2 col-4 mb-2">
        <div class="border rounded p-2 bg-light text-center">
          <div class="text-xs text-muted text-uppercase">Total Quantity</div>
          <div class="h5 mb-0 font-weight-bold text-primary"><?=$grand_total_boxes?></div>
        </div>
      </div>
    </div>

    <?php if ($total_products_count === 0): ?>
      <div class="alert alert-light border text-center py-5">
        <i class="fas fa-boxes text-muted fa-3x mb-3 d-block"></i>
        <h5 class="text-muted font-weight-bold">No stock items to load</h5>
        <p class="text-muted small mb-0">No active delivery orders were found for the selected date, area, or category.</p>
      </div>
    <?php else: ?>

      <!-- Search on-screen -->
      <div class="mb-3 d-print-none" style="max-width: 320px;">
        <div class="input-group input-group-sm">
          <div class="input-group-prepend"><span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span></div>
          <input type="text" id="loaderItemSearch" class="form-control" placeholder="Search product in list...">
        </div>
      </div>

      <!-- Category-wise Product Tables -->
      <?php 
      $serial = 0;
      foreach ($grouped as $cat_name => $cat_data): 
      ?>
      <div class="card mb-4 border category-block">
        <div class="card-header py-2 bg-dark text-white d-flex justify-content-between align-items-center">
          <span class="font-weight-bold">
            <i class="fas fa-tags mr-2 text-warning"></i> <?=htmlspecialchars($cat_name)?>
            <span class="badge badge-secondary ml-2"><?=count($cat_data['items'])?> product<?= count($cat_data['items']) == 1 ? '' : 's' ?></span>
          </span>
          <span class="small d-none d-sm-inline text-light">
            Cartons: <strong><?=$cat_data['subtotal_cartons']?></strong> &middot; Loose: <strong><?=$cat_data['subtotal_loose']?></strong> &middot; Total: <strong><?=$cat_data['subtotal_boxes']?></strong>
          </span>
        </div>
        <div class="table-responsive">
          <table class="table table-bordered table-hover table-sm mb-0 report-table loader-table">
            <thead>
              <tr class="bg-light">
                <th style="width: 45px;" class="text-center">#</th>
                <th>Product Name</th>
                <th style="width: 140px;">Brand</th>
                <th style="width: 130px;" class="text-center">Carton Size</th>
                <th style="width: 130px;" class="text-right">Full Cartons</th>
                <th style="width: 130px;" class="text-right">Loose Boxes</th>
                <th style="width: 140px;" class="text-right font-weight-bold">Total Qty</th>
                <th style="width: 80px;" class="text-center">Loaded</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($cat_data['items'] as $item): $serial++; ?>
              <tr class="product-row">
                <td class="text-center text-muted"><?=$serial?></td>
                <td class="font-weight-bold item-name">
                  <?=htmlspecialchars($item['product_name'])?>
                  <small class="text-muted d-block"><?=htmlspecialchars($item['product_code'])?></small>
                </td>
                <td class="col-brand"><?=htmlspecialchars($item['brand_name'] ?: '—')?></td>
                <td class="text-center col-bpc text-muted small"><?=$item['bpc']?> <?=$item['unit']?> / ctn</td>
                <td class="text-right font-weight-bold text-success col-carton" style="font-size: 1.1rem;">
                  <?=$item['cartons'] > 0 ? $item['cartons'] : '-'?>
                </td>
                <td class="text-right font-weight-bold text-warning col-loose" style="font-size: 1.1rem;">
                  <?=$item['loose'] > 0 ? $item['loose'] : '-'?>
                </td>
                <td class="text-right font-weight-bold text-primary col-total" style="font-size: 1.15rem;">
                  <?=$item['total_quantity']?> <small class="text-muted font-weight-normal"><?=htmlspecialchars($item['unit'])?></small>
                </td>
                <td class="text-center col-loaded">
                  <div class="checkbox-box d-inline-block border rounded" style="width: 24px; height: 24px; vertical-align: middle;"></div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot class="bg-light font-weight-bold">
              <tr>
                <td colspan="4" class="text-right">Subtotal (<?=htmlspecialchars($cat_name)?>):</td>
                <td class="text-right text-success"><?=$cat_data['subtotal_cartons']?></td>
                <td class="text-right text-warning"><?=$cat_data['subtotal_loose']?></td>
                <td class="text-right text-primary"><?=$cat_data['subtotal_boxes']?></td>
                <td class="text-center">-</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
      <?php endforeach; ?>

      <!-- Grand Total Banner -->
      <div class="card border-primary mb-4">
        <div class="card-body py-3 bg-primary text-white">
          <div class="row align-items-center text-center text-md-left">
            <div class="col-md-4 mb-2 mb-md-0 font-weight-bold" style="font-size: 1.15rem;">
              <i class="fas fa-clipboard-check mr-2"></i> GRAND TOTAL TO LOAD:
            </div>
            <div class="col-md-8 text-center text-md-right">
              <span class="mr-4" style="font-size: 1.1rem;">Full Cartons: <strong><?=$grand_total_cartons?></strong></span>
              <span class="mr-4" style="font-size: 1.1rem;">Loose Boxes: <strong><?=$grand_total_loose?></strong></span>
              <span style="font-size: 1.25rem;" class="badge badge-light text-primary px-3 py-2 font-weight-bold">Total: <?=$grand_total_boxes?> Units</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Printable Signature Footer -->
      <div class="report-foot d-none d-print-block mt-4 pt-4 border-top">
        <div class="row text-center">
          <div class="col-4">
            <div class="border-top pt-2" style="border-top: 1px dashed #475569 !important;">
              <strong>Prepared By</strong>
              <div class="small text-muted"><?=htmlspecialchars($printed_by ?: 'Warehouse Office')?></div>
            </div>
          </div>
          <div class="col-4">
            <div class="border-top pt-2" style="border-top: 1px dashed #475569 !important;">
              <strong>Loaded By (Loader)</strong>
              <div class="small text-muted">Signature: ____________________</div>
            </div>
          </div>
          <div class="col-4">
            <div class="border-top pt-2" style="border-top: 1px dashed #475569 !important;">
              <strong>Verified By (Driver / Salesman)</strong>
              <div class="small text-muted">Signature: ____________________</div>
            </div>
          </div>
        </div>
        <div class="text-center mt-3 small text-muted">
          Mehboob Traders &middot; Vehicle Loading Sheet &middot; Printed on: <?=date('d-m-Y H:i')?>
        </div>
      </div>

    <?php endif; ?>

  </div>
</div>

<style>
.checkbox-box {
  border: 2px solid #334155 !important;
  background: #ffffff;
  border-radius: 4px;
}

@media print {
  @page { 
    size: A4 portrait; 
    margin: 8mm; 
  }

  body {
    background: #ffffff !important;
    color: #0f172a !important;
  }

  /* Clean Letterhead */
  .report-sheet { 
    margin-bottom: 12px !important; 
  }
  .report-sheet .report-head { 
    border-bottom: 2.5px solid #0f172a !important; 
    padding-bottom: 6px !important; 
    margin-bottom: 10px !important; 
  }
  .report-sheet .report-brand-name { 
    font-size: 26px !important; 
    font-weight: 800 !important; 
    color: #0f172a !important; 
    letter-spacing: -0.5px !important;
  }
  .report-sheet .report-brand-sub  { 
    font-size: 12px !important; 
    color: #475569 !important; 
    font-weight: 500 !important;
  }
  .report-sheet .report-title     { 
    font-size: 20px !important; 
    font-weight: 800 !important; 
    color: #0f172a !important; 
    text-transform: uppercase !important;
  }
  .report-sheet .report-meta      { 
    font-size: 13.5px !important; 
    font-weight: 700 !important; 
    color: #1e293b !important; 
  }

  /* Summary Table */
  .report-summary-table { 
    width: 100% !important; 
    border: 1.5px solid #334155 !important; 
    margin-bottom: 14px !important; 
    background: #ffffff !important;
  }
  .report-summary-table td.rs-cell { 
    padding: 6px 10px !important; 
    border: 1px solid #cbd5e1 !important; 
    text-align: center !important; 
  }
  .rs-label { 
    font-size: 11.5px !important; 
    font-weight: 700 !important; 
    letter-spacing: 0.5px !important; 
    color: #475569 !important; 
    text-transform: uppercase !important; 
    display: block !important;
    margin-bottom: 2px !important;
  }
  .rs-val { 
    font-size: 18px !important; 
    font-weight: 800 !important; 
    color: #0f172a !important; 
  }

  /* Category Block & Header: Clean light slate header (No heavy ink-eating black blocks) */
  .category-block { 
    break-inside: avoid !important; 
    page-break-inside: avoid !important; 
    border: 1.5px solid #334155 !important; 
    margin-bottom: 16px !important; 
    box-shadow: none !important;
    background: #ffffff !important;
  }
  .card-header.bg-dark { 
    background: #f1f5f9 !important; 
    color: #0f172a !important; 
    border-bottom: 1.5px solid #334155 !important; 
    padding: 8px 12px !important; 
  }
  .card-header.bg-dark span.font-weight-bold {
    font-size: 15px !important;
    color: #0f172a !important;
  }
  .card-header.bg-dark .text-warning {
    color: #0f172a !important;
  }
  .card-header.bg-dark .badge-secondary {
    background: #e2e8f0 !important;
    color: #0f172a !important;
    border: 1px solid #94a3b8 !important;
    font-size: 12px !important;
    padding: 2px 6px !important;
  }
  .card-header.bg-dark .text-light {
    color: #334155 !important;
    font-size: 13.5px !important;
    font-weight: 700 !important;
  }

  /* Table Grid */
  .table-responsive { overflow: visible !important; }
  .report-table { 
    width: 100% !important; 
    border-collapse: collapse !important; 
    border: 1px solid #475569 !important; 
  }
  .report-table th { 
    font-size: 13px !important; 
    font-weight: 800 !important; 
    padding: 8px 8px !important; 
    background: #f8fafc !important; 
    color: #0f172a !important; 
    border: 1px solid #64748b !important; 
    text-transform: uppercase !important;
    vertical-align: middle !important;
  }
  .report-table td { 
    font-size: 14px !important; 
    padding: 8px 8px !important; 
    border: 1px solid #94a3b8 !important; 
    color: #0f172a !important; 
    vertical-align: middle !important;
  }

  /* Specific Large & Readable Text in Print */
  .report-table td.item-name { 
    font-size: 15px !important; 
    font-weight: 700 !important; 
    color: #000000 !important; 
    line-height: 1.3 !important;
  }
  .report-table td.item-name small { 
    font-size: 12px !important; 
    color: #475569 !important; 
    font-weight: 600 !important;
  }
  .report-table td.col-brand { 
    font-size: 13.5px !important; 
    font-weight: 600 !important; 
    color: #1e293b !important; 
  }
  .report-table td.col-bpc { 
    font-size: 13px !important; 
    font-weight: 600 !important; 
    color: #334155 !important; 
  }
  .report-table td.col-carton { 
    font-size: 17px !important; 
    font-weight: 800 !important; 
    color: #047857 !important; 
  }
  .report-table td.col-loose { 
    font-size: 17px !important; 
    font-weight: 800 !important; 
    color: #b45309 !important; 
  }
  .report-table td.col-total { 
    font-size: 17.5px !important; 
    font-weight: 800 !important; 
    color: #1d4ed8 !important; 
  }

  /* Big, Prominent Checkbox */
  .checkbox-box { 
    border: 2.5px solid #000000 !important; 
    width: 24px !important; 
    height: 24px !important; 
    border-radius: 4px !important; 
    background: #ffffff !important;
    display: inline-block !important;
  }

  /* Subtotal Footer in Category Table */
  tfoot.bg-light td { 
    background: #f8fafc !important; 
    font-size: 14.5px !important; 
    font-weight: 800 !important; 
    border-top: 2px solid #334155 !important; 
    padding: 8px 8px !important; 
  }

  /* Grand Total Banner in Print */
  .card.border-primary { 
    border: 2px solid #0f172a !important; 
    background: #ffffff !important;
    margin-top: 14px !important;
    break-inside: avoid !important;
    page-break-inside: avoid !important;
  }
  .card-body.bg-primary { 
    background: #f8fafc !important; 
    color: #0f172a !important; 
    padding: 12px 16px !important;
    border: 1px solid #0f172a !important;
  }
  .card-body.bg-primary .font-weight-bold { 
    color: #0f172a !important; 
    font-size: 16px !important;
  }
  .card-body.bg-primary span { 
    color: #0f172a !important; 
    font-size: 16px !important;
  }
  .card-body.bg-primary strong { 
    color: #0f172a !important; 
  }
  .card-body.bg-primary .badge-light { 
    background: #0f172a !important; 
    color: #ffffff !important; 
    font-size: 17px !important;
    padding: 6px 12px !important;
    border-radius: 4px !important;
  }

  /* Signatures */
  .report-foot { 
    font-size: 13px !important; 
    color: #0f172a !important; 
    margin-top: 18px !important; 
    padding-top: 14px !important;
    break-inside: avoid !important;
    page-break-inside: avoid !important;
  }
  .report-foot strong { 
    font-size: 13.5px !important; 
    font-weight: 700 !important;
  }
}
</style>

<script>
$(document).ready(function(){
  // Filter products by selected category in the filter dropdown
  $('#categorySelect').on('change', function(){
    var catId = $(this).val();
    $('#productSelect option').each(function(){
      var prodCat = $(this).data('category');
      if (!catId || !prodCat || prodCat == catId || $(this).val() === '') {
        $(this).show();
      } else {
        $(this).hide();
      }
    });
    if (catId && $('#productSelect option:selected').is(':hidden')) {
      $('#productSelect').val('');
    }
  });

  // Instant on-screen search for products
  $('#loaderItemSearch').on('keyup', function(){
    var q = $(this).val().toLowerCase().trim();
    $('.category-block').each(function(){
      var catBlock = $(this);
      var visibleInCat = 0;
      catBlock.find('.product-row').each(function(){
        var text = $(this).text().toLowerCase();
        if (q === '' || text.indexOf(q) > -1) {
          $(this).show();
          visibleInCat++;
        } else {
          $(this).hide();
        }
      });
      if (visibleInCat === 0 && q !== '') {
        catBlock.hide();
      } else {
        catBlock.show();
      }
    });
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>