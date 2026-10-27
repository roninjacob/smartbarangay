import { Toast } from 'bootstrap';
import { filePresentation, landingMotion } from './civic-interaction-helpers';

// Presentation enhancements never run inside the dedicated official print view.
if (!document.querySelector('[data-print-document]')) {
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const hero = document.querySelector('[data-landing-motion]');
    if (hero) landingMotion(hero, hero.querySelector('[data-motion-control]'), reducedMotion, document);

    // Content stays visible without JS and when IntersectionObserver is unavailable.
    if ('IntersectionObserver' in window && !reducedMotion.matches) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(({ target, isIntersecting }) => {
                if (!isIntersecting) return;
                target.classList.add('civic-enter');
                target.addEventListener('animationend', () => target.classList.remove('civic-enter'), { once: true });
                observer.unobserve(target);
            });
        }, { threshold: 0.12 });
        document.querySelectorAll('.public-service-card,.public-step,.public-about-surface,.app-page-heading,.reservation-progress').forEach((element) => observer.observe(element));
    }

    // Only routine profile/preferences success becomes a persistent dismissible toast.
    document.querySelectorAll('[data-routine-toast]').forEach((feedback) => {
        const region = document.createElement('div');
        region.className = 'civic-toast-region';
        region.setAttribute('aria-live', 'polite');
        const toast = document.createElement('div');
        toast.className = 'toast civic-toast';
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-atomic', 'true');
        const body = document.createElement('div');
        body.className = 'toast-body';
        const icon = document.createElement('span');
        icon.className = 'civic-toast-check';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = '✓';
        const message = document.createElement('span');
        message.className = 'civic-toast-message';
        message.textContent = feedback.textContent.trim();
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close';
        close.setAttribute('aria-label', 'Dismiss success message');
        close.setAttribute('data-bs-dismiss', 'toast');
        body.append(icon, message, close);
        toast.append(body);
        region.append(toast);
        document.body.append(region);
        toast.addEventListener('shown.bs.toast', () => { feedback.hidden = true; }, { once: true });
        toast.addEventListener('hidden.bs.toast', () => { Toast.getInstance(toast)?.dispose(); region.remove(); }, { once: true });
        Toast.getOrCreateInstance(toast, { autohide: false }).show();
    });

    // Progressive disclosure on phones; the same GET forms and validation remain authoritative.
    const mobile = window.matchMedia('(max-width: 767px)');
    document.querySelectorAll('form.request-filters,form.schedule-filters,form.worklist-filters,.report-controls form').forEach((form, index) => {
        form.dataset.civicFilter = '';
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'civic-filter-toggle';
        form.id ||= `civic-filters-${index}`;
        toggle.setAttribute('aria-controls', form.id);
        let expanded = Boolean(form.querySelector('.is-invalid') || form.parentElement.querySelector('.alert-danger'));
        const chips = document.createElement('div');
        chips.className = 'civic-filter-chips';
        chips.setAttribute('aria-label', 'Selected filters');
        [...form.elements].forEach((input) => {
            if (!input.name || input.disabled || input.closest('[hidden]') || input.type === 'hidden' || !input.value || ['all', 'upcoming'].includes(input.value)) return;
            if (!['INPUT', 'SELECT'].includes(input.tagName)) return;
            const label = input.tagName === 'SELECT' ? input.selectedOptions[0]?.textContent : input.value;
            if (!label) return;
            const chip = document.createElement('span');
            chip.className = 'civic-filter-chip';
            const fieldLabel = input.labels?.[0]?.textContent?.trim();
            chip.textContent = fieldLabel ? `${fieldLabel}: ${label}` : label;
            chips.append(chip);
        });
        const update = () => {
            const show = !mobile.matches || expanded;
            form.hidden = !show;
            toggle.setAttribute('aria-expanded', String(show));
            toggle.textContent = `${expanded ? 'Hide filters' : 'Filters'}${chips.children.length ? ` · ${chips.children.length} selected` : ''}`;
        };
        toggle.addEventListener('click', () => { expanded = !expanded; update(); });
        mobile.addEventListener('change', update);
        form.before(toggle);
        // Reports already show the applied period below the filter panel.
        if (!form.closest('.report-controls')) form.after(chips);
        update();
    });

    document.querySelectorAll('input[data-friendly-upload]').forEach((input) => {
        const card = document.createElement('div');
        card.className = 'civic-upload-card';
        card.hidden = true;
        card.setAttribute('role', 'status');
        let objectUrl;
        const clearUrl = () => { if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = undefined; } };
        const render = () => {
            clearUrl();
            card.replaceChildren();
            const file = input.files?.[0];
            card.hidden = !file;
            if (!file) return;
            const data = filePresentation(file);
            const thumbnail = document.createElement(data.image ? 'img' : 'span');
            if (data.image) {
                objectUrl = URL.createObjectURL(file);
                thumbnail.src = objectUrl;
                thumbnail.alt = 'Preview of the selected file';
                thumbnail.className = 'civic-upload-thumbnail';
            } else {
                thumbnail.className = 'civic-upload-file-icon';
                thumbnail.textContent = data.extension;
                thumbnail.setAttribute('aria-hidden', 'true');
            }
            const copy = document.createElement('div');
            const name = document.createElement('strong');
            name.className = 'civic-upload-name';
            name.textContent = data.name;
            const meta = document.createElement('span');
            meta.className = 'civic-upload-meta';
            meta.textContent = `${data.size} · Selected, not yet saved`;
            copy.append(name, meta);
            const actions = document.createElement('div');
            actions.className = 'civic-upload-actions';
            const replace = document.createElement('button');
            replace.type = 'button'; replace.className = 'btn btn-outline-primary'; replace.textContent = 'Choose another file';
            replace.addEventListener('click', () => input.click());
            const remove = document.createElement('button');
            remove.type = 'button'; remove.className = 'btn btn-outline-secondary'; remove.textContent = 'Remove selection';
            remove.addEventListener('click', () => { input.value = ''; input.dispatchEvent(new Event('change', { bubbles: true })); input.focus(); });
            actions.append(replace, remove);
            card.append(thumbnail, copy, actions);
        };
        input.after(card);
        input.addEventListener('change', render);
        window.addEventListener('pagehide', clearUrl);
        window.addEventListener('pageshow', render);
        render();
    });

    document.querySelectorAll('[data-pdf-preview]').forEach((frame) => {
        const status = frame.querySelector('[data-pdf-status]');
        if (!status) return;
        const retry = document.createElement('button');
        retry.type = 'button'; retry.className = 'btn btn-outline-primary civic-preview-retry';
        retry.textContent = 'Reload preview'; retry.hidden = true;
        retry.addEventListener('click', () => window.location.reload());
        frame.append(retry);
        const sync = () => {
            const failed = /could not|unavailable|cannot|failed/i.test(status.textContent);
            frame.dataset.previewState = status.hidden ? 'ready' : (failed ? 'error' : 'loading');
            retry.hidden = !failed || status.hidden;
        };
        new MutationObserver(sync).observe(status, { attributes: true, childList: true, characterData: true, subtree: true });
        sync();
    });
}
