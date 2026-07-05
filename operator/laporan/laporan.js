document.addEventListener('DOMContentLoaded', () => {
    const posDate = document.getElementById('posDate');
    const tanggalMulai = document.getElementById('tanggal_mulai');
    const tanggalSelesai = document.getElementById('tanggal_selesai');

    if (posDate) {
        const now = new Date();
        posDate.textContent = now.toLocaleDateString('id-ID', {
            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
        });
    }

    // Langsung tampilkan laporan saat salah satu tanggal diganti, tanpa perlu klik tombol
    [tanggalMulai, tanggalSelesai].forEach((input) => {
        if (input) {
            input.addEventListener('change', () => {
                input.closest('form').submit();
            });
        }
    });
});