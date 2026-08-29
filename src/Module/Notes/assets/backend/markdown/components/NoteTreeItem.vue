<script setup>
import { ref, computed } from 'vue';
import { ChevronRight, ChevronDown, Folder, FileText, Plus, Trash2 } from 'lucide-vue-next';
import AppIconButton from '@shared/components/action/AppIconButton.vue';

const props = defineProps({
    node: { type: Object, required: true },
    selectedId: { type: Number, default: null },
    draggable: { type: Boolean, default: false },
    draggingId: { type: Number, default: null },
    dragOverId: { type: Number, default: null },
    depth: { type: Number, default: 0 },
});

const emit = defineEmits([
    'select',
    'create-child',
    'delete',
    'drag-start',
    'drag-end',
    'drag-over',
    'drag-leave',
    'drop',
]);

const expanded = ref(true);

const children = computed(() => props.node.children ?? []);
const hasChildren = computed(() => children.value.length > 0);
const isSelected = computed(() => props.selectedId === props.node.id);
const isDragOver = computed(() => props.dragOverId === props.node.id);
const isBeingDragged = computed(() => props.draggingId === props.node.id);

// Indent applied to the card itself so its right edge stays flush with
// the sidebar (same convention as TermNode / media folder rows).
const indentStyle = computed(() => ({ marginLeft: `${props.depth * 1}rem` }));
</script>

<template>
    <div>
        <div
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
            v-on:click="emit('select', node.id)"
            v-on:dragstart="emit('drag-start', node, $event)"
            v-on:dragend="emit('drag-end', $event)"
            v-on:dragover="emit('drag-over', node, $event)"
            v-on:dragleave="emit('drag-leave', node, $event)"
            v-on:drop="emit('drop', node, $event)"
        >
            <AppIconButton
                v-if="hasChildren"
                size="sm"
                variant="ghost"
                class="-ml-1 shrink-0"
                :title="expanded ? $t('shared.common.collapse') : $t('shared.common.expand')"
                v-on:click.stop="expanded = !expanded"
            >
                <ChevronDown v-if="expanded" class="w-3 h-3" :stroke-width="2" />
                <ChevronRight v-else class="w-3 h-3" :stroke-width="2" />
            </AppIconButton>
            <span v-else class="w-4 shrink-0" />

            <component
                :is="hasChildren ? Folder : FileText"
                class="w-4 h-4 shrink-0"
                :class="isSelected || isDragOver ? 'text-accent-400' : 'text-muted'"
                :stroke-width="2"
            />

            <span class="flex-1 truncate min-w-0">
                {{ node.title || $t('notes.markdown.untitled') }}
            </span>

            <!-- Per-row extension point. Wrapped so a client decorator
                 sits between the title and the hover action buttons. -->
            <slot name="extra-cells" :note="node" />

            <div class="opacity-0 group-hover:opacity-100 flex gap-0.5 transition-opacity shrink-0">
                <AppIconButton
                    size="sm"
                    color="accent"
                    :title="$t('notes.markdown.create_child')"
                    v-on:click.stop="emit('create-child', node.id)"
                >
                    <Plus class="w-3.5 h-3.5" :stroke-width="2" />
                </AppIconButton>
                <AppIconButton
                    size="sm"
                    color="rose"
                    :title="$t('notes.markdown.delete')"
                    v-on:click.stop="emit('delete', node)"
                >
                    <Trash2 class="w-3.5 h-3.5" :stroke-width="2" />
                </AppIconButton>
            </div>
        </div>

        <div v-if="hasChildren && expanded" class="space-y-0.5 mt-0.5">
            <NoteTreeItem
                v-for="child in children"
                :key="child.id"
                :node="child"
                :selected-id="selectedId"
                :draggable="draggable"
                :dragging-id="draggingId"
                :drag-over-id="dragOverId"
                :depth="depth + 1"
                v-on:select="(id) => emit('select', id)"
                v-on:create-child="(id) => emit('create-child', id)"
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
