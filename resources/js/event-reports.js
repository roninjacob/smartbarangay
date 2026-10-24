document.querySelectorAll('[data-event-report-filters]').forEach((form) => {
    const period = form.elements.namedItem('period');
    const update = () => {
        const custom = period.value === 'custom';
        const specific = period.value === 'date';
        const date = form.elements.namedItem('date');
        const start = form.elements.namedItem('start');
        const end = form.elements.namedItem('end');
        date.disabled = !specific;
        date.required = specific;
        start.disabled = end.disabled = !custom;
        start.required = end.required = custom;
        end.min = custom ? start.value : '';
    };
    form.addEventListener('change', update);
    update();
});
