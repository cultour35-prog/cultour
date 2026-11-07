<?php
/**
 * CulTour Application Configuration
 *
 * Secrets (SMTP password, APP_URL override) come from environment variables
 * or from config/secrets.php (copy config/secrets.example.php). Do not commit
 * live passwords.
 */

define('APP_NAME', 'CulTour');
define('APP_TAGLINE', 'Ormoc City Tourism Management System');
define('APP_VERSION', '1.0.0');

define('ROOT_PATH', dirname(__DIR__));
$cultourSecrets = ROOT_PATH . '/config/secrets.php';
if (is_file($cultourSecrets)) {
    require $cultourSecrets;
}

function cultour_env(string $key, string $default = ''): string
{
    $v = getenv($key);
    if (is_string($v) && trim($v) !== '') {
        return trim($v);
    }
    if (isset($_ENV[$key]) && trim((string) $_ENV[$key]) !== '') {
        return trim((string) $_ENV[$key]);
    }
    return $default;
}

define('APP_URL', rtrim(cultour_env('CULTOUR_APP_URL', 'http://localhost/cultour'), '/'));

// Timezone: match this PC when we can (XAMPP), otherwise Philippines
$appTz = 'Asia/Manila';
if (class_exists('IntlTimeZone')) {
    try {
        $osTz = IntlTimeZone::createDefault();
        $osId = $osTz ? $osTz->getID() : '';
        if (is_string($osId) && $osId !== '' && @timezone_open($osId)) {
            $appTz = $osId;
        }
    } catch (Throwable $e) {
        // keep Asia/Manila
    }
}
date_default_timezone_set($appTz);

// Paths
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('UPLOAD_URL', APP_URL . '/uploads');

// Session
define('SESSION_NAME', 'cultour_session');
define('SESSION_IDLE_SECONDS', 8 * 3600);
define('SESSION_SAVE_PATH', ROOT_PATH . '/storage/sessions');
define('REMEMBER_COOKIE', 'cultour_remember');
define('REMEMBER_DAYS', 30);

// Security
define('CSRF_TOKEN_NAME', 'csrf_token');
define('AUTH_MAX_FAILS', 5);        // per email + IP
define('AUTH_IP_MAX_FAILS', 20);    // per IP across all emails
define('AUTH_LOCK_MINUTES', 15);
// Opens security-demo.php (deliberately unprotected SQL/XSS examples, this PC only). Keep false outside testing.
define('SECURITY_DEMO_ENABLED', true);

// Email — Gmail SMTP (required on XAMPP; PHP mail() alone usually cannot send via Gmail)
define('MAIL_FROM', cultour_env('CULTOUR_MAIL_FROM', 'cultour35@gmail.com'));
define('MAIL_FROM_NAME', 'CulTour');
define('MAIL_SMTP_HOST', cultour_env('CULTOUR_MAIL_SMTP_HOST', 'smtp.gmail.com'));
define('MAIL_SMTP_PORT', (int) cultour_env('CULTOUR_MAIL_SMTP_PORT', '587'));
define('MAIL_SMTP_USER', cultour_env('CULTOUR_MAIL_SMTP_USER', 'cultour35@gmail.com'));
define('MAIL_SMTP_PASS', cultour_env('CULTOUR_MAIL_SMTP_PASS', ''));
define('MAIL_SMTP_SECURE', cultour_env('CULTOUR_MAIL_SMTP_SECURE', 'tls')); // tls on 587, or ssl on 465

/**
 * GCash / payment gateway
 * Demo mode: tourist submits a reference; admin confirms it. Not a live GCash API.
 * For real PayMongo later: set PAYMENT_PROVIDER=paymongo and fill keys.
 */
define('PAYMENT_PROVIDER', cultour_env('CULTOUR_PAYMENT_PROVIDER', 'gcash_demo')); // gcash_demo | paymongo
define('PAYMENT_WEBHOOK_SECRET', cultour_env('CULTOUR_PAYMENT_WEBHOOK_SECRET', 'cultour_gcash_webhook_dev_secret'));
define('PAYMENT_CURRENCY', 'PHP');
// Static GCash / InstaPay QR shown on checkout (file under assets/img/)
define('GCASH_QR_PATH', 'assets/img/gcash-qr.png');
define('GCASH_ACCOUNT_LABEL', 'CulTour GCash');
// Optional real PayMongo keys (unused in demo mode)
define('PAYMONGO_SECRET_KEY', cultour_env('CULTOUR_PAYMONGO_SECRET_KEY', ''));
define('PAYMONGO_PUBLIC_KEY', cultour_env('CULTOUR_PAYMONGO_PUBLIC_KEY', ''));
define('PAYMONGO_WEBHOOK_SECRET', cultour_env('CULTOUR_PAYMONGO_WEBHOOK_SECRET', ''));

// Role IDs (must match seed data)
define('ROLE_ADMIN', 1);
define('ROLE_TOURIST', 2);
define('ROLE_TOUR_GUIDE', 3);
define('ROLE_BUSINESS', 4);

// Role redirects
function role_dashboard(int $roleId): string
{
    return match ($roleId) {
        ROLE_ADMIN      => APP_URL . '/admin/dashboard.php',
        ROLE_TOURIST    => APP_URL . '/tourist/dashboard.php',
        ROLE_TOUR_GUIDE => APP_URL . '/tourguide/dashboard.php',
        ROLE_BUSINESS   => APP_URL . '/business/dashboard.php',
        default         => APP_URL . '/login.php',
    };
}

function role_slug(int $roleId): string
{
    return match ($roleId) {
        ROLE_ADMIN      => 'admin',
        ROLE_TOURIST    => 'tourist',
        ROLE_TOUR_GUIDE => 'tour_guide',
        ROLE_BUSINESS   => 'business',
        default         => 'guest',
    };
}
