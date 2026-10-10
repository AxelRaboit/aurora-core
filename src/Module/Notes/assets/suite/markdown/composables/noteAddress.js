/**
 * The id an address names, read against the page's own address template.
 *
 * The notes screen writes its address as it goes (a note, a folder, the
 * library) and reads it back when the browser's back button is pressed. It
 * used to read it with a pattern written for the Notes module's addresses
 * (`/markdown/12`, `/folder/3`), so the same screen hosted elsewhere - a
 * client space's notes, `?view=notes&note=12` (10/10/2026) - could not find
 * its way back. The template the server hands down is what both sides agree
 * on: the id sits where it has `__id__`, in the path or in the query.
 */
export const ID_PLACEHOLDER = "__id__";

function escapeForPattern(text) {
    return text.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

/**
 * @param {string} template an address carrying `__id__`, in its path or its query
 * @param {{pathname: string, search: string}} [address] the address to read, the page's by default
 * @returns {number|null} the id, or null when the address is not one of the template's
 */
export function idFromAddress(template, address = window.location) {
    const [templatePath, templateQuery = ""] = String(template)
        .split("#")[0]
        .split("?");

    if (templateQuery.includes(ID_PLACEHOLDER)) {
        if (address.pathname !== templatePath) return null;

        const expected = new URLSearchParams(templateQuery);
        const actual = new URLSearchParams(address.search);
        let id = null;

        for (const [name, value] of expected) {
            if (ID_PLACEHOLDER === value) {
                const raw = actual.get(name) ?? "";
                id = /^\d+$/.test(raw) ? Number(raw) : null;
            } else if (actual.get(name) !== value) {
                return null;
            }
        }

        return id;
    }

    if (!templatePath.includes(ID_PLACEHOLDER)) return null;

    const pattern = new RegExp(
        `^${escapeForPattern(templatePath).replace(ID_PLACEHOLDER, "(\\d+)")}$`,
    );
    const match = pattern.exec(address.pathname);

    return match ? Number(match[1]) : null;
}
