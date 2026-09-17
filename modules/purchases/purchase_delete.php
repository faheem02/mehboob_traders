<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $purchase = getById('purchases', $id);
    if (!$purchase) redirect('index.php', 'Purchase not found', 'error');
    if ($purchase['status'] === 'cancelled') redirect('index.php', 'Purchase already cancelled', 'error');

    $pdo->beginTransaction();
    try {
        // 1. Reverse stock for each item
        $st = $pdo->prepare("SELECT product_id, quantity FROM purchase_items WHERE purchase_id = ?");
        $st->execute([$id]);
        $items = $st->fetchAll();
        foreach ($items as $it) {
            $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?")
                ->execute([(int)$it['quantity'], $it['product_id']]);
        }

        // 2. Reverse cash_book outflow
        $cb = $pdo->prepare("SELECT id, daily_id, transaction_date, amount FROM cash_book WHERE reference_type = 'purchase' AND reference_id = ?");
        $cb->execute([$id]);
        $cash_rows = $cb->fetchAll();
        foreach ($cash_rows as $cr) {
            $pdo->prepare("DELETE FROM cash_book WHERE id = ?")->execute([$cr['id']]);
            // Recompute daily totals
            $day = $pdo->prepare("SELECT id, total_outflow FROM cash_book_daily WHERE date = ?");
            $day->execute([$cr['transaction_date']]);
            $d = $day->fetch();
            if ($d) {
                $new_outflow = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE transaction_date = ? AND transaction_type = 'outflow'");
                $new_outflow->execute([$cr['transaction_date']]);
                $new_outflow = (float)$new_outflow->fetchColumn();
                $inflow = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE transaction_date = ? AND transaction_type = 'inflow'");
                $inflow->execute([$cr['transaction_date']]);
                $inflow = (float)$inflow->fetchColumn();
                $open = (float)$d['opening_balance'];
                $pdo->prepare("UPDATE cash_book_daily SET total_outflow = ?, total_inflow = ?, closing_balance = ? WHERE id = ?")
                    ->execute([$new_outflow, $inflow, $open + $inflow - $new_outflow, $d['id']]);
            }
        }

        // 3. Reverse bank transaction if bank payment
        if ($purchase['payment_method'] === 'bank' && $purchase['bank_account_id']) {
            $bt = $pdo->prepare("SELECT id, amount FROM bank_transactions WHERE reference_type = 'purchase' AND reference_id = ?");
            $bt->execute([$id]);
            $bank_rows = $bt->fetchAll();
            foreach ($bank_rows as $br) {
                $pdo->prepare("DELETE FROM bank_transactions WHERE id = ?")->execute([$br['id']]);
                $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")
                    ->execute([(float)$br['amount'], $purchase['bank_account_id']]);
            }
        }

        // 4. Delete purchase_items + purchase
        $pdo->prepare("DELETE FROM purchase_items WHERE purchase_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM purchases WHERE id = ?")->execute([$id]);

        // 5. Recalculate supplier balance (payments linked to this purchase are
        //    auto-unlinked via FK ON DELETE SET NULL, then re-allocated)
        if ($purchase['supplier_id']) {
            syncSupplierPurchasePayments($pdo, $purchase['supplier_id']);
            updateSupplierBalance($pdo, $purchase['supplier_id']);
        }

        $pdo->commit();
        logActivity($pdo, 'delete', 'purchase', $id, 'Deleted purchase ' . $purchase['invoice_no'] . ' (total ' . $purchase['total_amount'] . ')');
        redirect('index.php', 'Purchase deleted successfully');
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('index.php', 'Error deleting purchase: ' . $e->getMessage(), 'error');
    }
}
redirect('index.php');