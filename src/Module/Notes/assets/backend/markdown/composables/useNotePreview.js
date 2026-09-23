import { ref } from "vue";

/** Ce qu'on attend avant de déranger : assez pour ne pas suivre un curseur. */
const DELAI = 450;

/** La carte, en pixels. Sert à décider de quel côté elle tient. */
const LARGEUR = 360;
const HAUTEUR = 280;
const ECART = 12;
const MARGE = 8;

/**
 * Ce qu'on garde de la note : au-delà, on ne lit plus, on survole.
 *
 * Une note de dix mille signes rendue en entier coûte un analyseur complet
 * et un arbre DOM qu'on ne montrera jamais, pour une carte qui en affiche
 * vingt lignes.
 */
const COUPE = 1200;

/**
 * Le rendu d'une note, au survol de sa carte.
 *
 * **Le rendu, pas la source.** L'extrait des cartes est du texte aplati, ce
 * qui répond à « laquelle est-ce » ; l'aperçu répond à « qu'est-ce qu'il y a
 * dedans », et pour ça il faut les titres, les listes et les cases cochées
 * telles qu'elles se liront.
 *
 * Le contenu est chiffré en base, donc il n'arrive pas avec la liste : c'est
 * une requête par note, faite une fois et gardée pour la session. D'où le
 * délai avant d'ouvrir - traverser une mosaïque ne doit pas déclencher
 * trente requêtes.
 *
 * Rien de tout cela sur un écran tactile : il n'y a pas de survol, et une
 * carte qui s'ouvrirait à l'effleurement passerait devant ce qu'on visait.
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
            // matchMedia absent (jsdom ancien, environnement restreint) :
            // pas d'aperçu plutôt qu'une exception au survol.
            return false;
        }
    }

    /**
     * Où poser la carte par rapport à ce qu'on survole.
     *
     * À droite quand il y a la place, à gauche sinon, et remontée pour
     * rester entière à l'écran. Coordonnées de fenêtre, pour un `position:
     * fixed` : la carte doit pouvoir sortir de la grille qui défile.
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
     * Rien de ce qui se passe ici ne doit ressortir en exception.
     *
     * L'appel part d'un minuteur, donc son échec n'aurait aucun appelant
     * pour l'attraper : il remonterait en rejet non traité jusqu'au
     * `errorCaptured` de la page, qui remplacerait la bibliothèque entière
     * par son écran d'erreur - pour une bulle d'aperçu. Un aperçu qui ne
     * vient pas ne doit rien coûter de plus que son absence.
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
     * Programme l'ouverture. Le vrai travail n'a lieu qu'au bout du délai.
     *
     * @param {{id: number}} note
     * @param {HTMLElement} element ce qui est survolé
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

            // Le curseur a pu partir ailleurs pendant la requête : ce qui
            // revient ne doit pas écraser ce qu'on regarde maintenant.
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
