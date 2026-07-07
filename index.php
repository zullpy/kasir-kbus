<?php
session_start();

/**
 * Backend hook point.
 * Wire this up to your real authentication (DB check, password_verify, etc).
 * For now it only demonstrates the expected flow.
 *
 * Operator has 3 branch accounts (sodonghilir, sariwangi, manonjaya),
 * each with its own password.
 * Admin has 3 sub-akses (admin, ketua, bendahara), each with its own password.
 */
$validPasswords = [
    'admin' => [
        'admin'     => 'admin123',
        'ketua'     => 'ketua123',
        'bendahara' => 'bendahara123',
    ],
    'operator' => [
        'sodonghilir' => 'sodong123',
        'sariwangi'   => 'sariwangi123',
        'manonjaya'   => 'manonjaya123',
    ],
];

$error = '';
$errorRole = '';
$errorBranch = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['role'], $_POST['password'])) {
    $role      = $_POST['role'] === 'admin' ? 'admin' : 'operator';
    $password  = $_POST['password'];
    $branch    = isset($_POST['branch']) ? $_POST['branch'] : '';
    $adminRole = ''; // akan diisi otomatis berdasarkan password yang cocok

    if ($role === 'admin') {
        // Cari tahu password ini cocok dengan akses yang mana (admin/ketua/bendahara).
        $isValid = false;
        foreach ($validPasswords['admin'] as $key => $pass) {
            if ($password === $pass) {
                $isValid   = true;
                $adminRole = $key;
                break;
            }
        }
    } else {
        $isValid = isset($validPasswords['operator'][$branch]) && $password === $validPasswords['operator'][$branch];
    }

    if ($isValid) {
        $_SESSION['role'] = $role;
        if ($role === 'operator') {
            $_SESSION['branch'] = $branch;
        } else {
            $_SESSION['admin_role'] = $adminRole; // admin / ketua / bendahara, dipakai nanti untuk tanda tangan sesuai role
        }
        header('Location: ' . ($role === 'admin' ? 'operator/riwayat/riwayat-transaksi.php' : 'operator/index.php'));
        exit;
    } else {
        $error = ($role === 'operator' && !isset($validPasswords['operator'][$branch]))
            ? 'Silakan pilih kasir terlebih dahulu.'
            : 'Password salah, silakan coba lagi.';
        $errorRole = $role;
        $errorBranch = $branch;
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aplikasi Kasir | KBUS</title>
    <link rel="shortcut icon" href="./assets/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
</head>

<body>

    <main class="kiosk">

        <div class="brand">
            <img src="./assets/busmart.png" alt="Aplikasi Kasir - KBUS Mart">
            <span class="tagline">KBUS Mart</span>
        </div>

        <div class="select-heading">
            <h1>Masuk sebagai siapa hari ini?</h1>
            <p>Pilih peranmu untuk melanjutkan ke aplikasi kasir</p>
        </div>
        <div class="role-grid">
            <!-- Operator -->
            <button type="button" class="role-key operator" data-role="operator" data-open-modal>
                <span class="key-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="7" width="20" height="14" rx="2"></rect>
                        <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                    </svg>
                </span>
                <h2>Operator</h2>
                <p>Layani transaksi, cetak struk, dan kelola penjualan harian di kasir.</p>
                <span class="key-cta">
                    Masuk sebagai Operator
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14M13 6l6 6-6 6"></path>
                    </svg>
                </span>
            </button>
            <!-- Admin -->
            <button type="button" class="role-key admin" data-role="admin" data-open-modal>
                <span class="key-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="8" r="4"></circle>
                        <path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"></path>
                        <path d="M19 8l1.5 1.5L23 7"></path>
                    </svg>
                </span>
                <h2>Admin</h2>
                <p>Kelola produk, laporan, pengguna, dan pengaturan toko secara penuh.</p>
                <span class="key-cta">
                    Masuk sebagai Admin
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14M13 6l6 6-6 6"></path>
                    </svg>
                </span>
            </button>
        </div>
        <span class="footnote">&copy; <?php echo date('Y'); ?> Created by &middot; Muhammad Zulfahmi</span>
    </main>

    <!-- Password modal -->
    <div class="modal-overlay" id="modalOverlay">
        <form class="modal-card" id="modalCard" data-role="operator" method="POST" autocomplete="off">
            <button type="button" class="modal-close" id="modalClose" aria-label="Tutup">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M6 6l12 12M18 6L6 18"></path>
                </svg>
            </button>

            <span class="modal-role-badge" id="modalBadge"><span class="dot"></span> <span id="modalBadgeText">Operator</span></span>
            <h3 id="modalTitle">Masuk sebagai Operator</h3>
            <p class="modal-sub" id="modalSub">Pilih kasir untuk melanjutkan.</p>

            <input type="hidden" name="role" id="modalRoleInput" value="operator">
            <input type="hidden" name="branch" id="modalBranchInput" value="">

            <!-- Branch selection (operator only) -->
            <div class="branch-select" id="branchSelect">
                <label class="field-label">Pilih Kasir</label>
                <div class="branch-grid">
                    <button type="button" class="branch-opt" data-branch="sodonghilir">Sodonghilir</button>
                    <button type="button" class="branch-opt" data-branch="sariwangi">Sariwangi</button>
                    <button type="button" class="branch-opt" data-branch="manonjaya">Manonjaya</button>
                </div>
            </div>

            <!-- Kasir profile (photo + name), revealed after branch is chosen -->
            <div class="kasir-profile" id="kasirProfile">
                <div class="kasir-photo" id="kasirPhoto">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="8" r="4"></circle>
                        <path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"></path>
                    </svg>
                </div>
                <div class="kasir-meta">
                    <span class="kasir-name" id="kasirName">Nama belum diisi</span>
                    <span class="kasir-branch" id="kasirBranch"></span>
                </div>
            </div>

            <!-- Password section (revealed after branch/akses chosen) -->
            <div class="pass-section" id="passSection">
                <label class="field-label" for="modalPassword">Password</label>
                <div class="pass-wrap">
                    <input type="password" name="password" id="modalPassword" placeholder="Masukkan password" required>
                    <button type="button" class="pass-toggle" id="togglePassword" aria-label="Tampilkan password">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="error-msg" id="modalError"><?php echo htmlspecialchars($error); ?></div>

            <button type="submit" class="modal-submit" id="modalSubmit">Masuk</button>
        </form>
    </div>

    <script>
        window.serverError = {
            hasError: <?php echo (!empty($error)) ? 'true' : 'false'; ?>,
            role: '<?php echo htmlspecialchars($errorRole); ?>',
            branch: '<?php echo htmlspecialchars($errorBranch); ?>'
        };
    </script>
    <script src="script.js"></script>

</body>

</html>