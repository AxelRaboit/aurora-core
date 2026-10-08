import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/**
 * The group that holds the notes handed over one by one.
 *
 * Not a space in the database, and never sent by the server: a note shared on
 * its own lives in a space its reader cannot see, so there is no real group to
 * file it under. A string rather than a number so it can never collide with a
 * space id.
 */
export const SHARED_SPACE_ID = "shared";

/**
 * A space's name as it is read.
 *
 * The personal space carries none: everyone reads "Mon espace" there in
 * their own language, and not someone's name.
 */
export function spaceLabel(space, t) {
    if (!space) return "";
    if (space.shared) return t("notes.markdown.people.shared_with_me");
    if (space.personal) return t("notes.markdown.spaces.my_space");

    return space.name || t("notes.markdown.folders.untitled");
}

/**
 * The pseudo-space the notes handed over are grouped under.
 *
 * Writes nothing and manages nothing, whatever role the grant carries: the
 * group is heterogeneous - one note may be readable and the next writable -
 * and the gestures this would enable act on the notebook anyway. Renaming,
 * dragging and the space menu therefore stay off here, and editing happens
 * inside the note, where the role is known.
 */
export function sharedSpace() {
    return {
        id: SHARED_SPACE_ID,
        shared: true,
        personal: false,
        canWrite: false,
        canManage: false,
        published: false,
        managed: false,
        name: null,
        position: Number.MAX_SAFE_INTEGER,
    };
}

/**
 * The notes as a tree can draw them, once some were handed over on their own.
 *
 * **Two things have to be undone.** Such a note is filed in a folder of a
 * space the reader does not have, and the tree only emits notes whose folder
 * it knows - so it would silently vanish with the folder holding it. And it
 * carries that space's id, which would file it under a group the reader never
 * sees. Both are rewritten here, towards the root of {@see sharedSpace}.
 *
 * The role rides along as `sharedRole`, which is the only thing on screen
 * that can say whether the note may be written: its space says nothing to
 * this reader.
 *
 * @param {Array}  notes       the flat list, as the server sends it
 * @param {object} sharedRoles note id => "reader" | "editor"
 */
export function detachSharedNotes(notes, sharedRoles) {
    const roles = sharedRoles ?? {};

    if (!Object.keys(roles).length) return notes ?? [];

    return (notes ?? []).map((note) => {
        const role = roles[note.id] ?? roles[String(note.id)] ?? null;

        return null === role
            ? note
            : {
                  ...note,
                  folderId: null,
                  spaceId: SHARED_SPACE_ID,
                  sharedRole: role,
              };
    });
}

/**
 * The spaces to draw, with the handed-over group last when there is one.
 *
 * Last on purpose: it is somebody else's notebook showing through, and it
 * belongs after one's own spaces rather than among them.
 */
export function spacesWithShared(spaces, sharedRoles) {
    const list = spaces ?? [];

    return Object.keys(sharedRoles ?? {}).length
        ? [...list, sharedSpace()]
        : list;
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
