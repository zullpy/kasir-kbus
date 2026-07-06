<?php
// Guard: pastikan sesi operator aktif.
// Include file ini di bagian paling atas setiap halaman operator.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'operator' && $_SESSION['role'] !== 'admin') {
    // Hitung berapa level folder dari root berdasarkan path file, bukan jumlah '/' mentah.
    $parts = explode('/', trim($_SERVER['PHP_SELF'], '/'));
    $depth = count($parts) - 1; // -1 karena elemen terakhir adalah nama file (index.php)
    header('Location: ' . str_repeat('../', $depth) . 'index.php');
    exit;
}