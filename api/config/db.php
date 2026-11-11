<?php
// 60Score / STUMPS Backend - Database Connection
// Uses PDO with utf8mb4 and Prepared Statements for maximum security against SQL injection.

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            // Load credentials from 1-Step Web Installer if available
            $config = [];
            $configFile = __DIR__ . '/db_config.php';
            if (file_exists($configFile)) {
                $config = require $configFile;
            }

            $host = getenv('DB_HOST') ?: ($config['host'] ?? 'localhost');
            $db   = getenv('DB_NAME') ?: ($config['database'] ?? 'sixtyscore_db');
            $user = getenv('DB_USER') ?: ($config['username'] ?? 'sixtyscore_user');
            $pass = getenv('DB_PASS') ?: ($config['password'] ?? 'YourStrongPassword123!');
            $port = getenv('DB_PORT') ?: ($config['port'] ?? '3306');
            $charset = 'utf8mb4';

            $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                http_response_code(500);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Database connection failed: ' . $e->getMessage()
                ]);
                exit;
            }
        }
        return self::$instance;
    }
}
