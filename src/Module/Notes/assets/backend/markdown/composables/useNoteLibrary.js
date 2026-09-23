import { computed, ref } from "vue";

const VIEW_KEY = "aurora.notes.library.view";
const SORT_KEY = "aurora.notes.library.sort";
const DIRECTION_KEY = "aurora.notes.library.direction";
const FLAT_KEY = "aurora.notes.library.flat";

export const VIEWS = ["mosaic", "cards", "list"];
export const SORTS = ["name", "updated", "created", "manual"];

/**
 * What the library shows, and in what order.
 *
 * **Everything is sorted here, in the browser.** Titles and folder names are
 * encrypted columns, so no `ORDER BY` can touch them: the server hands over
 * the person's folders and notes, and the ordering is decided against the
 * decrypted values. That is the same reason the tree filter and the tag
 * filter already run client-side.
 *
 * **Folders come first, always.** Whatever the sort, a folder is a place and
 * a note is a thing in it; every file browser ever written agrees, and Craft
 * does too.
 *
 * **Deux façons de regarder le même carnet.** Rangée, la liste montre ce
 * que l'endroit contient : ses dossiers, puis ses notes, et le reste est
 * derrière les dossiers. À plat, elle montre toutes les notes d'ici et de
 * dessous d'un coup, comme s'il n'y avait pas de rangement - ce qu'on veut
 * quand on cherche quelque chose dont on ne sait plus où on l'a mis. Les
 * dossiers disparaissent alors de la liste : les afficher en plus des notes
 * qu'ils contiennent montrerait deux fois la même chose.
 *
 * Navigation writes the real address with `pushState` rather than reloading:
 * the whole notebook is already in the page, so entering a folder is a filter
 * over what we hold, and the address still names where the reader is.
 */
export function useNoteLibrary({
    folders,
    notes,
    initialFolderId = null,
    breadcrumb = [],
    urlFor,
    rootUrl,
}) {
    const currentFolderId = ref(
        null === initialFolderId ? null : Number(initialFolderId),
    );
    const view = ref(readStored(VIEW_KEY, VIEWS, "mosaic"));
    const sort = ref(readStored(SORT_KEY, SORTS, "updated"));
    const direction = ref(readStored(DIRECTION_KEY, ["asc", "desc"], "desc"));
    const flat = ref("1" === readStored(FLAT_KEY, ["0", "1"], "0"));

    /**
     * L'étiquette qu'on regarde, s'il y en a une.
     *
     * Pas retenue d'une visite à l'autre, contrairement à la vue et au tri :
     * c'est une question qu'on pose, pas une façon de lire. Retrouver son
     * carnet filtré le lendemain sans savoir pourquoi serait un piège.
     */
    const tag = ref(null);

    /**
     * Ce qu'on regarde : tout, ce qui est ouvert à l'équipe, ou ce qui ne
     * l'est pas.
     *
     * **Savoir ce qu'on expose vaut mieux que de le deviner.** Une fois le
     * partage possible, la question « qu'est-ce qui est sorti de chez
     * moi » se pose vraiment, et parcourir les cartes une par une pour y
     * répondre serait absurde. Trois états plutôt que deux : « privé »
     * répond à la question inverse, qui est celle qu'on se pose quand on
     * cherche où ranger quelque chose de sensible.
     *
     * Pas retenu d'une visite à l'autre, comme l'étiquette : c'est une
     * question qu'on pose, pas une façon de lire.
     */
    const visibility = ref("all");

    // Seeded from the server so a reload does not flash the root while the
    // chain is recomputed; rebuilt from the folder list on every move after.
    const serverBreadcrumb = ref(breadcrumb.map(normaliseCrumb));

    const foldersById = computed(() => {
        const map = new Map();
        for (const folder of folders.value) map.set(Number(folder.id), folder);

        return map;
    });

    const currentFolder = computed(() =>
        null === currentFolderId.value
            ? null
            : (foldersById.value.get(currentFolderId.value) ?? null),
    );

    /**
     * The chain from the root to here, the current folder last.
     *
     * Walked from the folder list rather than refetched, and bounded by the
     * number of folders so a cycle in the data cannot hang the page.
     */
    const path = computed(() => {
        if (null === currentFolderId.value) return [];

        const chain = [];
        const seen = new Set();
        let node = foldersById.value.get(currentFolderId.value) ?? null;

        while (node && !seen.has(Number(node.id))) {
            seen.add(Number(node.id));
            chain.unshift(normaliseCrumb(node));
            node = node.parentId
                ? (foldersById.value.get(Number(node.parentId)) ?? null)
                : null;
        }

        // Before the folder list has been refreshed the walk can come up
        // empty, and the server's answer is the better one in that moment.
        return chain.length ? chain : serverBreadcrumb.value;
    });

    /**
     * Ce qui est ouvert, et tout ce qui se trouve dessous.
     *
     * Borné par le nombre de dossiers : une boucle dans les parents ne doit
     * pas figer la page, pas plus ici que dans le fil d'Ariane.
     */
    const subtreeIds = computed(() => {
        const ids = new Set([currentFolderId.value]);
        const children = new Map();

        for (const folder of folders.value) {
            const parent = normaliseId(folder.parentId);
            if (!children.has(parent)) children.set(parent, []);
            children.get(parent).push(Number(folder.id));
        }

        const queue = [currentFolderId.value];

        while (queue.length) {
            for (const id of children.get(queue.shift()) ?? []) {
                if (ids.has(id)) continue;

                ids.add(id);
                queue.push(id);
            }
        }

        return ids;
    });

    // À plat comme sous une étiquette, il n'y a pas de dossier à montrer :
    // les notes sont déjà toutes là, et les redonner en cartes doublerait
    // l'affichage. Un dossier ne porte d'ailleurs pas d'étiquette.
    /** Le filtre de visibilité, posé sur un dossier comme sur une note. */
    function matchesVisibility(item) {
        if ("all" === visibility.value) return true;

        const partage = null !== (item.sharedAt ?? null);

        return "shared" === visibility.value ? partage : !partage;
    }

    const childFolders = computed(() =>
        flat.value || null !== tag.value
            ? []
            : folders.value.filter(
                  (folder) =>
                      normaliseId(folder.parentId) === currentFolderId.value &&
                      matchesVisibility(folder),
              ),
    );

    /**
     * Une étiquette se cherche dans tout le carnet, pas dans un dossier.
     *
     * C'est la question qu'on pose en cliquant dessus : « où sont mes notes
     * de repérage », pas « lesquelles de celles-ci ». La limiter au dossier
     * ouvert rendrait le clic muet une fois sur deux.
     */
    const childNotes = computed(() => {
        if (null !== tag.value) {
            return notes.value.filter((note) =>
                (note.tags ?? []).includes(tag.value),
            );
        }

        return notes.value.filter(
            (note) =>
                matchesVisibility(note) &&
                (flat.value
                    ? subtreeIds.value.has(normaliseId(note.folderId))
                    : normaliseId(note.folderId) === currentFolderId.value),
        );
    });

    /** Le nom du dossier qui contient une note, pour le dire sur sa carte. */
    function folderNameOf(id) {
        return foldersById.value.get(normaliseId(id))?.name ?? null;
    }

    const sortedFolders = computed(() =>
        sorted(childFolders.value, (folder) => folder.name),
    );

    const sortedNotes = computed(() =>
        sorted(childNotes.value, (note) => note.title),
    );

    const isEmpty = computed(
        () =>
            0 === sortedFolders.value.length && 0 === sortedNotes.value.length,
    );

    const count = computed(
        () => sortedFolders.value.length + sortedNotes.value.length,
    );

    /**
     * Le rang de deux éléments, avant que le sens s'en mêle.
     *
     * **Il n'y a jamais d'égalité au bout.** Une importation donne la même
     * seconde à trente notes, et un tri par date les laissait alors dans un
     * ordre que le bouton de sens ne changeait pas : il avait l'air cassé,
     * et il ne l'était pas. Le nom départage, puis l'identifiant, qui lui
     * est unique - ainsi inverser le sens inverse toujours quelque chose.
     */
    function rank(a, b, labelOf) {
        if ("manual" === sort.value) {
            const byPosition = (a.position ?? 0) - (b.position ?? 0);

            if (0 !== byPosition) return byPosition;
        } else if ("name" !== sort.value) {
            const field = "created" === sort.value ? "createdAt" : "updatedAt";

            // Une date illisible vaut zéro plutôt que `NaN` : un comparateur
            // qui rend `NaN` laisse l'ordre à la merci du moteur.
            const at = (item) => {
                const value = Date.parse(item[field]);

                return Number.isFinite(value) ? value : 0;
            };

            const byDate = at(a) - at(b);

            if (0 !== byDate) return byDate;
        }

        // `localeCompare` avec `numeric` pour que « Note 2 » précède
        // « Note 10 », que la comparaison de codes inverse.
        const byName = String(labelOf(a) ?? "").localeCompare(
            String(labelOf(b) ?? ""),
            undefined,
            { numeric: true, sensitivity: "base" },
        );

        if (0 !== byName) return byName;

        return Number(a.id ?? 0) - Number(b.id ?? 0);
    }

    function sorted(items, labelOf) {
        const factor = "asc" === direction.value ? 1 : -1;

        return [...items].sort((a, b) => factor * rank(a, b, labelOf));
    }

    /**
     * Enter a folder, or come back to the root with null.
     *
     * `pushState` rather than a navigation: the notebook is already loaded,
     * so this is a filter, and the address stays the one that would render
     * the same screen on a reload.
     */
    function openFolder(id, { push = true } = {}) {
        // Zéro n'est pas un dossier : aucune séquence ne le distribue, donc
        // un zéro qui arrive ici vient d'un `Number(null)` en chemin, et il
        // veut dire la racine.
        const next = normaliseId(id) || null;
        currentFolderId.value = next;

        if (!push) return;

        const url = null === next ? rootUrl : urlFor(next);
        try {
            window.history.pushState({ folderId: next }, "", url);
        } catch {
            // A sandboxed frame refuses history writes; the filter still
            // applied, which is the part the reader asked for.
        }
    }

    /** Follow the browser's own back and forward buttons. */
    function onPopState(event) {
        const fromState = event?.state?.folderId;
        currentFolderId.value =
            undefined === fromState
                ? readFolderFromLocation()
                : normaliseId(fromState);
    }

    function readFolderFromLocation() {
        const match = /\/folder\/(\d+)/.exec(window.location.pathname);

        return match ? Number(match[1]) : null;
    }

    function setView(value) {
        if (!VIEWS.includes(value)) return;
        view.value = value;
        store(VIEW_KEY, value);
    }

    function setSort(value) {
        if (!SORTS.includes(value)) return;

        // L'ordre manuel appartient à un dossier : à plat, il n'a personne
        // à qui appartenir.
        if (flat.value && "manual" === value) return;

        sort.value = value;
        store(SORT_KEY, value);
    }

    /**
     * Passer du rangé au tout-à-plat.
     *
     * L'ordre manuel n'a plus de sens à plat - deux notes de deux dossiers
     * n'ont pas de position commune - donc on retombe sur la date, qui est
     * le tri par défaut et le seul qui veuille dire quelque chose quand on
     * mélange des endroits.
     */
    function toggleFlat() {
        flat.value = !flat.value;
        store(FLAT_KEY, flat.value ? "1" : "0");

        if (flat.value && "manual" === sort.value) setSort("updated");
    }

    /**
     * Regarder une étiquette, ou revenir à l'endroit où l'on était.
     *
     * Le dossier ouvert n'est pas perdu pendant ce temps : il est toujours
     * dans l'adresse et dans le fil d'Ariane, et fermer l'étiquette y
     * ramène sans naviguer.
     */
    function setTag(value) {
        tag.value =
            null === value || undefined === value || "" === value
                ? null
                : String(value);
    }

    /**
     * Fait tourner le filtre : tout, partagés, privés, et retour.
     *
     * Un seul bouton plutôt que trois : la barre en porte déjà six, et
     * l'infobulle dit lequel des trois états est en vigueur.
     */
    function cycleVisibility() {
        visibility.value =
            "all" === visibility.value
                ? "shared"
                : "shared" === visibility.value
                  ? "private"
                  : "all";
    }

    function toggleDirection() {
        direction.value = "asc" === direction.value ? "desc" : "asc";
        store(DIRECTION_KEY, direction.value);
    }

    return {
        currentFolderId,
        currentFolder,
        path,
        view,
        sort,
        direction,
        flat,
        tag,
        visibility,
        folders: sortedFolders,
        notes: sortedNotes,
        isEmpty,
        count,
        openFolder,
        onPopState,
        setView,
        setSort,
        toggleDirection,
        toggleFlat,
        setTag,
        cycleVisibility,
        folderNameOf,
    };
}

function normaliseCrumb(folder) {
    return { id: Number(folder.id), name: folder.name ?? null };
}

function normaliseId(value) {
    if (null === value || undefined === value || "" === value) return null;

    const id = Number(value);

    return Number.isFinite(id) ? id : null;
}

function readStored(key, allowed, fallback) {
    try {
        const stored = window.localStorage.getItem(key);

        return allowed.includes(stored) ? stored : fallback;
    } catch {
        // localStorage unavailable (private browsing, sandboxed) - ignore.
        return fallback;
    }
}

function store(key, value) {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // Same as above: a remembered preference is a convenience, not state.
    }
}
