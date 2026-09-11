<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Salary Details';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

function monthRange($start, $end) {
    $months = [];
    $cur = new DateTime($start . '-01');
    $last = new DateTime($end . '-01');
    while ($cur <= $last) {
        $months[] = $cur->format('Y-m');
        $cur->modify('+1 month');
    }
    return $months;
}

$emp_id = (int)($_GET['employee_id'] ?? 0);
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');

$employee = getById('employees', $emp_id);

$months = [];
$details = [];
$total_paid = 0;
$total_remaining = 0;
$monthly = 0;
if ($employee) {
    $monthly = (float)$employee['salary'];
    $join = $employee['joining_date'] ?: $employee['created_at'];
    $start_month = date('Y-m', strtotime($join));
    $current_month = date('Y-m');

    $from_m = $from ?: $start_month;
    $to_m = $to ?: $current_month;
    if ($from_m > $to_m) { $tmp = $from_m; $from_m = $to_m; $to_m = $tmp; }

    $all_months = monthRange($start_month, $current_month);
    $months = array_values(array_filter($all_months, function($m) use ($from_m, $to_m) {
        return $m >= $from_m && $m <= $to_m;
    }));

    $stmt = $pdo->prepare("SELECT salary_month, SUM(amount) AS total,
                                  GROUP_CONCAT(CONCAT(slip_no,'|',payment_date) SEPARATOR ';;') AS slips
                           FROM employee_salaries
                           WHERE employee_id = ?
                           GROUP BY salary_month");
    $stmt->execute([$emp_id]);
    $by_month = [];
    foreach ($stmt->fetchAll() as $row) {
        $by_month[$row['salary_month']] = $row;
    }

    foreach ($months as $m) {
        $row = $by_month[$m] ?? null;
        $paid = $row ? (float)$row['total'] : 0;
        $remaining = max(0, $monthly - $paid);
        $total_paid += $paid;
        $total_remaining += $remaining;
        $details[$m] = [
            'paid' => $paid,
            'remaining' => $remaining,
            'slips' => $row ? explode(';;', $row['slips']) : [],
        ];
    }
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center d-print-none">
    <h6 class="mb-0"><i class="fas fa-list-alt"></i> Employee Salary Details</h6>
    <a href="salary_ledger.php" class="btn btn-sm btn-outline-secondary d-print-none"><i class="fas fa-arrow-left"></i> Back to Salary Ledger</a>
  </div>
  <div class="card-body">

    <?php if (!$employee): ?>
      <p class="text-muted text-center py-4 mb-0">Employee not found.</p>
    <?php else: ?>

    <form method="get" class="row mb-3 d-print-none">
      <input type="hidden" name="employee_id" value="<?=$emp_id?>">
      <div class="col-md-3">
        <label class="form-label small"><i class="fas fa-calendar-alt"></i> From</label>
        <input type="month" name="from" class="form-control" value="<?=htmlspecialchars($from)?>">
      </div>
      <div class="col-md-3">
        <label class="form-label small"><i class="fas fa-calendar-alt"></i> To</label>
        <input type="month" name="to" class="form-control" value="<?=htmlspecialchars($to)?>">
      </div>
      <div class="col-md-3 d-flex align-items-end">
        <button class="btn btn-outline-primary btn-block mr-2"><i class="fas fa-filter"></i> Filter</button>
      </div>
      <div class="col-md-3 d-flex align-items-end">
        <button type="button" class="btn btn-primary btn-block" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
      </div>
    </form>

    <div class="d-none d-print-block mb-3">
      <div class="text-center">
        <h4 class="font-weight-bold mb-0" style="color:#0f172a;">Mehboob Traders</h4>
        <small class="text-muted">Wholesale Business</small>
      </div>
    </div>

    <div class="row border-bottom pb-3 mb-3" style="border-top:2px solid #0f172a;">
      <div class="col-6">
        <h6 class="font-weight-bold text-primary mb-1">EMPLOYEE SALARY DETAILS</h6>
        <h5 class="font-weight-bold mb-0" style="color:#0f172a;"><?=htmlspecialchars($employee['full_name'])?></h5>
        <small class="text-muted">
          <?php if ($employee['employee_type']=='salesman'): ?>Salesman
          <?php elseif ($employee['employee_type']=='order_booker'): ?>Order Booker
          <?php else: ?>Loader<?php endif; ?>
          <?php if (!empty($employee['area'])): ?> &middot; <?=htmlspecialchars($employee['area'])?><?php endif; ?>
          &middot; Joining: <?= $employee['joining_date'] ? formatDate($employee['joining_date']) : formatDate($employee['created_at']) ?>
        </small>
      </div>
      <div class="col-6 text-right">
        <div><strong>Monthly Salary:</strong> PKR <?=formatCurrency($monthly)?></div>
        <div><strong>Period:</strong> <?= date('M Y', strtotime(($from ?: ($months[0] ?? $employee['created_at'])) . '-01')) ?> to <?= date('M Y', strtotime(($to ?: date('Y-m')) . '-01')) ?></div>
        <div><strong>Total Paid:</strong> <span class="text-success">PKR <?=formatCurrency($total_paid)?></span></div>
        <div><strong>Remaining Balance:</strong> <span class="<?= $total_remaining > 0 ? 'balance-negative' : 'balance-positive' ?>">PKR <?=formatCurrency($total_remaining)?></span></div>
      </div>
    </div>

    <div class="row mb-3 d-print-none">
      <div class="col-md-4 col-6 mb-2">
        <div class="small-box bg-slate">
          <div class="inner"><h3><?=count($months)?></h3><p>Months Shown</p></div>
          <div class="icon"><i class="fas fa-calendar-alt"></i></div>
        </div>
      </div>
      <div class="col-md-4 col-6 mb-2">
        <div class="small-box bg-emerald">
          <div class="inner"><h3><?=formatCurrency($total_paid)?></h3><p>Total Paid (PKR)</p></div>
          <div class="icon"><i class="fas fa-hand-holding-usd"></i></div>
        </div>
      </div>
      <div class="col-md-4 col-6 mb-2">
        <div class="small-box bg-danger" style="background:#ef4444;">
          <div class="inner"><h3><?=formatCurrency($total_remaining)?></h3><p>Remaining Balance (PKR)</p></div>
          <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
        </div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-bordered table-sm" id="monthTable">
        <thead class="thead-light">
          <tr>
            <th>Month</th>
            <th class="text-right">Salary</th>
            <th class="text-right">Paid</th>
            <th class="text-right">Remaining</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($months)): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No months found for the selected period.</td></tr>
          <?php else: foreach ($months as $m):
            $d = $details[$m];
            $isPaid = $d['paid'] >= $monthly && $monthly > 0;
            $isPartial = $d['paid'] > 0 && !$isPaid;
          ?>
          <tr>
            <td class="font-weight-bold"><?=date('F Y', strtotime($m . '-01'))?></td>
            <td class="text-right"><?=formatCurrency($monthly)?></td>
            <td class="text-right">
              <?php if ($d['paid'] > 0): ?>
                <?=formatCurrency($d['paid'])?>
                <?php if (!empty($d['slips'])): ?>
                  <div class="small text-muted">
                    <?php foreach ($d['slips'] as $s): list($slip, $pdate) = array_pad(explode('|', $s, 2), 2, ''); ?>
                      <?=htmlspecialchars($slip)?> <span class="text-nowrap">(<?= $pdate ? formatDate($pdate) : '' ?>)</span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-muted">&mdash;</span>
              <?php endif; ?>
            </td>
            <td class="text-right">
              <?php if ($d['remaining'] > 0): ?>
                <span class="balance-negative"><?=formatCurrency($d['remaining'])?></span>
              <?php else: ?>
                <span class="text-success">0.00</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($isPaid): ?>
                <span class="badge badge-success"><i class="fas fa-check"></i> Paid</span>
              <?php elseif ($isPartial): ?>
                <span class="badge badge-warning"><i class="fas fa-adjust"></i> Partial</span>
              <?php else: ?>
                <span class="badge badge-danger"><i class="fas fa-times"></i> Pending</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
          <tr class="font-weight-bold">
            <td>Total</td>
            <td class="text-right">PKR <?=formatCurrency($monthly * count($months))?></td>
            <td class="text-right text-success">PKR <?=formatCurrency($total_paid)?></td>
            <td class="text-right <?= $total_remaining > 0 ? 'balance-negative' : 'text-success' ?>">PKR <?=formatCurrency($total_remaining)?></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <?php endif; ?>
  </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>