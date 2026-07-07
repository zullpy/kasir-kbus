<?php
session_start();
header('Content-Type: application/json');
require_once '../database/koneksi.php';

// Nomor transaksi harian dihitung PER CABANG, bukan gabungan semua cabang.
// Cabang diambil dari $_SESSION['branch'] (di-set saat operator login di
// index.php), kolom `kasir` di tabel transaksi isinya sama persis (misal
// 'sariwangi'). Kalau somehow session branch kosong (mis. diakses tanpa
// login operator yang benar), fallback ke hitungan gabungan semua cabang.
$cabang = $_SESSION['branch'] ?? '';

if ($cabang !== '') {
    $stmtCount = mysqli_prepare(
        $koneksi_kasir,
        "SELECT COUNT(*) AS total FROM transaksi WHERE DATE(tanggal) = CURDATE() AND LOWER(kasir) = LOWER(?)"
    );
    mysqli_stmt_bind_param($stmtCount, 's', $cabang);
} else {
    $stmtCount = mysqli_prepare(
        $koneksi_kasir,
        "SELECT COUNT(*) AS total FROM transaksi WHERE DATE(tanggal) = CURDATE()"
    );
}

mysqli_stmt_execute($stmtCount);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtCount));
mysqli_stmt_close($stmtCount);

echo json_encode([
    'success' => true,
    'count'   => (int) ($row['total'] ?? 0),
]);