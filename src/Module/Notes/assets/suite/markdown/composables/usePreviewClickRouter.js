/**
 * Event delegation for the markdown preview pane.
 *
 * `NotePreview.vue` renders rendered-HTML via `v-html`. Vue can't bind
 * listeners to the injected elements (wiki-link anchors, task checkboxes)
 * so we use a single `@click` on the host and inspect `event.target` to
 * decide which interaction was triggered.
 *
 * This composable owns that dispatch logic - the SFC just calls
 * `route(event)` and consumes the resulting tuple via emit.
 *
 * @param {object} options
 * @param {(title: string, noteTitles: Array) => number|null} options.resolveWikiLink
 *   Function that maps a wiki-link target title to an existing note id
 *   (or null when no match). Provided by `useMarkdownRenderer`.
 * @param {() => Array<{id: number, title: string}>} options.noteTitlesGetter
 *   Returns the current list of note titles (passed as a thunk so the
 *   composable picks up the latest props on every click).
 */
export function usePreviewClickRouter({ resolveWikiLink, noteTitlesGetter }) {
    /**
     * Inspect the click target and return a `{ kind, payload }` tuple
     * describing what was clicked, or null when the click is on
     * non-interactive content.
     *
     * Variants:
     *   - { kind: 'wiki-link', payload: { noteTitle, heading, matchedId } }
     *   - { kind: 'checkbox',  payload: { index: number } }
     *   - null (no interactive target hit)
     */
    function route(event) {
        const target = event.target;
        if (!target) return null;

        if (target.tagName === "A" && target.classList.contains("wiki-link")) {
            event.preventDefault();
            const noteTitle = target.dataset.noteTitle ?? "";
            const heading = target.dataset.heading ?? "";
            return {
                kind: "wiki-link",
                payload: {
                    noteTitle,
                    heading,
                    matchedId: resolveWikiLink(noteTitle, noteTitlesGetter()),
                },
            };
        }

        if (
            target.tagName === "INPUT" &&
            target.classList.contains("task-checkbox")
        ) {
            // Prevent the browser's natural toggle - we round-trip through
            // the source markdown so the checked state stays authoritative
            // server-side.
            event.preventDefault();
            const index = Number.parseInt(
                target.dataset.checkboxIndex ?? "-1",
                10,
            );
            if (!Number.isInteger(index) || index < 0) return null;
            return { kind: "checkbox", payload: { index } };
        }

        return null;
    }

    return { route };
}
