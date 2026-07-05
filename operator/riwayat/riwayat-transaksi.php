<?php
// Halaman Riwayat Transaksi
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../../includes/auth_guard.php';
require_once '../../database/koneksi.php';

$dariTanggal   = $_GET['dari'] ?? date('Y-m-d', strtotime('-6 days'));
$sampaiTanggal = $_GET['sampai'] ?? date('Y-m-d');

$transaksiList = [];
$totalOmzetFilter = 0;

$stmt = mysqli_prepare(
    $koneksi_kasir,
    "SELECT id_transaksi, tanggal, subtotal, diskon, total, cash, kembalian
     FROM transaksi
     WHERE DATE(tanggal) BETWEEN ? AND ?
     ORDER BY tanggal DESC"
);
mysqli_stmt_bind_param($stmt, 'ss', $dariTanggal, $sampaiTanggal);
mysqli_stmt_execute($stmt);
$resultTransaksi = mysqli_stmt_get_result($stmt);

while ($row = mysqli_fetch_assoc($resultTransaksi)) {
    $row['items'] = [];
    $transaksiList[$row['id_transaksi']] = $row;
    $totalOmzetFilter += (float) $row['total'];
}
mysqli_stmt_close($stmt);

// Ambil semua item per transaksi dalam rentang tanggal (satu query, dikelompokkan di PHP)
if (!empty($transaksiList)) {
    $ids = implode(',', array_map('intval', array_keys($transaksiList)));
    $resultDetail = mysqli_query(
        $koneksi_kasir,
        "SELECT id_transaksi, nama_barang, harga, qty, subtotal
         FROM transaksi_detail
         WHERE id_transaksi IN ($ids)"
    );
    while ($d = mysqli_fetch_assoc($resultDetail)) {
        $transaksiList[$d['id_transaksi']]['items'][] = $d;
    }
}

function formatRupiahPhp($n)
{
    return 'Rp' . number_format((float) $n, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Transaksi - KBUS</title>
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
                        <h1>Riwayat Transaksi</h1>
                        <p><span id="rtCount"><?= count($transaksiList) ?></span> transaksi ·
                            Total <?= formatRupiahPhp($totalOmzetFilter) ?>
                            (<?= date('d M Y', strtotime($dariTanggal)) ?> &ndash; <?= date('d M Y', strtotime($sampaiTanggal)) ?>)
                        </p>
                    </div>
                    <form class="filter-form" method="get">
                        <label for="dari">Dari</label>
                        <input type="date" id="dari" name="dari" value="<?= htmlspecialchars($dariTanggal) ?>">
                        <label for="sampai">Sampai</label>
                        <input type="date" id="sampai" name="sampai" value="<?= htmlspecialchars($sampaiTanggal) ?>">
                        <button type="submit">Terapkan</button>
                    </form>
                </div>

                <div class="toolbar">
                    <div class="search-box">
                        <input type="text" id="searchTrx" placeholder="Cari no. transaksi...">
                    </div>
                </div>

                <div class="panel">
                    <?php if (empty($transaksiList)): ?>
                        <p class="empty-note small">Tidak ada transaksi pada rentang tanggal ini.</p>
                    <?php else: ?>
                        <table class="data-table" id="trxTable">
                            <thead>
                                <tr>
                                    <th>No. Transaksi</th>
                                    <th>Tanggal</th>
                                    <th class="num">Subtotal</th>
                                    <th class="num">Diskon</th>
                                    <th class="num">Total</th>
                                    <th class="num">Cash</th>
                                    <th class="num">Kembalian</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; ?>
                                <?php foreach ($transaksiList as $trx): ?>
                                    <tr class="trx-row" data-id="<?= $no ?>">
                                        <td>#<?= $no ?></td>
                                        <td><?= date('d M Y, H:i', strtotime($trx['tanggal'])) ?></td>
                                        <td class="num"><?= formatRupiahPhp($trx['subtotal']) ?></td>
                                        <td class="num"><?= formatRupiahPhp($trx['diskon']) ?></td>
                                        <td class="num"><b><?= formatRupiahPhp($trx['total']) ?></b></td>
                                        <td class="num"><?= formatRupiahPhp($trx['cash']) ?></td>
                                        <td class="num"><?= formatRupiahPhp($trx['kembalian']) ?></td>
                                    </tr>
                                    <tr class="detail-row hidden" data-detail-for="<?= $no ?>">
                                        <td colspan="7">
                                            <div class="detail-row-inner">
                                                <?php if (empty($trx['items'])): ?>
                                                    <span class="empty-note small">Tidak ada rincian item.</span>
                                                <?php else: ?>
                                                    <table class="detail-mini-table">
                                                        <?php foreach ($trx['items'] as $item): ?>
                                                            <tr>
                                                                <td><?= htmlspecialchars($item['nama_barang']) ?></td>
                                                                <td><?= (int) $item['qty'] ?> x <?= formatRupiahPhp($item['harga']) ?></td>
                                                                <td style="text-align:right;"><?= formatRupiahPhp($item['subtotal']) ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </table>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php $no++; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>
    <script src="riwayat.js"></script>
</body>

</html>