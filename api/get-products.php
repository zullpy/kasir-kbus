<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
require_once '../database/koneksi.php';

// Stok grosir (master) -> db_mbg.stok_barang (qty satuan DUS/dsb, per lokasi)
// Stok eceran          -> DIHITUNG dari stok grosir x isi_per_satuan, BUKAN diambil
//                          langsung dari stok_barang_eceran. Jadi kalau dus habis,
//                          otomatis stok eceran ikut jadi 0 juga.
// Harga & konversi      -> db_draft_barang.barang (cross-database JOIN by nama_barang)

$lokasi = $_SESSION['branch'] ?? '';

// Mapping nilai branch di session ke value kolom `lokasi` yang sebenarnya di DB.
// Kalau branch tidak ada di daftar ini, dipakai apa adanya (asumsi sama persis).
$lokasiMap = [
    'sodonghilir' => 'sodong',
    'sariwangi'   => 'sariwangi',
    'manonjaya'   => 'manonjaya',
];
$lokasi = $lokasiMap[$lokasi] ?? $lokasi;

if ($lokasi === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Lokasi cabang tidak ditemukan di sesi. Silakan login ulang.',
    ]);
    exit;
}

$query = "
    SELECT
        ROW_NUMBER() OVER (ORDER BY gs.nama_barang ASC) AS id,
        gs.nama_barang                                   AS name,
        gs.satuan                                        AS satuan,
        gs.qty                                           AS stock,
        COALESCE(b.harga_jual, 0)                        AS price,
        COALESCE(b.harga_jual_eceran, 0)                 AS price_eceran,
        COALESCE(b.kategori, 'Umum')                     AS category,
        b.satuan_eceran                                  AS satuan_eceran,
        b.isi_per_satuan                                 AS isi_per_satuan
    FROM stok_barang gs
    LEFT JOIN db_draft_barang.barang b
        ON gs.nama_barang COLLATE utf8mb4_unicode_ci = b.nama_barang COLLATE utf8mb4_unicode_ci
    WHERE gs.lokasi = ?
    ORDER BY gs.nama_barang ASC
";

$stmt = mysqli_prepare($koneksi_mbg, $query);

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Query gagal disiapkan: ' . mysqli_error($koneksi_mbg),
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, 's', $lokasi);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Query gagal: ' . mysqli_stmt_error($stmt),
    ]);
    exit;
}

$products = [];
while ($row = mysqli_fetch_assoc($result)) {
    $stockGrosir  = (float) $row['stock'];
    $isiPerSatuan = $row['isi_per_satuan'] !== null ? (float) $row['isi_per_satuan'] : 0;

    // Stok eceran = stok grosir x isi per satuan. Kalau isi_per_satuan belum
    // diisi di data barang, dianggap belum ada varian eceran untuk barang ini.
    $stockEceran = $isiPerSatuan > 0 ? (int) floor($stockGrosir * $isiPerSatuan) : 0;

    $products[] = [
        'id'             => (int)   $row['id'],
        'name'           => $row['name'],
        'price'          => (float) $row['price'],
        'price_eceran'   => (float) $row['price_eceran'],
        'stock'          => (int)   $stockGrosir,
        'stock_eceran'   => $stockEceran,
        'category'       => $row['category'],
        'satuan'         => $row['satuan'] ?? 'pcs',
        'satuan_eceran'  => $row['satuan_eceran'],
        'isi_per_satuan' => $isiPerSatuan > 0 ? $isiPerSatuan : null,
    ];
}

mysqli_free_result($result);
mysqli_stmt_close($stmt);

echo json_encode($products);