<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Salary Slip';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$id = (int)($_GET['id'] ?? 0);
$slip = $pdo->prepare("SELECT s.*, e.full_name, e.employee_type, e.phone, e.cnic, e.address, e.joining_date, e.salary, ba.account_name, ba.bank_name
    FROM employee_salaries s
    JOIN employees e ON e.id = s.employee_id
    LEFT JOIN bank_accounts ba ON ba.id = s.bank_account_id
    WHERE s.id = ?");
$slip->execute([$id]);
$slip = $slip->fetch();
if (!$slip) { redirect('salary.php', 'Salary slip not found', 'error'); }

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-print-none mb-3 d-flex justify-content-between align-items-center">
  <a href="salary.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-arrow-left"></i> Back to Salary Payments</a>
  <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
</div>

<div class="card shadow">
  <div class="card-body">
    <div class="row border-bottom pb-3 mb-3">
      <div class="col-6">
        <h4 class="font-weight-bold mb-0" style="color:#0f172a;">Mehboob Traders</h4>
        <small class="text-muted">Wholesale Business <br> GST No: --</small>
      </div>
      <div class="col-6 text-right">
        <h5 class="font-weight-bold text-warning">SALARY SLIP</h5>
        <div><strong>Slip #:</strong> <?=htmlspecialchars($slip['slip_no'])?></div>
        <div><strong>Date:</strong> <?=formatDate($slip['payment_date'])?></div>
      </div>
    </div>

    <div class="row mb-3">
      <div class="col-6">
        <h6 class="text-muted text-uppercase">Employee</h6>
        <div class="font-weight-bold h5 mb-1"><?=htmlspecialchars($slip['full_name'])?></div>
        <div><?=ucfirst(str_replace('_', ' ', $slip['employee_type']))?></div>
        <div><?=htmlspecialchars($slip['phone'] ?? '')?></div>
        <div><?=htmlspecialchars($slip['cnic'] ?? '')?></div>
        <?php if ($slip['address']): ?><div><?=htmlspecialchars($slip['address'])?></div><?php endif; ?>
      </div>
      <div class="col-6 text-right">
        <h6 class="text-muted text-uppercase">Period</h6>
        <div><strong>Salary Month:</strong> <?=date('F Y', strtotime($slip['salary_month'] . '-01'))?></div>
        <div><strong>Payment:</strong>
          <?php if ($slip['payment_method'] == 'bank'): ?>
            Bank (<?=htmlspecialchars($slip['account_name'] ?? '')?>)
          <?php else: ?>
            Cash
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-bordered">
        <thead>
          <tr><th>Description</th><th class="text-right">Amount (PKR)</th></tr>
        </thead>
        <tbody>
          <tr>
            <td>Salary for <?=date('F Y', strtotime($slip['salary_month'] . '-01'))?></td>
            <td class="text-right font-weight-bold"><?=formatCurrency($slip['amount'])?></td>
          </tr>
        </tbody>
        <tfoot>
          <tr><th class="text-right">Net Paid:</th><th class="text-right text-success">PKR <?=formatCurrency($slip['amount'])?></th></tr>
        </tfoot>
      </table>
    </div>

    <div class="row mt-4 pt-4">
      <div class="col-6">
        <div class="text-uppercase text-muted small">Employee Signature</div>
        <div style="border-top:1px solid #ccc; padding-top:6px; margin-top:60px;">______________________</div>
      </div>
      <div class="col-6 text-right">
        <div class="text-uppercase text-muted small">Authorized Signature</div>
        <div style="border-top:1px solid #ccc; padding-top:6px; margin-top:60px;">______________________</div>
      </div>
    </div>

    <?php if ($slip['notes']): ?>
    <div class="mt-3"><strong>Notes:</strong> <?=htmlspecialchars($slip['notes'])?></div>
    <?php endif; ?>
  </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>