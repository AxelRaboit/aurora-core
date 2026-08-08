import { ref } from "vue";

/**
 * Keeps one value in the URL's query string.
 *
 * The counterpart to `useTabState`'s fragment mode, and the split between
 * them is what each answers. A fragment says *which section of this page*
 * you are on — there is one of those, so one `#` is enough. A query
 * parameter says *how this list is being looked at* — sorted by which field,
 * in which direction, as a grid or a list — and several of those are true at
 * once, which is what a query string is for.
 *
 * Both replace this state being remembered in `localStorage`, where it used
 * to live. A remembered sort is a preference; a sort in the URL is a link
 * someone can send. The second is what people actually want when they ask a
 * colleague to look at something, and it also ends the class of bug where two
 * browser tabs on two lists overwrite each other's choice.
 *
 * A value equal to the default is removed rather than written. Otherwise
 * every list page would grow `?sort=name&dir=asc&view=grid` the moment it
 * opened, and a URL nobody chose is a URL nobody wants to copy.
 *
 * `replaceState`, so changing a sort does not put an entry in the history —
 * Back should leave the page, not undo a column click.
 *
 * @param {string}        key       Query parameter name.
 * @param {object}        options
 * @param {string}        [options.defaultValue] Value meaning "not in the URL".
 * @param {string[]|null} [options.valid]        Allowed values; anything else is discarded.
 */
export function useQueryState(key, { defaultValue = "", valid = null } = {}) {
    function read() {
        if ("undefined" === typeof window) return null;

        const raw = new URLSearchParams(window.location.search).get(key);

        if (null === raw) return null;

        return null === valid || valid.includes(raw) ? raw : null;
    }

    const value = ref(read() ?? defaultValue);

    function write() {
        if ("undefined" === typeof window) return;

        const params = new URLSearchParams(window.location.search);

        if (value.value === defaultValue || "" === value.value) {
            params.delete(key);
        } else {
            params.set(key, value.value);
        }

        const query = params.toString();

        window.history.replaceState(
            window.history.state,
            "",
            `${window.location.pathname}${query ? `?${query}` : ""}${window.location.hash}`,
        );
    }

    function set(next) {
        if (null !== valid && !valid.includes(next)) return;

        value.value = next;
        write();
    }

    return { value, set };
}
