<?php
/**
 * stok_helper.php
 *
 * Helper konversi satuan untuk sistem stok dengan SOURCE OF TRUTH tunggal
 * dalam satuan kecil (eceran). Dipakai bareng-bareng oleh checkout.php,
 * get-products.php, dan file lain yang butuh baca/tampilkan stok.
 *
 * Skema aktual (nama kolom TIDAK diubah, cuma cara pakainya):
 *   - barang.isi_per_satuan   -> rasio konversi, misal 1 dus = 24 pcs -> 24
 *                                 (kolom ini sudah ada di tabel barang)
 *   - stok_barang.qty_eceran  -> dipakai sebagai SATU-SATUNYA angka
 *                                 total stok, dalam satuan kecil (pcs/kg/dst),
 *                                 per lokasi. Ini source of truth.
 *   - stok_barang.qty_grosir  -> SUDAH TIDAK DIPAKAI oleh logic ini.
 *                                 Kolomnya boleh tetap ada di tabel (tidak
 *                                 dihapus/di-rename), tapi checkout & stok
 *                                 tidak lagi membaca/menulis kolom ini.
 *                                 Jumlah dus yang bisa dijual grosir dihitung
 *                                 langsung dari qty_eceran / isi_per_satuan.
 *
 * Kalau isi_per_satuan = 1 atau NULL, berarti barang itu tidak punya
 * varian "satuan besar" (cuma dijual eceran) -> tidak ada pembulatan dus.
 */

/**
 * Konversi qty transaksi (dalam mode grosir/eceran) menjadi jumlah satuan
 * kecil yang harus ditambah/dikurangkan ke total stok.
 *
 * @param float  $qty           Jumlah yang dibeli/dijual, dalam satuan sesuai $mode.
 * @param string $mode          'grosir' atau 'eceran'.
 * @param int    $isiPerSatuan  Rasio 1 satuan besar = berapa satuan kecil.
 * @return float                Jumlah dalam satuan kecil (eceran).
 */
function konversiKeEceran(float $qty, string $mode, int $isiPerSatuan): float
{
    $isiPerSatuan = $isiPerSatuan > 0 ? $isiPerSatuan : 1;
    return $mode === 'grosir' ? $qty * $isiPerSatuan : $qty;
}

/**
 * Pecah balik total stok (dalam satuan kecil) jadi jumlah satuan besar utuh
 * yang bisa dijual grosir (dus_utuh) + sisa satuan kecil (sisa_pcs).
 *
 * @return array{dus_utuh:int, sisa_pcs:float}
 */
function pecahStok(float $totalEceran, int $isiPerSatuan): array
{
    if ($isiPerSatuan <= 1) {
        // Barang tanpa varian satuan besar -> semuanya "sisa pcs".
        return ['dus_utuh' => 0, 'sisa_pcs' => $totalEceran];
    }

    $dusUtuh = intdiv((int) $totalEceran, $isiPerSatuan);
    $sisaPcs = $totalEceran - ($dusUtuh * $isiPerSatuan);

    return ['dus_utuh' => $dusUtuh, 'sisa_pcs' => $sisaPcs];
}

/**
 * Format total stok (satuan kecil) jadi teks campuran, contoh:
 * "1 dus lebih 22 pcs" atau "22 pcs" kalau dus_utuh = 0.
 */
function formatStokCampuran(
    float $totalEceran,
    int $isiPerSatuan,
    string $satuanBesar = 'dus',
    string $satuanKecil = 'pcs'
): string {
    $pecahan = pecahStok($totalEceran, $isiPerSatuan);

    if ($pecahan['dus_utuh'] <= 0) {
        return rtrim(rtrim((string) $pecahan['sisa_pcs'], '0'), '.') === ''
            ? '0 ' . $satuanKecil
            : formatAngka($pecahan['sisa_pcs']) . ' ' . $satuanKecil;
    }

    if ($pecahan['sisa_pcs'] <= 0) {
        return $pecahan['dus_utuh'] . ' ' . $satuanBesar;
    }

    return $pecahan['dus_utuh'] . ' ' . $satuanBesar . ' lebih ' .
        formatAngka($pecahan['sisa_pcs']) . ' ' . $satuanKecil;
}

/**
 * Berapa satuan besar (dus) UTUH yang tersedia untuk dijual grosir.
 * Sisa pcs yang tidak cukup 1 dus TIDAK dihitung (tidak bisa jual 0.x dus).
 */
function stokTersediaGrosir(float $totalEceran, int $isiPerSatuan): int
{
    return pecahStok($totalEceran, $isiPerSatuan)['dus_utuh'];
}

/**
 * Berapa satuan kecil (pcs) yang tersedia untuk dijual eceran.
 * Karena total_eceran adalah source of truth, SEMUA stok (termasuk yang
 * masih "terbungkus" dalam dus utuh) bisa dipecah untuk dijual eceran.
 */
function stokTersediaEceran(float $totalEceran): float
{
    return $totalEceran;
}

/** Format angka biar tidak nongol "22.0" kalau kebetulan bulat. */
function formatAngka(float $n): string
{
    return (fmod($n, 1) === 0.0) ? (string) (int) $n : rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}