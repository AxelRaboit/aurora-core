<script setup>
/**
 * The notebook, in the side menu.
 *
 * For one version it only carried folders, and that was half an answer: one
 * saw the filing without seeing what is filed. **A folder expands here** and
 * shows its notes, as in any file explorer; the library stays the screen
 * where one looks, sorts and files, the panel the one from which one gets
 * there.
 *
 * **Expansion is remembered.** It lives in the browser, not in the notebook:
 * it is a reading habit, it only concerns the person sitting there, and it
 * must survive a page change - the panel is remounted on every navigation.
 *
 * **Rows are real addresses.** A folder is a page
 * (`/suite/notes/markdown/folder/42`), a note too, so both can be sent and a
 * middle click behaves. On a simple click the panel first asks the page,
 * through `modulePanelBridge`: it is mounted, it takes the click and changes
 * folder or note in place. Nobody listening means the reader is elsewhere in
 * the module, and the link navigates.
 *
 * **The search stays global**, and it is the only place where it is: it goes
 * through the titles, the tags and the text of the notes - the latter on the
 * server side, the bodies being encrypted - and the tree opens on what it
 * found.
 */
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { BookOpen, ChevronDown, ChevronRight, ChevronsDownUp, Download, FileInput, FileText, Folder, Globe, Pin, PinOff, Plus, Settings2, Tag, Upload, User, Users } from "lucide-vue-next";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppModulePanel from "@/shared/nav/AppModulePanel.vue";
import { useDebounce } from "@/shared/composables/useDebounce.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { askPage, onPageNotice } from "@/shared/nav/modulePanelBridge.js";
import { useModulePanelData } from "@/shared/nav/useModulePanelData.js";
import { folderIdsIn, useNoteTree } from "./composables/useNoteTree.js";
import { peekNoteDrag, readNoteDrag, startNoteDrag } from "./composables/noteDrag.js";
import { dropZone, planDrop } from "./composables/noteDropPlan.js";
import { sortSpaces, spaceLabel } from "./composables/noteSpaces.js";
import { readExpanded, storeExpanded } from "./composables/expandedStore.js";
import NoteTreeItem from "./components/NoteTreeItem.vue";

const FOLDERS_ENDPOINT = "/suite/notes/markdown/folders";
const NOTES_ENDPOINT = "/suite/notes/markdown/list";
const SEARCH_ENDPOINT = "/suite/notes/markdown/search";
const SPACES_ENDPOINT = "/suite/notes/spaces";
const LIBRARY_URL = "/suite/notes/markdown";
const EXPORT_URL = "/suite/notes/markdown/export";
const PINNED_TAGS_KEY = "aurora.notes.panel.pinnedTags";
const TAGS_OPEN_KEY = "aurora.notes.panel.tagsOpen";
const SPACES_CLOSED_KEY = "aurora.notes.panel.spacesClosed";

/** How many unpinned tags are shown before folding. */
const TAGS_SHOWN = 8;

const { t } = useI18n();

const {
    data: fetchedFolders,
    loading,
    failed,
} = useModulePanelData(FOLDERS_ENDPOINT, { key: "folders" });

const { data: fetchedNotes } = useModulePanelData(NOTES_ENDPOINT, {
    key: "notes",
});

/**
 * What the page announces wins over what the panel fetched.
 *
 * We load on arrival because the panel may be rendered before the page is
 * mounted; then the page announces every change, which makes a folder
 * created there appear here without reloading.
 */
const announcedFolders = ref(null);
const announcedNotes = ref(null);

const folders = computed(() => announcedFolders.value ?? fetchedFolders.value);
const notes = computed(() => announcedNotes.value ?? fetchedNotes.value);

const selectedKey = ref(null);
const treeQuery = ref("");

const searching = computed(() => "" !== treeQuery.value.trim());

/**
 * The notes' text, searched on the server side.
 *
 * The bodies are not in the browser, and they are encrypted in the database:
 * the `/search` endpoint decrypts the person's notes and returns the matching
 * ids. Without it, searching "facture" would only find the notes that have
 * the word in their title, which is rarely where it was written.
 */
const { request } = useRequest();
const contentMatchIds = ref(new Set());

const runContentSearch = useDebounce(async (query) => {
    const payload = await request(
        `${SEARCH_ENDPOINT}?q=${encodeURIComponent(query)}`,
        null,
        { method: HttpMethod.Get, noGuard: true },
    );

    contentMatchIds.value = new Set((payload?.ids ?? []).map((id) => Number(id)));
}, 300);

watch(treeQuery, (value) => {
    const trimmed = value.trim();

    if ("" === trimmed) {
        contentMatchIds.value = new Set();

        return;
    }

    runContentSearch(trimmed);
});

const { tree } = useNoteTree(folders, treeQuery, notes, contentMatchIds);

const isEmpty = computed(() => 0 === folders.value.length && 0 === notes.value.length);

// ── Fold, expand ───────────────────────────────────────────────────

const openedIds = ref(readExpanded());

/**
 * What is open on screen: what the person expanded, and during a search,
 * everything the filtered tree contains. A search that leaves the branches
 * closed shows nothing, since what it found is precisely folded.
 */
const expanded = computed(() =>
    searching.value ? folderIdsIn(tree.value) : openedIds.value,
);

/**
 * Expand what is needed for a note to be visible.
 *
 * **The comment next door already promised that "son dossier s'ouvre", and
 * the code only highlighted it.** It showed when creating a note in a folded
 * folder: the note was indeed created and selected, but it stayed hidden
 * behind a closed arrow, and nothing said anything had happened.
 *
 * The whole chain, not only the direct folder: a note filed three levels
 * down stays invisible if only the last one is opened. The loop guards
 * against cycles by counting its turns, because a parent pointing at itself
 * would make the page spin without showing anything.
 */
function revealNote(noteId) {
    const note = notes.value.find((candidate) => Number(candidate.id) === Number(noteId));

    if (!note?.folderId) return;

    const parents = new Map(
        folders.value.map((folder) => [Number(folder.id), Number(folder.parentId) || null]),
    );

    const next = new Set(openedIds.value);
    let id = Number(note.folderId);

    for (let garde = 0; null !== id && garde <= parents.size; garde += 1) {
        if (next.has(id)) break;

        next.add(id);
        id = parents.get(id) ?? null;
    }

    openedIds.value = next;
    storeExpanded(next);
}

function toggle(node) {
    const id = Number(node.id);
    const next = new Set(openedIds.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    openedIds.value = next;
    storeExpanded(next);
}

/** Expand without ever folding: what a click on a folder does. */
function open(id) {
    if (null === id || openedIds.value.has(Number(id))) return;

    const next = new Set(openedIds.value);
    next.add(Number(id));
    openedIds.value = next;
    storeExpanded(next);
}

/**
 * Fold everything in one gesture, like Obsidian.
 *
 * A notebook that has been browsed ends up expanded everywhere, and folding
 * folder by folder is exactly the kind of housekeeping nobody ever does.
 */
function collapseAll() {
    openedIds.value = new Set();
    storeExpanded(openedIds.value);
}

const anyOpen = computed(() => !searching.value && openedIds.value.size > 0);

// ── Favourites ─────────────────────────────────────────────────────

/** A folder is a page, a note too: each offers its address. */
const hrefFor = (node) =>
    "folder" === node.kind
        ? `${LIBRARY_URL}/folder/${node.id}`
        : `${LIBRARY_URL}/${node.id}`;

/**
 * Switch to reading, in one click, from anywhere in the module.
 *
 * One had to open a note then look for "Lire" in its three dots: two
 * gestures and a menu to change the way of being in one's notebook. We read
 * the open note, or the first of the notebook when none is open.
 */
function firstNoteIn(nodes) {
    for (const node of nodes) {
        if ("note" === node.kind) return node;

        const found = firstNoteIn(node.children ?? []);
        if (found) return found;
    }

    return null;
}

const readTargetId = computed(() => {
    if (selectedKey.value?.startsWith("note:")) return Number(selectedKey.value.slice(5));

    return firstNoteIn(tree.value)?.id ?? null;
});

function openReader() {
    if (null !== readTargetId.value) window.location.assign(`${LIBRARY_URL}/${readTargetId.value}/read`);
}

/**
 * Alt+R, from any notes screen: the reader without looking for a button.
 * Read on `code` and not on `key`, because Alt+R types "®" on a Mac while
 * the key itself stays the same.
 */
function onShortcut(event) {
    if (!event.altKey || event.ctrlKey || event.metaKey || "KeyR" !== event.code) return;

    event.preventDefault();
    openReader();
}

/**
 * What is pinned, folders then notes, most recent first.
 *
 * Craft opens its menu on this, and it is the only place in the module from
 * which a note is reached in one click without knowing where it is filed.
 * Hidden during a search: the result list already answers the question
 * asked.
 */
const favorites = computed(() => {
    if (searching.value) return [];

    const pinned = (items, kind) =>
        items
            .filter((one) => Boolean(one.favoritedAt))
            .map((item) => ({ ...item, kind, key: `${kind}:${item.id}` }));

    return [
        ...pinned(folders.value, "folder"),
        ...pinned(notes.value, "note"),
    ].sort((left, right) => Date.parse(right.favoritedAt) - Date.parse(left.favoritedAt));
});

// ── Spaces ─────────────────────────────────────────────────────────

/**
 * The spaces the person reads, each with its role.
 *
 * **One section per space**, one's own first: what lives in a shared space
 * is filed under its name, and the header tells at a glance who reads it and
 * whether one can write in it. Mixing the roots of several spaces in a single
 * tree would suggest that a note dragged from one folder to another stays at
 * home, when it actually changes readers.
 */
const fetchedSpaces = ref(null);
const announcedSpaces = ref(null);
const canCreateSpace = ref(false);
/** Whether the Craft connection is open: a space's menu then offers the import. */
const craftEnabled = ref(false);

const spaces = computed(() => sortSpaces(announcedSpaces.value ?? fetchedSpaces.value ?? []));

onMounted(async () => {
    const payload = await request(SPACES_ENDPOINT, null, {
        method: HttpMethod.Get,
        noGuard: true,
    });

    if (payload) {
        fetchedSpaces.value = payload.spaces ?? [];
        canCreateSpace.value = Boolean(payload.canCreate);
        craftEnabled.value = Boolean(payload.craftEnabled);
    }
});

function spaceById(id) {
    return spaces.value.find((space) => Number(space.id) === Number(id)) ?? null;
}

/** As long as the spaces are not known, nothing is refused: the server will decide. */
function canWriteIn(spaceId) {
    if (null == spaceId || !spaces.value.length) return true;

    return Boolean(spaceById(spaceId)?.canWrite);
}

/**
 * The tree split by space. A top-level row tells its space; what it holds is
 * necessarily from the same one.
 */
const spaceGroups = computed(() => {
    if (!spaces.value.length) return [{ space: null, nodes: tree.value }];

    const bySpace = new Map(spaces.value.map((space) => [Number(space.id), []]));
    const unplaced = [];

    for (const node of tree.value) {
        (bySpace.get(Number(node.spaceId)) ?? unplaced).push(node);
    }

    const groups = spaces.value.map((space) => ({ space, nodes: bySpace.get(Number(space.id)) }));

    // A row of a space the list does not know yet - just created elsewhere -
    // stays visible rather than disappearing.
    if (unplaced.length) groups[0].nodes = [...groups[0].nodes, ...unplaced];

    return searching.value ? groups.filter((group) => group.nodes.length) : groups;
});

/**
 * A space header always shows, one's own included.
 *
 * Alone, one's space showed without a header, to keep the old panel: the
 * notes were there, but nothing said where they lived, and "Mon espace"
 * could not be found until a second one had been created.
 */
const showSpaceHeaders = computed(() => spaceGroups.value.some((group) => null !== group.space));

function readClosed() {
    try {
        return new Set(JSON.parse(window.localStorage.getItem(SPACES_CLOSED_KEY) ?? "[]").map(Number));
    } catch {
        return new Set();
    }
}

const closedSpaces = ref(readClosed());

function isSpaceOpen(space) {
    return searching.value || !space || !closedSpaces.value.has(Number(space.id));
}

function toggleSpace(space) {
    const next = new Set(closedSpaces.value);
    const id = Number(space.id);

    if (next.has(id)) next.delete(id);
    else next.add(id);

    closedSpaces.value = next;

    try {
        window.localStorage.setItem(SPACES_CLOSED_KEY, JSON.stringify([...next]));
    } catch {
        // The fold stays valid for the visit; it will not be remembered.
    }
}

/** A space's root, as a drop target. */
function spaceRoot(space) {
    return { kind: "folder", id: null, key: `space:${space.id}`, spaceId: Number(space.id) };
}

/** What a space header's menu offers. */
function spaceActions(space) {
    return [
        ...(space.canWrite
            ? [{
                key: "import",
                title: t("notes.markdown.spaces.import_here"),
                icon: Upload,
                onSelect: () => forward("import", Number(space.id)),
            }]
            : []),
        // Only when the installation has opened the Craft connection.
        ...(space.canWrite && craftEnabled.value
            ? [{
                key: "craft-import",
                title: t("notes.craft.import.action"),
                icon: FileInput,
                onSelect: () => forward("craft-import", Number(space.id)),
            }]
            : []),
        {
            key: "export",
            title: t("notes.markdown.spaces.export"),
            icon: Download,
            onSelect: () => exportArchive("space", Number(space.id)),
        },
    ];
}

function addInSpace(space) {
    if (!isSpaceOpen(space)) toggleSpace(space);
    forward("add", { folderId: null, spaceId: Number(space.id) });
}

// ── Tags ───────────────────────────────────────────────────────────

/**
 * The notebook's tags, pinned ones first.
 *
 * **Pinning lives in the browser, not in the database.** A tag is not a row
 * in Aurora: it is a string in a note's `tags` array, with no identity of its
 * own. Giving it a table would make the first table whose rows designate
 * nothing, and the tags admin screen - which renames, merges and deletes -
 * would have to keep it up to date in three more places. Here, a tag that is
 * gone disappears from the list by itself, since only the ones that still
 * exist are shown.
 *
 * Favourites, on the other hand, are in the database: they hang on a note or
 * a folder, that is on something that has a row.
 */
const pinnedTags = ref(readStoredTags());
const tagsOpen = ref("1" === readStored(TAGS_OPEN_KEY, "1"));
const showAllTags = ref(false);

function readStored(key, fallback) {
    try {
        return window.localStorage.getItem(key) ?? fallback;
    } catch {
        // Private browsing, restricted frame: the preference is a comfort,
        // not a state of the notebook.
        return fallback;
    }
}

function readStoredTags() {
    try {
        const raw = JSON.parse(window.localStorage.getItem(PINNED_TAGS_KEY) ?? "[]");

        return Array.isArray(raw) ? raw.filter((one) => "string" === typeof one) : [];
    } catch {
        return [];
    }
}

function store(key, value) {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // Same: nothing to recover, the session goes on without memory.
    }
}

/** Every tag carried by at least one note, by frequency. */
const allTags = computed(() => {
    const counts = new Map();

    for (const note of notes.value) {
        for (const one of note.tags ?? []) {
            if ("string" !== typeof one || "" === one.trim()) continue;

            counts.set(one, (counts.get(one) ?? 0) + 1);
        }
    }

    return [...counts.entries()]
        .map(([name, count]) => ({ name, count }))
        .sort(
            (left, right) =>
                right.count - left.count ||
                left.name.localeCompare(right.name, undefined, { sensitivity: "base" }),
        );
});

/**
 * What is shown: the pinned ones, then the most carried.
 *
 * A pinned tag that no longer exists - renamed, merged, deleted from the tags
 * screen - drops by itself, since the list starts from what the notes
 * actually carry.
 */
const visibleTags = computed(() => {
    if (searching.value) return [];

    const pinned = pinnedTags.value;
    const marked = allTags.value.map((one) => ({
        ...one,
        pinned: pinned.includes(one.name),
    }));

    const first = marked.filter((one) => one.pinned);
    const rest = marked.filter((one) => !one.pinned);

    return [...first, ...(showAllTags.value ? rest : rest.slice(0, TAGS_SHOWN))];
});

const hiddenTagCount = computed(() =>
    showAllTags.value
        ? 0
        : Math.max(0, allTags.value.length - pinnedTags.value.length - TAGS_SHOWN),
);

function togglePinned(name) {
    pinnedTags.value = pinnedTags.value.includes(name)
        ? pinnedTags.value.filter((one) => one !== name)
        : [...pinnedTags.value, name];

    store(PINNED_TAGS_KEY, JSON.stringify(pinnedTags.value));
}

function toggleTagsSection() {
    tagsOpen.value = !tagsOpen.value;
    store(TAGS_OPEN_KEY, tagsOpen.value ? "1" : "0");
}

function labelOf(node) {
    if ("folder" === node.kind) {
        return node.name || t("notes.markdown.folders.untitled");
    }

    return node.title || t("notes.markdown.untitled");
}

// ── What the panel asks of the page ────────────────────────────────

/**
 * What is held, and where it would fall.
 *
 * **The panel files by itself.** It passed the drop to the library, which
 * does not exist when a note is open: dragging a note onto a folder from the
 * editor did nothing, without a word. It now computes the result - which
 * folder, which rank - and hands it to the page as plain data, which the page
 * writes whatever screen is shown.
 */
const draggingKey = ref(null);
const dropHint = ref(null);

/** The hovered folder that will open if one waits on it. */
let hoverTimer = null;
let hoverKey = null;

/** The hover time that expands a closed folder, as in the Finder. */
const HOVER_OPEN_MS = 600;

function clearHover() {
    if (hoverTimer) clearTimeout(hoverTimer);
    hoverTimer = null;
    hoverKey = null;
}

function forward(name, ...forwardedArguments) {
    return askPage(`notes:${name}`, { args: forwardedArguments });
}

/**
 * Takes everything, a space or a folder away, as a zip.
 *
 * The page answers when it is the notes page; elsewhere (reading a note) the
 * panel downloads by itself, otherwise the menu entry did nothing.
 */
function exportArchive(kind = null, id = null) {
    const handled = "folder" === kind ? forward("export-folder", id) : forward("export", id ?? undefined);

    if (handled) return;

    const query = "folder" === kind ? `?folderId=${id}` : null != id ? `?spaceId=${id}` : "";
    window.location.assign(`${EXPORT_URL}${query}`);
}

/**
 * A click opens: a folder in the library, a note in the editor.
 *
 * The page answers both; if nobody listens, the row's link already has the
 * address and the browser goes there.
 */
function onSelect(node) {
    selectedKey.value = node.key;

    if ("folder" === node.kind) {
        // The root has no id, and `Number(null)` is zero: so the panel
        // asked for folder 0, which the library showed empty and whose
        // address returned a 404.
        // Nobody listening: the reader is elsewhere in the module, and the
        // row, which cancelled its link to let the page act, navigates.
        if (!forward("open-folder", null === node.id ? null : Number(node.id))) {
            window.location.assign(null === node.id ? LIBRARY_URL : hrefFor(node));
        }

        // A folder being opened expands too: one comes to see what it holds,
        // and the arrow was just one more detour.
        open(node.id);

        return;
    }

    if (!forward("select", Number(node.id))) window.location.assign(hrefFor(node));
}

/**
 * Add to favourites, or remove, from the row.
 *
 * The page does it when it is there, so that the library and the editor
 * follow. Otherwise the panel does it alone and corrects its own list.
 */
async function toggleFavorite(node) {
    const kind = "folder" === node.kind ? "folder" : "note";
    const id = Number(node.id);

    if (forward("favorite", { kind, id })) return;

    const url = "folder" === kind
        ? `${FOLDERS_ENDPOINT}/${id}/favorite`
        : `/suite/notes/markdown/${id}/favorite`;
    const payload = await request(url, {}, { method: HttpMethod.Post });

    if (undefined === payload?.favorite) return;

    const at = payload.favorite ? new Date().toISOString() : null;
    const patchOne = (list) =>
        (list ?? []).map((one) => (Number(one.id) === id ? { ...one, favoritedAt: at } : one));

    if ("folder" === kind) announcedFolders.value = patchOne(folders.value);
    else announcedNotes.value = patchOne(notes.value);
}

function onFavoriteClick(entry, event) {
    event.preventDefault();
    onSelect(entry);
}

/** What a drop on this row, at this height, would write. */
function planFor(node, event, dragged) {
    const zone = null === node.id
        ? "inside"
        : dropZone(event.currentTarget.getBoundingClientRect(), event.clientY, node.kind);

    const planned = planDrop({
        dragged,
        target: { kind: node.kind, id: node.id, spaceId: node.spaceId ?? null },
        zone,
        folders: folders.value,
        notes: notes.value,
    });

    // A space one cannot write in receives nothing: better to say it with
    // the cursor than with a refusal afterwards.
    const plan = planned && canWriteIn(planned.spaceId) ? planned : null;

    return { zone, plan };
}

/**
 * The drag starts here, so the clipboard is filled here.
 *
 * The page cannot do it for us: it receives the event once the drag has
 * started, and `setData` no longer has any effect at that point.
 */
function onDragStart(node, event) {
    draggingKey.value = node.key;
    startNoteDrag(event, node.kind, node.id);
}

function onDragEnd() {
    draggingKey.value = null;
    dropHint.value = null;
    clearHover();
}

function onDragOver(node, event) {
    const dragged = peekNoteDrag(event);
    if (!dragged) return;

    event.stopPropagation();

    const { zone, plan } = planFor(node, event, dragged);

    // An impossible drop - a folder into its own child, a row onto itself -
    // lights nothing and shows the not-allowed cursor: better to know before
    // letting go than after.
    if (!plan) {
        if (event.dataTransfer) event.dataTransfer.dropEffect = "none";
        dropHint.value = null;
        clearHover();

        return;
    }

    event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = "move";

    dropHint.value = { key: node.key, zone };

    // Waiting on a closed folder opens it: one goes down the tree without
    // letting go of what one holds.
    const closed = "folder" === node.kind && null !== node.id && !expanded.value.has(Number(node.id));

    if ("inside" === zone && closed && (node.children ?? []).length) {
        if (hoverKey !== node.key) {
            clearHover();
            hoverKey = node.key;
            hoverTimer = setTimeout(() => {
                open(node.id);
                clearHover();
            }, HOVER_OPEN_MS);
        }
    } else {
        clearHover();
    }
}

function onDragLeave(node, event) {
    const related = event.relatedTarget;
    if (related && event.currentTarget.contains(related)) return;
    if (dropHint.value?.key === node.key) dropHint.value = null;
    if (hoverKey === node.key) clearHover();
}

function onDrop(node, event) {
    const dragged = readNoteDrag(event) ?? peekNoteDrag(event);

    event.preventDefault();
    event.stopPropagation();

    const { plan } = dragged ? planFor(node, event, dragged) : { plan: null };

    draggingKey.value = null;
    dropHint.value = null;
    clearHover();

    if (plan) forward("move", plan);
}

/** The root is a target like any other: what is dropped goes back up there. */
const rootNode = { kind: "folder", id: null, key: "root" };

// ── The keyboard ───────────────────────────────────────────────────

/**
 * Walk the tree without the mouse, as in a file explorer.
 *
 * Up and down move from one visible row to the next, right expands then
 * goes down, left folds then goes up to the parent folder, Enter opens, F2
 * renames. Only one row at a time has the focus: the tree counts as one tab
 * stop, not a hundred.
 */
const treeRef = ref(null);

function rows() {
    return [...(treeRef.value?.querySelectorAll("[data-tree-row]") ?? [])];
}

function nodeByKey(key, list = tree.value) {
    for (const node of list) {
        if (node.key === key) return node;

        const found = nodeByKey(key, node.children ?? []);
        if (found) return found;
    }

    return null;
}

function focusRow(element) {
    element?.focus();
    element?.scrollIntoView?.({ block: "nearest" });
}

function onTreeFocus(event) {
    if (event.target !== treeRef.value) return;

    const all = rows();
    const selected = all.find((row) => row.dataset.treeKey === selectedKey.value);

    focusRow(selected ?? all[0]);
}

function onTreeKeydown(event) {
    // Only when the row itself has the focus: a button of the row - its
    // three dots - keeps Enter for itself, otherwise it opened the row
    // instead of its menu.
    const current = event.target;
    if (!current?.matches?.("[data-tree-row]")) return;

    const all = rows();
    const index = all.indexOf(current);
    const node = nodeByKey(current.dataset.treeKey);

    if (!node) return;

    const isFolder = "folder" === node.kind;
    const isOpen = isFolder && expanded.value.has(Number(node.id));
    const hasChildren = (node.children ?? []).length > 0;

    const handled = {
        ArrowDown: () => focusRow(all[index + 1]),
        ArrowUp: () => focusRow(all[index - 1]),
        Home: () => focusRow(all[0]),
        End: () => focusRow(all[all.length - 1]),
        ArrowRight: () => {
            if (isFolder && hasChildren && !isOpen) toggle(node);
            else if (isOpen) focusRow(all[index + 1]);
        },
        ArrowLeft: () => {
            if (isOpen && !searching.value) {
                toggle(node);

                return;
            }

            const parent = all.find((row) => row.dataset.treeKey === current.dataset.parentKey);
            focusRow(parent);
        },
        Enter: () => onSelect(node),
        F2: () => forward(isFolder ? "rename-folder" : "rename-note", node),
    }[event.key];

    if (!handled) return;

    event.preventDefault();
    handled();
}

const stopListening = [];

onMounted(() => {
    window.addEventListener("keydown", onShortcut);
    stopListening.push(() => window.removeEventListener("keydown", onShortcut));

    stopListening.push(
        onPageNotice("notes:changed", (detail) => {
            if (Array.isArray(detail?.notes)) announcedNotes.value = detail.notes;
            if (Array.isArray(detail?.folders)) {
                announcedFolders.value = detail.folders;
            }
            if (Array.isArray(detail?.spaces)) announcedSpaces.value = detail.spaces;
            if ("canCreateSpace" in (detail ?? {})) canCreateSpace.value = Boolean(detail.canCreateSpace);
            if ("craftEnabled" in (detail ?? {})) craftEnabled.value = Boolean(detail.craftEnabled);

            // The page says what it shows: a folder, a note, or the root.
            // The matching row lights up, and its folder opens so that it is
            // visible.
            if (detail?.noteId) {
                selectedKey.value = `note:${detail.noteId}`;
                revealNote(detail.noteId);

                return;
            }

            if ("folderId" in (detail ?? {})) {
                selectedKey.value = detail.folderId
                    ? `folder:${detail.folderId}`
                    : null;
            }
        }),
    );
});

onUnmounted(() => {
    while (stopListening.length) stopListening.pop()();
    clearHover();
});
</script>

<template>
    <AppModulePanel
        :title="t('notes.markdown.title')"
        :loading="loading"
        :failed="failed"
    >
        <template #action>
            <!-- Read the notebook, in one click: the uncluttered reading space. -->
            <AppIconButton
                data-read-mode-toggle
                :title="`${t('notes.markdown.read.mode')} (Alt+R)`"
                :disabled="null === readTargetId"
                v-on:click="openReader"
            >
                <BookOpen class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
            <AppIconButton
                v-if="anyOpen"
                :title="t('notes.markdown.collapse_all')"
                v-on:click="collapseAll"
            >
                <ChevronsDownUp class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
            <AppIconButton
                :title="t('notes.markdown.import.button')"
                v-on:click="forward('import')"
            >
                <Upload class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
            <AppIconButton
                :title="t('notes.markdown.export.all')"
                v-on:click="exportArchive()"
            >
                <Download class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
            <!-- A single plus, which asks what: a note or a folder.
                     Two buttons side by side forced one to guess which was
                     which from the shape of their icon alone. -->
            <AppIconButton
                :title="t('notes.markdown.add.title')"
                v-on:click="forward('add', null)"
            >
                <Plus class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
        </template>

        <!-- No horizontal indent: the tree rows carry their own inside and
             take the whole width of the panel. -->
        <div class="pb-1">
            <AppSearchInput
                v-model="treeQuery"
                :placeholder="t('notes.markdown.search_placeholder')"
            />
        </div>

        <!-- Favourites, before the tree: what one comes for every day does
             not have to be found in a tree. -->
        <div v-if="favorites.length" class="mb-2 border-b border-line pb-2">
            <p class="px-3 py-1 text-xs font-semibold uppercase tracking-wide text-muted">
                {{ t('notes.markdown.library.favorites') }}
            </p>

            <a
                v-for="entry in favorites"
                :key="entry.key"
                :data-favorite-row="entry.key"
                :href="hrefFor(entry)"
                class="flex min-w-0 items-center gap-2 rounded-lg px-3 py-2 text-sm text-primary no-underline transition-colors hover:bg-surface-2"
                v-on:click="onFavoriteClick(entry, $event)"
            >
                <component
                    :is="'folder' === entry.kind ? Folder : FileText"
                    class="h-4 w-4 shrink-0 text-muted"
                    :stroke-width="2"
                />
                <span class="min-w-0 flex-1 truncate">{{ labelOf(entry) }}</span>
            </a>
        </div>

        <!-- The root is a row like the others: it is where one lands back,
             and a tree without its top forces one to guess how to get back
             to it. -->
        <a
            :href="LIBRARY_URL"
            data-root-row
            class="group mb-0.5 flex min-w-0 items-center gap-2 rounded-md border px-2 py-1.5 text-sm no-underline transition-colors"
            :class="'root' === dropHint?.key
                ? 'border-accent-600/40 bg-accent-600/15 text-accent-400 ring-2 ring-accent-500'
                : null === selectedKey ? 'border-transparent bg-surface-2 font-medium text-primary' : 'border-transparent text-primary hover:bg-surface-2'"
            v-on:click.prevent="onSelect({ kind: 'folder', id: null, key: null })"
            v-on:dragover="onDragOver(rootNode, $event)"
            v-on:dragleave="onDragLeave(rootNode, $event)"
            v-on:drop="onDrop(rootNode, $event)"
        >
            <FileText class="h-4 w-4 shrink-0" :stroke-width="2" />
            <span class="flex-1 truncate">{{ t('notes.markdown.library.title') }}</span>
        </a>

        <p v-if="isEmpty && !searching" class="px-3 py-1 text-xs text-muted">
            {{ t("notes.markdown.folders.tree_empty") }}
        </p>

        <p v-else-if="searching && !tree.length" class="px-3 py-1 text-xs text-muted">
            {{ t("notes.markdown.search_no_results") }}
        </p>

        <!-- The tree counts as a single tab stop: one enters it, then the
             arrows do the rest. -->
        <div
            ref="treeRef"
            role="tree"
            tabindex="0"
            class="space-y-0.5 outline-none"
            :aria-label="t('notes.markdown.title')"
            v-on:focus="onTreeFocus"
            v-on:keydown="onTreeKeydown"
        >
            <template v-for="group in spaceGroups" :key="group.space ? `space:${group.space.id}` : 'all'">
                <!-- A space header: its name, whether it is read-only, and
                     what can be done there. Dropping something on it files
                     it at its root. -->
                <div
                    v-if="showSpaceHeaders && group.space"
                    :data-space-header="group.space.id"
                    class="group/space mt-2 flex min-w-0 items-center gap-1 rounded-md border px-1 py-1 first:mt-0"
                    :class="`space:${group.space.id}` === dropHint?.key
                        ? 'border-accent-600/40 bg-accent-600/15 ring-2 ring-accent-500'
                        : 'border-transparent'"
                    v-on:dragover="onDragOver(spaceRoot(group.space), $event)"
                    v-on:dragleave="onDragLeave(spaceRoot(group.space), $event)"
                    v-on:drop="onDrop(spaceRoot(group.space), $event)"
                >
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 items-center gap-1.5 rounded px-1 text-left text-xs font-semibold uppercase tracking-wide text-muted transition-colors hover:text-primary"
                        :aria-expanded="isSpaceOpen(group.space)"
                        v-on:click="toggleSpace(group.space)"
                    >
                        <ChevronDown v-if="isSpaceOpen(group.space)" class="h-3 w-3 shrink-0" :stroke-width="2" />
                        <ChevronRight v-else class="h-3 w-3 shrink-0" :stroke-width="2" />
                        <component
                            :is="group.space.personal ? User : Users"
                            class="h-3.5 w-3.5 shrink-0"
                            :style="group.space.color ? { color: group.space.color } : null"
                            :stroke-width="2"
                        />
                        <span class="min-w-0 truncate">{{ spaceLabel(group.space, t) }}</span>
                    </button>
                    <Globe
                        v-if="group.space.published"
                        data-space-published
                        class="h-3.5 w-3.5 shrink-0 text-accent-400"
                        :stroke-width="2"
                        :aria-label="t('notes.markdown.spaces.publication.badge')"
                    >
                        <title>{{ t('notes.markdown.spaces.publication.badge') }}</title>
                    </Globe>
                    <!-- Set from Studio: the badge says so, and the settings
                         do not open from here. -->
                    <span
                        v-if="group.space.managed"
                        data-space-managed
                        class="shrink-0 rounded-full bg-surface-2 px-1.5 py-0.5 text-[10px] font-medium text-muted"
                        :title="t('notes.markdown.spaces.managed_hint')"
                    >{{ t('notes.markdown.spaces.managed_badge') }}</span>
                    <span
                        v-if="!group.space.canWrite"
                        data-space-readonly
                        class="shrink-0 rounded-full bg-surface-2 px-1.5 py-0.5 text-[10px] font-medium text-muted"
                    >{{ t('notes.markdown.spaces.read_only') }}</span>
                    <AppIconButton
                        v-if="group.space.canWrite"
                        class="shrink-0 sm:opacity-0 sm:group-hover/space:opacity-100"
                        :title="t('notes.markdown.spaces.add_here', { name: spaceLabel(group.space, t) })"
                        :data-space-add="group.space.id"
                        v-on:click="addInSpace(group.space)"
                    >
                        <Plus class="h-3.5 w-3.5" :stroke-width="2" />
                    </AppIconButton>
                    <AppIconButton
                        v-if="group.space.canManage && !group.space.managed"
                        class="shrink-0 sm:opacity-0 sm:group-hover/space:opacity-100"
                        :title="t('notes.markdown.spaces.settings')"
                        :data-space-settings="group.space.id"
                        v-on:click="forward('space-settings', Number(group.space.id))"
                    >
                        <Settings2 class="h-3.5 w-3.5" :stroke-width="2" />
                    </AppIconButton>
                    <!-- Take a single space away, or pour files into it: the
                         two gestures of the panel's bar, limited to it. -->
                    <AppRowActions
                        class="shrink-0 sm:opacity-0 sm:group-hover/space:opacity-100"
                        size="sm"
                        :data-space-menu="group.space.id"
                        :actions="spaceActions(group.space)"
                        :label="spaceLabel(group.space, t)"
                    />
                </div>

                <template v-if="!showSpaceHeaders || isSpaceOpen(group.space)">
                    <NoteTreeItem
                        v-for="node in group.nodes"
                        :key="node.key"
                        :node="node"
                        :selected-key="selectedKey"
                        :expanded="expanded"
                        :draggable="group.space ? group.space.canWrite : true"
                        :editable="group.space ? group.space.canWrite : true"
                        exportable
                        :dragging-key="draggingKey"
                        :drop-hint="dropHint"
                        :href-for="hrefFor"
                        v-on:select="onSelect"
                        v-on:toggle="toggle"
                        v-on:add="(node) => { open(node.id); forward('add', Number(node.id)); }"
                        v-on:rename="(node) => forward('folder' === node.kind ? 'rename-folder' : 'rename-note', node)"
                        v-on:favorite="toggleFavorite"
                        v-on:delete="(node) => forward('folder' === node.kind ? 'delete-folder' : 'delete', node)"
                        v-on:export="(node) => exportArchive('folder', Number(node.id))"
                        v-on:drag-start="onDragStart"
                        v-on:drag-end="onDragEnd"
                        v-on:drag-over="onDragOver"
                        v-on:drag-leave="onDragLeave"
                        v-on:drop="onDrop"
                    />
                    <p
                        v-if="showSpaceHeaders && group.space && !group.nodes.length && !searching"
                        class="px-3 py-1 text-xs text-muted"
                    >
                        {{ t('notes.markdown.spaces.empty') }}
                    </p>
                </template>
            </template>
        </div>
        <!-- Tags, below the tree: they cut across the filing, so they cannot
             hold a place in it. Clicking one shows its notes, wherever they
             are. -->
        <div v-if="allTags.length && !searching" class="mt-2 border-t border-line pt-2">
            <button
                type="button"
                class="flex w-full items-center gap-1 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-muted transition-colors hover:text-primary"
                v-on:click="toggleTagsSection"
            >
                <ChevronDown v-if="tagsOpen" class="h-3 w-3 shrink-0" :stroke-width="2" />
                <ChevronRight v-else class="h-3 w-3 shrink-0" :stroke-width="2" />
                {{ t('notes.markdown.library.tag.section') }}
            </button>

            <template v-if="tagsOpen">
                <div
                    v-for="one in visibleTags"
                    :key="one.name"
                    class="group flex min-w-0 items-center gap-2 rounded-lg px-3 py-1.5 text-sm text-primary transition-colors hover:bg-surface-2"
                >
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 items-center gap-2 text-left"
                        :title="t('notes.markdown.library.tag.filter', { tag: one.name })"
                        v-on:click="forward('filter-tag', one.name)"
                    >
                        <Tag class="h-3.5 w-3.5 shrink-0 text-muted" :stroke-width="2" />
                        <span class="min-w-0 flex-1 truncate">{{ one.name }}</span>
                        <span class="shrink-0 text-xs text-muted tabular-nums">{{ one.count }}</span>
                    </button>

                    <AppIconButton
                        class="shrink-0 sm:opacity-0 sm:group-hover:opacity-100"
                        :class="one.pinned ? 'sm:opacity-100' : ''"
                        :title="one.pinned ? t('notes.markdown.library.tag.unpin') : t('notes.markdown.library.tag.pin')"
                        v-on:click.stop="togglePinned(one.name)"
                    >
                        <PinOff v-if="one.pinned" class="h-3 w-3" :stroke-width="2" />
                        <Pin v-else class="h-3 w-3" :stroke-width="2" />
                    </AppIconButton>
                </div>

                <button
                    v-if="hiddenTagCount"
                    type="button"
                    class="px-3 py-1 text-xs text-muted transition-colors hover:text-primary"
                    v-on:click="showAllTags = true"
                >
                    {{ t('notes.markdown.library.tag.show_all', { count: hiddenTagCount }) }}
                </button>
            </template>
        </div>
    </AppModulePanel>
</template>
