---
name: php-native-pos-audit-and-fix
description: Audit and fix a PHP Native POS/cashier application — covering multi-DB data-source inconsistencies, checkout API design, cross-DB JOINs, sidebar path bugs, session guards, and kasir profile UI.
source: auto-skill
extracted_at: '2026-07-05T08:10:52.077Z'
---

# PHP Native POS — Audit & Fix Playbook

Learned from fixing the **KBUS Mart** cashier application (PHP Native + MySQL + vanilla JS).

---

## 1. Multi-database data-source bug (Critical)

**Symptom:** Product prices show as Rp0 on the POS screen even though the database has prices.

**Root cause pattern:** Two databases serve different roles — one for stock/logistics (`db_mbg`, no price column), one for the product catalogue with prices (`db_draft_barang`). The PHP page correctly injects products from the price DB into `window.PRODUCTS`, but the JS `init()` then immediately calls `refreshProducts()` which fetches from an API endpoint backed by the *wrong* (no-price) database, overwriting all prices with 0.

**Fix:**
1. Rewrite `api/get-products.php` to query the catalogue DB (`db_draft_barang.barang`) instead of the logistics DB.
2. In `operator/script.js`, remove `await refreshProducts()` from `init()` — let `window.PRODUCTS` (injected by PHP) be the authoritative initial dataset. Keep `refreshProducts()` called only *after* a successful checkout so stale stock counts are refreshed post-transaction.

```js
// BEFORE (broken)
async function init() {
    await refreshProducts(); // overwrites PHP-injected prices with Rp0
    setDate();
    renderCart();
}

// AFTER (fixed)
function init() {
    setDate();
    renderProducts();  // uses window.PRODUCTS from PHP
    renderCart();
}
```

---

## 2. Missing checkout API endpoint (Critical)

The frontend `fetch('../api/checkout.php', { method: 'POST', ... })` will silently fail with a network error if the file does not exist. Always verify this file exists when the POS screen is present.

**`api/checkout.php` must:**
- Accept JSON body: `{ items: [{id, qty}], discount_percent, cash }`
- Validate each item against the catalogue DB (existence + stock sufficiency)
- Calculate: `subtotal → discountAmount → total → kembalian`
- Guard cash sufficiency server-side (never trust client)
- Generate a readable transaction code: `TRX-YYYYMMDD-XXXX` (pad sequence to 4 digits, count by `DATE(tanggal) = CURDATE()`)
- Wrap ALL DB writes in a single `mysqli_begin_transaction / commit / rollback` block across two operations:
  1. INSERT into `transaksi` + `transaksi_detail` (in `db_kasir`)
  2. UPDATE `stok_akhir = stok_akhir - qty` for each item (in `db_draft_barang`)
- Use prepared statements for every query
- Return JSON: `{ success, kode_transaksi, subtotal, diskon, total, cash, change, print: { success } }`

---

## 3. Table name inconsistency

**Pattern:** When two pages query the same table for related data, check that the table name is identical in both files.

In this project `laporan-harian.php` queried `detail_transaksi` while `riwayat-transaksi.php` correctly queried `transaksi_detail`. One of them will always produce an empty result or a MySQL error.

**How to catch it:** grep both files for the table name pattern:
```bash
grep -r "FROM detail_transaksi\|FROM transaksi_detail" operator/
```
Unify to whichever name the actual schema uses.

---

## 4. Session guard pattern

None of the operator pages protected routes by default. Always add a guard at the very top (before any HTML output):

**`includes/auth_guard.php`:**
```php
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'operator') {
    header('Location: ' . str_repeat('../', substr_count($_SERVER['PHP_SELF'], '/') - 2) . 'index.php');
    exit;
}
```

Then in every operator page:
```php
require_once '../includes/auth_guard.php';   // operator/index.php
require_once '../../includes/auth_guard.php'; // operator/riwayat/ and operator/laporan/
```

The `str_repeat('../', ...)` trick dynamically computes the correct relative path to `index.php` regardless of nesting depth.

---

## 5. Sidebar kasir profile UI

**Pattern:** Display a card in the sidebar footer showing the logged-in cashier's avatar, name, and branch location — sourced from `$_SESSION['branch']`.

```php
// In partials/sidebar.php
$__branch = $_SESSION['branch'] ?? '';
$__kasirMap = [
    'sodonghilir' => ['nama' => 'Kasir Sodonghilir', 'lokasi' => 'Kp. Cibengang, Sodonghilir'],
    'sariwangi'   => ['nama' => 'Kasir Sariwangi',   'lokasi' => 'Sariwangi, Tasikmalaya'],
    'manonjaya'   => ['nama' => 'Kasir Manonjaya',   'lokasi' => 'Manonjaya, Tasikmalaya'],
];
$__kasirInfo = $__kasirMap[$__branch] ?? ['nama' => 'Kasir', 'lokasi' => '-'];
```

**Structure at the bottom of the sidebar:**
```
.sidebar-footer          ← margin-top: auto (pushes to bottom)
  .kasir-card            ← glassmorphism card (rgba bg + border)
    .kasir-avatar        ← circle with person SVG icon
    .kasir-info
      .kasir-nama        ← bold, truncated
      .kasir-lokasi      ← pin icon + text, muted
  .pos-date              ← date injected by JS
  a.logout-btn           ← relative path back to index.php
```

The logout link path is computed with the same `str_repeat('../', ...)` approach so the partial works correctly when included from any depth.

---

## 6. Sidebar navigation path fix

When a single `partials/sidebar.php` is included from pages at *different* directory depths (e.g., `operator/index.php` vs `operator/riwayat/riwayat-transaksi.php`), relative hrefs like `../operator/riwayat/...` break for some callers.

**Fix:** Use `$__current = basename($_SERVER['PHP_SELF'])` to detect which page is active, then conditionally output the correct relative path:

```php
<a href="<?= $__current === 'index.php' ? 'index.php' : '../operator/index.php' ?>">
```

This keeps the sidebar as a single shared partial without introducing an absolute URL or a base-href.

---

## Checklist for any PHP Native POS audit

- [ ] Verify all API endpoints called by JS actually exist on disk
- [ ] Confirm product data source (price DB) is consistent between PHP page injection and API endpoint
- [ ] Check that table names are identical across all PHP files that reference them
- [ ] Ensure every operator/admin page has a session role check at the top
- [ ] Verify checkout uses a DB transaction (BEGIN/COMMIT/ROLLBACK) wrapping both insert and stock update
- [ ] Confirm sidebar partial navigation paths work from all include depths
- [ ] Check for hardcoded credentials and flag for replacement with DB-backed auth + `password_verify`
