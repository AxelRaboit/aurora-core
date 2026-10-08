<script setup>
import { overlaysSettled } from '@/shared/composables/overlay/useBackButtonClose.js';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { useMarkdownNotesPage } from '@notes/suite/markdown/composables/useMarkdownNotesPage.js';
import { useNoteFoldersApi } from '@notes/suite/markdown/composables/useNoteFoldersApi.js';
import { sortSpaces, useNoteSpacesApi } from '@notes/suite/markdown/composables/noteSpaces.js';
import NoteSpaceSettingsModal from '@notes/suite/markdown/components/NoteSpaceSettingsModal.vue';
import AppBackLink from '@/shared/components/nav/AppBackLink.vue';
import NoteLibrary from '@notes/suite/markdown/components/NoteLibrary.vue';
import NotePreview from '@notes/suite/markdown/components/NotePreview.vue';
import NoteSidePanel from '@notes/suite/markdown/components/NoteSidePanel.vue';
import NoteRevisionsModal from '@notes/suite/markdown/components/NoteRevisionsModal.vue';
import { outlineOf } from '@notes/suite/markdown/composables/noteOutline.js';
import NoteTagManagerModal from '@notes/suite/markdown/components/NoteTagManagerModal.vue';
import NoteShareModal from '@notes/suite/markdown/components/NoteShareModal.vue';
import NoteCoverModal from '@notes/suite/markdown/components/NoteCoverModal.vue';
import NoteEditor from '@notes/suite/markdown/components/NoteEditor.vue';
import NoteGraph from '@notes/suite/markdown/components/NoteGraph.vue';
import NoteCreateModal from '@notes/suite/markdown/components/NoteCreateModal.vue';
import NoteCraftImportModal from '@notes/suite/markdown/components/NoteCraftImportModal.vue';
import { useRequest } from '@/shared/composables/http/suite/useRequest.js';
import { folderPath } from '@notes/suite/markdown/composables/noteBreadcrumb.js';
import AppButton from '@shared/components/action/AppButton.vue';
import AppSearchInput from '@shared/components/form/input/AppSearchInput.vue';
import AppTagsInput from '@shared/components/form/select/AppTagsInput.vue';
import AppModal from '@shared/components/overlay/AppModal.vue';
import AppModalFooter from '@shared/components/overlay/AppModalFooter.vue';
import AppTab from '@shared/components/nav/AppTab.vue';
import AppPageActions from '@shared/components/action/AppPageActions.vue';
import { computed, nextTick, onErrorCaptured, onMounted, onUnmounted, watch } from 'vue';
import { onPanelRequest, tellPanels } from '@/shared/nav/modulePanelBridge.js';
import { ChevronRight, Trash2, BookOpen, Copy, FileDown, History, Image, LayoutTemplate, PanelRightOpen, Printer, PanelRightClose, RefreshCw, Star, StarOff, Tag, TriangleAlert, X, Network, Share2 } from 'lucide-vue-next';
import AppNoData from '@shared/components/feedback/AppNoData.vue';
import "@notes/share/appearance.css";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { useFoldable } from "@notes/suite/markdown/composables/useFoldable.js";
import { withoutLeadingTitle } from "@notes/suite/markdown/composables/noteBody.js";

const { formatDateTime } = useDateFormat();

const props = defineProps({
    notes: { type: Array, default: () => [] },
    /** Every folder of the reader, flat, serialized with its counts. */
    folders: { type: Array, default: () => [] },
    /** The folder the address names, null on the root listing or on a note. */
    folderId: { type: Number, default: null },
    /** The chain the server resolved for that folder, root first. */
    breadcrumb: { type: Array, default: () => [] },
    /** The folder routes, in one object rather than in seven props. */
    folderPaths: { type: Object, required: true },
    /** The space routes, in one object like the folder ones. */
    spacePaths: { type: Object, default: () => ({}) },
    /** The readable spaces, one's own first, with the reader's role. */
    spaces: { type: Array, default: () => [] },
    canCreateSpace: { type: Boolean, default: false },
    /** The library's address, that is the notebook at its root. */
    libraryPath: { type: String, required: true },
    maxDepth: { type: Number, default: 8 },
    listPath: { type: String, required: true },
    showPath: { type: String, required: true },
    createPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
    movePath: { type: String, required: true },
    favoritePath: { type: String, default: '' },
    /** The reader's personal space: whatever lives elsewhere is shared. */
    personalSpaceId: { type: Number, default: null },
    reorderPath: { type: String, required: true },
    duplicatePath: { type: String, default: '' },
    templatePath: { type: String, default: '' },
    fromTemplatePath: { type: String, default: '' },
    /** Today's note, in the personal space's journal. */
    dailyPath: { type: String, default: '' },
    revisionsPath: { type: String, default: '' },
    revisionPath: { type: String, default: '' },
    revisionRestorePath: { type: String, default: '' },
    backlinksPath: { type: String, required: true },
    unlinkedMentionsPath: { type: String, required: true },
    graphPath: { type: String, required: true },
    /** The whole notebook as a zip, a single note as .md, and the way back. */
    exportPath: { type: String, required: true },
    exportOnePath: { type: String, required: true },
    importPath: { type: String, required: true },
    searchPath: { type: String, required: true },
    tagsListPath: { type: String, required: true },
    tagsRenamePath: { type: String, required: true },
    tagsMergePath: { type: String, required: true },
    tagsDeletePath: { type: String, required: true },
    sharesListPath: { type: String, required: true },
    sharesPreviewPath: { type: String, required: true },
    sharesCreatePath: { type: String, required: true },
    sharesRevokePath: { type: String, required: true },
    imageUploadPath: { type: String, required: true },
    /** The address that shows a single note, without the back-office. */
    readPath: { type: String, default: '' },
    /** The relay to Pexels for the banner: no image enters the GED. */
    coversSearchPath: { type: String, default: '' },
    /** Whether the installation has opened a Craft connection. */
    craftEnabled: { type: Boolean, default: false },
    /** The Craft import routes: the list, the import, the update. */
    craftPaths: { type: Object, default: () => ({}) },
    /** What the others have opened to the whole back-office. */
    imageMaxEdge: { type: Number, default: 2048 },
    imageQuality: { type: Number, default: 0.85 },
    /**
     * Client-extension hook - see `docs/aurora-core/dev/entity_extensibility_convention.md`.
     * Shape: `{ <fieldKey>: { default: <value> } }`. Each key is seeded into
     * the form, persisted on save (server-side the client's overridden DTO
     * factory hydrates the entity), and exposed back to the parent through
     * the `extra-form-fields` slot's scoped `form` binding.
     */
    extraFields: { type: Object, default: () => ({}) },
    /** The note this URL is. Decided by the server, not by the browser. */
    activeId: { type: Number, default: null },
});

const { t } = useI18n();

const {
    isMobile,
    api,
    tagsApi,
    notes,
    selectedId,
    selectedNote,
    form,
    bodyReady,
    deleting,
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
    onTagsChanged,
    sidePanelOpen,
    graphOpen,
    tagManagerOpen,
    viewMode,
    viewModeOptions,
    lastSavedRelative,
    saveStatusDisplay,
    editorPaneRef,
    editorWidth,
    startSplitResize,
    splitDragging,
    navigateFromGraph,
    refreshList,
    flushPendingSave,
    conflict,
    saveAnyway,
    reloadDiscarding,
} = useMarkdownNotesPage(props, t);

// Local to this component rather than folded into `useMarkdownNotesPage`:
// sharing is opened from the toolbar and closed by the modal, and nothing in
// the page composable reads it.
const shareModalOpen = ref(false);

/**
 * The banner and the appearance: a single door for "what this note looks
 * like".
 *
 * Both are written into the form, so the autosave carries them like the
 * rest: picking an image has no button to confirm.
 */
const coverModalOpen = ref(false);

const cover = computed(() => ({
    url: form.value.coverUrl,
    creditName: form.value.coverCreditName,
    creditUrl: form.value.coverCreditUrl,
    position: form.value.coverPosition ?? 50,
}));

function chooseCover(photo) {
    form.value.coverUrl = photo.url;
    form.value.coverCreditName = photo.creditName;
    form.value.coverCreditUrl = photo.creditUrl;
}

function removeCover() {
    form.value.coverUrl = null;
    form.value.coverCreditName = null;
    form.value.coverCreditUrl = null;
    form.value.coverPosition = 50;
}

// `plain` sets no class: a note without styling follows the person's light
// or dark theme, and a class that repainted it in hard colours would take
// that choice away.
const lookClass = computed(() =>
    'plain' === form.value.appearance || !form.value.appearance
        ? ''
        : `note-look note-look-${form.value.appearance}`,
);

/**
 * The body as the preview shows it: without the title repeated at the top.
 *
 * The checkbox indexes follow: removing a heading changes neither the number
 * nor the order of the boxes, so ticking in the preview always writes to the
 * right line of the source.
 */
const previewBody = computed(() =>
    withoutLeadingTitle(form.value.content, form.value.title),
);

/**
 * Make the open note visible to the team, or close it again.
 *
 * The toggle only existed in a card's menu, so from the library: from the
 * note itself, the only thing that looked like sharing was the public link,
 * which is something else entirely. Two different gestures carried the same
 * word, and one was missing where it is expected.
 */
const readHref = computed(() =>
    selectedId.value && props.readPath
        ? props.readPath.replace('__id__', String(selectedId.value))
        : '',
);

/**
 * Tags fold into an icon, like the library search.
 *
 * They took up a whole line of the header all the time, that is one line
 * less for the text, for something touched once when the note is born and
 * never again after. Folded, the icon carries their count and names them in
 * a tooltip: one knows there are some, and which, without having them in
 * view.
 *
 * Folding on focus loss only applies when the field is empty, as for the
 * search: closing on a half-typed word would make it disappear.
 */
const {
    open: tagsOpen,
    box: tagsBox,
    reveal: openTags,
    fold: foldTags,
} = useFoldable();

function toggleTags() {
    if (tagsOpen.value) {
        foldTags();

        return;
    }

    void openTags();
}

function closeTagsIfIdle(event) {
    const field = event.currentTarget?.querySelector('input');

    if (field && '' !== field.value.trim()) return;

    foldTags();
}

const tagsLabel = computed(() => {
    // `form` is a `ref`: the model is read through `.value`, where the
    // template unwraps it on its own. Without this the tooltip stayed on the
    // empty-field text while the badge counted right.
    const tags = form.value.tags ?? [];

    return tags.length
        ? `${t('notes.markdown.tags.summary', { count: tags.length })} : ${tags.join(', ')}`
        : t('notes.markdown.tags.add_placeholder');
});

/**
 * What breaks must show.
 *
 * An error in a child component empties its area without a word: Vue logs
 * it to the console and renders nothing. Twice Axel got a large white frame
 * as the only message, and the only way to know what had happened was to
 * open the developer tools. A page that fails must say so to whoever is
 * looking at it, and say what.
 */
const crashed = ref(null);

onErrorCaptured((error) => {
    crashed.value = error;

    // Logged anyway: the message on screen serves the person, the trace
    // serves whoever fixes it.
    console.error('[notes] la page a échoué', error);

    return false;
});

/**
 * The folders, and the back and forth between the library and the editor.
 *
 * A single page mounts both: opening a note from the library writes its
 * address and loads its text, without reloading the document. The browser's
 * back button returns the library, because the address being left is a real
 * address and not an internal state.
 */
const foldersApi = useNoteFoldersApi(props.folderPaths);
const folders = ref([...props.folders]);

const spacesApi = useNoteSpacesApi(props.spacePaths);
const spaces = ref(sortSpaces(props.spaces));

async function refreshSpaces() {
    const { ok, payload } = await spacesApi.list();

    if (ok) spaces.value = sortSpaces(payload.spaces ?? []);
}

/** The space whose settings are open, or nothing. */
const settingsSpaceId = ref(null);

/** After a settings change: the space, and what it holds, may have changed. */
async function onSpaceChanged() {
    await Promise.all([refreshSpaces(), refreshList(), refreshFolders()]);
}

/**
 * The library, when it is there.
 *
 * The menu panel talks to this application, mounted on every page of the
 * module; the library is only mounted when no note is open. When it is
 * missing, a request from the panel becomes a navigation, which is the
 * honest answer: we leave the editor to go and look.
 */
const libraryRef = ref(null);

/**
 * The folder in view, which is not always the one of the initial address:
 * the library navigates without reloading.
 */
const openFolderId = ref(props.folderId);

/** In the reader's favourites: the menu says the opposite of the state. */
const isFavorite = computed(() => Boolean(selectedNote.value?.favoritedAt));

/** Whether the open note can be written: its space says so (`canWrite`). */
const canEditSelected = computed(() => {
    const spaceId = selectedNote.value?.spaceId ?? null;

    return Boolean(spaces.value.find((space) => Number(space.id) === Number(spaceId))?.canWrite ?? true);
});

/**
 * The Craft import: the space (and the folder) where the note will land, or
 * nothing.
 *
 * Opened from a space's menu, in the panel; the modal loads itself on open.
 */
const craftImport = ref(null);

const { request: craftRequest } = useRequest();

/** The imported note opens right away: it is what we came for. */
async function onCraftImported(payload) {
    await overlaysSettled();
    await refreshList();
    if (payload?.note?.id) await openNote(payload.note.id);
}

/**
 * Reset the open note to the current version of its Craft document.
 *
 * Confirmed first: the text is replaced. The previous state goes into the
 * note's history, from where it can be brought back.
 */
const craftRefreshPending = ref(false);
const craftRefreshing = ref(false);

async function refreshFromCraft() {
    const id = selectedId.value;

    if (!id || craftRefreshing.value || !props.craftPaths.refresh) return;

    craftRefreshing.value = true;

    try {
        const payload = await craftRequest(props.craftPaths.refresh.replace('__id__', String(id)));

        if (payload) {
            craftRefreshPending.value = false;
            await overlaysSettled();
            await refreshList();
            await openNote(id);
            toast.success(t('notes.craft.import.refreshed'));
        }
    } finally {
        craftRefreshing.value = false;
    }
}

/**
 * What the note's menu holds: the gestures done once.
 *
 * Exporting, sending a link, picking an image, opening the graph, pinning
 * it: each is done once per note, while view modes, tags and links are
 * touched while writing. All twelve on one line left no room for the title.
 */
/** The version history of the open note. */
const historyOpen = ref(false);

/** The reader, from the mode selector: the text is saved before leaving. */
async function openReading() {
    await flushPendingSave();
    window.location.assign(readHref.value);
}

const noteActions = computed(() => {
    const actions = [
        {
            key: "read",
            title: t('notes.markdown.read.open'),
            icon: BookOpen,
            href: readHref.value,
        },
        {
            // The reader prints: the note without the editor around it, and
            // the dialog opens by itself. "Enregistrer en PDF" is in it.
            key: "print",
            title: t('notes.markdown.print.open'),
            icon: Printer,
            onSelect: async () => {
                if (!readHref.value) return;

                await flushPendingSave();
                // The menu's history back, still on its way, would cancel the
                // navigation: we leave once it has completed.
                await overlaysSettled();
                window.location.assign(`${readHref.value}?print=1`);
            },
        },
        {
            // The tag count was a badge on the icon; in a menu, the label
            // names them, which says more than a number.
            key: "tags",
            title: tagsLabel.value,
            icon: Tag,
            onSelect: () => toggleTags(),
        },
        {
            key: "cover",
            title: t('notes.markdown.cover.title'),
            icon: Image,
            onSelect: () => {
                coverModalOpen.value = true;
            },
        },
        {
            key: "share-link",
            title: t('notes.markdown.share.button'),
            icon: Share2,
            onSelect: () => {
                shareModalOpen.value = true;
            },
        },
        {
            // Favourites are one's own: the note is pinned from here, where
            // one is when thinking of coming back to it. Only the library
            // offered it, and nobody found it there.
            key: "favorite",
            title: isFavorite.value
                ? t('notes.markdown.library.unpin')
                : t('notes.markdown.library.pin'),
            icon: isFavorite.value ? StarOff : Star,
            onSelect: () => void toggleFavorite('note', selectedId.value),
        },
        {
            key: "graph",
            title: t('notes.markdown.graph.open'),
            icon: Network,
            onSelect: () => {
                graphOpen.value = true;
            },
        },
        {
            key: "history",
            title: t('notes.markdown.revisions.open'),
            icon: History,
            onSelect: async () => {
                // What is waiting to be saved goes first: comparing with a
                // text the server does not have yet would be misleading.
                await flushPendingSave();
                // The menu closed during the wait, and its history back has
                // not completed yet: the window opened before would push its
                // entry under that back.
                await overlaysSettled();
                historyOpen.value = true;
            },
        },
        // Only on a note copied from a Craft document, and for whoever can
        // write it: elsewhere, there is nothing to take back from anywhere.
        ...(props.craftEnabled && selectedNote.value?.craftDocumentId && canEditSelected.value
            ? [{
                key: "craft-refresh",
                title: t('notes.craft.import.refresh'),
                icon: RefreshCw,
                onSelect: async () => {
                    await flushPendingSave();
                    await overlaysSettled();
                    craftRefreshPending.value = true;
                },
            }]
            : []),
        {
            key: "duplicate",
            title: t('notes.markdown.duplicate.action'),
            icon: Copy,
            onSelect: () => void duplicateNote(),
        },
        {
            // A template stays a note: it is read and edited like the
            // others, and "Ajouter" offers to start from it.
            key: "template",
            title: selectedNote.value?.template
                ? t('notes.markdown.template.unmark')
                : t('notes.markdown.template.mark'),
            icon: LayoutTemplate,
            onSelect: () => void toggleTemplate(),
        },
        {
            key: "export",
            title: t('notes.markdown.export.one'),
            icon: FileDown,
            onSelect: () => exportOne(selectedId.value),
        },
        // Move the note to the trash. The gesture existed and could only be
        // reached from the library: from the note itself, the most obvious
        // place, there was nothing.
        {
            key: "delete",
            title: t('notes.markdown.delete'),
            icon: Trash2,
            color: "rose",
            onSelect: () => requestDelete(),
        },
    ];

    return actions;
});


const previewPaneRef = ref(null);

/**
 * Go to the heading clicked in the outline.
 *
 * In the preview, the rendered heading carrying the same text (the n-th if
 * there are several); while writing, the cursor at the start of its line.
 * The first heading may be missing from the preview, which does not repeat
 * the note's title: we then go back to the top.
 */
function jumpToHeading(heading) {
    const normalise = (text) => String(text ?? '').replace(/\s+/g, ' ').trim().toLowerCase();
    const before = outlineOfContent().filter((one) => one.line < heading.line && normalise(one.text) === normalise(heading.text)).length;

    const preview = previewPaneRef.value;
    if (preview && 'edit' !== viewMode.value) {
        const matches = [...preview.querySelectorAll('h1, h2, h3, h4, h5, h6')].filter((headingElement) => normalise(headingElement.textContent) === normalise(heading.text));
        const target = matches[before] ?? null;

        // Only the pane scrolls: `scrollIntoView` also took the page along,
        // and the note's path went under the header.
        const top = target
            ? preview.scrollTop + target.getBoundingClientRect().top - preview.getBoundingClientRect().top - 12
            : 0;
        preview.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
    }

    const textarea = editorPaneRef.value?.querySelector('textarea');
    if (textarea && 'preview' !== viewMode.value) {
        const lines = String(form.value.content ?? '').split('\n');
        const offset = lines.slice(0, heading.line).reduce((total, line) => total + line.length + 1, 0);

        textarea.focus({ preventScroll: true });
        textarea.setSelectionRange(offset, offset);
        const pane = editorPaneRef.value;
        pane.scrollTop = Math.max(0, textarea.offsetTop + (heading.line / Math.max(1, lines.length)) * textarea.scrollHeight - 48);
    }

    // On a phone, the panel covers the note: we close it to see the note.
    if (isMobile.value) sidePanelOpen.value = false;
}

function outlineOfContent() {
    return outlineOf(form.value.content ?? '');
}


/** A version has just been restored: the note reloads from the server. */
async function onRevisionRestored(note) {
    historyOpen.value = false;
    // The window removes its history entry with a delay: reopening the note
    // before that pushes the address under that back, which would then go
    // back one step too far and leave the page.
    await overlaysSettled();
    await refreshList();
    await openNote(note.id);
    toast.success(t('notes.markdown.revisions.restored'));
}

/** A copy of the open note, right below it, opened right away. */
async function duplicateNote() {
    if (!selectedId.value) return;

    await flushPendingSave();

    const { ok, reported, payload } = await api.duplicate(selectedId.value);

    if (!ok) {
        if (!reported) toast.error(t('notes.markdown.duplicate.failed'));

        return;
    }

    await refreshList();
    await openNote(payload.note.id);
    toast.success(t('notes.markdown.duplicate.done'));
}

/**
 * Today's note, opened like a note just created. The server finds the one
 * already written today or writes it, and the "Journal" folder with it the
 * first time: the folders are reloaded along with the notes.
 */
const dailyOpening = ref(false);

async function openDailyNote() {
    if (dailyOpening.value) return;

    dailyOpening.value = true;
    const { ok, reported, payload } = await api.daily();
    dailyOpening.value = false;

    if (!ok) {
        if (!reported) toast.error(t('notes.markdown.daily.failed'));

        return;
    }

    await Promise.all([refreshFolders(), refreshList()]);
    await openNote(payload.note.id);
}

/** Make the open note a template, or turn it back into an ordinary one. */
async function toggleTemplate() {
    if (!selectedId.value) return;

    const next = !selectedNote.value?.template;
    const { ok, reported } = await api.markTemplate(selectedId.value, next);

    if (!ok) {
        if (!reported) toast.error(t('notes.markdown.errors.save_failed'));

        return;
    }

    const row = notes.value.find((one) => one.id === selectedId.value);
    if (row) row.template = next;

    toast.success(t(next ? 'notes.markdown.template.marked' : 'notes.markdown.template.unmarked'));
}

/** The templates one can read, for "Ajouter". */
const templates = computed(() => notes.value.filter((one) => one.template));

/** Add to favourites, or remove: a note or a folder. */
async function toggleFavorite(kind, id) {
    if (!id) return;

    const { ok, reported } = 'folder' === kind ? await foldersApi.favorite(id) : await api.favorite(id);

    if (!ok) {
        if (!reported) toast.error(t('notes.markdown.library.pin_failed'));

        return;
    }

    await ('folder' === kind ? refreshFolders() : refreshList());
}

function folderUrlFor(id) {
    return props.folderPaths.show.replace('__id__', String(id));
}

/**
 * Go back to the library, and land where asked.
 *
 * It and the editor live in the same application: leaving one for the other
 * is a change of display, not a navigation. The panel triggered a full
 * reload when a note was open, which threw away the scroll, the selection,
 * and the tree's expanded state only to come back to the same place.
 *
 * @returns {Promise<void>} resolved once the library is mounted
 */
async function showLibrary(folderId = null) {
    selectedId.value = null;

    // The library only exists once the editor is removed: without this
    // tick, the reference is still null and the order is lost.
    await nextTick();

    if (libraryRef.value) {
        libraryRef.value.openFolder(folderId ?? null);

        return;
    }

    // Fallback: if it did not mount, the address stays the truth.
    window.location.assign(
        null === folderId || undefined === folderId
            ? props.libraryPath
            : folderUrlFor(folderId),
    );
}

async function refreshFolders() {
    const { ok, payload } = await foldersApi.list();
    if (ok) folders.value = payload.folders ?? [];
}

function noteUrlFor(id) {
    return props.showPath.replace('__id__', String(id));
}

function noteExportUrlFor(id) {
    return props.exportOnePath.replace('__id__', String(id));
}

async function openNote(id) {
    try {
        window.history.pushState({ noteId: id }, '', noteUrlFor(id));
    } catch {
        // Sandboxed frame: the note opens anyway, only the address does not
        // follow.
    }

    await selectNote(id);
}

function onHistoryPop() {
    // The library has its own listener for the folder; this one only
    // decides between "a note" and "the list".
    const match = /\/markdown\/(\d+)(?:$|[?#])/.exec(window.location.pathname);

    if (match) {
        void selectNote(Number(match[1]));

        return;
    }

    selectedId.value = null;
}

async function onLibraryChanged() {
    await Promise.all([refreshFolders(), refreshList()]);

    // A note missing from the refreshed list went to the trash, alone or
    // with its folder. Keeping it open would let the autosave write into a
    // deleted note, and the reader would believe they were working on
    // something that no longer exists.
    if (selectedId.value && !notes.value.some((note) => note.id === selectedId.value)) {
        backToLibrary();
    }
}

/**
 * Delete, then make the address right.
 *
 * With the open note deleted, the screen goes back to the library but the
 * address stayed the note's: refreshing the page answered 404. The address
 * is rewritten once the confirmation dialog has closed, not before: the
 * dialog holds a history entry of its own, and rewriting while it is there
 * writes over that entry, which its own back would erase right away.
 */
async function confirmDeleteAndLeave() {
    const target = pendingDelete.value;
    const wasOpen = null !== target && selectedId.value === target.id;

    await confirmDelete();

    // Still pending: the server refused, the note is still there.
    if (!wasOpen || null !== pendingDelete.value) return;

    await overlaysSettled();

    const folderId = openFolderId.value;

    try {
        window.history.replaceState({ folderId }, '', folderId ? folderUrlFor(folderId) : props.libraryPath);
    } catch {
        // Sandboxed frame: the library is shown, only the address does not follow.
    }
}

function backToLibrary() {
    const folderId = openFolderId.value;
    const url = folderId ? folderUrlFor(folderId) : props.libraryPath;

    try {
        window.history.pushState({ folderId }, '', url);
    } catch {
        // Same: the back happens, the address does not follow.
    }

    selectedId.value = null;
}

/**
 * The panel's half of the contract.
 *
 * Every row action the tree used to perform itself is now an ask from the side
 * menu, answered here - selecting, creating a child, deleting, and the whole
 * drag. The handlers are the ones the aside called; the panel forwards their
 * arguments untouched, DOM event included, because both applications run in one
 * JavaScript context and it is the same event object either way.
 *
 * And the traffic goes both ways: `notes:changed` is how a note created in this
 * editor reaches the tree. Without it the panel showed the list it fetched on
 * arrival until the reader reloaded the page.
 */
/**
 * Take the notebook away, and bring it back.
 *
 * The export is a navigation, not a request: the browser knows how to
 * receive a file and store it, and going through `fetch` would force keeping
 * a whole zip in memory to hand it back to a fabricated link.
 */
function exportUrl({ spaceId = null, folderId = null } = {}) {
    // A single space when the panel asks for it from its header, a single
    // folder from its menu.
    if (null != folderId) return `${props.exportPath}?folderId=${encodeURIComponent(String(folderId))}`;
    if (null != spaceId) return `${props.exportPath}?spaceId=${encodeURIComponent(String(spaceId))}`;

    return props.exportPath;
}

function exportAll(spaceId = null) {
    window.location.assign(exportUrl({ spaceId }));
}

function exportOne(id) {
    window.location.assign(props.exportOnePath.replace("__id__", String(id)));
}

const importInput = ref(null);

/** The space root to import into, when the import starts from its header. */
const importSpaceId = ref(null);

function askForFiles(spaceId = null) {
    importSpaceId.value = null == spaceId ? null : Number(spaceId);
    importInput.value?.click();
}

async function onImportFiles(event) {
    const files = [...(event.target.files ?? [])];
    // Reset right away: otherwise, reimporting the same file would emit
    // nothing, since the field's value has not changed.
    event.target.value = "";

    if (!files.length) return;

    const form = new FormData();
    files.forEach((file) => form.append("files[]", file));

    // At the root of the requested space; otherwise in the open folder,
    // where one is looking.
    if (null !== importSpaceId.value) form.append("spaceId", String(importSpaceId.value));
    else if (openFolderId.value) form.append("folderId", String(openFolderId.value));
    importSpaceId.value = null;

    const { ok, payload } = await api.import(form);

    if (!ok) {
        if (payload?.errors) toast.error(Object.values(payload.errors)[0]);

        return;
    }

    await Promise.all([refreshList(), refreshFolders()]);
    toast.success(t("notes.markdown.import.done", { count: payload.created ?? 0 }));
}

// ── Add, move: what the panel asks for ─────────────────────────────

const addModal = ref(null);
const addSaving = ref(false);

/**
 * Open the add modal: in a folder (its id), or at the root of a space
 * (`{ spaceId }`), or without saying anything - the root of one's own.
 */
function openAdd(target) {
    if (null !== target && 'object' === typeof target) {
        addModal.value = {
            folderId: null == target.folderId ? null : Number(target.folderId),
            spaceId: null == target.spaceId ? null : Number(target.spaceId),
        };

        return;
    }

    addModal.value = { folderId: null == target ? null : Number(target), spaceId: null };
}

/**
 * Create what the modal asks for, where it says.
 *
 * A note opens right away, title included: it has just been named, one
 * wants to write in it. A folder stays where it is created, and the panel
 * shows it - several folders are often filed in a row.
 */
async function submitAdd({ kind, name, color, spaceId, access, defaultRole, templateId = null }) {
    const folderId = addModal.value?.folderId ?? null;
    // A folder imposes its space; without a folder, the chosen root.
    const rootSpaceId = null === folderId ? spaceId ?? null : null;

    addSaving.value = true;

    const request = {
        space: () => spacesApi.create({ name, color, access, defaultRole }),
        folder: () => foldersApi.create(name, folderId, color, rootSpaceId),
        // From a template, the server copies its text; the typed name stays
        // the title, the template's one otherwise.
        note: () => (null !== templateId
            ? api.fromTemplate(templateId, { folderId, spaceId: rootSpaceId, title: name })
            : api.create({ folderId, spaceId: rootSpaceId, title: name, content: '' })),
    }[kind];
    const { ok, reported, payload } = await request();

    addSaving.value = false;

    if (!ok) {
        if (!reported) {
            const failed = {
                space: 'notes.markdown.spaces.errors.save_failed',
                folder: 'notes.markdown.folders.errors.create_failed',
                note: null !== templateId ? 'notes.markdown.template.failed' : 'notes.markdown.errors.create_failed',
            }[kind];

            toast.error(t(failed));
        }

        return;
    }

    addModal.value = null;

    if ('space' === kind) {
        await refreshSpaces();
        toast.success(t('notes.markdown.spaces.created'));

        // A space opened to chosen people is useless as long as nobody is in
        // it: we open right away what is needed to add them.
        if ('members' === access) settingsSpaceId.value = Number(payload.space.id);

        return;
    }

    if ('folder' === kind) {
        await refreshFolders();
        toast.success(t('notes.markdown.folders.created'));

        return;
    }

    await refreshList();
    await openNote(payload.note.id);
}

/**
 * Write a drop: change folder if needed, then the order.
 *
 * The move first: it goes through the route that refuses a loop or one
 * level too deep, and that route must decide. The reordering comes next,
 * between siblings of the same folder, where it cannot break anything;
 * folders and notes share a single order there.
 */
async function applyDropPlan(plan) {
    if (!plan?.id) return;

    const isFolder = 'folder' === plan.kind;

    // The open note is being filed: what is waiting to be saved goes first,
    // then its row takes its new folder right away. The autosave sends the
    // folder from the list, and a send that left with the old one, after the
    // move, put the note back where it was.
    const moves = plan.fromFolderId !== plan.folderId || (plan.fromSpaceId ?? null) !== (plan.spaceId ?? null);

    if (!isFolder && plan.id === selectedId.value && moves) {
        await flushPendingSave();

        const row = notes.value.find((one) => one.id === plan.id);
        if (row) {
            row.folderId = plan.folderId;
            if (null != plan.spaceId) row.spaceId = plan.spaceId;
        }
    }

    if (moves) {
        const { ok, reported, payload } = isFolder
            ? await foldersApi.move(plan.id, plan.folderId, plan.spaceId ?? null)
            : await api.move(plan.id, plan.folderId, plan.spaceId ?? null);

        if (!ok) {
            if (!reported) {
                const failed = 'refused' === payload?.error
                    ? 'notes.markdown.folders.errors.move_refused'
                    : 'notes.markdown.folders.errors.move_failed';

                toast.error(t(failed, { max: props.maxDepth }));
            }

            await Promise.all([refreshList(), refreshFolders()]);

            return;
        }
    }

    // A single order for the folder's folders and notes: each gets its rank
    // in the mixed list, through the route of its kind.
    const folderEntries = [];
    const noteEntries = [];
    plan.order.forEach((entry, position) => {
        if ('folder' === entry.kind) {
            folderEntries.push({ id: entry.id, parentId: plan.folderId, position });
        } else {
            noteEntries.push({ id: entry.id, folderId: plan.folderId, position });
        }
    });

    await Promise.all([
        folderEntries.length ? foldersApi.reorder(folderEntries) : null,
        noteEntries.length ? api.reorder(noteEntries) : null,
    ]);

    await Promise.all([refreshList(), refreshFolders()]);

    if (moves) toast.success(t('notes.markdown.folders.moved'));
}

/**
 * The open note's path, from the root.
 *
 * A note only had its title above it: one no longer knew which folder one
 * was writing in, nor how to go back up to it, without searching the panel.
 * Each step leads back to its folder.
 */
const notePath = computed(() => folderPath(folders.value, selectedNote.value?.folderId ?? null));

const PANEL_INTENTS = {
    select: (id) => openNote(id),
    'space-settings': (id) => {
        settingsSpaceId.value = Number(id);
    },
    favorite: ({ kind, id }) => toggleFavorite(kind, id),
    export: (spaceId) => exportAll(spaceId ?? null),
    'export-folder': (folderId) => window.location.assign(exportUrl({ folderId })),
    import: (spaceId) => askForFiles(spaceId ?? null),
    'craft-import': (spaceId) => {
        if (props.craftEnabled) craftImport.value = { spaceId: spaceId ?? null, folderId: null };
    },
    create: (folderId) => createNote(folderId ?? null),
    delete: (note) => requestDelete(note),
    'open-folder': async (id) => {
        if (libraryRef.value) {
            libraryRef.value.openFolder(id);

            return;
        }

        await showLibrary(id);
    },
    // A tag cuts across the filing: it is viewed in the library, never in
    // the editor, so we bring the reader back there first.
    'filter-tag': async (tag) => {
        if (!libraryRef.value) await showLibrary(null);

        libraryRef.value?.filterByTag(tag);
    },
    'create-folder': async (parentId) => {
        if (!libraryRef.value) await showLibrary(parentId ?? null);

        libraryRef.value?.askForFolderName(null, parentId ?? null);
    },
    'delete-folder': async (folder) => {
        if (!libraryRef.value) await showLibrary(folder?.parentId ?? null);

        libraryRef.value?.askToDelete(folder);
    },
    // The rename modal is the library's, not a second one: it also writes
    // the colour, and two modals for the same gesture end up no longer
    // saying the same thing.
    'rename-folder': async (folder) => {
        if (!libraryRef.value) await showLibrary(folder?.parentId ?? null);

        libraryRef.value?.askForFolderName(folder);
    },
    // Renaming a note means writing its title: we open it and put the cursor
    // in it. No modal for a field that is already on the page.
    'rename-note': async (note) => {
        await openNote(note.id);

        await nextTick();
        document.querySelector('[data-note-title]')?.focus();
    },
    // The panel's drag: the target is a folder row, and what is moved
    // travels in the event's clipboard, so the library knows what to do
    // with it without being told again.
    drop: (folder, event) => libraryRef.value?.dropInto(Number(folder.id), event),
    // The panel's "+": a note or a folder, as chosen, where one clicked. The
    // modal lives here and not in the library, so that it also works when a
    // note is open.
    add: (target) => openAdd(target ?? null),
    // A drop in the panel, already computed there: where to file, in which
    // order. We write it whatever screen is shown.
    move: (plan) => applyDropPlan(plan),
};

const stopListening = [];

function announce() {
    tellPanels('notes:changed', {
        notes: notes.value,
        folders: folders.value,
        spaces: spaces.value,
        canCreateSpace: props.canCreateSpace,
        craftEnabled: props.craftEnabled,
        selectedId: selectedId.value,
        folderId: openFolderId.value,
        noteId: selectedId.value,
    });
}

onMounted(() => {
    for (const [intent, run] of Object.entries(PANEL_INTENTS)) {
        stopListening.push(
            onPanelRequest(`notes:${intent}`, ({ args: intentArguments = [] }) => run(...intentArguments)),
        );
    }

    // The panel fetches on arrival too, but it may well have done so before
    // this list existed; saying it once on mount settles which of the two wins.
    announce();
    stopListening.push(watch(notes, announce, { deep: true }));
    stopListening.push(watch(folders, announce, { deep: true }));
    stopListening.push(watch(spaces, announce, { deep: true }));
    stopListening.push(watch(openFolderId, announce));
    stopListening.push(watch(selectedId, announce));

    window.addEventListener('popstate', onHistoryPop);
    stopListening.push(() => window.removeEventListener('popstate', onHistoryPop));
});

onUnmounted(() => {
    while (stopListening.length) stopListening.pop()();
});

</script>

<template>
    <!-- The height is what is left, expressed with the values that make it:
         the top bar carries its own in `--aurora-topbar`, and the content
         area its top and bottom margins in `--aurora-page-margin`, the same
         value as its side margins. The `8rem`
         written here before was an estimate, off by three dozen pixels: the
         card went past the bottom of the screen, so the end of a long note
         was read by scrolling the whole page. The `4rem` that followed was
         right, but copied: it was the `py-8` of `<main>` written elsewhere,
         and would have become wrong the day that margin changed - that is,
         today. `dvh` rather than `vh` so that a mobile browser's bar
         counts. -->
    <!-- The field that receives imported files: invisible, triggered by the
         panel's button. An `input[type=file]` is not drawn. -->
    <input
        ref="importInput"
        type="file"
        class="hidden"
        multiple
        accept=".md,.markdown,.zip,text/markdown,application/zip"
        v-on:change="onImportFiles"
    >

    <!-- One column, and the card takes what is left.
         The way back lives **above** the note, not inside it: a note can
         carry its own background - paper, slate, night - and a navigation
         link set on that background reads badly, or not at all when its
         hover takes the back-office ink colour. It was taken out of the card
         rather than recoloured, because it does not belong to the note: it
         says how to leave it. `flex-1 min-h-0` on the card avoids writing its
         height by subtracting the link's, a number that would be wrong at the
         first change of font size. -->
    <div class="flex h-[calc(100dvh-var(--aurora-topbar)-var(--aurora-page-margin)*2)] flex-col gap-1.5">
        <!-- The breadcrumb extends the back link: the root, then each folder
             down to the note, each one clickable. A note only had its title
             above it, and one no longer knew which folder one was writing in
             without searching the panel for it. Outside the card for the
             same reason as the link. -->
        <nav
            v-if="selectedNote && !crashed"
            data-note-breadcrumb
            class="flex min-w-0 flex-wrap items-center gap-x-1 gap-y-0.5 text-xs text-muted"
            :aria-label="t('notes.markdown.breadcrumb')"
        >
            <!-- A real address, so the library opens in a new tab on a
                 modified click; a plain click returns to it without
                 reloading the page. -->
            <AppBackLink
                class="mr-1 shrink-0"
                :href="libraryPath"
                :label="t('notes.markdown.library.title')"
                v-on:back="backToLibrary"
            />
            <template v-for="crumb in notePath" :key="crumb.id">
                <ChevronRight class="h-3 w-3 shrink-0" :stroke-width="2" />
                <a
                    :href="folderUrlFor(crumb.id)"
                    data-note-crumb
                    class="max-w-[12rem] truncate rounded px-1 py-0.5 no-underline transition-colors hover:bg-surface-2 hover:text-primary"
                    :style="crumb.color ? { color: crumb.color } : null"
                    v-on:click.prevent="showLibrary(crumb.id)"
                >{{ crumb.name || t('notes.markdown.folders.untitled') }}</a>
            </template>
            <ChevronRight class="h-3 w-3 shrink-0" :stroke-width="2" />
            <span class="min-w-0 truncate px-1 text-secondary" aria-current="page">
                {{ form.title || t('notes.markdown.untitled') }}
            </span>
        </nav>

        <div class="aurora-card relative flex min-h-0 flex-1 overflow-hidden">
            <!-- No tree column and no drawer of its own: the notes are in the
             side menu's panel now, on every page of the module rather than
             this one, and the menu already has a drawer on small screens.
             Two drawers was two gestures to learn for the same thing. -->

            <!-- Editor pane -->
            <!-- `min-h-0` on the whole column, and not only `overflow-auto` at
             the bottom: a flex child is `min-height: auto`, so it refuses to
             shrink below the height of its content. A long note pushed the
             column past the card instead of scrolling the pane, and the end
             of the text went off screen. It is the vertical counterpart of
             what the comment on the two panes already says for the width. -->
            <section class="flex-1 flex flex-col min-w-0 min-h-0">
                <div v-if="crashed" class="flex flex-1 items-center justify-center p-4">
                    <AppNoData
                        :message="t('notes.markdown.errors.crashed')"
                        :hint="String(crashed?.message ?? crashed)"
                        :icon="TriangleAlert"
                    />
                </div>

                <div v-else-if="selectedNote" class="flex-1 flex flex-col min-h-0" :class="lookClass">
                    <!-- The banner, when the note has one. The image lives
                     with whoever hosts it: nothing entered the media library,
                     and if it disappears from there another one is picked.

                     Higher in the reading view than in the editor, and on
                     purpose: here it shares the column with the text being
                     written, there the page scrolls and has only the note to
                     show. -->
                    <figure v-if="form.coverUrl" class="relative m-0 shrink-0">
                        <img
                            :src="form.coverUrl"
                            alt=""
                            class="h-40 w-full object-cover sm:h-56"
                            :style="{ objectPosition: `50% ${form.coverPosition ?? 50}%` }"
                        >
                        <figcaption
                            v-if="form.coverCreditName"
                            class="absolute bottom-0 right-0 bg-black/40 px-2 py-0.5 text-2xs text-white"
                        >
                            {{ t('notes.markdown.cover.credit', { name: form.coverCreditName }) }}
                        </figcaption>
                    </figure>

                    <!-- The title and the commands on a single line: alone,
                         the title left half the header empty and pushed the
                         note down a row. The wrapping is the menu panel's -
                         the title asks for fifteen rem, the commands drop
                         down by themselves when space really runs out. -->
                    <header class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-line p-3">
                        <!-- The title is written like a title, not like a
                             form field.

                             A box with its border and its background said
                             "data to enter here" above a document that is
                             free text: two registers for the same page.
                             Craft and Notion write the title in the page,
                             large, and it is what is read first.

                             It is not a borderless `AppInput` but a bare
                             field: the house box carries its background, its
                             rule and its focus ring, and removing them one by
                             one with classes would have left a component
                             promising a look it no longer has. -->
                        <!-- **A field that looks like a title does not look
                             like a field.** Without border, without background
                             and in 2xl, this one read as the page title, and
                             people looked elsewhere for a way to rename the
                             note. A muted surface on hover and focus is
                             enough to say one can write in it, without
                             framing it all the time: the title stays a title
                             as long as it is not aimed at. -->
                        <input
                            v-model="form.title"
                            data-note-title
                            type="text"
                            class="min-w-0 flex-1 basis-60 rounded-md border-0 bg-transparent px-2 py-0.5 -mx-2 text-2xl font-semibold text-primary transition-colors placeholder:font-normal placeholder:text-muted hover:bg-surface-2 focus:bg-surface-2 focus:outline-none focus:ring-0"
                            :placeholder="t('notes.markdown.title_placeholder')"
                            :aria-label="t('notes.markdown.title_placeholder')"
                            :title="t('notes.markdown.rename')"
                        >

                        <div class="ml-auto flex shrink-0 flex-wrap items-center justify-end gap-2 md:gap-3">
                            <!-- The save state first, the buttons to its
                                 right: "Enregistré" is read where the eye
                                 leaves the title, and the gestures stay
                                 grouped at the edge of the screen. -->
                            <div class="flex items-center gap-3 shrink-0">
                                <span
                                    v-if="saveStatusDisplay"
                                    class="inline-flex items-center gap-1.5 text-xs"
                                    :class="saveStatusDisplay.classes"
                                >
                                    <component
                                        :is="saveStatusDisplay.icon"
                                        class="w-3.5 h-3.5"
                                        :class="saveStatusDisplay.spin ? 'animate-spin' : ''"
                                        :stroke-width="2"
                                    />
                                    {{ saveStatusDisplay.label }}
                                </span>
                                <span
                                    v-if="lastSavedAt"
                                    class="text-xs text-muted"
                                    :title="formatDateTime(lastSavedAt.toISOString())"
                                >
                                    {{ t('shared.common.autosave.last_saved', { time: lastSavedRelative }) }}
                                </span>
                            </div>

                            <!-- What is touched while writing stays at
                                 hand; the rest goes into the menu.

                                 Twelve commands on the title line, and the
                                 title had no room left: exporting, sharing,
                                 changing the image or opening the graph are
                                 done once per note, while view modes, tags
                                 and links are touched all the time. The
                                 house rule already says it for cards -
                                 beyond five, we keep the sheet. -->
                            <!-- Real buttons, all 38 px (02/10/2026): three
                                 bare icons sat next to a framed selector,
                                 each at its own height. Reading is the last
                                 position of the mode selector below. -->
                            <!-- An icon like its neighbours (08/10/2026):
                                 the only word in a bar of icons. -->
                            <AppPageActions
                                :actions="noteActions"
                                :label="form.title || t('notes.markdown.untitled')"
                                icon-only
                            />

                            <!-- The fold-out stays outside, to the right of
                                 the menu: it is the only one of these
                                 gestures that changes what is in view while
                                 writing, and its state - open or closed -
                                 must be readable without opening anything. -->
                            <AppButton
                                variant="secondary"
                                :active="sidePanelOpen"
                                :aria-pressed="sidePanelOpen"
                                :label="sidePanelOpen ? t('notes.markdown.links.close') : t('notes.markdown.links.open')"
                                icon-only
                                v-on:click="sidePanelOpen = !sidePanelOpen"
                            >
                                <PanelRightClose v-if="sidePanelOpen" class="w-4 h-4" :stroke-width="2" />
                                <PanelRightOpen v-else class="w-4 h-4" :stroke-width="2" />
                            </AppButton>

                            <!-- View mode toggle (edit / split / preview) - segmented AppTab control,
                                 at the height of the neighbouring buttons. -->
                            <div class="inline-flex h-9.5 items-stretch rounded-lg border border-line overflow-hidden">
                                <AppTab
                                    v-for="opt in viewModeOptions"
                                    :key="opt.value"
                                    size="sm"
                                    align="center"
                                    shape-class="rounded-none"
                                    :active="viewMode === opt.value"
                                    :title="opt.label"
                                    v-on:click="viewMode = opt.value"
                                >
                                    <component :is="opt.icon" class="w-4 h-4" :stroke-width="2" />
                                </AppTab>
                                <!-- Reading, the fourth way to look at the
                                     note (08/10/2026): it sat apart, to the
                                     left of the menu, as if it were another
                                     kind of gesture. It leaves the editor, so
                                     it is never the active one. -->
                                <AppTab
                                    v-if="readHref"
                                    data-note-read
                                    size="sm"
                                    align="center"
                                    shape-class="rounded-none"
                                    :active="false"
                                    :title="`${t('notes.markdown.read.mode')} (${t('notes.markdown.read.shortcut')})`"
                                    v-on:click="openReading"
                                >
                                    <BookOpen class="w-4 h-4" :stroke-width="2" />
                                </AppTab>
                            </div>
                        </div>

                        <!-- Folded into an icon: seeing the note being written
                         is worth more than a line of tags that are almost
                         never touched. The count is on the icon, the names
                         in its tooltip. -->
                        <div
                            v-if="tagsOpen"
                            ref="tagsBox"
                            v-on:keyup.esc="foldTags"
                            v-on:focusout="closeTagsIfIdle"
                        >
                            <AppTagsInput
                                v-model="form.tags"
                                :placeholder="t('notes.markdown.tags.add_placeholder')"
                            />
                        </div>

                        <!-- Editor form extension point. Scoped slot exposes
                         `form` (mutable reactive ref) so clients can wire
                         their custom v-model bindings against entity
                         fields they've added via aurora-client. -->
                        <slot name="extra-form-fields" :form="form" />
                    </header>

                    <!-- Two separate fixes, because the first one alone was aimed
                     at the wrong measurement.
                     
                     `max-w-[70%]` is the one that matters: the editor pane takes
                     a remembered pixel width with `shrink-0`, and 540px does not
                     fit next to a preview once the side menu and the note tree
                     have taken ~520px of a 1000px window. The preview was pushed
                     off-screen while the viewport was still far from any "mobile"
                     breakpoint - the constraint is how much room this pane has,
                     not how wide the window is. The cap is a share of the
                     available space, so it holds at every size.
                     
                     Stacking below `md` stays for phones, where two ~180px
                     columns would be unusable even when they fit. `min-w-0` on
                     both panes lets them actually shrink: a flex item defaults
                     to `min-width: auto` and refuses to go below its content. -->
                    <!-- Nothing of the body as long as it is not this note's.
                         The title is already there, it comes from the list;
                         the text comes from the server, and showing it
                         before meant showing the note being left under the
                         name of the one being opened. Three grey lines for
                         the round trip are better than a wrong answer. -->
                    <div v-if="!bodyReady" class="flex-1 min-h-0 space-y-3 p-3" aria-hidden="true">
                        <div class="h-3 w-2/3 animate-pulse rounded bg-surface-2" />
                        <div class="h-3 w-full animate-pulse rounded bg-surface-2" />
                        <div class="h-3 w-5/6 animate-pulse rounded bg-surface-2" />
                    </div>

                    <div v-else class="flex-1 min-h-0 flex flex-col md:flex-row overflow-hidden">
                        <div
                            v-if="viewMode !== 'preview'"
                            ref="editorPaneRef"
                            class="p-2 overflow-auto min-w-0 sm:p-4"
                            :class="viewMode === 'split' && !isMobile ? 'shrink-0 max-w-[70%]' : 'flex-1'"
                            :style="viewMode === 'split' && !isMobile ? { width: `${editorWidth}px` } : {}"
                        >
                            <NoteEditor
                                v-model="form.content"
                                :placeholder="t('notes.markdown.content_placeholder')"
                                :flat-notes="notes"
                                :upload-image="api.uploadImage"
                                :image-max-edge="imageMaxEdge"
                                :image-quality="imageQuality"
                            />
                        </div>

                        <!-- Resize handle: split mode on a wide screen only. Stacked
                         panes have nothing to redistribute horizontally. -->
                        <div
                            v-if="viewMode === 'split' && !isMobile"
                            class="w-1 shrink-0 cursor-col-resize bg-line hover:bg-accent-500/40 transition-colors"
                            :class="splitDragging ? 'bg-accent-500/60' : ''"
                            :title="t('notes.markdown.resize_handle')"
                            v-on:pointerdown="startSplitResize"
                        />

                        <div
                            v-if="viewMode !== 'edit'"
                            ref="previewPaneRef"
                            class="flex-1 min-w-0 p-2 overflow-auto sm:p-4"
                        >
                            <!-- The title lives in the field above: the
                                 preview does not repeat it. The text is not
                                 touched - the rendering abstains, and the
                                 `# ` stays in the writing area as in the
                                 export. -->
                            <NotePreview
                                :content="previewBody"
                                :note-titles="notes"
                                v-on:wiki-link-click="onWikiLinkClick"
                                v-on:checkbox-toggle="onCheckboxToggle"
                                v-on:image-resize="onImageResize"
                            />
                        </div>
                    </div>
                </div>

                <!-- No open note: the library. It used to be an empty screen
                 that said "choisissez une note" without showing which. -->
                <NoteLibrary
                    v-else
                    ref="libraryRef"
                    :folders="folders"
                    :notes="notes"
                    :personal-space-id="personalSpaceId"
                    :folders-api="foldersApi"
                    :notes-api="api"
                    :initial-folder-id="folderId"
                    :breadcrumb="breadcrumb"
                    :root-url="libraryPath"
                    :note-url-for="noteUrlFor"
                    :note-export-url-for="noteExportUrlFor"
                    :export-url-for="exportUrl"
                    :max-depth="maxDepth"
                    :daily-enabled="'' !== dailyPath"
                    :daily-opening="dailyOpening"
                    v-on:open-note="openNote"
                    v-on:create-note="createNote"
                    v-on:open-daily-note="openDailyNote"
                    v-on:changed="onLibraryChanged"
                    v-on:folder-changed="openFolderId = $event"
                />
            </section>

            <NoteGraph
                :show="graphOpen"
                :fetch-graph="api.graph"
                v-on:close="graphOpen = false"
                v-on:navigate="navigateFromGraph"
            />

            <NoteCoverModal
                :show="coverModalOpen"
                :search-path="coversSearchPath"
                :cover="cover"
                :appearance="form.appearance"
                v-on:close="coverModalOpen = false"
                v-on:choose="chooseCover"
                v-on:remove="removeCover"
                v-on:position="form.coverPosition = $event"
                v-on:appearance="form.appearance = $event"
            />

            <NoteShareModal
                :show="shareModalOpen"
                :note-id="selectedId"
                :paths="props"
                v-on:close="shareModalOpen = false"
            />

            <NoteTagManagerModal
                :show="tagManagerOpen"
                :api="tagsApi"
                v-on:close="tagManagerOpen = false"
                v-on:changed="onTagsChanged"
            />

            <NoteCraftImportModal
                v-if="craftEnabled"
                :show="null !== craftImport"
                :documents-path="craftPaths.documents ?? ''"
                :import-path="craftPaths.import ?? ''"
                :space-id="craftImport?.spaceId ?? null"
                :folder-id="craftImport?.folderId ?? null"
                v-on:close="craftImport = null"
                v-on:imported="onCraftImported"
            />

            <AppModal
                :show="craftRefreshPending"
                max-width="sm"
                :closeable="false"
                :title="t('notes.craft.import.refresh')"
                :icon="RefreshCw"
                v-on:close="craftRefreshPending = false"
            >
                <p class="text-sm text-primary">
                    {{ t('notes.craft.import.refresh_confirm', { title: form.title ?? '' }) }}
                </p>
                <p class="text-sm text-secondary">
                    {{ t('notes.craft.import.refresh_warning') }}
                </p>
                <template #footer>
                    <AppModalFooter>
                        <AppButton variant="ghost" size="md" v-on:click="craftRefreshPending = false">
                            <X class="h-3.5 w-3.5" :stroke-width="2" />
                            {{ t('shared.common.cancel') }}
                        </AppButton>
                        <AppButton variant="primary" size="md" :loading="craftRefreshing" v-on:click="refreshFromCraft">
                            <RefreshCw class="h-3.5 w-3.5" :stroke-width="2" />
                            {{ t('notes.craft.import.refresh_submit') }}
                        </AppButton>
                    </AppModalFooter>
                </template>
            </AppModal>

            <NoteRevisionsModal
                :show="historyOpen && null !== selectedId"
                :note-id="selectedId"
                :current="{ title: form.title, content: form.content }"
                :api="api"
                :can-restore="canEditSelected"
                v-on:close="historyOpen = false"
                v-on:restored="onRevisionRestored"
            />

            <NoteSidePanel
                v-if="sidePanelOpen && selectedNote"
                :note-id="selectedId"
                :fetch-backlinks="api.backlinks"
                :fetch-unlinked-mentions="api.unlinkedMentions"
                :content="form.content"
                v-on:close="sidePanelOpen = false"
                v-on:navigate="selectNote"
                v-on:jump="jumpToHeading"
            />

            <AppModal
                :show="!!pendingDelete"
                max-width="sm"
                :closeable="!deleting"
                :title="t('notes.markdown.delete')"
                :icon="Trash2"
                v-on:close="cancelDelete"
            >
                <p class="text-sm text-primary">
                    {{ t('notes.markdown.confirm_delete', { title: pendingDelete?.title || t('notes.markdown.untitled') }) }}
                </p>
                <p class="text-sm text-secondary mt-2">
                    {{ t('notes.markdown.delete_warning') }}
                </p>
                <template #footer>
                    <AppModalFooter>
                        <AppButton variant="ghost" size="md" :disabled="deleting" v-on:click="cancelDelete">
                            <X class="w-3.5 h-3.5" :stroke-width="2" />
                            {{ t('notes.markdown.cancel') }}
                        </AppButton>
                        <AppButton variant="danger" size="md" :loading="deleting" v-on:click="confirmDeleteAndLeave">
                            <Trash2 class="w-3.5 h-3.5" :stroke-width="2" />
                            {{ t('notes.markdown.delete') }}
                        </AppButton>
                    </AppModalFooter>
                </template>
            </AppModal>

            <!-- Someone wrote in the note in the meantime. We do not decide
                 for the person: take back their version, or overwrite it
                 knowingly. -->
            <AppModal
                :show="conflict"
                max-width="md"
                :closeable="false"
                :title="t('notes.markdown.conflict.title')"
                :icon="TriangleAlert"
            >
                <p class="text-sm text-primary" data-note-conflict>{{ t('notes.markdown.conflict.body') }}</p>
                <template #footer>
                    <AppModalFooter>
                        <AppButton variant="danger" size="md" data-conflict-overwrite v-on:click="saveAnyway">
                            {{ t('notes.markdown.conflict.overwrite') }}
                        </AppButton>
                        <AppButton variant="primary" size="md" data-conflict-reload v-on:click="reloadDiscarding">
                            {{ t('notes.markdown.conflict.reload') }}
                        </AppButton>
                    </AppModalFooter>
                </template>
            </AppModal>

            <NoteCreateModal
                :show="null !== addModal"
                :folder-id="addModal?.folderId ?? null"
                :space-id="addModal?.spaceId ?? null"
                :folders="folders"
                :spaces="spaces"
                :can-create-space="canCreateSpace"
                :templates="templates"
                :saving="addSaving"
                v-on:close="addModal = null"
                v-on:submit="submitAdd"
            />

            <NoteSpaceSettingsModal
                :space-id="settingsSpaceId"
                :api="spacesApi"
                v-on:close="settingsSpaceId = null"
                v-on:changed="onSpaceChanged"
            />
        </div>
    </div>
</template>
