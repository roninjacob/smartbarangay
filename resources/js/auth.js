// Progressive enhancements; Laravel remains responsible for validation and authentication.
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
