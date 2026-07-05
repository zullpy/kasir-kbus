document.addEventListener('DOMContentLoaded', () => {
    // Elemen DOM
    const productGrid = document.getElementById('productGrid');
    const searchInput = document.getElementById('searchInput');
    const categoryChips = document.getElementById('categoryChips');
    const receiptLines = document.getElementById('receiptLines');
    const subtotalText = document.getElementById('subtotalText');
    const discountInput = document.getElementById('discountInput');
    const cashInput = document.getElementById('cashInput');
    const changeText = document.getElementById('changeText');
    const payBtn = document.getElementById('payBtn');
    const toast = document.getElementById('toast');
    const rhDate = document.getElementById('rhDate');
    const rhTime = document.getElementById('rhTime');
    const posDate = document.getElementById('posDate');

    // State
    let products = window.PRODUCTS || [];
    let cart = [];
    let activeCategory = 'Semua';
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

    // Render produk
    function renderProducts() {
        const filtered = products.filter((p) => {
            const matchQuery = p.name.toLowerCase().includes(searchQuery.toLowerCase());
            const matchCategory = activeCategory === 'Semua' || p.category === activeCategory;
            return matchQuery && matchCategory;
        });

        if (filtered.length === 0) {
            productGrid.innerHTML = '<div class="empty-note">Barang tidak ditemukan.</div>';
            return;
        }

        productGrid.innerHTML = filtered.map((p) => `
            <button class="product-card" data-id="${p.id}" ${p.stock <= 0 ? 'disabled' : ''}>
                <span class="pc-name">${escapeHtml(p.name)}</span>
                <span class="pc-price">${formatRupiah(p.price)}</span>
                <span class="pc-stock${p.stock <= 5 ? ' low' : ''}">
                    ${p.stock <= 0 ? 'Stok habis' : 'Stok: ' + p.stock}
                </span>
            </button>
        `).join('');
    }

    // Keranjang
    function addToCart(productId) {
        const product = products.find((p) => p.id === productId);
        if (!product || product.stock <= 0) return;

        const existing = cart.find((it) => it.id === productId);
        if (existing) {
            if (existing.qty >= product.stock) return;
            existing.qty += 1;
        } else {
            cart.push({ id: product.id, name: product.name, price: product.price, qty: 1, stock: product.stock });
        }
        renderCart();
    }

    function changeQty(productId, delta) {
        const item = cart.find((it) => it.id === productId);
        if (!item) return;

        const next = item.qty + delta;
        if (next > item.stock) return;

        if (next <= 0) {
            cart = cart.filter((it) => it.id !== productId);
        } else {
            item.qty = next;
        }
        renderCart();
    }

    function removeFromCart(productId) {
        cart = cart.filter((it) => it.id !== productId);
        renderCart();
    }

    function renderCart() {
        if (cart.length === 0) {
            receiptLines.innerHTML = '<tr><td colspan="5" class="empty-note small">Belum ada barang di keranjang.</td></tr>';
        } else {
            receiptLines.innerHTML = cart.map((it, idx) => `
                <tr class="line-item" data-id="${it.id}">
                    <td class="col-no">${idx + 1}</td>
                    <td class="col-item">${escapeHtml(it.name)}</td>
                    <td class="col-qty">
                        <div class="qty-control">
                            <button data-action="minus" data-id="${it.id}">&minus;</button>
                            <span>${it.qty}</span>
                            <button data-action="plus" data-id="${it.id}">+</button>
                        </div>
                    </td>
                    <td class="col-harga">${formatRupiah(it.price)}</td>
                    <td class="col-subtotal">
                        <span class="li-price">${formatRupiah(it.price * it.qty)}</span>
                        <button class="li-remove" data-action="remove" data-id="${it.id}" aria-label="Hapus barang">&times;</button>
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

        const canPay = cart.length > 0 && total > 0 && cash >= total;
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
            products = await res.json();
            renderProducts();
        } catch (err) {
            console.error('Gagal memuat ulang data produk:', err);
        }
    }

    async function checkout() {
        payBtn.disabled = true;
        payBtn.textContent = 'Memproses...';

        const rawDisc = discountInput.value.replace(/\./g, '');
        const discPercent = Number(rawDisc) || 0;

        const payload = {
            items: cart.map((it) => ({ id: it.id, qty: it.qty })),
            discount_percent: discPercent, // Kirim PERSEN, nominal dihitung ulang di server (lebih aman)
            cash: Number(cashInput.value.replace(/\./g, '')) || 0,
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

            const printInfo = data.print && data.print.success ? '· Struk tercetak' : '· Struk belum tercetak';
            showToast(`Transaksi ${data.kode_transaksi} berhasil · ${formatRupiah(data.total)} · Kembalian ${formatRupiah(data.change)} ${printInfo}`);
            cart = [];
            discountInput.value = '0';
            cashInput.value = '';
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

    productGrid.addEventListener('click', (e) => {
        const card = e.target.closest('.product-card');
        if (!card || card.disabled) return;
        addToCart(Number(card.dataset.id));
    });

    receiptLines.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-action]');
        if (!btn) return;
        const id = Number(btn.dataset.id);
        const action = btn.dataset.action;
        if (action === 'remove') removeFromCart(id);
        if (action === 'plus') changeQty(id, 1);
        if (action === 'minus') changeQty(id, -1);
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

    // Init
    // Di script.js, ganti bagian init dengan:
    async function init() {
        await refreshProducts();
        setDate();
        renderCart();
    }

    init();
});