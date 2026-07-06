<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../includes/auth_guard.php';
require_once '../database/koneksi.php';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kasir - KBUS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="shortcut icon" href="../assets/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <div class="pos-root">
        <?php require '../partials/sidebar.php'; ?>
        <div class="pos-main">
            <main class="kasir-grid">
                <section class="products-pane">
                    <div class="toolbar">
                        <div class="search-box">
                            <input type="text" id="searchInput" placeholder="Cari barang...">
                        </div>
                        <div class="chips" id="modeChips">
                            <button type="button" class="chip active" data-mode="semua">Semua</button>
                            <button type="button" class="chip" data-mode="grosir">Grosir</button>
                            <button type="button" class="chip" data-mode="eceran">Eceran</button>
                        </div>
                    </div>
                    <div class="product-grid" id="productGrid"></div>
                </section>
                <section class="receipt-pane">
                    <div class="receipt">
                        <div class="receipt-edge top"></div>
                        <div class="receipt-body">
                            <div class="receipt-header">
                                <div class="store-logo">
                                    <img src="../assets/struk.png" alt="KBUS" class="logo-kbus">
                                    <img src="../assets/logostruk.png" alt="BUS MART" class="logo-busmart">
                                </div>
                                <div class="store-address">
                                    <span>Kp. Cibengang Desa Sodonghilir</span>
                                    <span>Kecamatan Sodonghilir</span>
                                </div>
                            </div>
                            <div class="receipt-divider"></div>
                            <div class="receipt-meta">
                                <div class="receipt-meta-row"><b id="rhTransNo">#1</b></div>
                                <div class="receipt-meta-row split">
                                    <span>Tanggal : <b id="rhDate"></b></span>
                                    <span>Jam : <b id="rhTime"></b></span>
                                </div>
                            </div>
                            <div class="receipt-divider"></div>
                            <table class="receipt-table">
                                <thead>
                                    <tr>
                                        <th class="col-no">No</th>
                                        <th class="col-item">Item</th>
                                        <th class="col-qty">Qty</th>
                                        <th class="col-harga">Harga</th>
                                        <th class="col-subtotal">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody id="receiptLines">
                                    <tr>
                                        <td colspan="5" class="empty-note small">Belum ada barang di keranjang.</td>
                                    </tr>
                                </tbody>
                            </table>
                            <div class="receipt-divider"></div>
                            <div class="receipt-totals">
                                <div class="rt-row">
                                    <span>Subtotal</span>
                                    <span id="subtotalText">Rp0</span>
                                </div>
                                <div class="rt-row">
                                    <span>Diskon (%)</span>
                                    <div class="input-wrap">
                                        <input type="text" id="discountInput" inputmode="numeric" value="0">
                                        <span class="suffix">%</span>
                                    </div>
                                </div>
                                <div class="rt-row" id="discCutRow" style="display:none;">
                                    <span>Potongan</span>
                                    <span id="discCutText" class="text-danger">- Rp0</span>
                                </div>
                                <div class="rt-row rt-total">
                                    <span>Total</span>
                                    <span id="totalText">Rp0</span>
                                </div>
                                <div class="rt-row">
                                    <div class="payment-method-group" id="paymentMethodGroup">
                                        <label class="pm-option active">
                                            <input type="radio" name="paymentMethod" value="cash" checked>
                                            <svg class="pm-radio" viewBox="0 0 20 20" aria-hidden="true">
                                                <circle class="pm-radio-outer" cx="10" cy="10" r="8"></circle>
                                                <circle class="pm-radio-dot" cx="10" cy="10" r="4"></circle>
                                            </svg>
                                            <span>Cash</span>
                                        </label>
                                        <label class="pm-option">
                                            <input type="radio" name="paymentMethod" value="transfer">
                                            <svg class="pm-radio" viewBox="0 0 20 20" aria-hidden="true">
                                                <circle class="pm-radio-outer" cx="10" cy="10" r="8"></circle>
                                                <circle class="pm-radio-dot" cx="10" cy="10" r="4"></circle>
                                            </svg>
                                            <span>Transfer</span>
                                        </label>
                                        <label class="pm-option">
                                            <input type="radio" name="paymentMethod" value="qris">
                                            <svg class="pm-radio" viewBox="0 0 20 20" aria-hidden="true">
                                                <circle class="pm-radio-outer" cx="10" cy="10" r="8"></circle>
                                                <circle class="pm-radio-dot" cx="10" cy="10" r="4"></circle>
                                            </svg>
                                            <span>QRIS</span>
                                        </label>
                                    </div>
                                </div>
                                <div class="rt-row" id="cashRow">
                                    <span>Cash</span>
                                    <input type="text" id="cashInput" inputmode="numeric" placeholder="0">
                                </div>
                                <div class="rt-row" id="changeRow">
                                    <span>Kembalian</span>
                                    <span id="changeText">—</span>
                                </div>
                            </div>
                            <div class="receipt-divider"></div>
                            <p class="thanks-note">Terima kasih sudah belanja ditempat kami</p>
                            <button class="pay-btn" id="payBtn" disabled>Selesaikan Transaksi</button>
                        </div>
                        <div class="receipt-edge bottom"></div>
                    </div>
                </section>
            </main>
        </div>
        <div class="toast" id="toast"></div>

        <!-- Struk cetak thermal 80mm (hanya tampil saat window.print()) -->
        <div class="print-receipt" id="printReceipt">
            <div class="pr-header">
                <img src="../assets/struk.png" class="pr-logo-kbus" alt="KBUS">
                <img src="../assets/logostruk.png" class="pr-logo-busmart" alt="BUS MART">
            </div>
            <div class="pr-address">
                Kp. Cibengang Desa Sodonghilir<br>
                Kecamatan Sodonghilir
            </div>
            <div class="pr-divider"></div>
            <div class="pr-meta-row"><b id="prTransNo">#1</b></div>
            <div class="pr-meta-row pr-meta-split">
                <span>Tanggal: <b id="prDate"></b></span>
                <span>Jam: <b id="prTime"></b></span>
            </div>
            <div class="pr-divider"></div>
            <table class="pr-table">
                <thead>
                    <tr>
                        <th class="pr-col-no">No</th>
                        <th class="pr-col-item">Item</th>
                        <th class="pr-col-qty">Qty</th>
                        <th class="pr-col-harga">Harga</th>
                        <th class="pr-col-subtotal">Subtotal</th>
                    </tr>
                </thead>
                <tbody id="prLines"></tbody>
            </table>
            <div class="pr-divider"></div>
            <div class="pr-totals">
                <div class="pr-row"><span>Total Belanja</span><span id="prSubtotal">Rp0</span></div>
                <div class="pr-row"><span>Disc</span><span id="prDisc">Rp0</span></div>
                <div class="pr-row"><span id="prPayLabel">Cash</span><span id="prCash">Rp0</span></div>
                <div class="pr-row"><span>Change</span><span id="prChange">Rp0</span></div>
            </div>
            <div class="pr-divider"></div>
            <p class="pr-thanks">Terima kasih sudah belanja ditempat kami</p>
        </div>
    </div>
    <script>
        // Data produk awal dikirim dari PHP ke JS
    </script>
    <script src="script.js"></script>
</body>

</html>