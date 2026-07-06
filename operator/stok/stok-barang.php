<?php
/**
 * stok-barang.php
 * Halaman Stok Barang: menampilkan daftar barang (No, Nama Barang, Lokasi, Qty)
 * lengkap dengan kolom Verifikasi Faktual (modal Sesuai / Tidak Sesuai + keterangan).
 *
 * Sumber data:
 * - Qty / stok barang  -> db_mbg.stok_barang
 * - Harga jual         -> db_draft_barang.barang
 * - Riwayat verifikasi -> db_kasir.verifikasi_stok
 *
 * Aturan bulanan:
 * - Di awal bulan baru, stok akhir bulan sebelumnya otomatis menjadi
 *   persediaan awal bulan berjalan, dan status verifikasi faktual
 *   direset kosong lagi. Logika ini ada di stok-fungsi.php (jalankan_rollover_bulanan).
 *
 * Letakkan file ini di: /operator/stok/stok-barang.php
 * (path ini harus sama dengan link di sidebar.php supaya menu "Stok Barang"
 * ter-highlight aktif)
 */

session_start();

require_once __DIR__ . '/../../database/koneksi.php';
require_once __DIR__ . '/stok-fungsi.php';

// TODO: tambahkan pengecekan login/session di sini jika belum ada,
// contoh: if (!isset($_SESSION['branch'])) { header('Location: /index.php'); exit; }

jalankan_rollover_bulanan($koneksi_mbg, $koneksi_kasir);
$daftar_stok = ambil_data_stok($koneksi_mbg, $koneksi_kasir, $koneksi_draft);

// Ringkasan status verifikasi untuk kartu kecil di atas tabel
$total_barang   = count($daftar_stok);
$total_sesuai   = 0;
$total_tidak    = 0;
$total_belum    = 0;

foreach ($daftar_stok as $item) {
    switch ($item['status_verifikasi']) {
        case 'sesuai':
            $total_sesuai++;
            break;
        case 'tidak_sesuai':
            $total_tidak++;
            break;
        default:
            $total_belum++;
    }
}

// Total nilai stok yang belum terjual = Qty (stok akhir, dalam PCS) x harga BELI
// per pcs, dijumlah semua barang. Pakai harga_beli_eceran (barang.harga_eceran)
// karena kolom itu sudah harga modal DALAM SATUAN KECIL (pcs) -- tidak perlu
// dikonversi/dibagi isi_per_satuan lagi, beda dengan harga_jual yang per DUS.
// Total selisih = jumlah Qty Fisik - Qty Sistem (snapshot saat verifikasi) dari SEMUA barang
// berstatus 'tidak_sesuai' yang sudah punya data qty_fisik, dijumlah langsung sebagai angka
// (bukan dikonversi ke rupiah). Contoh: barang A selisih 1, barang B selisih 3 -> total 4.
$total_nilai_stok   = 0;
$total_selisih_qty  = 0;

foreach ($daftar_stok as $item) {
    $total_nilai_stok += ((float) $item['qty']) * ((float) $item['harga_beli_eceran']);

    if (
        $item['status_verifikasi'] === 'tidak_sesuai'
        && $item['qty_fisik'] !== null
        && $item['qty_sistem_saat_verifikasi'] !== null
    ) {
        $selisih_qty = (float) $item['qty_fisik'] - (float) $item['qty_sistem_saat_verifikasi'];
        $total_selisih_qty += $selisih_qty;
    }
}

$nama_bulan = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];
$periode_aktif = tentukan_periode_aktif(); // format Y-m
[$tahun_aktif, $bulan_aktif] = explode('-', $periode_aktif);
$label_periode = $nama_bulan[(int) $bulan_aktif] . ' ' . $tahun_aktif;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Stok Barang - KBUS</title>
<link rel="stylesheet" href="../style.css">
<link rel="stylesheet" href="stok.css">
</head>
<body>

<div class="pos-layout">
    <?php require __DIR__ . '/../../partials/sidebar.php'; ?>

    <main class="pos-main">
        <div class="stok-header">
            <div>
                <h1>Stok Barang</h1>
                <p>Daftar stok barang beserta verifikasi faktual periode berjalan.</p>
            </div>
            <span class="stok-periode-badge">Periode <?= htmlspecialchars($label_periode) ?></span>
        </div>

        <div class="stok-ringkasan">
            <div class="ringkasan-item total">
                <span class="label">Total Barang</span>
                <span class="value"><?= $total_barang ?></span>
            </div>
            <div class="ringkasan-item sesuai">
                <span class="label">Sudah Sesuai</span>
                <span class="value"><?= $total_sesuai ?></span>
            </div>
            <div class="ringkasan-item tidak-sesuai">
                <span class="label">Tidak Sesuai</span>
                <span class="value"><?= $total_tidak ?></span>
            </div>
            <div class="ringkasan-item belum">
                <span class="label">Belum Diverifikasi</span>
                <span class="value"><?= $total_belum ?></span>
            </div>
        </div>

        <div class="stok-table-wrap">
            <?php if (empty($daftar_stok)) : ?>
                <div class="stok-empty">Belum ada data barang.</div>
            <?php else : ?>
            <table class="stok-table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama Barang</th>
                        <th>Lokasi</th>
                        <th>Persediaan Awal</th>
                        <th>Qty (Stok Akhir)</th>
                        <th>Harga Beli</th>
                        <th>Verifikasi Faktual</th>
                        <th>Selisih</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                <?php $no = 1; foreach ($daftar_stok as $item) :
                    $qty_text = format_qty_dus_pcs(
                        $item['qty'],
                        $item['satuan_besar'],
                        $item['satuan_eceran'],
                        $item['isi_per_satuan']
                    );

                    $persediaan_awal_text = $item['persediaan_awal_qty'] !== null
                        ? format_qty_dus_pcs(
                            $item['persediaan_awal_qty'],
                            $item['satuan_besar'],
                            $item['satuan_eceran'],
                            $item['isi_per_satuan']
                        )
                        : '-';

                    $harga_text = format_rupiah($item['harga_beli']);
                    $status     = $item['status_verifikasi'] ?: '';
                    $keterangan = $item['keterangan'] ?? '';

                    $selisih = format_selisih(
                        $item,
                        $item['satuan_besar'],
                        $item['satuan_eceran'],
                        $item['isi_per_satuan']
                    );

                    if ($status === 'sesuai') {
                        $badge_class = 'badge-sesuai';
                        $badge_label = 'Sesuai';
                    } elseif ($status === 'tidak_sesuai') {
                        $badge_class = 'badge-tidak-sesuai';
                        $badge_label = 'Tidak Sesuai';
                    } else {
                        $badge_class = 'badge-kosong';
                        $badge_label = 'Belum Diverifikasi';
                    }
                ?>
                    <tr>
                        <td class="col-no"><?= $no++ ?></td>
                        <td class="col-nama"><?= htmlspecialchars($item['nama_barang']) ?></td>
                        <td><?= htmlspecialchars($item['lokasi'] ?? '-') ?></td>
                        <td class="col-angka"><?= htmlspecialchars($persediaan_awal_text) ?></td>
                        <td class="col-angka"><?= htmlspecialchars($qty_text) ?></td>
                        <td class="col-angka"><?= htmlspecialchars($harga_text) ?></td>
                        <td>
                            <span class="badge <?= $badge_class ?>"><?= $badge_label ?></span>
                            <br>
                            <button
                                type="button"
                                class="btn-verifikasi"
                                style="margin-top:6px;"
                                data-verifikasi-id="<?= (int) $item['verifikasi_id'] ?>"
                                data-nama-barang="<?= htmlspecialchars($item['nama_barang'], ENT_QUOTES) ?>"
                                data-qty-text="<?= htmlspecialchars($qty_text, ENT_QUOTES) ?>"
                                data-harga-text="<?= htmlspecialchars($harga_text, ENT_QUOTES) ?>"
                                data-status-saat-ini="<?= htmlspecialchars($status, ENT_QUOTES) ?>"
                                data-keterangan-saat-ini="<?= htmlspecialchars($keterangan, ENT_QUOTES) ?>"
                                data-qty-fisik-saat-ini="<?= htmlspecialchars((string) ($item['qty_fisik'] ?? ''), ENT_QUOTES) ?>"
                                data-satuan-besar="<?= htmlspecialchars($item['satuan_besar'] ?: 'Dus', ENT_QUOTES) ?>"
                            >
                                Verifikasi
                            </button>
                        </td>
                        <td>
                            <span class="selisih <?= $selisih['class'] ?>"><?= htmlspecialchars($selisih['text']) ?></span>
                        </td>
                        <td class="col-keterangan">
                            <?= $keterangan !== '' ? htmlspecialchars($keterangan) : '-' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" class="col-total-label">Total Nilai Stok Belum Terjual</td>
                        <td class="col-angka"><?= htmlspecialchars(format_rupiah($total_nilai_stok)) ?></td>
                        <td></td>
                        <td>
                            <?php
                                $kelas_total_selisih = 'selisih-nol';
                                if ($total_selisih_qty > 0) {
                                    $kelas_total_selisih = 'selisih-lebih';
                                } elseif ($total_selisih_qty < 0) {
                                    $kelas_total_selisih = 'selisih-kurang';
                                }
                                $tanda_total = $total_selisih_qty > 0 ? '+' : ($total_selisih_qty < 0 ? '-' : '');
                            ?>
                            <span class="selisih <?= $kelas_total_selisih ?>"><?= $tanda_total ?><?= htmlspecialchars(format_angka(abs($total_selisih_qty))) ?></span>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- ==================== Modal Verifikasi Faktual ==================== -->
<div class="modal-overlay" id="modalVerifikasi">
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="modalNamaBarang">Nama Barang</h2>
            <div class="modal-subjudul">Verifikasi faktual stok barang</div>
        </div>
        <div class="modal-body">
            <div class="modal-ringkasan-barang">
                <div><span class="k">Qty saat ini:</span> <strong id="modalInfoQty">-</strong></div>
                <div><span class="k">Harga jual:</span> <strong id="modalInfoHarga">-</strong></div>
            </div>

            <div class="pilihan-status">
                <button type="button" id="btnPilihSesuai" class="pilih-sesuai">✓ Sesuai</button>
                <button type="button" id="btnPilihTidakSesuai" class="pilih-tidak-sesuai">✕ Tidak Sesuai</button>
            </div>

            <div class="blok-keterangan" id="blokKeterangan">
                <label for="inputQtyFisik">Qty Hasil Hitung Fisik (<span id="modalSatuanBesar">Dus</span>)</label>
                <input type="number" id="inputQtyFisik" step="0.01" min="0" placeholder="Contoh: 12.5">

                <label for="inputKeterangan" style="margin-top:10px;">Keterangan (wajib diisi)</label>
                <textarea id="inputKeterangan" placeholder="Contoh: selisih 2 pcs karena barang rusak"></textarea>
            </div>

            <div class="pesan-error" id="pesanError"></div>
            <input type="hidden" id="inputVerifikasiId" value="">
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-batal" id="btnBatalVerifikasi">Batal</button>
            <button type="button" class="btn btn-simpan" id="btnSimpanVerifikasi">Simpan Verifikasi</button>
        </div>
    </div>
</div>

<script src="stok.js"></script>
</body>
</html>