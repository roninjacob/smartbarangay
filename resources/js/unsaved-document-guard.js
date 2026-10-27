// Private answers remain in the form; never copy them into browser storage.
export function protectUnsavedDocument(form, browser = window) {
    const fields = [...form.querySelectorAll('[name^="document_answers["]')];
    const initial = fields.map((field) => field.value);
    let submitted = false;
    const dirty = () => !submitted && fields.some((field, index) => field.value !== initial[index]);
    const warn = (event) => {
        if (!dirty()) return;
        event.preventDefault();
        event.returnValue = '';
    };
    browser.addEventListener('beforeunload', warn);
    form.addEventListener('submit', () => { submitted = true; });
    // A cancelled submit or returning via browser history must restore protection.
    browser.addEventListener('pageshow', () => { submitted = false; });
    return { dirty };
}
