<?php
// Endpoint AJAX: simpan tanda tangan + nama ke database
// Dipanggil dari modal "Tanda Tangan" di surat-laporan.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../includes/auth_guard.php';
require_once 'koneksi.php';

header('Content-Type: application/json');

const LABEL_CABANG_VALID = ['sodonghilir', 'sariwangi', 'manonjaya'];
const SLOT_ADMIN_VALID   = ['admin', 'bendahara', 'ketua'];

function jsonGagal($pesan)
{
    echo json_encode(['ok' => false, 'error' => $pesan]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonGagal('Metode tidak diizinkan.');
}

$role           = $_SESSION['role'] ?? '';
$isOperator     = $role === 'operator';
$operatorBranch = $_SESSION['branch'] ?? '';
$adminRole      = $_SESSION['admin_role'] ?? '';

// Slot yang BOLEH ditandatangani oleh sesi yang sedang login
$slotDiizinkan = $isOperator ? 'kasir' : $adminRole;

$tanggal   = $_POST['tanggal'] ?? '';
$cabang    = $_POST['cabang'] ?? '';
$slot      = $_POST['slot'] ?? '';
$nama      = trim($_POST['nama'] ?? '');
$signature = $_POST['signature'] ?? '';

// --- Validasi dasar ---
if ($tanggal === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    jsonGagal('Tanggal tidak valid.');
}
if (!in_array($cabang, LABEL_CABANG_VALID, true)) {
    jsonGagal('Cabang tidak valid.');
}
if ($slot === '' || ($slot !== 'kasir' && !in_array($slot, SLOT_ADMIN_VALID, true))) {
    jsonGagal('Slot tidak valid.');
}
if ($nama === '') {
    jsonGagal('Nama wajib diisi.');
}
if ($signature === '' || strpos($signature, 'data:image/png;base64,') !== 0) {
    jsonGagal('Tanda tangan belum digambar.');
}

// --- PENTING: hanya boleh menandatangani slot miliknya sendiri ---
if ($slot !== $slotDiizinkan) {
    jsonGagal('Anda tidak berhak menandatangani kolom ini.');
}

// --- Operator hanya boleh simpan untuk cabangnya sendiri ---
if ($isOperator && $cabang !== $operatorBranch) {
    jsonGagal('Cabang tidak sesuai dengan akun Anda.');
}

$sql = "INSERT INTO tanda_tangan_laporan (tanggal, cabang, slot, nama, signature)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE nama = VALUES(nama), signature = VALUES(signature)";

$stmt = mysqli_prepare($koneksi_kasir, $sql);
mysqli_stmt_bind_param($stmt, 'sssss', $tanggal, $cabang, $slot, $nama, $signature);
$sukses = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

if (!$sukses) {
    jsonGagal('Gagal menyimpan ke database.');
}

echo json_encode(['ok' => true]);