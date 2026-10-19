<?php
/**
 * Mehboob Traders - Printable Sales Return Voucher / Credit Note
 */
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    die("Error: Invalid Return ID.");
}

// Fetch Return Record
$stmt = $pdo->prepare("SELECT * FROM sale_returns WHERE id = ?");
$stmt->execute([$id]);
$return = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$return) {
    die("Error: Sales Return record not found.");
}

// Fetch original invoice details
$target_inv_id = !empty($return['sale_id']) ? intval($return['sale_id']) : intval($return['invoice_id'] ?? 0);
$orig_invoice = null;

if ($target_inv_id > 0) {
    try {
        $stmt_s = $pdo->prepare("
            SELECT s.invoice_no, c.full_name AS customer_name, s.sale_date AS invoice_date, c.area AS route_name 
            FROM sales s 
            LEFT JOIN customers c ON c.id = s.customer_id 
            WHERE s.id = ?
        ");
        $stmt_s->execute([$target_inv_id]);
        $orig_invoice = $stmt_s->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Fallback customer details
$customer_display_name = $orig_invoice['customer_name'] ?? '';
$customer_route = $orig_invoice['route_name'] ?? '';

if (empty($customer_display_name) && !empty($return['customer_id'])) {
    try {
        $c_stmt = $pdo->prepare("SELECT full_name, phone, area FROM customers WHERE id = ?");
        $c_stmt->execute([$return['customer_id']]);
        $c_row = $c_stmt->fetch(PDO::FETCH_ASSOC);
        if ($c_row) {
            $customer_display_name = $c_row['full_name'];
            if (empty($customer_route)) $customer_route = $c_row['area'] ?? '';
        }
    } catch (Exception $e) {}
}

if (empty($customer_display_name)) {
    $customer_display_name = 'Counter Customer';
}
if (empty($customer_route)) {
    $customer_route = 'Local Area';
}

// Fetch return items
$stmt_items = $pdo->prepare("
    SELECT sri.*, 
           COALESCE(NULLIF(p.name, ''), 'Product') as product_name, 
           p.code as product_code,
           p.boxes_per_carton
    FROM sale_return_items sri 
    LEFT JOIN products p ON p.id = sri.product_id 
    WHERE sri.return_id = ?
    ORDER BY sri.id ASC
");
$stmt_items->execute([$id]);
$items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

// Company Details
$company = [
    'name' => 'MEHBOOB TRADERS',
    'tagline' => 'Wholesale Distribution & Supply Chain',
    'phone' => '0300-0000000',
    'address' => 'Wholesale Market, Lahore, Pakistan'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sale Return Voucher #<?= htmlspecialchars($return['return_no']) ?> - <?= htmlspecialchars($company['name']) ?></title>
    
    <!-- Bootstrap 4.6 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <!-- FontAwesome 5.15 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    
    <style>
        body {
            background-color: #f1f5f9;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: #1e293b;
            font-size: 13px;
        }
        .voucher-sheet {
            background: #ffffff;
            max-width: 860px;
            margin: 20px auto;
            padding: 30px 40px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            border: 1px solid #cbd5e1;
        }
        .table-voucher {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            margin-bottom: 20px;
        }
        .table-voucher th {
            background-color: #fef2f2;
            color: #991b1b;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 9px 12px;
            border-top: 2px solid #f87171;
            border-bottom: 2px solid #f87171;
        }
        .table-voucher td {
            padding: 9px 12px;
            border-bottom: 1px solid #fee2e2;
            font-size: 12px;
            vertical-align: middle;
        }
        .info-card {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 12px 14px;
        }
        .signature-box {
            border-top: 1px dashed #94a3b8;
            padding-top: 6px;
            font-size: 11px;
            text-align: center;
            color: #475569;
            font-weight: 600;
            margin-top: 25px;
        }
        .action-bar {
            max-width: 860px;
            margin: 20px auto 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        @media print {
            body {
                background: #ffffff;
                color: #000000;
                margin: 0;
            }
            .no-print {
                display: none !important;
            }
            .voucher-sheet {
                box-shadow: none;
                margin: 0;
                max-width: 100%;
                border: 1px solid #000;
                padding: 15px;
            }
            .table-voucher th {
                background-color: #fee2e2 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            @page {
                size: A4 portrait;
                margin: 10mm;
            }
        }
    </style>
</head>
<body>

    <!-- Action Toolbar (Hidden during print) -->
    <div class="action-bar no-print">
        <a href="sale_return.php" class="btn btn-outline-secondary btn-sm bg-white shadow-sm font-weight-bold">
            <i class="fas fa-arrow-left mr-1"></i> Back to Sales Returns
        </a>
        <button onclick="window.print()" class="btn btn-danger btn-sm px-4 font-weight-bold shadow-sm">
            <i class="fas fa-print mr-1"></i> Print Return Voucher
        </button>
    </div>

    <!-- Voucher Sheet -->
    <div class="voucher-sheet">
        <!-- Header -->
        <div class="row align-items-center border-bottom pb-3 mb-3">
            <div class="col-8">
                <div class="d-flex align-items-center">
                    <div style="width: 44px; height: 44px; border-radius: 8px; background: #dc2626; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 20px;" class="mr-3">
                        <i class="fas fa-undo"></i>
                    </div>
                    <div>
                        <h4 class="font-weight-bold mb-0 text-dark text-uppercase"><?= htmlspecialchars($company['name']) ?></h4>
                        <div class="text-muted small">Customer Sales Return Voucher &amp; Credit Note</div>
                    </div>
                </div>
            </div>
            <div class="col-4 text-right">
                <span class="badge badge-danger px-3 py-2 font-weight-bold" style="font-size: 0.95rem; font-family: monospace;">
                    <?= htmlspecialchars($return['return_no']) ?>
                </span>
                <div class="text-muted small mt-1">Return Date: <strong><?= formatDate($return['return_date']) ?></strong></div>
            </div>
        </div>

        <!-- Customer & Original Invoice Info -->
        <div class="row mb-3">
            <div class="col-6">
                <div class="info-card h-100">
                    <div class="text-muted text-uppercase font-weight-bold small" style="font-size: 10px;">Returned By (Customer)</div>
                    <div class="font-weight-bold text-dark mt-1" style="font-size: 1.05rem;"><?= htmlspecialchars($customer_display_name) ?></div>
                    <div class="text-secondary small mt-1">Area / Route: <strong><?= htmlspecialchars($customer_route) ?></strong></div>
                </div>
            </div>
            <div class="col-6">
                <div class="info-card h-100">
                    <div class="text-muted text-uppercase font-weight-bold small" style="font-size: 10px;">Return Settlement Details</div>
                    <div class="small mt-1">Against Invoice: <strong class="text-primary font-weight-bold"><?= $target_inv_id > 0 ? htmlspecialchars($orig_invoice['invoice_no'] ?? ('INV-' . str_pad($target_inv_id, 4, '0', STR_PAD_LEFT))) : '<span class="text-secondary">Direct Return</span>' ?></strong></div>
                    <div class="small">Refund Type: <strong class="text-danger font-weight-bold"><?= htmlspecialchars($return['refund_type']) ?></strong></div>
                    <div class="small">Reason: <strong><?= htmlspecialchars($return['reason'] ?: 'Customer Return') ?></strong></div>
                </div>
            </div>
        </div>

        <!-- Returned Items Table -->
        <table class="table-voucher">
            <thead>
                <tr>
                    <th style="width: 35px;" class="text-center">#</th>
                    <th>Product Description</th>
                    <th style="width: 100px;" class="text-center">Return Qty</th>
                    <th style="width: 120px;" class="text-right">Rate</th>
                    <th style="width: 140px;" class="text-center">Item Condition</th>
                    <th style="width: 140px;" class="text-right">Credit Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total_return_units = 0;
                if (!empty($items)):
                    foreach ($items as $idx => $it): 
                        $q = intval($it['quantity']);
                        $total_return_units += $q;
                        $rate = floatval($it['unit_price']);
                        $line_tot = floatval($it['total_price']);
                        $cond = !empty($it['condition']) ? $it['condition'] : 'Good / Resalable';
                        $is_good = (stripos($cond, 'Good') !== false || stripos($cond, 'Resalable') !== false);
                ?>
                    <tr>
                        <td class="text-center text-muted font-weight-bold"><?= $idx + 1 ?></td>
                        <td>
                            <strong class="text-dark"><?= htmlspecialchars($it['product_name']) ?></strong>
                            <?php if (!empty($it['product_code'])): ?>
                                <span class="badge badge-light border text-secondary font-weight-bold ml-1" style="font-size: 10px; font-family: monospace;"><?= htmlspecialchars($it['product_code']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center font-weight-bold text-danger" style="font-size: 0.95rem;"><?= $q ?> Boxes</td>
                        <td class="text-right font-weight-bold" style="font-family: monospace;">PKR <?= formatCurrency($rate) ?></td>
                        <td class="text-center">
                            <span class="badge <?= $is_good ? 'badge-success' : 'badge-danger' ?> px-2 py-1">
                                <i class="fas <?= $is_good ? 'fa-check' : 'fa-exclamation-triangle' ?> mr-1"></i><?= htmlspecialchars($cond) ?>
                            </span>
                        </td>
                        <td class="text-right font-weight-bold text-dark" style="font-family: monospace;">PKR <?= formatCurrency($line_tot) ?></td>
                    </tr>
                <?php 
                    endforeach;
                else: 
                ?>
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted font-italic">
                            <i class="fas fa-exclamation-circle text-warning mr-1"></i> No individual items recorded for this return voucher.
                        </td>
                    </tr>
                <?php endif; ?>

                <tr style="background-color: #fef2f2; font-weight: bold; border-top: 2px solid #f87171;">
                    <td colspan="2" class="text-right text-dark">Total Return Credit:</td>
                    <td class="text-center text-danger" style="font-size: 0.95rem;"><?= $total_return_units ?> Boxes</td>
                    <td colspan="2"></td>
                    <td class="text-right font-weight-bold text-danger" style="font-size: 1rem; font-family: monospace;">PKR <?= formatCurrency((float)$return['total_amount']) ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Signatures and Verification -->
        <div class="row pt-4 mt-2 text-center align-items-end">
            <div class="col-4">
                <div class="signature-box">
                    <div style="height: 40px;"></div>
                    Customer Signature
                </div>
            </div>
            <div class="col-4">
                <div class="signature-box">
                    <div style="height: 40px;"></div>
                    Store Receiver &amp; QC
                </div>
            </div>
            <div class="col-4">
                <div class="signature-box">
                    <div style="height: 40px;"></div>
                    Authorized Stamp / Signature
                </div>
            </div>
        </div>

        <div class="text-center text-muted border-top mt-4 pt-2 small" style="font-size: 11px;">
            Inventory Restock: Returned products in good condition have been verified and returned to warehouse inventory.
        </div>
    </div>

</body>
</html>
