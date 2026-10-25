// Presentation-only enhancements. Only the explicitly marked GET filter form is submitted.
const summary = document.querySelector('[data-validation-summary]');
if (summary) summary.focus({ preventScroll: false });

const worklistFilters = document.querySelector('form[data-worklist-filters][method="GET"]');
worklistFilters?.querySelector('[data-worklist-status]')?.addEventListener('change', () => {
    worklistFilters.requestSubmit();
});

// Keep wide comparison tables inside their own keyboard-scrollable region.
document.querySelectorAll('.civic-ui .table-responsive').forEach((region) => {
    if (!region.hasAttribute('tabindex')) region.tabIndex = 0;
    if (!region.hasAttribute('role')) region.setAttribute('role', 'region');
    if (!region.hasAttribute('aria-label') && !region.hasAttribute('aria-labelledby')) {
        const caption = region.querySelector('caption')?.textContent.trim();
        region.setAttribute('aria-label', caption || 'Data table: scroll to see more columns');
    }
});
