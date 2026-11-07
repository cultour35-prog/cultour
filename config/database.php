<?php
/**
 * CulTour Database Configuration
 * Ormoc City Tourism Office
 */

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'cultour_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

/**
 * Get PDO database connection (singleton).
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => true,
        ];
        $dsns = [
            'mysql:host=localhost;dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
            'mysql:host=' . DB_HOST . ';port=3306;dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        ];
        $lastError = null;
        foreach ($dsns as $index => $dsn) {
            $tries = $index === 0 ? 2 : 8;
            for ($attempt = 0; $attempt < $tries; $attempt++) {
                try {
                    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                    break 2;
                } catch (PDOException $e) {
                    $lastError = $e;
                    $portBusy = str_contains($e->getMessage(), 'Only one usage of each socket address');
                    if (!$portBusy || $attempt === $tries - 1) {
                        break;
                    }
                    usleep(20000);
                }
            }
        }
        if ($pdo instanceof PDO) {
            try {
                $pdo->query('SELECT 1');
            } catch (Throwable $e) {
                $lastError = $e;
                $pdo = null;
                try {
                    $options[PDO::ATTR_PERSISTENT] = false;
                    $pdo = new PDO($dsns[0], DB_USER, DB_PASS, $options);
                } catch (Throwable $again) {
                    $lastError = $again;
                    $pdo = null;
                }
            }
        }
        if (!$pdo instanceof PDO) {
            if ($lastError instanceof Throwable) {
                error_log('CulTour DB: ' . $lastError->getMessage());
            }
            http_response_code(503);
            die('Database connection failed. Refresh the page. MySQL is still running, so do not import the SQL file again.');
        }
    }

    return $pdo;
}
