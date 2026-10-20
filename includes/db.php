<?php
declare(strict_types=1);

// Set PHP Timezone
date_default_timezone_set('Asia/Kolkata');

class Database {
    private static ?PDO $instance = null;

    // Database configuration credentials
    private const DB_HOST = 'localhost';
    private const DB_NAME = 'pos_db';
    private const DB_USER = 'root';
    private const DB_PASS = '';
    private const DB_PORT = 3306;
    private const DB_CHARSET = 'utf8mb4';

    /**
     * Private constructor to prevent direct creation
     */
    private function __construct() {}

    /**
     * Prevent object cloning
     */
    private function __clone() {}

    /**
     * Prevent unserializing
     */
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }

    /**
     * Get the PDO database connection singleton
     */
    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                self::DB_HOST,
                self::DB_PORT,
                self::DB_NAME,
                self::DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, self::DB_USER, self::DB_PASS, $options);
                // Set UTF-8 encoding and MySQL connection timezone to Asia/Kolkata (+05:30)
                self::$instance->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
                self::$instance->exec("SET time_zone = '+05:30'");
            } catch (PDOException $e) {
                // Determine if this is an API request to format the error correctly
                $isApi = (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/api/') !== false) ||
                         (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
                         (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

                if ($isApi) {
                    http_response_code(500);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'success' => false,
                        'message' => 'Database connection failed. Please check server configuration.',
                        'data'    => null
                    ]);
                    exit;
                }

                http_response_code(500);
                echo '<!DOCTYPE html>
                <html lang="en">
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>Database Error</title>
                    <style>
                        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #1e293b; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
                        .card { background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); max-width: 480px; width: 100%; text-align: center; border: 1px solid #e2e8f0; }
                        h1 { color: #e11d48; margin-top: 0; font-size: 1.5rem; }
                        p { margin-bottom: 20px; line-height: 1.5; color: #64748b; }
                        .code { background: #f1f5f9; padding: 10px; border-radius: 6px; font-family: monospace; font-size: 0.85rem; color: #334155; word-break: break-all; margin-bottom: 20px; text-align: left; }
                    </style>
                </head>
                <body>
                    <div class="card">
                        <h1>Database Connection Failed</h1>
                        <p>Could not connect to the database. Ensure MySQL service is running in XAMPP and credentials in <code>includes/db.php</code> are correct.</p>
                        <div class="code">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>
                    </div>
                </body>
                </html>';
                exit;
            }
        }

        return self::$instance;
    }
}

/**
 * Global helper function to get PDO database instance
 */
function get_db(): PDO {
    return Database::getConnection();
}
