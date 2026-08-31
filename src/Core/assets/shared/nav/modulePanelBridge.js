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
 * Names are namespaced per module (`ged:folder`) and prefixed here, so two
 * modules cannot collide and nothing else on the page can be mistaken for a
 * panel request.
 */
const PREFIX = "aurora:panel:";

/**
 * Panel side. Ask the page to handle something.
 *
 * @param {string} name   e.g. `"ged:folder"`
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
