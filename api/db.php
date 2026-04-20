<?php
/**
 * Database Configuration — My Records Collection
 * MySQL connection using PDO.
 */

// Prevent direct access
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'db.php') {
    http_response_code(403);
    exit('Access denied.');
}

// Load from environment variables (set via IIS FastCGI config)
define('DB_HOST',    getenv('RC_DB_HOST')    ?: '127.0.0.1');
define('DB_NAME',    getenv('RC_DB_NAME')    ?: 'record_collection');
define('DB_USER',    getenv('RC_DB_USER')    ?: '');
define('DB_PASS',    getenv('RC_DB_PASS')    ?: '');
define('DB_CHARSET', 'utf8mb4');

/**
 * Get a PDO connection to the MySQL database.
 * Uses a singleton pattern to reuse the connection within a request.
 */
function get_db(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database connection failed.']);
            error_log('[Records DB] Connection error: ' . $e->getMessage());
            exit;
        }
    }

    return $pdo;
}
