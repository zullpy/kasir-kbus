<?php
// Partial navigasi sidebar - dipakai di index.php, riwayat-transaksi.php, laporan-harian.php
$__current = basename($_SERVER['PHP_SELF']);

// Data kasir dari session
$__branch   = $_SESSION['branch'] ?? '';
$__kasirMap = [
    'sodonghilir' => ['nama' => 'Kasir Sodonghilir', 'lokasi' => 'Kp. Cibengang, Sodonghilir'],
    'sariwangi'   => ['nama' => 'Kasir Sariwangi',   'lokasi' => 'Sariwangi, Tasikmalaya'],
    'manonjaya'   => ['nama' => 'Kasir Manonjaya',   'lokasi' => 'Manonjaya, Tasikmalaya'],
];
$__kasirInfo = $__kasirMap[$__branch] ?? ['nama' => 'Kasir', 'lokasi' => '-'];
?>
<aside class="pos-sidebar">
    <div class="brand">
        <div class="brand-mark">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M3 9.5 4.2 4h15.6l1.2 5.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                <path d="M3 9.5a2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 5 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                <path d="M4.5 11v8.5A1 1 0 0 0 5.5 20.5h13a1 1 0 0 0 1-1V11" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                <path d="M9.5 20.5V15a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v5.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </div>
        <span class="brand-name">KBUS</span>
    </div>

    <nav class="pos-nav">
        <a href="/operator/index.php"
           class="nav-item <?= $__current === 'index.php' ? 'active' : '' ?>" title="Transaksi">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                <path d="M9 8h6M9 12h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
            </svg>
            <span>Transaksi</span>
        </a>
        <a href="/operator/riwayat/riwayat-transaksi.php"
           class="nav-item <?= $__current === 'riwayat-transaksi.php' ? 'active' : '' ?>" title="Riwayat Transaksi">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                <path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <span>Riwayat Transaksi</span>
        </a>
        <a href="/operator/laporan/laporan-harian.php"
           class="nav-item <?= $__current === 'laporan-harian.php' ? 'active' : '' ?>" title="Laporan Harian">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M4 20V10M10 20V4M16 20v-7M4 20h16" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <span>Laporan Omset Harian</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="kasir-card">
            <div class="kasir-avatar" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="8" r="4" />
                    <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7" />
                </svg>
            </div>
            <div class="kasir-info">
                <span class="kasir-nama"><?= htmlspecialchars($__kasirInfo['nama']) ?></span>
                <span class="kasir-lokasi">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="11" height="11" aria-hidden="true">
                        <path d="M12 2C8.686 2 6 4.686 6 8c0 5.25 6 13 6 13s6-7.75 6-13c0-3.314-2.686-6-6-6z"/>
                        <circle cx="12" cy="8" r="2"/>
                    </svg>
                    <?= htmlspecialchars($__kasirInfo['lokasi']) ?>
                </span>
            </div>
        </div>
        <span class="pos-date" id="posDate"></span>
        <a href="/index.php" class="logout-btn" title="Keluar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
            Keluar
        </a>
    </div>
</aside>
