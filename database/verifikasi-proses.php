<?php
/**
 * verifikasi-proses.php
 * Endpoint AJAX dipanggil dari stok.js saat kasir menyimpan hasil verifikasi
 * faktual (Sesuai / Tidak Sesuai) dari modal pada halaman stok-barang.php.
 *
 * Input (POST):
 *   - verifikasi_id  (id baris verifikasi_stok periode berjalan)
 *   - status         ('sesuai' | 'tidak_sesuai')
 *   - qty_fisik      (wajib diisi jika status = tidak_sesuai, angka hasil hitung fisik
 *                      dalam satuan besar, misal Dus)
 *   - keterangan     (wajib diisi jika status = tidak_sesuai)
 *
 * Output: JSON { success: bool, message: string }
 *
 * Catatan: tabel verifikasi_stok ada di database db_kasir, jadi query
 * di sini pakai koneksi $koneksi_kasir. Qty sistem (live) diambil dari
 * db_mbg.stok_barang lewat $koneksi_mbg untuk disnapshot ke kolom
 * qty_sistem_saat_verifikasi, supaya nilai Selisih tidak berubah lagi
 * walau qty_barang terus bergerak (penjualan dsb) setelah verifikasi disimpan.
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/koneksi.php';

$respon = ['success' => false, 'message' => ''];

$verifikasi_id  = isset($_POST['verifikasi_id']) ? (int) $_POST['verifikasi_id'] : 0;
$status         = $_POST['status'] ?? '';
$keterangan     = trim($_POST['keterangan'] ?? '');
$qty_fisik_raw  = trim($_POST['qty_fisik'] ?? '');

$status_diizinkan = ['sesuai', 'tidak_sesuai'];

if ($verifikasi_id <= 0) {
    $respon['message'] = 'Data barang tidak valid.';
    echo json_encode($respon);
    exit;
}

if (!in_array($status, $status_diizinkan, true)) {
    $respon['message'] = 'Status verifikasi tidak valid.';
    echo json_encode($respon);
    exit;
}

if ($status === 'tidak_sesuai' && $keterangan === '') {
    $respon['message'] = 'Keterangan wajib diisi jika stok tidak sesuai.';
    echo json_encode($respon);
    exit;
}

if ($status === 'tidak_sesuai' && ($qty_fisik_raw === '' || !is_numeric($qty_fisik_raw) || (float) $qty_fisik_raw < 0)) {
    $respon['message'] = 'Qty hasil hitung fisik wajib diisi dengan angka valid jika stok tidak sesuai.';
    echo json_encode($respon);
    exit;
}

// Cari id_barang dari baris verifikasi ini, dipakai untuk ambil qty sistem live
$baris_verifikasi = mysqli_query(
    $koneksi_kasir,
    "SELECT id_barang FROM verifikasi_stok WHERE id = {$verifikasi_id} LIMIT 1"
);

if (!$baris_verifikasi || mysqli_num_rows($baris_verifikasi) === 0) {
    $respon['message'] = 'Data verifikasi tidak ditemukan.';
    echo json_encode($respon);
    exit;
}

$id_barang = (int) mysqli_fetch_assoc($baris_verifikasi)['id_barang'];

// Snapshot qty sistem (live) dari db_mbg.stok_barang di saat verifikasi disimpan,
// supaya Selisih yang tampil di tabel tidak ikut berubah kalau qty_barang
// bergerak lagi setelah ini (penjualan, dsb).
$qty_sistem_saat_ini = 0;
$hasil_qty_live = mysqli_query(
    $koneksi_mbg,
    "SELECT qty FROM stok_barang WHERE id = {$id_barang} LIMIT 1"
);
if ($hasil_qty_live && mysqli_num_rows($hasil_qty_live) > 0) {
    $qty_sistem_saat_ini = (float) mysqli_fetch_assoc($hasil_qty_live)['qty'];
}

// Nama kasir yang login, dipakai untuk jejak audit siapa yang memverifikasi
$nama_petugas = $_SESSION['nama'] ?? ($_SESSION['branch'] ?? 'Kasir');

$status_aman     = mysqli_real_escape_string($koneksi_kasir, $status);
$keterangan_aman = $status === 'tidak_sesuai'
    ? mysqli_real_escape_string($koneksi_kasir, $keterangan)
    : null;
$petugas_aman    = mysqli_real_escape_string($koneksi_kasir, $nama_petugas);

$set_keterangan = $keterangan_aman === null ? 'NULL' : "'{$keterangan_aman}'";

// qty_fisik & qty_sistem_saat_verifikasi hanya relevan (dan diisi) kalau tidak_sesuai;
// kalau sesuai, keduanya dikosongkan lagi karena tidak ada selisih untuk ditampilkan.
if ($status === 'tidak_sesuai') {
    $qty_fisik_aman = (float) $qty_fisik_raw;
    $set_qty_fisik  = $qty_fisik_aman;
    $set_qty_sistem = $qty_sistem_saat_ini;
} else {
    $set_qty_fisik  = 'NULL';
    $set_qty_sistem = 'NULL';
}

$sql = "UPDATE verifikasi_stok
        SET status_verifikasi         = '{$status_aman}',
            keterangan                = {$set_keterangan},
            qty_fisik                 = {$set_qty_fisik},
            qty_sistem_saat_verifikasi = {$set_qty_sistem},
            diverifikasi_oleh         = '{$petugas_aman}',
            diverifikasi_at           = NOW()
        WHERE id = {$verifikasi_id}";

if (mysqli_query($koneksi_kasir, $sql)) {
    $respon['success'] = true;
    $respon['message'] = 'Verifikasi berhasil disimpan.';
} else {
    $respon['message'] = 'Gagal menyimpan verifikasi: ' . mysqli_error($koneksi_kasir);
}

echo json_encode($respon);