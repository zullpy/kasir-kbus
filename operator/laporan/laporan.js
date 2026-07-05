document.addEventListener('DOMContentLoaded', () => {
    const posDate = document.getElementById('posDate');
    const tanggalInput = document.getElementById('tanggal');

    if (posDate) {
        const now = new Date();
        posDate.textContent = now.toLocaleDateString('id-ID', {
            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
        });
    }

    // Langsung tampilkan laporan saat tanggal diganti, tanpa perlu klik tombol
    if (tanggalInput) {
        tanggalInput.addEventListener('change', () => {
            tanggalInput.closest('form').submit();
        });
    }
});