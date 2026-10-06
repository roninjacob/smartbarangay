import { Offcanvas, Modal } from 'bootstrap';

const sidebar = document.getElementById('app-sidebar');

if (sidebar) {
    // A desktop sidebar stays fixed; explicitly clear mobile backdrops and scroll locks on resize.
    window.matchMedia('(min-width: 992px)').addEventListener('change', (event) => {
        if (event.matches) Offcanvas.getInstance(sidebar)?.hide();
    });

    sidebar.querySelectorAll('[data-app-nav-link]').forEach((link) => {
        link.addEventListener('click', () => {
            Offcanvas.getInstance(sidebar)?.hide();
        });
    });

    // Close the mobile menu before opening a dialog: Bootstrap focus traps must not overlap.
    sidebar.querySelectorAll('[data-app-account]').forEach((button) => {
        button.addEventListener('click', (event) => {
            if (!sidebar.classList.contains('show')) return;
            event.stopPropagation();
            sidebar.addEventListener('hidden.bs.offcanvas', () => {
                const account = document.getElementById('account-summary');
                account.addEventListener('hidden.bs.modal', () => {
                    const trigger = window.matchMedia('(min-width: 992px)').matches
                        ? button : document.querySelector('[aria-controls="app-sidebar"]');
                    trigger?.focus();
                }, { once: true });
                Modal.getOrCreateInstance(account).show(button);
            }, { once: true });
            Offcanvas.getInstance(sidebar)?.hide();
        });
    });
}
