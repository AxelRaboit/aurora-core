<script setup>
/**
 * Une ligne de l'arborescence : un dossier, ou une note rangée dedans.
 *
 * Les deux se ressemblent et ne font pas la même chose. Un dossier se
 * déplie, se remplit, se supprime avec son contenu ; une note s'ouvre, et
 * c'est tout ce qu'elle fait ici - la lire, la renommer, la jeter, cela se
 * passe dans l'éditeur ou dans la bibliothèque.
 *
 * **Le dépliage est tenu par le panneau, pas par la ligne.** Un état local
 * repartirait fermé à chaque rendu de l'arbre, c'est-à-dire à chaque note
 * créée ailleurs, et une recherche ne pourrait pas ouvrir les branches où
 * elle a trouvé quelque chose.
 */
import { computed } from 'vue';
import { ChevronRight, ChevronDown, FileText, Folder, FolderOpen, Plus, Trash2 } from 'lucide-vue-next';
import AppIconButton from '@shared/components/action/AppIconButton.vue';

const props = defineProps({
    node: { type: Object, required: true },
    /** Le dossier ouvert dans la bibliothèque, ou la note ouverte. */
    selectedKey: { type: String, default: null },
    /** Les identifiants des dossiers dépliés, tenus par le panneau. */
    expanded: { type: Set, default: () => new Set() },
    draggable: { type: Boolean, default: false },
    draggingKey: { type: String, default: null },
    dragOverKey: { type: String, default: null },
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
    'create-note',
    'delete',
    'drag-start',
    'drag-end',
    'drag-over',
    'drag-leave',
    'drop',
]);

const isFolder = computed(() => 'folder' === props.node.kind);
const children = computed(() => props.node.children ?? []);
const hasChildren = computed(() => children.value.length > 0);
const isOpen = computed(() => props.expanded.has(Number(props.node.id)));
const isSelected = computed(() => props.selectedKey === props.node.key);
const isDragOver = computed(() => props.dragOverKey === props.node.key);
const isBeingDragged = computed(() => props.draggingKey === props.node.key);

/**
 * La couleur du dossier, quand il en porte une.
 *
 * En style et non en classe : la valeur vient du lecteur, et Tailwind
 * n'écrit que les classes qu'il voit dans le source. Elle passe devant la
 * classe de couleur sauf quand la ligne est choisie ou survolée par un
 * glisser : là, c'est l'état qui doit se voir, pas la décoration.
 */
const tint = computed(() =>
    isFolder.value && props.node.color && !isSelected.value && !isDragOver.value
        ? { color: props.node.color }
        : null,
);

const label = computed(() =>
    isFolder.value
        ? props.node.name || undefined
        : props.node.title || undefined,
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

// Indent applied to the row itself so its right edge stays flush with
// the sidebar (same convention as TermNode / media folder rows).
const indentStyle = computed(() => ({ marginLeft: `${props.depth * 1}rem` }));
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
            class="group flex items-center gap-2 px-3 py-2 rounded-lg border transition-colors min-w-0 text-sm"
            :class="[
                isDragOver
                    ? 'bg-accent-600/15 text-accent-400 border-accent-600/30 ring-2 ring-accent-500'
                    : isSelected
                        ? 'bg-accent-600/15 text-accent-400 border-accent-600/30'
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
        >
            <AppIconButton
                v-if="isFolder && hasChildren"
                size="sm"
                class="-ml-1 shrink-0"
                :title="isOpen ? $t('shared.common.collapse') : $t('shared.common.expand')"
                v-on:click.stop="emit('toggle', node)"
            >
                <ChevronDown v-if="isOpen" class="w-3 h-3" :stroke-width="2" />
                <ChevronRight v-else class="w-3 h-3" :stroke-width="2" />
            </AppIconButton>
            <span v-else class="w-4 shrink-0" />

            <component
                :is="hrefFor ? 'a' : 'span'"
                :href="hrefFor ? hrefFor(node) : undefined"
                class="flex min-w-0 flex-1 items-center gap-2 no-underline"
                v-on:click="onRowClick"
            >
                <component
                    :is="isFolder ? (isOpen && hasChildren ? FolderOpen : Folder) : FileText"
                    class="w-4 h-4 shrink-0"
                    :class="isSelected || isDragOver ? 'text-accent-400' : 'text-muted'"
                    :style="tint"
                    :stroke-width="2"
                />

                <span class="flex-1 truncate min-w-0">
                    {{ label || (isFolder ? $t('notes.markdown.folders.untitled') : $t('notes.markdown.untitled')) }}
                </span>

                <!-- Ce qu'un dossier contient, dit une fois, et seulement
                     quand il est replié : déplié, la réponse est sous les
                     yeux, et le nombre ne fait plus que du bruit. -->
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

            <div v-if="isFolder" class="sm:opacity-0 sm:group-hover:opacity-100 flex gap-0.5 transition-opacity shrink-0">
                <AppIconButton
                    size="sm"
                    color="accent"
                    :title="$t('notes.markdown.create_in_folder')"
                    v-on:click.stop="emit('create-note', node.id)"
                >
                    <Plus class="w-3.5 h-3.5" :stroke-width="2" />
                </AppIconButton>
                <AppIconButton
                    size="sm"
                    color="rose"
                    :title="$t('notes.markdown.folders.delete')"
                    v-on:click.stop="emit('delete', node)"
                >
                    <Trash2 class="w-3.5 h-3.5" :stroke-width="2" />
                </AppIconButton>
            </div>
        </div>

        <div v-if="isFolder && hasChildren && isOpen" class="space-y-0.5 mt-0.5">
            <NoteTreeItem
                v-for="child in children"
                :key="child.key"
                :node="child"
                :selected-key="selectedKey"
                :expanded="expanded"
                :draggable="draggable"
                :dragging-key="draggingKey"
                :drag-over-key="dragOverKey"
                :depth="depth + 1"
                :href-for="hrefFor"
                v-on:select="(n) => emit('select', n)"
                v-on:toggle="(n) => emit('toggle', n)"
                v-on:create-note="(id) => emit('create-note', id)"
                v-on:delete="(n) => emit('delete', n)"
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
