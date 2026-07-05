<?php
// Guard: pastikan sesi operator aktif.
// Include file ini di bagian paling atas setiap halaman operator.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'operator') {
    header('Location: ' . str_repeat('../', substr_count($_SERVER['PHP_SELF'], '/') - 2) . 'index.php');
    exit;
}
