<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/settings.php';

$serverKey = getenv('MIDTRANS_SERVER_KEY');
$clientKey = getenv('MIDTRANS_CLIENT_KEY');
$isProduction = getenv('MIDTRANS_IS_PRODUCTION') === '1';

\Midtrans\Config::$serverKey = $serverKey === false ? '' : $serverKey;
\Midtrans\Config::$clientKey = $clientKey === false ? '' : $clientKey;
\Midtrans\Config::$isProduction = $isProduction;
\Midtrans\Config::$isSanitized = true;
\Midtrans\Config::$is3ds = true;

if (!defined('MIDTRANS_CONFIGURED')) {
    define(
        'MIDTRANS_CONFIGURED',
        $serverKey !== false && $serverKey !== '' &&
        $clientKey !== false && $clientKey !== ''
    );
}