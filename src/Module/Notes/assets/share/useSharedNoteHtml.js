/**
 * Turns editor-rendered markdown into HTML a guest can actually use.
 *
 * The editor renders for somebody logged in. Two things in that output are
 * wrong for a share page, and both fail silently rather than loudly:
 *
 * 1. **Images point at the authenticated route.** `MarkdownNoteImageService`
 *    builds paths from the *current user's* directory, and a guest has none,
 *    so every picture in a shared note would be a broken icon. They are moved
 *    onto the token route, which resolves against the note's owner.
 *
 * 2. **`[[wiki links]]` are anchors whether or not they lead anywhere.** On a
 *    share, a link to a note outside the scope leads nowhere by design - the
 *    server refuses it - so leaving it looking clickable invites the reader to
 *    keep pressing something that will never answer. Those are unwrapped to
 *    plain text; the title stays readable, because it was already written into
 *    the note's own sentence.
 *
 * Every path is handed in by the server, generated from the routes themselves.
 * Spelling them here would have put three URLs in a file that has no way of
 * noticing when a route moves - and a dead path 404s quietly instead of
 * failing.
 *
 * The scope itself is enforced server-side; nothing here is a security
 * boundary. This is about not lying to the reader.
 */

/**
 * @param {string} html   rendered markdown, already sanitised by the renderer
 * @param {object} paths
 * @param {string} paths.imagePrefix       backend image URL prefix to replace
 * @param {string} paths.shareImagePath    share image URL, with `__filename__`
 * @param {string} paths.shareNotePath     share note URL, with `__id__`
 * @param {Record<string, number>} paths.titleIndex  lower-cased title -> note id
 * @returns {string}
 */
export function shareHtml(
    html,
    { imagePrefix, shareImagePath, shareNotePath, titleIndex = {} },
) {
    const doc = new DOMParser().parseFromString(
        `<div>${html}</div>`,
        "text/html",
    );
    const root = doc.body.firstElementChild;
    if (!root) return html;

    for (const img of root.querySelectorAll("img")) {
        const src = img.getAttribute("src") ?? "";
        if (imagePrefix && src.startsWith(imagePrefix)) {
            const filename = src.slice(imagePrefix.length);
            img.setAttribute(
                "src",
                shareImagePath.replace("__filename__", filename),
            );
        }
    }

    for (const anchor of root.querySelectorAll("a.wiki-link")) {
        const title = (anchor.getAttribute("data-note-title") ?? "")
            .trim()
            .toLowerCase();
        // `Object.hasOwn` rather than a truthiness check: a note titled
        // "constructor" must not resolve to something off the prototype chain.
        const id = Object.hasOwn(titleIndex, title) ? titleIndex[title] : null;

        if (id === null) {
            // Unwrapped rather than removed: the words were part of the sentence
            // the author wrote, and deleting them would change what the note says.
            anchor.replaceWith(doc.createTextNode(anchor.textContent ?? ""));
            continue;
        }

        anchor.setAttribute(
            "href",
            shareNotePath.replace("__id__", String(id)),
        );
    }

    return root.innerHTML;
}
