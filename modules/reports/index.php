<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Reports';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$report = $_GET['report'] ?? 'sales';

// Sales totals for period
$ps = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) t, COALESCE(SUM(paid_amount),0) p, COALESCE(SUM(due_amount),0) d FROM sales WHERE sale_date BETWEEN ? AND ? AND status<>'cancelled'");
$ps->execute([$from, $to]); $st = $ps->fetch();

$pp = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) t, COALESCE(SUM(paid_amount),0) p, COALESCE(SUM(due_amount),0) d FROM purchases WHERE purchase_date BETWEEN ? AND ? AND status<>'cancelled'");
$pp->execute([$from, $to]); $pt = $pp->fetch();

$pe = $pdo->prepare("SELECT COALESCE(SUM(amount),0) t FROM expenses WHERE expense_date BETWEEN ? AND ?");
$pe->execute([$from, $to]); $et = (float)$pe->fetchColumn();

$lead = $st['t'] - $pt['t'] - $et;

// Daily sales summary
$daily = $pdo->prepare("SELECT sale_date, COUNT(*) cnt, SUM(total_amount) total FROM sales WHERE sale_date BETWEEN ? AND ? AND status<>'cancelled' GROUP BY sale_date ORDER BY sale_date DESC");
$daily->execute([$from, $to]);
$daily = $daily->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<?php
// Choose title
$reportTitle = '';
if ($report == 'sales') $reportTitle = 'Sales Report';
elseif ($report == 'purchases') $reportTitle = 'Purchase Report';
elseif ($report == 'profit') $reportTitle = 'Profit / Loss Report';
else $reportTitle = 'Report';
$page_title = $reportTitle;
?>

<div class="card shadow">
  <div class="card-header"><h6><i class="fas fa-chart-bar"></i> Reports</h6></div>
  <div class="card-body">
    <form method="get" class="row g-2 mb-4">
      <div class="col-md-2">
        <label class="form-label">Report</label>
        <select name="report" class="form-control">
          <option value="sales" <?= $report=='sales'?'selected':'' ?>>Sales</option>
          <option value="purchases" <?= $report=='purchases'?'selected':'' ?>>Purchases</option>
          <option value="profit" <?= $report=='profit'?'selected':'' ?>>Profit / Loss</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">From</label>
        <input type="date" name="from" class="form-control datepicker" value="<?=htmlspecialchars($from)?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">To</label>
        <input type="date" name="to" class="form-control datepicker" value="<?=htmlspecialchars($to)?>">
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-primary btn-block"><i class="fas fa-filter"></i> Generate</button>
      </div>
    </form>

    <div class="row">
      <div class="col-md-3">
        <div class="card border-left-primary shadow stat-card"><div class="card-body py-3 text-center">
          <div class="text-xs text-uppercase text-muted">Total Sales</div>
          <div class="h5 mb-0 font-weight-bold text-primary">PKR <?=formatCurrency($st['t'])?></div>
        </div></div>
      </div>
      <div class="col-md-3">
        <div class="card border-left-warning shadow stat-card"><div class="card-body py-3 text-center">
          <div class="text-xs text-uppercase text-muted">Total Purchases</div>
          <div class="h5 mb-0 font-weight-bold text-warning">PKR <?=formatCurrency($pt['t'])?></div>
        </div></div>
      </div>
      <div class="col-md-3">
        <div class="card border-left-danger shadow stat-card"><div class="card-body py-3 text-center">
          <div class="text-xs text-uppercase text-muted">Total Expenses</div>
          <div class="h5 mb-0 font-weight-bold text-danger">PKR <?=formatCurrency($et)?></div>
        </div></div>
      </div>
      <div class="col-md-3">
        <div class="card border-left-success shadow stat-card"><div class="card-body py-3 text-center">
          <div class="text-xs text-uppercase text-muted">Profit / Loss</div>
          <div class="h5 mb-0 font-weight-bold <?= $lead >= 0 ? 'text-success' : 'text-danger'?>">PKR <?=formatCurrency($lead)?></div>
        </div></div>
      </div>
    </div>

    <?php if ($report == 'sales'): ?>
    <hr>
    <h6 class="mt-3"><i class="fas fa-list"></i> Daily Sales</h6>
    <div class="table-responsive">
      <table class="table table-bordered table-hover mt-2">
        <thead><tr><th>Date</th><th>Invoices</th><th>Total Sales</th></tr></thead>
        <tbody>
          <?php foreach ($daily as $d): ?>
          <tr><td><?=formatDate($d['sale_date'])?></td><td><?=(int)$d['cnt']?></td><td class="font-weight-bold text-primary">PKR <?=formatCurrency($d['total'])?></td></tr>
          <?php endforeach; ?>
          <?php if (!count($daily)): ?><tr><td colspan="3" class="text-center text-muted py-3">No sales in this period</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

  </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>