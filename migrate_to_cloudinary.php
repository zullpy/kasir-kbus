<?php
/**
 * Script Migrasi Foto Lokal ke Cloudinary
 * Aplikasi Kasir - Koperasi Bina Usaha Sauyunan
 * 
 * Target:
 * 1. Bukti Transfer (tabel setoran, kolom bukti_tf)
 * 2. Tanda Tangan Kasir (tabel setoran, kolom tanda_tangan)
 */

@ini_set('memory_limit', '512M');
@set_time_limit(0);

$isCli = (php_sapi_name() === 'cli');

require_once __DIR__ . '/database/koneksi.php';
require_once __DIR__ . '/database/cloudinary_helper.php';

if (!cloudinary_is_configured()) {
    $msg = "ERROR: Cloudinary belum terkonfigurasi di database/cloudinary.php. Pastikan CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY, dan CLOUDINARY_API_SECRET telah terisi.";
    if ($isCli) {
        fwrite(STDERR, $msg . "\n");
        exit(1);
    } else {
        die("<div style='font-family:sans-serif;padding:24px;color:#b91c1c;background:#fee2e2;border-radius:8px;max-width:600px;margin:40px auto;'><h3>Konfigurasi Belum Lengkap</h3><p>{$msg}</p></div>");
    }
}

// Konfigurasi target migrasi aplikasi kasir
$MIGRATION_TARGETS = [
    'bukti' => [
        'label'        => 'Bukti Transfer (Tabel Setoran)',
        'table'        => 'setoran',
        'pk'           => 'id',
        'col'          => 'bukti_tf',
        'local_dir'    => __DIR__ . '/uploads/bukti',
        'cloud_folder' => 'bukti',
    ],
    'ttd' => [
        'label'        => 'Tanda Tangan Kasir (Tabel Setoran)',
        'table'        => 'setoran',
        'pk'           => 'id',
        'col'          => 'tanda_tangan',
        'local_dir'    => __DIR__ . '/uploads/ttd',
        'cloud_folder' => 'ttd',
    ]
];

// Helper: Scan statistik database
function get_migration_stats($koneksi, $targets)
{
    $stats = [];
    foreach ($targets as $key => $cfg) {
        $table = $cfg['table'];
        $col   = $cfg['col'];
        $pk    = $cfg['pk'];
        $dir   = $cfg['local_dir'];

        // Cek apakah tabel dan kolom ada
        $check = mysqli_query($koneksi, "SHOW TABLES LIKE '$table'");
        if (!$check || mysqli_num_rows($check) === 0) {
            continue;
        }

        // Total record dengan foto terisi
        $qTotal = mysqli_query($koneksi, "SELECT COUNT(*) as cnt FROM `$table` WHERE `$col` IS NOT NULL AND `$col` != ''");
        $total = $qTotal ? (int)mysqli_fetch_assoc($qTotal)['cnt'] : 0;

        // Sudah di Cloudinary
        $qCloud = mysqli_query($koneksi, "SELECT COUNT(*) as cnt FROM `$table` WHERE `$col` LIKE '%res.cloudinary.com%'");
        $inCloud = $qCloud ? (int)mysqli_fetch_assoc($qCloud)['cnt'] : 0;

        // Masih lokal
        $local = $total - $inCloud;

        // Cek ketersediaan file fisik lokal
        $qSample = mysqli_query($koneksi, "SELECT `$pk`, `$col` FROM `$table` WHERE `$col` IS NOT NULL AND `$col` != '' AND `$col` NOT LIKE '%res.cloudinary.com%' LIMIT 200");
        $existOnDisk = 0;
        $missingOnDisk = 0;
        if ($qSample) {
            while ($row = mysqli_fetch_assoc($qSample)) {
                $filename = basename($row[$col]);
                $fullPath = rtrim($dir, '/') . '/' . $filename;
                if (file_exists($fullPath) && is_file($fullPath)) {
                    $existOnDisk++;
                } else {
                    $missingOnDisk++;
                }
            }
        }

        $stats[$key] = [
            'label'         => $cfg['label'],
            'total'         => $total,
            'cloudinary'    => $inCloud,
            'local'         => $local,
            'exist_disk'    => $existOnDisk,
            'missing_disk'  => $missingOnDisk,
        ];
    }
    return $stats;
}

// ==== API BATCH HANDLER UNTUK AJAX / CLI ====
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'stats') {
    header('Content-Type: application/json');
    echo json_encode(get_migration_stats($koneksi_kasir, $MIGRATION_TARGETS));
    exit;
}

if ($action === 'samples') {
    header('Content-Type: application/json');
    $samples = [];
    foreach ($MIGRATION_TARGETS as $key => $cfg) {
        $table = $cfg['table'];
        $col   = $cfg['col'];
        $pk    = $cfg['pk'];
        $q = mysqli_query($koneksi_kasir, "SELECT `$pk` as id, `$col` as val FROM `$table` WHERE `$col` IS NOT NULL AND `$col` != '' ORDER BY `$pk` DESC LIMIT 4");
        $list = [];
        if ($q) {
            while ($r = mysqli_fetch_assoc($q)) {
                $list[] = [
                    'id'  => $r['id'],
                    'val' => $r['val'],
                    'is_cld' => str_contains($r['val'], 'res.cloudinary.com')
                ];
            }
        }
        $samples[$key] = [
            'label' => $cfg['label'],
            'items' => $list
        ];
    }
    echo json_encode($samples);
    exit;
}

if ($action === 'migrate_batch') {
    header('Content-Type: application/json');
    $targetKey    = $_POST['target'] ?? '';
    $lastId       = (int)($_POST['last_id'] ?? 0);
    $deleteLocal  = !empty($_POST['delete_local']);
    $batchSize    = max(1, min(20, (int)($_POST['batch_size'] ?? 5)));

    if (!isset($MIGRATION_TARGETS[$targetKey])) {
        echo json_encode(['success' => false, 'error' => "Target migrasi '{$targetKey}' tidak valid."]);
        exit;
    }

    $cfg   = $MIGRATION_TARGETS[$targetKey];
    $table = $cfg['table'];
    $col   = $cfg['col'];
    $pk    = $cfg['pk'];
    $dir   = $cfg['local_dir'];
    $sub   = $cfg['cloud_folder'];

    // Ambil batch record berikutnya berdasarkan ID Cursor
    $stmt = mysqli_prepare($koneksi_kasir, "
        SELECT `$pk`, `$col` 
        FROM `$table` 
        WHERE `$pk` > ? 
          AND `$col` IS NOT NULL 
          AND `$col` != '' 
          AND `$col` NOT LIKE '%res.cloudinary.com%'
        ORDER BY `$pk` ASC 
        LIMIT ?
    ");
    mysqli_stmt_bind_param($stmt, 'ii', $lastId, $batchSize);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $records = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $records[] = $r;
    }
    mysqli_stmt_close($stmt);

    if (empty($records)) {
        echo json_encode([
            'success'   => true,
            'done'      => true,
            'processed' => 0,
            'last_id'   => $lastId,
            'logs'      => ["[Selesai] Semua file untuk kategori '{$cfg['label']}' telah diproses."]
        ]);
        exit;
    }

    $processed = 0;
    $successCount = 0;
    $skipCount = 0;
    $errorCount = 0;
    $logs = [];
    $newLastId = $lastId;

    foreach ($records as $item) {
        $id = (int)$item[$pk];
        $photoName = trim($item[$col]);
        $newLastId = $id;
        $processed++;

        // Jika data aneh / sudah cloudinary
        if (str_starts_with($photoName, 'http://') || str_starts_with($photoName, 'https://')) {
            $skipCount++;
            $logs[] = "ID {$id}: Lewati, sudah berupa link web.";
            continue;
        }

        $cleanFile = basename($photoName);
        $fullPath = rtrim($dir, '/') . '/' . $cleanFile;

        if (!file_exists($fullPath) || !is_file($fullPath)) {
            $skipCount++;
            $logs[] = "ID {$id}: File fisik tidak ditemukan di server ({$cleanFile}), dilewati.";
            continue;
        }

        try {
            $cldRes = cloudinary_upload($fullPath, $sub);
            $cloudUrl = $cldRes['url'];

            // Update database dengan URL Cloudinary
            $upStmt = mysqli_prepare($koneksi_kasir, "UPDATE `$table` SET `$col` = ? WHERE `$pk` = ?");
            mysqli_stmt_bind_param($upStmt, 'si', $cloudUrl, $id);
            mysqli_stmt_execute($upStmt);
            mysqli_stmt_close($upStmt);

            $logs[] = "ID {$id}: Sukses diupload ke Cloudinary -> {$cloudUrl}";
            $successCount++;

            // Opsi: Hapus file lokal
            if ($deleteLocal && file_exists($fullPath)) {
                @unlink($fullPath);
                $logs[] = "  ↳ File lokal {$cleanFile} telah dihapus dari server.";
            }

        } catch (Exception $e) {
            $errorCount++;
            $logs[] = "ID {$id}: GAGAL upload ke Cloudinary. Error: " . $e->getMessage();
        }
    }

    echo json_encode([
        'success'   => true,
        'done'      => false,
        'processed' => $processed,
        'successes' => $successCount,
        'skipped'   => $skipCount,
        'errors'    => $errorCount,
        'last_id'   => $newLastId,
        'logs'      => $logs
    ]);
    exit;
}

// ==== TAMPILAN WEB UI (ADMIN) ====
$currentStats = get_migration_stats($koneksi_kasir, $MIGRATION_TARGETS);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migrasi Cloudinary - Aplikasi Kasir</title>
    <link rel="shortcut icon" href="assets/favicon.ico" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --bg: #f8fafc;
            --surface: #ffffff;
            --text: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --success: #16a34a;
            --warning: #d97706;
            --danger: #dc2626;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background-color: var(--bg);
            color: var(--text);
            padding: 30px 20px;
            line-height: 1.5;
        }

        .container {
            max-width: 960px;
            margin: 0 auto;
        }

        .header-card {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            color: #ffffff;
            padding: 28px;
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.25);
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .header-card h1 {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-card p {
            color: #bfdbfe;
            font-size: 14px;
        }

        .cloud-badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 16px;
            border-radius: 9999px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            backdrop-filter: blur(4px);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .stat-card-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stat-numbers {
            display: flex;
            gap: 16px;
            margin-bottom: 10px;
        }

        .stat-item {
            flex: 1;
        }

        .stat-val {
            font-size: 22px;
            font-weight: 800;
        }

        .stat-val.cld { color: var(--success); }
        .stat-val.local { color: var(--warning); }
        .stat-val.miss { color: var(--danger); }

        .stat-lbl {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
        }

        .progress-bar-bg {
            height: 8px;
            background: #f1f5f9;
            border-radius: 9999px;
            overflow: hidden;
            margin-top: 8px;
        }

        .progress-bar-fill {
            height: 100%;
            background: var(--success);
            transition: width 0.3s ease;
        }

        .action-panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.03);
        }

        .form-row {
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-danger { background: var(--danger); color: white; }
        .btn-secondary { background: #f1f5f9; color: var(--text); border: 1px solid var(--border); }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .form-check {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
            cursor: pointer;
        }

        .log-box {
            background: #0f172a;
            color: #38bdf8;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 13px;
            padding: 16px;
            border-radius: 12px;
            height: 320px;
            overflow-y: auto;
            white-space: pre-wrap;
            line-height: 1.6;
            margin-bottom: 24px;
        }

        .back-nav {
            margin-bottom: 16px;
        }
        .back-nav a {
            color: var(--primary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .back-nav a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="back-nav">
            <a href="operator/laporan/laporan-setoran.php">
                &larr; Kembali ke Laporan Setoran Kasir
            </a>
        </div>

        <div class="header-card">
            <div>
                <h1>☁️ Migrasi Foto & Tanda Tangan ke Cloudinary</h1>
                <p>Aplikasi Kasir — Pindahkan file bukti transfer & tanda tangan lokal ke Cloudinary secara otomatis.</p>
            </div>
            <div class="cloud-badge">
                <span>Folder: <strong>aplikasi-kasir</strong></span>
            </div>
        </div>

        <div class="stats-grid" id="statsGrid">
            <?php foreach ($currentStats as $key => $s): 
                $pct = $s['total'] > 0 ? round(($s['cloudinary'] / $s['total']) * 100) : 100;
            ?>
                <div class="stat-card" data-key="<?= $key ?>">
                    <div class="stat-card-title">
                        <span><?= htmlspecialchars($s['label']) ?></span>
                        <span style="font-size:12px; color:var(--primary);"><?= $pct ?>%</span>
                    </div>
                    <div class="stat-numbers">
                        <div class="stat-item">
                            <div class="stat-val cld"><?= $s['cloudinary'] ?></div>
                            <div class="stat-lbl">Cloudinary</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-val local"><?= $s['local'] ?></div>
                            <div class="stat-lbl">Lokal</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-val miss"><?= $s['missing_disk'] ?></div>
                            <div class="stat-lbl">Missing File</div>
                        </div>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill" style="width: <?= $pct ?>%;"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="action-panel">
            <h3 style="font-size:16px; margin-bottom:14px; font-weight:700;">Pengaturan & Kontrol Migrasi</h3>
            <div class="form-row">
                <label class="form-check">
                    <input type="checkbox" id="checkDeleteLocal" value="1">
                    <span>Hapus file lokal dari server setelah sukses ter-upload ke Cloudinary</span>
                </label>
            </div>
            <div class="form-row">
                <button type="button" class="btn btn-primary" id="btnStart" onclick="startMigration()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                    <span>Mulai Migrasi ke Cloudinary</span>
                </button>
                <button type="button" class="btn btn-danger" id="btnStop" onclick="stopMigration()" style="display:none;">
                    <span>Hentikan Proses</span>
                </button>
                <button type="button" class="btn btn-secondary" onclick="checkSamples()">
                    <span>Cek Sampel URL Database</span>
                </button>
            </div>
            <p style="font-size:12px; color:var(--text-muted);">
                * Migrasi dilakukan secara bertahap (batch per 5 file) via background AJAX sehingga server tidak akan timeout atau kehabisan memori.
            </p>
        </div>

        <div class="log-box" id="logBox">[Siap] Tekan tombol "Mulai Migrasi ke Cloudinary" untuk menjalankan proses.</div>
    </div>

    <script>
        const TARGET_KEYS = ['bukti', 'ttd'];
        let isRunning = false;
        let currentTargetIndex = 0;
        let currentLastId = 0;

        const logBox = document.getElementById('logBox');
        const btnStart = document.getElementById('btnStart');
        const btnStop = document.getElementById('btnStop');

        function log(msg) {
            const time = new Date().toLocaleTimeString();
            logBox.textContent += `\n[${time}] ${msg}`;
            logBox.scrollTop = logBox.scrollHeight;
        }

        async function refreshStats() {
            try {
                const res = await fetch('migrate_to_cloudinary.php?action=stats');
                const stats = await res.json();
                for (const [key, s] of Object.entries(stats)) {
                    const card = document.querySelector(`.stat-card[data-key="${key}"]`);
                    if (card) {
                        const pct = s.total > 0 ? Math.round((s.cloudinary / s.total) * 100) : 100;
                        card.querySelector('.stat-val.cld').textContent = s.cloudinary;
                        card.querySelector('.stat-val.local').textContent = s.local;
                        card.querySelector('.stat-val.miss').textContent = s.missing_disk;
                        card.querySelector('.progress-bar-fill').style.width = pct + '%';
                        card.querySelector('.stat-card-title span:last-child').textContent = pct + '%';
                    }
                }
            } catch (e) {}
        }

        async function checkSamples() {
            log('Mengambil sampel 4 record terbaru dari database...');
            try {
                const res = await fetch('migrate_to_cloudinary.php?action=samples');
                const data = await res.json();
                for (const [key, group] of Object.entries(data)) {
                    log(`--- Kategori [${group.label}] ---`);
                    if (group.items.length === 0) {
                        log('  (Belum ada record data)');
                    } else {
                        group.items.forEach(item => {
                            log(`ID ${item.id}: ${item.val} [${item.is_cld ? 'CLOUDINARY' : 'LOKAL'}]`);
                        });
                    }
                }
            } catch (e) {
                log('Gagal mengambil sampel: ' + e.message);
            }
        }

        function startMigration() {
            if (isRunning) return;
            isRunning = true;
            currentTargetIndex = 0;
            currentLastId = 0;
            btnStart.style.display = 'none';
            btnStop.style.display = 'inline-flex';
            log('=== MEMULAI MIGRASI OTOMATIS ===');
            runNextBatch();
        }

        function stopMigration() {
            isRunning = false;
            btnStart.style.display = 'inline-flex';
            btnStop.style.display = 'none';
            log('Proses migrasi dihentikan oleh pengguna.');
        }

        async function runNextBatch() {
            if (!isRunning) return;

            if (currentTargetIndex >= TARGET_KEYS.length) {
                log('🎉 SEMUA KATEGORI SELESAI DIPROSES!');
                stopMigration();
                refreshStats();
                return;
            }

            const currentKey = TARGET_KEYS[currentTargetIndex];
            const deleteLocal = document.getElementById('checkDeleteLocal').checked ? '1' : '0';

            const fd = new FormData();
            fd.append('action', 'migrate_batch');
            fd.append('target', currentKey);
            fd.append('last_id', currentLastId);
            fd.append('delete_local', deleteLocal);
            fd.append('batch_size', '5');

            try {
                const res = await fetch('migrate_to_cloudinary.php', { method: 'POST', body: fd });
                const json = await res.json();

                if (!json.success) {
                    log(`[Error] ${json.error || 'Terjadi kesalahan'}`);
                    currentTargetIndex++;
                    currentLastId = 0;
                    setTimeout(runNextBatch, 1000);
                    return;
                }

                if (json.logs && json.logs.length > 0) {
                    json.logs.forEach(l => log(l));
                }

                currentLastId = json.last_id;

                if (json.done) {
                    currentTargetIndex++;
                    currentLastId = 0;
                    refreshStats();
                }

                if (isRunning) {
                    setTimeout(runNextBatch, 500);
                }

            } catch (e) {
                log(`[Network/Server Error] ${e.message}. Mencoba lagi dalam 3 detik...`);
                setTimeout(runNextBatch, 3000);
            }
        }
    </script>
</body>
</html>
