<?php
// Halaman Laporan Harian (dikelompokkan per tanggal)
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../../includes/auth_guard.php';
require_once '../../database/koneksi.php';

/**
 * PENTING - SESUAIKAN INI:
 * Kode di bawah mengasumsikan tabel `transaksi` punya kolom metode pembayaran
 * bernama `metode_pembayaran`. Nilai untuk cash ada di NILAI_TUNAI, dan semua
 * nilai yang dianggap "masuk rekening" (transfer, QRIS, debit, dst) ada di
 * array NILAI_REKENING di bawah — boleh isi satu atau lebih.
 *
 * Kalau nama kolom atau nilainya berbeda di database Anda, cukup ubah bagian
 * ini, tidak perlu ubah query.
 */
const KOLOM_METODE   = 'metode_pembayaran';
const NILAI_TUNAI    = 'cash';
const NILAI_REKENING = ['transfer', 'qris']; // tambah/kurangi nilai di sini kalau perlu

// Label cabang, dipakai untuk tampilan (bukan untuk query)
const LABEL_CABANG = [
    'sodonghilir' => 'Sodonghilir',
    'sariwangi'   => 'Sariwangi',
    'manonjaya'   => 'Manonjaya',
];

/**
 * Operator hanya boleh lihat laporan cabangnya sendiri (kolom `kasir` di tabel
 * transaksi diisi nama cabang: sodonghilir/sariwangi/manonjaya, sesuai
 * $_SESSION['branch'] saat login). Admin/ketua/bendahara lihat semua cabang.
 */
$isOperator     = ($_SESSION['role'] ?? '') === 'operator';
$operatorBranch = $_SESSION['branch'] ?? '';
$adminRole      = $_SESSION['admin_role'] ?? ''; // admin / bendahara / ketua

// Slot yang boleh ditandatangani oleh sesi yang sedang login (dipakai untuk modal tanda tangan)
$slotSaya  = $isOperator ? 'kasir' : $adminRole;
$semuaSlot = ['kasir' => 'Kasir', 'admin' => 'Admin', 'bendahara' => 'Bendahara Koperasi', 'ketua' => 'Ketua Koperasi'];

/**
 * PENTING - SESUAIKAN INI: nama tetap pejabat, dipakai sebagai nilai default
 * pada kolom nama saat admin/bendahara/ketua menandatangani.
 */
const NAMA_PEJABAT = [
    'admin'     => 'Evin Yentiana',
    'bendahara' => 'Nancy Febi Yolla',
    'ketua'     => 'Yudi Hendrian',
];

// Cabang: operator terkunci ke cabangnya sendiri (jadi tetap 1 lokasi per baris).
// Admin/bendahara/ketua melihat semua cabang, tapi datanya dipecah PER LOKASI
// (bukan digabung), karena tanda tangan mengikuti lokasi omset masing-masing baris.

// Filter rentang tanggal, default: awal bulan ini s.d. hari ini
$tanggalMulai  = $_GET['tanggal_mulai'] ?? date('Y-m-01');
$tanggalSelesai = $_GET['tanggal_selesai'] ?? date('Y-m-d');

// Bikin placeholder "?" sejumlah nilai di NILAI_REKENING, misal: ?, ?
$placeholderRekening = implode(', ', array_fill(0, count(NILAI_REKENING), '?'));

// Kalau operator, tambahkan filter "AND kasir = ?" supaya cuma lihat cabangnya sendiri
$whereBranch = ($isOperator && $operatorBranch !== '') ? ' AND kasir = ?' : '';

$sql = "SELECT
            DATE(tanggal) AS tgl,
            kasir AS cabang,
            COUNT(*) AS jumlah_transaksi,
            SUM(total) AS omset,
            SUM(CASE WHEN " . KOLOM_METODE . " = ? THEN total ELSE 0 END) AS total_cash,
            SUM(CASE WHEN " . KOLOM_METODE . " IN ($placeholderRekening) THEN total ELSE 0 END) AS total_rekening
        FROM transaksi
        WHERE DATE(tanggal) BETWEEN ? AND ?" . $whereBranch . "
        GROUP BY DATE(tanggal), kasir
        ORDER BY tgl ASC, kasir ASC";

// Susun parameter: [tunai, ...nilai_rekening, tanggal_mulai, tanggal_selesai, (cabang)]
$params = array_merge([NILAI_TUNAI], NILAI_REKENING, [$tanggalMulai, $tanggalSelesai]);
if ($whereBranch !== '') {
    $params[] = $operatorBranch;
}
$types = str_repeat('s', count($params));

$stmt = mysqli_prepare($koneksi_kasir, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$resultLaporan = mysqli_stmt_get_result($stmt);

$laporanPerTanggal = [];
$grandTransaksi = 0;
$grandOmset = 0;
$grandCash = 0;
$grandRekening = 0;

while ($row = mysqli_fetch_assoc($resultLaporan)) {
    $laporanPerTanggal[] = $row;
    $grandTransaksi += (int) $row['jumlah_transaksi'];
    $grandOmset     += (float) $row['omset'];
    $grandCash      += (float) $row['total_cash'];
    $grandRekening  += (float) $row['total_rekening'];
}
mysqli_stmt_close($stmt);

// --- Ambil tanda tangan yang SUDAH tersimpan milik slot saya, untuk rentang tanggal ini ---
// dipakai supaya tombol "Tanda Tangan" bisa preload tanda tangan lama ke canvas (bisa diganti lagi)
$ttdSaya = []; // key "tanggal|cabang" => ['nama' => ..., 'signature' => ...]
if ($slotSaya !== '') {
    $stmtTtd = mysqli_prepare($koneksi_kasir, "SELECT tanggal, cabang, nama, signature FROM tanda_tangan_laporan WHERE slot = ? AND tanggal BETWEEN ? AND ?");
    mysqli_stmt_bind_param($stmtTtd, 'sss', $slotSaya, $tanggalMulai, $tanggalSelesai);
    mysqli_stmt_execute($stmtTtd);
    $hasilTtd = mysqli_stmt_get_result($stmtTtd);
    while ($rowTtd = mysqli_fetch_assoc($hasilTtd)) {
        $ttdSaya[$rowTtd['tanggal'] . '|' . $rowTtd['cabang']] = ['nama' => $rowTtd['nama'], 'signature' => $rowTtd['signature']];
    }
    mysqli_stmt_close($stmtTtd);
}

function formatRupiahPhp2($n)
{
    return 'Rp' . number_format((float) $n, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Harian - KBUS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="shortcut icon" href="../../assets/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="../style.css">
    <style>
        .btn-cetak-surat,
        .btn-ttd {
            display: inline-block;
            font-size: 12.5px;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 8px;
            text-decoration: none;
            white-space: nowrap;
            margin-right: 6px;
        }

        .btn-cetak-surat {
            background: #16294f;
            color: #eef5fc;
        }

        .btn-cetak-surat:hover {
            background: #3f7fc4;
        }

        .btn-ttd {
            background: #f2b705;
            color: #16294f;
        }

        .btn-ttd:hover {
            background: #d69800;
        }

        .btn-ttd {
            border: none;
            cursor: pointer;
            font-family: inherit;
        }

        /* ---- Modal tanda tangan ---- */
        .modal-overlay {
            position: fixed; inset: 0; background: rgba(14,24,46,0.55);
            display: none; align-items: center; justify-content: center; padding: 20px; z-index: 100;
        }
        .modal-overlay.is-open { display: flex; }
        .modal-card {
            width: 100%; max-width: 380px; background: #fff; border-radius: 16px;
            padding: 24px; box-shadow: 0 20px 50px rgba(10,20,40,0.35);
        }
        .modal-card h3 { margin: 0 0 4px; font-size: 18px; color: #16294f; }
        .modal-card p.sub { margin: 0 0 16px; font-size: 13px; opacity: 0.7; color: #16294f; }
        .modal-card label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #16294f; }
        .modal-card input[type="text"],
        .modal-card select {
            width: 100%; padding: 10px 12px; border-radius: 10px; border: 1.5px solid #cddbea;
            font-size: 14px; margin-bottom: 14px; font-family: inherit;
        }
        #canvasTtd {
            border: 1.5px dashed #a9cdec; border-radius: 8px; width: 100%; height: 140px;
            touch-action: none; cursor: crosshair; display: block; margin-bottom: 8px;
        }
        .modal-actions { display: flex; gap: 8px; margin-top: 14px; }
        .modal-actions button { flex: 1; padding: 11px; border-radius: 10px; border: none; font-weight: 600; cursor: pointer; }
        .btn-batal { background: #f1f4f9; color: #16294f; }
        .btn-hapus { background: #fff; color: #c0392b; border: 1.5px solid #f0c4bd !important; }
        .btn-simpan { background: #16294f; color: #eef5fc; }
        .modal-msg { font-size: 12.5px; margin-top: 8px; min-height: 16px; }
        .modal-msg.error { color: #c0392b; }
        .modal-msg.ok { color: #2e8b40; }
    </style>
</head>

<body>
    <div class="pos-root">
        <?php require '../../partials/sidebar.php'; ?>
        <div class="pos-main">
            <main class="page-main">
                <div class="page-header">
                    <div>
                        <h1>Laporan Harian</h1>
                        <p>
                            <?= date('d F Y', strtotime($tanggalMulai)) ?>
                            &mdash;
                            <?= date('d F Y', strtotime($tanggalSelesai)) ?>
                            <?php if ($isOperator && $operatorBranch !== ''): ?>
                                &middot; Cabang <?= htmlspecialchars(LABEL_CABANG[$operatorBranch] ?? $operatorBranch) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <form class="filter-form" method="get">
                        <label for="tanggal_mulai">Dari</label>
                        <input type="date" id="tanggal_mulai" name="tanggal_mulai" value="<?= htmlspecialchars($tanggalMulai) ?>">
                        <label for="tanggal_selesai">Sampai</label>
                        <input type="date" id="tanggal_selesai" name="tanggal_selesai" value="<?= htmlspecialchars($tanggalSelesai) ?>">
                        <button type="submit">Tampilkan</button>
                    </form>
                </div>

                <div class="stat-cards">
                    <div class="stat-card">
                        <span class="stat-label">Total Transaksi</span>
                        <span class="stat-value"><?= $grandTransaksi ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Total Omzet</span>
                        <span class="stat-value"><?= formatRupiahPhp2($grandOmset) ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Total Cash</span>
                        <span class="stat-value"><?= formatRupiahPhp2($grandCash) ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Total Rekening</span>
                        <span class="stat-value"><?= formatRupiahPhp2($grandRekening) ?></span>
                    </div>
                </div>

                <div class="panel">
                    <h2>Rekap per Tanggal</h2>
                    <?php if (empty($laporanPerTanggal)): ?>
                        <p class="empty-note small">Belum ada transaksi pada rentang tanggal ini.</p>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Lokasi</th>
                                    <th class="num">Jumlah Transaksi</th>
                                    <th class="num">Omset Harian</th>
                                    <th class="num">Cash</th>
                                    <th class="num">Rekening</th>
                                    <th class="num">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($laporanPerTanggal as $r): ?>
                                    <tr>
                                        <td><?= date('l, d F Y', strtotime($r['tgl'])) ?></td>
                                        <td><?= htmlspecialchars(LABEL_CABANG[$r['cabang']] ?? $r['cabang']) ?></td>
                                        <td class="num"><?= (int) $r['jumlah_transaksi'] ?></td>
                                        <td class="num"><b><?= formatRupiahPhp2($r['omset']) ?></b></td>
                                        <td class="num"><?= formatRupiahPhp2($r['total_cash']) ?></td>
                                        <td class="num"><?= formatRupiahPhp2($r['total_rekening']) ?></td>
                                        <td class="num">
                                            <?php if ($slotSaya !== ''):
                                                $keyTtd = $r['tgl'] . '|' . $r['cabang'];
                                                $sudahTtd = isset($ttdSaya[$keyTtd]);
                                            ?>
                                                <button type="button" class="btn-ttd"
                                                    data-tanggal="<?= htmlspecialchars($r['tgl']) ?>"
                                                    data-label="<?= htmlspecialchars(date('d F Y', strtotime($r['tgl']))) ?>"
                                                    data-cabang="<?= htmlspecialchars($r['cabang']) ?>"
                                                    data-cabang-label="<?= htmlspecialchars(LABEL_CABANG[$r['cabang']] ?? $r['cabang']) ?>"
                                                    data-signature="<?= htmlspecialchars($ttdSaya[$keyTtd]['signature'] ?? '') ?>"
                                                    data-nama="<?= htmlspecialchars($ttdSaya[$keyTtd]['nama'] ?? '') ?>">
                                                    <?= $sudahTtd ? 'Ubah Tanda Tangan' : 'Tanda Tangan' ?>
                                                </button>
                                            <?php endif; ?>
                                            <a class="btn-cetak-surat" href="surat-laporan.php?tanggal=<?= htmlspecialchars($r['tgl']) ?>&cabang=<?= htmlspecialchars($r['cabang']) ?>">
                                                Cetak Surat
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <!-- <tr>
                                    <td><b>Total</b></td>
                                    <td class="num"><b><?= $grandTransaksi ?></b></td>
                                    <td class="num"><b><?= formatRupiahPhp2($grandOmset) ?></b></td>
                                    <td class="num"><b><?= formatRupiahPhp2($grandCash) ?></b></td>
                                    <td class="num"><b><?= formatRupiahPhp2($grandRekening) ?></b></td>
                                </tr> -->
                            </tfoot>
                        </table>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>
    <?php if ($slotSaya !== ''): ?>
    <!-- Modal Tanda Tangan (langsung dari daftar laporan harian) -->
    <div class="modal-overlay" id="modalOverlay">
        <div class="modal-card">
            <h3>Tanda Tangan <?= htmlspecialchars($semuaSlot[$slotSaya] ?? '') ?></h3>
            <p class="sub" id="modalTanggalLabel">&nbsp;</p>
            <p class="sub" id="modalCabangLabel">&nbsp;</p>

            <?php if ($slotSaya === 'kasir'): ?>
                <label for="inputNama">Nama Kasir</label>
                <input type="text" id="inputNama" placeholder="Tulis nama lengkap...">
            <?php else: ?>
                <input type="hidden" id="inputNama" value="<?= htmlspecialchars(NAMA_PEJABAT[$slotSaya] ?? $semuaSlot[$slotSaya] ?? '') ?>">
            <?php endif; ?>

            <label>Tanda Tangan</label>
            <canvas id="canvasTtd" width="320" height="140"></canvas>

            <div class="modal-msg" id="modalMsg"></div>

            <div class="modal-actions">
                <button type="button" class="btn-batal" id="btnBatalModal">Batal</button>
                <button type="button" class="btn-hapus" id="btnHapusCanvas">Hapus</button>
                <button type="button" class="btn-simpan" id="btnSimpanTtd">Simpan</button>
            </div>
        </div>
    </div>
    <script>
        window.KBUS_TTD_HARIAN = {
            slotSaya: <?= json_encode($slotSaya) ?>,
            namaDefault: <?= json_encode($slotSaya === 'kasir' ? '' : (NAMA_PEJABAT[$slotSaya] ?? '')) ?>
        };
    </script>
    <script src="tanda-tangan-harian.js"></script>
    <?php endif; ?>
    <script src="laporan.js"></script>
</body>

</html>