<?php
// Partial navigasi sidebar - dipakai di index.php, riwayat-transaksi.php, laporan-harian.php
$__current = basename($_SERVER['PHP_SELF']);
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
        <a href="index.php" class="nav-item <?= $__current === 'index.php' ? 'active' : '' ?>" title="Transaksi">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                <path d="M9 8h6M9 12h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
            </svg>
            <span>Transaksi</span>
        </a>
        <a href="../operator/riwayat/riwayat-transaksi.php" class="nav-item <?= $__current === 'riwayat-transaksi.php' ? 'active' : '' ?>" title="Riwayat Transaksi">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                <path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <span>Riwayat Transaksi</span>
        </a>
        <a href="../operator/laporan/laporan-harian.php" class="nav-item <?= $__current === 'laporan-harian.php' ? 'active' : '' ?>" title="Laporan Harian">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M4 20V10M10 20V4M16 20v-7M4 20h16" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <span>Laporan Omset Harian</span>
        </a>
    </nav>

    <span class="pos-date" id="posDate"></span>
</aside>