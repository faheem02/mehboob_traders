<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Physical Stock Count';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$success_msg = '';
$error_msg   = '';

// ──────────────────────────────────────────────────────────
// POST: Save physical stock count & apply adjustments
// ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_count'])) {
    $adj_date    = trim($_POST['adj_date'] ?? date('Y-m-d'));
    $reason      = trim($_POST['reason'] ?? 'Physical Stock Count');
    $notes_g     = trim($_POST['notes_g'] ?? '');
    $physical    = $_POST['physical'] ?? [];   // product_id => physical_count

    if (!$adj_date) $adj_date = date('Y-m-d');

    // Fetch all active products with current stock
    $stmtP = $pdo->query("SELECT p.id, p.name, p.code, p.stock_quantity, p.boxes_per_carton, p.purchase_price
                           FROM products p WHERE p.status = 1 ORDER BY p.name ASC");
    $all_products = $stmtP->fetchAll();

    $saved = 0;
    $skipped = 0;

    try {
        $pdo->beginTransaction();

        foreach ($all_products as $p) {
            $pid = (int)$p['id'];
            if (!isset($physical[$pid]) || $physical[$pid] === '') {
                $skipped++;
                continue;   // blank = not counted, skip
            }
            $phys_qty   = (int)$physical[$pid];
            $sys_qty    = (int)$p['stock_quantity'];
            $diff       = $phys_qty - $sys_qty;    // +surplus / -deficit
            $adj_type   = $diff > 0 ? 'surplus' : ($diff < 0 ? 'deficit' : 'matched');
            $unit_cost  = (float)$p['purchase_price'];
            $bpc        = max(1, (int)$p['boxes_per_carton']);
            $cost_impact = abs($diff) * ($unit_cost / $bpc);
            $adj_no      = generateStockAdjustmentNo();

            // Insert adjustment record
            $pdo->prepare("
                INSERT INTO stock_adjustments
                    (adjustment_no, adjustment_date, product_id, system_stock,
                     physical_stock, difference, boxes_per_carton, unit_cost,
                     total_cost_impact, adjustment_type, reason, notes, created_by, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ")->execute([
                $adj_no, $adj_date, $pid, $sys_qty,
                $phys_qty, $diff, $bpc, $unit_cost,
                $cost_impact, $adj_type, $reason ?: 'Physical Stock Count',
                $notes_g,
                $_SESSION['user_id'] ?? 1,
            ]);

            // Update product stock to match physical count
            $pdo->prepare("UPDATE products SET stock_quantity = ?, updated_at = ? WHERE id = ?")
                ->execute([$phys_qty, $adj_date, $pid]);

            $saved++;
        }

        $pdo->commit();

        if ($saved > 0) {
            $success_msg = "$saved product(s) adjusted successfully." . ($skipped > 0 ? " $skipped skipped (blank)." : '');
            logActivity($pdo, 'stock_adjustment', 'inventory', null, "Physical count: $saved adjusted, $skipped skipped.");
        } else {
            $error_msg = 'No physical counts were entered. Please fill in at least one product count.';
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_msg = 'Error saving adjustments: ' . htmlspecialchars($e->getMessage());
    }
}

// ──────────────────────────────────────────────────────────
// GET: Load products for the count sheet
// ──────────────────────────────────────────────────────────
$cat_filter  = $_GET['category_id'] ?? '';
$q_filter    = trim($_GET['q'] ?? '');
$show_history = isset($_GET['history']);

$sql = "SELECT p.*, c.name AS cat_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.status = 1";
$params = [];
if ($cat_filter !== '') {
    $sql .= " AND p.category_id = ?";
    $params[] = $cat_filter;
}
if ($q_filter !== '') {
    $sql .= " AND (p.name LIKE ? OR p.code LIKE ?)";
    $params[] = "%$q_filter%";
    $params[] = "%$q_filter%";
}
$sql .= " ORDER BY p.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

// History records (last 200)
$history = [];
if ($show_history) {
    $histSQL = "SELECT sa.*, p.name AS product_name, p.code AS product_code, u.full_name AS created_by_name
                FROM stock_adjustments sa
                LEFT JOIN products p ON p.id = sa.product_id
                LEFT JOIN users u ON u.id = sa.created_by
                ORDER BY sa.created_at DESC
                LIMIT 200";
    $history = $pdo->query($histSQL)->fetchAll();
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- Page Actions Row -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
  <div>
    <a href="products.php" class="btn btn-sm btn-outline-secondary mr-1">
      <i class="fas fa-arrow-left mr-1"></i> Back to Products
    </a>
    <a href="stock_adjustment.php<?= $show_history ? '' : '?history=1' ?>" class="btn btn-sm btn-outline-info mr-1">
      <i class="fas fa-history mr-1"></i> <?= $show_history ? 'Hide History' : 'View Adjustment History' ?>
    </a>
    <button type="button" class="btn btn-sm btn-outline-secondary d-print-none" onclick="window.print()">
      <i class="fas fa-print mr-1"></i> Print Sheet
    </button>
  </div>
  <span class="badge badge-info mt-2 mt-sm-0 px-3 py-2">
    <i class="fas fa-box mr-1"></i> <?= count($products) ?> Active Products
  </span>
</div>

<?php if ($success_msg): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle mr-1"></i> <?= htmlspecialchars($success_msg) ?>
  <button type="button" class="close" data-dismiss="alert">&times;</button>
</div>
<?php endif; ?>
<?php if ($error_msg): ?>
<div class="alert alert-danger alert-dismissible fade show">
  <i class="fas fa-exclamation-circle mr-1"></i> <?= htmlspecialchars($error_msg) ?>
  <button type="button" class="close" data-dismiss="alert">&times;</button>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════
     PHYSICAL COUNT SHEET
════════════════════════════════════════════════════════ -->
<div class="card shadow mb-4">
  <div class="card-header d-flex flex-wrap align-items-center justify-content-between py-3">
    <h6 class="font-weight-bold mb-0">
      <i class="fas fa-clipboard-check text-success mr-1"></i>
      Physical Stock Count Sheet
    </h6>
    <small class="text-muted d-none d-print-block">Mehboob Traders &mdash; Physical Count &mdash; <?= date('d-m-Y') ?></small>
  </div>

  <div class="card-body">

    <!-- Filter bar (hidden on print) -->
    <form method="get" class="row g-2 mb-3 d-print-none">
      <?php if ($show_history): ?><input type="hidden" name="history" value="1"><?php endif; ?>
      <div class="col-md-4">
        <input type="text" name="q" class="form-control form-control-sm"
               placeholder="Search product name or code" value="<?= htmlspecialchars($q_filter) ?>">
      </div>
      <div class="col-md-3">
        <select name="category_id" class="form-control form-control-sm">
          <option value="">All Categories</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $cat_filter == $c['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button class="btn btn-sm btn-outline-primary"><i class="fas fa-search mr-1"></i> Filter</button>
      </div>
      <?php if ($cat_filter !== '' || $q_filter !== ''): ?>
      <div class="col-auto">
        <a href="stock_adjustment.php<?= $show_history ? '?history=1' : '' ?>" class="btn btn-sm btn-outline-danger">
          <i class="fas fa-undo mr-1"></i> Reset
        </a>
      </div>
      <?php endif; ?>
    </form>

    <!-- Count form -->
    <form method="post" id="countForm">
      <input type="hidden" name="save_count" value="1">

      <!-- Meta row: date + reason -->
      <div class="row mb-3 d-print-none">
        <div class="col-md-3">
          <label class="small font-weight-bold text-muted">Count Date</label>
          <input type="date" name="adj_date" class="form-control form-control-sm"
                 value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-4">
          <label class="small font-weight-bold text-muted">Reason / Remarks</label>
          <input type="text" name="reason" class="form-control form-control-sm"
                 placeholder="Physical Stock Count" value="Physical Stock Count">
        </div>
        <div class="col-md-4">
          <label class="small font-weight-bold text-muted">Notes (optional)</label>
          <input type="text" name="notes_g" class="form-control form-control-sm"
                 placeholder="Additional notes...">
        </div>
      </div>

      <div class="card border-left-primary shadow-sm mb-3 d-print-none">
        <div class="card-body py-2 d-flex flex-wrap justify-content-between align-items-center">
          <div>
            <div class="small text-uppercase font-weight-bold text-primary">Total Difference</div>
            <div class="h5 mb-0 font-weight-bold" id="totalDiffTop">—</div>
          </div>
          <small class="text-muted">Net difference for entered physical counts (boxes)</small>
        </div>
      </div>

      <!-- Legend -->
      <div class="mb-2 d-print-none">
        <span class="badge badge-success px-2 py-1 mr-1"><i class="fas fa-plus-circle"></i> Surplus</span>
        <span class="badge badge-danger px-2 py-1 mr-1"><i class="fas fa-minus-circle"></i> Deficit</span>
        <span class="badge badge-secondary px-2 py-1 mr-1"><i class="fas fa-equals"></i> Matched</span>
        <span class="text-muted small ml-2">Leave Physical Count blank to skip a product.</span>
      </div>

      <!-- Printable heading (only shows on print) -->
      <div class="d-none d-print-block text-center mb-3">
        <h4 class="font-weight-bold mb-0">Mehboob Traders</h4>
        <p class="mb-0 text-muted small">Wholesale Business</p>
        <h5 class="font-weight-bold mt-1">PHYSICAL STOCK COUNT SHEET</h5>
        <p class="small">Date: <?= date('d-m-Y') ?></p>
      </div>

      <div class="table-responsive">
        <table class="table table-bordered table-hover table-sm" id="stockTable">
          <thead class="thead-dark">
            <tr>
              <th style="width:40px">#</th>
              <th>Code</th>
              <th>Product Name</th>
              <th>Category</th>
              <th class="text-center">Box / Carton</th>
              <th class="text-center text-info">System Stock<br><small class="font-weight-normal">(Boxes)</small></th>
              <th class="text-center text-warning d-print-none">System Stock<br><small class="font-weight-normal">(Cartons)</small></th>
              <th class="text-center" style="min-width:130px;">
                Physical Count<br>
                <small class="font-weight-normal d-print-none">(enter boxes)</small>
                <small class="font-weight-normal d-none d-print-inline">Boxes</small>
                &nbsp;
                <small class="font-weight-normal d-none d-print-inline">| Cartons</small>
              </th>
              <th class="text-center d-print-none">Difference<br><small class="font-weight-normal">(Auto)</small></th>
              <th class="text-center d-print-none">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$products): ?>
            <tr>
              <td colspan="10" class="text-center text-muted py-4">
                No active products found.
              </td>
            </tr>
            <?php endif; ?>
            <?php foreach ($products as $i => $p):
              $sys   = (int)$p['stock_quantity'];
              $bpc   = max(1, (int)$p['boxes_per_carton']);
              $ctns  = (int)floor($sys / $bpc);
              $rem   = (int)($sys % $bpc);
            ?>
            <tr class="count-row"
                data-sys="<?= $sys ?>"
                data-bpc="<?= $bpc ?>"
                data-id="<?= $p['id'] ?>">
              <td class="text-muted small"><?= $i + 1 ?></td>
              <td class="small text-muted"><?= htmlspecialchars($p['code']) ?></td>
              <td class="font-weight-bold"><?= htmlspecialchars($p['name']) ?></td>
              <td class="small"><?= htmlspecialchars($p['cat_name'] ?? '-') ?></td>
              <td class="text-center small"><?= $bpc ?></td>
              <td class="text-center font-weight-bold text-info"><?= $sys ?></td>
              <td class="text-center small text-muted d-print-none">
                <?= $ctns ?> ctn<?= $ctns != 1 ? 's' : '' ?>
                <?= $rem > 0 ? '+ ' . $rem . ' box' . ($rem > 1 ? 'es' : '') : '' ?>
              </td>
              <td class="text-center">
                <!-- Print: blank writing columns -->
                <div class="d-none d-print-flex align-items-center justify-content-center" style="gap:8px;">
                  <span style="border-bottom:1px solid #999;min-width:60px;display:inline-block;">&nbsp;</span>
                  <span class="small text-muted">|</span>
                  <span style="border-bottom:1px solid #999;min-width:50px;display:inline-block;">&nbsp;</span>
                </div>
                <!-- Screen: number input -->
                <div class="d-print-none">
                  <input type="number"
                         name="physical[<?= $p['id'] ?>]"
                         class="form-control form-control-sm text-center physical-input"
                         min="0"
                         step="1"
                         placeholder="—"
                         autocomplete="off"
                         data-id="<?= $p['id'] ?>"
                         style="max-width:100px;margin:0 auto;">
                </div>
              </td>
              <!-- Difference column (calculated live) -->
              <td class="text-center font-weight-bold diff-cell d-print-none" id="diff_<?= $p['id'] ?>">
                <span class="text-muted">—</span>
              </td>
              <!-- Status badge -->
              <td class="text-center d-print-none" id="badge_<?= $p['id'] ?>">
                <span class="badge badge-light">—</span>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <?php if ($products): ?>
          <tfoot class="d-print-none">
            <tr class="bg-light">
              <td colspan="5" class="font-weight-bold text-right text-muted small">Totals / Net Difference:</td>
              <td class="text-center font-weight-bold text-info" id="totalSystem">—</td>
              <td></td>
              <td class="text-center font-weight-bold text-primary" id="totalPhysical">—</td>
              <td class="text-center font-weight-bold" id="totalDiff">—</td>
              <td></td>
            </tr>
          </tfoot>
          <?php endif; ?>
        </table>
      </div>

      <?php if ($products): ?>
      <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 d-print-none">
        <div id="summaryBadges" class="mb-2 mb-sm-0">
          <span class="badge badge-success px-2 py-1 mr-1">Surplus: <strong id="surplusCount">0</strong></span>
          <span class="badge badge-danger px-2 py-1 mr-1">Deficit: <strong id="deficitCount">0</strong></span>
          <span class="badge badge-secondary px-2 py-1 mr-1">Matched: <strong id="matchedCount">0</strong></span>
          <span class="badge badge-light border px-2 py-1">Skipped: <strong id="skippedCount"><?= count($products) ?></strong></span>
        </div>
        <div>
          <button type="button" class="btn btn-outline-secondary btn-sm mr-2" id="clearAllBtn">
            <i class="fas fa-eraser mr-1"></i> Clear All
          </button>
          <button type="submit" class="btn btn-success btn-sm px-4" id="saveBtn">
            <i class="fas fa-save mr-1"></i> Apply Adjustments &amp; Update Stock
          </button>
        </div>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     HISTORY TABLE
════════════════════════════════════════════════════════ -->
<?php if ($show_history): ?>
<div class="card shadow mb-4 d-print-none">
  <div class="card-header py-3 d-flex justify-content-between align-items-center">
    <h6 class="font-weight-bold mb-0">
      <i class="fas fa-history text-info mr-1"></i>
      Adjustment History <span class="badge badge-info ml-1"><?= count($history) ?></span>
    </h6>
    <?php if ($history): ?>
    <small class="text-muted">Last 200 records</small>
    <?php endif; ?>
  </div>
  <div class="card-body p-0">
    <?php if (!$history): ?>
    <div class="text-center text-muted py-5">
      <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
      No adjustment records found.
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-bordered table-hover table-sm mb-0">
        <thead class="thead-light">
          <tr>
            <th>Adj. No.</th>
            <th>Date</th>
            <th>Product</th>
            <th class="text-center">System Qty</th>
            <th class="text-center">Physical Qty</th>
            <th class="text-center">Difference</th>
            <th class="text-center">Type</th>
            <th>Reason</th>
            <th>By</th>
            <th>Created At</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $h):
            $diff = (int)$h['difference'];
            $type = $h['adjustment_type'];
            $badgeClass = $type === 'surplus' ? 'badge-success' : ($type === 'deficit' ? 'badge-danger' : 'badge-secondary');
            $diffLabel  = $diff > 0 ? '+' . $diff : ($diff < 0 ? $diff : '0');
            $diffClass  = $diff > 0 ? 'text-success' : ($diff < 0 ? 'text-danger' : 'text-muted');
          ?>
          <tr>
            <td class="small font-weight-bold text-muted"><?= htmlspecialchars($h['adjustment_no']) ?></td>
            <td class="small"><?= formatDate($h['adjustment_date']) ?></td>
            <td>
              <span class="font-weight-bold"><?= htmlspecialchars($h['product_name'] ?? '-') ?></span>
              <br><small class="text-muted"><?= htmlspecialchars($h['product_code'] ?? '') ?></small>
            </td>
            <td class="text-center"><?= (int)$h['system_stock'] ?></td>
            <td class="text-center font-weight-bold"><?= (int)$h['physical_stock'] ?></td>
            <td class="text-center font-weight-bold <?= $diffClass ?>"><?= $diffLabel ?></td>
            <td class="text-center">
              <span class="badge <?= $badgeClass ?>"><?= ucfirst($type) ?></span>
            </td>
            <td class="small"><?= htmlspecialchars($h['reason'] ?? '-') ?></td>
            <td class="small"><?= htmlspecialchars($h['created_by_name'] ?? 'System') ?></td>
            <td class="small text-muted"><?= $h['created_at'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════ -->
<script>
$(document).ready(function(){

  var totalSysQty = 0;
  $('.count-row').each(function(){
    totalSysQty += parseInt($(this).data('sys')) || 0;
  });
  $('#totalSystem').text(totalSysQty.toLocaleString());

  // Live difference calculation
  function recalcRow($input) {
    var $row   = $input.closest('.count-row');
    var pid    = $row.data('id');
    var sys    = parseInt($row.data('sys')) || 0;
    var val    = $input.val().trim();

    var $diff  = $('#diff_' + pid);
    var $badge = $('#badge_' + pid);

    if (val === '') {
      $diff.html('<span class="text-muted">—</span>');
      $badge.html('<span class="badge badge-light">—</span>');
      return;
    }

    var phys = parseInt(val) || 0;
    var diff = phys - sys;
    var diffTxt = diff === 0 ? '0' : (diff > 0 ? '+' + diff : '' + diff);
    var cls     = diff > 0 ? 'text-success' : (diff < 0 ? 'text-danger' : 'text-muted');
    var badge   = diff > 0
      ? '<span class="badge badge-success"><i class="fas fa-plus-circle mr-1"></i>Surplus</span>'
      : (diff < 0
          ? '<span class="badge badge-danger"><i class="fas fa-minus-circle mr-1"></i>Deficit</span>'
          : '<span class="badge badge-secondary"><i class="fas fa-equals mr-1"></i>Matched</span>');

    $diff.html('<span class="' + cls + ' font-weight-bold">' + diffTxt + '</span>');
    $badge.html(badge);
  }

  function updateTotals() {
    var totalPhys  = 0;
    var totalDiff  = 0;
    var surplus    = 0;
    var deficit    = 0;
    var matched    = 0;
    var skipped    = 0;
    var counted    = 0;

    $('.physical-input').each(function(){
      var val = $(this).val().trim();
      if (val === '') {
        skipped++;
        return;
      }
      counted++;
      var $row = $(this).closest('.count-row');
      var sys  = parseInt($row.data('sys')) || 0;
      var phys = parseInt(val) || 0;
      var diff = phys - sys;
      totalPhys += phys;
      totalDiff += diff;
      if (diff > 0)      surplus++;
      else if (diff < 0) deficit++;
      else               matched++;
    });

    var diffCls = totalDiff > 0 ? 'text-success' : (totalDiff < 0 ? 'text-danger' : 'text-muted');
    var diffTxt = totalDiff === 0 ? '0' : (totalDiff > 0 ? '+' + totalDiff : '' + totalDiff);

    $('#totalPhysical').text(counted > 0 ? totalPhys.toLocaleString() : '—');
    var diffDisplay = counted > 0 ? '<span class="' + diffCls + '">' + diffTxt + ' Boxes</span>' : '—';
    $('#totalDiff').html(diffDisplay);
    $('#totalDiffTop').html(diffDisplay).attr('class', 'h5 mb-0 font-weight-bold ' + diffCls);
    $('#surplusCount').text(surplus);
    $('#deficitCount').text(deficit);
    $('#matchedCount').text(matched);
    $('#skippedCount').text(skipped);
  }

  $(document).on('input', '.physical-input', function(){
    recalcRow($(this));
    updateTotals();
  });

  // Allow Tab key to jump between physical inputs easily
  $(document).on('keydown', '.physical-input', function(e){
    if (e.key === 'Enter') {
      e.preventDefault();
      var inputs = $('.physical-input:visible');
      var idx    = inputs.index(this);
      if (idx < inputs.length - 1) inputs.eq(idx + 1).focus();
    }
  });

  // Clear all inputs
  $('#clearAllBtn').on('click', function(){
    if (!confirm('Clear all entered physical counts?')) return;
    $('.physical-input').val('');
    $('.diff-cell').html('<span class="text-muted">—</span>');
    $('[id^="badge_"]').html('<span class="badge badge-light">—</span>');
    updateTotals();
  });

  // Confirm before submit
  $('#countForm').on('submit', function(e){
    var counted = 0;
    $('.physical-input').each(function(){
      if ($(this).val().trim() !== '') counted++;
    });
    if (counted === 0) {
      e.preventDefault();
      alert('Please enter physical count for at least one product before saving.');
      return false;
    }
    if (!confirm('Apply adjustments for ' + counted + ' product(s)?\n\nThis will UPDATE the system stock to match the physical counts you entered.')) {
      e.preventDefault();
      return false;
    }
    $('#saveBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving...');
  });

  // Initialise totals
  updateTotals();
});
</script>

<!-- Print styles -->
<style>
@media print {
  .sidebar, .topbar, .sidebar-overlay, .scroll-to-top,
  .d-print-none, nav, .sticky-footer { display: none !important; }
  #content-wrapper { margin: 0 !important; padding: 0 !important; }
  .content { padding: 0 !important; }
  .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
  .card-header { background: #f8f9fa !important; }
  body { font-size: 12px; }
}
</style>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>