/**
 * How a module's side-menu panel talks to that module's page.
 *
 * The two are separate Vue applications - the menu is mounted once by the
 * layout, the page mounts its own - so they share no store, no props and no
 * parent. What they do share is the document, and that is the channel: a
 * `CustomEvent` on `window`, which crosses application boundaries because it
 * never leaves the DOM.
 *
 * **Cancelable, and that is the whole design.** A panel row stays a real
 * `<a href>`, so middle-click and "open in a new tab" keep working and the
 * address is honest. On a plain click the panel *asks* first: if the page is
 * listening it calls `preventDefault()`, takes the work, and the panel leaves
 * the browser alone. If nobody is listening - the reader is on another page of
 * the module, where there is no listing to filter - the ask returns false and
 * the link navigates as written. One panel, correct on both.
 *
 * **Naming.** A name is `<module>:<verb>`, namespaced so two modules cannot
 * collide and nothing else on the page can be mistaken for a panel request.
 * The verb says what the sender wants done - `ged:select`, `notes:delete`,
 * `ged:reload` - except for one reserved word: `changed` is only ever an
 * announcement travelling the other way, from the page to its panels. It had
 * meant both directions in two modules at once, which is the kind of thing
 * that reads fine until somebody wires the wrong half.
 *
 * **Payloads** carry named fields (`{ folderId, scope }`). The exception is a
 * panel that forwards another component's events untouched: it sends
 * `{ args: [...] }`, so the page can call the very handler that component was
 * written for, and neither side restates the signature.
 */
const PREFIX = "aurora:panel:";

/**
 * Panel side. Ask the page to handle something.
 *
 * @param {string} name   e.g. `"ged:select"`
 * @param {object} detail payload for the page
 * @returns {boolean} true when the page took it, false when nobody did
 */
export function askPage(name, detail = {}) {
    const event = new CustomEvent(`${PREFIX}${name}`, {
        detail,
        cancelable: true,
    });

    // dispatchEvent returns false once a listener has called preventDefault -
    // which `onPanelRequest` does for you, so "was it handled" and "was it
    // cancelled" are the same question.
    return !window.dispatchEvent(event);
}

/**
 * Page side. Tell whatever panel is listening that something changed.
 *
 * The other direction, and it is not the same shape: an ask can be declined -
 * that is the whole point of `askPage` - while an announcement has no answer.
 * The page is stating a fact, and a panel that missed it would simply be
 * showing something stale.
 *
 * The panel could poll or refetch instead, and that is exactly what it did
 * before: a note created in the editor did not appear in the tree until the
 * reader reloaded the page, because the panel had fetched its list once on
 * arrival and nothing ever told it otherwise.
 *
 * @param {string} name
 * @param {object} detail
 */
export function tellPanels(name, detail = {}) {
    window.dispatchEvent(new CustomEvent(`${PREFIX}${name}`, { detail }));
}

/**
 * Panel side. Listen for what the page announces.
 *
 * @returns {Function} call it to stop listening
 */
export function onPageNotice(name, handler) {
    const listener = (event) => handler(event.detail);

    window.addEventListener(`${PREFIX}${name}`, listener);

    return () => window.removeEventListener(`${PREFIX}${name}`, listener);
}

/**
 * Page side. Take responsibility for a panel request.
 *
 * Calling `preventDefault()` is the acknowledgement, so handlers never have to
 * remember to; forgetting would let the panel navigate away from the page that
 * had just handled the click.
 *
 * @param {string}   name
 * @param {Function} handler receives the detail
 * @returns {Function} call it to stop listening
 */
export function onPanelRequest(name, handler) {
    const listener = (event) => {
        event.preventDefault();
        handler(event.detail);
    };

    window.addEventListener(`${PREFIX}${name}`, listener);

    return () => window.removeEventListener(`${PREFIX}${name}`, listener);
}
