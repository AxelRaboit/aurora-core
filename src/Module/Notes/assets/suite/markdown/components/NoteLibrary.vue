<script setup>
/**
 * The notebook, seen from outside.
 *
 * Before it, the module had no screen to look at what it contained: the
 * address returned the first note and the tree lived in the menu. This page
 * is the counterpart of the folder/note split - a place where one sees what
 * one has written, filed, and where one files.
 *
 * **Three ways of looking, a single source.** Mosaic, cards and list draw
 * the same sorted list; sort and display are reading preferences, kept in
 * the browser, not states of the notebook.
 *
 * **Filing happens here.** A card is dragged onto a folder or onto a link of
 * the breadcrumb, and the "Déplacer vers" modal does the same thing by
 * keyboard and by finger, because drag and drop does not exist on a phone.
 */
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import {
    ArrowDown,
    ArrowDownWideNarrow,
    ArrowUp,
    ArrowUpDown,
    ArrowUpNarrowWide,
    CalendarDays,
    ListChecks,
    ChevronRight,
    Download,
    FileDown,
    CheckSquare,
    FileText,
    Folder,
    FolderInput,
    FolderPlus,
    FolderTree,
    Layers,
    LayoutGrid,
    List,
    Pencil,
    Pin,
    PinOff,
    Plus,
    Rows3,
    Table2,
    Search,
    Tag,
    Lock,
    Users,
    UsersRound,
    Trash2,
    X,
} from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppColorPicker from "@/shared/components/form/picker/AppColorPicker.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppMultiselect from "@/shared/components/form/select/AppMultiselect.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import AppLoadMore from "@/shared/components/nav/AppLoadMore.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppSelectionCheck from "@/shared/components/feedback/AppSelectionCheck.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { useNoteLibrary } from "@notes/suite/markdown/composables/useNoteLibrary.js";
import { compareSiblings } from "@notes/suite/markdown/composables/noteSiblingOrder.js";
import { useFoldable } from "@notes/suite/markdown/composables/useFoldable.js";
import { useNotePreview } from "@notes/suite/markdown/composables/useNotePreview.js";
import { useMarkdownRenderer } from "@notes/suite/markdown/composables/useMarkdownRenderer.js";
import { withoutLeadingTitle } from "@notes/suite/markdown/composables/noteBody.js";
import NotePreview from "@notes/suite/markdown/components/NotePreview.vue";
import NoteJournalCalendar from "@notes/suite/markdown/components/NoteJournalCalendar.vue";
import NoteTableView from "@notes/suite/markdown/components/NoteTableView.vue";
import { useDismissable } from "@notes/suite/markdown/composables/useDismissable.js";
import {
    NOTE_DRAG_MIME,
    readNoteDrag,
    startNoteDrag,
} from "@notes/suite/markdown/composables/noteDrag.js";

const props = defineProps({
    /** Every folder of the reader, flat, `{id, parentId, name, noteCount, …}`. */
    folders: { type: Array, default: () => [] },
    /** Every note of the reader, flat and without its body. */
    notes: { type: Array, default: () => [] },
    /** The reader's own space: anything elsewhere is shared with others. */
    personalSpaceId: { type: Number, default: null },
    /** {@see useNoteFoldersApi} */
    foldersApi: { type: Object, required: true },
    /** {@see useMarkdownNotesApi} */
    notesApi: { type: Object, required: true },
    /** The folder the address names, null at the root. */
    initialFolderId: { type: Number, default: null },
    /** The chain the server resolved, so the first paint is not the root. */
    breadcrumb: { type: Array, default: () => [] },
    rootUrl: { type: String, required: true },
    /** Builds a note's own address, for a link that middle-click can open. */
    noteUrlFor: { type: Function, required: true },
    /** Builds the address that downloads one note as Markdown. */
    noteExportUrlFor: { type: Function, default: () => "" },
    /** Builds the zip address: `{ folderId }` for a folder, nothing for everything. */
    exportUrlFor: { type: Function, default: null },
    /** What the server accepts as depth, to say it on refusal. */
    maxDepth: { type: Number, default: 8 },
    /** The page knows where today's note is: the button shows. */
    dailyEnabled: { type: Boolean, default: false },
    /** `(month: 'YYYY-MM') => Promise<string[]>`, for the journal's calendar. */
    loadJournalDays: { type: Function, default: () => Promise.resolve([]) },
    /** Today's note is on its way: the button spins and waits. */
    dailyOpening: { type: Boolean, default: false },
    /** Shows the button of the tasks view. */
    tasksEnabled: { type: Boolean, default: false },
    /** list<{id, name}>, to name a « person » property in the table. */
    people: { type: Array, default: () => [] },
});

const emit = defineEmits([
    "open-note",
    "create-note",
    "open-daily-note",
    "open-tasks",
    "changed",
    "folder-changed",
]);

const { t } = useI18n();
const { formatDateTime } = useDateFormat();

const journalOpen = ref(false);
const journalRef = ref(null);
useDismissable(journalRef, journalOpen);

function openJournalDay(date) {
    journalOpen.value = false;
    emit("open-daily-note", date);
}

const foldersRef = computed(() => props.folders);
const notesRef = computed(() => props.notes);

const library = useNoteLibrary({
    folders: foldersRef,
    notes: notesRef,
    initialFolderId: props.initialFolderId,
    breadcrumb: props.breadcrumb,
    urlFor: props.foldersApi.urlFor,
    rootUrl: props.rootUrl,
    personalSpaceId: props.personalSpaceId,
});

const {
    currentFolderId,
    path,
    view,
    sort,
    direction,
    flat,
    tag: activeTag,
    visibility,
    isShared,
    folders: visibleFolders,
    notes: visibleNotes,
    isEmpty,
    openFolder,
    onPopState,
    setView,
    setSort,
    toggleDirection,
    toggleFlat,
    setTag,
    cycleVisibility,
    folderNameOf,
} = library;

onMounted(() => window.addEventListener("popstate", onPopState));
onUnmounted(() => window.removeEventListener("popstate", onPopState));

// The page follows the open folder: that is where an import must land, and
// it is what the menu panel highlights. Without this, importing after
// changing folder dropped the files into the one we had started from, that
// is the one the server had rendered.
watch(currentFolderId, (id) => emit("folder-changed", id), { immediate: true });

const query = ref("");

/**
 * The search folds into a magnifier, as in Craft.
 *
 * An empty field taking up a third of the bar costs that space to everything
 * else, and nobody searches all the time. The icon opens it, the slash key
 * too, Escape closes it - but only if it is empty: an active and invisible
 * filter would make one wonder why the list is short.
 */
const {
    open: searchOpen,
    box: searchBox,
    reveal: openSearch,
    fold: foldSearch,
} = useFoldable();

function closeSearch() {
    // As long as there is something in the field, it stays open: an active
    // and invisible filter would make one wonder why the list is short.
    if ("" !== query.value.trim()) return;

    foldSearch();
}

/**
 * The search filters what is in view, not the whole notebook.
 *
 * Searching the whole notebook is the job of the menu panel, which has the
 * field for it and brings back results from the whole tree. Here we filter
 * the open folder, which is what one expects from a file explorer.
 */
function matches(label) {
    const needle = query.value.trim().toLowerCase();

    return "" === needle || String(label ?? "").toLowerCase().includes(needle);
}

const shownFolders = computed(() =>
    visibleFolders.value.filter((folder) => matches(folder.name)),
);

const shownNotes = computed(() =>
    visibleNotes.value.filter((note) => matches(note.title)),
);

const nothingShown = computed(
    () => 0 === shownFolders.value.length && 0 === shownNotes.value.length,
);

/**
 * What is drawn at once, and what waits.
 *
 * The whole notebook is already in the page - sorting happens here, since
 * encrypted columns cannot be sorted in SQL - but drawing a thousand cards
 * at once freezes the screen for nothing: nobody ever reads a thousand. The
 * rest comes on demand, and the counter starts over as soon as one changes
 * folder or types something else.
 */
const PAGE = 60;
const shown = ref(PAGE);

watch([currentFolderId, query, sort, direction, flat, activeTag, visibility], () => {
    shown.value = PAGE;
});

const pagedFolders = computed(() => shownFolders.value.slice(0, shown.value));

const pagedNotes = computed(() =>
    shownNotes.value.slice(0, Math.max(0, shown.value - pagedFolders.value.length)),
);

const hasMore = computed(
    () => shownFolders.value.length + shownNotes.value.length > shown.value,
);

/**
 * The last notes touched, at the top of the notebook.
 *
 * Craft opens on them, and it is the question asked nine times out of ten
 * on arrival: "where was I". Only at the root, and only without a search: in
 * a folder, what one is looking for is the folder's content.
 */
const recent = computed(() => {
    if (
        null !== currentFolderId.value ||
        null !== activeTag.value ||
        // A filter applies to the whole screen: the recent row still showed
        // what the filter had just excluded, which makes one doubt the
        // filter rather than the row.
        "all" !== visibility.value ||
        "" !== query.value.trim()
    ) {
        return [];
    }

    return [...props.notes]
        .sort((left, right) => Date.parse(right.updatedAt ?? 0) - Date.parse(left.updatedAt ?? 0))
        .slice(0, 4);
});

const viewOptions = computed(() => [
    { value: "mosaic", icon: LayoutGrid, label: t("notes.markdown.library.view.mosaic") },
    { value: "cards", icon: Rows3, label: t("notes.markdown.library.view.cards") },
    { value: "list", icon: List, label: t("notes.markdown.library.view.list") },
    { value: "table", icon: Table2, label: t("notes.markdown.library.view.table") },
]);

/**
 * The sort folds like the search, but not on the same signal.
 *
 * Its panel is teleported out of the button: a `focusout` set there would
 * close it at the very moment an option is clicked, and the click would
 * never arrive. So the selector itself says when it closes - whether a
 * choice was made, Escape pressed, or a click landed elsewhere - and the
 * control folds with it. The icon's tooltip says which criterion is in
 * force: a folded sort whose value is unknown would be worse than a sort
 * that takes up space.
 *
 * That is also why the keyboard goes to the selector itself and not to a
 * field: without a search inside, there is no `input`, and the control's
 * root carries the focus. It opens the list on receiving it, so a click on
 * the icon unrolls the criteria instead of showing a closed box the reader
 * would have to click a second time.
 */
const {
    open: sortOpen,
    box: sortBox,
    reveal: openSort,
    fold: foldSort,
} = useFoldable();

function chooseSort(value) {
    setSort(value);
    foldSort();
}

const sortOptions = computed(() =>
    [
        { value: "name", label: t("notes.markdown.library.sort.name") },
        { value: "updated", label: t("notes.markdown.library.sort.updated") },
        { value: "created", label: t("notes.markdown.library.sort.created") },
        // Manual order is filed within a folder; flattened, two notes from
        // two folders have no common position to compare.
        ...(flat.value
            ? []
            : [{ value: "manual", label: t("notes.markdown.library.sort.manual") }]),
    ],
);

/** The visibility filter's state, written out, for the tooltip and the chip. */
const visibilityLabel = computed(() =>
    t(`notes.markdown.library.visibility.${visibility.value}`),
);

/** The criterion in force, written out, so that the icon can say it. */
const sortLabel = computed(
    () => sortOptions.value.find((one) => one.value === sort.value)?.label ?? "",
);

/**
 * A folder's colour, if it carries one.
 *
 * Set as a style rather than a class: it is a free value, chosen by the
 * reader, and Tailwind only generates the classes it sees written.
 *
 * Without one, the icon stays grey, as in the tree: the theme's accent read
 * as a colour the reader had never chosen (seen on 07/10/2026).
 */
function folderTint(folder) {
    return folder.color ? { color: folder.color } : null;
}

/**
 * A note's thumbnail: its beginning, rendered small.
 *
 * **The rendering is what makes a note recognisable**, not the text. A
 * heading, a list, a ticked box are spotted at a glance, where the same
 * beginning flattened into a sentence made every card identical. That is
 * what Craft does, and what Axel asked for on 23/09 when showing his wall of
 * cards.
 *
 * Reading comfort is not the point here: one is looking for "ah yes, that
 * one", so the text is small and the thumbnail is cut at the bottom.
 *
 * Kept in memory per note and per modification date: the grid is redrawn on
 * every sort, every filter, every extra page, and parsing sixty excerpts
 * each time for an identical result would be paid on every click.
 */
const { render: renderMarkdown } = useMarkdownRenderer();
const thumbnails = new Map();

function thumbnail(note) {
    if (!note.excerpt) return "";

    const key = `${note.id}:${note.updatedAt}`;
    const known = thumbnails.get(key);

    if (undefined !== known) return known;

    // The title is already written above the thumbnail: leaving it at the
    // top of the rendering would say it twice, and eat the first line of
    // what one is trying to recognise.
    const html = renderMarkdown(withoutLeadingTitle(note.excerpt, note.title));
    thumbnails.set(key, html);

    return html;
}

function folderLabel(folder) {
    return folder.name || t("notes.markdown.folders.untitled");
}

/**
 * Where a note lives, said on its card - only when the list is flattened.
 *
 * Filed, the answer is the folder just opened, and repeating it on every
 * card would be noise. Flattened, it is the missing information: one sees
 * everything, and no longer knows where it comes from.
 */
function noteFolderLabel(note) {
    if (!flat.value && null === activeTag.value) return null;

    const name = folderNameOf(note.folderId);

    return null === name
        ? null
        : name || t("notes.markdown.folders.untitled");
}

function noteLabel(note) {
    return note.title || t("notes.markdown.untitled");
}

// ── Choose several things at once ──────────────────────────────────

/**
 * The selection, and the two gestures it serves.
 *
 * Tidying a notebook is rarely moving one note: it is moving twelve. A box on
 * each card, a bar that says how many, and the two actions that were worth
 * grouping - move and delete. The rest (rename, export) makes no sense in
 * the plural.
 *
 * The keys carry the kind with the id: a note 3 and a folder 3 are not the
 * same thing, and a plain id would have mixed them up.
 */
const selected = ref(new Set());

const keyOf = (kind, item) => `${kind}:${item.id}`;

const selectionCount = computed(() => selected.value.size);

function isSelected(kind, item) {
    return selected.value.has(keyOf(kind, item));
}

function toggleSelection(kind, item) {
    const key = keyOf(kind, item);
    const next = new Set(selected.value);

    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }

    selected.value = next;
}

function clearSelection() {
    selected.value = new Set();
}

/**
 * Selection mode, as in the media library.
 *
 * The circles only show once the mode is opened by its button: on every
 * card all the time, they cluttered the library for a rare gesture. In this
 * mode, clicking a card ticks it instead of opening it. Leaving the mode
 * clears the selection.
 */
const selecting = ref(false);

function startSelecting() {
    selecting.value = true;
}

function stopSelecting() {
    selecting.value = false;
    clearSelection();
}

function toggleSelecting() {
    if (selecting.value) {
        stopSelecting();
    } else {
        startSelecting();
    }
}

// Changing folder clears the selection and closes the mode: what it holds
// is no longer on screen, and acting on it from afar is the best way to move
// what one was not looking at.
watch(currentFolderId, stopSelecting);

/** The chosen items, resolved back to their kind and their object. */
function selectedItems() {
    const items = [];

    for (const key of selected.value) {
        const [kind, rawId] = key.split(":");
        const id = Number(rawId);
        const source = "folder" === kind ? props.folders : props.notes;
        const item = source.find((one) => Number(one.id) === id);

        if (item) items.push({ kind, item });
    }

    return items;
}

async function moveSelection(targetFolderId) {
    const items = selectedItems();
    let refused = 0;

    // In series rather than in parallel: each move is a write, and the
    // server refuses a folder filed into its own branch - a burst would make
    // the order of the refusals unpredictable.
    for (const { kind, item } of items) {
        if ("folder" === kind && Number(item.id) === targetFolderId) continue;

        const moved = await applyMove(kind, Number(item.id), targetFolderId, {
            quiet: true,
        });

        if (!moved) ++refused;
    }

    stopSelecting();
    emit("changed");

    // One message per refusal, for twelve items, is twelve messages stacked
    // over what one wanted to read: the end of the gesture is said once.
    if (refused) {
        toast.error(t("notes.markdown.library.some_refused", { count: refused }));

        return;
    }

    toast.success(t("notes.markdown.folders.moved"));
}

async function deleteSelection() {
    let failed = 0;

    for (const { kind, item } of selectedItems()) {
        const { ok } =
            "folder" === kind
                ? await props.foldersApi.remove(item.id)
                : await props.notesApi.remove(item.id);

        if (!ok) ++failed;
    }

    stopSelecting();
    emit("changed");

    if (failed) {
        toast.error(t("notes.markdown.library.some_refused", { count: failed }));
    }
}

// ── Create, rename ─────────────────────────────────────────────────
const nameModal = ref(null);
const nameValue = ref("");
const nameColor = ref(null);
const nameSaving = ref(false);

function askForFolderName(folder = null, parentId = undefined) {
    nameModal.value = folder ?? {
        id: null,
        parentId: undefined === parentId ? currentFolderId.value : parentId,
    };
    nameValue.value = folder?.name ?? "";
    nameColor.value = folder?.color ?? null;
}

async function submitName() {
    if (!nameModal.value) return;

    nameSaving.value = true;

    const { id } = nameModal.value;
    const { ok, reported } = null === id
        ? await props.foldersApi.create(
            nameValue.value,
            nameModal.value.parentId ?? null,
            nameColor.value,
        )
        : await props.foldersApi.rename(
            id,
            nameValue.value,
            nameModal.value.parentId ?? null,
            nameColor.value,
        );

    nameSaving.value = false;

    if (!ok) {
        if (!reported) {
            const failed = null === id
                ? "notes.markdown.folders.errors.create_failed"
                : "notes.markdown.folders.errors.rename_failed";

            toast.error(t(failed));
        }

        return;
    }

    const done = null === id
        ? "notes.markdown.folders.created"
        : "notes.markdown.folders.renamed";

    toast.success(t(done));
    nameModal.value = null;
    emit("changed");
}

// ── Delete ─────────────────────────────────────────────────────────

/**
 * A note can be deleted from here too.
 *
 * It could only be deleted from the editor, which forced one to open a note
 * to get rid of it - and to read first what one wanted to throw away. Both
 * kinds go through the same confirmation, with the right word: a folder
 * takes what it holds with it, a note leaves alone.
 */
const pendingDelete = ref(null);
const deleting = ref(false);

function askToDelete(kind, item) {
    pendingDelete.value = { kind, item };
}

async function confirmDelete() {
    if (!pendingDelete.value) return;

    const { kind, item } = pendingDelete.value;

    if ("selection" === kind) {
        deleting.value = true;
        await deleteSelection();
        deleting.value = false;
        pendingDelete.value = null;

        return;
    }

    deleting.value = true;
    const { ok, reported } =
        "folder" === kind
            ? await props.foldersApi.remove(item.id)
            : await props.notesApi.remove(item.id);
    deleting.value = false;

    const isFolder = "folder" === kind;

    if (!ok) {
        if (!reported) {
            // The key is chosen before the call: `t()` is read by a test that
            // collects the file's keys, and a ternary inside its parentheses
            // makes it collect "folder".
            const failed = isFolder
                ? "notes.markdown.folders.errors.delete_failed"
                : "notes.markdown.errors.delete_failed";

            toast.error(t(failed));
        }

        return;
    }

    const done = isFolder
        ? "notes.markdown.folders.deleted"
        : "notes.markdown.saved";

    toast.success(t(done));
    pendingDelete.value = null;
    emit("changed");
}

// ── Move ───────────────────────────────────────────────────────────
const moving = ref(null);

/**
 * The possible destinations for what is being moved.
 *
 * A folder cannot be filed into itself nor into its own branch: the server
 * refuses it, and offering it in a list only to refuse it afterwards would
 * be a door painted on a wall.
 */
const moveTargets = computed(() => {
    if (!moving.value) return [];

    const excluded = new Set();

    if ("selection" === moving.value.kind) {
        for (const { kind, item } of selectedItems()) {
            if ("folder" === kind) excludeBranch(Number(item.id), excluded);
        }
    }

    if ("folder" === moving.value.kind) {
        excludeBranch(Number(moving.value.item.id), excluded);
    }

    return [
        { value: "", label: t("notes.markdown.folders.move_root") },
        ...props.folders
            .filter((folder) => !excluded.has(Number(folder.id)))
            .map((folder) => ({
                value: String(folder.id),
                label: pathLabel(folder),
            }))
            .sort((left, right) => left.label.localeCompare(right.label, undefined, { numeric: true })),
    ];
});

const moveTarget = ref("");

/**
 * A folder and everything hanging below it, marked as forbidden.
 *
 * The walk keeps a list of what it has already seen: the server refuses
 * cycles, but a row edited by hand would make one, and an unguarded loop
 * would freeze the tab rather than show an incomplete menu.
 */
function excludeBranch(rootId, excluded) {
    const queue = [rootId];

    while (queue.length) {
        const id = queue.shift();

        if (excluded.has(id)) continue;

        excluded.add(id);

        for (const folder of props.folders) {
            if (Number(folder.parentId) === id) queue.push(Number(folder.id));
        }
    }
}

/** A folder's full path, so that two namesakes can be told apart. */
function pathLabel(folder) {
    const names = [];
    const seen = new Set();
    let node = folder;

    while (node && !seen.has(Number(node.id))) {
        seen.add(Number(node.id));
        names.unshift(node.name || t("notes.markdown.folders.untitled"));
        node = props.folders.find((one) => Number(one.id) === Number(node.parentId)) ?? null;
    }

    return names.join(" / ");
}

function askToMove(kind, item = null) {
    moving.value = { kind, item };
    moveTarget.value = "";
}

async function submitMove() {
    if (!moving.value) return;

    const target = "" === moveTarget.value ? null : Number(moveTarget.value);

    if ("selection" === moving.value.kind) {
        await moveSelection(target);
    } else {
        await applyMove(moving.value.kind, Number(moving.value.item.id), target);
    }

    moving.value = null;
}

async function applyMove(kind, id, targetFolderId, { quiet = false } = {}) {
    const { ok, reported, payload } =
        "folder" === kind
            ? await props.foldersApi.move(id, targetFolderId)
            : await props.notesApi.move(id, targetFolderId);

    if (!ok) {
        // A group move counts its refusals and says it once; alone, it is
        // said right away.
        if (!reported && !quiet) {
            const failed =
                "refused" === payload?.error
                    ? "notes.markdown.folders.errors.move_refused"
                    : "notes.markdown.folders.errors.move_failed";

            toast.error(t(failed, { max: props.maxDepth }));
        }

        return false;
    }

    if (!quiet) {
        toast.success(t("notes.markdown.folders.moved"));
        emit("changed");
    }

    return true;
}

// ── Drag and drop ──────────────────────────────────────────────────
//
// The clipboard format is shared with the menu panel, from which one drags
// too: see `noteDrag.js`.
const dragOverId = ref(null);
const rootDragOver = ref(false);
const dragging = ref(null);

function onDragStart(kind, item, event) {
    if (!event.dataTransfer) return;

    dragging.value = { kind, id: Number(item.id) };
    startNoteDrag(event, kind, item.id);
}

function onDragEnd() {
    dragging.value = null;
    dragOverId.value = null;
    rootDragOver.value = false;
}

function acceptsDrop(event) {
    return Boolean(event.dataTransfer?.types.includes(NOTE_DRAG_MIME));
}

function onDragOverFolder(folder, event) {
    if (!acceptsDrop(event)) return;

    // A folder is not dropped onto itself: the server would refuse, and the
    // target must not light up for a gesture that cannot succeed.
    if (dragging.value?.kind === "folder" && dragging.value.id === Number(folder.id)) return;

    event.preventDefault();
    event.stopPropagation();
    event.dataTransfer.dropEffect = "move";
    dragOverId.value = `folder:${folder.id}`;
    rootDragOver.value = false;
}

function onDragLeaveFolder(folder, event) {
    const related = event.relatedTarget;
    if (related && event.currentTarget.contains(related)) return;
    if (dragOverId.value === `folder:${folder.id}`) dragOverId.value = null;
}

function onDragOverCrumb(crumb, event) {
    if (!acceptsDrop(event)) return;

    event.preventDefault();
    event.dataTransfer.dropEffect = "move";
    dragOverId.value = `crumb:${crumb?.id ?? "root"}`;
}

async function onDropOn(targetFolderId, event) {
    if (!acceptsDrop(event)) return;

    event.preventDefault();
    event.stopPropagation();

    const dragged = readNoteDrag(event);

    dragOverId.value = null;
    rootDragOver.value = false;
    dragging.value = null;

    if (!dragged) return;
    if ("folder" === dragged.kind && dragged.id === targetFolderId) return;

    await applyMove(dragged.kind, dragged.id, targetFolderId);
}

// ── The hover preview ──────────────────────────────────────────────

/**
 * The hover preview: the note's rendering, not its source.
 *
 * The content is encrypted, so it does not come with the list; the preview
 * asks the hovered card for it, once, and keeps it. It does not open during
 * a drag: one is filing, not reading.
 */
const {
    noteId: previewId,
    content: previewContent,
    loading: previewLoading,
    position: previewAt,
    open: openPreview,
    close: closePreview,
} = useNotePreview({
    fetchNote: (id) =>
        "function" === typeof props.notesApi.show
            ? props.notesApi.show(id)
            : Promise.resolve({ ok: false, payload: {} }),
});

function hoverNote(note, event) {
    if (dragging.value) return;

    openPreview(note, event.currentTarget);
}

// ── The keyboard ───────────────────────────────────────────────────

/**
 * Move around without the mouse.
 *
 * The cards are a list: the arrows go down it, Enter opens, Backspace goes
 * up one folder, Space selects, Escape lets go of everything. `n` makes a
 * note, `N` a folder - not `Cmd+N`, which the browser keeps for itself and
 * which would open a window over the screen.
 *
 * None of this while writing: a field, a text area or an editable content
 * keeps its keys, otherwise typing "nouvelle" in the search would create two
 * notes.
 */
const focused = ref(-1);

const navigable = computed(() => [
    ...pagedFolders.value.map((item) => ({ kind: "folder", item })),
    ...pagedNotes.value.map((item) => ({ kind: "note", item })),
]);

function isFocused(kind, item) {
    const current = navigable.value[focused.value];

    return Boolean(
        current && current.kind === kind && Number(current.item.id) === Number(item.id),
    );
}

function typing(event) {
    const node = event.target;

    if (!node || !node.tagName) return false;

    return (
        ["INPUT", "TEXTAREA", "SELECT"].includes(node.tagName) ||
        true === node.isContentEditable
    );
}

function onKeydown(event) {
    // An open modal has its own keys, and the library's keyboard has
    // nothing to say over it.
    if (typing(event) || nameModal.value || moving.value || pendingDelete.value) {
        return;
    }

    const total = navigable.value.length;

    switch (event.key) {
    case "ArrowDown":
    case "ArrowRight":
        if (!total) return;
        event.preventDefault();
        focused.value = (focused.value + 1) % total;

        return;

    case "ArrowUp":
    case "ArrowLeft":
        if (!total) return;
        event.preventDefault();
        focused.value = (focused.value - 1 + total) % total;

        return;

    case "Enter": {
        const current = navigable.value[focused.value];
        if (!current) return;
        event.preventDefault();

        if ("folder" === current.kind) {
            openFolder(current.item.id);
        } else {
            emit("open-note", current.item.id);
        }

        return;
    }

    case " ": {
        const current = navigable.value[focused.value];
        if (!current) return;
        event.preventDefault();
        // Space opens the mode if it is not open: it is the key that already
        // ticked, it does not have to wait for the button.
        startSelecting();
        toggleSelection(current.kind, current.item);

        return;
    }

    case "Backspace":
        if (null === currentFolderId.value) return;
        event.preventDefault();
        openFolder(path.value.at(-2)?.id ?? null);

        return;

    case "Escape":
        stopSelecting();

        return;

    // The slash opens the search: the convention is GitHub's and Craft's,
    // and it saves aiming at the magnifier.
    case "/":
        event.preventDefault();
        void openSearch();

        return;

    case "n":
        event.preventDefault();
        emit("create-note", currentFolderId.value);

        return;

    case "N":
        event.preventDefault();
        askForFolderName();
    }
}

onMounted(() => window.addEventListener("keydown", onKeydown));
onUnmounted(() => window.removeEventListener("keydown", onKeydown));

// What was targeted can disappear: changing folder, filtering, sorting.
watch([currentFolderId, query, sort, direction, flat, activeTag, visibility], () => {
    focused.value = -1;
});

// ── Manual order ───────────────────────────────────────────────────

/**
 * Move up and down, rather than an insertion line on drag.
 *
 * Dragging already serves to file: dropping a card onto a folder puts it
 * inside. Making it also say "insert yourself here" requires telling the
 * edge of a card from its middle, which is easy to miss with a finger. Two
 * entries in the card's menu say the same thing without ambiguity, and work
 * with the keyboard.
 *
 * Offered only when the sort is manual: moving a card one step in a list
 * sorted by date would mean nothing, since the sort would put it back where
 * it was.
 */
const manualOrder = computed(() => "manual" === sort.value && !flat.value);

async function nudge(kind, item, delta) {
    const list = "folder" === kind ? [...shownFolders.value] : [...shownNotes.value];
    const from = list.findIndex((one) => Number(one.id) === Number(item.id));
    const to = from + delta;

    if (from < 0 || to < 0 || to >= list.length) return;

    // The neighbour being passed, the one next to it on screen.
    const neighbour = list[to];

    // The folder's folders and notes share a single order, the one the tree
    // shows mixed: the card swaps places with its neighbour in that common
    // order, without touching the rank of the items of the other kind.
    // Renumbering a single kind from 0 to n-1, as before, would overwrite
    // the other's order.
    const parentOf = (value) => (null == value || "" === value ? null : Number(value));
    const inFolder = (one, key) => parentOf(one[key]) === parentOf(currentFolderId.value);
    const combined = [
        ...props.folders.filter((one) => inFolder(one, "parentId")).map((one) => ({ kind: "folder", id: Number(one.id), position: one.position })),
        ...props.notes.filter((one) => inFolder(one, "folderId")).map((one) => ({ kind: "note", id: Number(one.id), position: one.position })),
    ].sort(compareSiblings);

    const at = (target) => combined.findIndex((one) => one.kind === kind && one.id === Number(target.id));
    const moving = combined.splice(at(item), 1)[0];
    const anchor = at(neighbour);

    if (!moving || anchor < 0) return;

    // Towards increasing positions, the item moves after its neighbour;
    // towards decreasing ones, before. In descending order, "moving up" on
    // screen goes towards increasing positions.
    const towardsHigher = ("desc" === direction.value) === (delta < 0);
    combined.splice(towardsHigher ? anchor + 1 : anchor, 0, moving);

    const folderEntries = [];
    const noteEntries = [];
    combined.forEach((one, position) => {
        if ("folder" === one.kind) folderEntries.push({ id: one.id, parentId: currentFolderId.value, position });
        else noteEntries.push({ id: one.id, folderId: currentFolderId.value, position });
    });

    const results = await Promise.all([
        folderEntries.length ? props.foldersApi.reorder(folderEntries) : { ok: true },
        noteEntries.length ? props.notesApi.reorder(noteEntries) : { ok: true },
    ]);
    const failed = results.find((result) => !result.ok);
    const { ok, reported } = failed ?? { ok: true, reported: false };

    if (!ok) {
        if (!reported) toast.error(t("notes.markdown.errors.reorder_failed"));

        return;
    }

    emit("changed");
}

/**
 * Pin, or unpin.
 *
 * A notebook has three or four places one goes back to every day, and
 * looking for them in the tree each time is a chore Craft removes with its
 * favourites. The action says what it will do, not the current state:
 * "Épingler" on what is not pinned.
 */
function favoriteAction(kind, item) {
    const pinned = Boolean(item.favoritedAt);

    return {
        key: "favorite",
        title: pinned
            ? t("notes.markdown.library.unpin")
            : t("notes.markdown.library.pin"),
        icon: pinned ? PinOff : Pin,
        onSelect: () => togglePin(kind, item),
    };
}

async function togglePin(kind, item) {
    const { ok, reported } =
        "folder" === kind
            ? await props.foldersApi.favorite(item.id)
            : await props.notesApi.favorite(item.id);

    if (!ok) {
        if (!reported) toast.error(t("notes.markdown.library.pin_failed"));

        return;
    }

    emit("changed");
}

function orderActions(kind, item) {
    if (!manualOrder.value) return [];

    return [
        {
            key: "up",
            title: t("notes.markdown.library.sort.move_up"),
            icon: ArrowUp,
            onSelect: () => nudge(kind, item, -1),
        },
        {
            key: "down",
            title: t("notes.markdown.library.sort.move_down"),
            icon: ArrowDown,
            onSelect: () => nudge(kind, item, 1),
        },
    ];
}

// ── A card's actions ───────────────────────────────────────────────
/**
 * Takes away what is on screen: everything at the root, the folder otherwise.
 *
 * A navigation and not a request, like the panel's: the browser receives the
 * file and stores it.
 */
function exportShown() {
    window.location.assign(props.exportUrlFor({ folderId: currentFolderId.value }));
}

function folderActions(folder) {
    return [
        {
            key: "open",
            title: t("notes.markdown.folders.open"),
            icon: Folder,
            href: props.foldersApi.urlFor(folder.id),
            onSelect: () => openFolder(folder.id),
        },
        {
            key: "rename",
            title: t("notes.markdown.folders.rename"),
            icon: Pencil,
            onSelect: () => askForFolderName(folder),
        },
        {
            key: "move",
            title: t("notes.markdown.folders.move_to"),
            icon: FolderInput,
            onSelect: () => askToMove("folder", folder),
        },
        favoriteAction("folder", folder),
        ...(props.exportUrlFor
            ? [{
                key: "export",
                title: t("notes.markdown.folders.export"),
                icon: Download,
                href: props.exportUrlFor({ folderId: folder.id }),
            }]
            : []),
        ...orderActions("folder", folder),
        {
            key: "delete",
            title: t("notes.markdown.folders.delete"),
            icon: Trash2,
            color: "rose",
            onSelect: () => askToDelete("folder", folder),
        },
    ];
}

function noteActions(note) {
    return [
        {
            key: "open",
            title: t("notes.markdown.folders.open"),
            icon: FileText,
            href: props.noteUrlFor(note.id),
            onSelect: () => emit("open-note", note.id),
        },
        {
            key: "move",
            title: t("notes.markdown.folders.move_to"),
            icon: FolderInput,
            onSelect: () => askToMove("note", note),
        },
        favoriteAction("note", note),
        ...orderActions("note", note),
        {
            key: "export",
            title: t("notes.markdown.export.one"),
            icon: FileDown,
            href: props.noteExportUrlFor(note.id),
        },
        {
            key: "delete",
            title: t("notes.markdown.delete"),
            icon: Trash2,
            color: "rose",
            onSelect: () => askToDelete("note", note),
        },
    ];
}

/**
 * A card's date, or nothing.
 *
 * `Intl` throws a `RangeError` on a date it does not understand, and an
 * exception during rendering takes the whole component down: the page
 * becomes an empty frame, without a word. It happened on 23/09, with dates
 * the server sent as objects rather than strings. The server is fixed, and
 * the display no longer depends on its goodwill.
 */
/**
 * The whole card opens, not only its title.
 *
 * A card is a wide target, that is what it promises by taking up that space;
 * making only its twenty pixels of title clickable forces one to aim. The
 * title stays a link, for the middle click and for "open in a new tab".
 *
 * Two gestures are not openings and are left alone: what starts from a
 * button or a link (the checkbox, the menu, the title itself, which have
 * their own answer), and a click that has just ended a text selection -
 * reading an excerpt by highlighting it must not leave the page.
 */
function onCardClick(kind, item, event) {
    // In selection mode, the whole card ticks, title link included: only a
    // button (the menu, the pin) keeps its own gesture.
    if (selecting.value) {
        if (event.target.closest("button")) return;
        event.preventDefault();
        toggleSelection(kind, item);

        return;
    }

    if (event.target.closest("button, a")) return;

    const selection = window.getSelection?.();
    if (selection && "" !== String(selection)) return;

    if ("folder" === kind) {
        openFolder(item.id);

        return;
    }

    emit("open-note", item.id);
}

function updatedLabel(item) {
    return Number.isFinite(Date.parse(item.updatedAt))
        ? formatDateTime(item.updatedAt)
        : "";
}

/**
 * What the menu panel can ask of this page.
 *
 * The `modulePanelBridge` bridge talks to the application, which is always
 * mounted; the library is only mounted when no note is open. So the
 * application relays, and these four functions are the contract between
 * the two.
 */
defineExpose({
    openFolder,
    filterByTag: (value) => setTag(value),
    askForFolderName,
    askToDelete: (folder) => askToDelete("folder", folder),
    dropInto: (folderId, event) => onDropOn(folderId, event),
});
</script>

<template>
    <div class="flex flex-col min-h-0 flex-1">
        <header class="flex flex-col gap-3 border-b border-line p-3">
            <!-- The breadcrumb is a target too: going up one level is done by
                 dragging what one holds onto it, without opening a modal. -->
            <!-- First line: where one is, and what one can create there. -->
            <div class="flex items-start justify-between gap-3">
                <nav class="flex min-w-0 flex-1 flex-wrap items-center gap-1 text-sm" :aria-label="t('notes.markdown.library.title')">
                    <button
                        type="button"
                        class="rounded px-2 py-1 transition-colors"
                        :class="[
                            null === currentFolderId ? 'font-semibold text-primary' : 'text-muted hover:text-primary',
                            'crumb:root' === dragOverId ? 'bg-accent-500/15 ring-1 ring-accent-500' : '',
                        ]"
                        v-on:click="openFolder(null)"
                        v-on:dragover="onDragOverCrumb(null, $event)"
                        v-on:dragleave="dragOverId = null"
                        v-on:drop="onDropOn(null, $event)"
                    >
                        {{ t('notes.markdown.library.title') }}
                    </button>

                    <!-- The tag being viewed takes the breadcrumb's place: it
                         cuts across the notebook, so a folder's path no
                         longer describes what is on screen. The cross returns
                         the folder one was in, which has not moved. -->
                    <template v-if="'all' !== visibility">
                        <ChevronRight class="w-3.5 h-3.5 text-muted shrink-0" :stroke-width="2" />
                        <span class="inline-flex items-center gap-1 rounded-full bg-accent-600/15 px-2 py-1 text-xs font-medium text-accent-400">
                            <component :is="'shared' === visibility ? Users : Lock" class="h-3 w-3" :stroke-width="2" />
                            {{ visibilityLabel }}
                            <button
                                type="button"
                                class="transition-colors hover:text-primary"
                                :title="t('notes.markdown.library.visibility.clear')"
                                :aria-label="t('notes.markdown.library.visibility.clear')"
                                v-on:click="visibility = 'all'"
                            >
                                <X class="h-3 w-3" :stroke-width="2" />
                            </button>
                        </span>
                    </template>

                    <template v-if="null !== activeTag">
                        <ChevronRight class="w-3.5 h-3.5 text-muted shrink-0" :stroke-width="2" />
                        <span class="inline-flex items-center gap-1 rounded-full bg-accent-600/15 px-2 py-1 text-xs font-medium text-accent-400">
                            <Tag class="h-3 w-3" :stroke-width="2" />
                            {{ activeTag }}
                            <button
                                type="button"
                                class="transition-colors hover:text-primary"
                                :title="t('notes.markdown.library.tag.clear')"
                                :aria-label="t('notes.markdown.library.tag.clear')"
                                v-on:click="setTag(null)"
                            >
                                <X class="h-3 w-3" :stroke-width="2" />
                            </button>
                        </span>
                    </template>

                    <template v-for="crumb in path" :key="crumb.id">
                        <ChevronRight class="w-3.5 h-3.5 text-muted shrink-0" :stroke-width="2" />
                        <button
                            type="button"
                            class="rounded px-2 py-1 transition-colors"
                            :class="[
                                crumb.id === currentFolderId ? 'font-semibold text-primary' : 'text-muted hover:text-primary',
                                `crumb:${crumb.id}` === dragOverId ? 'bg-accent-500/15 ring-1 ring-accent-500' : '',
                            ]"
                            v-on:click="openFolder(crumb.id)"
                            v-on:dragover="onDragOverCrumb(crumb, $event)"
                            v-on:dragleave="dragOverId = null"
                            v-on:drop="onDropOn(crumb.id, $event)"
                        >
                            {{ crumb.name || t('notes.markdown.folders.untitled') }}
                        </button>
                    </template>
                </nav>

                <!-- Icons for the occasional gestures, the word for the
                     frequent one. « Nouvelle note » is the verb of this
                     screen, so it is the suite's main button, with its name,
                     rightmost, like « + Nouvelle publication » on the other
                     lists (UI audit of 07/10/2026, see AppPageActions); on a
                     phone it keeps its square and loses the word. Export and
                     new folder stay bare icons, named by their tooltip. -->
                <div class="flex shrink-0 items-center gap-1">
                    <!-- In the title row, not in the toolbar below, which
                         already holds nine icons: it takes away what the row
                         names, everything at the root, the folder otherwise. -->
                    <!-- Framed like the day's note and the main button beside
                         them (08/10/2026): two bare icons next to two real
                         buttons read as two kinds of gesture. -->
                    <AppButton
                        v-if="exportUrlFor"
                        variant="secondary"
                        data-library-export
                        :label="null === currentFolderId ? t('notes.markdown.export.all') : t('notes.markdown.folders.export')"
                        icon-only
                        v-on:click="exportShown"
                    >
                        <Download class="h-4 w-4" :stroke-width="2" />
                    </AppButton>

                    <AppButton
                        variant="secondary"
                        :label="t('notes.markdown.library.new_folder')"
                        icon-only
                        v-on:click="askForFolderName()"
                    >
                        <FolderPlus class="h-4 w-4" :stroke-width="2" />
                    </AppButton>

                    <!-- Every task of every note (09/10/2026). -->
                    <AppButton
                        v-if="tasksEnabled"
                        data-library-tasks
                        class="ml-1"
                        variant="secondary"
                        :label="t('notes.markdown.tasks.title')"
                        icon-only
                        v-on:click="emit('open-tasks')"
                    >
                        <ListChecks class="h-4 w-4" :stroke-width="2" />
                    </AppButton>

                    <!-- The journal (09/10/2026): today's note, and the
                         month's calendar to reach any other day's. An icon
                         at every width: next to the main button, a second
                         word would compete with it. -->
                    <div v-if="dailyEnabled" ref="journalRef" class="relative ml-1">
                        <AppButton
                            data-library-daily-note
                            variant="secondary"
                            :label="t('notes.markdown.daily.title')"
                            :loading="dailyOpening"
                            :aria-expanded="journalOpen"
                            icon-only
                            v-on:click="journalOpen = !journalOpen"
                        >
                            <CalendarDays v-if="!dailyOpening" class="h-4 w-4" :stroke-width="2" />
                        </AppButton>
                        <div v-if="journalOpen" class="absolute right-0 top-full z-30 mt-1">
                            <NoteJournalCalendar :load-days="loadJournalDays" v-on:open="openJournalDay" />
                        </div>
                    </div>

                    <AppButton
                        data-library-new-note
                        class="ml-1"
                        variant="primary"
                        :label="t('notes.markdown.library.new_note')"
                        icon-only-on-phone
                        v-on:click="emit('create-note', currentFolderId)"
                    >
                        <Plus class="h-4 w-4" :stroke-width="2" />
                    </AppButton>
                </div>
            </div>

            <!-- Second line: how one looks. What *creates* moved up a row,
                 to the right of the breadcrumb, because those gestures are
                 about the place one is in, not about the way of reading it.
                 A single bar mixed them, and eight controls packed together
                 read like a wall. -->
            <!-- All the way to the right, in a single group: the magnifier
                 alone on the left left a gap of half the bar for a thirty
                 pixel button. Opened, the search takes its place in the group
                 and pushes the rest, instead of crossing the screen. -->
            <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-2">
                <AppIconButton
                    v-if="!searchOpen"
                    :title="t('notes.markdown.library.search_placeholder')"
                    :aria-label="t('notes.markdown.library.search_placeholder')"
                    v-on:click="openSearch()"
                >
                    <Search class="h-4 w-4" :stroke-width="2" />
                </AppIconButton>

                <div
                    v-else
                    ref="searchBox"
                    class="w-full sm:w-64"
                    v-on:keyup.esc="closeSearch"
                    v-on:focusout="closeSearch"
                >
                    <AppSearchInput
                        v-model="query"
                        :placeholder="t('notes.markdown.library.search_placeholder')"
                    />
                </div>

                <div class="flex items-center gap-3">
                    <!-- Filed or completely flat. Two readings of the same
                         notebook: what the place holds, or all the notes from
                         here and below at once, to find what one no longer
                         knows where one put. -->
                    <!-- What is open to the team, what is not, or everything.
                         A button that cycles rather than three: the bar
                         already carries six. -->
                    <AppIconButton
                        :class="'all' === visibility ? '' : 'text-accent-400'"
                        :title="visibilityLabel"
                        :aria-label="visibilityLabel"
                        v-on:click="cycleVisibility"
                    >
                        <Users v-if="'shared' === visibility" class="h-4 w-4" :stroke-width="2" />
                        <Lock v-else-if="'private' === visibility" class="h-4 w-4" :stroke-width="2" />
                        <UsersRound v-else class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>

                    <AppIconButton
                        :class="flat ? 'text-accent-400' : ''"
                        :title="flat ? t('notes.markdown.library.scope.grouped') : t('notes.markdown.library.scope.flat')"
                        :aria-label="flat ? t('notes.markdown.library.scope.grouped') : t('notes.markdown.library.scope.flat')"
                        :aria-pressed="flat"
                        v-on:click="toggleFlat"
                    >
                        <Layers v-if="flat" class="h-4 w-4" :stroke-width="2" />
                        <FolderTree v-else class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>

                    <!-- Selection mode, as in the media library: the circles
                         only appear once it is open. -->
                    <AppIconButton
                        :class="selecting ? 'text-accent-400' : ''"
                        :title="selecting ? t('notes.markdown.library.stop_selecting') : t('notes.markdown.library.select')"
                        :aria-label="selecting ? t('notes.markdown.library.stop_selecting') : t('notes.markdown.library.select')"
                        :aria-pressed="selecting"
                        v-on:click="toggleSelecting"
                    >
                        <CheckSquare class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>

                    <div class="inline-flex overflow-hidden rounded-md border border-line">
                        <AppTab
                            v-for="opt in viewOptions"
                            :key="opt.value"
                            size="sm"
                            align="center"
                            shape-class="rounded-none"
                            :active="view === opt.value"
                            :title="opt.label"
                            v-on:click="setView(opt.value)"
                        >
                            <component :is="opt.icon" class="h-4 w-4" :stroke-width="2" />
                        </AppTab>
                    </div>

                    <!-- The direction is a button separate from the criterion,
                         as in Craft: changing order must not require
                         reopening the list of criteria. -->
                    <div class="flex items-center gap-1">
                        <!-- Folded into an icon, like the search: the
                             criterion changes in fits and starts and does not
                             have to take up its width all the time. -->
                        <AppIconButton
                            v-if="!sortOpen"
                            :title="`${t('notes.markdown.library.sort.label')} : ${sortLabel}`"
                            :aria-label="`${t('notes.markdown.library.sort.label')} : ${sortLabel}`"
                            v-on:click="openSort('.multiselect')"
                        >
                            <ArrowUpDown class="h-4 w-4" :stroke-width="2" />
                        </AppIconButton>

                        <!-- The house selector rather than the native
                             `<select>`: same look as everywhere else in the
                             back-office. No search inside, four criteria are
                             not searched for. -->
                        <div v-else ref="sortBox" class="w-44" v-on:keyup.esc="foldSort">
                            <AppMultiselect
                                :model-value="sort"
                                :options="sortOptions"
                                :searchable="false"
                                v-on:update:model-value="chooseSort($event)"
                                v-on:close="foldSort"
                            />
                        </div>
                        <AppIconButton
                            :title="'asc' === direction ? t('notes.markdown.library.sort.asc') : t('notes.markdown.library.sort.desc')"
                            v-on:click="toggleDirection"
                        >
                            <ArrowUpNarrowWide v-if="'asc' === direction" class="h-4 w-4" :stroke-width="2" />
                            <ArrowDownWideNarrow v-else class="h-4 w-4" :stroke-width="2" />
                        </AppIconButton>
                    </div>
                </div>
            </div>
        </header>
        <!-- The screen's how-to, next to what it explains; folded or
             expanded, the choice applies to every callout. The header and
             content margins (`p-3`): set right on the card, it touched its
             edges. -->
        <AppGuide :title="t('notes.markdown.guide.title')" storage-key="notes-library" class="mx-3 mt-3">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 8" :key="step">{{ t(`notes.markdown.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <!-- What the selection allows, when there is one. A bar rather than
             a menu: what is chosen must stay counted in view while one
             decides. -->
        <div
            v-if="selectionCount"
            class="flex flex-wrap items-center gap-2 border-b border-line bg-surface-2 px-3 py-2 sm:px-4"
        >
            <span class="text-sm text-primary">
                {{ t('notes.markdown.library.selected', { count: selectionCount }) }}
            </span>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                <AppButton variant="ghost" size="sm" v-on:click="askToMove('selection')">
                    <FolderInput class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t('notes.markdown.folders.move_to') }}
                </AppButton>
                <AppButton variant="danger" size="sm" v-on:click="askToDelete('selection', null)">
                    <Trash2 class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t('notes.markdown.delete') }}
                </AppButton>
                <AppIconButton
                    :title="t('notes.markdown.library.clear_selection')"
                    v-on:click="stopSelecting"
                >
                    <X class="w-4 h-4" :stroke-width="2" />
                </AppIconButton>
            </div>
        </div>

        <div
            class="flex-1 min-h-0 overflow-auto p-3"
            :class="rootDragOver ? 'bg-accent-500/5' : ''"
            v-on:dragover="onDragOverCrumb(null, $event)"
            v-on:drop="onDropOn(null, $event)"
        >
            <!-- A tag without notes has its own wording: "ce dossier est
                 vide" would be wrong, the folder has nothing to do with it. -->
            <AppNoData
                v-if="null !== activeTag && isEmpty"
                :message="t('notes.markdown.library.tag.none')"
                :hint="t('notes.markdown.library.tag.none_description', { tag: activeTag })"
                :icon="Tag"
            />

            <AppNoData
                v-else-if="isEmpty"
                :message="null === currentFolderId ? t('notes.markdown.library.empty_root.title') : t('notes.markdown.library.empty.title')"
                :hint="null === currentFolderId ? t('notes.markdown.library.empty_root.description') : t('notes.markdown.library.empty.description')"
                :icon="Folder"
            />

            <AppNoData
                v-else-if="nothingShown"
                :message="t('notes.markdown.search_no_results')"
                :hint="t('notes.markdown.search_no_results_description', { query })"
                :icon="FileText"
            />

            <!-- Everything else fits in a single branch: a `v-else` must
                 follow its `v-if` immediately, and the recent row had slipped
                 in between the two. -->
            <template v-else>
                <section v-if="recent.length" class="mb-5">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">
                        {{ t('notes.markdown.library.recent') }}
                    </h3>

                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-4">
                        <a
                            v-for="note in recent"
                            :key="`recent-${note.id}`"
                            :href="noteUrlFor(note.id)"
                            class="aurora-card flex min-w-0 items-center gap-2 px-3 py-2 text-sm no-underline transition-colors hover:border-accent-500/50"
                            v-on:click.prevent="emit('open-note', note.id)"
                        >
                            <span v-if="note.icon" class="inline-flex w-4 shrink-0 justify-center leading-none">{{ note.icon }}</span>
                            <FileText v-else class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
                            <span class="truncate text-primary">{{ noteLabel(note) }}</span>
                        </a>
                    </div>
                </section>

                <!-- Mosaic and cards share the grid and only differ by the
                 height of the tiles: a single column on a phone, that is the
                 house rule since 14/09. -->
                <div v-if="'mosaic' === view || 'cards' === view">
                    <div
                        class="grid grid-cols-1 gap-3"
                        :class="'mosaic' === view ? 'sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4' : 'sm:grid-cols-3 2xl:grid-cols-4'"
                    >
                        <article
                            v-for="folder in pagedFolders"
                            :key="`folder-${folder.id}`"
                            class="group flex cursor-pointer flex-col rounded-lg border bg-surface transition-colors"
                            :class="[
                                `folder:${folder.id}` === dragOverId ? 'border-accent-500 bg-accent-500/10' : 'border-line hover:border-accent-500/50',
                                isSelected('folder', folder) ? 'ring-2 ring-accent-500' : '',
                                isFocused('folder', folder) ? 'ring-2 ring-accent-500/60' : '',
                                'mosaic' === view ? 'p-4' : 'p-3',
                            ]"
                            draggable="true"
                            v-on:dragstart="onDragStart('folder', folder, $event)"
                            v-on:dragend="onDragEnd"
                            v-on:dragover="onDragOverFolder(folder, $event)"
                            v-on:dragleave="onDragLeaveFolder(folder, $event)"
                            v-on:drop="onDropOn(Number(folder.id), $event)"
                            v-on:click="onCardClick('folder', folder, $event)"
                        >
                            <div class="flex items-start gap-2">
                                <!-- The box comes before the title instead
                                     of covering it: overlaid, it landed on
                                     the name of short folders. -->
                                <button
                                    v-if="selecting"
                                    type="button"
                                    class="shrink-0 pt-0.5"
                                    :title="t('notes.markdown.library.select')"
                                    v-on:click.stop="toggleSelection('folder', folder)"
                                >
                                    <AppSelectionCheck :active="isSelected('folder', folder)" size="xs" />
                                </button>

                                <!-- A click opens, a double click renames:
                                     it is a file explorer's gesture, and the
                                     menu keeps the entry for whoever does
                                     not know it. -->
                                <button
                                    type="button"
                                    class="flex min-w-0 flex-1 items-center gap-2 text-left"
                                    v-on:click="openFolder(folder.id)"
                                    v-on:dblclick.stop="askForFolderName(folder)"
                                >
                                    <Folder
                                        class="w-5 h-5 shrink-0 text-muted"
                                        :style="folderTint(folder)"
                                        :stroke-width="2"
                                    />
                                    <span class="truncate font-medium text-primary">{{ folderLabel(folder) }}</span>
                                    <!-- What has left one's place shows
                                         without having to open a menu. -->
                                    <Users
                                        v-if="isShared(folder)"
                                        class="h-3.5 w-3.5 shrink-0 text-accent-400"
                                        :title="t('notes.markdown.library.shared.badge')"
                                        :stroke-width="2"
                                    />
                                </button>

                                <AppRowActions :actions="folderActions(folder)" :label="folderLabel(folder)" />
                            </div>

                            <p class="mt-2 text-xs text-muted">
                                {{ t('notes.markdown.folders.folder_count', { count: folder.folderCount ?? 0 }) }} · {{ t('notes.markdown.folders.note_count', { count: folder.noteCount ?? 0 }) }}
                            </p>
                        </article>

                        <article
                            v-for="note in pagedNotes"
                            :key="`note-${note.id}`"
                            class="aurora-card group flex cursor-pointer flex-col transition-colors hover:border-accent-500/50"
                            :class="[
                                isSelected('note', note) ? 'ring-2 ring-accent-500' : '',
                                isFocused('note', note) ? 'ring-2 ring-accent-500/60' : '',
                                'mosaic' === view ? 'p-4' : 'p-3',
                            ]"
                            draggable="true"
                            v-on:dragstart="onDragStart('note', note, $event)"
                            v-on:dragend="onDragEnd"
                            v-on:click="onCardClick('note', note, $event)"
                            v-on:mouseenter="hoverNote(note, $event)"
                            v-on:mouseleave="closePreview"
                        >
                            <div class="flex items-start gap-2">
                                <button
                                    v-if="selecting"
                                    type="button"
                                    class="shrink-0 pt-0.5"
                                    :title="t('notes.markdown.library.select')"
                                    v-on:click.stop="toggleSelection('note', note)"
                                >
                                    <AppSelectionCheck :active="isSelected('note', note)" size="xs" />
                                </button>

                                <a
                                    :href="noteUrlFor(note.id)"
                                    class="flex min-w-0 flex-1 items-center gap-2"
                                    v-on:click.prevent="emit('open-note', note.id)"
                                >
                                    <span v-if="note.icon" class="inline-flex w-5 shrink-0 justify-center text-lg leading-none">{{ note.icon }}</span>
                                    <FileText v-else class="w-5 h-5 shrink-0 text-muted" :stroke-width="2" />
                                    <span class="truncate font-medium text-primary">{{ noteLabel(note) }}</span>
                                    <Users
                                        v-if="isShared(note)"
                                        class="h-3.5 w-3.5 shrink-0 text-accent-400"
                                        :title="t('notes.markdown.library.shared.badge')"
                                        :stroke-width="2"
                                    />
                                </a>

                                <AppRowActions :actions="noteActions(note)" :label="noteLabel(note)" />
                            </div>

                            <!-- The thumbnail, in the mosaic only: it is what
                                 sets this view apart from the cards. Cut at
                                 the bottom by a gradient rather than a sharp
                                 line, so that nothing looks like the end of
                                 a note. -->
                            <div
                                v-if="'mosaic' === view && note.excerpt"
                                class="note-thumb relative mt-2 h-40 overflow-hidden text-[0.6875rem] leading-snug text-muted"
                            >
                                <div v-html="thumbnail(note)" />
                                <div class="pointer-events-none absolute inset-x-0 bottom-0 h-10 bg-gradient-to-t from-surface to-transparent" />
                            </div>

                            <!-- Flattened, the folder the note comes from,
                                 and the way to get there. -->
                            <button
                                v-if="noteFolderLabel(note)"
                                type="button"
                                class="mt-2 flex w-fit items-center gap-1 text-xs text-muted transition-colors hover:text-accent-400"
                                :title="t('notes.markdown.library.scope.open_folder', { folder: noteFolderLabel(note) })"
                                v-on:click.stop="openFolder(note.folderId)"
                            >
                                <Folder class="h-3 w-3 shrink-0" :stroke-width="2" />
                                <span class="truncate">{{ noteFolderLabel(note) }}</span>
                            </button>

                            <!-- A tag is clickable: it is the gesture one
                                 tries on seeing it, and it existed nowhere
                                 since the redesign. -->
                            <div v-if="note.tags?.length" class="mt-2 flex flex-wrap gap-1">
                                <button
                                    v-for="one in note.tags"
                                    :key="one"
                                    type="button"
                                    :title="t('notes.markdown.library.tag.filter', { tag: one })"
                                    v-on:click.stop="setTag(one)"
                                >
                                    <AppBadge color="gray" size="xs">{{ one }}</AppBadge>
                                </button>
                            </div>

                            <p class="mt-auto pt-2 text-xs text-muted">{{ updatedLabel(note) }}</p>
                        </article>
                    </div>

                    <AppLoadMore
                        v-if="hasMore"
                        class="mt-3"
                        :has-more="hasMore"
                        v-on:load="shown += PAGE"
                    />
                </div>

                <!-- The table (09/10/2026): the notes' properties in columns. -->
                <div v-else-if="'table' === view">
                    <NoteTableView
                        :folders="pagedFolders"
                        :notes="pagedNotes"
                        :people="people"
                        :note-url-for="noteUrlFor"
                        :note-label="noteLabel"
                        :folder-label="folderLabel"
                        v-on:open-note="emit('open-note', $event)"
                        v-on:open-folder="openFolder"
                    />

                    <AppLoadMore
                        v-if="hasMore"
                        class="mt-3"
                        :has-more="hasMore"
                        v-on:load="shown += PAGE"
                    />
                </div>

                <!-- The list: a table on a wide screen, stacked rows below.
                 The table scrolls in its own container so that the page
                 never goes sideways. -->
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide text-muted">
                            <tr>
                                <th scope="col" class="px-2 py-2 font-medium">{{ t('notes.markdown.library.columns.name') }}</th>
                                <th scope="col" class="hidden px-2 py-2 font-medium sm:table-cell">{{ t('notes.markdown.library.columns.tags') }}</th>
                                <th scope="col" class="hidden px-2 py-2 font-medium sm:table-cell">{{ t('notes.markdown.library.columns.updated') }}</th>
                                <th scope="col" class="px-2 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="folder in pagedFolders"
                                :key="`row-folder-${folder.id}`"
                                class="border-t border-line transition-colors"
                                :class="`folder:${folder.id}` === dragOverId ? 'bg-accent-500/10' : ''"
                                draggable="true"
                                v-on:dragstart="onDragStart('folder', folder, $event)"
                                v-on:dragend="onDragEnd"
                                v-on:dragover="onDragOverFolder(folder, $event)"
                                v-on:dragleave="onDragLeaveFolder(folder, $event)"
                                v-on:drop="onDropOn(Number(folder.id), $event)"
                                v-on:click="onCardClick('folder', folder, $event)"
                            >
                                <td class="px-2 py-2">
                                    <button
                                        type="button"
                                        class="flex items-center gap-2 text-left"
                                        v-on:click="openFolder(folder.id)"
                                        v-on:dblclick.stop="askForFolderName(folder)"
                                    >
                                        <Folder
                                            class="w-4 h-4 shrink-0 text-muted"
                                            :style="folderTint(folder)"
                                            :stroke-width="2"
                                        />
                                        <span class="truncate font-medium text-primary">{{ folderLabel(folder) }}</span>
                                    </button>
                                </td>
                                <td class="hidden px-2 py-2 text-muted sm:table-cell">
                                    {{ t('notes.markdown.library.count', { count: (folder.noteCount ?? 0) + (folder.folderCount ?? 0) }) }}
                                </td>
                                <td class="hidden px-2 py-2 text-muted sm:table-cell">{{ updatedLabel(folder) }}</td>
                                <td class="px-2 py-2">
                                    <AppRowActions :actions="folderActions(folder)" :label="folderLabel(folder)" />
                                </td>
                            </tr>

                            <tr
                                v-for="note in pagedNotes"
                                :key="`row-note-${note.id}`"
                                class="border-t border-line"
                                draggable="true"
                                v-on:dragstart="onDragStart('note', note, $event)"
                                v-on:dragend="onDragEnd"
                                v-on:click="onCardClick('note', note, $event)"
                                v-on:mouseenter="hoverNote(note, $event)"
                                v-on:mouseleave="closePreview"
                            >
                                <td class="px-2 py-2">
                                    <a
                                        :href="noteUrlFor(note.id)"
                                        class="flex items-center gap-2"
                                        v-on:click.prevent="emit('open-note', note.id)"
                                    >
                                        <span v-if="note.icon" class="inline-flex w-4 shrink-0 justify-center leading-none">{{ note.icon }}</span>
                                        <FileText v-else class="w-4 h-4 shrink-0 text-muted" :stroke-width="2" />
                                        <span class="truncate font-medium text-primary">{{ noteLabel(note) }}</span>
                                    </a>

                                    <button
                                        v-if="noteFolderLabel(note)"
                                        type="button"
                                        class="mt-0.5 flex items-center gap-1 pl-6 text-xs text-muted transition-colors hover:text-accent-400"
                                        :title="t('notes.markdown.library.scope.open_folder', { folder: noteFolderLabel(note) })"
                                        v-on:click.stop="openFolder(note.folderId)"
                                    >
                                        <Folder class="h-3 w-3 shrink-0" :stroke-width="2" />
                                        <span class="truncate">{{ noteFolderLabel(note) }}</span>
                                    </button>
                                </td>
                                <td class="hidden px-2 py-2 sm:table-cell">
                                    <div class="flex flex-wrap gap-1">
                                        <button
                                            v-for="one in note.tags ?? []"
                                            :key="one"
                                            type="button"
                                            :title="t('notes.markdown.library.tag.filter', { tag: one })"
                                            v-on:click.stop="setTag(one)"
                                        >
                                            <AppBadge color="gray" size="xs">{{ one }}</AppBadge>
                                        </button>
                                    </div>
                                </td>
                                <td class="hidden px-2 py-2 text-muted sm:table-cell">{{ updatedLabel(note) }}</td>
                                <td class="px-2 py-2">
                                    <AppRowActions :actions="noteActions(note)" :label="noteLabel(note)" />
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <AppLoadMore
                        v-if="hasMore"
                        class="mt-3"
                        :has-more="hasMore"
                        v-on:load="shown += PAGE"
                    />
                </div>
            </template>
        </div>

        <AppModal
            :show="null !== nameModal"
            max-width="sm"
            :closeable="!nameSaving"
            :title="nameModal?.id ? t('notes.markdown.folders.rename') : t('notes.markdown.folders.create')"
            :icon="FolderPlus"
            v-on:close="nameModal = null"
        >
            <AppInput
                v-model="nameValue"
                :placeholder="t('notes.markdown.folders.name_placeholder')"
                class="w-full"
                v-on:keyup.enter="submitName"
            />

            <!-- A colour to recognise a folder without reading it. The house
                 picker, presets and hexadecimal, the same as for document
                 tags. -->
            <AppColorPicker
                v-model="nameColor"
                class="mt-4"
                :label="t('notes.markdown.folders.color')"
            />

            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" :disabled="nameSaving" v-on:click="nameModal = null">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t('notes.markdown.cancel') }}
                    </AppButton>
                    <AppButton variant="primary" size="md" :loading="nameSaving" v-on:click="submitName">
                        {{ t('notes.markdown.save') }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="null !== moving"
            max-width="sm"
            :title="t('notes.markdown.folders.move_to')"
            :icon="FolderInput"
            v-on:close="moving = null"
        >
            <!-- Searchable, this one: a tidy notebook has dozens of folders,
                 and unrolling the whole list to target one would be the same
                 chore as the tree one has just left. -->
            <AppMultiselect
                v-model="moveTarget"
                :options="moveTargets"
                :placeholder="t('notes.markdown.folders.move_to')"
                class="w-full"
            />

            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="moving = null">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t('notes.markdown.cancel') }}
                    </AppButton>
                    <AppButton variant="primary" size="md" v-on:click="submitMove">
                        <FolderInput class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t('notes.markdown.folders.move_to') }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="null !== pendingDelete"
            max-width="sm"
            :closeable="!deleting"
            :title="'folder' === pendingDelete?.kind ? t('notes.markdown.folders.delete') : t('notes.markdown.delete')"
            :icon="Trash2"
            v-on:close="pendingDelete = null"
        >
            <p v-if="'selection' === pendingDelete?.kind" class="text-sm text-primary">
                {{ t('notes.markdown.library.confirm_delete_selection', { count: selectionCount }) }}
            </p>
            <p v-else-if="'folder' === pendingDelete?.kind" class="text-sm text-primary">
                {{ t('notes.markdown.folders.confirm_delete', { name: folderLabel(pendingDelete.item) }) }}
            </p>
            <p v-else-if="pendingDelete" class="text-sm text-primary">
                {{ t('notes.markdown.confirm_delete', { title: noteLabel(pendingDelete.item) }) }}
            </p>
            <p class="mt-2 text-sm text-secondary">
                {{ t('notes.markdown.delete_warning') }}
            </p>

            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" :disabled="deleting" v-on:click="pendingDelete = null">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t('notes.markdown.cancel') }}
                    </AppButton>
                    <AppButton variant="danger" size="md" :loading="deleting" v-on:click="confirmDelete">
                        <Trash2 class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t('notes.markdown.delete') }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- The preview lives in the `body`: the grid scrolls and clips, and
             a floating card must not be cut by what it hovers over. It never
             takes the pointer - `pointer-events` set to none - otherwise it
             would get between the cursor and the card that opened it, which
             would immediately receive a `mouseleave`. -->
        <Teleport to="body">
            <div
                v-if="null !== previewId"
                class="aurora-card pointer-events-none fixed z-50 w-90 overflow-hidden p-3 shadow-xl"
                :style="{
                    top: `${previewAt.top}px`,
                    left: `${previewAt.left}px`,
                    maxHeight: '17.5rem',
                }"
            >
                <p v-if="previewLoading" class="text-xs text-muted">
                    {{ t('shared.common.loading') }}
                </p>
                <p v-else-if="'' === previewContent" class="text-xs text-muted">
                    {{ t('notes.markdown.library.preview.empty') }}
                </p>
                <NotePreview v-else :content="previewContent" />
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
/*
 * A card's thumbnail: the note's rendering, small.
 *
 * `:deep` because this HTML comes from a `v-html` and not from the template:
 * scoped styles do not mark it. And not the preview stylesheet
 * (`preview.css`), which is sized to be read: here we want the silhouette of
 * a note, not its comfort.
 */
.note-thumb :deep(h1),
.note-thumb :deep(h2),
.note-thumb :deep(h3),
.note-thumb :deep(h4) {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--color-primary);
    margin: 0 0 0.25rem;
    line-height: 1.3;
}

.note-thumb :deep(p),
.note-thumb :deep(ul),
.note-thumb :deep(ol),
.note-thumb :deep(blockquote),
.note-thumb :deep(pre) {
    margin: 0 0 0.375rem;
}

.note-thumb :deep(ul),
.note-thumb :deep(ol) {
    padding-left: 1rem;
    list-style: revert;
}

.note-thumb :deep(blockquote) {
    border-left: 2px solid var(--color-line);
    padding-left: 0.5rem;
}

.note-thumb :deep(pre) {
    background: var(--color-surface-2);
    border-radius: 0.25rem;
    padding: 0.25rem 0.375rem;
    overflow: hidden;
    white-space: pre-wrap;
}

.note-thumb :deep(code) {
    font-size: 0.625rem;
}

.note-thumb :deep(a) {
    color: var(--color-accent-400);
    text-decoration: none;
}

.note-thumb :deep(hr) {
    border-color: var(--color-line);
    margin: 0.375rem 0;
}

/*
 * Checkboxes: without bullet, with some air, and inert.
 *
 * The preview stylesheet already handles them, but it targets
 * `.note-preview` and the thumbnail is not one: the lines therefore kept
 * their bullet *and* their box, stuck to the text. And a box in a thumbnail
 * must not tick - the card opens the note, and ticking here would change a
 * drawing without writing anything.
 */
.note-thumb :deep(.task-list-item) {
    list-style: none;
    margin-left: -0.9rem;
    display: flex;
    align-items: baseline;
    gap: 0.35rem;
}

.note-thumb :deep(.task-checkbox) {
    pointer-events: none;
    flex-shrink: 0;
    width: 0.7rem;
    height: 0.7rem;
    margin: 0;
}

/* A whole table in a fifteen-line thumbnail would say nothing more than a
   grey block, and would make the card overflow in width. */
.note-thumb :deep(table) {
    display: none;
}

/* The server strips images from the excerpt; those that would come another
   way (an HTML tag in the text) stay bounded. */
.note-thumb :deep(img) {
    max-height: 4rem;
    width: auto;
}
</style>
