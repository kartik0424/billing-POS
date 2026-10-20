<?php
declare(strict_types=1);

/**
 * Audit & Reports API (Admin Only)
 * Handles sales summaries, chart time-series, pending customer reports, top products, and Excel export dataset.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

// Strict Server-Side Role Enforcement: Admin Only
require_role('admin');

$db = Database::getConnection();
$action = sanitize_text($_GET['action'] ?? 'summary');

/**
 * REVENUE FORMULA DEFINITION & ACCOUNTING RULES:
 * ----------------------------------------------------------------------------
 * 1. Total Sales (Recognized Revenue for Bills in the Selected Range):
 *    Formula:
 *      SUM(CASE 
 *          WHEN status = 'completed' THEN total 
 *          WHEN status = 'pending' THEN advance_paid 
 *          ELSE 0 
 *      END)
 *    Explanation:
 *      For completed bills created in the period, the full bill total is recognized as sales.
 *      For pending bills created in the period, only the advance amount received so far is 
 *      counted as realized revenue, leaving the balance_due as an unpaid receivable.
 *
 * 2. Cash Collected (Actual Cash Inflow in the Selected Range):
 *    Formula: SUM(cash_amount) from `payments` table WHERE created_at BETWEEN :from AND :to.
 *    Captures cash received across full payments, advance deposits, and balance settlements.
 *
 * 3. UPI Collected (Actual Digital Inflow in the Selected Range):
 *    Formula: SUM(upi_amount) from `payments` table WHERE created_at BETWEEN :from AND :to.
 *    Captures UPI received across full payments, advance deposits, and balance settlements.
 *
 * 4. Total Collected (Total Actual Inflow):
 *    Formula: Cash Collected + UPI Collected.
 *
 * 5. Outstanding / Pending Balance:
 *    - Range Balance: SUM(balance_due) on pending bills created within the selected range.
 *    - All-Time Balance: SUM(balance_due) across ALL pending bills in the system.
 * ----------------------------------------------------------------------------
 */

/**
 * Resolve date range filter into [fromDateTime, toDateTime, rangeKey, startDate, endDate, label]
 */
function resolve_date_filter(): array
{
    $range = sanitize_text($_GET['range'] ?? 'daily');
    $today = date('Y-m-d');

    switch ($range) {
        case 'weekly':
            // Rolling last 7 days including today
            $startDate = date('Y-m-d', strtotime('-6 days'));
            $endDate = $today;
            $label = 'Last 7 Days (' . date('d M', strtotime($startDate)) . ' - ' . date('d M Y', strtotime($endDate)) . ')';
            $isHourly = false;
            break;

        case 'monthly':
            // 1st of current month through today
            $startDate = date('Y-m-01');
            $endDate = $today;
            $label = 'This Month (' . date('M Y') . ')';
            $isHourly = false;
            break;

        case 'custom':
            $rawFrom = sanitize_text($_GET['from'] ?? $today);
            $rawTo = sanitize_text($_GET['to'] ?? $today);

            // Validate YYYY-MM-DD
            $startDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawFrom) ? $rawFrom : $today;
            $endDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawTo) ? $rawTo : $today;

            if ($startDate > $endDate) {
                $temp = $startDate;
                $startDate = $endDate;
                $endDate = $temp;
            }

            $label = date('d M Y', strtotime($startDate)) . ' - ' . date('d M Y', strtotime($endDate));
            $isHourly = ($startDate === $endDate);
            break;

        case 'daily':
        default:
            $range = 'daily';
            $startDate = $today;
            $endDate = $today;
            $label = 'Today (' . date('d M Y') . ')';
            $isHourly = true;
            break;
    }

    return [
        'range'       => $range,
        'start_date'  => $startDate,
        'end_date'    => $endDate,
        'from_sql'    => $startDate . ' 00:00:00',
        'to_sql'      => $endDate . ' 23:59:59',
        'label'       => $label,
        'is_hourly'   => $isHourly
    ];
}

$dateFilter = resolve_date_filter();
$fromSql = $dateFilter['from_sql'];
$toSql = $dateFilter['to_sql'];

// ============================================================================
// Action: summary
// ============================================================================
if ($action === 'summary') {
    // 1. Bills Aggregate Metrics
    $billsStmt = $db->prepare("
        SELECT 
            COUNT(*) AS total_bills,
            COALESCE(SUM(subtotal), 0) AS total_subtotal,
            COALESCE(SUM(discount_amount), 0) AS total_discount,
            COALESCE(SUM(total), 0) AS total_bill_amount,
            COALESCE(SUM(CASE 
                WHEN status = 'completed' THEN total 
                WHEN status = 'pending' THEN advance_paid 
                ELSE 0 
            END), 0) AS total_sales,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) AS completed_bills_count,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_bills_count,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN balance_due ELSE 0 END), 0) AS range_pending_balance
        FROM bills
        WHERE created_at BETWEEN :from AND :to
    ");
    $billsStmt->execute([':from' => $fromSql, ':to' => $toSql]);
    $billsMetrics = $billsStmt->fetch(PDO::FETCH_ASSOC);

    // 2. Payments Inflow Metrics (Actual cash & UPI collected in the period)
    $paymentsStmt = $db->prepare("
        SELECT 
            COALESCE(SUM(amount), 0) AS total_collected,
            COALESCE(SUM(cash_amount), 0) AS cash_collected,
            COALESCE(SUM(upi_amount), 0) AS upi_collected,
            COUNT(*) AS payments_count
        FROM payments
        WHERE created_at BETWEEN :from AND :to
    ");
    $paymentsStmt->execute([':from' => $fromSql, ':to' => $toSql]);
    $paymentsMetrics = $paymentsStmt->fetch(PDO::FETCH_ASSOC);

    // 3. Overall All-Time Pending Balance
    $overallPendingStmt = $db->query("
        SELECT 
            COALESCE(SUM(balance_due), 0) AS overall_pending_balance,
            COUNT(*) AS overall_pending_count
        FROM bills
        WHERE status = 'pending' AND balance_due > 0
    ");
    $overallPendingMetrics = $overallPendingStmt->fetch(PDO::FETCH_ASSOC);

    // 4. Time Series for Charting
    $chartData = [
        'labels'     => [],
        'sales'      => [],
        'cash'       => [],
        'upi'        => [],
        'bills'      => [],
        'interval'   => $dateFilter['is_hourly'] ? 'hourly' : 'daily'
    ];

    if ($dateFilter['is_hourly']) {
        // Hourly breakdown (00:00 to 23:00)
        $hourlyBillsStmt = $db->prepare("
            SELECT 
                HOUR(created_at) AS hr,
                COUNT(*) AS bill_count,
                COALESCE(SUM(CASE 
                    WHEN status = 'completed' THEN total 
                    WHEN status = 'pending' THEN advance_paid 
                    ELSE 0 
                END), 0) AS sales
            FROM bills
            WHERE created_at BETWEEN :from AND :to
            GROUP BY HOUR(created_at)
        ");
        $hourlyBillsStmt->execute([':from' => $fromSql, ':to' => $toSql]);
        $billsByHour = [];
        while ($row = $hourlyBillsStmt->fetch(PDO::FETCH_ASSOC)) {
            $billsByHour[(int)$row['hr']] = $row;
        }

        $hourlyPayStmt = $db->prepare("
            SELECT 
                HOUR(created_at) AS hr,
                COALESCE(SUM(amount), 0) AS collected,
                COALESCE(SUM(cash_amount), 0) AS cash,
                COALESCE(SUM(upi_amount), 0) AS upi
            FROM payments
            WHERE created_at BETWEEN :from AND :to
            GROUP BY HOUR(created_at)
        ");
        $hourlyPayStmt->execute([':from' => $fromSql, ':to' => $toSql]);
        $payByHour = [];
        while ($row = $hourlyPayStmt->fetch(PDO::FETCH_ASSOC)) {
            $payByHour[(int)$row['hr']] = $row;
        }

        for ($h = 0; $h < 24; $h++) {
            $chartData['labels'][] = sprintf('%02d:00', $h);
            $chartData['sales'][]  = round((float)($billsByHour[$h]['sales'] ?? 0), 2);
            $chartData['cash'][]   = round((float)($payByHour[$h]['cash'] ?? 0), 2);
            $chartData['upi'][]    = round((float)($payByHour[$h]['upi'] ?? 0), 2);
            $chartData['bills'][]  = (int)($billsByHour[$h]['bill_count'] ?? 0);
        }
    } else {
        // Per-Day breakdown
        $dailyBillsStmt = $db->prepare("
            SELECT 
                DATE(created_at) AS dt,
                COUNT(*) AS bill_count,
                COALESCE(SUM(CASE 
                    WHEN status = 'completed' THEN total 
                    WHEN status = 'pending' THEN advance_paid 
                    ELSE 0 
                END), 0) AS sales
            FROM bills
            WHERE created_at BETWEEN :from AND :to
            GROUP BY DATE(created_at)
        ");
        $dailyBillsStmt->execute([':from' => $fromSql, ':to' => $toSql]);
        $billsByDay = [];
        while ($row = $dailyBillsStmt->fetch(PDO::FETCH_ASSOC)) {
            $billsByDay[$row['dt']] = $row;
        }

        $dailyPayStmt = $db->prepare("
            SELECT 
                DATE(created_at) AS dt,
                COALESCE(SUM(amount), 0) AS collected,
                COALESCE(SUM(cash_amount), 0) AS cash,
                COALESCE(SUM(upi_amount), 0) AS upi
            FROM payments
            WHERE created_at BETWEEN :from AND :to
            GROUP BY DATE(created_at)
        ");
        $dailyPayStmt->execute([':from' => $fromSql, ':to' => $toSql]);
        $payByDay = [];
        while ($row = $dailyPayStmt->fetch(PDO::FETCH_ASSOC)) {
            $payByDay[$row['dt']] = $row;
        }

        $current = new DateTime($dateFilter['start_date']);
        $endDt = new DateTime($dateFilter['end_date']);
        $endDt->modify('+1 day');
        $period = new DatePeriod($current, new DateInterval('P1D'), $endDt);

        foreach ($period as $dtObj) {
            $dStr = $dtObj->format('Y-m-d');
            $chartData['labels'][] = $dtObj->format('d M');
            $chartData['sales'][]  = round((float)($billsByDay[$dStr]['sales'] ?? 0), 2);
            $chartData['cash'][]   = round((float)($payByDay[$dStr]['cash'] ?? 0), 2);
            $chartData['upi'][]    = round((float)($payByDay[$dStr]['upi'] ?? 0), 2);
            $chartData['bills'][]  = (int)($billsByDay[$dStr]['bill_count'] ?? 0);
        }
    }

    $summary = [
        'filter' => [
            'range'      => $dateFilter['range'],
            'start_date' => $dateFilter['start_date'],
            'end_date'   => $dateFilter['end_date'],
            'label'      => $dateFilter['label']
        ],
        'total_sales'             => round((float)($billsMetrics['total_sales'] ?? 0), 2),
        'total_bills'             => (int)($billsMetrics['total_bills'] ?? 0),
        'completed_bills'         => (int)($billsMetrics['completed_bills_count'] ?? 0),
        'pending_bills'           => (int)($billsMetrics['pending_bills_count'] ?? 0),
        'total_subtotal'          => round((float)($billsMetrics['total_subtotal'] ?? 0), 2),
        'total_discount'          => round((float)($billsMetrics['total_discount'] ?? 0), 2),
        'total_bill_amount'       => round((float)($billsMetrics['total_bill_amount'] ?? 0), 2),
        'total_collected'         => round((float)($paymentsMetrics['total_collected'] ?? 0), 2),
        'cash_collected'          => round((float)($paymentsMetrics['cash_collected'] ?? 0), 2),
        'upi_collected'           => round((float)($paymentsMetrics['upi_collected'] ?? 0), 2),
        'payments_count'          => (int)($paymentsMetrics['payments_count'] ?? 0),
        'range_pending_balance'   => round((float)($billsMetrics['range_pending_balance'] ?? 0), 2),
        'overall_pending_balance' => round((float)($overallPendingMetrics['overall_pending_balance'] ?? 0), 2),
        'overall_pending_count'   => (int)($overallPendingMetrics['overall_pending_count'] ?? 0),
        'chart'                   => $chartData
    ];

    json_response(true, 'Summary metrics retrieved successfully.', $summary);
}

// ============================================================================
// Action: pending (Unpaid / Pending Customers)
// ============================================================================
if ($action === 'pending') {
    $stmt = $db->query("
        SELECT 
            b.id,
            b.bill_no,
            COALESCE(b.customer_name, 'Walk-in') AS customer_name,
            COALESCE(b.customer_phone, '-') AS customer_phone,
            b.total,
            b.advance_paid,
            b.balance_due,
            b.created_at,
            u.name AS cashier_name
        FROM bills b
        LEFT JOIN users u ON b.created_by = u.id
        WHERE b.status = 'pending' AND b.balance_due > 0
        ORDER BY b.created_at DESC
    ");
    $pendingList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted = array_map(function ($row) {
        return [
            'id'             => (int)$row['id'],
            'bill_no'        => $row['bill_no'],
            'customer_name'  => $row['customer_name'],
            'customer_phone' => $row['customer_phone'],
            'total'          => round((float)$row['total'], 2),
            'advance_paid'   => round((float)$row['advance_paid'], 2),
            'balance_due'    => round((float)$row['balance_due'], 2),
            'created_at'     => $row['created_at'],
            'date_formatted' => date('d M Y, h:i A', strtotime($row['created_at'])),
            'cashier_name'   => $row['cashier_name'] ?? 'System'
        ];
    }, $pendingList);

    json_response(true, 'Pending customers retrieved successfully.', [
        'count' => count($formatted),
        'total_balance_due' => round(array_sum(array_column($formatted, 'balance_due')), 2),
        'customers' => $formatted
    ]);
}

// ============================================================================
// Action: top_products (Top 10 by Quantity and Revenue in Range)
// ============================================================================
if ($action === 'top_products') {
    $stmt = $db->prepare("
        SELECT 
            bi.item_name,
            COALESCE(p.category, 'Custom') AS category,
            bi.is_custom,
            COALESCE(SUM(bi.quantity), 0) AS total_qty,
            COALESCE(SUM(bi.line_total), 0) AS total_revenue,
            COUNT(DISTINCT bi.bill_id) AS orders_count
        FROM bill_items bi
        INNER JOIN bills b ON bi.bill_id = b.id
        LEFT JOIN products p ON bi.product_id = p.id
        WHERE b.created_at BETWEEN :from AND :to
        GROUP BY bi.item_name, p.category, bi.is_custom
        ORDER BY total_revenue DESC, total_qty DESC
        LIMIT 10
    ");
    $stmt->execute([':from' => $fromSql, ':to' => $toSql]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted = array_map(function ($p, $idx) {
        return [
            'rank'          => $idx + 1,
            'item_name'     => $p['item_name'],
            'category'      => $p['category'],
            'is_custom'     => (bool)$p['is_custom'],
            'total_qty'     => (float)$p['total_qty'],
            'total_revenue' => round((float)$p['total_revenue'], 2),
            'orders_count'  => (int)$p['orders_count']
        ];
    }, $products, array_keys($products));

    json_response(true, 'Top products retrieved successfully.', [
        'filter'   => $dateFilter['label'],
        'products' => $formatted
    ]);
}

// ============================================================================
// Action: export_data (Comprehensive dataset for SheetJS Excel export)
// ============================================================================
if ($action === 'export_data') {
    // 1. Fetch Summary Metrics
    $billsStmt = $db->prepare("
        SELECT 
            COUNT(*) AS total_bills,
            COALESCE(SUM(subtotal), 0) AS total_subtotal,
            COALESCE(SUM(discount_amount), 0) AS total_discount,
            COALESCE(SUM(total), 0) AS total_bill_amount,
            COALESCE(SUM(CASE 
                WHEN status = 'completed' THEN total 
                WHEN status = 'pending' THEN advance_paid 
                ELSE 0 
            END), 0) AS total_sales,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) AS completed_bills_count,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_bills_count,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN balance_due ELSE 0 END), 0) AS range_pending_balance
        FROM bills
        WHERE created_at BETWEEN :from AND :to
    ");
    $billsStmt->execute([':from' => $fromSql, ':to' => $toSql]);
    $summaryMetrics = $billsStmt->fetch(PDO::FETCH_ASSOC);

    $payStmt = $db->prepare("
        SELECT 
            COALESCE(SUM(amount), 0) AS total_collected,
            COALESCE(SUM(cash_amount), 0) AS cash_collected,
            COALESCE(SUM(upi_amount), 0) AS upi_collected
        FROM payments
        WHERE created_at BETWEEN :from AND :to
    ");
    $payStmt->execute([':from' => $fromSql, ':to' => $toSql]);
    $payMetrics = $payStmt->fetch(PDO::FETCH_ASSOC);

    $allPendingStmt = $db->query("
        SELECT COALESCE(SUM(balance_due), 0) AS overall_pending_balance
        FROM bills
        WHERE status = 'pending' AND balance_due > 0
    ");
    $allPendingVal = (float)$allPendingStmt->fetchColumn();

    // 2. Fetch All Bills in Date Range
    $billsListStmt = $db->prepare("
        SELECT 
            b.id,
            b.bill_no,
            b.created_at,
            b.completed_at,
            COALESCE(b.customer_name, 'Walk-in') AS customer_name,
            COALESCE(b.customer_phone, '') AS customer_phone,
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
            u.name AS cashier_name
        FROM bills b
        LEFT JOIN users u ON b.created_by = u.id
        WHERE b.created_at BETWEEN :from AND :to
        ORDER BY b.created_at DESC
    ");
    $billsListStmt->execute([':from' => $fromSql, ':to' => $toSql]);
    $billsRows = $billsListStmt->fetchAll(PDO::FETCH_ASSOC);

    // Collect all bill IDs
    $billIds = array_column($billsRows, 'id');
    $itemsMap = [];
    $paymentsMap = [];

    if (!empty($billIds)) {
        // Fetch Items for these bills
        $inPlaceholders = implode(',', array_fill(0, count($billIds), '?'));
        $itemsStmt = $db->prepare("
            SELECT bill_id, item_name, price, quantity, line_total, is_custom
            FROM bill_items
            WHERE bill_id IN ($inPlaceholders)
            ORDER BY id ASC
        ");
        $itemsStmt->execute($billIds);
        while ($item = $itemsStmt->fetch(PDO::FETCH_ASSOC)) {
            $itemsMap[(int)$item['bill_id']][] = $item;
        }

        // Fetch Payments for these bills
        $paymentsStmt = $db->prepare("
            SELECT bill_id, amount, upi_amount, cash_amount, type, created_at
            FROM payments
            WHERE bill_id IN ($inPlaceholders)
            ORDER BY created_at ASC
        ");
        $paymentsStmt->execute($billIds);
        while ($p = $paymentsStmt->fetch(PDO::FETCH_ASSOC)) {
            $paymentsMap[(int)$p['bill_id']][] = $p;
        }
    }

    $exportBills = [];
    foreach ($billsRows as $b) {
        $bId = (int)$b['id'];
        $bItems = $itemsMap[$bId] ?? [];
        $bPayments = $paymentsMap[$bId] ?? [];

        // Build a readable items summary string e.g. "Espresso (x2), Croissant (x1)"
        $itemsSummaryArr = [];
        foreach ($bItems as $it) {
            $qty = (float)$it['quantity'];
            $qtyStr = ($qty == (int)$qty) ? (string)(int)$qty : (string)$qty;
            $itemsSummaryArr[] = $it['item_name'] . ' (x' . $qtyStr . ')';
        }
        $itemsSummaryStr = implode(', ', $itemsSummaryArr);

        $exportBills[] = [
            'bill_no'         => $b['bill_no'],
            'created_at'      => $b['created_at'],
            'completed_at'    => $b['completed_at'] ?? '',
            'customer_name'   => $b['customer_name'],
            'customer_phone'  => $b['customer_phone'],
            'items_summary'   => $itemsSummaryStr,
            'items_count'     => count($bItems),
            'subtotal'        => round((float)$b['subtotal'], 2),
            'discount_type'   => $b['discount_type'],
            'discount_value'  => round((float)$b['discount_value'], 2),
            'discount_amount' => round((float)$b['discount_amount'], 2),
            'total'           => round((float)$b['total'], 2),
            'advance_paid'    => round((float)$b['advance_paid'], 2),
            'balance_due'     => round((float)$b['balance_due'], 2),
            'status'          => $b['status'],
            'payment_mode'    => strtoupper($b['payment_mode']),
            'upi_amount'      => round((float)$b['upi_amount'], 2),
            'cash_amount'     => round((float)$b['cash_amount'], 2),
            'cashier'         => $b['cashier_name'] ?? 'System',
            'payments'        => $bPayments
        ];
    }

    // 3. Fetch Pending Customers for Sheet 3
    $pendingStmt = $db->query("
        SELECT 
            b.bill_no,
            b.created_at,
            COALESCE(b.customer_name, 'Walk-in') AS customer_name,
            COALESCE(b.customer_phone, '-') AS customer_phone,
            b.total,
            b.advance_paid,
            b.balance_due,
            u.name AS cashier_name
        FROM bills b
        LEFT JOIN users u ON b.created_by = u.id
        WHERE b.status = 'pending' AND b.balance_due > 0
        ORDER BY b.created_at DESC
    ");
    $exportPending = array_map(function ($row) {
        return [
            'bill_no'        => $row['bill_no'],
            'created_at'     => $row['created_at'],
            'customer_name'  => $row['customer_name'],
            'customer_phone' => $row['customer_phone'],
            'total'          => round((float)$row['total'], 2),
            'advance_paid'   => round((float)$row['advance_paid'], 2),
            'balance_due'    => round((float)$row['balance_due'], 2),
            'cashier'        => $row['cashier_name'] ?? 'System'
        ];
    }, $pendingStmt->fetchAll(PDO::FETCH_ASSOC));

    $exportData = [
        'business_name'   => get_setting('business_name', 'QuickPOS Mart'),
        'filter' => [
            'range'      => $dateFilter['range'],
            'start_date' => $dateFilter['start_date'],
            'end_date'   => $dateFilter['end_date'],
            'label'      => $dateFilter['label']
        ],
        'summary' => [
            'total_sales'             => round((float)($summaryMetrics['total_sales'] ?? 0), 2),
            'total_collected'         => round((float)($payMetrics['total_collected'] ?? 0), 2),
            'cash_collected'          => round((float)($payMetrics['cash_collected'] ?? 0), 2),
            'upi_collected'           => round((float)($payMetrics['upi_collected'] ?? 0), 2),
            'total_bills'             => (int)($summaryMetrics['total_bills'] ?? 0),
            'completed_bills'         => (int)($summaryMetrics['completed_bills_count'] ?? 0),
            'pending_bills'           => (int)($summaryMetrics['pending_bills_count'] ?? 0),
            'total_subtotal'          => round((float)($summaryMetrics['total_subtotal'] ?? 0), 2),
            'total_discount'          => round((float)($summaryMetrics['total_discount'] ?? 0), 2),
            'total_bill_amount'       => round((float)($summaryMetrics['total_bill_amount'] ?? 0), 2),
            'range_pending_balance'   => round((float)($summaryMetrics['range_pending_balance'] ?? 0), 2),
            'overall_pending_balance' => round($allPendingVal, 2)
        ],
        'bills'             => $exportBills,
        'pending_customers' => $exportPending
    ];

    json_response(true, 'Export dataset generated successfully.', $exportData);
}

// Invalid action
json_response(false, "Unknown action '{$action}'. Valid actions: summary, pending, top_products, export_data.", null, 400);
