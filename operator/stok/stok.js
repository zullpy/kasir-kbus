/* ==========================================================================
   stok.js
   Interaksi tabel Stok Barang: buka modal verifikasi faktual, toggle
   status Sesuai / Tidak Sesuai, validasi keterangan, dan simpan via AJAX.
   ========================================================================== */

(function () {
    'use strict';

    var overlay        = document.getElementById('modalVerifikasi');
    var judulBarang     = document.getElementById('modalNamaBarang');
    var infoQty         = document.getElementById('modalInfoQty');
    var infoHarga       = document.getElementById('modalInfoHarga');
    var btnSesuai       = document.getElementById('btnPilihSesuai');
    var btnTidakSesuai  = document.getElementById('btnPilihTidakSesuai');
    var blokKeterangan  = document.getElementById('blokKeterangan');
    var inputQtyFisik   = document.getElementById('inputQtyFisik');
    var modalSatuanBesar = document.getElementById('modalSatuanBesar');
    var inputKeterangan = document.getElementById('inputKeterangan');
    var pesanError      = document.getElementById('pesanError');
    var btnSimpan       = document.getElementById('btnSimpanVerifikasi');
    var btnBatal        = document.getElementById('btnBatalVerifikasi');
    var inputVerifikasiId = document.getElementById('inputVerifikasiId');

    var statusTerpilih = null;

    function bukaModal(tombol) {
        var data = tombol.dataset;

        inputVerifikasiId.value = data.verifikasiId;
        judulBarang.textContent = data.namaBarang;
        infoQty.textContent = data.qtyText;
        infoHarga.textContent = data.hargaText;

        // Set ulang state modal ke kondisi awal
        statusTerpilih = data.statusSaatIni || null;
        inputKeterangan.value = data.keteranganSaatIni || '';
        inputQtyFisik.value = data.qtyFisikSaatIni || '';
        modalSatuanBesar.textContent = data.satuanBesar || 'Dus';
        sembunyikanError();
        perbaruiTampilanStatus();

        overlay.classList.add('terbuka');
    }

    function tutupModal() {
        overlay.classList.remove('terbuka');
    }

    function perbaruiTampilanStatus() {
        btnSesuai.classList.toggle('aktif', statusTerpilih === 'sesuai');
        btnTidakSesuai.classList.toggle('aktif', statusTerpilih === 'tidak_sesuai');

        if (statusTerpilih === 'tidak_sesuai') {
            blokKeterangan.classList.add('tampil');
        } else {
            blokKeterangan.classList.remove('tampil');
        }
    }

    function tampilkanError(pesan) {
        pesanError.textContent = pesan;
        pesanError.classList.add('tampil');
    }

    function sembunyikanError() {
        pesanError.textContent = '';
        pesanError.classList.remove('tampil');
    }

    function tampilkanToast(pesan, gagal) {
        var toast = document.createElement('div');
        toast.className = 'toast' + (gagal ? ' gagal' : '');
        toast.textContent = pesan;
        document.body.appendChild(toast);

        requestAnimationFrame(function () {
            toast.classList.add('tampil');
        });

        setTimeout(function () {
            toast.classList.remove('tampil');
            setTimeout(function () { toast.remove(); }, 250);
        }, 2500);
    }

    function simpanVerifikasi() {
        sembunyikanError();

        if (!statusTerpilih) {
            tampilkanError('Pilih salah satu: Sesuai atau Tidak Sesuai.');
            return;
        }

        var qtyFisik = inputQtyFisik.value.trim();
        if (statusTerpilih === 'tidak_sesuai' && (qtyFisik === '' || isNaN(qtyFisik) || Number(qtyFisik) < 0)) {
            tampilkanError('Qty hasil hitung fisik wajib diisi dengan angka yang valid.');
            inputQtyFisik.focus();
            return;
        }

        var keterangan = inputKeterangan.value.trim();
        if (statusTerpilih === 'tidak_sesuai' && keterangan === '') {
            tampilkanError('Keterangan wajib diisi jika stok tidak sesuai.');
            inputKeterangan.focus();
            return;
        }

        var formData = new FormData();
        formData.append('verifikasi_id', inputVerifikasiId.value);
        formData.append('status', statusTerpilih);
        formData.append('qty_fisik', statusTerpilih === 'tidak_sesuai' ? qtyFisik : '');
        formData.append('keterangan', statusTerpilih === 'tidak_sesuai' ? keterangan : '');

        btnSimpan.disabled = true;
        btnSimpan.textContent = 'Menyimpan...';

        fetch('../../database/verifikasi-proses.php', {
            method: 'POST',
            body: formData
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    tutupModal();
                    tampilkanToast(data.message || 'Verifikasi berhasil disimpan.', false);
                    setTimeout(function () { window.location.reload(); }, 700);
                } else {
                    tampilkanError(data.message || 'Gagal menyimpan verifikasi.');
                }
            })
            .catch(function () {
                tampilkanError('Terjadi kesalahan jaringan. Coba lagi.');
            })
            .finally(function () {
                btnSimpan.disabled = false;
                btnSimpan.textContent = 'Simpan Verifikasi';
            });
    }

    document.addEventListener('click', function (e) {
        var tombolBuka = e.target.closest('.btn-verifikasi');
        if (tombolBuka) {
            bukaModal(tombolBuka);
            return;
        }

        if (e.target === overlay) {
            tutupModal();
        }
    });

    btnSesuai.addEventListener('click', function () {
        statusTerpilih = 'sesuai';
        sembunyikanError();
        perbaruiTampilanStatus();
    });

    btnTidakSesuai.addEventListener('click', function () {
        statusTerpilih = 'tidak_sesuai';
        sembunyikanError();
        perbaruiTampilanStatus();
        inputKeterangan.focus();
    });

    btnBatal.addEventListener('click', tutupModal);
    btnSimpan.addEventListener('click', simpanVerifikasi);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('terbuka')) {
            tutupModal();
        }
    });

    // Jam / tanggal pada sidebar (elemen #posDate sudah ada di sidebar.php)
    var elTanggal = document.getElementById('posDate');
    if (elTanggal) {
        var formatter = new Intl.DateTimeFormat('id-ID', {
            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
        });
        elTanggal.textContent = formatter.format(new Date());
    }
})();