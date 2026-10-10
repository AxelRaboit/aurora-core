import {
    ID_PLACEHOLDER,
    idFromAddress,
} from "@notes/suite/markdown/composables/noteAddress.js";
import { computed, ref } from "vue";

const VIEW_KEY = "aurora.notes.library.view";
const SORT_KEY = "aurora.notes.library.sort";
const DIRECTION_KEY = "aurora.notes.library.direction";
const FLAT_KEY = "aurora.notes.library.flat";

export const VIEWS = ["mosaic", "cards", "list", "table"];
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
 * **Two ways of looking at the same notebook.** Filed, the list shows what
 * the place contains: its folders, then its notes, and the rest is behind
 * the folders. Flattened, it shows all the notes from here and below at
 * once, as if there were no filing - what one wants when looking for
 * something one no longer knows where one put. The folders then disappear
 * from the list: showing them on top of the notes they contain would show
 * the same thing twice.
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
    personalSpaceId = null,
}) {
    const currentFolderId = ref(
        null === initialFolderId ? null : Number(initialFolderId),
    );
    const view = ref(readStored(VIEW_KEY, VIEWS, "mosaic"));
    const sort = ref(readStored(SORT_KEY, SORTS, "updated"));
    const direction = ref(readStored(DIRECTION_KEY, ["asc", "desc"], "desc"));
    const flat = ref("1" === readStored(FLAT_KEY, ["0", "1"], "0"));

    /**
     * The tag being viewed, if there is one.
     *
     * Not remembered from one visit to the next, unlike the view and the
     * sort: it is a question one asks, not a way of reading. Finding one's
     * notebook filtered the next day without knowing why would be a trap.
     */
    const tag = ref(null);

    /**
     * What is being viewed: everything, what is open to the team, or what
     * is not.
     *
     * **Knowing what one exposes is better than guessing it.** Once sharing
     * is possible, the question "what has left my place" really comes up,
     * and going through the cards one by one to answer it would be absurd.
     * Three states rather than two: "privé" answers the opposite question,
     * which is the one asked when looking for where to file something
     * sensitive.
     *
     * Not remembered from one visit to the next, like the tag: it is a
     * question one asks, not a way of reading.
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
     * What is open, and everything below it.
     *
     * Bounded by the number of folders: a loop in the parents must not
     * freeze the page, here no more than in the breadcrumb.
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

    // Flattened as under a tag, there is no folder to show: the notes are
    // all already there, and giving them again as cards would double the
    // display. A folder does not carry a tag anyway.
    /**
     * The visibility filter, applied to a folder as to a note.
     *
     * "Partagé" means: in a space other than one's own. The space says who
     * reads, no longer a mark set on the row.
     */
    function matchesVisibility(item) {
        if ("all" === visibility.value) return true;

        const partage = isShared(item);

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
     * A tag is searched across the whole notebook, not within a folder.
     *
     * It is the question asked when clicking it: "where are my location
     * scouting notes", not "which of these". Limiting it to the open folder
     * would make the click silent one time out of two.
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

    /** The name of the folder holding a note, to say it on its card. */
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
     * The rank of two items, before the direction gets involved.
     *
     * **There is never a tie at the end.** An import gives the same second
     * to thirty notes, and a sort by date then left them in an order the
     * direction button did not change: it looked broken, and it was not.
     * The name breaks the tie, then the id, which is unique - so reversing
     * the direction always reverses something.
     */
    function rank(left, right, labelOf) {
        if ("manual" === sort.value) {
            const byPosition = (left.position ?? 0) - (right.position ?? 0);

            if (0 !== byPosition) return byPosition;
        } else if ("name" !== sort.value) {
            const field = "created" === sort.value ? "createdAt" : "updatedAt";

            // An unreadable date is zero rather than `NaN`: a comparator that
            // returns `NaN` leaves the order at the engine's mercy.
            const at = (item) => {
                const value = Date.parse(item[field]);

                return Number.isFinite(value) ? value : 0;
            };

            const byDate = at(left) - at(right);

            if (0 !== byDate) return byDate;
        }

        // `localeCompare` with `numeric` so that "Note 2" comes before
        // "Note 10", which a code comparison reverses.
        const byName = String(labelOf(left) ?? "").localeCompare(
            String(labelOf(right) ?? ""),
            undefined,
            { numeric: true, sensitivity: "base" },
        );

        if (0 !== byName) return byName;

        return Number(left.id ?? 0) - Number(right.id ?? 0);
    }

    function sorted(items, labelOf) {
        const factor = "asc" === direction.value ? 1 : -1;

        return [...items].sort(
            (left, right) => factor * rank(left, right, labelOf),
        );
    }

    /**
     * Enter a folder, or come back to the root with null.
     *
     * `pushState` rather than a navigation: the notebook is already loaded,
     * so this is a filter, and the address stays the one that would render
     * the same screen on a reload.
     */
    function openFolder(id, { push = true } = {}) {
        // Zero is not a folder: no sequence hands it out, so a zero arriving
        // here comes from a `Number(null)` along the way, and it means the
        // root.
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

    /**
     * Read against the folder address the page writes, so a hosted screen -
     * whose folder is a query parameter of the host's page - reads it back.
     */
    function readFolderFromLocation() {
        return idFromAddress(urlFor(ID_PLACEHOLDER));
    }

    function setView(value) {
        if (!VIEWS.includes(value)) return;
        view.value = value;
        store(VIEW_KEY, value);
    }

    function setSort(value) {
        if (!SORTS.includes(value)) return;

        // Manual order belongs to a folder: flattened, it has nobody to
        // belong to.
        if (flat.value && "manual" === value) return;

        sort.value = value;
        store(SORT_KEY, value);
    }

    /**
     * Switch from filed to completely flat.
     *
     * Manual order no longer makes sense when flat - two notes from two
     * folders have no common position - so we fall back to the date, which
     * is the default sort and the only one that means something when places
     * are mixed.
     */
    function toggleFlat() {
        flat.value = !flat.value;
        store(FLAT_KEY, flat.value ? "1" : "0");

        if (flat.value && "manual" === sort.value) setSort("updated");
    }

    /**
     * View a tag, or go back to the place one was in.
     *
     * The open folder is not lost in the meantime: it is still in the
     * address and in the breadcrumb, and closing the tag goes back there
     * without navigating.
     */
    function setTag(value) {
        tag.value =
            null === value || undefined === value || "" === value
                ? null
                : String(value);
    }

    /** In a shared space, so readable by others than oneself. */
    function isShared(item) {
        return (
            null !== personalSpaceId &&
            null != item.spaceId &&
            Number(item.spaceId) !== Number(personalSpaceId)
        );
    }

    /**
     * Cycles the filter: everything, shared, private, and back.
     *
     * A single button rather than three: the bar already carries six, and
     * the tooltip says which of the three states is in force.
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
        isShared,
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
