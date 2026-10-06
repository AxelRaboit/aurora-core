import { ref } from "vue";

/** What we wait before disturbing: enough not to follow a cursor around. */
const DELAI = 450;

/** The card, in pixels. Used to decide which side it fits on. */
const LARGEUR = 360;
const HAUTEUR = 280;
const ECART = 12;
const MARGE = 8;

/**
 * What is kept of the note: beyond it, one no longer reads, one skims.
 *
 * A ten-thousand-character note rendered in full costs a complete parser
 * and a DOM tree that will never be shown, for a card that displays twenty
 * lines of it.
 */
const COUPE = 1200;

/**
 * A note's rendering, on hovering its card.
 *
 * **The rendering, not the source.** The cards' excerpt is flattened text,
 * which answers "which one is it"; the preview answers "what is in it", and
 * for that it needs the headings, the lists and the ticked boxes as they
 * will be read.
 *
 * The content is encrypted in the database, so it does not come with the
 * list: it is one request per note, made once and kept for the session.
 * Hence the delay before opening - crossing a mosaic must not trigger thirty
 * requests.
 *
 * None of this on a touch screen: there is no hover, and a card opening on
 * a light touch would get in front of what one was aiming at.
 */
export function useNotePreview({ fetchNote }) {
    const noteId = ref(null);
    const content = ref("");
    const loading = ref(false);
    const position = ref({ top: 0, left: 0 });

    const cache = new Map();
    let minuteur = null;
    let demande = 0;

    function survolPossible() {
        try {
            return window.matchMedia?.("(hover: hover)")?.matches ?? false;
        } catch {
            // matchMedia missing (old jsdom, restricted environment): no
            // preview rather than an exception on hover.
            return false;
        }
    }

    /**
     * Where to place the card relative to what is hovered.
     *
     * On the right when there is room, on the left otherwise, and moved up
     * to stay whole on screen. Window coordinates, for a `position:
     * fixed`: the card must be able to leave the scrolling grid.
     */
    function place(rect) {
        const largeurVue = window.innerWidth;
        const hauteurVue = window.innerHeight;

        const aDroite = rect.right + ECART + LARGEUR + MARGE <= largeurVue;
        const left = aDroite
            ? rect.right + ECART
            : Math.max(MARGE, rect.left - ECART - LARGEUR);

        const top = Math.min(
            Math.max(MARGE, rect.top),
            Math.max(MARGE, hauteurVue - HAUTEUR - MARGE),
        );

        return { top, left };
    }

    /**
     * Nothing that happens here must come out as an exception.
     *
     * The call starts from a timer, so its failure would have no caller to
     * catch it: it would bubble up as an unhandled rejection to the page's
     * `errorCaptured`, which would replace the whole library with its error
     * screen - for a preview bubble. A preview that does not come must cost
     * nothing more than its absence.
     */
    async function charge(id) {
        if (cache.has(id)) return cache.get(id);

        try {
            const { ok, payload } = (await fetchNote(id)) ?? {};

            if (!ok) return null;

            const texte = String(payload?.note?.content ?? "").slice(0, COUPE);
            cache.set(id, texte);

            return texte;
        } catch {
            return null;
        }
    }

    /**
     * Schedules the opening. The real work only happens at the end of the
     * delay.
     *
     * @param {{id: number}} note
     * @param {HTMLElement} element what is hovered
     */
    function open(note, element) {
        if (!survolPossible() || !element) return;

        window.clearTimeout(minuteur);

        minuteur = window.setTimeout(async () => {
            const id = Number(note.id);
            const jeton = ++demande;

            position.value = place(element.getBoundingClientRect());
            noteId.value = id;
            content.value = cache.get(id) ?? "";
            loading.value = !cache.has(id);

            const texte = await charge(id);

            // The cursor may have moved elsewhere during the request: what
            // comes back must not overwrite what is being looked at now.
            if (jeton !== demande) return;

            loading.value = false;

            if (null === texte) {
                noteId.value = null;

                return;
            }

            content.value = texte;
        }, DELAI);
    }

    function close() {
        window.clearTimeout(minuteur);
        ++demande;
        noteId.value = null;
        loading.value = false;
    }

    return { noteId, content, loading, position, open, close };
}
