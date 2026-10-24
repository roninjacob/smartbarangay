document.querySelectorAll('[data-collection-filters]').forEach((form) => {
    const mode = form.elements.namedItem('period');
    const date = form.elements.namedItem('date');
    const start = form.elements.namedItem('start');
    const end = form.elements.namedItem('end');
    const update = () => {
        date.disabled = mode.value !== 'date';
        date.required = !date.disabled;
        start.disabled = end.disabled = mode.value !== 'custom';
        start.required = end.required = !start.disabled;
        end.min = start.disabled ? '' : start.value;
    };
    mode.addEventListener('change', update);
    start.addEventListener('input', update);
    update();
});
