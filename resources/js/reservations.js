import { Modal } from 'bootstrap';

// Mirror the conditional server rule for clear browser feedback.
document.querySelectorAll('[data-reservation-status]').forEach((select) => {
    const notes = select.form.querySelector('[data-reservation-notes]');
    if (!notes) return;
    const readyPanel = select.form.querySelector('[data-ready-confirmation]');
    const readyAccepted = select.form.querySelector('[data-ready-accepted]');
    const dialog = select.form.hasAttribute('data-ready-modal') ? document.getElementById('ready-confirmation-modal') : null;
    const modal = dialog ? Modal.getOrCreateInstance(dialog) : null;
    let confirmed = false;
    const updateRequired = () => {
        const ready = select.value === 'ready_for_pickup';
        notes.required = select.value === 'rejected' || (!modal && ready && readyPanel?.dataset.certificatePrepared !== '1');
        if (readyPanel) readyPanel.hidden = Boolean(modal) || !ready;
        if (readyAccepted) {
            readyAccepted.required = ready && !modal;
            readyAccepted.disabled = !ready;
            if (!ready) readyAccepted.checked = false;
        }
    };
    select.addEventListener('change', () => { confirmed = false; if (readyAccepted) readyAccepted.checked = false; updateRequired(); });
    if (modal) {
        const reason = dialog.querySelector('[data-ready-reason]');
        const submitButton = select.form.querySelector('button[type="submit"]');
        const submitLabel = submitButton.textContent;
        select.form.addEventListener('submit', (event) => {
            if (select.value === 'ready_for_pickup' && !confirmed) {
                event.preventDefault();
                if (reason) reason.value = notes.value;
                modal.show();
                return;
            }
            const button = select.form.querySelector('button[type="submit"]');
            button.disabled = true;
            button.textContent = 'Savingâ€¦';
        });
        dialog.querySelector('[data-ready-continue]').addEventListener('click', () => {
            if (reason) {
                if (!reason.value.trim()) reason.value = '';
                if (!reason.reportValidity()) return;
                notes.value = reason.value;
            }
            confirmed = true;
            readyAccepted.checked = true;
            select.form.requestSubmit();
        });
        dialog.addEventListener('hidden.bs.modal', () => { confirmed = false; });
        window.addEventListener('pageshow', () => { confirmed = false; submitButton.disabled = false; submitButton.textContent = submitLabel; });
    }
    updateRequired();
});

document.querySelectorAll('[data-reservation-cancel]').forEach((form) => {
    const dialog = document.getElementById('reservation-cancel-confirmation');
    const modal = Modal.getOrCreateInstance(dialog);
    let confirmed = false;
    form.addEventListener('submit', (event) => {
        if (!confirmed) {
            event.preventDefault();
            modal.show();
            return;
        }
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        button.textContent = 'Cancelling…';
        form.setAttribute('aria-busy', 'true');
    });
    dialog.querySelector('[data-cancellation-confirm]').addEventListener('click', () => {
        if (!form.reportValidity()) return;
        confirmed = true;
        form.requestSubmit();
    });
    window.addEventListener('pageshow', () => {
        confirmed = false;
        const button = form.querySelector('button[type="submit"]');
        button.disabled = false;
        button.textContent = 'Cancel reservation';
        form.removeAttribute('aria-busy');
    });
});
