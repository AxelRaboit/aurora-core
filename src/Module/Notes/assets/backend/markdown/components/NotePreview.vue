<script setup>
import './preview.css';

import { computed } from 'vue';
import { useMarkdownRenderer } from '@notes/backend/markdown/composables/useMarkdownRenderer.js';
import { usePreviewClickRouter } from '@notes/backend/markdown/composables/usePreviewClickRouter.js';
import { useNoteImageDragResize } from '@notes/backend/markdown/composables/useNoteImageDragResize.js';

const props = defineProps({
    content: { type: String, default: '' },
    noteTitles: { type: Array, default: () => [] }, // list<{id, title}>, used to resolve wiki-link clicks
});

const emit = defineEmits(['wiki-link-click', 'checkbox-toggle', 'image-resize']);

const { render, resolveWikiLink } = useMarkdownRenderer();
const html = computed(() => render(props.content));

const { route } = usePreviewClickRouter({
    resolveWikiLink,
    noteTitlesGetter: () => props.noteTitles,
});

function onClick(event) {
    const result = route(event);
    if (!result) return;
    if (result.kind === 'wiki-link') emit('wiki-link-click', result.payload);
    else if (result.kind === 'checkbox') emit('checkbox-toggle', result.payload.index);
}

const { onPointerDown } = useNoteImageDragResize({
    onResize: (payload) => emit('image-resize', payload),
});
</script>

<!--
    Styles for the rendered markdown (wiki-links, callouts, task checkboxes)
    live in `./preview.css` next to this SFC (co-located, code-split).
-->
<template>
    <div
        class="note-preview prose prose-sm dark:prose-invert max-w-none"
        v-on:click="onClick"
        v-on:pointerdown="onPointerDown"
        v-html="html"
    />
</template>
