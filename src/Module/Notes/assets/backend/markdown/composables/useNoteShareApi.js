import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * HTTP layer for a note's share links: list, create, revoke.
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
        create: (body) => request(props.sharesCreatePath, body),
        revoke: (id) => request(withId(props.sharesRevokePath, id), {}),
    };
}
