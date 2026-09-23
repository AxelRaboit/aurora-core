import { computed, ref } from "vue";

const VIEW_KEY = "aurora.notes.library.view";
const SORT_KEY = "aurora.notes.library.sort";
const DIRECTION_KEY = "aurora.notes.library.direction";

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

    const childFolders = computed(() =>
        folders.value.filter(
            (folder) => normaliseId(folder.parentId) === currentFolderId.value,
        ),
    );

    const childNotes = computed(() =>
        notes.value.filter(
            (note) => normaliseId(note.folderId) === currentFolderId.value,
        ),
    );

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

    function sorted(items, labelOf) {
        const factor = "asc" === direction.value ? 1 : -1;

        return [...items].sort((a, b) => {
            if ("manual" === sort.value) {
                return factor * ((a.position ?? 0) - (b.position ?? 0));
            }

            if ("name" === sort.value) {
                // `localeCompare` with `numeric` so "Note 2" comes before
                // "Note 10", which a plain code-point comparison reverses.
                return (
                    factor *
                    String(labelOf(a) ?? "").localeCompare(
                        String(labelOf(b) ?? ""),
                        undefined,
                        { numeric: true, sensitivity: "base" },
                    )
                );
            }

            const field = "created" === sort.value ? "createdAt" : "updatedAt";

            return (
                factor * (Date.parse(a[field] ?? 0) - Date.parse(b[field] ?? 0))
            );
        });
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
        sort.value = value;
        store(SORT_KEY, value);
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
        folders: sortedFolders,
        notes: sortedNotes,
        isEmpty,
        count,
        openFolder,
        onPopState,
        setView,
        setSort,
        toggleDirection,
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
