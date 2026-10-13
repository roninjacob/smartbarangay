document.querySelectorAll('[data-builder-field]').forEach((form) => {
    const type = form.querySelector('[data-builder-type]');
    const update = () => {
        form.querySelectorAll('[data-builder-for]').forEach((group) => {
            const visible = group.dataset.builderFor === type.value;
            group.hidden = !visible;
            group.querySelectorAll('input, textarea').forEach((input) => {
                input.disabled = !visible;
                input.required = visible && group.dataset.builderFor === 'dropdown';
            });
        });
    };
    type.addEventListener('change', update);
    update();
});
