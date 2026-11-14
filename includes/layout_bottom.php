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

<script>
/* ===== สลับธีมสว่าง / มืด ===== */
(() => {
    const root = document.documentElement;
    const KEY = 'hr-theme';
    const btn = document.getElementById('themeToggle');
    const metaColor = document.querySelector('meta[name="theme-color"]');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    const current = () => (root.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light');
    const sync = () => {
        const dark = current() === 'dark';
        if (btn) btn.setAttribute('aria-pressed', String(dark));
        if (metaColor) metaColor.setAttribute('content', dark ? '#080b16' : '#f6f7fb');
    };
    const apply = (theme) => {
        root.setAttribute('data-bs-theme', theme);
        sync();
        window.dispatchEvent(new CustomEvent('themechange', { detail: { theme } }));
    };
    const save = (theme) => {
        try { localStorage.setItem(KEY, theme); } catch (e) { /* ใช้ต่อได้แม้เก็บค่าไม่ได้ */ }
    };

    sync();
    if (!btn) return;

    btn.addEventListener('click', () => {
        const next = current() === 'dark' ? 'light' : 'dark';
        save(next);

        if (reduceMotion.matches) {
            apply(next);
            return;
        }

        if (typeof document.startViewTransition === 'function') {
            // วงกลมขยายออกจากปุ่ม
            const rect = btn.getBoundingClientRect();
            const x = rect.left + rect.width / 2;
            const y = rect.top + rect.height / 2;
            const radius = Math.hypot(Math.max(x, innerWidth - x), Math.max(y, innerHeight - y));

            const transition = document.startViewTransition(() => apply(next));
            transition.ready.then(() => {
                root.animate(
                    { clipPath: [`circle(0px at ${x}px ${y}px)`, `circle(${radius}px at ${x}px ${y}px)`] },
                    { duration: 650, easing: 'cubic-bezier(0.22, 1, 0.36, 1)', pseudoElement: '::view-transition-new(root)' }
                );
            }).catch(() => {});
        } else {
            // เบราว์เซอร์ที่ไม่รองรับ: เฟดสีนุ่มๆ
            root.classList.add('theme-fade');
            apply(next);
            window.setTimeout(() => root.classList.remove('theme-fade'), 450);
        }
    });

    // ถ้าผู้ใช้ยังไม่เคยเลือกเอง ให้ตามธีมของระบบปฏิบัติการ
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (event) => {
        let saved = null;
        try { saved = localStorage.getItem(KEY); } catch (e) {}
        if (!saved) apply(event.matches ? 'dark' : 'light');
    });
})();
</script>

<script>
/* ===== แอนิเมชันหน้า: นับเลข KPI, แสงตามเมาส์, เงา topbar ===== */
(() => {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const fmt = new Intl.NumberFormat('th-TH');

    // นับเลขขึ้นแบบ ease-out
    document.querySelectorAll('[data-count]').forEach((el) => {
        const to = Number(el.dataset.count) || 0;
        if (reduceMotion || to === 0) {
            el.textContent = fmt.format(to);
            return;
        }
        const duration = 1100;
        const start = performance.now() + 250;
        el.textContent = '0';
        const tick = (now) => {
            const t = Math.min(Math.max((now - start) / duration, 0), 1);
            el.textContent = fmt.format(Math.round(to * (1 - Math.pow(1 - t, 4))));
            if (t < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    });

    // แสงสะท้อนบนการ์ด KPI ตามตำแหน่งเมาส์
    if (!reduceMotion && window.matchMedia('(hover: hover)').matches) {
        document.querySelectorAll('.dashboard-kpi-card').forEach((card) => {
            card.addEventListener('pointermove', (event) => {
                const rect = card.getBoundingClientRect();
                card.style.setProperty('--mx', ((event.clientX - rect.left) / rect.width * 100).toFixed(1) + '%');
                card.style.setProperty('--my', ((event.clientY - rect.top) / rect.height * 100).toFixed(1) + '%');
            });
            card.addEventListener('pointerleave', () => {
                card.style.removeProperty('--mx');
                card.style.removeProperty('--my');
            });
        });
    }

    // เงาบางๆ ใต้ topbar เมื่อเลื่อนหน้า
    const topbar = document.querySelector('.topbar');
    if (topbar) {
        let ticking = false;
        const update = () => {
            topbar.classList.toggle('is-scrolled', window.scrollY > 4);
            ticking = false;
        };
        window.addEventListener('scroll', () => {
            if (!ticking) {
                ticking = true;
                requestAnimationFrame(update);
            }
        }, { passive: true });
        update();
    }
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
