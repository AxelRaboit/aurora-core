<script setup>
/**
 * A tree row: a folder, or a note filed in it.
 *
 * Both look alike and do not do the same thing. A folder expands, fills up,
 * is deleted with its content; a note opens, and that is all it does here -
 * reading it, renaming it, throwing it away happens in the editor or in the
 * library.
 *
 * **Expansion is held by the panel, not by the row.** A local state would
 * start closed again on every render of the tree, that is on every note
 * created elsewhere, and a search could not open the branches where it found
 * something.
 *
 * **The drop is read on the row.** A line above or below says "before" or
 * "after", a frame says "inside": it is what Craft, Notion and Obsidian
 * show, and it is what was missing to file at a precise rank rather than
 * loosely at the bottom of a folder.
 */
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ChevronRight, ChevronDown, Download, FileText, Folder, FolderOpen, LayoutTemplate, Pencil, Plus, Star, StarOff, Trash2 } from 'lucide-vue-next';
import AppIconButton from '@shared/components/action/AppIconButton.vue';
import AppRowActions from '@shared/components/action/AppRowActions.vue';

const props = defineProps({
    node: { type: Object, required: true },
    /** The folder open in the library, or the open note. */
    selectedKey: { type: String, default: null },
    /** The ids of the expanded folders, held by the panel. */
    expanded: { type: Set, default: () => new Set() },
    draggable: { type: Boolean, default: false },
    /** Reading mode: the row leads somewhere, it offers nothing else. */
    readonly: { type: Boolean, default: false },
    /**
     * False in a space one reads without writing in it: the row only offers
     * what is one's own - favourites -, no rename, no filing, no delete.
     */
    editable: { type: Boolean, default: true },
    /** Offers « Exporter ce dossier »: only where something answers it. */
    exportable: { type: Boolean, default: false },
    draggingKey: { type: String, default: null },
    /** Where what is held would land: `{ key, zone }`, zone before, inside or after. */
    dropHint: { type: Object, default: null },
    /** The key of the row that holds this one, to go back up by keyboard. */
    parentKey: { type: String, default: null },
    depth: { type: Number, default: 0 },
    /**
     * Turns the row into a real link.
     *
     * A folder and a note are both pages, so a row in the side menu has to
     * be middle-clickable and sendable - the whole reason their addresses
     * exist. The click handler still runs and still wins: selecting swaps
     * the listing or the note in place, and the navigation is cancelled.
     */
    hrefFor: { type: Function, default: null },
});

const emit = defineEmits([
    'select',
    'toggle',
    'add',
    'rename',
    'favorite',
    'delete',
    'export',
    'drag-start',
    'drag-end',
    'drag-over',
    'drag-leave',
    'drop',
]);

const { t } = useI18n();

const isFolder = computed(() => 'folder' === props.node.kind);

/**
 * What a row offers, folder or note.
 *
 * In a sheet and not as lined-up buttons: the house rule says that beyond
 * two gestures we stack, and a tree row is too narrow to line up three. A
 * folder's plus stays outside, it is the one that is repeated; it is also in
 * the sheet, because the right click only opens the sheet.
 */
const favoriteAction = computed(() => ({
    // Favourites are one's own: one pins what one reads, where one sees it.
    key: 'favorite',
    title: props.node.favoritedAt ? t('notes.markdown.library.unpin') : t('notes.markdown.library.pin'),
    icon: props.node.favoritedAt ? StarOff : Star,
    onSelect: () => emit('favorite', props.node),
}));

const editActions = computed(() => [
    ...(isFolder.value
        ? [{
            key: 'add',
            title: t('notes.markdown.add.here'),
            icon: Plus,
            onSelect: () => emit('add', props.node),
        }]
        : []),
    {
        key: 'rename',
        title: isFolder.value ? t('notes.markdown.folders.rename') : t('notes.markdown.rename'),
        icon: Pencil,
        onSelect: () => emit('rename', props.node),
    },
    favoriteAction.value,
    {
        key: 'delete',
        title: isFolder.value ? t('notes.markdown.folders.delete') : t('notes.markdown.delete'),
        icon: Trash2,
        color: 'rose',
        onSelect: () => emit('delete', props.node),
    },
]);

// Taking a folder away only asks to read it: a reader of the space can
// already read and copy all of it.
const exportAction = computed(() => (isFolder.value && props.exportable
    ? [{
        key: 'export',
        title: t('notes.markdown.folders.export'),
        icon: Download,
        onSelect: () => emit('export', props.node),
    }]
    : []));

const rowActions = computed(() => [
    ...(props.editable ? editActions.value : [favoriteAction.value]),
    ...exportAction.value,
]);
const children = computed(() => props.node.children ?? []);
const hasChildren = computed(() => children.value.length > 0);
const isOpen = computed(() => props.expanded.has(Number(props.node.id)));
const isSelected = computed(() => props.selectedKey === props.node.key);
const zone = computed(() => (props.dropHint?.key === props.node.key ? props.dropHint.zone : null));
const isDropInside = computed(() => 'inside' === zone.value);
const isBeingDragged = computed(() => props.draggingKey === props.node.key);

/**
 * The folder's colour, when it carries one.
 *
 * As a style and not a class: the value comes from the reader, and Tailwind
 * only writes the classes it sees in the source. It takes precedence over
 * the colour class except when the row is selected or targeted by a drag:
 * there, the state is what must show, not the decoration.
 */
const tint = computed(() =>
    isFolder.value && props.node.color && !isSelected.value && !isDropInside.value
        ? { color: props.node.color }
        : null,
);

const label = computed(() =>
    isFolder.value
        ? props.node.name || undefined
        : props.node.title || undefined,
);

const displayLabel = computed(() =>
    label.value || (isFolder.value ? t('notes.markdown.folders.untitled') : t('notes.markdown.untitled')),
);

/**
 * Selecting is what a click means; the address is for the other gestures.
 * Cancelling the navigation is what keeps the page from reloading under a
 * reader who only meant to switch.
 */
function onRowClick(event) {
    if (props.hrefFor) event.preventDefault();
    emit('select', props.node);
}

// The right click opens the same sheet as the three dots: a single menu, two
// doors, as in any file explorer.
const actionsRef = ref(null);

function onContextMenu(event) {
    if (props.readonly) return;

    event.preventDefault();
    actionsRef.value?.open();
}

/**
 * Double-clicking the name renames; double-clicking a button of the row -
 * the arrow one closes and reopens, the plus, the three dots - only does
 * what the button does. Without this guard, folding then quickly expanding
 * opened the rename modal.
 */
function onDoubleClick(event) {
    if (props.readonly || !props.editable || event.target?.closest?.('button')) return;

    emit('rename', props.node);
}

// Indent applied to the row itself so its right edge stays flush with
// the sidebar (same convention as TermNode / media folder rows).
const indentStyle = computed(() => ({ marginLeft: `${props.depth * 0.875}rem` }));
</script>

<template>
    <div>
        <!-- The row is a div, and only the title inside it is a link.
             Putting the whole row in an `<a>` seemed tidier and was wrong twice
             over: interactive content inside a link is invalid HTML, and the
             action buttons stop the click before the row can cancel the
             navigation - so pressing "new note" followed the href and
             reloaded the page instead. -->
        <div
            :data-folder-row="isFolder ? node.id : undefined"
            :data-note-row="isFolder ? undefined : node.id"
            data-tree-row
            :data-tree-key="node.key"
            :data-parent-key="parentKey ?? undefined"
            :data-drop-zone="zone ?? undefined"
            :aria-expanded="isFolder && hasChildren ? isOpen : undefined"
            :aria-selected="isSelected"
            role="treeitem"
            tabindex="-1"
            class="group relative flex items-center gap-1.5 px-2 py-1 rounded-md border transition-colors min-w-0 text-sm outline-none focus-visible:ring-2 focus-visible:ring-accent-500/60"
            :class="[
                isDropInside
                    ? 'bg-accent-600/15 text-accent-400 border-accent-600/40 ring-2 ring-accent-500'
                    : isSelected
                        ? 'bg-surface-2 font-medium text-primary border-transparent'
                        : 'hover:bg-surface-2 text-primary border-transparent',
                isBeingDragged ? 'opacity-40' : '',
                draggable ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
            ]"
            :style="indentStyle"
            :draggable="draggable"
            v-on:dragstart="emit('drag-start', node, $event)"
            v-on:dragend="emit('drag-end', $event)"
            v-on:dragover="emit('drag-over', node, $event)"
            v-on:dragleave="emit('drag-leave', node, $event)"
            v-on:drop="emit('drop', node, $event)"
            v-on:contextmenu="onContextMenu"
            v-on:dblclick="onDoubleClick"
        >
            <!-- The insertion line: we file at that rank, not at the bottom. -->
            <span
                v-if="'before' === zone || 'after' === zone"
                aria-hidden="true"
                class="pointer-events-none absolute left-1 right-1 h-0.5 rounded-full bg-accent-500"
                :class="'before' === zone ? '-top-px' : '-bottom-px'"
            />

            <AppIconButton
                v-if="isFolder && hasChildren"
                class="-ml-1 -my-0.5 shrink-0"
                tabindex="-1"
                :title="isOpen ? $t('shared.common.collapse') : $t('shared.common.expand')"
                v-on:click.stop="emit('toggle', node)"
            >
                <ChevronDown v-if="isOpen" class="w-3 h-3" :stroke-width="2" />
                <ChevronRight v-else class="w-3 h-3" :stroke-width="2" />
            </AppIconButton>
            <span v-else class="w-5 shrink-0" />

            <component
                :is="hrefFor ? 'a' : 'span'"
                :href="hrefFor ? hrefFor(node) : undefined"
                tabindex="-1"
                draggable="false"
                class="flex min-w-0 flex-1 items-center gap-2 no-underline"
                v-on:click="onRowClick"
            >
                <component
                    :is="isFolder ? (isOpen && hasChildren ? FolderOpen : Folder) : (node.template ? LayoutTemplate : FileText)"
                    :aria-label="!isFolder && node.template ? t('notes.markdown.template.badge') : undefined"
                    class="w-4 h-4 shrink-0"
                    :class="isSelected || isDropInside ? 'text-accent-400' : 'text-muted'"
                    :style="tint"
                    :stroke-width="2"
                />

                <span class="flex-1 truncate min-w-0">{{ displayLabel }}</span>

                <!-- What a folder holds, said once, and only when it is
                     folded: expanded, the answer is in view, and the number
                     is just noise. -->
                <span
                    v-if="isFolder && !isOpen && node.noteCount"
                    class="shrink-0 text-xs text-muted tabular-nums"
                >
                    {{ node.noteCount }}
                </span>
            </component>

            <!-- Per-row extension point. Wrapped so a client decorator
                 sits between the title and the hover action buttons. -->
            <slot name="extra-cells" :node="node" />

            <!-- With negative margins: the buttons keep their click area
                 without stretching the row. They pushed it up to 42 pixels, a
                 form height, where a file explorer fits in 30. -->
            <div v-if="!readonly" class="-my-1.5 sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-visible:opacity-100 flex items-center gap-0.5 transition-opacity shrink-0">
                <AppIconButton
                    v-if="isFolder && editable"
                    color="accent"
                    tabindex="-1"
                    :title="$t('notes.markdown.create_in_folder')"
                    v-on:click.stop="emit('add', node)"
                >
                    <Plus class="w-3.5 h-3.5" :stroke-width="2" />
                </AppIconButton>
                <AppRowActions
                    ref="actionsRef"
                    :actions="rowActions"
                    :label="displayLabel"
                    size="sm"
                />
            </div>
        </div>

        <!-- A rule along the children, like Obsidian: one sees at a glance
             what belongs to which folder, even three levels down. -->
        <div
            v-if="isFolder && hasChildren && isOpen"
            role="group"
            class="relative mt-0.5 space-y-0.5 before:pointer-events-none before:absolute before:top-0 before:bottom-0 before:w-px before:bg-line/70"
            :style="{ '--guide': `${depth * 0.875 + 0.95}rem` }"
            :class="'before:left-[var(--guide)]'"
        >
            <NoteTreeItem
                v-for="child in children"
                :key="child.key"
                :node="child"
                :selected-key="selectedKey"
                :expanded="expanded"
                :draggable="draggable"
                :readonly="readonly"
                :editable="editable"
                :exportable="exportable"
                :dragging-key="draggingKey"
                :drop-hint="dropHint"
                :parent-key="node.key"
                :depth="depth + 1"
                :href-for="hrefFor"
                v-on:select="(n) => emit('select', n)"
                v-on:toggle="(n) => emit('toggle', n)"
                v-on:add="(n) => emit('add', n)"
                v-on:rename="(n) => emit('rename', n)"
                v-on:favorite="(n) => emit('favorite', n)"
                v-on:delete="(n) => emit('delete', n)"
                v-on:export="(n) => emit('export', n)"
                v-on:drag-start="(n, e) => emit('drag-start', n, e)"
                v-on:drag-end="(e) => emit('drag-end', e)"
                v-on:drag-over="(n, e) => emit('drag-over', n, e)"
                v-on:drag-leave="(n, e) => emit('drag-leave', n, e)"
                v-on:drop="(n, e) => emit('drop', n, e)"
            >
                <!-- Forward the slot recursively so descendants render the
                     same decoration. Without this template the slot would
                     stop at depth 0. -->
                <template #extra-cells="data">
                    <slot name="extra-cells" v-bind="data" />
                </template>
            </NoteTreeItem>
        </div>
    </div>
</template>
