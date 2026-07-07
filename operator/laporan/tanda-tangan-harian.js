document.addEventListener('DOMContentLoaded', () => {
    const cfg = window.KBUS_TTD_HARIAN || {};

    const overlay = document.getElementById('modalOverlay');
    const btnBatal = document.getElementById('btnBatalModal');
    const btnHapus = document.getElementById('btnHapusCanvas');
    const btnSimpan = document.getElementById('btnSimpanTtd');
    const canvas = document.getElementById('canvasTtd');
    const inputNama = document.getElementById('inputNama');
    const modalMsg = document.getElementById('modalMsg');
    const modalTanggalLabel = document.getElementById('modalTanggalLabel');
    const modalCabangLabel = document.getElementById('modalCabangLabel');

    if (!overlay || !canvas) return; // sesi tanpa hak tanda tangan

    const ctx = canvas.getContext('2d');
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#16294f';
    let drawing = false;
    let hasDrawn = false;
    let currentTanggal = '';
    let currentCabang = '';

    function getPos(evt) {
        const rect = canvas.getBoundingClientRect();
        const scaleX = canvas.width / rect.width;
        const scaleY = canvas.height / rect.height;
        const point = evt.touches ? evt.touches[0] : evt;
        return {
            x: (point.clientX - rect.left) * scaleX,
            y: (point.clientY - rect.top) * scaleY
        };
    }

    function start(evt) {
        evt.preventDefault();
        drawing = true;
        hasDrawn = true;
        const p = getPos(evt);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
    }

    function move(evt) {
        if (!drawing) return;
        evt.preventDefault();
        const p = getPos(evt);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
    }

    function end() { drawing = false; }

    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    canvas.addEventListener('mouseup', end);
    canvas.addEventListener('mouseleave', end);
    canvas.addEventListener('touchstart', start);
    canvas.addEventListener('touchmove', move);
    canvas.addEventListener('touchend', end);

    function clearCanvas() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        hasDrawn = false;
    }

    function setMsg(text, type) {
        modalMsg.textContent = text || '';
        modalMsg.className = 'modal-msg' + (type ? ' ' + type : '');
    }

    function openModalFor(tanggal, tanggalLabel, cabang, cabangLabel, signatureLama, namaLama) {
        currentTanggal = tanggal;
        currentCabang = cabang;
        clearCanvas();
        setMsg('');
        if (modalTanggalLabel) {
            modalTanggalLabel.textContent = 'Untuk surat tanggal ' + (tanggalLabel || tanggal);
        }
        if (modalCabangLabel) {
            modalCabangLabel.textContent = 'Cabang: ' + (cabangLabel || cabang);
        }
        if (inputNama && inputNama.type !== 'hidden') {
            inputNama.value = namaLama || '';
        }

        // Kalau slot ini sudah pernah tanda tangan, tampilkan tanda tangan lama di canvas
        if (signatureLama) {
            const img = new Image();
            img.onload = () => {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                hasDrawn = true;
            };
            img.src = signatureLama;
        }

        overlay.classList.add('is-open');
    }

    function closeModal() {
        overlay.classList.remove('is-open');
    }

    document.querySelectorAll('.btn-ttd[data-tanggal]').forEach((btn) => {
        btn.addEventListener('click', () => {
            openModalFor(
                btn.dataset.tanggal,
                btn.dataset.label,
                btn.dataset.cabang,
                btn.dataset.cabangLabel,
                btn.dataset.signature || '',
                btn.dataset.nama || ''
            );
        });
    });

    btnBatal.addEventListener('click', closeModal);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) closeModal(); });
    btnHapus.addEventListener('click', clearCanvas);

    btnSimpan.addEventListener('click', () => {
        if (!hasDrawn) {
            setMsg('Gambar tanda tangan dulu.', 'error');
            return;
        }
        const nama = (inputNama.value || '').trim();
        if (nama === '') {
            setMsg('Nama wajib diisi.', 'error');
            return;
        }
        if (!currentTanggal) {
            setMsg('Tanggal tidak valid.', 'error');
            return;
        }

        const cabang = currentCabang;
        if (!cabang) {
            setMsg('Cabang tidak diketahui.', 'error');
            return;
        }

        const signature = canvas.toDataURL('image/png');

        btnSimpan.disabled = true;
        setMsg('Menyimpan...', '');

        const body = new URLSearchParams({
            tanggal: currentTanggal,
            cabang: cabang,
            slot: cfg.slotSaya,
            nama: nama,
            signature: signature
        });

        fetch('../../database/simpan-tanda-tangan.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then((res) => res.json())
            .then((json) => {
                btnSimpan.disabled = false;
                if (json.ok) {
                    setMsg('Tersimpan!', 'ok');
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    setMsg(json.error || 'Gagal menyimpan.', 'error');
                }
            })
            .catch(() => {
                btnSimpan.disabled = false;
                setMsg('Gagal terhubung ke server.', 'error');
            });
    });
});