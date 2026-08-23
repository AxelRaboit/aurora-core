<script setup>
/**
 * Full-width row used in vertical navigation lists - taxonomy
 * picker on the left, media folder sidebar, notes tree, etc.
 *
 * Visual language (matches the media folder sidebar):
 *   - Rounded-lg, gap-2 px-3 py-2, no permanent border.
 *   - Hovered state: surface-2 background.
 *   - Active state: accent-600/15 fill + accent-400 text + accent border.
 *   - Drag-over state (optional): ring-2 ring-accent-500 - wire by
 *     passing `dragOver` true while a drop is being targeted.
 *
 * Slots:
 *   - `icon` (optional): leading icon. Shrink-0 wrapper is provided.
 *   - default: main label.
 *   - `trailing` (optional): suffix content (counts, locks, etc.).
 */
defineOptions({ inheritAttrs: false });

defineProps({
    active: { type: Boolean, default: false },
    dragOver: { type: Boolean, default: false },
    type: { type: String, default: "button" },
});

const emit = defineEmits(["click"]);
</script>

<template>
    <button
        v-bind="$attrs"
        :type="type"
        class="w-full text-left px-3 py-2 rounded-lg transition-colors flex items-center gap-2 border"
        :class="[
            active
                ? 'bg-accent-600/15 text-accent-400 border-accent-600/30'
                : 'hover:bg-surface-2 text-primary border-transparent',
            dragOver ? 'ring-2 ring-accent-500' : '',
        ]"
        v-on:click="emit('click', $event)"
    >
        <span v-if="$slots.icon" class="shrink-0 flex items-center">
            <slot name="icon" />
        </span>
        <span class="flex-1 text-sm font-medium truncate min-w-0">
            <slot />
        </span>
        <span v-if="$slots.trailing" class="shrink-0 flex items-center gap-1">
            <slot name="trailing" />
        </span>
    </button>
</template>
