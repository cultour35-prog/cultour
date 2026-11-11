<?php
/**
 * CulTour Database Configuration
 * Ormoc City Tourism Office
 *
 * Local (localhost / 127.0.0.1): XAMPP cultour_db as root.
 * Any other host: InfinityFree credentials from CULTOUR_DB_* (config/secrets.php).
 * Production never falls back to the local database.
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

$cultourSecretsFile = ROOT_PATH . '/config/secrets.php';
if (is_file($cultourSecretsFile)) {
    require_once $cultourSecretsFile;
}

function cultour_db_env(string $key): string
{
    if (function_exists('cultour_env')) {
        return cultour_env($key, '');
    }
    $v = getenv($key);
    if (is_string($v) && trim($v) !== '') {
        return trim($v);
    }
    if (isset($_ENV[$key]) && trim((string) $_ENV[$key]) !== '') {
        return trim((string) $_ENV[$key]);
    }
    return '';
}

function cultour_is_local_request(): bool
{
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $host = strtolower(trim((string) $host));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return true;
    }
    if (str_ends_with($host, '.trycloudflare.com')) {
        return true;
    }
    // The GCash QR uses this PC's Wi-Fi address so a phone can open it.
    // That address is still this XAMPP site, not the live server.
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    return false;
}

/**
 * @return array{host:string,name:string,user:string,pass:string,local:bool}
 */
function cultour_db_config(): array
{
    if (cultour_is_local_request()) {
        return [
            'host' => '127.0.0.1',
            'name' => 'cultour_db',
            'user' => 'root',
            'pass' => '',
            'local' => true,
        ];
    }

    return [
        'host' => cultour_db_env('CULTOUR_DB_HOST'),
        'name' => cultour_db_env('CULTOUR_DB_NAME'),
        'user' => cultour_db_env('CULTOUR_DB_USER'),
        'pass' => cultour_db_env('CULTOUR_DB_PASS'),
        'local' => false,
    ];
}

$cultourDb = cultour_db_config();

define('DB_HOST', $cultourDb['host']);
define('DB_NAME', $cultourDb['name']);
define('DB_USER', $cultourDb['user']);
define('DB_PASS', $cultourDb['pass']);
define('DB_CHARSET', 'utf8mb4');
define('DB_IS_LOCAL', $cultourDb['local']);

function cultour_db_fail(string $logMessage): void
{
    error_log('CulTour DB: ' . $logMessage);
    if (!headers_sent()) {
        http_response_code(503);
    }
    die('Database connection failed. Please try again later.');
}

/**
 * Get PDO database connection (singleton).
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        if (!DB_IS_LOCAL && (DB_HOST === '' || DB_NAME === '' || DB_USER === '' || DB_PASS === '')) {
            cultour_db_fail('Production database credentials are missing. Set CULTOUR_DB_HOST, CULTOUR_DB_NAME, CULTOUR_DB_USER, and CULTOUR_DB_PASS in config/secrets.php on the server.');
        }

        $usePipe = DB_IS_LOCAL && PHP_OS_FAMILY === 'Windows';
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Each Windows click was opening a new TCP connection and leaving it
            // in TIME_WAIT until the port list filled up. Reuse one pipe connection
            // per Apache thread. Production stays non-persistent.
            PDO::ATTR_PERSISTENT         => $usePipe,
        ];
        $dsn = $usePipe
            ? 'mysql:unix_socket=\\\\.\\pipe\\MySQL;dbname=' . DB_NAME . ';charset=' . DB_CHARSET
            : 'mysql:host=' . DB_HOST . ';port=3306;dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

        $lastError = null;
        $tries = $usePipe ? 3 : 1;
        for ($attempt = 0; $attempt < $tries; $attempt++) {
            try {
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                $pdo->query('SELECT 1');
                break;
            } catch (Throwable $e) {
                $lastError = $e;
                $pdo = null;
                if ($attempt < $tries - 1) {
                    usleep(40000);
                }
            }
        }

        if (!$pdo instanceof PDO) {
            $where = DB_IS_LOCAL ? 'local' : 'production';
            $detail = $lastError instanceof Throwable ? $lastError->getMessage() : 'unknown error';
            cultour_db_fail($where . ' connection failed: ' . $detail);
        }
    }

    return $pdo;
}
