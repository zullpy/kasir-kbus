<?php
header('Content-Type: application/json');
require_once '../database/koneksi.php';

// Hitung jumlah transaksi yang SUDAH tersimpan hari ini di db_kasir.
// Sumbernya sama dengan yang dipakai checkout.php untuk generate kode_transaksi,
// jadi nomor di receipt-pane akan selalu sinkron dengan data di database.
$stmtCount = mysqli_prepare(
    $koneksi_kasir,
    "SELECT COUNT(*) AS total FROM transaksi WHERE DATE(tanggal) = CURDATE()"
);
mysqli_stmt_execute($stmtCount);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtCount));
mysqli_stmt_close($stmtCount);

echo json_encode([
    'success' => true,
    'count'   => (int) ($row['total'] ?? 0),
]);