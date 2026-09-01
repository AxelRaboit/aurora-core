<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useMarkdownNotesPage } from '@notes/backend/markdown/composables/useMarkdownNotesPage.js';
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
import AppNoData from '@shared/components/feedback/AppNoData.vue';
import AppModal from '@shared/components/overlay/AppModal.vue';
import AppModalFooter from '@shared/components/overlay/AppModalFooter.vue';
import AppTab from '@shared/components/nav/AppTab.vue';
import { onMounted, onUnmounted, watch } from 'vue';
import { onPanelRequest, tellPanels } from '@/shared/nav/modulePanelBridge.js';
import { Plus, Trash2, FileText, PanelRightOpen, PanelRightClose, X, Settings2, Network, Share2} from 'lucide-vue-next';

const props = defineProps({
    notes: { type: Array, default: () => [] },
    listPath: { type: String, required: true },
    showPath: { type: String, required: true },
    createPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
    movePath: { type: String, required: true },
    reorderPath: { type: String, required: true },
    backlinksPath: { type: String, required: true },
    unlinkedMentionsPath: { type: String, required: true },
    graphPath: { type: String, required: true },
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
    tree,
    treeQuery,
    availableTags,
    selectedTags,
    toggleTag,
    clearTags,
    onTagsChanged,
    sidePanelOpen,
    graphOpen,
    tagManagerOpen,
    dragEnabled,
    draggingId,
    dragOverId,
    rootDragOver,
    onDragStart,
    onDragEnd,
    onDragOverNote,
    onDragLeaveNote,
    onDragOverRoot,
    onDragLeaveRoot,
    onDropOnNote,
    onDropOnRoot,
    viewMode,
    viewModeOptions,
    lastSavedRelative,
    saveStatusDisplay,
    editorPaneRef,
    editorWidth,
    startSplitResize,
    splitDragging,
    navigateFromGraph,
} = useMarkdownNotesPage(props, t);

// Local to this component rather than folded into `useMarkdownNotesPage`:
// sharing is opened from the toolbar and closed by the modal, and nothing in
// the page composable reads it.
const shareModalOpen = ref(false);

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
const PANEL_INTENTS = {
    select: (id) => selectNote(id),
    create: (parentId) => createNote(parentId ?? null),
    delete: (note) => requestDelete(note),
    'drag-start': (note, event) => onDragStart(note, event),
    'drag-end': () => onDragEnd(),
    'drag-over': (note, event) => onDragOverNote(note, event),
    'drag-leave': (note, event) => onDragLeaveNote(note, event),
    drop: (note, event) => onDropOnNote(note, event),
};

const stopListening = [];

function announce() {
    tellPanels('notes:changed', {
        notes: notes.value,
        selectedId: selectedId.value,
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
    stopListening.push(watch(selectedId, announce));
});

onUnmounted(() => {
    while (stopListening.length) stopListening.pop()();
});

</script>

<template>
    <div class="relative flex h-[calc(100vh-8rem)] bg-surface rounded-xl border border-line overflow-hidden">
        <!-- No tree column and no drawer of its own: the notes are in the
             side menu's panel now, on every page of the module rather than
             this one, and the menu already has a drawer on small screens.
             Two drawers was two gestures to learn for the same thing. -->

        <!-- Editor pane -->
        <section class="flex-1 flex flex-col min-w-0">
            <div v-if="selectedNote" class="flex-1 flex flex-col">
                <header class="p-4 border-b border-line flex flex-col gap-2">
                    <div class="flex flex-wrap items-center gap-2 md:gap-3">
                        <AppInput
                            v-model="form.title"
                            :placeholder="t('notes.markdown.title_placeholder')"
                            class="flex-1 min-w-0 text-lg font-medium"
                        />

                        <!-- Disabled until a note is selected: there is nothing to
                             share from an empty editor, and a modal that opens on
                             null would ask the server for share links of no note. -->
                        <AppIconButton
                            :title="t('notes.markdown.share.button')"
                            size="md"
                            variant="ghost"
                            :disabled="!selectedId"
                            v-on:click="shareModalOpen = true"
                        >
                            <Share2 class="w-4 h-4" :stroke-width="2" />
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
                                :title="lastSavedAt.toLocaleString()"
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
                <div class="flex-1 flex flex-col md:flex-row overflow-hidden">
                    <div
                        v-if="viewMode !== 'preview'"
                        ref="editorPaneRef"
                        class="p-4 overflow-auto min-w-0"
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
                        class="flex-1 min-w-0 p-4 overflow-auto"
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

            <div v-else class="flex-1 flex flex-col">
                <header class="p-3 border-b border-line flex items-center gap-2 md:hidden">
                    <h2 class="text-sm font-semibold text-primary">{{ t('notes.markdown.title') }}</h2>
                </header>
                <div class="flex-1 flex items-center justify-center text-muted text-sm">
                    <AppNoData
                        :title="t('notes.markdown.no_selection.title')"
                        :description="t('notes.markdown.no_selection.description')"
                        :icon="FileText"
                    />
                </div>
            </div>
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
