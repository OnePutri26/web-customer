<?php
require_once __DIR__ . '/config/xendit.php';
$c = xenditConfig();
header('Content-Type: text/plain');
echo "file local ada : " . (is_file(__DIR__ . '/config/xendit.local.php') ? 'ya' : 'TIDAK') . "\n";
echo "secret_key     : " . ($c['secret_key'] !== '' ? 'terisi' : 'KOSONG') . "\n";
echo "callback_token : " . ($c['callback_token'] !== '' ? 'terisi, akhiran ' . substr($c['callback_token'], -4) : 'KOSONG') . "\n";
echo "app_url        : " . $c['app_url'] . "\n";
echo "curl           : " . (function_exists('curl_init') ? 'ya' : 'TIDAK') . "\n";