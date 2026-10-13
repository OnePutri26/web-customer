<?php

require_once __DIR__ . '/config/auth.php';

if ((int) ($_SESSION['user_id'] ?? 0) <= 0) {
    appRedirect('login.php');
}

require_once __DIR__ . '/config/database.php';

redirectRoleHome(
    $conn,
    (string) ($_SESSION['role'] ?? ''),
    (int) $_SESSION['user_id']
);
