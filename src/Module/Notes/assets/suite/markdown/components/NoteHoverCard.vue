<script setup>
/**
 * The card a linked note shows on hover (see `useWikiLinkHoverCard`).
 * Teleported to the page's body: inside a scrolled pane, it would be cut by
 * the pane's edge.
 */
defineProps({
    /** `{title, html, left, top, bottom}`, or null when nothing is shown. */
    card: { type: Object, default: null },
});

defineEmits(["enter", "leave"]);
</script>

<template>
    <Teleport to="body">
        <div
            v-if="card"
            data-note-hover-card
            class="fixed z-50 max-h-64 w-96 max-w-[calc(100vw-1rem)] overflow-hidden rounded-lg border border-line bg-surface p-3 shadow-xl"
            :style="{ left: `${card.left}px`, top: null === card.top ? null : `${card.top}px`, bottom: null === card.bottom ? null : `${card.bottom}px` }"
            v-on:mouseenter="$emit('enter')"
            v-on:mouseleave="$emit('leave')"
        >
            <p class="mb-1 truncate text-xs font-semibold text-muted">{{ card.title }}</p>
            <!-- eslint-disable-next-line vue/no-v-html -- rendered by the note renderer, which sanitises -->
            <div class="note-preview prose prose-sm dark:prose-invert max-w-none text-sm" v-html="card.html" />
            <div class="pointer-events-none absolute inset-x-0 bottom-0 h-10 bg-gradient-to-t from-surface to-transparent" />
        </div>
    </Teleport>
</template>
