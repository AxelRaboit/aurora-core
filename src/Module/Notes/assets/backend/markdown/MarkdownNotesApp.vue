<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { useMarkdownNotesPage } from '@notes/backend/markdown/composables/useMarkdownNotesPage.js';
import { useNoteFoldersApi } from '@notes/backend/markdown/composables/useNoteFoldersApi.js';
import NoteLibrary from '@notes/backend/markdown/components/NoteLibrary.vue';
import NotePreview from '@notes/backend/markdown/components/NotePreview.vue';
import NoteSidePanel from '@notes/backend/markdown/components/NoteSidePanel.vue';
import NoteTagManagerModal from '@notes/backend/markdown/components/NoteTagManagerModal.vue';
import NoteShareModal from '@notes/backend/markdown/components/NoteShareModal.vue';
import NoteEditor from '@notes/backend/markdown/components/NoteEditor.vue';
import NoteGraph from '@notes/backend/markdown/components/NoteGraph.vue';
import AppButton from '@shared/components/action/AppButton.vue';
import AppIconButton from '@shared/components/action/AppIconButton.vue';
import AppInput from '@shared/components/form/input/AppInput.vue';
import AppSearchInput from '@shared/components/form/input/AppSearchInput.vue';
import AppTagsInput from '@shared/components/form/select/AppTagsInput.vue';
import AppModal from '@shared/components/overlay/AppModal.vue';
import AppModalFooter from '@shared/components/overlay/AppModalFooter.vue';
import AppTab from '@shared/components/nav/AppTab.vue';
import { onErrorCaptured, onMounted, onUnmounted, watch } from 'vue';
import { onPanelRequest, tellPanels } from '@/shared/nav/modulePanelBridge.js';
import { Trash2, FileDown, PanelRightOpen, PanelRightClose, TriangleAlert, X, Network, Share2 } from 'lucide-vue-next';
import AppNoData from '@shared/components/feedback/AppNoData.vue';
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";

const { formatDateTimeNumeric } = useDateFormat();

const props = defineProps({
    notes: { type: Array, default: () => [] },
    /** Every folder of the reader, flat, serialized with its counts. */
    folders: { type: Array, default: () => [] },
    /** The folder the address names, null on the root listing or on a note. */
    folderId: { type: Number, default: null },
    /** The chain the server resolved for that folder, root first. */
    breadcrumb: { type: Array, default: () => [] },
    /** Les routes des dossiers, en un objet plutôt qu'en sept props. */
    folderPaths: { type: Object, required: true },
    /** L'adresse de la bibliothèque, c'est-à-dire du carnet à sa racine. */
    libraryPath: { type: String, required: true },
    browsePath: { type: String, default: '' },
    maxDepth: { type: Number, default: 8 },
    listPath: { type: String, required: true },
    showPath: { type: String, required: true },
    createPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
    movePath: { type: String, required: true },
    favoritePath: { type: String, default: '' },
    reorderPath: { type: String, required: true },
    backlinksPath: { type: String, required: true },
    unlinkedMentionsPath: { type: String, required: true },
    graphPath: { type: String, required: true },
    /** Le carnet entier en zip, une note seule en .md, et le chemin du retour. */
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
} = useMarkdownNotesPage(props, t);

// Local to this component rather than folded into `useMarkdownNotesPage`:
// sharing is opened from the toolbar and closed by the modal, and nothing in
// the page composable reads it.
const shareModalOpen = ref(false);

/**
 * Ce qui casse doit se voir.
 *
 * Une erreur dans un composant enfant vide sa zone sans un mot : Vue la
 * consigne dans la console et rend du vide. Axel a eu deux fois un grand
 * cadre blanc pour tout message, et la seule façon de savoir ce qui s'était
 * passé était d'ouvrir les outils de développement. Une page qui échoue doit
 * le dire à qui la regarde, et dire quoi.
 */
const crashed = ref(null);

onErrorCaptured((error) => {
    crashed.value = error;

    // Consigné quand même : le message à l'écran sert la personne, la trace
    // sert celui qui répare.
    console.error('[notes] la page a échoué', error);

    return false;
});

/**
 * Les dossiers, et le va-et-vient entre la bibliothèque et l'éditeur.
 *
 * Une seule page monte les deux : ouvrir une note depuis la bibliothèque
 * écrit son adresse et charge son texte, sans recharger le document. Le
 * retour arrière du navigateur rend la bibliothèque, parce que l'adresse
 * qu'on quitte est une vraie adresse et pas un état interne.
 */
const foldersApi = useNoteFoldersApi(props.folderPaths);
const folders = ref([...props.folders]);

/**
 * La bibliothèque, quand elle est là.
 *
 * Le panneau du menu parle à cette application, montée sur toutes les pages
 * du module ; la bibliothèque n'est montée que lorsque aucune note n'est
 * ouverte. Quand elle manque, une demande du panneau devient une navigation,
 * ce qui est la réponse honnête : on quitte l'éditeur pour aller voir.
 */
const libraryRef = ref(null);

/**
 * Le dossier sous les yeux, qui n'est pas toujours celui de l'adresse
 * initiale : la bibliothèque navigue sans recharger.
 */
const openFolderId = ref(props.folderId);

function folderUrlFor(id) {
    return props.folderPaths.show.replace('__id__', String(id));
}

function goToFolder(id) {
    window.location.assign(null === id || undefined === id ? props.libraryPath : folderUrlFor(id));
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
        // Cadre bac à sable : la note s'ouvre quand même, seule l'adresse
        // ne suit pas.
    }

    await selectNote(id);
}

function onHistoryPop() {
    // La bibliothèque a son propre écouteur pour le dossier ; celui-ci ne
    // tranche qu'entre « une note » et « la liste ».
    const match = /\/markdown\/(\d+)(?:$|[?#])/.exec(window.location.pathname);

    if (match) {
        void selectNote(Number(match[1]));

        return;
    }

    selectedId.value = null;
}

async function onLibraryChanged() {
    await Promise.all([refreshFolders(), refreshList()]);
}

function backToLibrary() {
    const folderId = openFolderId.value;
    const url = folderId ? folderUrlFor(folderId) : props.libraryPath;

    try {
        window.history.pushState({ folderId }, '', url);
    } catch {
        // Idem : le retour se fait, l'adresse ne suit pas.
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
 * Emporter le carnet, et le rendre.
 *
 * L'export est une navigation, pas une requête : le navigateur sait recevoir
 * un fichier et le ranger, et passer par `fetch` obligerait à garder un zip
 * entier en mémoire pour le redonner à un lien fabriqué.
 */
function exportAll() {
    window.location.assign(props.exportPath);
}

function exportOne(id) {
    window.location.assign(props.exportOnePath.replace("__id__", String(id)));
}

const importInput = ref(null);

function askForFiles() {
    importInput.value?.click();
}

async function onImportFiles(event) {
    const files = [...(event.target.files ?? [])];
    // Remis à zéro tout de suite : sans ça, réimporter le même fichier
    // n'émettrait rien, le champ n'ayant pas changé de valeur.
    event.target.value = "";

    if (!files.length) return;

    const form = new FormData();
    files.forEach((file) => form.append("files[]", file));

    // Dans le dossier ouvert quand il y en a un : on importe là où on regarde.
    if (openFolderId.value) form.append("folderId", String(openFolderId.value));

    const { ok, payload } = await api.import(form);

    if (!ok) {
        if (payload?.errors) toast.error(Object.values(payload.errors)[0]);

        return;
    }

    await refreshList();
    toast.success(t("notes.markdown.import.done", { count: payload.created ?? 0 }));
}

const PANEL_INTENTS = {
    select: (id) => openNote(id),
    export: () => exportAll(),
    import: () => askForFiles(),
    create: (folderId) => createNote(folderId ?? null),
    delete: (note) => requestDelete(note),
    'open-folder': (id) => (libraryRef.value ? libraryRef.value.openFolder(id) : goToFolder(id)),
    'create-folder': (parentId) =>
        libraryRef.value
            ? libraryRef.value.askForFolderName(null, parentId ?? null)
            : goToFolder(parentId ?? null),
    'delete-folder': (folder) =>
        libraryRef.value ? libraryRef.value.askToDelete(folder) : goToFolder(folder?.id ?? null),
    // Le glisser du panneau : la cible est une ligne de dossier, et ce qui
    // est déplacé voyage dans le presse-papier de l'événement, donc la
    // bibliothèque sait quoi en faire sans qu'on le lui répète.
    drop: (folder, event) => libraryRef.value?.dropInto(Number(folder.id), event),
};

const stopListening = [];

function announce() {
    tellPanels('notes:changed', {
        notes: notes.value,
        folders: folders.value,
        selectedId: selectedId.value,
        folderId: openFolderId.value,
        noteId: selectedId.value,
    });
}

onMounted(() => {
    for (const [intent, run] of Object.entries(PANEL_INTENTS)) {
        stopListening.push(
            onPanelRequest(`notes:${intent}`, ({ args = [] }) => run(...args)),
        );
    }

    // The panel fetches on arrival too, but it may well have done so before
    // this list existed; saying it once on mount settles which of the two wins.
    announce();
    stopListening.push(watch(notes, announce, { deep: true }));
    stopListening.push(watch(folders, announce, { deep: true }));
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
    <!-- La hauteur est ce qui reste, dite avec les valeurs qui la font : la
         barre du haut porte déjà la sienne dans `--aurora-topbar`, et les
         quatre rem sont les marges hautes et basses de la zone de contenu. Le
         `8rem` écrit ici avant était une estimation, fausse de trois douzaines
         de pixels : la carte dépassait le bas de l'écran, donc la fin d'une
         note longue se lisait en faisant défiler la page entière. `dvh` plutôt
         que `vh` pour que la barre d'un navigateur mobile compte. -->
    <!-- Le champ qui reçoit les fichiers importés : invisible, déclenché par
         le bouton du panneau. Un `input[type=file]` ne se dessine pas. -->
    <input
        ref="importInput"
        type="file"
        class="hidden"
        multiple
        accept=".md,.markdown,.zip,text/markdown,application/zip"
        v-on:change="onImportFiles"
    >

    <div class="relative flex h-[calc(100dvh-var(--aurora-topbar)-4rem)] bg-surface rounded-xl border border-line overflow-hidden">
        <!-- No tree column and no drawer of its own: the notes are in the
             side menu's panel now, on every page of the module rather than
             this one, and the menu already has a drawer on small screens.
             Two drawers was two gestures to learn for the same thing. -->

        <!-- Editor pane -->
        <!-- `min-h-0` sur toute la colonne, et pas seulement `overflow-auto` en
             bas : un enfant de flex vaut `min-height: auto`, donc il refuse de
             descendre sous la hauteur de son contenu. Une note longue poussait
             la colonne au-delà de la carte au lieu de faire défiler le volet,
             et la fin du texte sortait de l'écran. C'est le pendant vertical de
             ce que le commentaire des deux volets dit déjà pour la largeur. -->
        <section class="flex-1 flex flex-col min-w-0 min-h-0">
            <div v-if="crashed" class="flex flex-1 items-center justify-center p-6">
                <AppNoData
                    :title="t('notes.markdown.errors.crashed')"
                    :description="String(crashed?.message ?? crashed)"
                    :icon="TriangleAlert"
                />
            </div>

            <div v-else-if="selectedNote" class="flex-1 flex flex-col min-h-0">
                <header class="p-2 border-b border-line flex flex-col gap-2 sm:p-4">
                    <!-- Le chemin de retour. Une note ouverte depuis la
                         bibliothèque doit pouvoir y revenir sans le bouton
                         précédent du navigateur, qui n'est pas une commande
                         de l'application. -->
                    <button
                        type="button"
                        class="self-start text-xs text-muted hover:text-primary transition-colors"
                        v-on:click="backToLibrary"
                    >
                        ← {{ t('notes.markdown.library.title') }}
                    </button>
                    <!-- Le titre prend la ligne. Partagée avec les six boutons
                         et les deux mentions d'état, elle laissait au nom de la
                         note ce qui restait, c'est-à-dire peu : sur un écran
                         moyen, un titre un peu long était tronqué à la saisie.
                         Ce qui l'accompagne descend d'un cran. -->
                    <AppInput
                        v-model="form.title"
                        :placeholder="t('notes.markdown.title_placeholder')"
                        class="w-full text-lg font-medium"
                    />

                    <div class="flex flex-wrap items-center gap-2 md:gap-3">
                        <!-- Disabled until a note is selected: there is nothing to
                             share from an empty editor, and a modal that opens on
                             null would ask the server for share links of no note. -->
                        <!-- Cette note seule, en Markdown. À côté du partage
                             parce que les deux répondent à « je veux la donner
                             à quelqu'un », par un lien ou par un fichier. -->
                        <AppIconButton
                            :title="t('notes.markdown.export.one')"
                            size="md"
                            variant="ghost"
                            :disabled="!selectedId"
                            v-on:click="exportOne(selectedId)"
                        >
                            <FileDown class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>

                        <AppIconButton
                            :title="t('notes.markdown.share.button')"
                            size="md"
                            variant="ghost"
                            :disabled="!selectedId"
                            v-on:click="shareModalOpen = true"
                        >
                            <Share2 class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>

                        <!-- Le graphe n'avait aucun bouton : le composant était
                             monté, branché sur sa source et traduit, et
                             `graphOpen` n'était mis à vrai nulle part. La
                             fonction existait sans porte d'entrée. -->
                        <AppIconButton
                            :title="t('notes.markdown.graph.open')"
                            size="md"
                            variant="ghost"
                            v-on:click="graphOpen = true"
                        >
                            <Network class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>

                        <AppIconButton
                            :title="sidePanelOpen ? t('notes.markdown.links.close') : t('notes.markdown.links.open')"
                            size="md"
                            :variant="sidePanelOpen ? 'primary' : 'ghost'"
                            v-on:click="sidePanelOpen = !sidePanelOpen"
                        >
                            <PanelRightClose v-if="sidePanelOpen" class="w-4 h-4" :stroke-width="2" />
                            <PanelRightOpen v-else class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>

                        <!-- View mode toggle (edit / split / preview) - segmented AppTab control -->
                        <div class="inline-flex rounded-md border border-line overflow-hidden">
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
                        </div>

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
                                :title="formatDateTimeNumeric(lastSavedAt.toISOString())"
                            >
                                {{ t('shared.common.autosave.last_saved', { time: lastSavedRelative }) }}
                            </span>
                        </div>
                    </div>

                    <AppTagsInput
                        v-model="form.tags"
                        :placeholder="t('notes.markdown.tags.add_placeholder')"
                    />

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
                <div class="flex-1 min-h-0 flex flex-col md:flex-row overflow-hidden">
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
                        class="flex-1 min-w-0 p-2 overflow-auto sm:p-4"
                    >
                        <NotePreview
                            :content="form.content"
                            :note-titles="notes"
                            v-on:wiki-link-click="onWikiLinkClick"
                            v-on:checkbox-toggle="onCheckboxToggle"
                            v-on:image-resize="onImageResize"
                        />
                    </div>
                </div>
            </div>

            <!-- Pas de note ouverte : la bibliothèque. C'était un écran vide
                 qui disait « choisissez une note » sans montrer lesquelles. -->
            <NoteLibrary
                v-else
                ref="libraryRef"
                :folders="folders"
                :notes="notes"
                :folders-api="foldersApi"
                :notes-api="api"
                :initial-folder-id="folderId"
                :breadcrumb="breadcrumb"
                :root-url="libraryPath"
                :note-url-for="noteUrlFor"
                :note-export-url-for="noteExportUrlFor"
                :max-depth="maxDepth"
                v-on:open-note="openNote"
                v-on:create-note="createNote"
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

        <NoteSidePanel
            v-if="sidePanelOpen && selectedNote"
            :note-id="selectedId"
            :fetch-backlinks="api.backlinks"
            :fetch-unlinked-mentions="api.unlinkedMentions"
            v-on:close="sidePanelOpen = false"
            v-on:navigate="selectNote"
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
                    <AppButton variant="danger" size="md" :loading="deleting" v-on:click="confirmDelete">
                        <Trash2 class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t('notes.markdown.delete') }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
