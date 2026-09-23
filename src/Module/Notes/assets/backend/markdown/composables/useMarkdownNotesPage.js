import { ref, computed, onMounted, watch } from "vue";
import { Pencil, Eye, Columns } from "lucide-vue-next";
import { useMediaQuery } from "@/shared/composables/useMediaQuery.js";
import { useMarkdownNotesApi } from "@notes/backend/markdown/composables/useMarkdownNotesApi.js";
import { useNotesEditor } from "@notes/backend/markdown/composables/useNotesEditor.js";
import { useNoteTagFilter } from "@notes/backend/markdown/composables/useNoteTagFilter.js";
import { useMarkdownTagsApi } from "@notes/backend/markdown/composables/useMarkdownTagsApi.js";
import { useEditorPaneMode } from "@notes/backend/markdown/composables/useEditorPaneMode.js";
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
        bodyReady,
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

    // ── Mobile / responsive state ──────────────────────────────────
    // Tailwind's md breakpoint is 768px. Below that, the editor turns
    // into a single-column layout: the split view-mode is disabled and
    // the side-panel renders as a fullscreen overlay.
    const { matches: isMobile } = useMediaQuery("(max-width: 767px)");

    // The tag filter's own list is gone with the tree it filtered - the
    // library filters what it shows, and the panel searches the notebook.
    // What is left of it is the pruning a global rename needs.
    const { pruneMissingTags } = useNoteTagFilter(notes);

    /**
     * After a global tag rename / merge / delete, refresh the flat
     * note list, drop any selected-filter tags that vanished, and
     * reload the currently open note so its tags reflect the rewrite.
     */
    async function onTagsChanged() {
        await reloadCurrent();
        pruneMissingTags();
    }

    // ── Editor panes (edit / split / preview) ──────────────────────
    const { mode: viewMode } = useEditorPaneMode();
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

    async function selectNote(id) {
        await selectNoteRaw(id);
    }

    async function createNote(folderId) {
        await createNoteRaw(folderId);
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

        // backend
        api,
        tagsApi,

        // editor domain
        notes,
        selectedId,
        selectedNote,
        form,
        bodyReady,
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
        refreshList,
        onWikiLinkClick,
        onCheckboxToggle,
        onImageResize,

        // tags
        onTagsChanged,

        // overlays
        sidePanelOpen,
        graphOpen,
        tagManagerOpen,

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
