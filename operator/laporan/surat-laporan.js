document.addEventListener('DOMContentLoaded', () => {
    const btnCetak = document.getElementById('btnCetak');

    if (btnCetak) {
        btnCetak.addEventListener('click', () => window.print());
    }
});