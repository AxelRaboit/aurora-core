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
        /**
         * Ce qu'un lien emporterait, avant qu'il existe.
         *
         * Cette méthode manquait depuis que la modale demande la liste des
         * notes liées : la route était là, le chemin était passé au
         * composant, et l'appel tombait sur `api.preview is not a
         * function`. L'exception partait d'un `watch` sur l'ouverture, donc
         * elle remontait en rejet non traité - invisible - jusqu'à ce que
         * la page se dote d'un garde-fou, qui l'a rendue spectaculaire :
         * ouvrir le partage effaçait l'écran.
         */
        preview: (noteId, { linked = false } = {}) =>
            request(
                `${withId(props.sharesPreviewPath, noteId)}?linked=${linked ? 1 : 0}`,
                null,
                { method: "GET", noGuard: true },
            ),
        create: (body) => request(props.sharesCreatePath, body),
        revoke: (id) => request(withId(props.sharesRevokePath, id), {}),
    };
}
