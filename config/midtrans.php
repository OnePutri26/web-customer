<?php

require_once __DIR__ . '/../vendor/autoload.php';

\Midtrans\Config::$serverKey = 'SERVER_KEY_KAMU';
\Midtrans\Config::$clientKey = 'CLIENT_KEY_KAMU';

\Midtrans\Config::$isProduction = false;
\Midtrans\Config::$isSanitized = true;
\Midtrans\Config::$is3ds = true;