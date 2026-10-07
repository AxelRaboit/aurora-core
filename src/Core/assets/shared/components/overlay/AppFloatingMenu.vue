<script setup>
/**
 * Generic floating menu - a positioned dropdown listing selectable
 * items, styled to match Aurora surface tokens.
 *
 * Teleported to <body> and positioned `fixed`, in viewport coordinates. It
 * rendered inline and `absolute` at first, on the reasoning that a menu glued
 * to a textarea caret belongs inside the consumer's own layout. That is exactly
 * what broke it: the markdown editor sits in an `overflow-auto` pane, so a menu
 * opening near an edge was cut in half by the pane it was glued into. A
 * caret-anchored menu has to be able to leave its container.
 *
 * Consumers so far:
 *   - markdown editor: slash command palette ("/" at line start)
 *   - markdown editor: wiki-link autocomplete ("[[" inside a line)
 *
 * The default slot is scoped (`{ item, index, active }`) so each
 * consumer renders its own row - slash wants a monospace glyph + label,
 * wiki wants a Lucide icon + truncated title. The menu owns positioning,
 * keyboard-active highlight, mouseenter routing, and the mousedown.prevent
 * trick that lets a click pick an item before the trigger element blurs.
 *
 * Visibility is left to the parent (v-if on the wrapper). The menu only
 * renders when the parent decides to show it.
 *
 * The list scrolls, and two things say so. The keyboard-highlighted row is
 * kept in view: the slash palette has fifteen commands for eight visible
 * rows, and ArrowDown used to walk into rows nobody could see. And a fade
 * at the bottom shows there is more, since a Mac hides scrollbars until one
 * scrolls and the list then looked like it ended at the eighth command.
 */
import { nextTick, onMounted, ref, watch } from "vue";

const props = defineProps({
    /**
     * Items rendered as buttons. Each item should have a stable `id`
     * for the v-for key; the rest of the shape is consumer-defined and
     * forwarded to the scoped slot.
     */
    items: { type: Array, required: true },
    /**
     * `{ top, left }` in CSS pixels, relative to the nearest positioned
     * ancestor. Set by the consumer composable's mirror-div math.
     */
    position: {
        type: Object,
        required: true,
        validator: (position) => typeof position.top === "number" && typeof position.left === "number",
    },
    /**
     * Height cap in pixels, computed from the room actually left on the chosen
     * side. Absent means "use the default cap".
     */
    maxHeight: { type: Number, default: null },
    /**
     * Index of the keyboard-highlighted row. Driven by the consumer's
     * ArrowUp/ArrowDown handlers.
     */
    activeIndex: { type: Number, default: 0 },
    /** Tailwind class for min-width (default ~14rem). */
    minWidthClass: { type: String, default: "min-w-56" },
});

const emit = defineEmits(["select", "highlight"]);

const listRef = ref(null);
const moreBelow = ref(false);

function measure() {
    const list = listRef.value;
    if (!list) return;
    moreBelow.value = list.scrollHeight - list.scrollTop - list.clientHeight > 4;
}

watch(
    () => props.activeIndex,
    async (index) => {
        await nextTick();
        const row = listRef.value?.querySelectorAll("[data-floating-menu-item]")[index];
        row?.scrollIntoView?.({ block: "nearest" });
        measure();
    },
);

watch(
    () => props.items,
    async () => {
        await nextTick();
        measure();
    },
);

onMounted(measure);
</script>

<template>
    <Teleport to="body">
        <div
            data-floating-menu
            class="fixed z-50 overflow-hidden rounded-md border border-line bg-surface shadow-lg flex flex-col"
            :class="[minWidthClass, maxHeight === null ? 'max-h-64' : '']"
            :style="{
                top: `${position.top}px`,
                left: `${position.left}px`,
                ...(maxHeight === null ? {} : { maxHeight: `${maxHeight}px` }),
            }"
        >
            <!-- Optional sticky header (e.g. search bar reflecting an inline
             filter, section title, etc.). Sits above the scrolling list.
             The `data-floating-menu` attribute on the wrapper lets the
             trigger (textarea, etc.) detect that a blur target landed
             inside this menu and skip its auto-close. -->
            <div v-if="$slots.header" class="shrink-0 border-b border-line">
                <slot name="header" />
            </div>

            <!-- The list is what scrolls, not the menu: `min-h-0` lets it
             shrink under the height cap, so a header stays in place. -->
            <div ref="listRef" class="relative min-h-0 flex-1 overflow-auto py-1" v-on:scroll="measure">
                <template v-if="items.length > 0">
                    <button
                        v-for="(item, index) in items"
                        :key="item.id"
                        type="button"
                        data-floating-menu-item
                        class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm transition-colors"
                        :class="
                            index === activeIndex
                                ? 'bg-accent-500/15 text-primary'
                                : 'text-secondary hover:bg-surface-2'
                        "
                        v-on:mousedown.prevent="emit('select', item)"
                        v-on:mouseenter="emit('highlight', index)"
                    >
                        <slot :item="item" :index="index" :active="index === activeIndex" />
                    </button>
                </template>
                <!-- Empty state - rendered when `items` is empty. The slot
                 lets each consumer phrase the no-results message in its
                 own domain language. Falls back to a generic line if
                 the slot isn't provided. -->
                <div v-else class="px-3 py-2 text-xs text-muted italic text-center">
                    <slot name="empty">No results</slot>
                </div>
                <div
                    v-if="moreBelow"
                    data-floating-menu-more
                    aria-hidden="true"
                    class="pointer-events-none sticky bottom-0 -mt-6 h-6 bg-gradient-to-t from-surface to-transparent"
                />
            </div>
        </div>
    </Teleport>
</template>
