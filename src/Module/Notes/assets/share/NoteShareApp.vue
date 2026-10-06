<script setup>
import "@notes/suite/markdown/components/preview.css";
import "@notes/share/appearance.css";
import "@notes/share/print.css";

import { computed, onMounted, onUnmounted } from "vue";
import { useI18n } from "vue-i18n";
import { useMarkdownRenderer } from "@notes/suite/markdown/composables/useMarkdownRenderer.js";
import { shareHtml } from "@notes/share/useSharedNoteHtml.js";
import { withoutLeadingTitle } from "@notes/suite/markdown/composables/noteBody.js";
import { lightWhilePrinting } from "@notes/share/useNotePrint.js";

const props = defineProps({
    imagePrefix: { type: String, required: true },
    shareImagePath: { type: String, required: true },
    shareNotePath: { type: String, required: true },
    noteId: { type: Number, required: true },
    noteTitle: { type: String, default: "" },
    content: { type: String, default: "" },
    /** list<{id, title}> - every note of the share, titles only. */
    tree: { type: Array, default: () => [] },
    /** lower-cased title -> id, for resolving `[[links]]` inside the share. */
    titleIndex: { type: Object, default: () => ({}) },
    /**
     * The note's banner: `{url, creditName, creditUrl, position}`.
     *
     * The image stays with whoever hosts it; we only keep its address. The
     * credit goes with it because the licence requires it, and because there
     * is no longer a media library record to carry it.
     */
    cover: { type: Object, default: null },
    /** {@see NoteAppearanceEnum} - the note's background and its ink. */
    appearance: { type: String, default: "plain" },
    /** The way back to the editor, when reading one's own note. */
    backPath: { type: String, default: "" },
});

const { t } = useI18n();
const { render } = useMarkdownRenderer();

// Paper is light: the dark theme steps aside while printing, on the reader
// as on a share, from the button as from Ctrl+P.
let stopPrintTheme = () => {};
onMounted(() => {
    stopPrintTheme = lightWhilePrinting();
});
onUnmounted(() => stopPrintTheme());

const html = computed(() =>
    // The page already writes the title above the body.
    shareHtml(render(withoutLeadingTitle(props.content, props.noteTitle)), {
        imagePrefix: props.imagePrefix,
        shareImagePath: props.shareImagePath,
        shareNotePath: props.shareNotePath,
        titleIndex: props.titleIndex,
    }),
);

// The list only earns its place when the share carries more than the one note.
const hasTree = computed(() => props.tree.length > 1);

function titleOf(node) {
    return node.title?.trim() || t("notes.markdown.untitled");
}

const coverUrl = computed(() => props.cover?.url || "");

/**
 * Where to cut the photo, as a percentage of its height.
 *
 * A banner shows a strip of an image that was not framed for it: without
 * this setting, a portrait shows a forehead or a chin.
 */
const coverStyle = computed(() => ({
    objectPosition: `50% ${Number(props.cover?.position ?? 50)}%`,
}));

// `plain` sets no class: a note without styling follows the person's light
// or dark theme, and a class that repainted it in hard colours would take
// that choice away.
const lookClass = computed(() =>
    "plain" === props.appearance ? "" : `note-look note-look-${props.appearance}`,
);
</script>

<template>
    <div class="flex flex-col gap-2 sm:gap-4 md:flex-row md:items-start">
        <nav
            v-if="hasTree"
            class="aurora-card w-full shrink-0 p-2 md:w-64 print:hidden"
            :aria-label="t('notes.markdown.share.tree_label')"
        >
            <ul class="flex flex-col">
                <li v-for="node in tree" :key="node.id">
                    <a
                        :href="shareNotePath.replace('__id__', String(node.id))"
                        class="block truncate rounded-md px-2 py-1.5 text-sm transition-colors"
                        :class="
                            node.id === noteId
                                ? 'bg-surface-2 font-medium text-primary'
                                : 'text-secondary hover:bg-surface-2'
                        "
                        :style="{ paddingLeft: '0.5rem' }"
                    >{{ titleOf(node) }}</a>
                </li>
            </ul>
        </nav>

        <article
            class="note-print-article aurora-card min-w-0 flex-1 overflow-hidden"
            :class="lookClass"
        >
            <!-- The banner, when the note has one. The image lives with
                 whoever hosts it: if it disappears from there, the frame
                 stays empty and another one is picked. -->
            <figure v-if="coverUrl" class="relative m-0">
                <img
                    :src="coverUrl"
                    :alt="''"
                    class="h-48 w-full object-cover sm:h-72"
                    :style="coverStyle"
                    loading="lazy"
                >
                <figcaption
                    v-if="cover?.creditName"
                    class="note-look-caption absolute bottom-0 right-0 bg-black/40 px-2 py-0.5 text-2xs text-white"
                >
                    <a
                        v-if="cover?.creditUrl"
                        :href="cover.creditUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-white no-underline hover:underline"
                    >{{ t("notes.markdown.cover.credit", { name: cover.creditName }) }}</a>
                    <span v-else>{{ t("notes.markdown.cover.credit", { name: cover.creditName }) }}</span>
                </figcaption>
            </figure>

            <div class="p-4 sm:p-5">
                <h2 class="mb-4 text-xl font-semibold text-primary">
                    {{ noteTitle?.trim() || t("notes.markdown.untitled") }}
                </h2>
                <!-- eslint-disable-next-line vue/no-v-html -- the renderer sanitises
                 through DOMPurify before this ever reaches the page. -->
                <!-- The same classes as the editor preview: without
                     `prose`, a list lost its bullets and its indent, and
                     the note read online no longer looked like the note
                     as written. Seen at 375 px on the shared page. -->
                <div class="note-preview prose prose-sm dark:prose-invert max-w-none" v-html="html" />

                <!-- The way back, only when reading one's own note: a guest
                 has no editor to go back to. -->
                <a
                    v-if="backPath"
                    :href="backPath"
                    class="mt-6 inline-block text-xs text-muted no-underline transition-colors hover:text-primary print:hidden"
                >{{ t("notes.markdown.read.back") }}</a>
            </div>
        </article>
    </div>
</template>
