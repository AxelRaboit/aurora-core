import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/**
 * A space's name as it is read.
 *
 * The personal space carries none: everyone reads "Mon espace" there in
 * their own language, and not someone's name.
 */
export function spaceLabel(space, t) {
    if (!space) return "";
    if (space.personal) return t("notes.markdown.spaces.my_space");

    return space.name || t("notes.markdown.folders.untitled");
}

/**
 * The spaces in the panel's order: one's own first, then by position.
 *
 * The server already returns them that way; we sort again anyway, because a
 * space just created lands at the end of the list without going through it.
 */
export function sortSpaces(spaces) {
    return [...(spaces ?? [])].sort(
        (left, right) =>
            Number(Boolean(right.personal)) - Number(Boolean(left.personal)) ||
            (left.position ?? 0) - (right.position ?? 0) ||
            Number(left.id) - Number(right.id),
    );
}

/**
 * The space calls, with the same envelope as the folder ones: `reported`
 * says whether a message has already been shown.
 */
export function useNoteSpacesApi(paths) {
    const { request } = useRequest();

    const resolve = (template, id, userId = null) =>
        template
            .replace("__id__", String(id))
            .replace("__user__", String(userId));

    async function call(method, url, body = null) {
        const payload = await request(url, body, { method, noGuard: true });

        if (payload === null) {
            return { ok: false, reported: true, payload: {} };
        }

        return { ok: payload.success !== false, reported: false, payload };
    }

    return {
        list: () => call(HttpMethod.Get, paths.list),
        create: (input) => call(HttpMethod.Post, paths.create, input),
        show: (id) => call(HttpMethod.Get, resolve(paths.show, id)),
        update: (id, input) =>
            call(HttpMethod.Post, resolve(paths.update, id), input),
        remove: (id) => call(HttpMethod.Post, resolve(paths.delete, id), {}),
        setMember: (id, userId, role) =>
            call(HttpMethod.Post, resolve(paths.membersSet, id), {
                userId,
                role,
            }),
        removeMember: (id, userId) =>
            call(HttpMethod.Post, resolve(paths.membersRemove, id, userId), {}),
        people: () => call(HttpMethod.Get, paths.people),
        publish: (id, input) =>
            call(HttpMethod.Post, resolve(paths.publish, id), input),
    };
}
