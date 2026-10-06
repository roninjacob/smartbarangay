import { Modal } from 'bootstrap';

// Mirror the conditional server rule for clear browser feedback.
document.querySelectorAll('[data-reservation-status]').forEach((select) => {
    const notes = select.form.querySelector('[data-reservation-notes]');
    if (!notes) return;
    const updateRequired = () => { notes.required = select.value === 'rejected'; };
    select.addEventListener('change', updateRequired);
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
