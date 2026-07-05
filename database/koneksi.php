<?php
// koneksi.php
// Menghubungkan ke 3 database: db_mbg, db_kasir, db_draft_barang

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ==== Konfigurasi Host & Kredensial ====
$db_host = "localhost";
$db_user = "root";
$db_pass = "";

// ==== Koneksi Database: db_mbg ====
$koneksi_mbg = mysqli_connect($db_host, $db_user, $db_pass, "db_mbg");
if (!$koneksi_mbg) {
    die("Koneksi ke database db_mbg gagal: " . mysqli_connect_error());
}
mysqli_set_charset($koneksi_mbg, "utf8mb4");

// ==== Koneksi Database: db_kasir ====
$koneksi_kasir = mysqli_connect($db_host, $db_user, $db_pass, "db_kasir");
if (!$koneksi_kasir) {
    die("Koneksi ke database db_kasir gagal: " . mysqli_connect_error());
}
mysqli_set_charset($koneksi_kasir, "utf8mb4");

// ==== Koneksi Database: db_draft_barang ====
$koneksi_draft = mysqli_connect($db_host, $db_user, $db_pass, "db_draft_barang");
if (!$koneksi_draft) {
    die("Koneksi ke database db_draft_barang gagal: " . mysqli_connect_error());
}
mysqli_set_charset($koneksi_draft, "utf8mb4");
