<?php
// laporan-setoran.php
session_start();
require_once __DIR__ . '/../../database/koneksi.php'; // sesuaikan path ke koneksi.php
require_once __DIR__ . '/../../database/cloudinary_helper.php';

$__role = $_SESSION['role'] ?? '';
$__isAdmin = $__role === 'admin';

if (!$__isAdmin && !isset($_SESSION['branch'])) {
    header('Location: ../../index.php');
    exit;
}

$cabang  = $_SESSION['branch'] ?? '';
$pesan   = '';
$error   = '';

// Folder penyimpanan file
$dirBukti = __DIR__ . '/../../uploads/bukti/';
$dirTtd   = __DIR__ . '/../../uploads/ttd/';
if (!is_dir($dirBukti)) mkdir($dirBukti, 0755, true);
if (!is_dir($dirTtd))   mkdir($dirTtd, 0755, true);

// ==== Proses submit form setoran ====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi']) && $_POST['aksi'] === 'simpan_setoran') {
    if ($__isAdmin) {
        $error = 'Admin tidak diperbolehkan menginput setoran.';
    } else {
        $saldo_kasir      = str_replace(['.', ','], ['', '.'], $_POST['saldo_kasir'] ?? '0');
        $setoran_koperasi = str_replace(['.', ','], ['', '.'], $_POST['setoran_koperasi'] ?? '0');
        $saldo_kasir      = is_numeric($saldo_kasir) ? (float) $saldo_kasir : 0;
        $setoran_koperasi = is_numeric($setoran_koperasi) ? (float) $setoran_koperasi : 0;
        $tanggal      = date('Y-m-d');

        // --- Upload bukti transfer ---
        $namaBukti = null;
        if (!empty($_FILES['bukti_tf']['name']) && $_FILES['bukti_tf']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['bukti_tf']['name'], PATHINFO_EXTENSION));
            $allowedExt = ['jpg', 'jpeg', 'png', 'pdf'];
            if (in_array($ext, $allowedExt)) {
                try {
                    $namaBukti = smart_upload_foto($_FILES['bukti_tf'], 'bukti', $dirBukti, 'bukti_' . $cabang);
                } catch (Exception $e) {
                    $error = 'Gagal mengunggah bukti transfer: ' . $e->getMessage();
                }
            } else {
                $error = 'Format bukti transfer harus JPG, PNG, atau PDF.';
            }
        } else {
            $error = 'Bukti transfer wajib diunggah.';
        }

        // --- Simpan tanda tangan (base64 dari canvas) ---
        $namaTtd = null;
        if (empty($error) && !empty($_POST['tanda_tangan_data'])) {
            try {
                $namaTtd = smart_upload_base64($_POST['tanda_tangan_data'], 'ttd', $dirTtd, 'ttd_' . $cabang);
            } catch (Exception $e) {
                $error = 'Gagal menyimpan tanda tangan: ' . $e->getMessage();
            }
        } elseif (empty($error)) {
            $error = 'Tanda tangan kasir wajib diisi.';
        }

        if (empty($error)) {
            $stmt = mysqli_prepare($koneksi_kasir,
                "INSERT INTO setoran (cabang, tanggal, saldo_kasir, setoran_koperasi, bukti_tf, tanda_tangan, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending')");
            mysqli_stmt_bind_param($stmt, 'ssddss', $cabang, $tanggal, $saldo_kasir, $setoran_koperasi, $namaBukti, $namaTtd);
            $sukses = mysqli_stmt_execute($stmt);
            if (!$sukses) {
                $error = 'Gagal menyimpan setoran: ' . mysqli_error($koneksi_kasir);
            }
            mysqli_stmt_close($stmt);

            if ($sukses) {
                // Redirect supaya reload (F5) tidak submit ulang form yang sama
                header('Location: ' . $_SERVER['PHP_SELF'] . '?sukses=1');
                exit;
            }
        }
    }
}

// Pesan sukses setelah redirect
if (isset($_GET['sukses']) && $_GET['sukses'] === '1') {
    $pesan = 'Setoran berhasil disimpan.';
}

// ==== Ambil data setoran untuk tabel ====
if ($__isAdmin) {
    $resultSetoran = mysqli_query($koneksi_kasir,
        "SELECT id, cabang, tanggal, saldo_kasir, setoran_koperasi, bukti_tf, tanda_tangan, status, created_at
         FROM setoran ORDER BY created_at DESC LIMIT 100");
} else {
    $stmt = mysqli_prepare($koneksi_kasir,
        "SELECT id, cabang, tanggal, saldo_kasir, setoran_koperasi, bukti_tf, tanda_tangan, status, created_at
         FROM setoran WHERE cabang = ? ORDER BY created_at DESC LIMIT 100");
    mysqli_stmt_bind_param($stmt, 's', $cabang);
    mysqli_stmt_execute($stmt);
    $resultSetoran = mysqli_stmt_get_result($stmt);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Laporan Setoran - KBUS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="shortcut icon" href="../../assets/favicon.ico" type="image/x-icon">
<link rel="stylesheet" href="../style.css">
<style>
    .card { background:#fff; border: 1px solid var(--line); border-radius: 10px; padding: 22px 24px; margin-bottom: 26px; }
    .card h2 { font-family: 'Space Grotesk', sans-serif; font-size: 14px; font-weight: 600; margin: 0 0 16px; color: var(--ink); }

    .alert { padding: 10px 14px; border-radius: 8px; font-size: 14px; margin-bottom: 16px; }
    .alert-success { background:#e6f6ec; color:#1a7f42; }
    .alert-error   { background:#fdecec; color:#c62828; }

    .form-grid { display:grid; grid-template-columns: 1fr 1fr; gap: 18px 20px; }
    .form-group { display:flex; flex-direction:column; gap:6px; }
    .form-group.full { grid-column: 1 / -1; }
    .tanggal-jam-box {
        border: 1px solid #d7dbe3; border-radius: 8px; padding: 9px 12px; font-size: 14px;
        font-family: 'IBM Plex Mono', monospace; background:#f8f9fb; color:#374151;
    }
    label { font-size: 13px; font-weight: 600; color:#374151; }
    input[type="text"], input[type="number"], input[type="file"] {
        border: 1px solid #d7dbe3; border-radius: 8px; padding: 9px 12px; font-size: 14px; font-family: inherit;
    }
    input:focus { outline: none; border-color:#3b82f6; }

    .ttd-box { border: 1px dashed #c9ced9; border-radius: 10px; padding: 10px; background:#fafbfc; }
    canvas#pad { width:100%; height:150px; background:#fff; border-radius:6px; border:1px solid #e5e7eb; touch-action:none; cursor:crosshair; }
    .ttd-actions { display:flex; justify-content: space-between; margin-top: 8px; }
    .btn-clear { background:none; border:none; color:#6b7280; font-size:13px; cursor:pointer; text-decoration:underline; }

    .btn-submit {
        margin-top: 20px; background:#2563eb; color:#fff; border:none; border-radius: 9px;
        padding: 11px 22px; font-size: 14px; font-weight:600; cursor:pointer;
    }
    .btn-submit:hover { background:#1d4ed8; }

    table { width:100%; border-collapse: collapse; font-size: 13.5px; }
    th, td { padding: 10px 12px; text-align:left; border-bottom: 1px solid #eef0f4; }
    th { color:#6b7280; font-weight:600; background:#f8f9fb; }
    tbody tr:hover { background:#f9fafc; }
    .badge-pembayaran-pending { background:#fff3d6; color:#9a6b00; }
    .badge-pembayaran-ok { background:#e6f6ec; color:#1a7f42; }
    .link-file { color:#2563eb; text-decoration:none; font-size:13px; }
    .link-file:hover { text-decoration:underline; }
    .empty-row { text-align:center; color:#9aa1ae; padding: 24px; }

    .page-header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; }

    .btn-open-modal {
        background:#2563eb; color:#fff; border:none; border-radius: 9px;
        padding: 10px 18px; font-size: 14px; font-weight:600; cursor:pointer;
    }
    .btn-open-modal:hover { background:#1d4ed8; }

    .modal-overlay {
        display:none; position:fixed; inset:0; background:rgba(15,20,30,.5);
        align-items:center; justify-content:center; z-index:50; padding:20px;
    }
    .modal-overlay.show { display:flex; }
    .modal-box {
        background:#fff; border-radius: 12px; padding: 24px 26px; width: 100%;
        max-width: 560px; max-height: 90vh; overflow-y: auto;
    }
    .modal-box-header { display:flex; align-items:center; justify-content:space-between; margin-bottom: 16px; }
    .modal-box-header h2 { margin:0; }
    .btn-close-modal { background:none; border:none; font-size: 20px; line-height:1; color:#9aa1ae; cursor:pointer; padding:2px 6px; }
    .btn-close-modal:hover { color:#374151; }

    /* Modal Preview Persis Dompet Harian (100% Identik) */
    .modal-overlay-dompet {
        position: fixed !important; top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important;
        background: rgba(15, 23, 42, 0.6) !important; backdrop-filter: blur(4px) !important;
        -webkit-backdrop-filter: blur(4px) !important; z-index: 99999 !important;
        display: none; align-items: center !important; justify-content: center !important;
        padding: 1rem !important; box-sizing: border-box !important;
    }
    .modal-overlay-dompet.active,
    .modal-overlay-dompet[style*="display: flex"] {
        display: flex !important;
    }
    .modal-dompet {
        background: #ffffff !important; border-radius: 16px !important; width: 95vw !important;
        max-width: 640px !important; max-height: calc(100vh - 40px) !important;
        display: flex !important; flex-direction: column !important; overflow: hidden !important;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04) !important;
        animation: modalInDompet 0.25s cubic-bezier(0.16, 1, 0.3, 1) !important;
        position: relative !important; padding: 0 !important; border: none !important; margin: 0 !important;
        box-sizing: border-box !important;
    }
    @keyframes modalInDompet {
        from { opacity: 0; transform: translateY(16px) scale(0.98); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }
    .modal-dompet .modal-header {
        display: flex !important; align-items: center !important; justify-content: space-between !important;
        padding: 1.25rem 1.5rem !important; background: linear-gradient(135deg, #2563a8 0%, #4a9fd4 100%) !important;
        border-radius: 16px 16px 0 0 !important; flex-shrink: 0 !important; z-index: 10 !important;
        margin: 0 !important; border: none !important; width: 100% !important; box-sizing: border-box !important;
    }
    .modal-dompet .modal-header-left { display: flex !important; align-items: center !important; gap: 0.75rem !important; }
    .modal-dompet .modal-header-icon {
        width: 36px !important; height: 36px !important; display: flex !important; align-items: center !important;
        justify-content: center !important; background: rgba(255, 255, 255, 0.15) !important;
        border-radius: 8px !important; flex-shrink: 0 !important;
    }
    .modal-dompet .modal-title {
        font-size: 1rem !important; font-weight: 700 !important; color: #ffffff !important;
        font-family: inherit !important; margin: 0 !important; letter-spacing: normal !important;
    }
    .modal-dompet .modal-close {
        width: 34px !important; height: 34px !important; display: flex !important; align-items: center !important;
        justify-content: center !important; background: rgba(255, 255, 255, 0.12) !important;
        border: none !important; border-radius: 8px !important; cursor: pointer !important;
        transition: all 0.2s ease !important; color: #ffffff !important; padding: 0 !important;
    }
    .modal-dompet .modal-close:hover { background: rgba(255, 255, 255, 0.22) !important; }
    .modal-dompet .nota-modal-body {
        display: flex !important; flex-direction: column !important; gap: 1.25rem !important;
        max-height: 65vh !important; overflow-y: auto !important; padding: 1.25rem 1.5rem !important;
        background: #ffffff !important; margin: 0 !important; box-sizing: border-box !important;
    }
    .modal-dompet .nota-preview-item {
        display: flex !important; flex-direction: column !important; gap: 0.65rem !important;
        background: #f8fafc !important; border: 1px solid #e2e8f0 !important; border-radius: 12px !important;
        padding: 1rem !important; box-sizing: border-box !important;
    }
    .modal-dompet .nota-preview-label {
        display: flex !important; align-items: center !important; gap: 0.4rem !important;
        font-size: 0.8rem !important; font-weight: 700 !important; color: #2563eb !important;
        text-transform: uppercase !important; letter-spacing: 0.4px !important;
    }
    .modal-dompet .nota-preview-img {
        width: 100% !important; max-height: 420px !important; object-fit: contain !important;
        border-radius: 8px !important; border: 1px solid #e2e8f0 !important; background: #ffffff !important;
        cursor: zoom-in !important; transition: opacity 0.2s !important; display: block !important; margin: 0 auto !important;
    }
    .modal-dompet .nota-preview-img:hover { opacity: 0.9 !important; }
    .modal-dompet .nota-preview-pdf-wrap {
        width: 100% !important; height: 420px !important; border-radius: 8px !important;
        overflow: hidden !important; border: 1px solid #e2e8f0 !important;
    }
    .modal-dompet .nota-preview-pdf { width: 100% !important; height: 100% !important; border: none !important; }
    .modal-dompet .btn-delete-nota {
        display: inline-flex !important; align-items: center !important; gap: 6px !important;
        padding: 6px 12px !important; background: #fef2f2 !important; color: #ef4444 !important;
        border: 1px solid #fee2e2 !important; border-radius: 6px !important; font-size: 12px !important;
        font-weight: 600 !important; cursor: pointer !important; transition: all 0.15s ease !important;
        width: auto !important;
    }
    .modal-dompet .btn-delete-nota:hover { background: #fee2e2 !important; color: #dc2626 !important; }
    .modal-dompet .modal-footer {
        display: flex !important; align-items: center !important; justify-content: flex-end !important;
        gap: 0.6rem !important; padding: 1rem 1.5rem 1.25rem !important; background: #ffffff !important;
        border-top: 1px solid #e2e8f0 !important; flex-shrink: 0 !important; border-radius: 0 0 16px 16px !important;
        z-index: 10 !important; margin: 0 !important; box-sizing: border-box !important;
    }
    .modal-dompet .btn-cancel {
        height: 40px !important; padding: 0 1.2rem !important; border: 1.5px solid #e2e8f0 !important;
        background: #ffffff !important; border-radius: 8px !important; font-size: 0.87rem !important;
    .modal-dompet .btn-cancel:hover { background: #f8fafc !important; border-color: #cbd5e1 !important; color: #1e293b !important; }

    /* SweetAlert2 Theme Persis Dompet Harian & Di Depan Modal */
    .swal2-container {
        z-index: 99999999 !important;
    }
    .swal-kopdes {
        border-radius: 16px !important; font-family: inherit !important;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15) !important; padding: 1.8rem !important;
    }
    .swal-kopdes .swal2-title { font-size: 1.15rem !important; font-weight: 700 !important; color: #1e293b !important; }
    .swal-kopdes .swal2-html-container,
    .swal-kopdes .swal2-content { font-size: 0.875rem !important; color: #64748b !important; }
    .swal-kopdes .swal2-confirm,
    .swal-kopdes .swal2-cancel {
        border-radius: 8px !important; font-size: 0.85rem !important; font-weight: 600 !important; padding: 0.5rem 1.2rem !important;
    }
</style>
</head>
<body>

<div class="pos-root">
    <?php include __DIR__ . '/../../partials/sidebar.php'; ?>

    <div class="pos-main">
        <main class="page-main">
            <div class="page-header">
                <div>
                    <h1>Laporan Setoran</h1>
                    <p class="subtitle">Input setoran harian dan riwayat setoran kasir</p>
                </div>
                <?php if (!$__isAdmin): ?>
                <button type="button" class="btn-open-modal" onclick="bukaModalSetoran()">+ Input Setoran</button>
                <?php endif; ?>
            </div>

            <?php if ($pesan): ?><div class="alert alert-success"><?= htmlspecialchars($pesan) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <!-- 2 & 3. TABEL DATA SETORAN -->
            <div class="card">
                <h2>Riwayat Setoran</h2>
                <table>
                    <thead>
                        <tr>
                            <?php if ($__isAdmin): ?>
                                <th>Cabang</th>
                            <?php endif; ?>
                            <th>Tanggal</th>
                            <th>Saldo Kasir</th>
                            <th>Setoran ke Koperasi</th>
                            <th>Bukti TF</th>
                            <th>Tanda Tangan</th>
                            <th>Status</th>
                            <th>Waktu Input</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($resultSetoran) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($resultSetoran)): ?>
                                <tr>
                                    <?php if ($__isAdmin): ?>
                                        <td><?= htmlspecialchars($row['cabang']) ?></td>
                                    <?php endif; ?>
                                    <td><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                                    <td>Rp <?= number_format($row['saldo_kasir'], 0, ',', '.') ?></td>
                                    <td>Rp <?= number_format($row['setoran_koperasi'], 0, ',', '.') ?></td>
                                    <td id="cell-bukti-<?= $row['id'] ?>">
                                        <?php if ($row['bukti_tf']): ?>
                                            <a class="link-file" href="javascript:void(0)"
                                               onclick="openPreviewBuktiSetoran('<?= htmlspecialchars(resolve_photo_url($row['bukti_tf'], '../../uploads/bukti/')) ?>', <?= (int)$row['id'] ?>, 'bukti_tf', '<?= htmlspecialchars($row['cabang'], ENT_QUOTES) ?>', '<?= date('d/m/Y', strtotime($row['tanggal'])) ?>')">Lihat</a>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                    <td id="cell-ttd-<?= $row['id'] ?>">
                                        <?php if ($row['tanda_tangan']): ?>
                                            <a class="link-file" href="javascript:void(0)"
                                               onclick="openPreviewBuktiSetoran('<?= htmlspecialchars(resolve_photo_url($row['tanda_tangan'], '../../uploads/ttd/')) ?>', <?= (int)$row['id'] ?>, 'tanda_tangan', '<?= htmlspecialchars($row['cabang'], ENT_QUOTES) ?>', '<?= date('d/m/Y', strtotime($row['tanggal'])) ?>')">Lihat</a>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['status'] === 'diverifikasi'): ?>
                                            <span class="badge badge-pembayaran-ok">Diverifikasi</span>
                                        <?php else: ?>
                                            <span class="badge badge-pembayaran-pending">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="<?= $__isAdmin ? 8 : 7 ?>" class="empty-row">Belum ada data setoran.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<!-- Modal Input Setoran -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal-box">
        <div class="modal-box-header">
            <h2>Input Setoran</h2>
            <button type="button" class="btn-close-modal" onclick="tutupModalSetoran()">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="formSetoran">
            <input type="hidden" name="aksi" value="simpan_setoran">
            <input type="hidden" name="tanda_tangan_data" id="tanda_tangan_data">

            <div class="form-grid">
                <div class="form-group full">
                    <label>Tanggal &amp; Jam Input</label>
                    <div class="tanggal-jam-box" id="tanggalJamBox">--</div>
                </div>

                <div class="form-group">
                    <label for="saldo_kasir">Saldo Kasir (Rp) </br><small>Total uang fisik yang ada di laci kasir</small></label>
                    <input type="text" id="saldo_kasir" name="saldo_kasir" placeholder="0" inputmode="numeric" autocomplete="off" required>
                </div>
                <div class="form-group">
                    <label for="setoran_koperasi">Uang Disetor ke Rekening Koperasi (Rp) </label>
                    <input type="text" id="setoran_koperasi" name="setoran_koperasi" placeholder="0" inputmode="numeric" autocomplete="off" required>
                </div>

                <div class="form-group full">
                    <label for="bukti_tf">Bukti Transfer (png, jpg)</label>
                    <input type="file" id="bukti_tf" name="bukti_tf" accept=".jpg,.jpeg,.png,.pdf" required>
                </div>

                <div class="form-group full">
                    <label>Tanda Tangan Kasir</label>
                    <div class="ttd-box">
                        <canvas id="pad"></canvas>
                        <div class="ttd-actions">
                            <button type="button" class="btn-clear" id="btnClear">Hapus &amp; ulangi</button>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-submit">Simpan Setoran</button>
        </form>
    </div>
</div>

<script>
// ==== Tanggal & Jam otomatis (berjalan sendiri) di dalam modal ====
const tanggalJamBox = document.getElementById('tanggalJamBox');
function updateTanggalJam() {
    const now = new Date();
    const tanggal = now.toLocaleDateString('id-ID', {
        weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
    });
    const jam = now.toLocaleTimeString('id-ID', { hour12: false });
    tanggalJamBox.textContent = tanggal + ' - ' + jam;
}
updateTanggalJam();
setInterval(updateTanggalJam, 1000);

// ==== Format otomatis Rupiah untuk Saldo Kasir & Setoran ke Koperasi ====
function formatRupiahInput(el) {
    el.addEventListener('input', function () {
        let cursorFromEnd = el.value.length - el.selectionStart;
        let angka = el.value.replace(/\D/g, ''); // buang semua selain digit
        angka = angka.replace(/^0+(?=\d)/, '');  // buang nol di depan
        el.value = angka ? angka.replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '';
        let pos = el.value.length - cursorFromEnd;
        el.setSelectionRange(pos, pos);
    });
}
formatRupiahInput(document.getElementById('saldo_kasir'));
formatRupiahInput(document.getElementById('setoran_koperasi'));

// ==== Signature pad sederhana (tanda tangan kasir) ====
const canvas = document.getElementById('pad');
const ctx = canvas.getContext('2d');
let drawing = false;

function resizeCanvas() {
    const ratio = window.devicePixelRatio || 1;
    canvas.width = canvas.clientWidth * ratio;
    canvas.height = canvas.clientHeight * ratio;
    ctx.scale(ratio, ratio);
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#1c2536';
}

function getPos(e) {
    const rect = canvas.getBoundingClientRect();
    const point = e.touches ? e.touches[0] : e;
    return { x: point.clientX - rect.left, y: point.clientY - rect.top };
}

function start(e) { drawing = true; const p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
function move(e) {
    if (!drawing) return;
    e.preventDefault();
    const p = getPos(e);
    ctx.lineTo(p.x, p.y); ctx.stroke();
}
function end() { drawing = false; }

canvas.addEventListener('mousedown', start);
canvas.addEventListener('mousemove', move);
canvas.addEventListener('mouseup', end);
canvas.addEventListener('mouseleave', end);
canvas.addEventListener('touchstart', start);
canvas.addEventListener('touchmove', move);
canvas.addEventListener('touchend', end);

document.getElementById('btnClear').addEventListener('click', function () {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
});

document.getElementById('formSetoran').addEventListener('submit', function (e) {
    // pastikan ada tanda tangan sebelum submit
    const blank = document.createElement('canvas');
    blank.width = canvas.width; blank.height = canvas.height;
    if (canvas.toDataURL() === blank.toDataURL()) {
        e.preventDefault();
        alert('Tanda tangan kasir wajib diisi sebelum menyimpan.');
        return;
    }
    document.getElementById('tanda_tangan_data').value = canvas.toDataURL('image/png');
});

// ==== Buka / tutup modal input setoran ====
const modalOverlay = document.getElementById('modalOverlay');

function bukaModalSetoran() {
    modalOverlay.classList.add('show');
    setTimeout(resizeCanvas, 50); // canvas butuh ukuran nyata dulu, jadi resize setelah modal tampil
}
function tutupModalSetoran() {
    modalOverlay.classList.remove('show');
}
modalOverlay.addEventListener('click', function (e) {
    if (e.target === modalOverlay) tutupModalSetoran();
});

<?php if ($error): ?>
// Buka ulang modal otomatis kalau submit sebelumnya gagal, biar pesan errornya kelihatan
bukaModalSetoran();
<?php endif; ?>
</script>

<script>
    // Tanggal berjalan di footer sidebar (sesuai elemen #posDate pada sidebar.php)
    const posDate = document.getElementById('posDate');
    if (posDate) {
        posDate.textContent = new Date().toLocaleDateString('id-ID', {
            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
        });
    }
</script>

<!-- MODAL PREVIEW BUKTI SETORAN PERSIS SEPERTI DOMPET HARIAN -->
<div id="setoranPreviewModal" class="modal-overlay-dompet" style="display:none;">
    <div class="modal-dompet">
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-header-icon">
                    <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
                        <rect x="1" y="2.5" width="16" height="13" rx="1.5" stroke="#fff" stroke-width="1.5" />
                        <circle cx="5.5" cy="8" r="1.5" fill="#fff" />
                        <path d="M1 15l5-5 3 3 2.5-2.5L17 15" stroke="#fff" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
                <div class="modal-title" id="setoranPreviewTitle">Bukti Transfer Setoran</div>
            </div>
            <button type="button" class="modal-close" onclick="closePreviewSetoran()" aria-label="Tutup">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                    <path d="M2 2L12 12M12 2L2 12" stroke="#fff" stroke-width="1.8" stroke-linecap="round" />
                </svg>
            </button>
        </div>
        <div class="nota-modal-body" id="setoranPreviewBody"></div>
        <div class="modal-footer">
            <button type="button" onclick="closePreviewSetoran()" class="btn-cancel">Tutup</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function closePreviewSetoran() {
    const modal = document.getElementById('setoranPreviewModal');
    if (modal) modal.style.display = 'none';
    const body = document.getElementById('setoranPreviewBody');
    if (body) body.innerHTML = '';
}

document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('setoranPreviewModal');
    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closePreviewSetoran();
        });
    }
});

function openPreviewBuktiSetoran(url, id, type, cabang, tanggal) {
    if (!url) return;
    const modal = document.getElementById('setoranPreviewModal');
    const title = document.getElementById('setoranPreviewTitle');
    const body = document.getElementById('setoranPreviewBody');
    if (!modal || !body) return;

    const isBuktiTf = (type === 'bukti_tf');
    const labelTitle = isBuktiTf ? `Bukti Transfer — ${cabang || 'Setoran'} (${tanggal || ''})` : `Tanda Tangan — ${cabang || 'Setoran'} (${tanggal || ''})`;
    if (title) title.textContent = labelTitle;

    const isPdf = url.toLowerCase().split('?')[0].endsWith('.pdf');
    const labelBtn = isBuktiTf ? 'Hapus Bukti' : 'Hapus TTD';
    const itemLabel = isBuktiTf ? 'Bukti Transfer' : 'Tanda Tangan';

    body.innerHTML = `
        <div class="nota-preview-item">
            <div class="nota-preview-label">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                    <rect x="1" y="2" width="12" height="10" rx="1.2" stroke="currentColor" stroke-width="1.4"/>
                    <circle cx="4.5" cy="6" r="1.2" fill="currentColor"/>
                    <path d="M1 12l4-4 2.5 2.5 2-2L13 12" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                ${itemLabel}
            </div>
            ${isPdf 
                ? `<div class="nota-preview-pdf-wrap"><embed src="${url}" type="application/pdf" class="nota-preview-pdf"></div>`
                : `<img src="${url}" alt="Preview" class="nota-preview-img" onclick="window.open('${url}', '_blank')">`
            }
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:8px;">
                <button type="button" class="btn-delete-nota" onclick="hapusFileSetoran(${id}, '${type}', '${url}')">
                    <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                        <path d="M2 3.5h9M5 3.5V2.5a.5.5 0 0 1 .5-.5h2a.5.5 0 0 1 .5.5v1M5.5 6v3.5M7.5 6v3.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                        <path d="M3 3.5l.7 7a.5.5 0 0 0 .5.5h4.6a.5.5 0 0 0 .5-.5l.7-7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    ${labelBtn}
                </button>
            </div>
        </div>
    `;

    modal.style.display = 'flex';
}

async function hapusFileSetoran(id, type, url) {
    const isBuktiTf = (type === 'bukti_tf');
    const label = isBuktiTf ? 'Bukti Transfer' : 'Tanda Tangan';

    const result = await Swal.fire({
        title: `Hapus ${label}?`,
        text: `File fisik ${label.toLowerCase()} di Cloudinary/server akan ikut terhapus permanen. Tindakan ini tidak dapat dibatalkan!`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Hapus Permanen',
        cancelButtonText: 'Batal',
        customClass: { popup: 'swal-kopdes' },
        didOpen: () => {
            const container = document.querySelector('.swal2-container');
            if (container) container.style.zIndex = '99999999';
        }
    });

    if (!result.isConfirmed) return;

    Swal.fire({
        title: 'Menghapus...',
        text: 'Sedang menghapus file fisik...',
        allowOutsideClick: false,
        customClass: { popup: 'swal-kopdes' },
        didOpen: () => {
            const container = document.querySelector('.swal2-container');
            if (container) container.style.zIndex = '99999999';
            Swal.showLoading();
        }
    });

    try {
        const res = await fetch('hapus-bukti-setoran.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, type: type })
        });
        const json = await res.json();
        if (json.success) {
            closePreviewSetoran();
            Swal.fire({
                icon: 'success',
                title: 'Berhasil Dihapus',
                text: `File fisik ${label.toLowerCase()} telah dihapus.`,
                timer: 1500,
                showConfirmButton: false
            });

            // Update cell di tabel live tanpa reload
            const cellId = isBuktiTf ? `cell-bukti-${id}` : `cell-ttd-${id}`;
            const cell = document.getElementById(cellId);
            if (cell) cell.innerHTML = '—';
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message || 'Terjadi kesalahan' });
        }
    } catch (err) {
        Swal.fire({ icon: 'error', title: 'Error', text: err.message });
    }
}
</script>
</body>
</html>