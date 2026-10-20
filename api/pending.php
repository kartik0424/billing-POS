<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

// Authentication required for both cashiers and admins
require_login();

$jsonInput = get_json_input();
$action = sanitize_text($_GET['action'] ?? $_POST['action'] ?? $jsonInput['action'] ?? 'list');

$db = get_db();

switch ($action) {
    case 'list':
        try {
            $search = sanitize_text($_GET['search'] ?? $_GET['q'] ?? '');

            $where = ["b.status = 'pending'"];
            $params = [];

            if (!empty($search)) {
                $where[] = "(b.customer_name LIKE :s_name OR b.customer_phone LIKE :s_phone OR b.bill_no LIKE :s_bill)";
                $params[':s_name']  = '%' . $search . '%';
                $params[':s_phone'] = '%' . $search . '%';
                $params[':s_bill']  = '%' . $search . '%';
            }

            $sql = "
                SELECT 
                    b.id, 
                    b.bill_no, 
                    b.customer_name, 
                    b.customer_phone,
                    b.subtotal, 
                    b.discount_type,
                    b.discount_value,
                    b.discount_amount, 
                    b.total, 
                    b.advance_paid, 
                    b.balance_due,
                    b.payment_mode, 
                    b.upi_amount, 
                    b.cash_amount, 
                    b.status, 
                    b.created_at,
                    u.name AS cashier_name,
                    COALESCE(
                        GROUP_CONCAT(CONCAT(bi.item_name, ' × ', ROUND(bi.quantity)) SEPARATOR ', '),
                        ''
                    ) AS items_summary
                FROM bills b
                JOIN users u ON u.id = b.created_by
                LEFT JOIN bill_items bi ON bi.bill_id = b.id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY b.id
                ORDER BY b.id DESC
            ";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $bills = $stmt->fetchAll();

            // Cast numeric values appropriately
            foreach ($bills as &$bill) {
                $bill['id'] = (int)$bill['id'];
                $bill['subtotal'] = (float)$bill['subtotal'];
                $bill['discount_amount'] = (float)$bill['discount_amount'];
                $bill['total'] = (float)$bill['total'];
                $bill['advance_paid'] = (float)$bill['advance_paid'];
                $bill['balance_due'] = (float)$bill['balance_due'];
                $bill['upi_amount'] = (float)$bill['upi_amount'];
                $bill['cash_amount'] = (float)$bill['cash_amount'];
            }

            json_response(true, 'Pending bills retrieved successfully.', [
                'bills' => $bills,
                'count' => count($bills)
            ]);
        } catch (\Throwable $e) {
            json_response(false, 'Failed to fetch pending bills: ' . $e->getMessage(), null, 500);
        }
        break;

    case 'mark_paid':
        // Require CSRF token on balance payments
        require_csrf();

        $data = !empty($jsonInput) ? $jsonInput : $_POST;
        $billId = sanitize_int($data['bill_id'] ?? null);
        $paymentMode = validate_enum($data['payment_mode'] ?? '', ['upi', 'cash', 'mixed'], 'cash');

        if ($billId <= 0) {
            json_response(false, 'Invalid bill identifier.', null, 422);
        }

        // Start transaction with row-level lock
        $db->beginTransaction();

        try {
            // Lock the bill row to prevent concurrent race conditions or double payments
            $stmt = $db->prepare("SELECT * FROM bills WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $billId]);
            $bill = $stmt->fetch();

            if (!$bill) {
                $db->rollBack();
                json_response(false, 'Bill not found.', null, 404);
            }

            // Verify if already paid (prevent double submission)
            if ($bill['status'] === 'completed' || (float)$bill['balance_due'] <= 0.00) {
                $db->rollBack();
                json_response(false, 'This bill has already been fully paid and marked completed.', null, 409);
            }

            $balanceDue = round((float)$bill['balance_due'], 2);
            $upiAmount = 0.00;
            $cashAmount = 0.00;

            if ($paymentMode === 'mixed') {
                $upiAmount = sanitize_float($data['upi_amount'] ?? 0.0, 0.0, 0.0);
                $cashAmount = sanitize_float($data['cash_amount'] ?? 0.0, 0.0, 0.0);

                if (abs(($upiAmount + $cashAmount) - $balanceDue) > 0.01) {
                    $db->rollBack();
                    json_response(false, sprintf(
                        'Payment split mismatch: UPI (₹%.2f) + Cash (₹%.2f) must equal balance due of ₹%.2f.',
                        $upiAmount, $cashAmount, $balanceDue
                    ), null, 422);
                }
            } elseif ($paymentMode === 'upi') {
                $upiAmount = $balanceDue;
                $cashAmount = 0.00;
            } else { // cash
                $cashAmount = $balanceDue;
                $upiAmount = 0.00;
            }

            // 1. Insert 'balance' payment record
            $payStmt = $db->prepare("
                INSERT INTO payments (bill_id, amount, upi_amount, cash_amount, type, created_at)
                VALUES (:bill_id, :amount, :upi_amount, :cash_amount, 'balance', NOW())
            ");
            $payStmt->execute([
                ':bill_id'     => $billId,
                ':amount'      => $balanceDue,
                ':upi_amount'  => $upiAmount,
                ':cash_amount' => $cashAmount
            ]);

            // 2. Accumulate overall UPI and Cash payments for the bill
            $newUpiTotal = round((float)$bill['upi_amount'] + $upiAmount, 2);
            $newCashTotal = round((float)$bill['cash_amount'] + $cashAmount, 2);

            // Determine final overall payment mode (if advance and balance modes differ, it's mixed)
            if ($newUpiTotal > 0.00 && $newCashTotal > 0.00) {
                $finalMode = 'mixed';
            } elseif ($newUpiTotal > 0.00) {
                $finalMode = 'upi';
            } else {
                $finalMode = 'cash';
            }

            // 3. Update bill status to completed
            $updateStmt = $db->prepare("
                UPDATE bills 
                SET status = 'completed',
                    balance_due = 0.00,
                    completed_at = NOW(),
                    payment_mode = :final_mode,
                    upi_amount = :new_upi,
                    cash_amount = :new_cash
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':final_mode' => $finalMode,
                ':new_upi'    => $newUpiTotal,
                ':new_cash'   => $newCashTotal,
                ':id'         => $billId
            ]);

            $db->commit();

            // 4. Return updated full bill record for receipt preview/reprint
            $fetchStmt = $db->prepare("
                SELECT b.*, u.name as cashier_name
                FROM bills b
                JOIN users u ON u.id = b.created_by
                WHERE b.id = :id
            ");
            $fetchStmt->execute([':id' => $billId]);
            $updatedBill = $fetchStmt->fetch();

            // Fetch items
            $itemStmt = $db->prepare("SELECT * FROM bill_items WHERE bill_id = :id ORDER BY id ASC");
            $itemStmt->execute([':id' => $billId]);
            $updatedBill['items'] = $itemStmt->fetchAll();

            // Fetch payments
            $payFetchStmt = $db->prepare("SELECT * FROM payments WHERE bill_id = :id ORDER BY created_at ASC");
            $payFetchStmt->execute([':id' => $billId]);
            $updatedBill['payments'] = $payFetchStmt->fetchAll();

            $settings = get_all_settings();

            json_response(true, "Bill {$updatedBill['bill_no']} marked as fully paid.", [
                'bill'     => $updatedBill,
                'settings' => $settings
            ]);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            json_response(false, 'Failed to process balance payment: ' . $e->getMessage(), null, 500);
        }
        break;

    default:
        json_response(false, 'Invalid action specified.', null, 400);
}
