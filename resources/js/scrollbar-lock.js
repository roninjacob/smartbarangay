// The root always shows its scrollbar track (tokens.css), so Bootstrap's modal/offcanvas scrollbar
// compensation would only add padding and shift the page. Remove it before the next paint.
const body = document.body;
if (body) {
    new MutationObserver(() => {
        if (body.style.paddingRight) body.style.removeProperty('padding-right');
    }).observe(body, { attributes: true, attributeFilter: ['style'] });
}
