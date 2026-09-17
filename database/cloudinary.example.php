<?php
// =========================================================================
// CONTOH TEMPLATE KONFIGURASI CLOUDINARY (APLIKASI KASIR)
// Salin file ini menjadi: database/cloudinary.php lalu isi kredensial akun Anda.
// =========================================================================

define('CLOUDINARY_CLOUD_NAME', 'YOUR_CLOUD_NAME');
define('CLOUDINARY_API_KEY',    'YOUR_API_KEY');
define('CLOUDINARY_API_SECRET', 'YOUR_API_SECRET');

// Folder utama di Cloudinary Media Library khusus Aplikasi Kasir
define('CLOUDINARY_BASE_FOLDER', 'aplikasi-kasir');

// Fallback otomatis ke penyimpanan lokal jika Cloudinary down / belum diisi
define('CLOUDINARY_FALLBACK_LOCAL', true);
