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
    // Default lokasi ke 'sodong' jika session branch kosong (misal login sebagai admin)
    $lokasi = 'sodong';
}

// 1. Ambil data harga, kategori, & isi_per_satuan dari db_draft_barang (via $koneksi_draft)
$petaBarang = [];
$resBarang = mysqli_query($koneksi_draft, "SELECT nama_barang, harga_jual, harga_jual_eceran, kategori, isi_per_satuan FROM barang");
if ($resBarang) {
    while ($b = mysqli_fetch_assoc($resBarang)) {
        $kunci = strtolower(trim(preg_replace('/\s+/', ' ', $b['nama_barang'])));
        $petaBarang[$kunci] = $b;
    }
}

// 2. Ambil data stok dari db_mbg.stok_barang (via $koneksi_mbg)
$query = "
    SELECT
        nama_barang,
        satuan,
        satuan_eceran,
        qty_eceran
    FROM stok_barang
    WHERE LOWER(lokasi) = LOWER(?)
    ORDER BY nama_barang ASC
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
$idCounter = 1;
while ($row = mysqli_fetch_assoc($result)) {
    $kunci = strtolower(trim(preg_replace('/\s+/', ' ', $row['nama_barang'])));
    $infoBarang = $petaBarang[$kunci] ?? null;

    $price        = (float) ($infoBarang['harga_jual'] ?? 0);
    $price_eceran = (float) ($infoBarang['harga_jual_eceran'] ?? 0);
    $category     = $infoBarang['kategori'] ?? 'Umum';
    $isiPerSatuan = isset($infoBarang['isi_per_satuan']) ? (int) $infoBarang['isi_per_satuan'] : 1;
    $isiPerSatuan = $isiPerSatuan > 0 ? $isiPerSatuan : 1;

    $totalEceran  = (float) $row['qty_eceran'];

    // "stock" (kartu grosir) = jumlah dus UTUH yang bisa dijual grosir.
    $stockGrosir = stokTersediaGrosir($totalEceran, $isiPerSatuan);
    // "stock_eceran" (kartu eceran) = seluruh total_eceran, karena dus utuh pun bisa dipecah
    $stockEceran = stokTersediaEceran($totalEceran);

    $products[] = [
        'id'             => $idCounter++,
        'name'           => $row['nama_barang'],
        'price'          => $price,
        'price_eceran'   => $price_eceran,
        'stock'          => (int)   $stockGrosir,
        'stock_eceran'   => (int)   $stockEceran,
        'category'       => $category,
        'satuan'         => $row['satuan'] ?? 'pcs',
        'satuan_eceran'  => $row['satuan_eceran'],
        'isi_per_satuan' => $isiPerSatuan > 1 ? $isiPerSatuan : null,
    ];
}

mysqli_free_result($result);
mysqli_stmt_close($stmt);

echo json_encode($products);