const roleData = {
    operator: {
        label: 'Operator',
        title: 'Masuk sebagai Operator',
        sub: 'Pilih kasir untuk melanjutkan.'
    },
    admin: {
        label: 'Admin',
        title: 'Masuk sebagai Admin',
        sub: 'Masukkan password untuk mengelola aplikasi kasir.'
    }
};

const branchLabels = {
    sodonghilir: 'Sodonghilir',
    sariwangi: 'Sariwangi',
    manonjaya: 'Manonjaya'
};

// Isi nanti: 'photo' diisi path/URL foto kasir, 'name' diisi nama kasirnya.
// Kalau 'photo' dikosongkan (''), otomatis tampil ikon avatar default.
const branchInfo = {
    sodonghilir: { photo: '../assets/', name: '' },
    sariwangi: { photo: '../assets/', name: '' },
    manonjaya: { photo: '../assets/', name: '' }
};

const overlay = document.getElementById('modalOverlay');
const card = document.getElementById('modalCard');
const badgeText = document.getElementById('modalBadgeText');
const titleEl = document.getElementById('modalTitle');
const subEl = document.getElementById('modalSub');
const roleInput = document.getElementById('modalRoleInput');
const branchInput = document.getElementById('modalBranchInput');
const branchSelect = document.getElementById('branchSelect');
const branchOpts = document.querySelectorAll('.branch-opt[data-branch]');
const kasirProfile = document.getElementById('kasirProfile');
const kasirPhoto = document.getElementById('kasirPhoto');
const kasirName = document.getElementById('kasirName');
const kasirBranch = document.getElementById('kasirBranch');
const passSection = document.getElementById('passSection');
const passwordEl = document.getElementById('modalPassword');
const closeBtn = document.getElementById('modalClose');
const toggleBtn = document.getElementById('togglePassword');
const submitBtn = document.getElementById('modalSubmit');

function selectBranch(branch) {
    branchOpts.forEach(b => b.classList.toggle('is-selected', b.dataset.branch === branch));
    branchInput.value = branch;
    subEl.textContent = 'Masuk sebagai kasir cabang ' + (branchLabels[branch] || branch) + '.';

    const info = branchInfo[branch] || { photo: '', name: '' };
    if (info.photo) {
        kasirPhoto.innerHTML = '<img src="' + info.photo + '" alt="Foto kasir">';
    } else {
        kasirPhoto.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"></circle><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"></path></svg>';
    }
    kasirName.textContent = info.name || 'Nama belum diisi';
    kasirBranch.textContent = branchLabels[branch] || branch;

    kasirProfile.classList.add('is-visible');
    passSection.classList.add('is-visible');
    submitBtn.disabled = false;
}

function openModal(role) {
    const data = roleData[role];
    card.dataset.role = role;
    roleInput.value = role;
    branchInput.value = '';
    badgeText.textContent = data.label;
    titleEl.textContent = data.title;
    subEl.textContent = data.sub;
    passwordEl.value = '';
    branchOpts.forEach(b => b.classList.remove('is-selected'));

    kasirProfile.classList.remove('is-visible');

    if (role === 'operator') {
        branchSelect.classList.add('is-visible');
        passSection.classList.remove('is-visible');
        submitBtn.disabled = true;
    } else {
        // Admin: langsung tampil password, tanpa pilihan apapun.
        // Akses (admin/ketua/bendahara) ditentukan otomatis di server dari password mana yang cocok.
        branchSelect.classList.remove('is-visible');
        passSection.classList.add('is-visible');
        submitBtn.disabled = false;
        setTimeout(() => passwordEl.focus(), 150);
    }

    overlay.classList.add('is-open');
}

function closeModal() {
    overlay.classList.remove('is-open');
}

document.querySelectorAll('[data-open-modal]').forEach(btn => {
    btn.addEventListener('click', () => openModal(btn.dataset.role));
});

branchOpts.forEach(btn => {
    btn.addEventListener('click', () => {
        selectBranch(btn.dataset.branch);
        setTimeout(() => passwordEl.focus(), 100);
    });
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

// Reopen modal on server-side validation error so the message is visible.
// window.serverError is set inline in index.php from PHP session/POST state.
if (window.serverError && window.serverError.hasError) {
    window.addEventListener('DOMContentLoaded', () => {
        openModal(window.serverError.role);
        if (window.serverError.role === 'operator' && window.serverError.branch) {
            selectBranch(window.serverError.branch);
        }
    });
}