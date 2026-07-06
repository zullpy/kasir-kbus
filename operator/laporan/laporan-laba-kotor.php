<?php
session_start();
require_once '../../database/koneksi.php';
// $koneksi_kasir, $koneksi_mbg, $koneksi_draft tersedia dari koneksi.php

// ==== Nama hari & bulan Indonesia (untuk format tanggal tanpa ekstensi intl) ====
$__hari  = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
$__bulan = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
function formatTanggalID($tgl, $hari, $bulan) {
    $ts = strtotime($tgl);
    return $hari[date('w', $ts)] . ', ' . date('d', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

function formatBulanID($bulanKey, $bulan) {
    list($y, $m) = explode('-', $bulanKey);
    return $bulan[(int)$m] . ' ' . $y;
}

// ==== Filter periode & mode tampilan (default: bulan berjalan, mode harian) ====
$tgl_awal  = $_GET['tgl_awal']  ?? date('Y-m-01');
$tgl_akhir = $_GET['tgl_akhir'] ?? date('Y-m-t');
$mode      = ($_GET['mode'] ?? 'harian') === 'bulanan' ? 'bulanan' : 'harian';

// ==== Ambil data transaksi_detail + join ke barang (db_draft_barang) untuk harga beli/jual/satuan ====
// Catatan: koneksi memakai $koneksi_kasir (database db_kasir), sedangkan tabel `barang`
// berada di database db_draft_barang -> diakses lintas database dengan nama lengkap
// db_draft_barang.barang (server yang sama, satu user root, jadi bisa diakses langsung).
$sql = "SELECT
            DATE(t.tanggal) AS tgl,
            td.id_barang,
            MAX(td.nama_barang) AS nama_barang,
            SUM(td.qty) AS total_qty,
            TRIM(b.satuan) AS satuan,
            CAST(REPLACE(REPLACE(b.harga_beli,'.',''),',','') AS DECIMAL(14,2)) AS harga_beli_satuan,
            CAST(b.harga_jual AS DECIMAL(14,2)) AS harga_jual_satuan
        FROM transaksi t
        INNER JOIN transaksi_detail td ON td.id_transaksi = t.id_transaksi
        LEFT JOIN db_draft_barang.barang b ON b.id_barang = td.id_barang
        WHERE DATE(t.tanggal) BETWEEN ? AND ?
        GROUP BY DATE(t.tanggal), td.id_barang
        ORDER BY DATE(t.tanggal) DESC, nama_barang ASC";

$stmt = mysqli_prepare($koneksi_kasir, $sql);
mysqli_stmt_bind_param($stmt, "ss", $tgl_awal, $tgl_akhir);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// ==== Susun data per tanggal ====
$laporan       = [];   // [tgl] => [ [item...], [item...] ]
$subtotal_tgl  = [];   // [tgl] => ['beli'=>, 'jual'=>, 'laba'=>]
$grand_beli = 0;
$grand_jual = 0;
$grand_laba = 0;

while ($row = mysqli_fetch_assoc($result)) {
    $tgl        = $row['tgl'];
    $qty        = (float) $row['total_qty'];
    $harga_beli_satuan = (float) $row['harga_beli_satuan'];
    $harga_jual_satuan = (float) $row['harga_jual_satuan'];

    $total_beli = $harga_beli_satuan * $qty;
    $total_jual = $harga_jual_satuan * $qty;
    $laba_item  = $total_jual - $total_beli;

    $laporan[$tgl][] = [
        'nama_barang' => $row['nama_barang'],
        'qty'         => $qty,
        'satuan'      => $row['satuan'] ?: '-',
        'total_beli'  => $total_beli,
        'total_jual'  => $total_jual,
        'laba'        => $laba_item,
    ];

    if (!isset($subtotal_tgl[$tgl])) {
        $subtotal_tgl[$tgl] = ['beli' => 0, 'jual' => 0, 'laba' => 0];
    }
    $subtotal_tgl[$tgl]['beli'] += $total_beli;
    $subtotal_tgl[$tgl]['jual'] += $total_jual;
    $subtotal_tgl[$tgl]['laba'] += $laba_item;

    $grand_beli += $total_beli;
    $grand_jual += $total_jual;
    $grand_laba += $laba_item;
}

// ==== Susun data per bulan (dipakai untuk mode Bulanan) ====
// Setiap bulan berisi daftar tanggal beserta total laba kotor harian-nya,
// diambil langsung dari $subtotal_tgl yang sudah dihitung di atas.
$bulanan = [];   // [YYYY-mm] => ['hari' => [ ['tgl'=>, 'laba'=>], ... ], 'total' => ]
foreach ($subtotal_tgl as $tgl => $sub) {
    $bulanKey = date('Y-m', strtotime($tgl));
    if (!isset($bulanan[$bulanKey])) {
        $bulanan[$bulanKey] = ['hari' => [], 'total' => 0];
    }
    $bulanan[$bulanKey]['hari'][] = ['tgl' => $tgl, 'laba' => $sub['laba']];
    $bulanan[$bulanKey]['total'] += $sub['laba'];
}

function rupiah($angka) {
    return 'Rp ' . number_format((float)$angka, 0, ',', '.');
}

function formatQty($qty) {
    // Buang angka desimal .00 kalau memang bulat
    if ($qty == floor($qty)) {
        return number_format($qty, 0, ',', '.');
    }
    return number_format($qty, 2, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rekap Laba Kotor Stok Barang</title>
<link rel="stylesheet" href="../style.css">
<link rel="shortcut icon" href="../../assets/favicon.ico" type="image/x-icon">
<style>
    :root {
        --brand: #185A85;
        --brand-dark: #123e94;
        --bg: #f4f6fb;
        --card: #ffffff;
        --border: #dde4f0;
        --text: #1e2530;
        --muted: #6b7280;
        --danger: #c0392b;
        --success: #1d5fd6;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: 'Segoe UI', Roboto, Arial, sans-serif;
        background: var(--bg);
        color: var(--text);
        display: flex;
    }
    .pos-sidebar { flex-shrink: 0; }
    .main-content {
        flex: 1;
        padding: 28px 32px;
        min-width: 0;
    }
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        flex-wrap: wrap;
        gap: 16px;
        margin-bottom: 22px;
    }
    .page-header h1 {
        font-size: 22px;
        margin: 0 0 4px;
    }
    .page-header p {
        margin: 0;
        color: var(--muted);
        font-size: 13.5px;
    }

    .filter-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 16px 20px;
        margin-bottom: 22px;
        display: flex;
        gap: 14px;
        align-items: flex-end;
        flex-wrap: wrap;
    }
    .filter-card label {
        display: block;
        font-size: 12.5px;
        color: var(--muted);
        margin-bottom: 6px;
    }
    .filter-card input[type="date"] {
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 8px 10px;
        font-size: 14px;
    }
    .filter-card button {
        background: var(--brand);
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: 9px 20px;
        font-size: 14px;
        cursor: pointer;
    }
    .filter-card button:hover { background: var(--brand-dark); }

    .mode-tabs {
        display: inline-flex;
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 4px;
        gap: 4px;
        margin-bottom: 18px;
    }
    .mode-tabs a {
        text-decoration: none;
        color: var(--muted);
        font-size: 13.5px;
        font-weight: 600;
        padding: 8px 18px;
        border-radius: 7px;
    }
    .mode-tabs a.active {
        background: var(--brand);
        color: #fff;
    }

    .bulan-block {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 12px;
        margin-bottom: 20px;
        overflow: hidden;
    }
    .bulan-block-header {
        background: var(--brand);
        color: #fff;
        padding: 12px 18px;
        font-size: 14.5px;
        font-weight: 600;
    }

    .summary-cards {
        display: grid;
        grid-template-columns: repeat(3, minmax(180px, 1fr));
        gap: 14px;
        margin-bottom: 26px;
    }
    .summary-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 16px 18px;
    }
    .summary-card span.label {
        display: block;
        font-size: 12.5px;
        color: var(--muted);
        margin-bottom: 6px;
    }
    .summary-card span.value {
        font-size: 20px;
        font-weight: 700;
    }
    .summary-card.laba span.value { color: var(--success); }

    .tgl-block {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 12px;
        margin-bottom: 20px;
        overflow: hidden;
    }
    .tgl-block-header {
        background: var(--brand);
        color: #fff;
        padding: 12px 18px;
        font-size: 14.5px;
        font-weight: 600;
    }
    table.laba-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13.5px;
    }
    table.laba-table th, table.laba-table td {
        padding: 10px 14px;
        border-bottom: 1px solid var(--border);
        text-align: left;
    }
    table.laba-table th {
        background: #f0f4f2;
        color: var(--muted);
        font-weight: 600;
        font-size: 12.5px;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    table.laba-table td.num, table.laba-table th.num { text-align: right; }
    table.laba-table tr.subtotal-row td {
        background: #f7faf8;
        font-weight: 700;
        border-top: 2px solid var(--border);
    }
    table.laba-table tr.subtotal-row td.label {
        text-align: right;
    }
    .laba-positif { color: var(--success); font-weight: 600; }
    .laba-negatif { color: var(--danger); font-weight: 600; }

    .grand-total-card {
        background: var(--brand-dark);
        color: #fff;
        border-radius: 14px;
        padding: 22px 26px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 24px;
    }
    .grand-total-card .gt-label {
        font-size: 14px;
        opacity: 0.85;
    }
    .grand-total-card .gt-value {
        font-size: 28px;
        font-weight: 800;
    }

    .empty-state {
        background: var(--card);
        border: 1px dashed var(--border);
        border-radius: 12px;
        padding: 40px;
        text-align: center;
        color: var(--muted);
    }
</style>
</head>
<body>

<?php require_once '../../partials/sidebar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1>Rekap Laba Kotor Stok Barang</h1>
            <p>Laba kotor dihitung per barang berdasarkan seluruh transaksi penjualan, dikelompokkan per tanggal.</p>
        </div>
    </div>

    <div class="mode-tabs">
        <a href="?mode=harian&tgl_awal=<?= htmlspecialchars($tgl_awal) ?>&tgl_akhir=<?= htmlspecialchars($tgl_akhir) ?>"
           class="<?= $mode === 'harian' ? 'active' : '' ?>">Harian</a>
        <a href="?mode=bulanan&tgl_awal=<?= htmlspecialchars($tgl_awal) ?>&tgl_akhir=<?= htmlspecialchars($tgl_akhir) ?>"
           class="<?= $mode === 'bulanan' ? 'active' : '' ?>">Bulanan</a>
    </div>

    <form class="filter-card" method="GET">
        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
        <div>
            <label for="tgl_awal">Dari Tanggal</label>
            <input type="date" id="tgl_awal" name="tgl_awal" value="<?= htmlspecialchars($tgl_awal) ?>">
        </div>
        <div>
            <label for="tgl_akhir">Sampai Tanggal</label>
            <input type="date" id="tgl_akhir" name="tgl_akhir" value="<?= htmlspecialchars($tgl_akhir) ?>">
        </div>
        <button type="submit">Tampilkan</button>
    </form>

    <!-- <div class="summary-cards">
        <div class="summary-card laba">
            <span class="label">Total Laba Kotor</span>
            <span class="value"><?= rupiah($grand_laba) ?></span>
        </div>
    </div> -->

    <?php if (empty($laporan)): ?>
        <div class="empty-state">
            Tidak ada data transaksi pada rentang tanggal yang dipilih.
        </div>
    <?php elseif ($mode === 'harian'): ?>
        <?php foreach ($laporan as $tgl => $items): ?>
            <div class="tgl-block">
                <div class="tgl-block-header">
                    <?= formatTanggalID($tgl, $__hari, $__bulan) ?>
                </div>
                <table class="laba-table">
                    <thead>
                        <tr>
                            <th>Nama Barang</th>
                            <th class="num">Qty</th>
                            <th class="num">Laba</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td><?= htmlspecialchars($it['nama_barang']) ?></td>
                                <td class="num"><?= formatQty($it['qty']) ?> <?= htmlspecialchars($it['satuan']) ?></td>
                                <td class="num <?= $it['laba'] >= 0 ? 'laba-positif' : 'laba-negatif' ?>">
                                    <?= rupiah($it['laba']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="subtotal-row">
                            <td colspan="2" class="label">Subtotal Laba Kotor Tanggal Ini</td>
                            <td class="num <?= $subtotal_tgl[$tgl]['laba'] >= 0 ? 'laba-positif' : 'laba-negatif' ?>">
                                <?= rupiah($subtotal_tgl[$tgl]['laba']) ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

        <!-- <div class="grand-total-card">
            <span class="gt-label">Total Laba Kotor Periode <?= date('d/m/Y', strtotime($tgl_awal)) ?> - <?= date('d/m/Y', strtotime($tgl_akhir)) ?></span>
            <span class="gt-value"><?= rupiah($grand_laba) ?></span>
        </div> -->
    <?php else: ?>
        <?php foreach ($bulanan as $bulanKey => $data): ?>
            <div class="bulan-block">
                <div class="bulan-block-header">
                    <?= formatBulanID($bulanKey, $__bulan) ?>
                </div>
                <table class="laba-table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th class="num">Total Laba Kotor Harian</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['hari'] as $h): ?>
                            <tr>
                                <td><?= formatTanggalID($h['tgl'], $__hari, $__bulan) ?></td>
                                <td class="num <?= $h['laba'] >= 0 ? 'laba-positif' : 'laba-negatif' ?>">
                                    <?= rupiah($h['laba']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="subtotal-row">
                            <td class="label" >Subtotal Laba Kotor Bulan Ini</td>
                            <td class="num <?= $data['total'] >= 0 ? 'laba-positif' : 'laba-negatif' ?>">
                                <?= rupiah($data['total']) ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

        <!-- <div class="grand-total-card">
            <span class="gt-label">Total Laba Kotor Periode <?= date('d/m/Y', strtotime($tgl_awal)) ?> - <?= date('d/m/Y', strtotime($tgl_akhir)) ?></span>
            <span class="gt-value"><?= rupiah($grand_laba) ?></span>
        </div> -->
    <?php endif; ?>
</div>

<script>
document.getElementById('posDate') && (function(){
    var el = document.getElementById('posDate');
    var d = new Date();
    var hari = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    var bulan = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    el.textContent = hari[d.getDay()] + ', ' + d.getDate() + ' ' + bulan[d.getMonth()] + ' ' + d.getFullYear();
})();
</script>
</body>
</html>