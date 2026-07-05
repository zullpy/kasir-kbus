document.addEventListener('DOMContentLoaded', () => {
    const posDate = document.getElementById('posDate');
    const searchTrx = document.getElementById('searchTrx');
    const trxTable = document.getElementById('trxTable');
    const rtCount = document.getElementById('rtCount');

    if (posDate) {
        const now = new Date();
        posDate.textContent = now.toLocaleDateString('id-ID', {
            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
        });
    }

    // Klik baris transaksi untuk buka/tutup rincian item
    if (trxTable) {
        trxTable.addEventListener('click', (e) => {
            const row = e.target.closest('.trx-row');
            if (!row) return;
            const id = row.dataset.id;
            const detailRow = trxTable.querySelector(`.detail-row[data-detail-for="${id}"]`);
            if (detailRow) detailRow.classList.toggle('hidden');
        });
    }

    // Pencarian cepat berdasarkan nomor transaksi (client-side, dari data yang sudah dimuat)
    if (searchTrx && trxTable) {
        searchTrx.addEventListener('input', () => {
            const q = searchTrx.value.trim().replace('#', '').toLowerCase();
            const rows = trxTable.querySelectorAll('.trx-row');
            let visibleCount = 0;

            rows.forEach((row) => {
                const id = row.dataset.id;
                const match = id.toLowerCase().includes(q);
                row.style.display = match ? '' : 'none';

                const detailRow = trxTable.querySelector(`.detail-row[data-detail-for="${id}"]`);
                if (detailRow && !match) detailRow.classList.add('hidden');
                if (detailRow) detailRow.style.display = match ? '' : 'none';

                if (match) visibleCount += 1;
            });

            if (rtCount) rtCount.textContent = visibleCount;
        });
    }
});