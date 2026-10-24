<?php
$page_title = 'Edit Sale Return';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$return_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($return_id <= 0) {
    redirect('sale_returns.php', 'Invalid return ID', 'error');
}

$stmt = $pdo->prepare("SELECT * FROM sale_returns WHERE id = ?");
$stmt->execute([$return_id]);
$return = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$return) {
    redirect('sale_returns.php', 'Sale return not found', 'error');
}

$target_inv_id = !empty($return['invoice_id']) ? (int)$return['invoice_id'] : (int)($return['sale_id'] ?? 0);
$customer_id = (int)$return['customer_id'];
$refund_type_old = $return['refund_type'] ?? 'Credit Note';
$total_amount_old = (float)$return['total_amount'];

$items_stmt = $pdo->prepare("SELECT sri.*, p.name AS product_name, p.code AS product_code FROM sale_return_items sri LEFT JOIN products p ON p.id = sri.product_id WHERE sri.return_id = ? ORDER BY sri.id ASC");
$items_stmt->execute([$return_id]);
$return_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// sold qty map
$sold_map = [];
if ($target_inv_id > 0) {
    $sm = $pdo->prepare('SELECT product_id, quantity, price FROM sale_items WHERE sale_id = ?');
    $sm->execute([$target_inv_id]);
    foreach ($sm->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (int)$row['product_id'];
        if (!isset($sold_map[$pid])) {
            $sold_map[$pid] = ['qty' => 0, 'price' => (float)$row['price']];
        }
        $sold_map[$pid]['qty'] += (int)$row['quantity'];
    }
}
// already returned by other returns
$other_ret_map = [];
$orm = $pdo->prepare("SELECT sri.product_id, COALESCE(SUM(sri.quantity),0) AS q FROM sale_return_items sri JOIN sale_returns sr ON sr.id = sri.return_id WHERE sr.status = 'Completed' AND sr.id <> ? AND (sr.invoice_id = ? OR sr.sale_id = ?) GROUP BY sri.product_id");
$orm->execute([$return_id, $target_inv_id, $target_inv_id]);
foreach ($orm->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $other_ret_map[(int)$row['product_id']] = (int)$row['q'];
}

$bank_accounts = $pdo->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_refund_type = trim($_POST['refund_type'] ?? $refund_type_old);
    $bank_account_id_new = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
    $reason = trim($_POST['reason'] ?? ($return['reason'] ?? ''));
    $return_date_new = trim($_POST['return_date'] ?? $return['return_date']);
    $items_post = $_POST['items'] ?? [];

    try {
        $pdo->beginTransaction();

        // 1) Reverse old effects
        // stock back only for Good items
        $rst = $pdo->prepare("SELECT product_id, quantity, `condition` FROM sale_return_items WHERE return_id = ?");
        $rst->execute([$return_id]);
        $old_items = $rst->fetchAll(PDO::FETCH_ASSOC);
        $stock_rev = $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?');
        foreach ($old_items as $oi) {
            $c = $oi['condition'] ?? 'Good / Resalable';
            if ($c === 'Good / Resalable' || $c === 'Good') {
                $stock_rev->execute([(int)$oi['quantity'], (int)$oi['product_id']]);
            }
        }

        // cash/bank reversal
        if ($refund_type_old === 'Cash Refund') {
            recordCashInflow($pdo, $return['return_date'], $total_amount_old, 'Reversal of Sale Return #' . $return['return_no'], 'sale_return_reversal', $return_id, (int)$_SESSION['user_id']);
        } elseif ($refund_type_old === 'Bank Refund' && !empty($return['bank_account_id'])) {
            $pdo->prepare('UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?')->execute([$total_amount_old, (int)$return['bank_account_id']]);
        }

        // 2) Validate new items
        $validated = [];
        $total_return_amount_new = 0;
        if (!is_array($items_post)) {
            throw new Exception('No items provided.');
        }
        foreach ($items_post as $ip) {
            $pid = (int)($ip['product_id'] ?? 0);
            $qty = (int)($ip['quantity'] ?? 0);
            if ($pid <= 0 || $qty <= 0) continue;
            if (!isset($sold_map[$pid])) {
                continue;
            }
            $max = $sold_map[$pid]['qty'] - ($other_ret_map[$pid] ?? 0);
            if ($qty > $max) {
                $qty = $max;
            }
            if ($qty <= 0) continue;
            $price = (float)($ip['unit_price'] ?? 0);
            if ($price <= 0) $price = $sold_map[$pid]['price'];
            $cond = trim($ip['condition'] ?? 'Good / Resalable');
            $line = round($qty * $price, 2);
            $total_return_amount_new += $line;
            $validated[] = [
                'product_id' => $pid,
                'quantity' => $qty,
                'price' => $price,
                'total' => $line,
                'condition' => $cond
            ];
        }
        if (!$validated) {
            throw new Exception('No valid return quantities entered.');
        }

        // 3) Update sale_returns
        $upd = $pdo->prepare("UPDATE sale_returns SET return_date = ?, refund_type = ?, total_amount = ?, reason = ?, bank_account_id = ?, updated_at = ? WHERE id = ?");
        $upd->execute([$return_date_new, $new_refund_type, $total_return_amount_new, $reason, $bank_account_id_new, date('Y-m-d'), $return_id]);

        // 4) Replace items
        $pdo->prepare('DELETE FROM sale_return_items WHERE return_id = ?')->execute([$return_id]);
        $item_ins = $pdo->prepare("INSERT INTO sale_return_items (return_id, product_id, quantity, unit_price, total_price, `condition`) VALUES (?, ?, ?, ?, ?, ?)");
        $stock_add = $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?');
        foreach ($validated as $it) {
            $item_ins->execute([$return_id, $it['product_id'], $it['quantity'], $it['price'], $it['total'], $it['condition']]);
            if ($it['condition'] === 'Good / Resalable' || $it['condition'] === 'Good') {
                $stock_add->execute([$it['quantity'], $it['product_id']]);
            }
        }

        // 5) Apply new effects
        if ($new_refund_type === 'Cash Refund') {
            recordCashOutflow($pdo, $return_date_new, $total_return_amount_new, 'Cash Refund for Sale Return #' . $return['return_no'] . ' (edited)', 'sale_return', $return_id, (int)$_SESSION['user_id']);
        } elseif ($new_refund_type === 'Bank Refund' && $bank_account_id_new) {
            $pdo->prepare('UPDATE bank_accounts SET current_balance = GREATEST(0, current_balance - ?) WHERE id = ?')->execute([$total_return_amount_new, $bank_account_id_new]);
        }

        $pdo->commit();
        updateCustomerBalance($pdo, $customer_id);
        redirect('sale_returns.php', 'Sale return updated successfully', 'success');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h6 class="mb-0"><i class="fas fa-edit"></i> Edit Sale Return - <?= htmlspecialchars($return['return_no']) ?></h6>
    <div>
      <a href="sale_returns.php" class="btn btn-sm btn-light"><i class="fas fa-arrow-left"></i> Back to Returns</a>
      <a href="print_return.php?id=<?= $return_id ?>" class="btn btn-sm btn-outline-danger" target="_blank"><i class="fas fa-print"></i> Print Voucher</a>
    </div>
  </div>
  <div class="card-body">
    <?php if (!empty($error)): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post">
      <div class="row">
        <div class="form-group col-md-3">
          <label>Return Date</label>
          <input type="date" name="return_date" class="form-control" value="<?= htmlspecialchars($return['return_date']) ?>" required>
        </div>
        <div class="form-group col-md-3">
          <label>Refund Type</label>
          <select name="refund_type" id="refund_type" class="form-control">
            <option value="Credit Note" <?= $refund_type_old === 'Credit Note' ? 'selected' : '' ?>>Credit Note</option>
            <option value="Cash Refund" <?= $refund_type_old === 'Cash Refund' ? 'selected' : '' ?>>Cash Refund</option>
            <option value="Bank Refund" <?= $refund_type_old === 'Bank Refund' ? 'selected' : '' ?>>Bank Refund</option>
            <option value="Deduct Balance" <?= $refund_type_old === 'Deduct Balance' ? 'selected' : '' ?>>Deduct Balance</option>
            <option value="Store Credit" <?= $refund_type_old === 'Store Credit' ? 'selected' : '' ?>>Store Credit</option>
            <option value="Adjust / Deduct Customer Balance" <?= $refund_type_old === 'Adjust / Deduct Customer Balance' ? 'selected' : '' ?>>Adjust / Deduct Customer Balance</option>
          </select>
        </div>
        <div class="form-group col-md-4 <?= $refund_type_old === 'Bank Refund' ? '' : 'd-none' ?>" id="bank_wrap">
          <label>Bank Account</label>
          <select name="bank_account_id" class="form-control">
            <option value="">Select Bank</option>
            <?php foreach ($bank_accounts as $ba): ?>
              <option value="<?= (int)$ba['id'] ?>" <?= ((int)($return['bank_account_id'] ?? 0) === (int)$ba['id']) ? 'selected' : '' ?>><?= htmlspecialchars($ba['account_name'] . ' - ' . $ba['bank_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group col-md-6">
          <label>Reason</label>
          <input type="text" name="reason" class="form-control" value="<?= htmlspecialchars($return['reason'] ?? '') ?>">
        </div>
      </div>

      <div class class="table-responsive">
        <table class="table table-bordered">
          <thead class="thead-light">
            <tr>
              <th>Product</th>
              <th style="width:120px">Sold Qty</th>
              <th style="width:140px">Already Returned (other)</th>
              <th style="width:150px">Return Qty</th>
              <th style="width:150px">Unit Price</th>
              <th style="width:150px">Condition</th>
              <th style="width:150px" class="text-right">Line Total</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($return_items as $ri): 
              $pid = (int)$ri['product_id'];
              $sold = (int)($sold_map[$pid]['qty'] ?? 0);
              $other_ret = (int)($other_ret_map[$pid] ?? 0);
              $max_ret = max(0, $sold - $other_ret);
            ?>
            <tr>
              <td>
                <input type="hidden" name="items[<?= $pid ?>][product_id]" value="<?= $pid ?>">
                <strong><?= htmlspecialchars($ri['product_name']) ?></strong>
                <?php if (!empty($ri['product_code'])): ?><div class="small text-muted"><?= htmlspecialchars($ri['product_code']) ?></div><?php endif; ?>
              </td>
              <td class="text-center"><?= $sold ?></td>
              <td class="text-center"><?= $other_ret ?></td>
              <td>
                <input type="number" name="items[<?= $pid ?>][quantity]" class="form-control form-control-sm text-center qty" data-pid="<?= $pid ?>" data-price="<?= (float)$ri['unit_price'] ?>" min="0" max="<?= $max_ret ?>" value="<?= (int)$ri['quantity'] ?>" required>
                <small class="text-muted">Max: <?= $max_ret ?></small>
              </td>
              <td>
                <input type="number" step="0.01" name="items[<?= $pid ?>][unit_price]" class="form-control form-control-sm price" data-pid="<?= $pid ?>" value="<?= (float)$ri['unit_price'] ?>">
              </td>
              <td>
                <select name="items[<?= $pid ?>][condition]" class="form-control form-control-sm">
                  <option value="Good / Resalable" <?= (($ri['condition'] ?? '') === 'Good / Resalable') ? 'selected' : '' ?>>Good / Resalable</option>
                  <option value="Good" <?= (($ri['condition'] ?? '') === 'Good') ? 'selected' : '' ?>>Good</option>
                  <option value="Damaged / Non-Resalable" <?= (($ri['condition'] ?? '') === 'Damaged / Non-Resalable') ? 'selected' : '' ?>>Damaged / Non-Resalable</option>
                </select>
              </td>
              <td class="text-right font-weight-bold line-total" data-pid="<?= $pid ?>">PKR 0.00</td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <th colspan="6" class="text-right">Total</th>
              <th class="text-right text-danger" id="grand_total">PKR <?= formatCurrency($total_amount_old) ?></th>
            </tr>
          </tfoot>
        </table>
      </div>
      <div class="text-right">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Return</button>
      </div>
    </form>
  </div>
</div>

<script>
function calc() {
  let total = 0;
  $('.qty').each(function(){
    const pid = $(this).data('pid');
    const qty = parseFloat($(this).val()) || 0;
    const price = parseFloat($('.price[data-pid="'+pid+'"]').val()) || 0;
    const line = qty * price;
    total += line;
    $('.line-total[data-pid="'+pid+'"]').text('PKR ' + line.toFixed(2));
  });
  $('#grand_total').text('PKR ' + total.toFixed(2));
}
$(document).on('input change', '.qty, .price', calc);
$('#refund_type').on('change', function(){
  $('#bank_wrap').toggleClass('d-none', $(this).val() !== 'Bank Refund');
});
$(function(){ calc(); });
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
