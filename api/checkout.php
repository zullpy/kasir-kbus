<?php
header('Content-Type: application/json');
session_start();
require_once '../database/koneksi.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method tidak diizinkan.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['items']) || !isset($input['discount_percent'], $input['cash'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap.']);
    exit;
}

$items           = $input['items'];
$discountPercent = max(0, min(100, (float) $input['discount_percent']));
$cashReceived    = (float) $input['cash'];

// Mapping nilai branch di session ke value kolom `lokasi` yang sebenarnya di DB.
// Harus SAMA PERSIS dengan mapping di get-products.php.
$lokasiMap = [
    'sodonghilir' => 'sodong',
    'sariwangi'   => 'sariwangi',
    'manonjaya'   => 'manonjaya',
];
$branchSession = $_SESSION['branch'] ?? '';
$lokasi        = $lokasiMap[$branchSession] ?? $branchSession;
$kasir         = $branchSession !== '' ? $branchSession : 'unknown';

if ($lokasi === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Lokasi cabang tidak ditemukan di sesi. Silakan login ulang.']);
    exit;
}

// ── Validasi tiap item: harga & id dari db_draft_barang, stok dari db_mbg.stok_barang ──
$validatedItems = [];
$subtotal = 0;

foreach ($items as $item) {
    $namaBarang = trim($item['name'] ?? '');
    $qty        = (float) ($item['qty'] ?? 0);
    $mode       = ($item['mode'] ?? 'grosir') === 'eceran' ? 'eceran' : 'grosir';

    if ($namaBarang === '' || $qty <= 0) {
        echo json_encode(['success' => false, 'message' => 'Data item tidak valid.']);
        exit;
    }

    // Ambil id, harga, dan faktor konversi dari db_draft_barang (sumber kebenaran harga)
    $stmtBarang = mysqli_prepare(
        $koneksi_draft,
        "SELECT id_barang, harga_jual, harga_jual_eceran, isi_per_satuan
         FROM barang WHERE nama_barang = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($stmtBarang, 's', $namaBarang);
    mysqli_stmt_execute($stmtBarang);
    $rowBarang = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtBarang));
    mysqli_stmt_close($stmtBarang);

    if (!$rowBarang) {
        echo json_encode(['success' => false, 'message' => "Barang \"$namaBarang\" tidak terdaftar di data barang."]);
        exit;
    }

    $idBarang     = (int) $rowBarang['id_barang'];
    $isiPerSatuan = $rowBarang['isi_per_satuan'] !== null ? (float) $rowBarang['isi_per_satuan'] : 0;

    // Harga mengikuti mode: grosir pakai harga_jual, eceran pakai harga_jual_eceran
    $hargaFinal = $mode === 'eceran' ? (float) $rowBarang['harga_jual_eceran'] : (float) $rowBarang['harga_jual'];

    if ($mode === 'eceran' && $isiPerSatuan <= 0) {
        echo json_encode(['success' => false, 'message' => "Barang \"$namaBarang\" belum punya konversi eceran (isi_per_satuan)."]);
        exit;
    }

    // Stok grosir (master) dari db_mbg.stok_barang, per lokasi
    $stmtStok = mysqli_prepare(
        $koneksi_mbg,
        "SELECT qty FROM stok_barang WHERE nama_barang = ? AND lokasi = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($stmtStok, 'ss', $namaBarang, $lokasi);
    mysqli_stmt_execute($stmtStok);
    $rowStok = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtStok));
    mysqli_stmt_close($stmtStok);

    $stokGrosirTersedia = $rowStok ? (float) $rowStok['qty'] : 0;

    // Kalau mode eceran, qty yang dikurangi dari stok grosir dalam bentuk pecahan dus
    $qtyDusTerpakai = $mode === 'eceran' ? ($qty / $isiPerSatuan) : $qty;

    if ($stokGrosirTersedia < $qtyDusTerpakai) {
        $stokEceranTersedia = $isiPerSatuan > 0 ? floor($stokGrosirTersedia * $isiPerSatuan) : 0;
        $sisaTampil = $mode === 'eceran' ? $stokEceranTersedia : $stokGrosirTersedia;
        echo json_encode([
            'success' => false,
            'message' => "Stok \"$namaBarang\" tidak cukup. Tersisa: $sisaTampil.",
        ]);
        exit;
    }

    $itemSubtotal = $hargaFinal * $qty;
    $subtotal    += $itemSubtotal;

    $validatedItems[] = [
        'id_barang'       => $idBarang,
        'nama'            => $namaBarang,
        'harga'           => $hargaFinal,
        'qty'             => $qty,
        'subtotal'        => $itemSubtotal,
        'qty_dus_terpakai' => $qtyDusTerpakai,
    ];
}

$discountAmount = round($subtotal * ($discountPercent / 100));
$total          = $subtotal - $discountAmount;
$kembalian      = $cashReceived - $total;

if ($cashReceived < $total) {
    echo json_encode(['success' => false, 'message' => 'Jumlah cash tidak mencukupi.']);
    exit;
}

// ── Generate kode transaksi: TRX-YYYYMMDD-XXXX ─────────────────────────────────
$tanggalHari = date('Ymd');
$stmtCount   = mysqli_prepare(
    $koneksi_kasir,
    "SELECT COUNT(*) AS total FROM transaksi WHERE DATE(tanggal) = CURDATE()"
);
mysqli_stmt_execute($stmtCount);
$rowCount      = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtCount));
mysqli_stmt_close($stmtCount);

$nomorUrut     = (int) $rowCount['total'] + 1;
$kodeTransaksi = 'TRX-' . $tanggalHari . '-' . str_pad($nomorUrut, 4, '0', STR_PAD_LEFT);
$tanggalNow    = date('Y-m-d H:i:s');

// ── Simpan transaksi (db_kasir) & kurangi stok (db_mbg) ────────────────────────
// Dua koneksi database berbeda -> masing-masing punya transaksinya sendiri.
// Kalau salah satu gagal, dua-duanya di-rollback biar tidak nyangkut setengah jalan.
mysqli_begin_transaction($koneksi_kasir);
mysqli_begin_transaction($koneksi_mbg);

try {
    $stmtTrx = mysqli_prepare(
        $koneksi_kasir,
        "INSERT INTO transaksi (kode_transaksi, tanggal, subtotal, diskon, total, cash, kembalian, kasir)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param(
        $stmtTrx, 'ssddddds',
        $kodeTransaksi, $tanggalNow,
        $subtotal, $discountAmount, $total,
        $cashReceived, $kembalian, $kasir
    );
    mysqli_stmt_execute($stmtTrx);
    $idTransaksi = mysqli_insert_id($koneksi_kasir);
    mysqli_stmt_close($stmtTrx);

    $stmtDetail = mysqli_prepare(
        $koneksi_kasir,
        "INSERT INTO transaksi_detail (id_transaksi, id_barang, nama_barang, harga, qty, subtotal)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmtKurangi = mysqli_prepare(
        $koneksi_mbg,
        "UPDATE stok_barang SET qty = qty - ? WHERE nama_barang = ? AND lokasi = ?"
    );

    foreach ($validatedItems as $item) {
        mysqli_stmt_bind_param(
            $stmtDetail, 'iisdid',
            $idTransaksi, $item['id_barang'], $item['nama'], $item['harga'], $item['qty'], $item['subtotal']
        );
        mysqli_stmt_execute($stmtDetail);

        mysqli_stmt_bind_param(
            $stmtKurangi, 'dss',
            $item['qty_dus_terpakai'], $item['nama'], $lokasi
        );
        mysqli_stmt_execute($stmtKurangi);
    }
    mysqli_stmt_close($stmtDetail);
    mysqli_stmt_close($stmtKurangi);

    mysqli_commit($koneksi_kasir);
    mysqli_commit($koneksi_mbg);
} catch (Exception $e) {
    mysqli_rollback($koneksi_kasir);
    mysqli_rollback($koneksi_mbg);
    error_log('Checkout gagal: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Transaksi gagal disimpan. Silakan coba lagi.']);
    exit;
}

echo json_encode([
    'success'        => true,
    'kode_transaksi' => $kodeTransaksi,
    'subtotal'       => $subtotal,
    'diskon'         => $discountAmount,
    'total'          => $total,
    'cash'           => $cashReceived,
    'change'         => $kembalian,
    'print'          => ['success' => false],
]);