import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/**
 * HTTP layer for the Markdown notes suite.
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
        /** `spaceId` says which root when `folderId` is null. */
        move: (id, folderId, spaceId = null) =>
            call(HttpMethod.Post, resolvePath(props.movePath, id), {
                folderId,
                spaceId,
            }),
        favorite: (id) =>
            call(HttpMethod.Post, resolvePath(props.favoritePath, id), {}),
        /**
         * A folder's notes, in the wanted order.
         *
         * Sends what the server reads - `{entries: [{id, folderId,
         * position}]}` - and not a list of ids: the call had always promised
         * `{ids}`, which the controller silently ignored.
         */
        reorder: (entries) =>
            call(HttpMethod.Post, props.reorderPath, { entries }),
        /** A copy right below the note, in the same folder. */
        duplicate: (id) =>
            call(HttpMethod.Post, resolvePath(props.duplicatePath, id), {}),
        /** Make the note a template, or turn it back into an ordinary one. */
        markTemplate: (id, template) =>
            call(HttpMethod.Post, resolvePath(props.templatePath, id), {
                template,
            }),
        /** A note's past versions, most recent first. */
        revisions: (id) =>
            call(HttpMethod.Get, resolvePath(props.revisionsPath, id)),
        revision: (id, revisionId) =>
            call(
                HttpMethod.Get,
                resolvePath(props.revisionPath, id).replace(
                    "__revisionId__",
                    String(revisionId),
                ),
            ),
        restoreRevision: (id, revisionId) =>
            call(
                HttpMethod.Post,
                resolvePath(props.revisionRestorePath, id).replace(
                    "__revisionId__",
                    String(revisionId),
                ),
                {},
            ),
        /** A new note from a template: `{folderId, spaceId, title}`. */
        fromTemplate: (id, payload) =>
            call(
                HttpMethod.Post,
                resolvePath(props.fromTemplatePath, id),
                payload,
            ),
        /**
         * Today's note, in the personal space's journal: the server finds the
         * one already written today, or writes it.
         */
        // A date picks the day; none, today (09/10/2026).
        daily: (date = null) =>
            call(HttpMethod.Post, props.dailyPath, date ? { date } : {}),
        dailyDays: (month) =>
            call(
                HttpMethod.Get,
                `${props.dailyDaysPath}?month=${encodeURIComponent(month)}`,
            ),
        tasks: () => call(HttpMethod.Get, props.tasksPath),
        searchFull: (query, sort = "relevance") =>
            call(
                HttpMethod.Get,
                `${props.searchFullPath}?q=${encodeURIComponent(query)}&sort=${sort}`,
            ),
        /** `{ids, find, replacement, exact, dryRun}`: dry run counts only. */
        replaceInNotes: (payload) =>
            call(HttpMethod.Post, props.searchReplacePath, payload),
        comments: (id) =>
            call(HttpMethod.Get, resolvePath(props.commentsPath, id)),
        addComment: (id, payload) =>
            call(HttpMethod.Post, resolvePath(props.commentsPath, id), payload),
        resolveComment: (commentId, resolved) =>
            call(
                HttpMethod.Post,
                String(props.commentResolvePath).replace(
                    "__comment__",
                    String(commentId),
                ),
                { resolved },
            ),
        deleteComment: (commentId) =>
            call(
                HttpMethod.Post,
                String(props.commentDeletePath).replace(
                    "__comment__",
                    String(commentId),
                ),
                {},
            ),
        reminder: (id) =>
            call(HttpMethod.Get, resolvePath(props.reminderPath, id)),
        setReminder: (id, remindAt) =>
            call(HttpMethod.Post, resolvePath(props.reminderPath, id), {
                remindAt,
            }),
        toggleTask: (id, index, done) =>
            call(HttpMethod.Post, resolvePath(props.taskPath, id), {
                index,
                done,
            }),
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

        /**
         * Markdown files, or a zip, turned back into notes.
         *
         * The `FormData` is built by the caller: it knows which folder is
         * being imported into, and building it here would require passing
         * the two halves separately only to glue them back right away.
         */
        import: async (formData) => {
            const payload = await request(props.importPath, null, {
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
