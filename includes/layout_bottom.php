</main>
</div>
<script>
(() => {
    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('sidebarToggle');
    const close = document.getElementById('sidebarClose');
    const backdrop = document.getElementById('sidebarBackdrop');
    const main = document.querySelector('.main-wrapper');
    const mobileQuery = window.matchMedia('(max-width: 991.98px)');

    if (!sidebar || !toggle || !close || !backdrop || !main) {
        console.error('Sidebar navigation could not initialize because required layout elements are missing.');
        return;
    }

    const setOpen = (isOpen, restoreFocus = false) => {
        const mobile = mobileQuery.matches;
        const open = mobile && isOpen;

        sidebar.classList.toggle('show', open);
        sidebar.inert = mobile && !open;
        sidebar.setAttribute('aria-hidden', String(mobile && !open));
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', open ? 'ปิดเมนู' : 'เปิดเมนู');
        backdrop.hidden = !open;
        main.inert = open;
        main.setAttribute('aria-hidden', String(open));

        if (open) {
            close.focus();
        } else if (restoreFocus && mobile) {
            toggle.focus();
        }
    };

    setOpen(false);

    toggle.addEventListener('click', () => {
        setOpen(toggle.getAttribute('aria-expanded') !== 'true');
    });
    close.addEventListener('click', () => setOpen(false, true));
    backdrop.addEventListener('click', () => setOpen(false, true));
    sidebar.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => setOpen(false));
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
            setOpen(false, true);
            return;
        }

        if (event.key === 'Tab' && toggle.getAttribute('aria-expanded') === 'true') {
            const focusable = Array.from(sidebar.querySelectorAll('a[href], button:not([disabled])'))
                .filter((element) => element.getClientRects().length > 0);
            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    });
    mobileQuery.addEventListener('change', () => setOpen(false));
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
