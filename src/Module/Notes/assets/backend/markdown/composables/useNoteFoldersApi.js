import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/**
 * HTTP layer for the note folders.
 *
 * Same envelope and same rules as `useMarkdownNotesApi`: everything goes
 * through `useRequest` so the `X-Requested-With` header is sent (per
 * `convention_no_raw_fetch`), `noGuard` is passed because the page
 * legitimately lists and moves at the same time, and `reported` tells a
 * caller whether a toast has already been shown for it.
 *
 * The paths arrive in one object rather than as a prop each: the page already
 * carries two dozen path strings, and a second family of them one prop at a
 * time is how that list got there.
 */
export function useNoteFoldersApi(paths) {
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
        list: () => call(HttpMethod.Get, paths.list),
        create: (name, parentId = null) =>
            call(HttpMethod.Post, paths.create, { name, parentId }),
        rename: (id, name, parentId = null) =>
            call(HttpMethod.Post, resolvePath(paths.update, id), {
                name,
                parentId,
            }),
        move: (id, parentId) =>
            call(HttpMethod.Post, resolvePath(paths.move, id), { parentId }),
        remove: (id) =>
            call(HttpMethod.Post, resolvePath(paths.delete, id), {}),
        favorite: (id) =>
            call(HttpMethod.Post, resolvePath(paths.favorite, id), {}),
        reorder: (entries) => call(HttpMethod.Post, paths.reorder, { entries }),
        /** The address of a folder's page, for a link the browser can follow. */
        urlFor: (id) => resolvePath(paths.show, id),
    };
}
