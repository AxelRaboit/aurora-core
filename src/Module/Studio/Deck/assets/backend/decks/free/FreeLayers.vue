<script setup>
/**
 * The slide's elements as a list, front first.
 *
 * The list is the stacking order turned upside down: what is on top of the
 * slide is at the top of the list, as in every editor that has layers. Dragged
 * here, an element goes behind or in front of the others; picked here, it is
 * selected even when another covers it on the slide, which is the only way to
 * reach a shape hidden under a photograph.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { VueDraggable } from "vue-draggable-plus";
import {
    ChartColumn,
    Film,
    GripVertical,
    Image as ImageIcon,
    Lock,
    LockOpen,
    Shapes,
    Smile,
    Table,
    Type,
    Youtube,
} from "lucide-vue-next";

const props = defineProps({
    editor: { type: Object, required: true },
    editable: { type: Boolean, default: true },
});

const { t } = useI18n();

const editor = props.editor;

const ICONS = { text: Type, image: ImageIcon, video: Film, embed: Youtube, shape: Shapes, icon: Smile, chart: ChartColumn, table: Table };

/** Front first. */
const rows = computed(() => [...editor.elements.value].reverse());

function reorder(list) {
    editor.setElements([...list].reverse());
}

/** What a row is called: its name, else the start of its words, else its kind. */
function nameOf(element) {
    if (element.name) return element.name;

    if (element.type === "text") {
        const words = String(element.html ?? "").replace(/<[^>]*>/g, " ").replace(/&nbsp;/g, " ").replace(/\s+/g, " ").trim();

        if (words) return words.slice(0, 40);
    }

    if (element.type === "shape") return t(`backend.studio.decks.free.shapes.${element.shape}`);
    if (element.type === "icon") return element.icon;

    return t(`backend.studio.decks.free.types.${element.type}`);
}

const renaming = ref(null);

function rename(element, value) {
    editor.patch([element.id], { name: value.trim() || null }, { coalesce: `name.${element.id}` });
}

function pick(element, event) {
    editor.select([element.id], { add: event.shiftKey || event.metaKey || event.ctrlKey });
}
</script>

<template>
    <div class="flex flex-col gap-1">
        <p class="m-0 text-xs font-semibold uppercase tracking-wide text-muted">
            {{ t("backend.studio.decks.free.layers") }}
            <span class="tabular-nums">{{ rows.length }}</span>
        </p>
        <p v-if="!rows.length" class="m-0 text-xs text-muted">{{ t("backend.studio.decks.free.layers_empty") }}</p>
        <VueDraggable
            :model-value="rows"
            handle=".layer-handle"
            :animation="120"
            :disabled="!editable"
            class="flex flex-col gap-0.5"
            v-on:update:model-value="reorder"
        >
            <div
                v-for="element in rows"
                :key="element.id"
                class="group flex items-center gap-1.5 rounded-md px-1.5 py-1"
                :class="editor.selection.value.includes(element.id) ? 'bg-accent-600/15' : 'hover:bg-surface-2'"
            >
                <span v-if="editable" class="layer-handle cursor-grab text-muted active:cursor-grabbing" :title="t('backend.studio.decks.drag_hint')">
                    <GripVertical class="h-3.5 w-3.5" :stroke-width="2" />
                </span>
                <component :is="ICONS[element.type] ?? Shapes" class="h-3.5 w-3.5 shrink-0 text-muted" :stroke-width="2" />
                <input
                    v-if="renaming === element.id"
                    class="min-w-0 flex-1 rounded border border-line bg-surface px-1 text-xs text-primary outline-none"
                    :value="element.name ?? ''"
                    :placeholder="nameOf(element)"
                    autofocus
                    v-on:change="(event) => rename(element, event.target.value)"
                    v-on:blur="renaming = null"
                    v-on:keydown.enter="renaming = null"
                >
                <button
                    v-else
                    type="button"
                    class="min-w-0 flex-1 cursor-pointer truncate border-0 bg-transparent p-0 text-left text-xs text-primary"
                    :class="element.group ? 'pl-2 border-l border-accent-500/50' : ''"
                    v-on:click="(event) => pick(element, event)"
                    v-on:dblclick="renaming = element.id"
                >
                    {{ nameOf(element) }}
                </button>
                <button
                    type="button"
                    class="cursor-pointer border-0 bg-transparent p-0.5 text-muted transition-opacity hover:text-primary"
                    :class="element.locked ? '' : 'opacity-0 group-hover:opacity-100'"
                    :title="element.locked ? t('backend.studio.decks.free.unlock') : t('backend.studio.decks.free.lock')"
                    v-on:click="editor.patch([element.id], { locked: element.locked ? null : true })"
                >
                    <Lock v-if="element.locked" class="h-3.5 w-3.5" :stroke-width="2" />
                    <LockOpen v-else class="h-3.5 w-3.5" :stroke-width="2" />
                </button>
            </div>
        </VueDraggable>
    </div>
</template>
