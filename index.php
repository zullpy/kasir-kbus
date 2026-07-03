<?php
session_start();

/**
 * Backend hook point.
 * Wire this up to your real authentication (DB check, password_verify, etc).
 * For now it only demonstrates the expected flow.
 */
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['role'], $_POST['password'])) {
    $role     = $_POST['role'] === 'admin' ? 'admin' : 'operator';
    $password = $_POST['password'];

    // TODO: replace with real credential check
    $validPasswords = [
        'operator' => 'kasir123',
        'admin'    => 'admin123',
    ];

    if ($password === $validPasswords[$role]) {
        $_SESSION['role'] = $role;
        header('Location: ' . ($role === 'admin' ? 'admin/dashboard.php' : 'operator/kasir.php'));
        exit;
        $error = ''; // success placeholder — redirect goes here
    } else {
        $error = 'Password salah, silakan coba lagi.';
        $errorRole = $role;
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
            <p class="modal-sub" id="modalSub">Masukkan password untuk melanjutkan ke kasir.</p>

            <input type="hidden" name="role" id="modalRoleInput" value="operator">

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

            <div class="error-msg" id="modalError"><?php echo htmlspecialchars($error); ?></div>

            <button type="submit" class="modal-submit">Masuk</button>
        </form>
    </div>

    <script>
        const roleData = {
            operator: {
                label: 'Operator',
                title: 'Masuk sebagai Operator',
                sub: 'Masukkan password untuk melanjutkan ke kasir.'
            },
            admin: {
                label: 'Admin',
                title: 'Masuk sebagai Admin',
                sub: 'Masukkan password untuk mengelola aplikasi kasir.'
            }
        };

        const overlay = document.getElementById('modalOverlay');
        const card = document.getElementById('modalCard');
        const badgeText = document.getElementById('modalBadgeText');
        const titleEl = document.getElementById('modalTitle');
        const subEl = document.getElementById('modalSub');
        const roleInput = document.getElementById('modalRoleInput');
        const passwordEl = document.getElementById('modalPassword');
        const closeBtn = document.getElementById('modalClose');
        const toggleBtn = document.getElementById('togglePassword');

        function openModal(role) {
            const data = roleData[role];
            card.dataset.role = role;
            roleInput.value = role;
            badgeText.textContent = data.label;
            titleEl.textContent = data.title;
            subEl.textContent = data.sub;
            passwordEl.value = '';
            overlay.classList.add('is-open');
            setTimeout(() => passwordEl.focus(), 150);
        }

        function closeModal() {
            overlay.classList.remove('is-open');
        }

        document.querySelectorAll('[data-open-modal]').forEach(btn => {
            btn.addEventListener('click', () => openModal(btn.dataset.role));
        });

        closeBtn.addEventListener('click', closeModal);

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeModal();
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeModal();
        });

        toggleBtn.addEventListener('click', () => {
            const isPassword = passwordEl.type === 'password';
            passwordEl.type = isPassword ? 'text' : 'password';
        });

        <?php if (!empty($error)): ?>
            // Reopen modal on server-side validation error so the message is visible
            window.addEventListener('DOMContentLoaded', () => openModal('<?php echo $errorRole; ?>'));
        <?php endif; ?>
    </script>

</body>

</html>