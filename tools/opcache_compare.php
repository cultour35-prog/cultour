<?php
/**
 * Measures page latency on PHP's built-in server with OPcache off vs on (CLI only).
 *
 *   C:\xampp\php\php.exe tools\opcache_compare.php [--n=20]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$n = max(3, (int) (getopt('', ['n::'])['n'] ?? 20));
$php = PHP_BINARY;
$docroot = dirname(__DIR__, 2);
$port = 8099;
$base = "http://127.0.0.1:{$port}/cultour";
$pages = ['/login.php', '/register.php', '/tourist/dashboard.php', '/tourist/destinations.php'];

function oc_get(string $url, ?string $jar): float
{
    $ch = curl_init($url);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60];
    if ($jar) {
        $o[CURLOPT_COOKIEFILE] = $jar;
    }
    curl_setopt_array($ch, $o);
    curl_exec($ch);
    $t = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
    curl_close($ch);
    return $t;
}

function oc_login(string $base): ?string
{
    $jar = tempnam(sys_get_temp_dir(), 'ctoc');
    $ch = curl_init($base . '/login.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    $html = (string) curl_exec($ch);
    curl_close($ch);
    if (!preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $html, $m)) {
        return null;
    }
    $ch = curl_init($base . '/login.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $m[1], 'email' => 'tourist@example.com', 'password' => 'Password123!']),
    ]);
    curl_exec($ch);
    curl_close($ch);
    return $jar;
}

$results = [];
foreach (['off' => '-d opcache.enable=0', 'on' => '-d zend_extension=opcache -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.validate_timestamps=1'] as $mode => $flags) {
    $cmd = '"' . $php . '" ' . $flags . ' -S 127.0.0.1:' . $port . ' -t "' . $docroot . '"';
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
    usleep(800000);
    $jar = oc_login($base);
    foreach ($pages as $p) {
        $needsLogin = str_starts_with($p, '/tourist/');
        oc_get($base . $p, $needsLogin ? $jar : null);
        $times = [];
        for ($i = 0; $i < $n; $i++) {
            $times[] = oc_get($base . $p, $needsLogin ? $jar : null);
        }
        sort($times);
        $results[$p][$mode] = $times[(int) floor(count($times) / 2)];
    }
    if ($jar) {
        @unlink($jar);
    }
    $status = proc_get_status($proc);
    if (!empty($status['pid'])) {
        exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>NUL');
    }
    proc_close($proc);
}

echo "| Page | OPcache off (median ms) | OPcache on (median ms) | Faster |\n|---|---:|---:|---:|\n";
foreach ($results as $p => $r) {
    $off = $r['off'] ?? 0;
    $on = $r['on'] ?? 0;
    printf("| %s | %.0f | %.0f | %.1fx |\n", $p, $off, $on, $on > 0 ? $off / $on : 0);
}
