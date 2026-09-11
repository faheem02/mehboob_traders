<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Cash Book';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

// Date filter
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$conds = [];
$params = [];
if ($from !== '') { $conds[] = "transaction_date >= ?"; $params[] = $from; }
if ($to !== '') { $conds[] = "transaction_date <= ?"; $params[] = $to; }
$where = $conds ? (" WHERE " . implode(' AND ', $conds)) : '';

$today = date('Y-m-d');
$monday = date('Y-m-d', strtotime('monday this week'));
$sunday = date('Y-m-d', strtotime('sunday this week'));
$month_first = date('Y-m-01');
$month_last = date('Y-m-t');

// All cash book entries
$stmt = $pdo->prepare("SELECT * FROM cash_book" . $where . " ORDER BY id DESC");
$stmt->execute($params);
$entries = $stmt->fetchAll();

// Totals (respect selected range)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE transaction_type = 'inflow'" . ($where ? ' AND ' . substr($where, 7) : ''));
$stmt->execute($params);
$total_in = $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE transaction_type = 'outflow'" . ($where ? ' AND ' . substr($where, 7) : ''));
$stmt->execute($params);
$total_out = $stmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tdate = $_POST['opening_date'] ?: date('Y-m-d');
    $amount = (float)($_POST['opening_amount'] ?? 0);
    if ($amount < 0) { redirect('index.php', 'Enter a valid opening balance amount', 'error'); }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM cash_book_daily WHERE date = ?");
        $stmt->execute([$tdate]);
        $daily = $stmt->fetch();

        if ($daily) {
            $daily_id = $daily['id'];
            $up = $pdo->prepare("UPDATE cash_book_daily SET opening_balance = ?, closing_balance = ? + total_inflow - total_outflow, updated_at = ? WHERE id = ?");
            $up->execute([$amount, $amount, date('Y-m-d'), $daily_id]);
        } else {
            $daily_id = insert('cash_book_daily', [
                'date' => $tdate,
                'opening_balance' => $amount,
                'total_inflow' => 0,
                'total_outflow' => 0,
                'closing_balance' => $amount,
                'status' => 'open',
                'created_by' => $_SESSION['user_id'],
                'created_at' => date('Y-m-d'),
            ]);
        }

        $stmt = $pdo->prepare("SELECT closing_balance FROM cash_book_daily WHERE date = ?");
        $stmt->execute([$tdate]);
        $prev_closing = (float)$stmt->fetchColumn();

        $rows = $pdo->prepare("SELECT * FROM cash_book_daily WHERE date > ? ORDER BY date ASC, id ASC");
        $rows->execute([$tdate]);
        foreach ($rows->fetchAll() as $r) {
            $closing = $prev_closing + (float)$r['total_inflow'] - (float)$r['total_outflow'];
            $up = $pdo->prepare("UPDATE cash_book_daily SET opening_balance = ?, closing_balance = ?, updated_at = ? WHERE id = ?");
            $up->execute([$prev_closing, $closing, date('Y-m-d'), $r['id']]);
            $prev_closing = $closing;
        }

        insert('cash_book', [
            'daily_id' => $daily_id,
            'transaction_date' => $tdate,
            'transaction_type' => 'opening_balance',
            'amount' => $amount,
            'description' => 'Opening balance (set manually)',
            'reference_type' => 'opening_balance',
            'created_by' => $_SESSION['user_id'],
            'created_at' => date('Y-m-d'),
        ]);

        logActivity($pdo, 'opening', 'cash', null, 'Set cash opening balance PKR ' . $amount . ' for ' . $tdate);
        $pdo->commit();
        redirect('index.php', 'Opening balance saved');
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
        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Inflow</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($total_in)?></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card border-left-danger shadow stat-card">
      <div class="card-body py-2">
        <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Total Outflow</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($total_out)?></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <?php $cash_in_hand = $pdo->query("SELECT closing_balance FROM cash_book_daily ORDER BY date DESC LIMIT 1")->fetchColumn(); if (!$cash_in_hand) $cash_in_hand = 0; ?>
    <div class="card border-left-primary shadow stat-card">
      <div class="card-body py-2">
        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Cash in Hand</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($cash_in_hand)?></div>
      </div>
    </div>
  </div>
</div>

<div class="row">
  <!-- Entries -->
  <div class="col-12 mb-3">
    <div class="card shadow">
      <div class="card-header"><h6><i class="fas fa-money-bill-wave"></i> Cash Book Entries</h6></div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-hover">
            <thead><tr><th>Date</th><th>Type</th><th>Description</th><th>Reference</th><th>Amount</th></tr></thead>
            <tbody>
              <?php foreach ($entries as $e): ?>
              <tr>
                <td><?=formatDate($e['transaction_date'])?></td>
                <td>
                  <?php
                    $type = $e['transaction_type'];
                    if ($type == 'inflow') echo '<span class="badge badge-success">Inflow</span>';
                    elseif ($type == 'outflow') echo '<span class="badge badge-danger">Outflow</span>';
                    else echo '<span class="badge badge-secondary">' . ucfirst(str_replace('_',' ',$type)) . '</span>';
                  ?>
                </td>
                <td><?=htmlspecialchars($e['description'])?></td>
                <td><span class="text-muted"><?=htmlspecialchars($e['reference_type'] ?? '-')?></span></td>
                <td class="<?= $e['transaction_type'] == 'inflow' ? 'text-success' : ($e['transaction_type'] == 'outflow' ? 'text-danger' : '') ?> font-weight-bold">
                  <?= $e['transaction_type'] == 'inflow' ? '+' : ($e['transaction_type'] == 'outflow' ? '-' : '') ?> PKR <?=formatCurrency($e['amount'])?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!count($entries)): ?><tr><td colspan="5" class="text-center text-muted py-3">No cash book entries yet</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="openingModal" tabindex="-1" role="dialog" aria-labelledby="openingModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title" id="openingModalLabel"><i class="fas fa-coins"></i> Cash Opening Balance</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label">Date *</label>
            <input type="date" name="opening_date" class="form-control datepicker" value="<?=date('Y-m-d')?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">Opening Balance (PKR) *</label>
            <input type="number" name="opening_amount" step="0.01" min="0" class="form-control" placeholder="0.00" required>
          </div>
          <?php $cur_opening = $pdo->query("SELECT opening_balance FROM cash_book_daily WHERE date = CURDATE()")->fetchColumn(); if ($cur_opening !== false): ?>
          <small class="text-muted">Current opening balance today: PKR <?=formatCurrency($cur_opening)?></small>
          <?php endif; ?>
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