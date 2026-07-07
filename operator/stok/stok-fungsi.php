<?php
/**
 * stok-fungsi.php
 * Kumpulan fungsi bantu untuk halaman Stok Barang:
 * - Rollover otomatis awal bulan (persediaan awal <- stok akhir bulan lalu)
 * - Reset status verifikasi faktual tiap awal bulan
 * - Ambil data gabungan stok_barang + harga_jual + verifikasi_stok untuk ditampilkan di tabel
 *
 * SUMBER DATA:
 *   - Qty / stok barang  -> database db_mbg,    tabel `stok_barang`
 *   - Harga jual + konversi satuan -> database db_draft_barang, tabel `barang`
 *   - Riwayat verifikasi -> database db_kasir,  tabel `verifikasi_stok`
 *
 * STRUKTUR TABEL `stok_barang` (db_mbg) -- SESUAI KOLOM ASLI DI DB:
 *   - id            INT (PK)   -> dipakai sebagai kunci ke `verifikasi_stok` di db_kasir
 *   - nama_barang   VARCHAR
 *   - satuan        VARCHAR    -> satuan besar, contoh: DUS
 *   - satuan_eceran VARCHAR    -> satuan kecil, contoh: PCS
 *   - lokasi        VARCHAR    -> cabang/lokasi barang ini
 *   - qty_grosir    DECIMAL    -> HANYA turunan/cache, disinkronkan ulang oleh
 *                                 checkout.php dari qty_eceran. TIDAK dipakai
 *                                 di file ini untuk hitungan, cuma buat
 *                                 kompatibilitas sistem lain yang baca kolom ini.
 *   - qty_eceran    DECIMAL    -> SOURCE OF TRUTH. Total stok dalam satuan
 *                                 KECIL (pcs/kg/dst), SUDAH termasuk dus utuh
 *                                 yang belum dibuka (bukan lagi qty dus
 *                                 fraksional seperti versi lama file ini).
 *   - updated_at    DATETIME
 *
 * PENTING (beda dari versi sebelumnya file ini): dulu file ini mengasumsikan
 * ada kolom tunggal `qty` dalam satuan besar yang bisa pecahan (misal 0.88
 * dus), dan mengonversi ke pcs dengan MENGALIKAN isi_per_satuan. Itu SUDAH
 * TIDAK BERLAKU. Sekarang kolom `qty` itu tidak ada; yang dipakai adalah
 * `qty_eceran` yang ISINYA SUDAH total pcs, jadi tidak perlu dikali lagi --
 * cukup dipecah balik (dibagi) untuk ditampilkan sebagai "X Dus Y Pcs".
 *
 * STRUKTUR TABEL `barang` (db_draft_barang), dipakai untuk harga & konversi satuan:
 *   - id_barang       INT (PK, auto_increment) -- TIDAK dipakai untuk pencocokan (lihat catatan di bawah)
 *   - nama_barang     VARCHAR(41)
 *   - harga_jual      BIGINT(20)   -> harga per satuan besar (dus)
 *   - satuan          VARCHAR(7)   -> satuan besar, contoh: DUS
 *   - satuan_eceran   VARCHAR(10)  -> satuan kecil, contoh: PCS
 *   - isi_per_satuan  DECIMAL(10,2) -> jumlah satuan eceran dalam 1 satuan besar, contoh 15
 *
 * PENTING: `id_barang` di tabel `barang` adalah auto_increment TERPISAH dari
 * `id` di `stok_barang` (dua tabel berbeda, urutan ID sendiri-sendiri), jadi
 * TIDAK BISA dicocokkan lewat ID (checkout.php pun mencocokkan lewat
 * nama_barang, bukan id, untuk alasan yang sama). Semua data dari tabel
 * `barang` di sini dicocokkan lewat NAMA BARANG yang sudah dinormalisasi
 * (lower-case + trim spasi).
 */

const KOLOM_ID_BARANG          = 'id';           // stok_barang.id
const KOLOM_NAMA_BARANG        = 'nama_barang';  // stok_barang.nama_barang
const KOLOM_SATUAN             = 'satuan';       // stok_barang.satuan (satuan besar, cuma buat fallback label)
const KOLOM_SATUAN_ECERAN_STOK = 'satuan_eceran'; // stok_barang.satuan_eceran
const KOLOM_LOKASI             = 'lokasi';       // stok_barang.lokasi
const KOLOM_QTY                = 'qty_eceran';   // stok_barang.qty_eceran -- SOURCE OF TRUTH (total satuan kecil)
const KOLOM_NAMA_BARANG_DRAFT  = 'nama_barang';  // barang.nama_barang (db_draft_barang)
const HARI_ROLLOVER_BULANAN    = 5;              // rollover & reset verifikasi baru jalan mulai tanggal segini
const KOLOM_HARGA_BELI         = 'harga_eceran';   // barang.harga_jual (harga JUAL per satuan besar/dus)
const KOLOM_HARGA_BELI_ECERAN  = 'harga_eceran'; // barang.harga_eceran (harga BELI/modal, SUDAH per satuan kecil/pcs)
const KOLOM_SATUAN_BESAR       = 'satuan';       // barang.satuan
const KOLOM_SATUAN_ECERAN      = 'satuan_eceran';// barang.satuan_eceran
const KOLOM_ISI_PER_SATUAN     = 'isi_per_satuan'; // barang.isi_per_satuan

/**
 * Normalisasi nama barang supaya pencocokan antar tabel tidak terganggu
 * spasi berlebih atau perbedaan huruf besar/kecil.
 */
function normalisasi_nama(string $nama): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', $nama)));
}

/**
 * Tentukan periode (Y-m) yang sedang "aktif" dipakai untuk data & verifikasi stok.
 *
 * Supaya data verifikasi bulan lalu tidak langsung hilang begitu tanggal 1
 * bulan baru, pergantian periode baru dianggap terjadi mulai tanggal
 * HARI_ROLLOVER_BULANAN (misal tanggal 5). Jadi:
 *  - Tanggal 1 s/d (HARI_ROLLOVER_BULANAN - 1): periode aktif MASIH bulan lalu
 *    (data & status verifikasi bulan lalu masih tampil apa adanya).
 *  - Tanggal HARI_ROLLOVER_BULANAN dan seterusnya: periode aktif sudah bulan
 *    berjalan (di titik inilah rollover benar-benar dieksekusi, lihat
 *    jalankan_rollover_bulanan()).
 */
function tentukan_periode_aktif(): string
{
    $hari_ini = (int) date('j');

    if ($hari_ini < HARI_ROLLOVER_BULANAN) {
        // Masih pakai periode bulan sebelumnya
        return date('Y-m', strtotime(date('Y-m-01') . ' -1 day'));
    }

    return date('Y-m');
}

/**
 * Pastikan setiap barang punya baris verifikasi_stok untuk periode berjalan.
 * stok_barang dibaca dari db_mbg, verifikasi_stok dibaca/ditulis di db_kasir.
 *
 * Kalau periode berjalan belum ada (artinya baru masuk bulan baru / pertama kali dipakai):
 *  - baris periode SEBELUMNYA (kalau ada) di-snapshot stok_akhir-nya dari
 *    qty_eceran stok_barang saat itu (total satuan kecil, SUDAH termasuk dus
 *    utuh yang belum dibuka -- bukan lagi fraksi dus seperti versi lama)
 *  - baris periode BARU dibuat dengan persediaan_awal = stok_akhir snapshot tadi (carry-over)
 *  - KHUSUS kalau ini periode PERTAMA untuk barang tsb (belum ada histori verifikasi
 *    sama sekali sebelumnya), persediaan_awal = 0, bukan qty live saat ini. Baru bulan
 *    depan persediaan_awal-nya ke-isi dari stok akhir bulan ini.
 *  - status_verifikasi & keterangan otomatis kosong (default tabel)
 */
function jalankan_rollover_bulanan(mysqli $koneksi_mbg, mysqli $koneksi_kasir): void
{
    $periode_sekarang = tentukan_periode_aktif();

    $sql_stok = "SELECT " . KOLOM_ID_BARANG . " AS id_barang,
                        " . KOLOM_QTY     . " AS qty,
                        " . KOLOM_SATUAN_ECERAN_STOK . " AS satuan
                 FROM stok_barang";
    $hasil_stok = mysqli_query($koneksi_mbg, $sql_stok);
    if (!$hasil_stok) {
        return;
    }

    while ($stok = mysqli_fetch_assoc($hasil_stok)) {
        $id_barang = (int) $stok['id_barang'];
        $qty       = (float) $stok['qty'];
        $satuan    = $stok['satuan'];
        $satuan_aman = mysqli_real_escape_string($koneksi_kasir, $satuan);

        $cek = mysqli_query(
            $koneksi_kasir,
            "SELECT id FROM verifikasi_stok
             WHERE id_barang = {$id_barang} AND periode = '{$periode_sekarang}'
             LIMIT 1"
        );

        if ($cek && mysqli_num_rows($cek) > 0) {
            continue; // periode berjalan sudah ada, tidak perlu rollover
        }

        // Cari baris periode terakhir (bulan sebelumnya) untuk barang ini
        $periode_lalu = mysqli_query(
            $koneksi_kasir,
            "SELECT id FROM verifikasi_stok
             WHERE id_barang = {$id_barang}
             ORDER BY periode DESC
             LIMIT 1"
        );

        $ada_periode_sebelumnya = $periode_lalu && mysqli_num_rows($periode_lalu) > 0;

        if ($ada_periode_sebelumnya) {
            $baris_lalu = mysqli_fetch_assoc($periode_lalu);

            // Tutup periode sebelumnya: catat stok akhir = qty live stok_barang saat rollover terjadi
            mysqli_query(
                $koneksi_kasir,
                "UPDATE verifikasi_stok
                 SET stok_akhir_qty = {$qty}, stok_akhir_satuan = '{$satuan_aman}'
                 WHERE id = {$baris_lalu['id']} AND stok_akhir_qty IS NULL"
            );
        }

        // Persediaan awal bulan berjalan:
        //  - Kalau sudah pernah ada periode sebelumnya -> carry-over dari stok akhir bulan lalu
        //    (qty live saat rollover ini, yang barusan juga dipakai menutup periode lalu di atas)
        //  - Kalau ini periode PERTAMA untuk barang ini (belum pernah ada histori verifikasi
        //    sama sekali) -> persediaan awal mulai dari 0, baru bulan depan ke-isi dari stok
        //    akhir bulan ini
        $persediaan_awal_qty = $ada_periode_sebelumnya ? $qty : 0;

        mysqli_query(
            $koneksi_kasir,
            "INSERT INTO verifikasi_stok
                (id_barang, periode, persediaan_awal_qty, persediaan_awal_satuan, status_verifikasi, keterangan)
             VALUES
                ({$id_barang}, '{$periode_sekarang}', {$persediaan_awal_qty}, '{$satuan_aman}', '', NULL)"
        );
    }
}

/**
 * Ambil data gabungan untuk ditampilkan di tabel:
 * - stok_barang diambil dari db_mbg. Kolom `qty` di array hasil di sini
 *   sebenarnya isinya qty_eceran (source of truth, total satuan kecil) --
 *   nama variabelnya tetap "qty" biar tidak mengubah nama field yang sudah
 *   dipakai stok-barang.php, tapi ISINYA sudah dalam satuan kecil.
 * - verifikasi_stok periode berjalan diambil dari db_kasir, digabung di PHP lewat id_barang
 * - harga_jual + konversi satuan (satuan besar/eceran/isi_per_satuan) diambil dari
 *   db_draft_barang.barang, digabung di PHP lewat NAMA BARANG (bukan id, lihat catatan di atas)
 *
 * @param string|null $lokasi_filter Kalau diisi (misal 'sariwangi'), hanya barang dengan
 *                                   lokasi yang sama (tidak case-sensitive) yang diambil.
 *                                   Dipakai untuk operator supaya hanya lihat stok cabangnya
 *                                   sendiri. Kalau null/kosong, semua lokasi diambil (untuk admin).
 */
function ambil_data_stok(mysqli $koneksi_mbg, mysqli $koneksi_kasir, mysqli $koneksi_draft, ?string $lokasi_filter = null): array
{
    $sql_stok = "SELECT
                    " . KOLOM_ID_BARANG   . " AS id_barang,
                    " . KOLOM_NAMA_BARANG . " AS nama_barang,
                    " . KOLOM_SATUAN      . " AS satuan,
                    " . KOLOM_LOKASI      . " AS lokasi,
                    " . KOLOM_QTY         . " AS qty
                 FROM stok_barang";

    // Operator hanya boleh melihat stok cabangnya sendiri (lokasi_filter diisi
    // dari $_SESSION['branch']). Admin memanggil fungsi ini dengan
    // $lokasi_filter = null sehingga semua cabang tampil, tidak difilter.
    if ($lokasi_filter !== null && $lokasi_filter !== '') {
        $lokasi_aman = mysqli_real_escape_string($koneksi_mbg, $lokasi_filter);
        $sql_stok   .= " WHERE LOWER(" . KOLOM_LOKASI . ") = LOWER('{$lokasi_aman}')";
    }

    $sql_stok .= " ORDER BY " . KOLOM_NAMA_BARANG . " ASC";

    $hasil = mysqli_query($koneksi_mbg, $sql_stok);
    $data  = [];

    if ($hasil) {
        while ($baris = mysqli_fetch_assoc($hasil)) {
            $baris['harga_jual']             = 0;
            $baris['harga_beli_eceran']      = 0; // modal per pcs (barang.harga_eceran), dipakai buat valuasi nilai stok
            $baris['satuan_besar']           = $baris['satuan']; // fallback kalau tidak ketemu di barang
            $baris['satuan_eceran']          = null;
            $baris['isi_per_satuan']         = 0;
            $baris['verifikasi_id']          = null;
            $baris['persediaan_awal_qty']    = null;
            $baris['persediaan_awal_satuan'] = null;
            $baris['status_verifikasi']      = '';
            $baris['keterangan']             = null;
            $baris['qty_fisik']              = null;
            $baris['qty_sistem_saat_verifikasi'] = null;
            $data[$baris['id_barang']]       = $baris;
        }
    }

    if (empty($data)) {
        return [];
    }

    $daftar_id        = array_map('intval', array_keys($data));
    $daftar_id_sql     = implode(',', $daftar_id);
    $periode_sekarang  = tentukan_periode_aktif();

    // ---- Gabungkan status verifikasi periode berjalan dari db_kasir (kunci: id_barang) ----
    $sql_verifikasi = "SELECT id, id_barang, persediaan_awal_qty, persediaan_awal_satuan,
                               status_verifikasi, keterangan, diverifikasi_oleh, diverifikasi_at,
                               qty_fisik, qty_sistem_saat_verifikasi
                        FROM verifikasi_stok
                        WHERE periode = '{$periode_sekarang}'
                          AND id_barang IN ({$daftar_id_sql})";

    $hasil_verifikasi = mysqli_query($koneksi_kasir, $sql_verifikasi);
    if ($hasil_verifikasi) {
        while ($v = mysqli_fetch_assoc($hasil_verifikasi)) {
            $id = $v['id_barang'];
            if (isset($data[$id])) {
                $data[$id]['verifikasi_id']              = $v['id'];
                $data[$id]['persediaan_awal_qty']        = $v['persediaan_awal_qty'];
                $data[$id]['persediaan_awal_satuan']     = $v['persediaan_awal_satuan'];
                $data[$id]['status_verifikasi']          = $v['status_verifikasi'];
                $data[$id]['keterangan']                 = $v['keterangan'];
                $data[$id]['diverifikasi_oleh']          = $v['diverifikasi_oleh'];
                $data[$id]['diverifikasi_at']            = $v['diverifikasi_at'];
                $data[$id]['qty_fisik']                  = $v['qty_fisik'];
                $data[$id]['qty_sistem_saat_verifikasi'] = $v['qty_sistem_saat_verifikasi'];
            }
        }
    }

    // ---- Gabungkan harga_jual + konversi satuan dari db_draft_barang (kunci: NAMA BARANG) ----
    $sql_barang = "SELECT " . KOLOM_NAMA_BARANG_DRAFT . " AS nama_barang,
                          " . KOLOM_HARGA_BELI         . " AS harga_eceran,
                          " . KOLOM_HARGA_BELI_ECERAN  . " AS harga_beli_eceran,
                          " . KOLOM_SATUAN_BESAR       . " AS satuan_besar,
                          " . KOLOM_SATUAN_ECERAN      . " AS satuan_eceran,
                          " . KOLOM_ISI_PER_SATUAN     . " AS isi_per_satuan
                   FROM barang";

    $hasil_barang = mysqli_query($koneksi_draft, $sql_barang);
    $peta_barang  = [];

    if ($hasil_barang) {
        while ($b = mysqli_fetch_assoc($hasil_barang)) {
            $kunci = normalisasi_nama($b['nama_barang']);
            $peta_barang[$kunci] = $b;
        }
    }

    foreach ($data as $id => $baris) {
        $kunci = normalisasi_nama($baris['nama_barang']);
        if (isset($peta_barang[$kunci])) {
            $b = $peta_barang[$kunci];
            $data[$id]['harga_beli']        = $b['harga_eceran'];
            $data[$id]['harga_beli_eceran'] = $b['harga_beli_eceran'];
            $data[$id]['satuan_besar']      = $b['satuan_besar'] ?: $baris['satuan'];
            $data[$id]['satuan_eceran']     = $b['satuan_eceran'];
            $data[$id]['isi_per_satuan']    = $b['isi_per_satuan'] !== null ? (float) $b['isi_per_satuan'] : 0;
        }
    }

    return array_values($data);
}

/**
 * Format angka: bulat tanpa desimal, pecahan maksimal 2 digit tanpa nol berlebih.
 */
function format_angka($angka): string
{
    $angka = (float) $angka;
    if (floor($angka) == $angka) {
        return number_format($angka, 0, ',', '.');
    }
    return rtrim(rtrim(number_format($angka, 2, ',', '.'), '0'), ',');
}

/**
 * Pecah TOTAL stok dalam satuan kecil (qty_eceran, sudah termasuk dus utuh
 * yang belum dibuka) jadi kombinasi satuan besar utuh + sisa satuan kecil,
 * memakai isi_per_satuan dari db_draft_barang.barang.
 * Contoh: totalEceran=47, isi_per_satuan=24 -> "1 Dus 23 Pcs".
 *
 * PENTING: berbeda dari versi lama fungsi ini -- input di sini SUDAH total
 * pcs, BUKAN qty dalam satuan besar yang perlu dikali isi_per_satuan lagi.
 * Mengalikannya lagi akan menghasilkan angka yang salah (dobel).
 *
 * Kalau isi_per_satuan tidak diketahui (0/null), barang dianggap tidak punya
 * varian satuan besar -> ditampilkan apa adanya dalam satuan KECIL, misal "47 Pcs".
 */
function format_qty_dus_pcs($totalEceran, ?string $satuanBesar, ?string $satuanEceran, $isiPerSatuan): string
{
    $totalEceran  = (float) $totalEceran;
    $isiPerSatuan = (float) $isiPerSatuan;

    $satuanBesarTampil  = $satuanBesar ? ucfirst(strtolower($satuanBesar)) : 'Dus';
    $satuanEceranTampil = $satuanEceran ? ucfirst(strtolower($satuanEceran)) : 'Pcs';

    if ($isiPerSatuan <= 0) {
        return trim(format_angka($totalEceran) . ' ' . $satuanEceranTampil);
    }

    $dusUtuh    = (int) floor(($totalEceran / $isiPerSatuan) + 0.0000001);
    $sisaEceran = round($totalEceran - ($dusUtuh * $isiPerSatuan), 2);

    // Jaga-jaga hasil pembulatan bikin sisa sama/lebih dari 1 satuan besar
    if ($sisaEceran >= $isiPerSatuan) {
        $dusUtuh++;
        $sisaEceran -= $isiPerSatuan;
    }

    $bagian = [];
    if ($dusUtuh > 0) {
        $bagian[] = $dusUtuh . ' ' . $satuanBesarTampil;
    }
    if ($sisaEceran > 0 || empty($bagian)) {
        $bagian[] = format_angka($sisaEceran) . ' ' . $satuanEceranTampil;
    }

    return implode(' ', $bagian);
}

/**
 * Format rupiah sederhana.
 */
function format_rupiah($angka): string
{
    return 'Rp' . number_format((float) $angka, 0, ',', '.');
}

/**
 * Hitung & format Selisih = Qty Hasil Hitung Fisik - Qty Sistem (snapshot saat
 * verifikasi disimpan, lihat verifikasi-proses.php).
 *
 * Aturan tampil:
 *  - Belum diverifikasi / status kosong -> '-' (belum ada data untuk dibandingkan)
 *  - Status 'sesuai'                    -> 'Sesuai' (dianggap tidak ada selisih)
 *  - Status 'tidak_sesuai' & qty_fisik ada -> selisih dihitung & ditampilkan dengan
 *    tanda +/- dan warna: kurang (minus) = merah, lebih (plus) = kuning/oranye
 *
 * Return: ['text' => string, 'class' => string] - class dipakai untuk styling di CSS.
 */
function format_selisih(array $item, ?string $satuanBesar, ?string $satuanEceran, $isiPerSatuan): array
{
    $status = $item['status_verifikasi'] ?: '';

    if ($status === 'sesuai') {
        return ['text' => 'Sesuai', 'class' => 'selisih-nol'];
    }

    if ($status !== 'tidak_sesuai' || $item['qty_fisik'] === null || $item['qty_sistem_saat_verifikasi'] === null) {
        return ['text' => '-', 'class' => ''];
    }

    $selisih = round((float) $item['qty_fisik'] - (float) $item['qty_sistem_saat_verifikasi'], 4);

    if (abs($selisih) < 0.0001) {
        return ['text' => 'Sesuai (0)', 'class' => 'selisih-nol'];
    }

    $tanda      = $selisih > 0 ? '+' : '-';
    $teks_angka = format_qty_dus_pcs(abs($selisih), $satuanBesar, $satuanEceran, $isiPerSatuan);
    $kelas      = $selisih < 0 ? 'selisih-kurang' : 'selisih-lebih';

    return ['text' => $tanda . ' ' . $teks_angka, 'class' => $kelas];
}