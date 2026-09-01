import { ref, computed, onMounted, watch } from "vue";
import { Pencil, Eye, Columns } from "lucide-vue-next";
import { useDebounce } from "@/shared/composables/useDebounce.js";
import { useMediaQuery } from "@/shared/composables/useMediaQuery.js";
import { useMarkdownNotesApi } from "@notes/backend/markdown/composables/useMarkdownNotesApi.js";
import { useNotesEditor } from "@notes/backend/markdown/composables/useNotesEditor.js";
import { useNoteTree } from "@notes/backend/markdown/composables/useNoteTree.js";
import { useNoteTagFilter } from "@notes/backend/markdown/composables/useNoteTagFilter.js";
import { useMarkdownTagsApi } from "@notes/backend/markdown/composables/useMarkdownTagsApi.js";
import { useNoteDragDrop } from "@notes/backend/markdown/composables/useNoteDragDrop.js";
import { useViewMode } from "@notes/backend/markdown/composables/useViewMode.js";
import { useResizable } from "@shared/composables/useResizable.js";
import { useRelativeTime } from "@shared/composables/useRelativeTime.js";
import { useAutoSaveStatusDisplay } from "@shared/composables/useAutoSaveStatusDisplay.js";

/**
 * Page-level composition for the Markdown notes editor. Wires together
 * all the smaller domain composables (API client, editor state, tree
 * filter, tag manager, drag-drop, view mode, auto-save status, …) and
 * exposes a single flat bag that the SFC binds to its template.
 *
 * Keeping the orchestration here lets `MarkdownNotesApp.vue` stay pure
 * presentation, in the same shape as `NoteGraph.vue` / `NoteEditor.vue`.
 *
 * @param {object} props - same shape MarkdownNotesApp receives as
 *   defineProps: the eleven backend paths + the initial flat note list.
 * @param {(key: string) => string} t - vue-i18n's `t`
 */
export function useMarkdownNotesPage(props, t) {
    const api = useMarkdownNotesApi(props);
    const tagsApi = useMarkdownTagsApi(props);

    const editor = useNotesEditor({
        api,
        initialNotes: props.notes,
        extraFields: props.extraFields ?? {},
    });
    const {
        notes,
        selectedId,
        selectedNote,
        form,
        saving,
        deleting,
        saveStatus,
        lastSavedAt,
        selectNote: selectNoteRaw,
        createNote: createNoteRaw,
        pendingDelete,
        requestDelete,
        cancelDelete,
        confirmDelete,
        onWikiLinkClick,
        onCheckboxToggle,
        onImageResize,
        refreshList,
        reloadCurrent,
    } = editor;

    // ── UI-only state ──────────────────────────────────────────────
    const sidePanelOpen = ref(false);
    const graphOpen = ref(false);
    const tagManagerOpen = ref(false);
    const treeQuery = ref("");

    // ── Mobile / responsive state ──────────────────────────────────
    // Tailwind's md breakpoint is 768px. Below that, the editor turns
    // into a single-column layout: sidebar slides in as a drawer, the
    // split view-mode is disabled, and the side-panel renders as a
    // fullscreen overlay (see MarkdownNotesApp.vue).
    const { matches: isMobile } = useMediaQuery("(max-width: 767px)");
    const sidebarOpen = ref(!isMobile.value);
    watch(isMobile, (mobile) => {
        // On desktop the sidebar is always visible; resetting to true
        // here ensures resizing the window back up reveals it again.
        sidebarOpen.value = !mobile;
    });

    // ── Tags + tree ────────────────────────────────────────────────
    const {
        availableTags,
        selectedTags,
        toggleTag,
        clearTags,
        pruneMissingTags,
    } = useNoteTagFilter(notes);

    // Server-side content search. The tree filter handles title + tags
    // client-side (we already have those fields in the flat list), but
    // note content isn't shipped to the browser - we have to round-trip
    // a debounced fetch to the `/search` endpoint and merge its matching
    // ids back in. Empty query short-circuits to an empty set so we
    // don't hit the endpoint on the way back to "no filter".
    const contentMatchIds = ref(new Set());
    const contentSearchLoading = ref(false);
    const runContentSearch = useDebounce(async (query) => {
        if (!query) {
            contentMatchIds.value = new Set();
            contentSearchLoading.value = false;
            return;
        }
        const { ok, payload } = await api.searchContent(query);
        contentMatchIds.value = new Set(
            ok ? (payload.ids ?? []).map((id) => Number(id)) : [],
        );
        contentSearchLoading.value = false;
    }, 300);
    watch(treeQuery, (q) => {
        const trimmed = q.trim();
        if (trimmed === "") {
            contentMatchIds.value = new Set();
            contentSearchLoading.value = false;
            return;
        }
        contentSearchLoading.value = true;
        runContentSearch(trimmed);
    });

    const { tree } = useNoteTree(
        notes,
        treeQuery,
        selectedTags,
        contentMatchIds,
    );

    /**
     * After a global tag rename / merge / delete, refresh the flat
     * note list, drop any selected-filter tags that vanished, and
     * reload the currently open note so its tags reflect the rewrite.
     */
    async function onTagsChanged() {
        await reloadCurrent();
        pruneMissingTags();
    }

    // ── Drag-drop ──────────────────────────────────────────────────
    // Drag-drop hierarchy editing is disabled while a filter is active,
    // since the visible tree is then a subset of the real one and a
    // drop would target a node hidden behind the filter.
    const dragEnabled = computed(
        () => treeQuery.value.trim() === "" && selectedTags.value.length === 0,
    );
    const dragDrop = useNoteDragDrop({ api, refreshList });

    // ── View mode (edit / split / preview) ─────────────────────────
    const { mode: viewMode } = useViewMode();
    const viewModeOptions = computed(() => {
        const all = [
            {
                value: "edit",
                icon: Pencil,
                label: t("notes.markdown.view.edit"),
            },
            {
                value: "split",
                icon: Columns,
                label: t("notes.markdown.view.split"),
            },
            {
                value: "preview",
                icon: Eye,
                label: t("notes.markdown.view.preview"),
            },
        ];
        // No split mode on mobile - a side-by-side editor + preview
        // wouldn't fit a phone-width viewport.
        return isMobile.value
            ? all.filter((opt) => opt.value !== "split")
            : all;
    });
    // If the persisted preference is "split" but we just resized into
    // mobile (or loaded directly on a phone), fall back to edit.
    watch(
        isMobile,
        (mobile) => {
            if (mobile && viewMode.value === "split") viewMode.value = "edit";
        },
        { immediate: true },
    );

    // ── Mobile-aware wrappers around selectNote / createNote so the
    //    sidebar drawer dismisses itself once the user picks something.
    async function selectNote(id) {
        await selectNoteRaw(id);
        if (isMobile.value) sidebarOpen.value = false;
    }
    async function createNote(parentId) {
        await createNoteRaw(parentId);
        if (isMobile.value) sidebarOpen.value = false;
    }

    /**
     * Open the note the address names.
     *
     * The page used to open on nothing until the reader picked from its own
     * tree. That tree is in the side menu now and every row is a link, so the
     * server has already answered which note this is - and a link somebody was
     * sent has to land on the note it names.
     */
    onMounted(() => {
        if (props.activeId) void selectNoteRaw(props.activeId);
    });

    // ── Auto-save status display ───────────────────────────────────
    const { relative: lastSavedRelative } = useRelativeTime(lastSavedAt);
    const { display: saveStatusDisplay } = useAutoSaveStatusDisplay(saveStatus);

    // ── Editor / preview split-pane resize ─────────────────────────
    const editorPaneRef = ref(null);
    const {
        size: editorWidth,
        startResize: startSplitResize,
        dragging: splitDragging,
    } = useResizable({
        key: "aurora.notes.markdown.editorWidth",
        defaultValue: 540,
        min: 240,
        max: 1200,
        axis: "x",
        getOrigin: () => editorPaneRef.value,
    });

    /**
     * Open the graph node-click handler - close the modal and switch
     * the editor to the picked note. Defined here so the SFC can bind
     * it as a single function rather than an inline lambda.
     */
    async function navigateFromGraph(id) {
        graphOpen.value = false;
        await selectNote(id);
    }

    return {
        // responsive
        isMobile,
        sidebarOpen,

        // backend
        api,
        tagsApi,

        // editor domain
        notes,
        selectedId,
        selectedNote,
        form,
        saving,
        deleting,
        saveStatus,
        lastSavedAt,
        pendingDelete,
        selectNote,
        createNote,
        requestDelete,
        cancelDelete,
        confirmDelete,
        onWikiLinkClick,
        onCheckboxToggle,
        onImageResize,

        // sidebar tree + tags
        tree,
        treeQuery,
        contentSearchLoading,
        availableTags,
        selectedTags,
        toggleTag,
        clearTags,
        onTagsChanged,

        // overlays
        sidePanelOpen,
        graphOpen,
        tagManagerOpen,

        // drag-drop
        dragEnabled,
        ...dragDrop,

        // view mode
        viewMode,
        viewModeOptions,

        // auto-save status
        lastSavedRelative,
        saveStatusDisplay,

        // split-pane resize
        editorPaneRef,
        editorWidth,
        startSplitResize,
        splitDragging,

        // cross-component glue
        navigateFromGraph,
    };
}
