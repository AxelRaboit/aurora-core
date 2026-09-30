import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/**
 * Le nom d'un espace tel qu'on le lit.
 *
 * L'espace personnel n'en porte pas : chacun y lit « Mon espace » dans sa
 * langue, et non le nom de quelqu'un.
 */
export function spaceLabel(space, t) {
    if (!space) return "";
    if (space.personal) return t("notes.markdown.spaces.my_space");

    return space.name || t("notes.markdown.folders.untitled");
}

/**
 * Les espaces dans l'ordre du panneau : le sien d'abord, puis par position.
 *
 * Le serveur les rend déjà ainsi ; on retrie quand même, parce qu'un espace
 * créé à l'instant arrive au bout de la liste sans passer par lui.
 */
export function sortSpaces(spaces) {
    return [...(spaces ?? [])].sort(
        (a, b) =>
            Number(Boolean(b.personal)) - Number(Boolean(a.personal)) ||
            (a.position ?? 0) - (b.position ?? 0) ||
            Number(a.id) - Number(b.id),
    );
}

/**
 * Les appels des espaces, avec la même enveloppe que ceux des dossiers :
 * `reported` dit si un message a déjà été montré.
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
