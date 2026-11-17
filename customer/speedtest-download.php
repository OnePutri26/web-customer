<?php
declare(strict_types=1);

require_once __DIR__ . '/speedtest-common.php';

stGuard(['GET', 'HEAD']);

header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

// Mode ping: respons kosong, sekecil mungkin.
if (isset($_GET['ping']) || $_SERVER['REQUEST_METHOD'] === 'HEAD') {
    http_response_code(204);
    exit;
}

$kb = filter_var($_GET['kb'] ?? 4096, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 64, 'max_range' => 8192],
]);
if ($kb === false) {
    http_response_code(400);
    exit;
}

$size = $kb * 1024;

@ini_set('zlib.output_compression', '0');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
header('Content-Type: application/octet-stream');
header('Content-Encoding: identity');
header('Content-Length: ' . $size);
header('X-Accel-Buffering: no');

while (ob_get_level() > 0) {
    ob_end_clean();
}

// Satu blok acak dipakai ulang: tidak bisa dikompres, ringan di CPU.
$block = random_bytes(65536);
$remaining = $size;

while ($remaining > 0 && connection_aborted() === 0) {
    $n = min(65536, $remaining);
    echo $n === 65536 ? $block : substr($block, 0, $n);
    $remaining -= $n;
    flush();
}