<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

// Authentication required for both cashiers and admins
require_login();

$user = current_user();
$userId = (int)$user['id'];

// Parse input
$jsonInput = get_json_input();
$action = sanitize_text($_GET['action'] ?? $_POST['action'] ?? $jsonInput['action'] ?? '');

$db = get_db();

switch ($action) {
    case 'create':
        // Require CSRF token on bill generation
        require_csrf();

        $data = !empty($jsonInput) ? $jsonInput : $_POST;

        $items = $data['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            json_response(false, 'Cannot create a bill with an empty cart.', null, 422);
        }

        // 1. Process and recalculate items on the server
        $processedItems = [];
        $subtotal = 0.00;

        // Fetch products mapping for batch validation
        $productIds = [];
        foreach ($items as $it) {
            if (!empty($it['product_id']) && empty($it['is_custom'])) {
                $productIds[] = (int)$it['product_id'];
            }
        }

        $dbProducts = [];
        if (!empty($productIds)) {
            $inClause = implode(',', array_fill(0, count($productIds), '?'));
            $prodStmt = $db->prepare("SELECT id, name, price, is_active FROM products WHERE id IN ($inClause)");
            $prodStmt->execute($productIds);
            foreach ($prodStmt->fetchAll() as $p) {
                $dbProducts[(int)$p['id']] = $p;
            }
        }

        foreach ($items as $rawItem) {
            $isCustom = !empty($rawItem['is_custom']);
            $quantity = sanitize_float($rawItem['quantity'] ?? 1.0, 1.0, 0.01, 9999.0);
            
            if ($quantity <= 0) {
                json_response(false, 'Invalid item quantity.', null, 422);
            }

            if ($isCustom) {
                $itemName = sanitize_text($rawItem['item_name'] ?? $rawItem['name'] ?? 'Custom Item');
                $price = sanitize_float($rawItem['price'] ?? 0.0, 0.0, 0.01);
                $productId = null;

                if (empty($itemName)) {
                    json_response(false, 'Custom item must have a valid description.', null, 422);
                }
                if ($price <= 0) {
                    json_response(false, 'Custom item price must be greater than zero.', null, 422);
                }
            } else {
                $productId = sanitize_int($rawItem['product_id'] ?? 0);
                if (!isset($dbProducts[$productId])) {
                    json_response(false, "Product ID {$productId} not found in catalog.", null, 422);
                }
                // Always use true product catalog name and price from DB
                $itemName = (string)$dbProducts[$productId]['name'];
                $price = (float)$dbProducts[$productId]['price'];
            }

            $lineTotal = round($price * $quantity, 2);
            $subtotal += $lineTotal;

            $processedItems[] = [
                'product_id' => $productId,
                'item_name'  => $itemName,
                'price'      => $price,
                'quantity'   => $quantity,
                'line_total' => $lineTotal,
                'is_custom'  => $isCustom ? 1 : 0
            ];
        }

        $subtotal = round($subtotal, 2);

        // 2. Validate & recalculate discount
        $discountType = validate_enum($data['discount_type'] ?? '', ['none', 'flat', 'percent'], 'none');
        $discountValue = sanitize_float($data['discount_value'] ?? 0.0, 0.0, 0.0);
        $discountAmount = 0.00;

        if ($discountType === 'percent') {
            $discountValue = min(100.0, max(0.0, $discountValue));
            $discountAmount = round($subtotal * ($discountValue / 100.0), 2);
        } elseif ($discountType === 'flat') {
            $discountValue = min($subtotal, max(0.0, $discountValue));
            $discountAmount = round($discountValue, 2);
        } else {
            $discountType = 'none';
            $discountValue = 0.00;
            $discountAmount = 0.00;
        }

        $total = max(0.00, round($subtotal - $discountAmount, 2));

        // 3. Customer Information & Phone Validation
        $customerName = sanitize_text($data['customer_name'] ?? '');
        $customerPhone = sanitize_text($data['customer_phone'] ?? '');

        // 4. Advance & Payment Status Calculation
        $advanceInput = isset($data['advance_amount']) && $data['advance_amount'] !== '' ? sanitize_float($data['advance_amount']) : null;
        
        if ($advanceInput !== null && $advanceInput > $total) {
            json_response(false, 'Advance amount cannot be greater than the grand total (₹' . number_format($total, 2) . ').', null, 422);
        }

        $isAdvanceBill = ($advanceInput !== null && $advanceInput > 0 && $advanceInput < $total);

        // When advance amount is entered, customer name and contact are compulsory so the client can identify the customer
        if ($advanceInput !== null && $advanceInput > 0) {
            if (empty($customerName)) {
                json_response(false, 'Customer name is compulsory when an advance amount is entered.', null, 422);
            }
            if (empty($customerPhone)) {
                json_response(false, 'Customer contact number is compulsory when an advance amount is entered.', null, 422);
            }
        }

        if (!empty($customerPhone) && !validate_phone($customerPhone)) {
            json_response(false, 'Invalid customer phone number. Must be a valid mobile number.', null, 422);
        }

        if ($isAdvanceBill) {
            $status = 'pending';
            $advancePaid = $advanceInput;
            $balanceDue = round($total - $advanceInput, 2);
            $amountPayingNow = $advanceInput;
            $paymentType = 'advance';
            $completedAt = null;
        } else {
            // Full payment (either advance blank, or advance equal to total)
            $status = 'completed';
            $advancePaid = $total;
            $balanceDue = 0.00;
            $amountPayingNow = $total;
            $paymentType = 'full';
            $completedAt = date('Y-m-d H:i:s');
        }

        // 5. Payment Mode & Split Calculation
        $paymentMode = validate_enum($data['payment_mode'] ?? '', ['upi', 'cash', 'mixed'], 'cash');
        $upiAmount = 0.00;
        $cashAmount = 0.00;

        if ($paymentMode === 'mixed') {
            $upiAmount = sanitize_float($data['upi_amount'] ?? 0.0, 0.0, 0.0);
            $cashAmount = sanitize_float($data['cash_amount'] ?? 0.0, 0.0, 0.0);

            if (abs(($upiAmount + $cashAmount) - $amountPayingNow) > 0.01) {
                json_response(false, sprintf(
                    'Mixed payment mismatch: UPI (₹%.2f) + Cash (₹%.2f) must equal the amount payable now (₹%.2f).',
                    $upiAmount, $cashAmount, $amountPayingNow
                ), null, 422);
            }
        } elseif ($paymentMode === 'upi') {
            $upiAmount = $amountPayingNow;
            $cashAmount = 0.00;
        } else { // cash
            $cashAmount = $amountPayingNow;
            $upiAmount = 0.00;
        }

        // Idempotency Check: if client_uuid is supplied and already recorded, return existing bill
        $clientUuid = sanitize_text($data['client_uuid'] ?? '');
        if (!empty($clientUuid)) {
            try {
                $dupStmt = $db->prepare("SELECT id FROM bills WHERE client_uuid = :client_uuid LIMIT 1");
                $dupStmt->execute([':client_uuid' => $clientUuid]);
                $existingBillId = (int)$dupStmt->fetchColumn();

                if ($existingBillId > 0) {
                    $existStmt = $db->prepare("
                        SELECT b.*, u.name as cashier_name
                        FROM bills b
                        JOIN users u ON u.id = b.created_by
                        WHERE b.id = :id
                        LIMIT 1
                    ");
                    $existStmt->execute([':id' => $existingBillId]);
                    $existingBill = $existStmt->fetch(PDO::FETCH_ASSOC);

                    $existItemsStmt = $db->prepare("SELECT * FROM bill_items WHERE bill_id = :id ORDER BY id ASC");
                    $existItemsStmt->execute([':id' => $existingBillId]);
                    $existingBill['items'] = $existItemsStmt->fetchAll(PDO::FETCH_ASSOC);

                    $settings = get_all_settings();
                    json_response(true, 'Bill previously created (idempotent sync).', [
                        'bill'     => $existingBill,
                        'settings' => $settings
                    ], 200);
                }
            } catch (\Throwable $e) {
                // Ignore if client_uuid column is not present in existing table
            }
        }

        // 6. Execute Transaction
        $db->beginTransaction();

        try {
            // Sequential bill number generator using row lock
            $billNo = generate_bill_no($db);

            // Check if client_uuid column exists in bills table
            $hasClientUuidCol = false;
            try {
                $colCheck = $db->query("SHOW COLUMNS FROM bills LIKE 'client_uuid'");
                $hasClientUuidCol = ($colCheck && $colCheck->rowCount() > 0);
            } catch (\Throwable $e) {
                $hasClientUuidCol = false;
            }

            if ($hasClientUuidCol) {
                $billStmt = $db->prepare("
                    INSERT INTO bills (
                        bill_no, client_uuid, customer_name, customer_phone, subtotal, discount_type,
                        discount_value, discount_amount, total, advance_paid, balance_due,
                        status, payment_mode, upi_amount, cash_amount, created_by, created_at, completed_at
                    ) VALUES (
                        :bill_no, :client_uuid, :customer_name, :customer_phone, :subtotal, :discount_type,
                        :discount_value, :discount_amount, :total, :advance_paid, :balance_due,
                        :status, :payment_mode, :upi_amount, :cash_amount, :created_by, NOW(), :completed_at
                    )
                ");

                $billStmt->execute([
                    ':bill_no'         => $billNo,
                    ':client_uuid'     => !empty($clientUuid) ? $clientUuid : null,
                    ':customer_name'   => !empty($customerName) ? $customerName : null,
                    ':customer_phone'  => !empty($customerPhone) ? $customerPhone : null,
                    ':subtotal'        => $subtotal,
                    ':discount_type'   => $discountType,
                    ':discount_value'  => $discountValue,
                    ':discount_amount' => $discountAmount,
                    ':total'           => $total,
                    ':advance_paid'    => $advancePaid,
                    ':balance_due'     => $balanceDue,
                    ':status'          => $status,
                    ':payment_mode'    => $paymentMode,
                    ':upi_amount'      => $upiAmount,
                    ':cash_amount'     => $cashAmount,
                    ':created_by'      => $userId,
                    ':completed_at'    => $completedAt
                ]);
            } else {
                $billStmt = $db->prepare("
                    INSERT INTO bills (
                        bill_no, customer_name, customer_phone, subtotal, discount_type,
                        discount_value, discount_amount, total, advance_paid, balance_due,
                        status, payment_mode, upi_amount, cash_amount, created_by, created_at, completed_at
                    ) VALUES (
                        :bill_no, :customer_name, :customer_phone, :subtotal, :discount_type,
                        :discount_value, :discount_amount, :total, :advance_paid, :balance_due,
                        :status, :payment_mode, :upi_amount, :cash_amount, :created_by, NOW(), :completed_at
                    )
                ");

                $billStmt->execute([
                    ':bill_no'         => $billNo,
                    ':customer_name'   => !empty($customerName) ? $customerName : null,
                    ':customer_phone'  => !empty($customerPhone) ? $customerPhone : null,
                    ':subtotal'        => $subtotal,
                    ':discount_type'   => $discountType,
                    ':discount_value'  => $discountValue,
                    ':discount_amount' => $discountAmount,
                    ':total'           => $total,
                    ':advance_paid'    => $advancePaid,
                    ':balance_due'     => $balanceDue,
                    ':status'          => $status,
                    ':payment_mode'    => $paymentMode,
                    ':upi_amount'      => $upiAmount,
                    ':cash_amount'     => $cashAmount,
                    ':created_by'      => $userId,
                    ':completed_at'    => $completedAt
                ]);
            }

            $billId = (int)$db->lastInsertId();

            // Insert Bill Items
            $itemStmt = $db->prepare("
                INSERT INTO bill_items (bill_id, product_id, item_name, price, quantity, line_total, is_custom)
                VALUES (:bill_id, :product_id, :item_name, :price, :quantity, :line_total, :is_custom)
            ");

            foreach ($processedItems as $pItem) {
                $itemStmt->execute([
                    ':bill_id'    => $billId,
                    ':product_id' => $pItem['product_id'],
                    ':item_name'  => $pItem['item_name'],
                    ':price'      => $pItem['price'],
                    ':quantity'   => $pItem['quantity'],
                    ':line_total' => $pItem['line_total'],
                    ':is_custom'  => $pItem['is_custom']
                ]);
            }

            // Insert initial payment entry
            $paymentStmt = $db->prepare("
                INSERT INTO payments (bill_id, amount, upi_amount, cash_amount, type, created_at)
                VALUES (:bill_id, :amount, :upi_amount, :cash_amount, :type, NOW())
            ");

            $paymentStmt->execute([
                ':bill_id'     => $billId,
                ':amount'      => $amountPayingNow,
                ':upi_amount'  => $upiAmount,
                ':cash_amount' => $cashAmount,
                ':type'        => $paymentType
            ]);

            $db->commit();

            // 7. Fetch complete bill details with creator name for receipt
            $fetchStmt = $db->prepare("
                SELECT b.*, u.name as cashier_name
                FROM bills b
                JOIN users u ON u.id = b.created_by
                WHERE b.id = :id
            ");
            $fetchStmt->execute([':id' => $billId]);
            $billRecord = $fetchStmt->fetch();

            $billRecord['id'] = (int)$billRecord['id'];
            $billRecord['subtotal'] = (float)$billRecord['subtotal'];
            $billRecord['discount_value'] = (float)$billRecord['discount_value'];
            $billRecord['discount_amount'] = (float)$billRecord['discount_amount'];
            $billRecord['total'] = (float)$billRecord['total'];
            $billRecord['advance_paid'] = (float)$billRecord['advance_paid'];
            $billRecord['balance_due'] = (float)$billRecord['balance_due'];
            $billRecord['upi_amount'] = (float)$billRecord['upi_amount'];
            $billRecord['cash_amount'] = (float)$billRecord['cash_amount'];
            $billRecord['items'] = $processedItems;

            $settings = get_all_settings();

            json_response(true, 'Bill created successfully.', [
                'bill'     => $billRecord,
                'settings' => $settings
            ], 201);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            json_response(false, 'Transaction failed: ' . $e->getMessage(), null, 500);
        }
        break;

    case 'get':
        $id = sanitize_int($_GET['id'] ?? null);
        $billNo = sanitize_text($_GET['bill_no'] ?? '');

        if ($id <= 0 && empty($billNo)) {
            json_response(false, 'Bill ID or Bill Number is required.', null, 422);
        }

        try {
            $where = $id > 0 ? 'b.id = :id' : 'b.bill_no = :bill_no';
            $param = $id > 0 ? [':id' => $id] : [':bill_no' => $billNo];

            $stmt = $db->prepare("
                SELECT b.*, u.name as cashier_name
                FROM bills b
                JOIN users u ON u.id = b.created_by
                WHERE $where
                LIMIT 1
            ");
            $stmt->execute($param);
            $bill = $stmt->fetch();

            if (!$bill) {
                json_response(false, 'Bill not found.', null, 404);
            }

            // Fetch items
            $itemStmt = $db->prepare("SELECT * FROM bill_items WHERE bill_id = :id ORDER BY id ASC");
            $itemStmt->execute([':id' => $bill['id']]);
            $items = $itemStmt->fetchAll();

            foreach ($items as &$it) {
                $it['id'] = (int)$it['id'];
                $it['price'] = (float)$it['price'];
                $it['quantity'] = (float)$it['quantity'];
                $it['line_total'] = (float)$it['line_total'];
            }
            $bill['items'] = $items;

            // Fetch payments
            $payStmt = $db->prepare("SELECT * FROM payments WHERE bill_id = :id ORDER BY created_at ASC");
            $payStmt->execute([':id' => $bill['id']]);
            $payments = $payStmt->fetchAll();

            foreach ($payments as &$p) {
                $p['amount'] = (float)$p['amount'];
                $p['upi_amount'] = (float)$p['upi_amount'];
                $p['cash_amount'] = (float)$p['cash_amount'];
            }
            $bill['payments'] = $payments;

            $settings = get_all_settings();

            json_response(true, 'Bill details retrieved successfully.', [
                'bill'     => $bill,
                'settings' => $settings
            ]);
        } catch (\Throwable $e) {
            json_response(false, 'Failed to fetch bill: ' . $e->getMessage(), null, 500);
        }
        break;

    default:
        json_response(false, 'Invalid action.', null, 400);
}
