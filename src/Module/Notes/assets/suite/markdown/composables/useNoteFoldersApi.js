import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
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
        create: (name, parentId = null, color = null, spaceId = null) =>
            call(HttpMethod.Post, paths.create, {
                name,
                parentId,
                color,
                spaceId,
            }),
        // `rename` also writes the colour: it is the same modal and the same
        // endpoint, and a call that left out the colour would erase it,
        // since the manager applies the whole input.
        rename: (id, name, parentId = null, color = null) =>
            call(HttpMethod.Post, resolvePath(paths.update, id), {
                name,
                parentId,
                color,
            }),
        /** `spaceId` says which root when `parentId` is null. */
        move: (id, parentId, spaceId = null) =>
            call(HttpMethod.Post, resolvePath(paths.move, id), {
                parentId,
                spaceId,
            }),
        remove: (id) =>
            call(HttpMethod.Post, resolvePath(paths.delete, id), {}),
        favorite: (id) =>
            call(HttpMethod.Post, resolvePath(paths.favorite, id), {}),
        reorder: (entries) => call(HttpMethod.Post, paths.reorder, { entries }),
        /** The address of a folder's page, for a link the browser can follow. */
        urlFor: (id) => resolvePath(paths.show, id),
    };
}
