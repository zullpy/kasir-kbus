<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
require_once '../database/koneksi.php';
require_once '../includes/stok_helper.php'; // konversi qty_eceran (source of truth) <-> tampilan dus/pcs

// Stok: SATU kolom source of truth -> db_mbg.stok_barang.qty_eceran (total
// dalam satuan kecil, per lokasi). Kolom qty_grosir TIDAK dipakai lagi untuk
// menghitung stok (namanya tetap ada di tabel, cuma tidak dibaca di sini).
// Stok "grosir" yang ditampilkan ke user dihitung on-the-fly dari
// qty_eceran / isi_per_satuan (lihat stokTersediaGrosir() di stok_helper.php).
// Satuan grosir/eceran  -> db_mbg.stok_barang.satuan / satuan_eceran (KOLOM SENDIRI)
// Harga & isi_per_satuan (info konversi) -> db_draft_barang.barang (cross-database JOIN by nama_barang)

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
        gs.satuan_eceran                                  AS satuan_eceran,
        gs.qty_eceran                                     AS qty_eceran,
        COALESCE(b.harga_jual, 0)                        AS price,
        COALESCE(b.harga_jual_eceran, 0)                 AS price_eceran,
        COALESCE(b.kategori, 'Umum')                     AS category,
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
    $totalEceran  = (float) $row['qty_eceran'];
    $isiPerSatuan = $row['isi_per_satuan'] !== null ? (int) $row['isi_per_satuan'] : 0;
    $isiPerSatuan = $isiPerSatuan > 0 ? $isiPerSatuan : 1;

    // "stock" (kartu grosir) = jumlah dus UTUH yang bisa dijual grosir.
    // Sisa pcs yang belum cukup 1 dus tidak dihitung di sini.
    $stockGrosir = stokTersediaGrosir($totalEceran, $isiPerSatuan);
    // "stock_eceran" (kartu eceran) = seluruh total_eceran, karena dus utuh
    // pun bisa dipecah buat dijual satuan kecil.
    $stockEceran = stokTersediaEceran($totalEceran);

    $products[] = [
        'id'             => (int)   $row['id'],
        'name'           => $row['name'],
        'price'          => (float) $row['price'],
        'price_eceran'   => (float) $row['price_eceran'],
        'stock'          => (int)   $stockGrosir,
        'stock_eceran'   => (int)   $stockEceran,
        'category'       => $row['category'],
        'satuan'         => $row['satuan'] ?? 'pcs',
        'satuan_eceran'  => $row['satuan_eceran'],
        'isi_per_satuan' => $isiPerSatuan > 1 ? $isiPerSatuan : null,
    ];
}

mysqli_free_result($result);
mysqli_stmt_close($stmt);

echo json_encode($products);