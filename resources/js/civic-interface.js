import { protectUnsavedDocument } from './unsaved-document-guard';

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

// Reveal only the date controls relevant to the selected report period.
// Values, request parameters and existing validation/disabled rules stay unchanged.
document.querySelectorAll('.report-controls form').forEach((form) => {
    const period = form.elements.namedItem('period');
    if (!period) return;
    const showErrors = Boolean(form.closest('.report-controls').querySelector('.alert-danger'));
    const update = () => {
        for (const name of ['date', 'start', 'end']) {
            const input = form.elements.namedItem(name);
            const container = input?.closest('div');
            if (container) container.hidden = !showErrors && period.value !== (name === 'date' ? 'date' : 'custom');
        }
    };
    period.addEventListener('change', update);
    update();
});

// Rejection has a separate public explanation; internal notes never become public.
const statusInput = document.querySelector('[data-reservation-status]');
const rejectionPanel = document.querySelector('[data-rejection-message]');
if (statusInput && rejectionPanel) {
    const input = rejectionPanel.querySelector('textarea');
    const update = () => {
        const rejecting = statusInput.value === 'rejected';
        rejectionPanel.hidden = !rejecting;
        input.required = rejecting;
        input.disabled = !rejecting;
    };
    statusInput.addEventListener('change', update);
    update();
}

// Protect custom answers when navigating to Profile or another page before Continue.
document.querySelectorAll('[data-unsaved-document-form]').forEach((form) => protectUnsavedDocument(form));
