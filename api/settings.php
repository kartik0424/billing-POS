<?php
declare(strict_types=1);

/**
 * Settings Management API (Admin Only)
 * Handles retrieving and updating store configuration, billing preferences, and thermal printer setup.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

// Strict Server-Side Role Enforcement: Admin Only
require_role('admin');

$db = Database::getConnection();
$action = sanitize_text($_GET['action'] ?? 'get');

// ============================================================================
// Action: get (Fetch all settings)
// ============================================================================
if ($action === 'get') {
    $settings = get_all_settings();

    $businessAddress = $settings['business_address'] ?? $settings['address'] ?? 'Shop 4, Market Complex, MG Road, Pune';
    $businessPhone   = $settings['business_phone'] ?? $settings['phone'] ?? '9876543210';
    $businessGstin   = $settings['business_gstin'] ?? $settings['gstin'] ?? '27AAAAA0000A1Z5';
    $receiptFooter   = $settings['receipt_footer'] ?? $settings['footer_message'] ?? 'Thank you for your visit! Please visit again.';

    // Ensure all standard keys exist with defaults
    $defaultSettings = [
        'business_name'    => 'QuickPOS Mart',
        'business_address' => $businessAddress,
        'address'          => $businessAddress,
        'business_phone'   => $businessPhone,
        'phone'            => $businessPhone,
        'business_gstin'   => $businessGstin,
        'gstin'            => $businessGstin,
        'receipt_footer'   => $receiptFooter,
        'footer_message'   => $receiptFooter,
        'paper_width'      => '58',
        'printer_ip'       => '',
        'printer_port'     => '9100',
        'bill_prefix'      => 'INV-'
    ];

    $merged = array_merge($defaultSettings, $settings);
    $merged['business_address'] = $businessAddress;
    $merged['address'] = $businessAddress;
    $merged['business_phone'] = $businessPhone;
    $merged['phone'] = $businessPhone;
    $merged['business_gstin'] = $businessGstin;
    $merged['gstin'] = $businessGstin;
    $merged['receipt_footer'] = $receiptFooter;
    $merged['footer_message'] = $receiptFooter;

    json_response(true, 'Settings retrieved successfully.', $merged);
}

// ============================================================================
// Action: update (Update settings with server-side validation & CSRF check)
// ============================================================================
if ($action === 'update') {
    // Only POST allowed for mutations
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(false, 'Method not allowed. Use POST.', null, 405);
    }

    require_csrf();

    $input = get_json_input();
    if (empty($input)) {
        $input = $_POST;
    }

    // 1. Validate Business Name
    $businessName = sanitize_text($input['business_name'] ?? '');
    if (empty($businessName)) {
        json_response(false, 'Business name is required.', null, 422);
    }
    if (mb_strlen($businessName) > 100) {
        json_response(false, 'Business name cannot exceed 100 characters.', null, 422);
    }

    // 2. Validate Address & Phone
    $businessAddress = sanitize_text($input['business_address'] ?? '');
    if (mb_strlen($businessAddress) > 255) {
        json_response(false, 'Business address cannot exceed 255 characters.', null, 422);
    }

    $businessPhone = sanitize_text($input['business_phone'] ?? '');
    if (!empty($businessPhone)) {
        if (!validate_phone($businessPhone) && !preg_match('/^[0-9+\s-]{8,20}$/', $businessPhone)) {
            json_response(false, 'Invalid business phone number format.', null, 422);
        }
    }

    // 3. Validate GSTIN
    $businessGstin = strtoupper(sanitize_text($input['business_gstin'] ?? ''));
    if (!empty($businessGstin) && mb_strlen($businessGstin) > 20) {
        json_response(false, 'GSTIN cannot exceed 20 characters.', null, 422);
    }

    // 4. Validate Receipt Footer
    $receiptFooter = sanitize_text($input['receipt_footer'] ?? '');
    if (mb_strlen($receiptFooter) > 255) {
        json_response(false, 'Receipt footer message cannot exceed 255 characters.', null, 422);
    }

    // 5. Validate Paper Width (58mm or 80mm)
    $paperWidth = sanitize_text((string)($input['paper_width'] ?? '58'));
    if (!in_array($paperWidth, ['58', '80'], true)) {
        json_response(false, "Invalid paper width '{$paperWidth}'. Allowed values: 58, 80.", null, 422);
    }

    // 6. Validate Bill Prefix
    $billPrefix = strtoupper(sanitize_text($input['bill_prefix'] ?? 'INV-'));
    if (empty($billPrefix)) {
        $billPrefix = 'INV-';
    }
    if (!preg_match('/^[A-Z0-9\-_]{1,10}$/', $billPrefix)) {
        json_response(false, 'Bill prefix must be 1-10 alphanumeric characters, hyphens or underscores.', null, 422);
    }

    // 7. Validate Network Printer IP and Port
    $printerIp = trim(sanitize_text($input['printer_ip'] ?? ''));
    if (!empty($printerIp)) {
        if (!filter_var($printerIp, FILTER_VALIDATE_IP)) {
            json_response(false, 'Invalid network printer IP address format.', null, 422);
        }
    }

    $rawPort = $input['printer_port'] ?? '9100';
    $printerPort = (int)$rawPort;
    if ($printerPort < 1 || $printerPort > 65535) {
        json_response(false, 'Printer port must be an integer between 1 and 65535.', null, 422);
    }

    // Key-value map to persist (syncs both business_* and legacy keys)
    $settingsMap = [
        'business_name'    => $businessName,
        'business_address' => $businessAddress,
        'address'          => $businessAddress,
        'business_phone'   => $businessPhone,
        'phone'            => $businessPhone,
        'business_gstin'   => $businessGstin,
        'gstin'            => $businessGstin,
        'receipt_footer'   => $receiptFooter,
        'footer_message'   => $receiptFooter,
        'paper_width'      => $paperWidth,
        'printer_ip'       => $printerIp,
        'printer_port'     => (string)$printerPort,
        'bill_prefix'      => $billPrefix
    ];

    try {
        $db->beginTransaction();

        $upsertStmt = $db->prepare("
            INSERT INTO settings (setting_key, setting_value) 
            VALUES (:k, :v)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");

        foreach ($settingsMap as $k => $v) {
            $upsertStmt->execute([':k' => $k, ':v' => $v]);
        }

        $db->commit();

        json_response(true, 'Settings updated successfully.', $settingsMap);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[Settings Update Error] ' . $e->getMessage());
        json_response(false, 'Database error while saving settings: ' . $e->getMessage(), null, 500);
    }
}

// Invalid action
json_response(false, "Unknown action '{$action}'. Valid actions: get, update.", null, 400);
