import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

/**
 * HTTP layer for sharing a note: with people who have an account, and with
 * anybody holding an address.
 *
 * One composable because it is one screen. The two halves answer the same
 * question - who else reaches this note - and the modal shows them one above
 * the other; splitting them would have meant two loading flags and two error
 * paths for one dialog.
 *
 * Kept apart from `useMarkdownNotesApi` because sharing is a different subject
 * from editing. Goes through `useRequest` like the rest of the module, so the
 * loading guard, the error toast and the `X-Requested-With` header are the
 * shared ones rather than a second set.
 *
 * Returns `null` on transport / 5xx - callers must short-circuit on that.
 */
export function useNoteShareApi(props) {
    const { loading, request } = useRequest();

    const withId = (template, id) => template.replace("__id__", String(id));

    return {
        loading,
        list: (noteId) =>
            request(withId(props.sharesListPath, noteId), null, "GET"),
        /**
         * What a link would carry, before it exists.
         *
         * This method was missing since the modal asks for the list of
         * linked notes: the route was there, the path was passed to the
         * component, and the call hit `api.preview is not a function`. The
         * exception started from a `watch` on the opening, so it bubbled up
         * as an unhandled rejection - invisible - until the page got a
         * safety net, which made it spectacular: opening the share wiped
         * the screen.
         */
        preview: (noteId, { linked = false } = {}) =>
            request(
                `${withId(props.sharesPreviewPath, noteId)}?linked=${linked ? 1 : 0}`,
                null,
                { method: "GET", noGuard: true },
            ),
        create: (body) => request(props.sharesCreatePath, body),
        revoke: (id) => request(withId(props.sharesRevokePath, id), {}),

        /** The note's guest list, and the accounts that could join it. */
        listPeople: (noteId) =>
            request(withId(props.peopleListPath, noteId), null, "GET"),
        /** Adds somebody, or changes the role they already had: one call. */
        setPerson: (noteId, userId, role) =>
            request(withId(props.peopleSetPath, noteId), { userId, role }),
        removePerson: (noteId, userId) =>
            request(
                withId(props.peopleRemovePath, noteId).replace(
                    "__user__",
                    String(userId),
                ),
                {},
            ),
    };
}
