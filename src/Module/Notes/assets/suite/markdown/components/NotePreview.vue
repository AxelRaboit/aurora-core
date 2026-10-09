<script setup>
import './preview.css';

import { computed, ref } from 'vue';
import { useMarkdownRenderer } from '@notes/suite/markdown/composables/useMarkdownRenderer.js';
import { usePreviewClickRouter } from '@notes/suite/markdown/composables/usePreviewClickRouter.js';
import { useNoteImageDragResize } from '@notes/suite/markdown/composables/useNoteImageDragResize.js';
import { useFootnoteLabels, useNoteHtmlEnhancer } from '@notes/suite/markdown/composables/useNoteHtmlEnhancer.js';
import { markdownSection } from '@notes/suite/markdown/composables/noteHtmlEnhancer.js';
import { noteExcerpt, useWikiLinkHoverCard } from '@notes/suite/markdown/composables/useWikiLinkHoverCard.js';
import NoteHoverCard from '@notes/suite/markdown/components/NoteHoverCard.vue';

// Two roots since the hover card (09/10/2026): what a parent passes goes to
// the rendered note, as before.
defineOptions({ inheritAttrs: false });

const props = defineProps({
    content: { type: String, default: '' },
    noteTitles: { type: Array, default: () => [] }, // list<{id, title}>, used to resolve wiki-link clicks
    /**
     * `(id) => Promise<string|null>`: another note's text, for `![[Note]]`.
     * Absent where the preview has no notebook behind it (a template's
     * preview in the library): inclusions then stay links.
     */
    loadNoteContent: { type: Function, default: null },
});

const emit = defineEmits(['wiki-link-click', 'checkbox-toggle', 'image-resize', 'tag-click']);

const { render, resolveWikiLink } = useMarkdownRenderer({ footnotes: useFootnoteLabels() });
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
    else if (result.kind === 'tag') emit('tag-click', result.payload.tag);
}

const { onPointerDown } = useNoteImageDragResize({
    onResize: (payload) => emit('image-resize', payload),
});

/**
 * Included notes, kept for twenty seconds: the preview renders on every
 * keystroke, and fetching the included note each time would be a request per
 * letter typed elsewhere.
 */
const INCLUDED_NOTE_LIFETIME_MS = 20_000;
const includedNotes = new Map();

async function includedContent(id) {
    const kept = includedNotes.get(id);
    if (kept && Date.now() - kept.at < INCLUDED_NOTE_LIFETIME_MS) return kept.content;

    const content = await props.loadNoteContent(id);
    includedNotes.set(id, { content, at: Date.now() });

    return content;
}

async function loadEmbed({ title, heading }) {
    if (!props.loadNoteContent) return null;
    const id = resolveWikiLink(title, props.noteTitles);
    if (null === id) return null;

    const content = await includedContent(id);
    if (null === content || undefined === content) return null;

    return render(markdownSection(content, heading));
}

const root = ref(null);
useNoteHtmlEnhancer(root, html, { loadEmbed });

/** A linked note's beginning, on hover (09/10/2026). */
const { card, onCardEnter, onCardLeave } = useWikiLinkHoverCard(root, async (title, heading) => {
    if (!props.loadNoteContent) return null;
    const id = resolveWikiLink(title, props.noteTitles);
    if (null === id) return null;
    const content = await includedContent(id);
    if (null === content || undefined === content) return null;

    return render(noteExcerpt(markdownSection(content, heading)));
});
</script>

<!--
    Styles for the rendered markdown (wiki-links, callouts, task checkboxes)
    live in `./preview.css` next to this SFC (co-located, code-split).
-->
<template>
    <NoteHoverCard :card="card" v-on:enter="onCardEnter" v-on:leave="onCardLeave" />
    <div
        ref="root"
        v-bind="$attrs"
        class="note-preview prose prose-sm dark:prose-invert max-w-none"
        v-on:click="onClick"
        v-on:pointerdown="onPointerDown"
        v-html="html"
    />
</template>
