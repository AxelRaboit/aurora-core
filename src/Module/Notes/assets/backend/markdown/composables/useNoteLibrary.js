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

    // À plat, il n'y a pas de dossier à montrer : ils sont dépliés dans la
    // liste des notes, et les redonner en cartes doublerait l'affichage.
    const childFolders = computed(() =>
        flat.value
            ? []
            : folders.value.filter(
                  (folder) =>
                      normaliseId(folder.parentId) === currentFolderId.value,
              ),
    );

    const childNotes = computed(() =>
        notes.value.filter((note) =>
            flat.value
                ? subtreeIds.value.has(normaliseId(note.folderId))
                : normaliseId(note.folderId) === currentFolderId.value,
        ),
    );

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
