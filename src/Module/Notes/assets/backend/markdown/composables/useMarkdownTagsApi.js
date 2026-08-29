import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * HTTP layer for the global tag-management endpoints (list / rename /
 * merge / delete). Goes through `useRequest` for the standard loading
 * guard, error toast and `X-Requested-With` header.
 *
 * Returns `null` on transport / 5xx - caller must short-circuit on that.
 */
export function useMarkdownTagsApi(props) {
    const { loading, request } = useRequest();

    return {
        loading,
        list: () => request(props.tagsListPath, null, "GET"),
        rename: (oldTag, newTag) =>
            request(props.tagsRenamePath, { oldTag, newTag }),
        merge: (sourceTags, targetTag) =>
            request(props.tagsMergePath, { sourceTags, targetTag }),
        remove: (tag) => request(props.tagsDeletePath, { tag }),
    };
}
