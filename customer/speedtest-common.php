<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function stFail(int $code, ?string $allow = null, ?int $retry = null): never
{
    http_response_code($code);
    if ($allow !== null) header('Allow: ' . $allow);
    if ($retry !== null) header('Retry-After: ' . $retry);
    exit;
}

/** Batas request per menit per pelanggan (file lock, tanpa session lock). */
function stRateLimit(int $userId, int $maxPerMinute): void
{
    $file = sys_get_temp_dir() . '/yesnet_st_' . $userId . '.json';
    $fp = @fopen($file, 'c+');
    if ($fp === false) return;

    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    $now = time();

    if (!is_array($data) || ($data['w'] ?? 0) < $now - 60) {
        $data = ['w' => $now, 'n' => 0];
    }

    $data['n']++;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($data['n'] > $maxPerMinute) {
        stFail(429, null, 10);
    }
}

function stGuard(array $methods, int $maxPerMinute = 400): void
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $role = normalizeAuthRole($_SESSION['role'] ?? '');
    $sessionCsrf = $_SESSION['speedtest_csrf'] ?? '';
    $csrf = $_SERVER['HTTP_X_YESNET_CSRF'] ?? '';

    // Tutup sesi secepatnya agar request paralel tidak saling mengantre.
    session_write_close();

    if ($userId <= 0 || $role !== 'customer') stFail(401);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, $methods, true)) stFail(405, implode(', ', $methods));

    if (
        !is_string($csrf) || !is_string($sessionCsrf) ||
        $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)
    ) {
        stFail(403);
    }

    stRateLimit($userId, $maxPerMinute);
}   