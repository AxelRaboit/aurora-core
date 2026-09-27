/**
 * Which view a space opens on, from its address.
 *
 * `?view=` wins when it names a view this space offers, `content` included -
 * reading it through a query state whose default is `content` hid an explicit
 * `?view=content`. A link that names a card or a state but no view opens the
 * content, where both mean something: the last view used could be the
 * conversation. Otherwise null, and the reader's remembered view stands.
 *
 * @param {string} search      `window.location.search`
 * @param {string[]} viewKeys  the views this space offers
 * @returns {string|null}
 */
export function viewFromAddress(search, viewKeys) {
    const params = new URLSearchParams(search);
    const named = params.get("view");

    if (named && viewKeys.includes(named)) return named;
    if (params.has("item") || params.has("state")) return "content";

    return null;
}
