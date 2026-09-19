<?php
while (ob_get_level()) { ob_end_clean(); }
ob_start();
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_USER_DEPRECATED);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../koneksi.php';
require_once __DIR__ . '/../../../aplikasi_kopdes/database/cloudinary_helper.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Sesi tidak valid']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$id = intval($data['id'] ?? $_POST['id'] ?? 0);
$type = trim($data['type'] ?? $_POST['type'] ?? 'bukti_tf'); // 'bukti_tf' atau 'tanda_tangan'

if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID Setoran tidak valid']);
    exit;
}

$column = ($type === 'tanda_tangan') ? 'tanda_tangan' : 'bukti_tf';
$uploadDir = ($type === 'tanda_tangan') ? __DIR__ . '/../../uploads/ttd/' : __DIR__ . '/../../uploads/bukti/';

try {
    $stmt = mysqli_prepare($conn, "SELECT id, $column FROM setoran WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if ($row && !empty($row[$column])) {
        delete_photo_asset($row[$column], $uploadDir);

        $stmtUp = mysqli_prepare($conn, "UPDATE setoran SET $column = NULL WHERE id = ?");
        mysqli_stmt_bind_param($stmtUp, 'i', $id);
        mysqli_stmt_execute($stmtUp);
        mysqli_stmt_close($stmtUp);
    }

    while (ob_get_level()) { ob_end_clean(); }
    echo json_encode(['success' => true, 'message' => 'Berkas berhasil dihapus secara permanen']);
    exit;
} catch (Throwable $e) {
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Gagal menghapus berkas: ' . $e->getMessage()]);
    exit;
}
