<?php
// Halaman Surat Laporan Omset Harian (versi cetak, tanda tangan tersimpan di database)
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../../includes/auth_guard.php';
require_once '../../database/koneksi.php';

const KOLOM_METODE   = 'metode_pembayaran';
const NILAI_TUNAI    = 'cash';
const NILAI_REKENING = ['transfer', 'qris'];

/**
 * PENTING - SESUAIKAN INI: alamat kop surat per cabang.
 */
const ALAMAT_CABANG = [
    'sodonghilir' => ['Kp. Cibengang Desa Sodonghilir', 'Kecamatan Sodonghilir'],
    'sariwangi'   => ['Alamat Sariwangi (isi di sini)', 'Kecamatan Sariwangi'],
    'manonjaya'   => ['Alamat Manonjaya (isi di sini)', 'Kecamatan Manonjaya'],
];

const LABEL_CABANG = [
    'sodonghilir' => 'Sodonghilir',
    'sariwangi'   => 'Sariwangi',
    'manonjaya'   => 'Manonjaya',
];

/**
 * PENTING - SESUAIKAN INI: nama tetap pejabat, selalu tercetak di surat
 * walau belum ada tanda tangan (menunggu ttd asli/digital).
 */
const NAMA_PEJABAT = [
    'admin'     => 'Evin Yentiana',
    'bendahara' => 'Nancy Febi Yolla',
    'ketua'     => 'Yudi Hendrian',
];

// --- Role & akses ---
$role           = $_SESSION['role'] ?? '';
$isOperator     = $role === 'operator';
$operatorBranch = $_SESSION['branch'] ?? '';
$adminRole      = $_SESSION['admin_role'] ?? ''; // admin / bendahara / ketua

// Slot yang boleh ditandatangani oleh sesi yang sedang login
$slotSaya = $isOperator ? 'kasir' : $adminRole;

// --- Cabang: operator terkunci ke cabangnya sendiri, admin/ketua/bendahara pilih dari dropdown ---
if ($isOperator) {
    $cabang = $operatorBranch;
} else {
    $cabang = $_GET['cabang'] ?? 'sodonghilir';
    if (!isset(LABEL_CABANG[$cabang])) {
        $cabang = 'sodonghilir';
    }
}

// --- Tanggal (surat ini untuk 1 tanggal spesifik) ---
$tanggal = $_GET['tanggal'] ?? date('Y-m-d');

// --- Query data omset untuk tanggal + cabang tersebut ---
$placeholderRekening = implode(', ', array_fill(0, count(NILAI_REKENING), '?'));
$sql = "SELECT
            COUNT(*) AS jumlah_transaksi,
            COALESCE(SUM(total), 0) AS omset,
            COALESCE(SUM(CASE WHEN " . KOLOM_METODE . " = ? THEN total ELSE 0 END), 0) AS total_cash,
            COALESCE(SUM(CASE WHEN " . KOLOM_METODE . " IN ($placeholderRekening) THEN total ELSE 0 END), 0) AS total_rekening
        FROM transaksi
        WHERE DATE(tanggal) = ? AND kasir = ?";

$params = array_merge([NILAI_TUNAI], NILAI_REKENING, [$tanggal, $cabang]);
$types  = str_repeat('s', count($params));

$stmt = mysqli_prepare($koneksi_kasir, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

// --- Ambil tanda tangan yang sudah tersimpan untuk tanggal + cabang ini ---
$ttdTersimpan = []; // slot => ['nama' => ..., 'signature' => ...]
$stmtTtd = mysqli_prepare($koneksi_kasir, "SELECT slot, nama, signature FROM tanda_tangan_laporan WHERE tanggal = ? AND cabang = ?");
mysqli_stmt_bind_param($stmtTtd, 'ss', $tanggal, $cabang);
mysqli_stmt_execute($stmtTtd);
$hasilTtd = mysqli_stmt_get_result($stmtTtd);
while ($row = mysqli_fetch_assoc($hasilTtd)) {
    $ttdTersimpan[$row['slot']] = ['nama' => $row['nama'], 'signature' => $row['signature']];
}
mysqli_stmt_close($stmtTtd);

function formatRupiahSurat($n)
{
    return number_format((float) $n, 0, ',', '.');
}

$alamat = ALAMAT_CABANG[$cabang];
$semuaSlot = ['kasir' => 'Kasir', 'admin' => 'Admin', 'bendahara' => 'Bendahara Koperasi', 'ketua' => 'Ketua Koperasi'];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surat Laporan Omset Harian - KBUS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="shortcut icon" href="../../assets/favicon.ico" type="image/x-icon">
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: #eef2f7;
            margin: 0;
            padding: 30px 16px;
            color: #16294f;
        }

        .toolbar {
            max-width: 780px;
            margin: 0 auto 16px;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .toolbar label { font-size: 13px; font-weight: 600; }

        .toolbar input[type="date"],
        .toolbar select {
            padding: 8px 10px;
            border-radius: 8px;
            border: 1.5px solid #cddbea;
            font-size: 14px;
        }

        .toolbar button, .toolbar a.btn {
            padding: 9px 16px;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            font-size: 13.5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary { background: #16294f; color: #eef5fc; }

        .btn-secondary { 
            background: #fff; 
            color: #16294f; 
            border: 1.5px solid #cddbea; 
            border-radius: 8px;
            padding:8px;
        }
        #btnCetak { margin-left: auto; }

        .sheet {
            max-width: 780px;
            margin: 0 auto;
            background: #fff;
            padding: 46px 50px;
            border-radius: 6px;
            box-shadow: 0 10px 30px rgba(22, 41, 79, 0.12);
        }

        .kop {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            border-bottom: 2px solid #16294f;
            padding-bottom: 14px;
            margin-bottom: 22px;
        }
        .kop .kop-logos { display: flex; align-items: center; justify-content: center; margin-bottom: 8px; }
        .kop .kop-logos img.logo-icon { height: 75px; position: relative; z-index: 1; }
        .kop .kop-logos img.logo-text { height: 60px; margin-left: -20px; position: relative; z-index: 2; margin-top: 10px;}
        .kop p { margin: 2px 0 0; font-size: 12.5px; opacity: 0.75; }

        .judul { margin: 4px 0 18px; }
        .judul h3 { margin: 0 0 4px; font-size: 16px; }
        .judul .cabang-tag { font-size: 12.5px; opacity: 0.7; }

        .baris-tanggal { display: flex; gap: 8px; align-items: baseline; font-size: 14px; margin-bottom: 18px; }
        .baris-tanggal .garis { flex: 1; border-bottom: 1px solid #16294f; padding-bottom: 2px; }

        table.rekap { width: 100%; border-collapse: collapse; margin-bottom: 40px; }
        table.rekap th, table.rekap td { border: 1px solid #16294f; padding: 10px 12px; font-size: 13.5px; text-align: left; }
        table.rekap th { background: #f4f7fb; font-weight: 600; }

        .ttd-mengetahui-wrap { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; margin-bottom: 8px; }
        .ttd-mengetahui { grid-column: 3 / 5; text-align: center; font-size: 13.5px; font-style: italic; margin-bottom: 26px; }
        .ttd-grid { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 14px; }
        .ttd-col { text-align: center; }
        .ttd-col .peran { font-size: 13px; text-decoration: underline; margin-bottom: 6px; }
        .ttd-slot { height: 70px; display: flex; align-items: center; justify-content: center; margin-bottom: 4px; }
        .ttd-slot img { max-height: 68px; max-width: 100%; }
        .ttd-nama { font-size: 13.5px; border-top: 1px solid #16294f; padding-top: 4px; min-height: 18px; }
        .ttd-status { font-size: 11px; margin-top: 3px; }
        .ttd-status.belum { color: #c0392b; }
        .ttd-status.sudah { color: #2e8b40; }

        @page{
            margin: 10mm;
        }

        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none; }
            .sheet { box-shadow: none; padding: 20px 10px; }
        }
    </style>
</head>

<body>
    <div class="toolbar">
        <form method="get" style="display:flex; gap:10px; align-items:center; flex-wrap: wrap;">
            <label for="tanggal">Tanggal</label>
            <input type="date" id="tanggal" name="tanggal" value="<?= htmlspecialchars($tanggal) ?>">

            <?php if (!$isOperator): ?>
                <label for="cabang">Cabang</label>
                <select id="cabang" name="cabang">
                    <?php foreach (LABEL_CABANG as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $cabang === $key ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <button type="submit" class="btn-secondary">Tampilkan</button>
        </form>

        <button type="button" id="btnCetak" class="btn-primary">Cetak Surat</button>
        <a href="laporan-harian.php" class="btn-secondary">Kembali</a>
    </div>

    <div class="sheet" id="sheet">
        <div class="kop">
            <div class="kop-logos">
                <img src="../../assets/struk.png" alt="Logo KBUS" class="logo-icon" onerror="this.style.display='none'">
                <img src="../../assets/logostruk.png" alt="KBUS Mart" class="logo-text" onerror="this.style.display='none'">
            </div>
            <p><?= htmlspecialchars($alamat[0]) ?></p>
            <p><?= htmlspecialchars($alamat[1]) ?></p>
        </div>

        <div class="judul">
            <h3>Laporan Omset Harian</h3>
            <span class="cabang-tag">Cabang: <?= htmlspecialchars(LABEL_CABANG[$cabang]) ?></span>
        </div>

        <div class="baris-tanggal">
            <span>Tanggal</span>
            <span class="garis"><?= date('d F Y', strtotime($tanggal)) ?></span>
        </div>

        <table class="rekap">
            <thead>
                <tr>
                    <th>Total Transaksi</th>
                    <th>Omset Harian</th>
                    <th>Uang Tunai</th>
                    <th>Uang di Rekening</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?= (int) ($data['jumlah_transaksi'] ?? 0) ?></td>
                    <td>Rp <?= formatRupiahSurat($data['omset'] ?? 0) ?></td>
                    <td>Rp <?= formatRupiahSurat($data['total_cash'] ?? 0) ?></td>
                    <td>Rp <?= formatRupiahSurat($data['total_rekening'] ?? 0) ?></td>
                </tr>
            </tbody>
        </table>

        <div class="ttd-mengetahui-wrap">
            <div class="ttd-mengetahui">Mengetahui,</div>
        </div>
        <div class="ttd-grid">
            <?php foreach ($semuaSlot as $slotKey => $labelSlot):
                $sudah = isset($ttdTersimpan[$slotKey]);
            ?>
                <div class="ttd-col">
                    <div class="peran"><?= htmlspecialchars($labelSlot) ?></div>
                    <div class="ttd-slot">
                        <?php if ($sudah): ?>
                            <img src="<?= htmlspecialchars($ttdTersimpan[$slotKey]['signature']) ?>" alt="Tanda tangan <?= htmlspecialchars($labelSlot) ?>">
                        <?php endif; ?>
                    </div>
                    <div class="ttd-nama">
                        <?php
                        $namaTampil = $ttdTersimpan[$slotKey]['nama'] ?? (NAMA_PEJABAT[$slotKey] ?? '');
                        echo $namaTampil !== '' ? htmlspecialchars($namaTampil) : '&nbsp;';
                        ?>
                    </div>
                    <?php if ($slotKey === $slotSaya): ?>
                        <div class="ttd-status <?= $sudah ? 'sudah' : 'belum' ?>">
                            <?= $sudah ? '✓ Sudah tanda tangan' : 'Belum tanda tangan' ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script src="surat-laporan.js"></script>
</body>

</html>