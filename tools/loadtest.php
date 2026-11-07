<?php
/**
 * CulTour load test (CLI only).
 *
 *   C:\xampp\php\php.exe tools\loadtest.php [--n=100] [--c=10] [--email=tourist@example.com] [--password=Password123!] [--out=report.md]
 *
 * --n   requests per page
 * --c   concurrent virtual users (each one signs in with its own session)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/app.php';

$opts = getopt('', ['n::', 'c::', 'email::', 'password::', 'out::', 'base::']);
$perPage = max(1, (int) ($opts['n'] ?? 100));
$concurrency = max(1, (int) ($opts['c'] ?? 10));
$email = (string) ($opts['email'] ?? 'tourist@example.com');
$password = (string) ($opts['password'] ?? 'Password123!');
$base = rtrim((string) ($opts['base'] ?? APP_URL), '/');
$outFile = isset($opts['out']) ? (string) $opts['out'] : null;

$publicPages = ['/', '/login.php', '/register.php', '/explore.php'];
$userPages = [
    '/tourist/dashboard.php',
    '/tourist/destinations.php',
    '/tourist/businesses.php',
    '/tourist/itineraries.php',
    '/notifications.php',
];

function lt_login(string $base, string $email, string $password): ?string
{
    $jar = tempnam(sys_get_temp_dir(), 'ctlt');
    $ch = curl_init($base . '/login.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    $html = (string) curl_exec($ch);
    curl_close($ch);
    if (!preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $html, $m)) {
        @unlink($jar);
        return null;
    }
    $ch = curl_init($base . '/login.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $m[1], 'email' => $email, 'password' => $password]),
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $res = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    if (empty($res['ok'])) {
        @unlink($jar);
        return null;
    }
    return $jar;
}

/**
 * @param list<?string> $jars one cookie jar per virtual user (null = anonymous)
 * @return array{times:list<float>,errors:int,bytes:int,wall:float}
 */
function lt_run(string $url, int $total, array $jars): array
{
    $mh = curl_multi_init();
    $times = [];
    $errors = 0;
    $bytes = 0;
    $started = 0;
    $active = [];
    $start = microtime(true);

    $add = function (int $slot) use (&$started, &$active, $mh, $url, $jars): void {
        $ch = curl_init($url);
        $o = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ];
        if ($jars[$slot] !== null) {
            $o[CURLOPT_COOKIEFILE] = $jars[$slot];
        }
        curl_setopt_array($ch, $o);
        curl_multi_add_handle($mh, $ch);
        $active[(int) $ch] = $slot;
        $started++;
    };

    for ($s = 0; $s < count($jars) && $started < $total; $s++) {
        $add($s);
    }
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.5);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $times[] = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
            $bytes += (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
            if ($info['result'] !== CURLE_OK || $code < 200 || $code >= 400) {
                $errors++;
            }
            $slot = $active[(int) $ch];
            unset($active[(int) $ch]);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if ($started < $total) {
                $add($slot);
            }
        }
    } while ($running > 0 || $active);
    curl_multi_close($mh);

    return ['times' => $times, 'errors' => $errors, 'bytes' => $bytes, 'wall' => microtime(true) - $start];
}

function lt_pct(array $sorted, float $p): float
{
    if (!$sorted) {
        return 0.0;
    }
    $i = (int) ceil($p / 100 * count($sorted)) - 1;
    return $sorted[max(0, min(count($sorted) - 1, $i))];
}

echo "CulTour load test: {$perPage} requests per page, {$concurrency} concurrent users, base {$base}\n";
$userJars = [];
for ($i = 0; $i < $concurrency; $i++) {
    $jar = lt_login($base, $email, $password);
    if ($jar === null) {
        fwrite(STDERR, "Could not sign in as {$email}; signed-in pages will be skipped.\n");
        $userJars = [];
        break;
    }
    $userJars[] = $jar;
}
$anonJars = array_fill(0, $concurrency, null);

$rows = [];
$plan = array_map(fn ($p) => [$p, $anonJars, 'guest'], $publicPages);
if ($userJars) {
    foreach ($userPages as $p) {
        $plan[] = [$p, $userJars, 'tourist'];
    }
}
foreach ($plan as [$path, $jars, $who]) {
    $r = lt_run($base . $path, $perPage, $jars);
    $t = $r['times'];
    sort($t);
    $rows[] = [
        'page' => $path,
        'who' => $who,
        'n' => count($t),
        'errors' => $r['errors'],
        'avg' => $t ? array_sum($t) / count($t) : 0,
        'p50' => lt_pct($t, 50),
        'p95' => lt_pct($t, 95),
        'max' => $t ? max($t) : 0,
        'rps' => $r['wall'] > 0 ? count($t) / $r['wall'] : 0,
        'kb' => $t ? $r['bytes'] / count($t) / 1024 : 0,
    ];
    printf("  %-28s done\n", $path);
}
foreach ($userJars as $jar) {
    @unlink($jar);
}

$lines = [];
$lines[] = '| Page | User | Requests | Errors | Avg ms | p50 ms | p95 ms | Max ms | Req/s | KB/resp (gzip) |';
$lines[] = '|---|---|---:|---:|---:|---:|---:|---:|---:|---:|';
foreach ($rows as $r) {
    $lines[] = sprintf(
        '| %s | %s | %d | %d | %.0f | %.0f | %.0f | %.0f | %.1f | %.1f |',
        $r['page'], $r['who'], $r['n'], $r['errors'], $r['avg'], $r['p50'], $r['p95'], $r['max'], $r['rps'], $r['kb']
    );
}
$report = "CulTour load test - " . date('Y-m-d H:i') . "\n\n"
    . "- Requests per page: {$perPage}\n- Concurrent users: {$concurrency}\n- Server: {$base}\n\n"
    . implode("\n", $lines) . "\n";
echo "\n" . $report;
if ($outFile) {
    file_put_contents($outFile, $report);
    echo "\nSaved to {$outFile}\n";
}
