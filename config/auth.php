<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requireLogin()
{
    if (
        !isset($_SESSION['user_id'])
        || (int) $_SESSION['user_id'] <= 0
    ) {

        header("Location: ../login.php");
        exit;
    }
}

function requireRole($role)
{
    requireLogin();

    if (
        !isset($_SESSION['role'])
        || $_SESSION['role'] !== $role
    ) {

        header("Location: ../index.php");
        exit;
    }
}