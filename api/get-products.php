<?php
header('Content-Type: application/json');
require_once '../database/koneksi.php';

// Stok dihitung dari:
//   total barang yang dikirim (tabel detail_pengiriman)
//   dikurangi total barang yang sudah diambil (tabel pengambilan_barang_detail)
// Dikelompokkan per nama_barang.
//
// Catatan: skema db_mbg tidak punya kolom harga_jual / kategori,
// jadi 'price' dan 'category' di-default kan agar struktur JSON tetap sama.

$query = "
    SELECT
        ROW_NUMBER() OVER (ORDER BY masuk.nama_barang ASC) as id,
        masuk.nama_barang as name,
        masuk.satuan as satuan,
        masuk.keterangan as keterangan,
        (masuk.total_masuk - COALESCE(keluar.total_keluar, 0)) as stock
    FROM (
        SELECT
            nama_barang,
            MAX(satuan) as satuan,
            MAX(keterangan) as keterangan,
            SUM(qty) as total_masuk
        FROM detail_pengiriman
        GROUP BY nama_barang
    ) masuk
    LEFT JOIN (
        SELECT nama_barang, SUM(qty) as total_keluar
        FROM pengambilan_barang_detail
        GROUP BY nama_barang
    ) keluar ON masuk.nama_barang COLLATE utf8mb4_unicode_ci = keluar.nama_barang COLLATE utf8mb4_unicode_ci
    ORDER BY masuk.nama_barang ASC
";

$result = mysqli_query($koneksi_mbg, $query);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Query gagal: ' . mysqli_error($koneksi_mbg)
    ]);
    exit;
}

$products = [];
while ($row = mysqli_fetch_assoc($result)) {
    $products[] = [
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'price' => 0, // tidak ada kolom harga di skema ini
        'stock' => (int)$row['stock'],
        'category' => 'Umum', // tidak ada kolom kategori di skema ini
        'keterangan' => $row['keterangan'],
        'satuan' => $row['satuan'] ?? 'pcs'
    ];
}

echo json_encode($products);

mysqli_free_result($result);
