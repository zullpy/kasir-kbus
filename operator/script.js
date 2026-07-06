document.addEventListener('DOMContentLoaded', () => {
    // Elemen DOM
    const productGrid = document.getElementById('productGrid');
    const searchInput = document.getElementById('searchInput');
    const categoryChips = document.getElementById('categoryChips');
    const modeChips = document.getElementById('modeChips');
    const receiptLines = document.getElementById('receiptLines');
    const subtotalText = document.getElementById('subtotalText');
    const discountInput = document.getElementById('discountInput');
    const cashInput = document.getElementById('cashInput');
    const changeText = document.getElementById('changeText');
    const payBtn = document.getElementById('payBtn');
    const toast = document.getElementById('toast');
    const rhDate = document.getElementById('rhDate');
    const rhTime = document.getElementById('rhTime');
    const rhTransNo = document.getElementById('rhTransNo');
    const posDate = document.getElementById('posDate');
    const paymentMethodGroup = document.getElementById('paymentMethodGroup');
    const cashRow = document.getElementById('cashRow');
    const changeRow = document.getElementById('changeRow');

    // State
    let activePaymentMethod = 'cash';
    let rawProducts = window.PRODUCTS || []; // data mentah dari API (per nama_barang)
    let displayItems = [];                   // hasil turunan sesuai mode aktif (grosir/eceran/semua)
    let cart = [];
    let activeCategory = 'Semua';
    let activeMode = 'semua'; // 'semua' | 'grosir' | 'eceran'
    let searchQuery = '';

    // Helpers
    function formatRupiah(n) {
        return 'Rp' + Math.round(n).toLocaleString('id-ID');
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Nomor transaksi harian: diambil dari database (tabel transaksi, db_kasir),
    // sumber yang sama dipakai checkout.php untuk generate kode_transaksi.
    // Jadi nomor di receipt-pane selalu sinkron & tercatat, walau dibuka
    // dari beberapa komputer/browser sekaligus.
    let todayTransactionCount = 0;

    // Ambil ulang jumlah transaksi hari ini dari server.
    async function fetchTodayTransactionCount() {
        try {
            const res = await fetch('../api/get-trans-count.php');
            const data = await res.json();
            todayTransactionCount = data.success ? (Number(data.count) || 0) : 0;
        } catch (err) {
            console.error('Gagal mengambil jumlah transaksi hari ini:', err);
        }
        return todayTransactionCount;
    }

    // Menampilkan nomor transaksi BERIKUTNYA (yang akan dibuat) di receipt pane.
    function updateTransactionNoDisplay() {
        if (!rhTransNo) return;
        rhTransNo.textContent = '#' + (todayTransactionCount + 1);
    }

    function setDate() {
        const now = new Date();
        const formatted = now.toLocaleDateString('id-ID', {
            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
        });
        rhDate.textContent = formatted;
        posDate.textContent = formatted;
        if (rhTime) {
            rhTime.textContent = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
        }
    }

    // Format input angka dengan pemisah ribuan (.)
    function formatNumberInput(input) {
        let value = input.value.replace(/\./g, '').replace(/\D/g, '');
        if (value === '') {
            input.value = '';
            return;
        }
        input.value = Number(value).toLocaleString('id-ID');
    }

    // Bangun daftar item tampilan (varian grosir & eceran) dari data mentah produk
    // sesuai mode aktif. Satu barang bisa menghasilkan 1 atau 2 item tampilan.
    function buildDisplayItems() {
        const items = [];

        rawProducts.forEach((p) => {
            // Stok grosir & eceran independen (masing-masing dari tabel stok_barang
            // dan stok_barang_eceran), tidak ada konversi antar satuan di sini.
            const hasEceran = Number(p.price_eceran) > 0 && !!p.satuan_eceran;

            const grosirItem = {
                uid: 'g' + p.id,
                baseId: p.id,
                mode: 'grosir',
                name: p.name,
                price: Number(p.price),
                stock: Number(p.stock),
                satuan: p.satuan || 'pcs',
                category: p.category,
            };

            const eceranItem = hasEceran ? {
                uid: 'e' + p.id,
                baseId: p.id,
                mode: 'eceran',
                name: p.name,
                price: Number(p.price_eceran),
                stock: Number(p.stock_eceran) || 0,
                satuan: p.satuan_eceran,
                category: p.category,
            } : null;

            if (activeMode === 'grosir') {
                items.push(grosirItem);
            } else if (activeMode === 'eceran') {
                if (eceranItem) items.push(eceranItem);
            } else {
                // semua
                items.push(grosirItem);
                if (eceranItem) items.push(eceranItem);
            }
        });

        return items;
    }

    // Render produk
    function renderProducts() {
        displayItems = buildDisplayItems();

        const filtered = displayItems.filter((p) => {
            const matchQuery = p.name.toLowerCase().includes(searchQuery.toLowerCase());
            const matchCategory = activeCategory === 'Semua' || p.category === activeCategory;
            return matchQuery && matchCategory;
        });

        if (filtered.length === 0) {
            productGrid.innerHTML = '<div class="empty-note">Barang tidak ditemukan.</div>';
            return;
        }

        productGrid.innerHTML = filtered.map((p) => `
            <button class="product-card" data-uid="${p.uid}" ${p.stock <= 0 ? 'disabled' : ''}>
                <span class="pc-mode ${p.mode}">${p.mode === 'eceran' ? 'Eceran' : 'Grosir'}</span>
                <span class="pc-name">${escapeHtml(p.name)}</span>
                <span class="pc-price">${formatRupiah(p.price)} <small>/ ${escapeHtml(p.satuan)}</small></span>
                <span class="pc-stock${p.stock <= 5 ? ' low' : ''}">
                    ${p.stock <= 0 ? 'Stok habis' : 'Stok: ' + p.stock + ' ' + escapeHtml(p.satuan)}
                </span>
            </button>
        `).join('');
    }

    // Keranjang
    function addToCart(uid) {
        const item = displayItems.find((p) => p.uid === uid);
        if (!item || item.stock <= 0) return;

        const existing = cart.find((it) => it.uid === uid);
        if (existing) {
            if (existing.qty >= item.stock) return;
            existing.qty += 1;
        } else {
            cart.push({
                uid: item.uid,
                baseId: item.baseId,
                mode: item.mode,
                name: item.name,
                price: item.price,
                satuan: item.satuan,
                qty: 1,
                stock: item.stock,
            });
        }
        renderCart();
    }

    function changeQty(uid, delta) {
        const item = cart.find((it) => it.uid === uid);
        if (!item) return;

        const next = item.qty + delta;
        if (next > item.stock) return;

        if (next <= 0) {
            cart = cart.filter((it) => it.uid !== uid);
        } else {
            item.qty = next;
        }
        renderCart();
    }

    function removeFromCart(uid) {
        cart = cart.filter((it) => it.uid !== uid);
        renderCart();
    }

    function renderCart() {
        if (cart.length === 0) {
            receiptLines.innerHTML = '<tr><td colspan="5" class="empty-note small">Belum ada barang di keranjang.</td></tr>';
        } else {
            receiptLines.innerHTML = cart.map((it, idx) => `
                <tr class="line-item" data-uid="${it.uid}">
                    <td class="col-no">${idx + 1}</td>
                    <td class="col-item">${escapeHtml(it.name)}${it.mode === 'eceran' ? ' <small>(ecr)</small>' : ''}</td>
                    <td class="col-qty">
                        <div class="qty-control">
                            <button data-action="minus" data-uid="${it.uid}">&minus;</button>
                            <span>${it.qty}</span>
                            <button data-action="plus" data-uid="${it.uid}">+</button>
                        </div>
                    </td>
                    <td class="col-harga">${formatRupiah(it.price)}</td>
                    <td class="col-subtotal">
                        <span class="li-price">${formatRupiah(it.price * it.qty)}</span>
                        <button class="li-remove" data-action="remove" data-uid="${it.uid}" aria-label="Hapus barang">&times;</button>
                    </td>
                </tr>
            `).join('');
        }
        updateTotals();
    }

    function updateTotals() {
        const subtotal = cart.reduce((sum, it) => sum + it.price * it.qty, 0);

        // Parse diskon (persen)
        const rawDisc = discountInput.value.replace(/\./g, '');
        let discPercent = 0;
        if (rawDisc !== '') {
            discPercent = Math.max(0, Math.min(100, Number(rawDisc) || 0));
        }

        const discountAmount = Math.round(subtotal * (discPercent / 100));
        const total = subtotal - discountAmount;

        // Parse cash
        const rawCash = cashInput.value.replace(/\./g, '');
        const cash = rawCash === '' ? 0 : (Number(rawCash) || 0);
        const change = cash - total;

        // Update UI
        subtotalText.textContent = formatRupiah(subtotal);

        const discCutRow = document.getElementById('discCutRow');
        const discCutText = document.getElementById('discCutText');
        if (discCutRow && discCutText) {
            if (discountAmount > 0) {
                discCutRow.style.display = 'flex';
                discCutText.textContent = '- ' + formatRupiah(discountAmount);
            } else {
                discCutRow.style.display = 'none';
            }
        }

        const totalText = document.getElementById('totalText');
        if (totalText) totalText.textContent = formatRupiah(total);

        changeText.textContent = cashInput.value === '' ? '—' : formatRupiah(Math.max(change, 0));
        changeText.classList.toggle('neg', change < 0);

        const canPay = cart.length > 0 && total > 0 &&
            (activePaymentMethod !== 'cash' || cash >= total);
        payBtn.disabled = !canPay;
    }

    function showToast(message) {
        toast.textContent = message;
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 2500);
    }

    async function refreshProducts() {
        try {
            const res = await fetch('../api/get-products.php');
            rawProducts = await res.json();
            renderProducts();
        } catch (err) {
            console.error('Gagal memuat ulang data produk:', err);
        }
    }

    // Isi & cetak struk thermal 80mm memakai data hasil checkout + isi keranjang
    // (dipanggil SEBELUM cart dikosongkan, supaya baris item masih ada).
    function printReceipt(result, cartSnapshot, discPercent) {
        const prTransNo = document.getElementById('prTransNo');
        const prDate = document.getElementById('prDate');
        const prTime = document.getElementById('prTime');
        const prLines = document.getElementById('prLines');
        const prSubtotal = document.getElementById('prSubtotal');
        const prDisc = document.getElementById('prDisc');
        const prPayLabel = document.getElementById('prPayLabel');
        const prCash = document.getElementById('prCash');
        const prChange = document.getElementById('prChange');

        if (!prTransNo) return;

        const now = new Date();
        // Nomor urut harian sederhana ("#1"), sama seperti yang tampil di
        // receipt-pane sebelum transaksi disimpan (rhTransNo), BUKAN kode_transaksi.
        prTransNo.textContent = rhTransNo ? rhTransNo.textContent : '#1';
        prDate.textContent = now.toLocaleDateString('id-ID', {
            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
        });
        prTime.textContent = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });

        prLines.innerHTML = cartSnapshot.map((it, idx) => `
            <tr>
                <td class="pr-col-no">${idx + 1}</td>
                <td class="pr-col-item">
                    <span class="pr-item-name">${escapeHtml(it.name)}${it.mode === 'eceran' ? ' <small>(ecr)</small>' : ''}</span>
                </td>
                <td class="pr-col-qty">${it.qty} ${escapeHtml(it.satuan || '')}</td>
                <td class="pr-col-harga">${formatRupiah(it.price)}</td>
                <td class="pr-col-subtotal">${formatRupiah(it.price * it.qty)}</td>
            </tr>
        `).join('');

        prSubtotal.textContent = formatRupiah(result.subtotal);
        prDisc.textContent = result.diskon > 0
            ? '- ' + formatRupiah(result.diskon) + (discPercent ? ` (${discPercent}%)` : '')
            : formatRupiah(0);

        const payLabelMap = { cash: 'Cash', transfer: 'Transfer', qris: 'QRIS' };
        prPayLabel.textContent = payLabelMap[result.metode_pembayaran] || 'Cash';
        prCash.textContent = formatRupiah(result.cash);
        prChange.textContent = formatRupiah(result.change);

        window.print();
    }

    async function checkout() {
        payBtn.disabled = true;
        payBtn.textContent = 'Memproses...';

        const rawDisc = discountInput.value.replace(/\./g, '');
        const discPercent = Number(rawDisc) || 0;

        const payload = {
            items: cart.map((it) => ({
                name: it.name,
                qty: it.qty,
                price: it.price,
                satuan: it.satuan,
                mode: it.mode,
            })),
            discount_percent: discPercent,
            metode_pembayaran: activePaymentMethod,
            cash: activePaymentMethod === 'cash' ? (Number(cashInput.value.replace(/\./g, '')) || 0) : 0,
        };

        try {
            const res = await fetch('../api/checkout.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await res.json();

            if (!data.success) {
                alert(data.message || 'Transaksi gagal diproses.');
                return;
            }

            showToast(`Transaksi ${data.kode_transaksi} berhasil · ${formatRupiah(data.total)} · Kembalian ${formatRupiah(data.change)}`);

            // Cetak struk thermal 80mm memakai snapshot keranjang saat ini
            // (dipanggil sebelum cart dikosongkan di bawah).
            printReceipt(data, cart, discPercent);

            await fetchTodayTransactionCount();
            updateTransactionNoDisplay();
            cart = [];
            discountInput.value = '0';
            cashInput.value = '';
            activePaymentMethod = 'cash';
            paymentMethodGroup.querySelectorAll('input[type="radio"]').forEach((r) => { r.checked = r.value === 'cash'; });
            paymentMethodGroup.querySelectorAll('.pm-option').forEach((l) => { l.classList.toggle('active', l.querySelector('input')?.value === 'cash'); });
            cashRow.style.display = '';
            changeRow.style.display = '';
            renderCart();
            await refreshProducts();
        } catch (err) {
            alert('Gagal terhubung ke server. Periksa koneksi atau coba lagi.');
        } finally {
            payBtn.textContent = 'Selesaikan Transaksi';
            updateTotals();
        }
    }

    // Event listeners
    searchInput.addEventListener('input', (e) => {
        searchQuery = e.target.value;
        renderProducts();
    });

    // Fix: Cek null untuk categoryChips agar tidak error jika elemen tidak ada di HTML
    if (categoryChips) {
        categoryChips.addEventListener('click', (e) => {
            const btn = e.target.closest('.chip');
            if (!btn) return;
            activeCategory = btn.dataset.category;
            [...categoryChips.children].forEach((c) => c.classList.toggle('active', c === btn));
            renderProducts();
        });
    }

    // Toggle mode Semua / Grosir / Eceran
    if (modeChips) {
        modeChips.addEventListener('click', (e) => {
            const btn = e.target.closest('.chip');
            if (!btn) return;
            activeMode = btn.dataset.mode;
            [...modeChips.children].forEach((c) => c.classList.toggle('active', c === btn));
            renderProducts();
        });
    }

    productGrid.addEventListener('click', (e) => {
        const card = e.target.closest('.product-card');
        if (!card || card.disabled) return;
        addToCart(card.dataset.uid);
    });

    if (paymentMethodGroup) {
        paymentMethodGroup.addEventListener('change', (e) => {
            const radio = e.target.closest('input[type="radio"]');
            if (!radio) return;
            activePaymentMethod = radio.value;
            paymentMethodGroup.querySelectorAll('.pm-option').forEach((label) => {
                label.classList.toggle('active', label.querySelector('input')?.value === activePaymentMethod);
            });
            const isCash = activePaymentMethod === 'cash';
            cashRow.style.display = isCash ? '' : 'none';
            changeRow.style.display = isCash ? '' : 'none';
            if (!isCash) cashInput.value = '';
            updateTotals();
        });
    }

    receiptLines.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-action]');
        if (!btn) return;
        const uid = btn.dataset.uid;
        const action = btn.dataset.action;
        if (action === 'remove') removeFromCart(uid);
        if (action === 'plus') changeQty(uid, 1);
        if (action === 'minus') changeQty(uid, -1);
    });

    // Format input saat diketik lalu update total
    discountInput.addEventListener('input', (e) => {
        formatNumberInput(e.target);
        updateTotals();
    });

    cashInput.addEventListener('input', (e) => {
        formatNumberInput(e.target);
        updateTotals();
    });

    payBtn.addEventListener('click', checkout);

    // Init: fetch produk dari API (stok db_mbg + harga db_draft_barang).
    // refreshProducts() juga dipanggil setelah checkout berhasil agar stok update.
    async function init() {
        await refreshProducts();
        setDate();
        await fetchTodayTransactionCount();
        updateTransactionNoDisplay();
        renderCart();
    }

    init();
});