<script setup>
/**
 * The notes, in the side menu.
 *
 * This was a 280 px aside inside the notes page - the widest of the six the
 * module system set out to move, and the one `ModuleNavView::$panelComponent`
 * was invented for: nine hundred notes cannot be nine hundred menu entries, and
 * a tree with a search field and a tag filter is not a list of links.
 *
 * **Rows are real addresses.** A note is a page now
 * (`/backend/notes/markdown/42`), so it can be sent to somebody, and
 * middle-click behaves. On a plain click the panel asks the page first through
 * `modulePanelBridge`: the editor is mounted, so it takes the click and swaps
 * the note in place, exactly as the aside's own handler used to. Nobody
 * listening means the reader is elsewhere in the module, and the link
 * navigates.
 *
 * What it deliberately leaves behind: the graph button and the tag manager,
 * which open dialogs belonging to the page, and the mobile drawer - the menu
 * has its own, and two drawers is two gestures to learn.
 */
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Plus, X } from "lucide-vue-next";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppModulePanel from "@/shared/nav/AppModulePanel.vue";
import { askPage, onPageNotice } from "@/shared/nav/modulePanelBridge.js";
import { useModulePanelData } from "@/shared/nav/useModulePanelData.js";
import { useNoteTree } from "./composables/useNoteTree.js";
import { useNoteTagFilter } from "./composables/useNoteTagFilter.js";
import NoteTreeItem from "./components/NoteTreeItem.vue";

const LIST_ENDPOINT = "/backend/notes/markdown/list";

const { t } = useI18n();

/**
 * The rows are `NoteTreeItem`, the component the aside used, and every one of
 * its events is handed straight to the page.
 *
 * Rebuilding the row here was the mistake in the first pass: it lost the
 * create-child button, the delete button and the whole drag-and-drop, silently,
 * because a hand-written `v-for` only has what you remember to give it. The
 * page still owns the note API - it always exists while this panel is on
 * screen, Notes having exactly one destination - so there is nothing to
 * duplicate and nothing to keep in agreement.
 */
const {
    data: fetched,
    loading,
    failed,
} = useModulePanelData(LIST_ENDPOINT, { key: "notes" });

/**
 * The page's list wins over our own fetch as soon as it speaks.
 *
 * We fetch on arrival because the panel may render before the editor is
 * mounted; from then on the editor announces every change, which is what makes
 * a note created in the editor appear here without a reload. Without it the
 * tree showed whatever was true when the page loaded, for as long as the reader
 * stayed on it.
 */
const announced = ref(null);
const notes = computed(() => announced.value ?? fetched.value);
const selectedId = ref(null);

const treeQuery = ref("");
const { availableTags, selectedTags, toggleTag, clearTags } =
    useNoteTagFilter(notes);
const { tree } = useNoteTree(notes, treeQuery, selectedTags);

const isEmpty = computed(() => 0 === notes.value.length);

/**
 * Our own copy of what is being dragged, so the rows can highlight.
 *
 * The page keeps the same state - it has to, the drop handler reads it - but
 * mirroring it here costs one assignment per event we are forwarding anyway,
 * where reading it back would mean an announcement on every `dragover`.
 */
const draggingId = ref(null);
const dragOverId = ref(null);

function forward(name, ...args) {
    askPage(`notes:${name}`, { args });
}

function onSelect(id) {
    selectedId.value = id;
    forward("select", id);
}

function onDragStart(note, event) {
    draggingId.value = note.id;
    forward("drag-start", note, event);
}

function onDragEnd() {
    draggingId.value = null;
    dragOverId.value = null;
    forward("drag-end");
}

function onDragOver(note, event) {
    if (note.id !== draggingId.value) dragOverId.value = note.id;
    forward("drag-over", note, event);
}

function onDragLeave(note, event) {
    if (dragOverId.value === note.id) dragOverId.value = null;
    forward("drag-leave", note, event);
}

function onDrop(note, event) {
    dragOverId.value = null;
    draggingId.value = null;
    forward("drop", note, event);
}

/** A note is a page: the row offers its address for every gesture but a plain click. */
const hrefFor = (note) => `/backend/notes/markdown/${note.id}`;

const stopListening = [];

onMounted(() => {
    stopListening.push(
        onPageNotice("notes:changed", (detail) => {
            if (Array.isArray(detail?.notes)) announced.value = detail.notes;
            if ("selectedId" in (detail ?? {})) selectedId.value = detail.selectedId;
        }),
    );
});

onUnmounted(() => {
    while (stopListening.length) stopListening.pop()();
});
</script>

<template>
    <AppModulePanel
        :title="t('notes.markdown.title')"
        :loading="loading"
        :failed="failed"
    >
        <template #action>
            <AppIconButton
                size="sm"
                variant="ghost"
                :title="t('notes.markdown.create_root')"
                v-on:click="forward('create', null)"
            >
                <Plus class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
        </template>

        <div class="px-3 pb-1">
            <AppSearchInput
                v-model="treeQuery"
                :placeholder="t('notes.markdown.search_placeholder')"
            />
        </div>

        <div v-if="availableTags.length" class="flex flex-wrap gap-1 px-3 pb-1">
            <button
                v-for="tag in availableTags"
                :key="tag"
                type="button"
                class="rounded-full border px-2 py-0.5 text-xs transition-colors"
                :class="
                    selectedTags.includes(tag)
                        ? 'border-violet-500 text-violet-300'
                        : 'border-line text-muted hover:text-primary'
                "
                v-on:click="toggleTag(tag)"
            >
                {{ tag }}
            </button>
            <AppIconButton
                v-if="selectedTags.length"
                size="sm"
                variant="ghost"
                :title="t('notes.markdown.tags.clear')"
                v-on:click="clearTags"
            >
                <X class="h-3 w-3" :stroke-width="2" />
            </AppIconButton>
        </div>

        <p v-if="isEmpty" class="px-3 py-1 text-xs text-muted">
            {{ t("notes.markdown.tree_empty") }}
        </p>

        <NoteTreeItem
            v-for="node in tree"
            :key="node.id"
            :node="node"
            :selected-id="selectedId"
            :draggable="true"
            :dragging-id="draggingId"
            :drag-over-id="dragOverId"
            :href-for="hrefFor"
            v-on:select="onSelect"
            v-on:create-child="(id) => forward('create', id)"
            v-on:delete="(note) => forward('delete', note)"
            v-on:drag-start="onDragStart"
            v-on:drag-end="onDragEnd"
            v-on:drag-over="onDragOver"
            v-on:drag-leave="onDragLeave"
            v-on:drop="onDrop"
        />
    </AppModulePanel>
</template>
