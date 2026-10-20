<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

// Authentication required for both cashiers and admins
require_login();

$db = get_db();

// Parse pagination and filter parameters
$page = sanitize_int($_GET['page'] ?? 1, 1, 1);
$perPage = sanitize_int($_GET['per_page'] ?? 15, 15, 1, 100);
$search = sanitize_text($_GET['search'] ?? $_GET['q'] ?? '');
$fromDate = sanitize_text($_GET['from'] ?? '');
$toDate = sanitize_text($_GET['to'] ?? '');

try {
    // Base condition: completed bills only
    $where = ["b.status = 'completed'"];
    $params = [];

    // Search filter (bill_no, customer_name, customer_phone)
    if (!empty($search)) {
        $where[] = "(b.bill_no LIKE :s_bill OR b.customer_name LIKE :s_name OR b.customer_phone LIKE :s_phone)";
        $params[':s_bill']  = '%' . $search . '%';
        $params[':s_name']  = '%' . $search . '%';
        $params[':s_phone'] = '%' . $search . '%';
    }

    // Date range filter
    if (!empty($fromDate)) {
        $where[] = "DATE(COALESCE(b.completed_at, b.created_at)) >= :from_date";
        $params[':from_date'] = $fromDate;
    }
    if (!empty($toDate)) {
        $where[] = "DATE(COALESCE(b.completed_at, b.created_at)) <= :to_date";
        $params[':to_date'] = $toDate;
    }

    $whereSql = implode(' AND ', $where);

    // 1. Calculate overall summary aggregates for current filter
    $aggSql = "
        SELECT 
            COUNT(*) AS total_count,
            COALESCE(SUM(b.total), 0) AS total_amount,
            COALESCE(SUM(b.upi_amount), 0) AS total_upi,
            COALESCE(SUM(b.cash_amount), 0) AS total_cash
        FROM bills b
        WHERE $whereSql
    ";
    $aggStmt = $db->prepare($aggSql);
    $aggStmt->execute($params);
    $summary = $aggStmt->fetch();

    $totalCount = (int)($summary['total_count'] ?? 0);
    $totalAmount = (float)($summary['total_amount'] ?? 0.0);
    $totalUpi = (float)($summary['total_upi'] ?? 0.0);
    $totalCash = (float)($summary['total_cash'] ?? 0.0);

    $totalPages = $totalCount > 0 ? (int)ceil($totalCount / $perPage) : 1;
    $offset = ($page - 1) * $perPage;

    // 2. Fetch paginated bills page
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
            b.status,
            b.payment_mode,
            b.upi_amount,
            b.cash_amount,
            b.created_at,
            b.completed_at,
            u.name AS cashier_name
        FROM bills b
        JOIN users u ON u.id = b.created_by
        WHERE $whereSql
        ORDER BY COALESCE(b.completed_at, b.created_at) DESC, b.id DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $bills = $stmt->fetchAll();

    // 3. Batch fetch items and payment rows for each bill
    if (!empty($bills)) {
        $billIds = array_column($bills, 'id');
        $inPlaceholders = implode(',', array_fill(0, count($billIds), '?'));

        // Fetch Bill Items
        $itemsStmt = $db->prepare("
            SELECT bill_id, product_id, item_name, price, quantity, line_total, is_custom
            FROM bill_items
            WHERE bill_id IN ($inPlaceholders)
            ORDER BY id ASC
        ");
        $itemsStmt->execute($billIds);
        $allItems = $itemsStmt->fetchAll();

        $itemsByBill = [];
        foreach ($allItems as $item) {
            $bId = (int)$item['bill_id'];
            if (!isset($itemsByBill[$bId])) {
                $itemsByBill[$bId] = [];
            }
            $itemsByBill[$bId][] = [
                'product_id' => $item['product_id'] !== null ? (int)$item['product_id'] : null,
                'item_name'  => (string)$item['item_name'],
                'price'      => (float)$item['price'],
                'quantity'   => (float)$item['quantity'],
                'line_total' => (float)$item['line_total'],
                'is_custom'  => (int)$item['is_custom']
            ];
        }

        // Fetch Payments Records
        $paymentsStmt = $db->prepare("
            SELECT bill_id, amount, upi_amount, cash_amount, type, created_at
            FROM payments
            WHERE bill_id IN ($inPlaceholders)
            ORDER BY created_at ASC
        ");
        $paymentsStmt->execute($billIds);
        $allPayments = $paymentsStmt->fetchAll();

        $paymentsByBill = [];
        foreach ($allPayments as $p) {
            $bId = (int)$p['bill_id'];
            if (!isset($paymentsByBill[$bId])) {
                $paymentsByBill[$bId] = [];
            }
            $paymentsByBill[$bId][] = [
                'amount'      => (float)$p['amount'],
                'upi_amount'  => (float)$p['upi_amount'],
                'cash_amount' => (float)$p['cash_amount'],
                'type'        => (string)$p['type'],
                'created_at'  => (string)$p['created_at']
            ];
        }

        // Assemble objects
        foreach ($bills as &$bill) {
            $bId = (int)$bill['id'];
            $bill['id'] = $bId;
            $bill['subtotal'] = (float)$bill['subtotal'];
            $bill['discount_value'] = (float)$bill['discount_value'];
            $bill['discount_amount'] = (float)$bill['discount_amount'];
            $bill['total'] = (float)$bill['total'];
            $bill['advance_paid'] = (float)$bill['advance_paid'];
            $bill['balance_due'] = (float)$bill['balance_due'];
            $bill['upi_amount'] = (float)$bill['upi_amount'];
            $bill['cash_amount'] = (float)$bill['cash_amount'];

            $bill['items'] = $itemsByBill[$bId] ?? [];
            $bill['payments'] = $paymentsByBill[$bId] ?? [];
        }
    }

    $settings = get_all_settings();

    json_response(true, 'Sales history retrieved successfully.', [
        'bills' => $bills,
        'summary' => [
            'total_count'  => $totalCount,
            'total_amount' => $totalAmount,
            'total_upi'    => $totalUpi,
            'total_cash'   => $totalCash
        ],
        'pagination' => [
            'page'        => $page,
            'per_page'    => $perPage,
            'total_count' => $totalCount,
            'total_pages' => $totalPages
        ],
        'settings' => $settings
    ]);
} catch (\Throwable $e) {
    json_response(false, 'Failed to fetch sales history: ' . $e->getMessage(), null, 500);
}
