import { nextCycleIndex } from './landing-helpers';

// Progressive enhancements; Laravel remains responsible for validation and authentication.

// Decorative status chip on the account pages' brand panel. Static under reduced motion.
const statusChip = document.querySelector('[data-auth-page] [data-au-status-cycle]');
if (statusChip && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    let statuses = [];
    try { statuses = JSON.parse(statusChip.dataset.auStatusCycle || '[]'); } catch { statuses = []; }
    let index = 0;
    if (statuses.length > 1) {
        window.setInterval(() => {
            if (document.hidden) return;
            index = nextCycleIndex(index, statuses.length);
            statusChip.classList.add('is-changing');
            window.setTimeout(() => {
                statusChip.textContent = statuses[index].label;
                statusChip.dataset.tone = statuses[index].tone;
                statusChip.classList.remove('is-changing');
            }, 220);
        }, 2600);
    }
}

document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    const input = document.getElementById(button.dataset.passwordToggle);
    if (!input) return;

    button.hidden = false;
    const fieldLabel = document.querySelector(`label[for="${input.id}"]`)?.textContent.trim().toLowerCase() || 'password';

    button.addEventListener('click', () => {
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        button.textContent = visible ? 'Hide' : 'Show';
        button.setAttribute('aria-pressed', String(visible));
        button.setAttribute('aria-label', `${visible ? 'Hide' : 'Show'} ${fieldLabel}`);
    });
});

document.querySelectorAll('[data-auth-form]').forEach((form) => {
    const password = form.querySelector('[name="password"]');
    const confirmation = form.querySelector('[name="password_confirmation"]');
    const submit = form.querySelector('[type="submit"]');
    const originalLabel = submit?.innerHTML;

    if (password && confirmation) {
        const checkConfirmation = () => {
            confirmation.setCustomValidity(
                confirmation.value && confirmation.value !== password.value
                    ? 'The passwords do not match.'
                    : ''
            );
        };
        password.addEventListener('input', checkConfirmation);
        confirmation.addEventListener('input', checkConfirmation);
    }

    form.addEventListener('submit', () => {
        if (!submit) return;
        submit.disabled = true;
        submit.textContent = submit.dataset.submitLabel;
        form.setAttribute('aria-busy', 'true');
    });

    // Restore the form when returning with the browser's Back button.
    window.addEventListener('pageshow', () => {
        if (!submit) return;
        submit.disabled = false;
        submit.innerHTML = originalLabel;
        form.removeAttribute('aria-busy');
    });
});
