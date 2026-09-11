<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $sale = getById('sales', $id);
    if (!$sale) redirect('invoices.php', 'Sale not found', 'error');
    if (!isAdmin() && (int)$sale['created_by'] !== (int)$_SESSION['user_id']) {
        redirect('invoices.php', 'You can only delete your own invoices', 'error');
    }
    if ($sale['status'] === 'cancelled') redirect('invoices.php', 'Sale already cancelled', 'error');

    $pdo->beginTransaction();
    try {
        // 1. Reverse stock for each item
        $st = $pdo->prepare("SELECT product_id, quantity FROM sale_items WHERE sale_id = ?");
        $st->execute([$id]);
        $items = $st->fetchAll();
        foreach ($items as $it) {
            $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?")
                ->execute([(float)$it['quantity'], $it['product_id']]);
        }

        // 2. Reverse cash_book inflow
        $cb = $pdo->prepare("SELECT id, daily_id, transaction_date, amount FROM cash_book WHERE reference_type = 'sale' AND reference_id = ?");
        $cb->execute([$id]);
        $cash_rows = $cb->fetchAll();
        $min_date = null;
        foreach ($cash_rows as $cr) {
            $pdo->prepare("DELETE FROM cash_book WHERE id = ?")->execute([$cr['id']]);
            $day = $pdo->prepare("SELECT id, opening_balance, total_outflow FROM cash_book_daily WHERE date = ?");
            $day->execute([$cr['transaction_date']]);
            $d = $day->fetch();
            if ($d) {
                $new_inflow = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE transaction_date = ? AND transaction_type = 'inflow'");
                $new_inflow->execute([$cr['transaction_date']]);
                $new_inflow = (float)$new_inflow->fetchColumn();
                $outflow = (float)$d['total_outflow'];
                $open = (float)$d['opening_balance'];
                $pdo->prepare("UPDATE cash_book_daily SET total_inflow = ?, closing_balance = ? WHERE id = ?")
                    ->execute([$new_inflow, $open + $new_inflow - $outflow, $d['id']]);
            }
            if ($min_date === null || $cr['transaction_date'] < $min_date) $min_date = $cr['transaction_date'];
        }
        if ($min_date) recomputeCashDailyFrom($pdo, $min_date);

        // 3. Reverse bank transaction if bank payment
        $bt = $pdo->prepare("SELECT id, amount, bank_account_id FROM bank_transactions WHERE reference_type = 'sale' AND reference_id = ?");
        $bt->execute([$id]);
        $bank_rows = $bt->fetchAll();
        foreach ($bank_rows as $br) {
            $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")
                ->execute([(float)$br['amount'], $br['bank_account_id']]);
            $pdo->prepare("DELETE FROM bank_transactions WHERE id = ?")->execute([$br['id']]);
        }

        // 4. Delete sale_items + sale
        $pdo->prepare("DELETE FROM sale_items WHERE sale_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM sales WHERE id = ?")->execute([$id]);

        // 5. Recalculate customer balance
        if ($sale['customer_id']) updateCustomerBalance($pdo, $sale['customer_id']);

        $pdo->commit();
        logActivity($pdo, 'delete', 'sale', $id, 'Deleted sale ' . $sale['invoice_no'] . ' (total ' . $sale['total_amount'] . ')');
        redirect('invoices.php', 'Sale deleted successfully');
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('invoices.php', 'Error deleting sale: ' . $e->getMessage(), 'error');
    }
}
redirect('invoices.php');