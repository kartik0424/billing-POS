<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Send standard JSON API response and terminate execution
 *
 * @param bool $success
 * @param string $message
 * @param mixed $data
 * @param int $statusCode
 * @return never
 */
function json_response(bool $success, string $message, $data = null, int $statusCode = 200): void {
    // Clean any prior buffer contents if needed
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Escape HTML output to prevent XSS attacks
 *
 * @param mixed $value
 * @return string
 */
function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Format currency in Indian Rupees (INR) with 2 decimals
 *
 * @param float|int|string|null $amount
 * @return string
 */
function format_money($amount): string {
    $num = (float)($amount ?? 0);
    return '₹' . number_format($num, 2, '.', ',');
}

/**
 * Get setting value by key with memory cache
 *
 * @param string $key
 * @param string|null $default
 * @return string|null
 */
function get_setting(string $key, ?string $default = null): ?string {
    static $cache = [];

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $db = get_db();
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1");
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch();

        if ($row && $row['setting_value'] !== null) {
            $value = (string)$row['setting_value'];
        } else {
            // Check fallback alias if key is one of the known mapped keys
            $alias = match ($key) {
                'business_address' => 'address',
                'address'          => 'business_address',
                'business_phone'   => 'phone',
                'phone'            => 'business_phone',
                'business_gstin'   => 'gstin',
                'gstin'            => 'business_gstin',
                'receipt_footer'   => 'footer_message',
                'footer_message'   => 'receipt_footer',
                default            => null
            };

            if ($alias !== null) {
                $altStmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = :alias LIMIT 1");
                $altStmt->execute([':alias' => $alias]);
                $altRow = $altStmt->fetch();
                $value = ($altRow && $altRow['setting_value'] !== null) ? (string)$altRow['setting_value'] : $default;
            } else {
                $value = $default;
            }
        }

        $cache[$key] = $value;
        return $value;
    } catch (\Throwable $e) {
        return $default;
    }
}

/**
 * Retrieve all settings as key-value pairs
 *
 * @return array<string, string>
 */
function get_all_settings(): array {
    try {
        $db = get_db();
        $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        // If business_* exists, keep legacy keys in sync (and vice-versa)
        if (!empty($settings['business_address'])) {
            $settings['address'] = $settings['business_address'];
        } elseif (!empty($settings['address'])) {
            $settings['business_address'] = $settings['address'];
        }

        if (!empty($settings['business_phone'])) {
            $settings['phone'] = $settings['business_phone'];
        } elseif (!empty($settings['phone'])) {
            $settings['business_phone'] = $settings['phone'];
        }

        if (!empty($settings['business_gstin'])) {
            $settings['gstin'] = $settings['business_gstin'];
        } elseif (!empty($settings['gstin'])) {
            $settings['business_gstin'] = $settings['gstin'];
        }

        if (!empty($settings['receipt_footer'])) {
            $settings['footer_message'] = $settings['receipt_footer'];
        } elseif (!empty($settings['footer_message'])) {
            $settings['receipt_footer'] = $settings['footer_message'];
        }

        return $settings;
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Generate sequential bill number safe against race conditions (e.g., INV-000001)
 * Uses row lock (SELECT ... FOR UPDATE) inside caller transaction or auto-transaction.
 *
 * @param PDO|null $pdo
 * @return string
 */
function generate_bill_no(?PDO $pdo = null): string {
    $db = $pdo ?? get_db();
    $prefix = get_setting('bill_prefix', 'INV');
    if (empty($prefix)) {
        $prefix = 'INV';
    }

    $isLocalTx = false;
    if (!$db->inTransaction()) {
        $db->beginTransaction();
        $isLocalTx = true;
    }

    try {
        // Lock the latest bill record to avoid concurrent duplicate sequence numbers
        $stmt = $db->query("SELECT bill_no FROM bills ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $lastBill = $stmt->fetch();

        $nextNumber = 1;
        if ($lastBill && !empty($lastBill['bill_no'])) {
            if (preg_match('/(\d+)$/', $lastBill['bill_no'], $matches)) {
                $nextNumber = ((int)$matches[1]) + 1;
            }
        }

        if ($isLocalTx) {
            $db->commit();
        }

        return sprintf('%s-%06d', $prefix, $nextNumber);
    } catch (\Throwable $e) {
        if ($isLocalTx && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Sanitize plain text string
 *
 * @param mixed $value
 * @return string
 */
function sanitize_text($value): string {
    if ($value === null) {
        return '';
    }
    $clean = trim((string)$value);
    $clean = str_replace(chr(0), '', $clean);
    return strip_tags($clean);
}

/**
 * Sanitize and validate float value
 *
 * @param mixed $value
 * @param float $default
 * @param float|null $min
 * @param float|null $max
 * @return float
 */
function sanitize_float($value, float $default = 0.0, ?float $min = null, ?float $max = null): float {
    if ($value === null || $value === '') {
        return $default;
    }
    $filtered = filter_var($value, FILTER_VALIDATE_FLOAT);
    if ($filtered === false) {
        return $default;
    }
    $val = (float)$filtered;
    if ($min !== null && $val < $min) {
        return $min;
    }
    if ($max !== null && $val > $max) {
        return $max;
    }
    return round($val, 2);
}

/**
 * Sanitize and validate integer value
 *
 * @param mixed $value
 * @param int $default
 * @param int|null $min
 * @param int|null $max
 * @return int
 */
function sanitize_int($value, int $default = 0, ?int $min = null, ?int $max = null): int {
    if ($value === null || $value === '') {
        return $default;
    }
    $filtered = filter_var($value, FILTER_VALIDATE_INT);
    if ($filtered === false) {
        return $default;
    }
    $val = (int)$filtered;
    if ($min !== null && $val < $min) {
        return $min;
    }
    if ($max !== null && $val > $max) {
        return $max;
    }
    return $val;
}

/**
 * Validate customer phone number (supports Indian and international formats)
 *
 * @param mixed $phone
 * @return bool
 */
function validate_phone($phone): bool {
    if ($phone === null || $phone === '') {
        return true; // Phone is optional in bills
    }
    $cleaned = preg_replace('/[\s\-\(\)]/', '', (string)$phone);
    return (bool)preg_match('/^(\+91|91|0)?[6-9]\d{9}$|^[0-9]{7,15}$/', $cleaned);
}

/**
 * Validate value against an allowed set of enum options
 *
 * @param mixed $value
 * @param array $allowed
 * @param string $default
 * @return string
 */
function validate_enum($value, array $allowed, string $default = ''): string {
    $str = (string)($value ?? '');
    return in_array($str, $allowed, true) ? $str : $default;
}

/**
 * Helper to parse raw JSON request body
 *
 * @return array
 */
function get_json_input(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}
