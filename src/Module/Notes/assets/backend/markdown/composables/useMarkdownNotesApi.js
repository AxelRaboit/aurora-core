import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/**
 * HTTP layer for the Markdown notes backend.
 *
 * Goes through `useRequest` rather than calling `fetch`, per
 * `convention_no_raw_fetch`. That is not a style preference: `useRequest` sends
 * `X-Requested-With: XMLHttpRequest`, which is the contract Symfony reads with
 * `isXmlHttpRequest()` to answer JSON instead of an HTML page. Calling `fetch`
 * by hand omitted it, and the day a route starts branching on it these calls
 * would have received HTML and failed while parsing it.
 *
 * The `{ok, payload}` envelope is kept because nine call sites read it, and
 * `reported` is added beside them: `useRequest` already shows a toast for
 * transport and 5xx failures, so a caller that also shows one stacks two
 * messages over each other. Callers now ask `!ok && !reported` before
 * reporting anything themselves.
 *
 * `noGuard` is passed on every call. `useRequest`'s own guard drops a second
 * request while one is in flight, which is right for a form button and wrong
 * here: the page legitimately lists, shows and searches at the same time, and
 * a silently dropped call would look like a note that failed to open.
 */
export function useMarkdownNotesApi(props) {
    const { request } = useRequest();

    function resolvePath(template, id) {
        return template.replace("__id__", String(id));
    }

    async function call(method, url, body = null) {
        const payload = await request(url, body, { method, noGuard: true });

        // Null means transport or 5xx, and `useRequest` has already said so.
        if (payload === null) {
            return { ok: false, reported: true, payload: {} };
        }

        return { ok: payload.success !== false, reported: false, payload };
    }

    return {
        list: () => call(HttpMethod.Get, props.listPath),
        show: (id) => call(HttpMethod.Get, resolvePath(props.showPath, id)),
        create: (payload) => call(HttpMethod.Post, props.createPath, payload),
        update: (id, payload) =>
            call(HttpMethod.Post, resolvePath(props.updatePath, id), payload),
        remove: (id) =>
            call(HttpMethod.Post, resolvePath(props.deletePath, id), {}),
        move: (id, parentId) =>
            call(HttpMethod.Post, resolvePath(props.movePath, id), {
                parentId,
            }),
        reorder: (ids) => call(HttpMethod.Post, props.reorderPath, { ids }),
        backlinks: (id) =>
            call(HttpMethod.Get, resolvePath(props.backlinksPath, id)),
        unlinkedMentions: (id) =>
            call(HttpMethod.Get, resolvePath(props.unlinkedMentionsPath, id)),
        graph: () => call(HttpMethod.Get, props.graphPath),
        searchContent: (query) =>
            call(
                HttpMethod.Get,
                `${props.searchPath}?q=${encodeURIComponent(query)}`,
            ),
        /**
         * Multipart upload. `useRequest`'s `rawBody` exists for exactly this:
         * it sets the XHR header and leaves the browser to write the multipart
         * `Content-Type` with its boundary, which is the one header that must
         * not be set by hand.
         */
        uploadImage: async (file) => {
            const formData = new FormData();
            formData.append("image", file);

            const payload = await request(props.imageUploadPath, null, {
                method: HttpMethod.Post,
                rawBody: formData,
                noGuard: true,
            });

            if (payload === null) {
                return { ok: false, reported: true, payload: {} };
            }

            return { ok: payload.success !== false, reported: false, payload };
        },
    };
}
