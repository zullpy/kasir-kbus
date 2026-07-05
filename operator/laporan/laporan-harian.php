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

// Filter rentang tanggal, default: awal bulan ini s.d. hari ini
$tanggalMulai  = $_GET['tanggal_mulai'] ?? date('Y-m-01');
$tanggalSelesai = $_GET['tanggal_selesai'] ?? date('Y-m-d');

// Bikin placeholder "?" sejumlah nilai di NILAI_REKENING, misal: ?, ?
$placeholderRekening = implode(', ', array_fill(0, count(NILAI_REKENING), '?'));

$sql = "SELECT
            DATE(tanggal) AS tgl,
            COUNT(*) AS jumlah_transaksi,
            SUM(total) AS omset,
            SUM(CASE WHEN " . KOLOM_METODE . " = ? THEN total ELSE 0 END) AS total_cash,
            SUM(CASE WHEN " . KOLOM_METODE . " IN ($placeholderRekening) THEN total ELSE 0 END) AS total_rekening
        FROM transaksi
        WHERE DATE(tanggal) BETWEEN ? AND ?
        GROUP BY DATE(tanggal)
        ORDER BY tgl ASC";

// Susun parameter: [tunai, ...nilai_rekening, tanggal_mulai, tanggal_selesai]
$params = array_merge([NILAI_TUNAI], NILAI_REKENING, [$tanggalMulai, $tanggalSelesai]);
$types  = str_repeat('s', count($params));

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
                                    <th class="num">Jumlah Transaksi</th>
                                    <th class="num">Omset Harian</th>
                                    <th class="num">Cash</th>
                                    <th class="num">Rekening</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($laporanPerTanggal as $r): ?>
                                    <tr>
                                        <td><?= date('l, d F Y', strtotime($r['tgl'])) ?></td>
                                        <td class="num"><?= (int) $r['jumlah_transaksi'] ?></td>
                                        <td class="num"><b><?= formatRupiahPhp2($r['omset']) ?></b></td>
                                        <td class="num"><?= formatRupiahPhp2($r['total_cash']) ?></td>
                                        <td class="num"><?= formatRupiahPhp2($r['total_rekening']) ?></td>
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
    <script src="laporan.js"></script>
</body>

</html>