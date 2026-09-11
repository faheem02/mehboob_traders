<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Bank Book';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

// Accounts with balances
$accounts = $pdo->query("SELECT * FROM bank_accounts ORDER BY id")->fetchAll();

// Date filter
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$conds = [];
$params = [];
if ($from !== '') { $conds[] = "bt.transaction_date >= ?"; $params[] = $from; }
if ($to !== '') { $conds[] = "bt.transaction_date <= ?"; $params[] = $to; }
$where = $conds ? (" WHERE " . implode(' AND ', $conds)) : '';

$today = date('Y-m-d');
$monday = date('Y-m-d', strtotime('monday this week'));
$sunday = date('Y-m-d', strtotime('sunday this week'));
$month_first = date('Y-m-01');
$month_last = date('Y-m-t');

// Transactions
$stmt = $pdo->prepare("SELECT bt.*, ba.account_name, ba.bank_name FROM bank_transactions bt LEFT JOIN bank_accounts ba ON bt.bank_account_id = ba.id" . $where . " ORDER BY bt.id DESC");
$stmt->execute($params);
$transactions = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM bank_transactions WHERE transaction_type = 'deposit' AND transaction_date >= ? AND transaction_date <= ?");
$stmt->execute([$from !== '' ? $from : '0000-01-01', $to !== '' ? $to : '9999-12-31']);
$total_deposits = $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM bank_transactions WHERE transaction_type = 'withdrawal' AND transaction_date >= ? AND transaction_date <= ?");
$stmt->execute([$from !== '' ? $from : '0000-01-01', $to !== '' ? $to : '9999-12-31']);
$total_withdrawals = $stmt->fetchColumn();
$total_bank = $pdo->query("SELECT COALESCE(SUM(current_balance),0) FROM bank_accounts")->fetchColumn();
$total_opening = $pdo->query("SELECT COALESCE(SUM(opening_balance),0) FROM bank_accounts")->fetchColumn();
$latest_opening_date = $pdo->query("SELECT MAX(opening_date) FROM bank_accounts WHERE opening_date IS NOT NULL")->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_bank_opening'])) {
    $pdo->beginTransaction();
    try {
        $input = $_POST['opening'] ?? [];
        $opening_date = $_POST['opening_date'] ?: null;
        foreach ($accounts as $a) {
            $val = array_key_exists($a['id'], $input) ? (float)$input[$a['id']] : (float)$a['opening_balance'];
            if ($val < 0) $val = 0;
            $q = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('deposit','transfer_in') THEN amount ELSE 0 END),0) - COALESCE(SUM(CASE WHEN transaction_type IN ('withdrawal','transfer_out') THEN amount ELSE 0 END),0) FROM bank_transactions WHERE bank_account_id = ?");
            $q->execute([$a['id']]);
            $net = (float)$q->fetchColumn();
            $up = $pdo->prepare("UPDATE bank_accounts SET opening_balance = ?, opening_date = ?, current_balance = ?, updated_at = ? WHERE id = ?");
            $up->execute([$val, $opening_date, $val + $net, date('Y-m-d'), $a['id']]);
        }
        logActivity($pdo, 'opening', 'bank', null, 'Updated bank opening balance');
        $pdo->commit();
        redirect('index.php', 'Bank opening balance updated');
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('index.php', 'Error: ' . $e->getMessage(), 'error');
    }
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="row mb-3 align-items-center">
  <div class="col-md-8">
    <form method="get" class="form-inline mb-1">
      <label class="mr-1 small text-muted">From</label>
      <input type="date" name="from" value="<?=htmlspecialchars($from)?>" class="form-control form-control-sm mr-2">
      <label class="mr-1 small text-muted">To</label>
      <input type="date" name="to" value="<?=htmlspecialchars($to)?>" class="form-control form-control-sm mr-2">
      <button type="submit" class="btn btn-sm btn-primary mr-1"><i class="fas fa-filter"></i> Filter</button>
      <a href="index.php" class="btn btn-sm btn-outline-secondary">All</a>
    </form>
    <div class="small">
      <a href="index.php?from=<?=$today?>&to=<?=$today?>" class="mr-2">Today</a>
      <a href="index.php?from=<?=$monday?>&to=<?=$sunday?>" class="mr-2">This Week</a>
      <a href="index.php?from=<?=$month_first?>&to=<?=$month_last?>" class="mr-2">This Month</a>
    </div>
  </div>
  <div class="col-md-4 text-md-right">
    <button type="button" class="btn btn-primary shadow-sm" data-toggle="modal" data-target="#openingModal">
      <i class="fas fa-coins"></i> Opening Balance
    </button>
  </div>
</div>

<div class="row mb-3">
  <div class="col-md-4">
    <div class="card border-left-success shadow stat-card">
      <div class="card-body py-2">
        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Deposits</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($total_deposits)?></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card border-left-danger shadow stat-card">
      <div class="card-body py-2">
        <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Total Withdrawals</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($total_withdrawals)?></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card border-left-info shadow stat-card">
      <div class="card-body py-2">
        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Bank Balance</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($total_bank)?></div>
      </div>
    </div>
  </div>
</div>

<div class="card shadow">
  <div class="card-header"><h6><i class="fas fa-university"></i> Bank Transactions</h6></div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered table-hover">
        <thead><tr><th>Date</th><th>Account</th><th>Type</th><th>Description</th><th>Reference</th><th>Amount</th></tr></thead>
        <tbody>
          <tr class="table-light">
            <td><?= $latest_opening_date ? formatDate($latest_opening_date) : '-' ?></td>
            <td><span class="text-muted">All Accounts</span></td>
            <td><span class="badge badge-secondary">Opening Balance</span></td>
            <td>Opening balance (total)</td>
            <td><span class="text-muted">opening_balance</span></td>
            <td class="font-weight-bold">PKR <?=formatCurrency($total_opening)?></td>
          </tr>
          <?php foreach ($transactions as $t): ?>
          <tr>
            <td><?=formatDate($t['transaction_date'])?></td>
            <td><?=htmlspecialchars($t['account_name'] ?? '-')?></td>
            <td>
              <?php
                $type = $t['transaction_type'];
                if (in_array($type, ['deposit','transfer_in'])) echo '<span class="badge badge-success">' . ucfirst(str_replace('_',' ',$type)) . '</span>';
                elseif (in_array($type, ['withdrawal','transfer_out'])) echo '<span class="badge badge-danger">' . ucfirst(str_replace('_',' ',$type)) . '</span>';
                else echo '<span class="badge badge-secondary">' . ucfirst($type) . '</span>';
              ?>
            </td>
            <td><?=htmlspecialchars($t['description'])?></td>
            <td><span class="text-muted"><?=htmlspecialchars($t['reference_type'] ?? '-')?></span></td>
            <td class="<?= in_array($t['transaction_type'], ['deposit','transfer_in']) ? 'text-success' : 'text-danger' ?> font-weight-bold">
              <?= in_array($t['transaction_type'], ['deposit','transfer_in']) ? '+' : '-' ?> PKR <?=formatCurrency($t['amount'])?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($transactions)): ?><tr><td colspan="6" class="text-center text-muted py-3">No bank transactions yet</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="openingModal" tabindex="-1" role="dialog" aria-labelledby="openingModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="save_bank_opening" value="1">
        <div class="modal-header">
          <h5 class="modal-title" id="openingModalLabel"><i class="fas fa-coins"></i> Bank Opening Balance</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label">Date *</label>
            <input type="date" name="opening_date" class="form-control datepicker" value="<?= $accounts ? (isset($accounts[0]['opening_date']) && $accounts[0]['opening_date'] ? $accounts[0]['opening_date'] : date('Y-m-d')) : date('Y-m-d') ?>" required>
          </div>
          <div class="table-responsive">
            <table class="table table-bordered">
              <thead><tr><th>Account</th><th style="width:240px;">Opening Balance (PKR)</th></tr></thead>
              <tbody>
                <?php foreach ($accounts as $a): ?>
                <tr>
                  <td>
                    <strong><?=htmlspecialchars($a['account_name'])?></strong><br>
                    <small class="text-muted"><?=htmlspecialchars($a['bank_name'])?> · <?=htmlspecialchars($a['account_no'])?></small>
                  </td>
                  <td><input type="number" step="0.01" min="0" class="form-control" name="opening[<?=$a['id']?>]" value="<?=(float)$a['opening_balance']?>"></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!count($accounts)): ?>
                <tr><td colspan="2" class="text-center text-muted py-3">No bank accounts yet.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <small class="text-muted">Current balance auto-recalculates = opening + deposits - withdrawals.</small>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>