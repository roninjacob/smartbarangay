document.querySelectorAll('[data-profile-picture-form]').forEach((form) => {
    const input = form.querySelector('input[type="file"]');
    const feedback = form.querySelector('[data-picture-feedback]');
    input.addEventListener('change', () => {
        const file = input.files[0];
        input.setCustomValidity('');
        feedback.textContent = '';
        if (!file) return;
        const allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (!allowed.includes(file.type) || !/\.(jpe?g|png|webp)$/i.test(file.name)) {
            input.setCustomValidity('Choose a JPG, JPEG, PNG or WebP image.');
        } else if (file.size > 2 * 1024 * 1024) {
            input.setCustomValidity('Choose an image no larger than 2 MB.');
        }
        feedback.textContent = input.validationMessage || 'Picture selected. Choose Upload or Replace to save it.';
        feedback.classList.toggle('text-danger', !input.validity.valid);
    });
});
