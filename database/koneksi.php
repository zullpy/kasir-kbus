<?php
// koneksi.php
// Menghubungkan ke 3 database: db_mbg, db_kasir, db_draft_barang (-> db_barang)
// Auto-detect: LOCAL vs HOSTING

// Deteksi berdasarkan hostname, bukan coba-konek.
// PHP 8.1+ melempar mysqli_sql_exception yang tidak bisa di-suppress dengan @.
$_rawHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? gethostname();
$_host    = strtok($_rawHost, ':'); // strip port, misal localhost:3000 → localhost
$isLocal  = in_array($_host, ['localhost', '127.0.0.1', '::1'])
         || str_ends_with($_host, '.local')
         || str_ends_with($_host, '.test')
         || preg_match('/^192\.168\./', $_host)
         || preg_match('/^10\./', $_host)
         || preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $_host);
if ($isLocal) {
    // ==== KONFIGURASI LOCAL ====
    $cfg = [
        'host'  => 'localhost',
        'mbg'   => ['user' => 'root', 'pass' => '',  'db' => 'db_mbg'],
        'kasir' => ['user' => 'root', 'pass' => '',  'db' => 'db_kasir'],
        'draft' => ['user' => 'root', 'pass' => '',  'db' => 'db_draft_barang'],
    ];
} else {
    // ==== KONFIGURASI HOSTING (Hostinger) ====
    $cfg = [
        'host'  => 'localhost',
        'mbg'   => ['user' => 'u673037475_bgn2026',   'pass' => 'Bgnmbg2026',  'db' => 'u673037475_db_bgn'],
        'kasir' => ['user' => 'u673037475_kasir2026',  'pass' => 'Busmart2026', 'db' => 'u673037475_db_kasir'],
        'draft' => ['user' => 'u673037475_dbkbus',     'pass' => 'Kbus2026',    'db' => 'u673037475_db_barang'],
    ];
}

// ==== Koneksi Database: db_mbg ====
$koneksi_mbg = mysqli_connect($cfg['host'], $cfg['mbg']['user'], $cfg['mbg']['pass'], $cfg['mbg']['db']);
if (!$koneksi_mbg) {
    die("Koneksi ke database db_mbg gagal: " . mysqli_connect_error());
}
mysqli_set_charset($koneksi_mbg, "utf8mb4");

// ==== Koneksi Database: db_kasir ====
$koneksi_kasir = mysqli_connect($cfg['host'], $cfg['kasir']['user'], $cfg['kasir']['pass'], $cfg['kasir']['db']);
if (!$koneksi_kasir) {
    die("Koneksi ke database db_kasir gagal: " . mysqli_connect_error());
}
mysqli_set_charset($koneksi_kasir, "utf8mb4");

// ==== Koneksi Database: db_draft_barang (-> db_barang) ====
$koneksi_draft = mysqli_connect($cfg['host'], $cfg['draft']['user'], $cfg['draft']['pass'], $cfg['draft']['db']);
if (!$koneksi_draft) {
    die("Koneksi ke database db_draft_barang gagal: " . mysqli_connect_error());
}
mysqli_set_charset($koneksi_draft, "utf8mb4");