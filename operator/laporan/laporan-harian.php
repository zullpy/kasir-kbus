<?php
// Halaman Laporan Harian
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../../includes/auth_guard.php';
require_once '../../database/koneksi.php';

$tanggal = $_GET['tanggal'] ?? date('Y-m-d');

$stmt = mysqli_prepare(
    $koneksi_kasir,
    "SELECT id_transaksi, tanggal, subtotal, diskon, total, cash, kembalian
     FROM transaksi
     WHERE DATE(tanggal) = ?
     ORDER BY tanggal ASC"
);
mysqli_stmt_bind_param($stmt, 's', $tanggal);
mysqli_stmt_execute($stmt);
$resultTransaksi = mysqli_stmt_get_result($stmt);

$transaksiHariIni = [];
$totalOmzet = 0;
$totalDiskon = 0;
$totalSubtotal = 0;

while ($row = mysqli_fetch_assoc($resultTransaksi)) {
    $transaksiHariIni[] = $row;
    $totalOmzet    += (float) $row['total'];
    $totalDiskon   += (float) $row['diskon'];
    $totalSubtotal += (float) $row['subtotal'];
}
mysqli_stmt_close($stmt);

$jumlahTransaksi = count($transaksiHariIni);
$rataRata = $jumlahTransaksi > 0 ? $totalOmzet / $jumlahTransaksi : 0;

// Produk terlaris pada tanggal terpilih
$produkTerlaris = [];
if ($jumlahTransaksi > 0) {
    $ids = implode(',', array_map(fn($t) => (int) $t['id_transaksi'], $transaksiHariIni));
    $resultProduk = mysqli_query(
        $koneksi_kasir,
        "SELECT nama_barang, SUM(qty) AS total_qty, SUM(subtotal) AS total_omzet
         FROM transaksi_detail
         WHERE id_transaksi IN ($ids)
         GROUP BY nama_barang
         ORDER BY total_qty DESC
         LIMIT 5"
    );
    while ($p = mysqli_fetch_assoc($resultProduk)) {
        $produkTerlaris[] = $p;
    }
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
    <link rel="shortcut icon" href="../assets/favicon.ico" type="image/x-icon">
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
                        <p><?= date('l, d F Y', strtotime($tanggal)) ?></p>
                    </div>
                    <form class="filter-form" method="get">
                        <label for="tanggal">Tanggal</label>
                        <input type="date" id="tanggal" name="tanggal" value="<?= htmlspecialchars($tanggal) ?>">
                        <button type="submit">Tampilkan</button>
                    </form>
                </div>

                <div class="stat-cards">
                    <div class="stat-card">
                        <span class="stat-label">Jumlah Transaksi</span>
                        <span class="stat-value"><?= $jumlahTransaksi ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Total Omzet</span>
                        <span class="stat-value"><?= formatRupiahPhp2($totalOmzet) ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Total Diskon Diberikan</span>
                        <span class="stat-value"><?= formatRupiahPhp2($totalDiskon) ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Rata-rata / Transaksi</span>
                        <span class="stat-value"><?= formatRupiahPhp2($rataRata) ?></span>
                    </div>
                </div>

                <div class="report-grid">
                    <div class="panel">
                        <h2>Daftar Transaksi</h2>
                        <?php if ($jumlahTransaksi === 0): ?>
                            <p class="empty-note small">Belum ada transaksi pada tanggal ini.</p>
                        <?php else: ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>No. Transaksi</th>
                                        <th>Jam</th>
                                        <th class="num">Subtotal</th>
                                        <th class="num">Diskon</th>
                                        <th class="num">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transaksiHariIni as $trx): ?>
                                        <tr>
                                            <td>#<?= (int) $trx['id_transaksi'] ?></td>
                                            <td><?= date('H:i', strtotime($trx['tanggal'])) ?></td>
                                            <td class="num"><?= formatRupiahPhp2($trx['subtotal']) ?></td>
                                            <td class="num"><?= formatRupiahPhp2($trx['diskon']) ?></td>
                                            <td class="num"><b><?= formatRupiahPhp2($trx['total']) ?></b></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <div class="panel">
                        <h2>Produk Terlaris <span class="badge">Top 5</span></h2>
                        <?php if (empty($produkTerlaris)): ?>
                            <p class="empty-note small">Belum ada data penjualan produk.</p>
                        <?php else: ?>
                            <?php foreach ($produkTerlaris as $p): ?>
                                <div class="top-product-item">
                                    <span><?= htmlspecialchars($p['nama_barang']) ?></span>
                                    <span class="tp-qty"><?= (int) $p['total_qty'] ?> terjual</span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <script src="laporan.js"></script>
</body>

</html>