<?php
// laporan-setoran.php
session_start();
require_once __DIR__ . '/../../database/koneksi.php'; // sesuaikan path ke koneksi.php

if (!isset($_SESSION['branch'])) {
    header('Location: ../../index.php');
    exit;
}

$cabang  = $_SESSION['branch'];
$pesan   = '';
$error   = '';

// Folder penyimpanan file
$dirBukti = __DIR__ . '/../../uploads/bukti/';
$dirTtd   = __DIR__ . '/../../uploads/ttd/';
if (!is_dir($dirBukti)) mkdir($dirBukti, 0755, true);
if (!is_dir($dirTtd))   mkdir($dirTtd, 0755, true);

// ==== Proses submit form setoran ====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi']) && $_POST['aksi'] === 'simpan_setoran') {

    $saldo_kasir  = str_replace(['.', ','], ['', '.'], $_POST['saldo_kasir'] ?? '0');
    $omset_harian = str_replace(['.', ','], ['', '.'], $_POST['omset_harian'] ?? '0');
    $saldo_kasir  = is_numeric($saldo_kasir) ? (float) $saldo_kasir : 0;
    $omset_harian = is_numeric($omset_harian) ? (float) $omset_harian : 0;
    $tanggal      = date('Y-m-d');

    // --- Upload bukti transfer ---
    $namaBukti = null;
    if (!empty($_FILES['bukti_tf']['name']) && $_FILES['bukti_tf']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['bukti_tf']['name'], PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png', 'pdf'];
        if (in_array($ext, $allowedExt)) {
            $namaBukti = 'bukti_' . $cabang . '_' . date('Ymd_His') . '.' . $ext;
            move_uploaded_file($_FILES['bukti_tf']['tmp_name'], $dirBukti . $namaBukti);
        } else {
            $error = 'Format bukti transfer harus JPG, PNG, atau PDF.';
        }
    } else {
        $error = 'Bukti transfer wajib diunggah.';
    }

    // --- Simpan tanda tangan (base64 dari canvas) ---
    $namaTtd = null;
    if (empty($error) && !empty($_POST['tanda_tangan_data'])) {
        $data = $_POST['tanda_tangan_data'];
        if (preg_match('/^data:image\/(png|jpeg);base64,/', $data, $m)) {
            $data = substr($data, strpos($data, ',') + 1);
            $data = base64_decode($data);
            if ($data !== false) {
                $namaTtd = 'ttd_' . $cabang . '_' . date('Ymd_His') . '.png';
                file_put_contents($dirTtd . $namaTtd, $data);
            }
        } else {
            $error = 'Tanda tangan kasir wajib diisi.';
        }
    } elseif (empty($error)) {
        $error = 'Tanda tangan kasir wajib diisi.';
    }

    if (empty($error)) {
        $stmt = mysqli_prepare($koneksi_kasir,
            "INSERT INTO setoran (cabang, tanggal, saldo_kasir, omset_harian, bukti_tf, tanda_tangan, status)
             VALUES (?, ?, ?, ?, ?, ?, 'pending')");
        mysqli_stmt_bind_param($stmt, 'ssddss', $cabang, $tanggal, $saldo_kasir, $omset_harian, $namaBukti, $namaTtd);
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

// Pesan sukses setelah redirect
if (isset($_GET['sukses']) && $_GET['sukses'] === '1') {
    $pesan = 'Setoran berhasil disimpan.';
}

// ==== Ambil data setoran untuk tabel ====
$stmt = mysqli_prepare($koneksi_kasir,
    "SELECT id, tanggal, saldo_kasir, omset_harian, bukti_tf, tanda_tangan, status, created_at
     FROM setoran WHERE cabang = ? ORDER BY created_at DESC LIMIT 100");
mysqli_stmt_bind_param($stmt, 's', $cabang);
mysqli_stmt_execute($stmt);
$resultSetoran = mysqli_stmt_get_result($stmt);
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
                <button type="button" class="btn-open-modal" onclick="bukaModalSetoran()">+ Input Setoran</button>
            </div>

            <?php if ($pesan): ?><div class="alert alert-success"><?= htmlspecialchars($pesan) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <!-- 2 & 3. TABEL DATA SETORAN -->
            <div class="card">
                <h2>Riwayat Setoran</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Saldo Kasir</th>
                            <th>Omset Harian</th>
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
                                    <td><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                                    <td>Rp <?= number_format($row['saldo_kasir'], 0, ',', '.') ?></td>
                                    <td>Rp <?= number_format($row['omset_harian'], 0, ',', '.') ?></td>
                                    <td>
                                        <?php if ($row['bukti_tf']): ?>
                                            <a class="link-file" target="_blank"
                                               href="../../uploads/bukti/<?= htmlspecialchars($row['bukti_tf']) ?>">Lihat</a>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['tanda_tangan']): ?>
                                            <a class="link-file" target="_blank"
                                               href="../../uploads/ttd/<?= htmlspecialchars($row['tanda_tangan']) ?>">Lihat</a>
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
                            <tr><td colspan="7" class="empty-row">Belum ada data setoran.</td></tr>
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
                <div class="form-group">
                    <label for="saldo_kasir">Saldo Kasir (Rp) </br><small>Total uang fisik yang ada di laci kasir</small></label>
                    <input type="text" id="saldo_kasir" name="saldo_kasir" placeholder="0" inputmode="numeric" autocomplete="off" required>
                </div>
                <div class="form-group">
                    <label for="omset_harian">Omset Hari Ini (Rp) </br><small>Total omset hari ini</small></label>
                    <input type="text" id="omset_harian" name="omset_harian" placeholder="0" inputmode="numeric" autocomplete="off" required>
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
// ==== Format otomatis Rupiah untuk Saldo Kasir & Omset Harian ====
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
formatRupiahInput(document.getElementById('omset_harian'));

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
</body>
</html>