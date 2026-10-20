<?php
declare(strict_types=1);

/**
 * ESC/POS Direct Socket Network Printing API
 * 
 * IMPORTANT ARCHITECTURAL NOTE:
 * WiFi / Network thermal socket printing requires the POS host server and the 
 * thermal printer to reside on the same Local Area Network (LAN) subnet, or have 
 * routable direct socket access to the printer's static IP and TCP port (typically 9100).
 * 
 * Accessible by: admin, cashier
 * Security: CSRF protected
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

// Accessible by both admin and cashier
require_role(['admin', 'cashier']);

// Require POST and CSRF validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed. Use POST.', null, 405);
}

require_csrf();

$db = Database::getConnection();
$input = get_json_input();
if (empty($input)) {
    $input = $_POST;
}

$billId = (int)($input['bill_id'] ?? 0);
$isTest = (bool)($input['test_print'] ?? false);

// Retrieve Network Printer settings
$printerIp = trim(get_setting('printer_ip', ''));
$printerPort = (int)get_setting('printer_port', '9100');
$paperWidth = get_setting('paper_width', '58');

if (empty($printerIp)) {
    json_response(false, 'Network printer IP is not configured. Please set the printer IP in Settings.', null, 400);
}

if (!filter_var($printerIp, FILTER_VALIDATE_IP)) {
    json_response(false, 'Configured printer IP is invalid: ' . $printerIp, null, 400);
}

if ($printerPort < 1 || $printerPort > 65535) {
    $printerPort = 9100;
}

// Line character widths: 58mm = 32 chars, 80mm = 48 chars
$lineWidth = ($paperWidth === '80') ? 48 : 32;

/**
 * Build ESC/POS formatted byte sequence
 */
function build_escpos_bytes(array $bill, array $settings, int $lineWidth): string
{
    // ESC/POS Commands
    $ESC = "\x1B";
    $GS  = "\x1D";

    $INIT          = $ESC . "@";          // Initialize printer
    $ALIGN_LEFT    = $ESC . "a\x00";      // Align Left
    $ALIGN_CENTER  = $ESC . "a\x01";      // Align Center
    $ALIGN_RIGHT   = $ESC . "a\x02";      // Align Right
    $BOLD_ON       = $ESC . "E\x01";      // Bold ON
    $BOLD_OFF      = $ESC . "E\x00";      // Bold OFF
    $DOUBLE_SIZE   = $GS  . "!\x11";      // Double Width + Height
    $NORMAL_SIZE   = $GS  . "!\x00";      // Normal Size
    $FEED_3        = $ESC . "d\x03";      // Feed 3 lines
    $FEED_4        = $ESC . "d\x04";      // Feed 4 lines
    $PARTIAL_CUT   = $GS  . "V\x41\x00";  // Partial cut with feed (GS V A 0)

    $sepLine = str_repeat('-', $lineWidth) . "\n";
    $doubleSep = str_repeat('=', $lineWidth) . "\n";

    $out = "";
    $out .= $INIT;

    // 1. Header: Store / Business Name (Double Size & Centered)
    $out .= $ALIGN_CENTER;
    $out .= $BOLD_ON . $DOUBLE_SIZE;
    $out .= strtoupper($settings['business_name'] ?? 'QUICKPOS MART') . "\n";
    $out .= $NORMAL_SIZE . $BOLD_OFF;

    // Address & Contact Info
    $address = $settings['business_address'] ?? $settings['address'] ?? '';
    if (!empty($address)) {
        $out .= wordwrap($address, $lineWidth, "\n", true) . "\n";
    }
    $phone = $settings['business_phone'] ?? $settings['phone'] ?? '';
    if (!empty($phone)) {
        $out .= "Ph: " . $phone . "\n";
    }
    $gstin = $settings['business_gstin'] ?? $settings['gstin'] ?? '';
    if (!empty($gstin)) {
        $out .= "GSTIN: " . $gstin . "\n";
    }

    $out .= $doubleSep;

    // 2. Receipt Metadata (Left aligned)
    $out .= $ALIGN_LEFT;
    $out .= "BILL NO: " . ($bill['bill_no'] ?? 'N/A') . "\n";
    $out .= "DATE   : " . ($bill['date_formatted'] ?? date('d M Y, h:i A')) . "\n";
    if (!empty($bill['cashier_name'])) {
        $out .= "CASHIER: " . $bill['cashier_name'] . "\n";
    }

    // Customer info (Only print if present)
    if (!empty($bill['customer_name']) && $bill['customer_name'] !== 'Walk-in') {
        $out .= "CUSTOMER: " . $bill['customer_name'] . "\n";
    }
    if (!empty($bill['customer_phone']) && $bill['customer_phone'] !== '-') {
        $out .= "PHONE   : " . $bill['customer_phone'] . "\n";
    }

    $out .= $sepLine;

    // 3. Items Column Headers
    if ($lineWidth === 48) {
        // 80mm format: Item (22) | Qty (5) | Price (9) | Total (12)
        $out .= $BOLD_ON;
        $out .= sprintf("%-22s %5s %9s %10s\n", "ITEM", "QTY", "PRICE", "TOTAL");
        $out .= $BOLD_OFF;
        $out .= $sepLine;

        foreach ($bill['items'] ?? [] as $item) {
            $name = $item['item_name'];
            $qty = (float)$item['quantity'];
            $qtyStr = ($qty == (int)$qty) ? (string)(int)$qty : (string)$qty;
            $priceStr = number_format((float)$item['price'], 2, '.', '');
            $totalStr = number_format((float)$item['line_total'], 2, '.', '');

            // Truncate or wrap item name if needed
            if (mb_strlen($name) > 22) {
                $nameLine = mb_substr($name, 0, 22);
            } else {
                $nameLine = $name;
            }

            $out .= sprintf("%-22s %5s %9s %10s\n", $nameLine, $qtyStr, $priceStr, $totalStr);
            if (mb_strlen($name) > 22) {
                $out .= "  " . mb_substr($name, 22, 24) . "\n";
            }
        }
    } else {
        // 58mm format: Item (14) | Qty (3) | Price (6) | Total (7)
        $out .= $BOLD_ON;
        $out .= sprintf("%-14s %3s %6s %7s\n", "ITEM", "QTY", "PRICE", "TOTAL");
        $out .= $BOLD_OFF;
        $out .= $sepLine;

        foreach ($bill['items'] ?? [] as $item) {
            $name = $item['item_name'];
            $qty = (float)$item['quantity'];
            $qtyStr = ($qty == (int)$qty) ? (string)(int)$qty : (string)$qty;
            $priceStr = number_format((float)$item['price'], 2, '.', '');
            $totalStr = number_format((float)$item['line_total'], 2, '.', '');

            if (mb_strlen($name) > 14) {
                $nameLine = mb_substr($name, 0, 14);
            } else {
                $nameLine = $name;
            }

            $out .= sprintf("%-14s %3s %6s %7s\n", $nameLine, $qtyStr, $priceStr, $totalStr);
            if (mb_strlen($name) > 14) {
                $out .= " " . mb_substr($name, 14, 16) . "\n";
            }
        }
    }

    $out .= $sepLine;

    // Helper for aligned label-value rows (e.g. Subtotal ....... Rs. 100.00)
    $formatRow = function (string $label, string $val) use ($lineWidth): string {
        $space = $lineWidth - mb_strlen($label) - mb_strlen($val);
        if ($space < 1) $space = 1;
        return $label . str_repeat(' ', $space) . $val . "\n";
    };

    // 4. Financial Totals (Replace ₹ with 'Rs.')
    $subtotal = number_format((float)($bill['subtotal'] ?? 0), 2);
    $discount = (float)($bill['discount_amount'] ?? 0);
    $total = number_format((float)($bill['total'] ?? 0), 2);
    $advancePaid = (float)($bill['advance_paid'] ?? 0);
    $balanceDue = (float)($bill['balance_due'] ?? 0);

    $out .= $formatRow("Subtotal", "Rs. " . $subtotal);

    // Only print discount if present
    if ($discount > 0) {
        $out .= $formatRow("Discount", "-Rs. " . number_format($discount, 2));
    }

    $out .= $sepLine;
    $out .= $BOLD_ON;
    $out .= $formatRow("GRAND TOTAL", "Rs. " . $total);
    $out .= $BOLD_OFF;
    $out .= $sepLine;

    // Payment Mode & Advance / Balance details
    $mode = strtoupper($bill['payment_mode'] ?? 'CASH');
    $out .= $formatRow("Payment Mode", $mode);

    if ($mode === 'MIXED') {
        $upi = number_format((float)($bill['upi_amount'] ?? 0), 2);
        $cash = number_format((float)($bill['cash_amount'] ?? 0), 2);
        $out .= $formatRow("  - UPI Amount", "Rs. " . $upi);
        $out .= $formatRow("  - Cash Amount", "Rs. " . $cash);
    }

    // Only print advance / balance if pending bill
    if ($advancePaid > 0 && $balanceDue > 0) {
        $out .= $formatRow("Advance Paid", "Rs. " . number_format($advancePaid, 2));
        $out .= $BOLD_ON;
        $out .= $formatRow("BALANCE DUE", "Rs. " . number_format($balanceDue, 2));
        $out .= $BOLD_OFF;
    }

    $out .= $doubleSep;

    // 5. Footer Message & Cut
    $out .= $ALIGN_CENTER;
    $footer = $settings['receipt_footer'] ?? $settings['footer_message'] ?? 'Thank you for your visit!';
    $out .= wordwrap($footer, $lineWidth, "\n", true) . "\n";
    $out .= "POWERED BY QUICKPOS PWA\n";

    $out .= $FEED_4;
    $out .= $PARTIAL_CUT;

    return $out;
}

// Handle Test Print
if ($isTest || $billId <= 0) {
    $testBill = [
        'bill_no'         => 'TEST-0001',
        'date_formatted'  => date('d M Y, h:i A'),
        'cashier_name'    => current_user()['name'] ?? 'Admin',
        'customer_name'   => 'Test Customer',
        'customer_phone'  => '9876543210',
        'subtotal'        => 150.00,
        'discount_amount' => 15.00,
        'total'           => 135.00,
        'payment_mode'    => 'CASH',
        'advance_paid'    => 0.00,
        'balance_due'     => 0.00,
        'items' => [
            ['item_name' => 'Sample Coffee Espresso', 'quantity' => 1, 'price' => 100.00, 'line_total' => 100.00],
            ['item_name' => 'Butter Croissant', 'quantity' => 1, 'price' => 50.00, 'line_total' => 50.00]
        ]
    ];

    $allSettings = get_all_settings();
    $escposData = build_escpos_bytes($testBill, $allSettings, $lineWidth);
} else {
    // Query Bill from Database
    $billStmt = $db->prepare("
        SELECT 
            b.*,
            u.name AS cashier_name
        FROM bills b
        LEFT JOIN users u ON b.created_by = u.id
        WHERE b.id = :id
        LIMIT 1
    ");
    $billStmt->execute([':id' => $billId]);
    $bill = $billStmt->fetch(PDO::FETCH_ASSOC);

    if (!$bill) {
        json_response(false, 'Bill not found.', null, 404);
    }

    // Query Items
    $itemsStmt = $db->prepare("
        SELECT item_name, price, quantity, line_total, is_custom
        FROM bill_items
        WHERE bill_id = :id
        ORDER BY id ASC
    ");
    $itemsStmt->execute([':id' => $billId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $bill['items'] = $items;
    $bill['date_formatted'] = date('d M Y, h:i A', strtotime($bill['completed_at'] ?? $bill['created_at']));

    $allSettings = get_all_settings();
    $escposData = build_escpos_bytes($bill, $allSettings, $lineWidth);
}

// Send Raw ESC/POS Bytes via TCP Socket (fsockopen)
$connectionTimeout = 3.5; // seconds
$errno = 0;
$errstr = '';

$socket = @fsockopen($printerIp, $printerPort, $errno, $errstr, $connectionTimeout);

if (!$socket) {
    $msg = "Unable to connect to printer at {$printerIp}:{$printerPort}. Error: {$errstr} (Code: {$errno}). " .
           "Please ensure the printer is turned on, has paper, and is connected to the same local WiFi/LAN network.";
    error_log("[Network Printer Error] " . $msg);
    json_response(false, $msg, null, 502);
}

// Set write timeout to 3 seconds
stream_set_timeout($socket, 3);

$bytesWritten = @fwrite($socket, $escposData);
@fflush($socket);
@fclose($socket);

if ($bytesWritten === false || $bytesWritten === 0) {
    json_response(false, "Connected to {$printerIp}:{$printerPort}, but failed to write data stream to printer.", null, 500);
}

json_response(true, "Receipt successfully dispatched to network printer ({$printerIp}:{$printerPort}).", [
    'printer_ip'    => $printerIp,
    'printer_port'  => $printerPort,
    'bytes_written' => $bytesWritten
]);
