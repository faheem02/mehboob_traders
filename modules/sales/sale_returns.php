<?php
$page_title = 'Sale Returns History';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin', 'order_booker']);

$from = isset($_GET['from']) ? trim($_GET['from']) : '';
$to   = isset($_GET['to']) ? trim($_GET['to']) : '';
$q    = isset($_GET['q']) ? trim($_GET['q']) : '';
$ob_id_filter = isset($_GET['order_booker_id']) ? intval($_GET['order_booker_id']) : 0;
$ob_ids = [];
if ($ob_id_filter > 0) {
    $ob_ids[] = $ob_id_filter;
} elseif (isset($_GET['order_booker_id']) && is_array($_GET['order_booker_id'])) {
    foreach ($_GET['order_booker_id'] as $oid) {
        $oidv = intval($oid);
        if ($oidv > 0) {
            $ob_ids[] = $oidv;
        }
    }
}
$ob_ids = array_values(array_unique($ob_ids));

$sql = "
    SELECT 
        sr.*,
        c.full_name AS customer_name,
        c.area AS customer_area,
        s.invoice_no AS original_invoice_no,
        s.created_by AS sale_created_by,
        ob.full_name AS order_taker
    FROM sale_returns sr
    LEFT JOIN customers c ON c.id = sr.customer_id
    LEFT JOIN sales s ON s.id = COALESCE(sr.invoice_id, sr.sale_id)
    LEFT JOIN users ob ON ob.id = s.created_by
    WHERE 1=1
";

$params = [];

if ($from !== '') {
    $sql .= " AND sr.return_date >= ?";
    $params[] = $from;
}
if ($to !== '') {
    $sql .= " AND sr.return_date <= ?";
    $params[] = $to;
}

if (!isAdmin()) {
    $sql .= " AND s.created_by = ?";
    $params[] = (int)$_SESSION['user_id'];
} elseif (!empty($ob_ids)) {
    $placeholders_ob = implode(',', array_fill(0, count($ob_ids), '?'));
    $sql .= " AND s.created_by IN ($placeholders_ob)";
    foreach ($ob_ids as $oid) {
        $params[] = $oid;
    }
}

if ($q !== '') {
    $sql .= " AND (sr.return_no LIKE ? OR c.full_name LIKE ? OR s.invoice_no LIKE ? OR sr.reason LIKE ? OR sr.refund_type LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$sql .= " ORDER BY sr.return_date DESC, sr.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_returns = 0;
$completed_returns = 0;
foreach ($returns as $r) {
    $amt = (float)$r['total_amount'];
    $total_returns += $amt;
    if (strtolower($r['status']) === 'completed') {
        $completed_returns += $amt;
    }
}

$ob_name = '';
if (count($ob_ids) === 1) {
    $on = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $on->execute([$ob_ids[0]]);
    $ob_name = (string)$on->fetchColumn();
}

$period_label = 'All Returns';
$period_desc = 'All dates';
if ($from && $to) {
    $period_label = 'Filtered Returns';
    $period_desc = formatDate($from) . ' to ' . formatDate($to);
} elseif ($from) {
    $period_label = 'Filtered Returns';
    $period_desc = 'From ' . formatDate($from);
} elseif ($to) {
    $period_label = 'Filtered Returns';
    $period_desc = 'Until ' . formatDate($to);
}

$filter_note = [];
if ($ob_name !== '') {
    $filter_note[] = 'Order Taker: ' . $ob_name;
}
if ($q !== '') {
    $filter_note[] = 'Search: ' . $q;
}
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
  .report-table tr { page-break-inside: avoid !important; break-inside: avoid !important; }
}
</style>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center d-print-none">
    <h6 class="mb-0"><i class="fas fa-undo text-danger"></i> Sale Returns History (<span id="topHeaderCount"><?= count($returns) ?></span>)</h6>
    <div class="d-flex flex-wrap">
      <a href="invoices.php" class="btn btn-sm btn-outline-primary mr-2"><i class="fas fa-file-invoice"></i> Invoices</a>
      <button type="button" class="btn btn-sm btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    </div>
  </div>
  <div class="card-body">
    <div class="d-none d-print-block mb-3">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <h4 class="mb-0">MEHBOOB TRADERS</h4>
          <div class="text-muted">Sale Returns History</div>
          <div class="small text-muted mt-1"><?= $period_label ?><?= $filter_note ?></div>
        </div>
        <div class="text-right">
          <div class="small">Printed: <?= date('d-M-Y h:i A') ?></div>
          <?php if ($printed_by): ?><div class="small">By: <?= htmlspecialchars($printed_by) ?></div><?php endif; ?>
        </div>
      </div>
      <hr>
    </div>

    <form method="get" class="form-row align-items-end mb-3 d-print-none">
      <div class="form-group col-md-2 col-6">
        <label class="small mb-1">From Date</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars($from) ?>">
      </div>
      <div class="form-group col-md-2 col-6">
        <label class="small mb-1">To Date</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars($to) ?>">
      </div>
      <?php if (isAdmin()): ?>
      <div class="form-group col-md-3 col-12">
        <label class="small mb-1">Order Taker</label>
        <select name="order_booker_id" class="form-control form-control-sm">
          <option value="0">All Order Takers</option>
          <?php
          $obList = $pdo->query("SELECT id, full_name FROM users WHERE role='order_booker' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
          foreach ($obList as $ob) {
              $sel = in_array((int)$ob['id'], $ob_ids) ? 'selected' : '';
              echo '<option value="'.(int)$ob['id'].'" '.$sel.'>'.htmlspecialchars($ob['full_name']).'</option>';
          }
          ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="form-group col-md-3 col-12">
        <label class="small mb-1">Search</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Return No, Invoice, Customer, Reason..." value="<?= htmlspecialchars($q) ?>">
      </div>
      <div class="form-group col-md-2 col-12">
        <div class="d-flex" style="gap: 4px;">
          <button type="submit" class="btn btn-sm btn-primary flex-fill"><i class="fas fa-filter"></i> Apply</button>
          <a href="sale_returns.php" class="btn btn-sm btn-light flex-fill"><i class="fas fa-undo"></i> Reset</a>
        </div>
      </div>
    </form>

    <div class="row mb-3">
      <div class="col-xl-3 col-md-6 col-12 mb-2">
        <div class="card border-left-danger shadow-sm h-100">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Total Returns</div>
            <div class="h5 mb-0 font-weight-bold text-danger">PKR <?= formatCurrency($total_returns) ?></div>
            <div class="small text-muted"><?= count($returns) ?> record(s)</div>
          </div>
        </div>
      </div>
      <div class="col-xl-3 col-md-6 col-12 mb-2">
        <div class="card border-left-success shadow-sm h-100">
          <div class="card-body py-2 px-3">
            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Completed Returns</div>
            <div class="h5 mb-0 font-weight-bold text-success">PKR <?= formatCurrency($completed_returns) ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-bordered table-hover report-table">
        <thead class="thead-light">
          <tr>
            <th>Return No</th>
            <th>Return Date</th>
            <th>Original Invoice</th>
            <th>Customer</th>
            <th>Order Taker</th>
            <th>Refund Type</th>
            <th>Status</th>
            <th>Reason</th>
            <th class="text-right">Amount</th>
            <th class="text-center no-print">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($returns)): ?>
          <tr>
            <td colspan="10" class="text-center text-muted py-4">No sale returns found.</td>
          </tr>
          <?php else: ?>
          <?php foreach ($returns as $r): ?>
          <tr>
            <td class="font-weight-bold text-danger"><?= htmlspecialchars($r['return_no']) ?></td>
            <td><?= formatDate($r['return_date']) ?></td>
            <td>
              <?php if (!empty($r['original_invoice_no'])): ?>
                <a href="invoice.php?id=<?= (int)($r['invoice_id'] ?: $r['sale_id']) ?>"><?= htmlspecialchars($r['original_invoice_no']) ?></a>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="font-weight-bold text-dark"><?= htmlspecialchars($r['customer_name'] ?? 'Counter Customer') ?></div>
              <?php if (!empty($r['customer_area'])): ?>
                <span class="badge badge-light border text-muted" style="font-size: 11px;"><i class="fas fa-map-marker-alt text-danger"></i> <?= htmlspecialchars($r['customer_area']) ?></span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($r['order_taker'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['refund_type'] ?: '—') ?></td>
            <td>
              <?php
              $st = strtolower($r['status'] ?? '');
              $badge = 'badge-secondary';
              if ($st === 'completed') $badge = 'badge-success';
              if ($st === 'pending') $badge = 'badge-warning';
              if ($st === 'cancelled') $badge = 'badge-danger';
              ?>
              <span class="badge <?= $badge ?>"><?= htmlspecialchars($r['status'] ?: '—') ?></span>
            </td>
            <td class="text-muted small"><?= htmlspecialchars($r['reason'] ?: '—') ?></td>
            <td class="text-right font-weight-bold text-danger">PKR <?= formatCurrency($r['total_amount']) ?></td>
            <td class="text-center no-print" nowrap>
              <?php if (isAdmin()): ?>
                <a href="sale_return_edit.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-warning" title="Edit Sale Return"><i class="fas fa-edit"></i> Edit</a>
              <?php endif; ?>
              <a href="print_return.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-danger" target="_blank" title="View / Print Return Voucher"><i class="fas fa-print"></i> Print</a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
        <?php if (!empty($returns)): ?>
        <tfoot>
          <tr class="font-weight-bold">
            <td colspan="8" class="text-right">Total:</td>
            <td class="text-right text-danger">PKR <?= formatCurrency($total_returns) ?></td>
            <td class="no-print"></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>

    <div class="report-foot d-none d-print-block mt-3">
      <div><strong>Prepared by:</strong> <?= htmlspecialchars($printed_by ?: '—') ?></div>
    </div>
  </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
