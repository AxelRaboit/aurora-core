<script setup>
import "@notes/backend/markdown/components/preview.css";

import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { useMarkdownRenderer } from "@notes/backend/markdown/composables/useMarkdownRenderer.js";
import { shareHtml } from "@notes/share/useSharedNoteHtml.js";

const props = defineProps({
    imagePrefix: { type: String, required: true },
    shareImagePath: { type: String, required: true },
    shareNotePath: { type: String, required: true },
    noteId: { type: Number, required: true },
    noteTitle: { type: String, default: "" },
    content: { type: String, default: "" },
    /** list<{id, title, parentId}> - the whole share, titles only. */
    tree: { type: Array, default: () => [] },
    /** lower-cased title -> id, for resolving `[[links]]` inside the share. */
    titleIndex: { type: Object, default: () => ({}) },
});

const { t } = useI18n();
const { render } = useMarkdownRenderer();

const html = computed(() =>
    shareHtml(render(props.content), {
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
</script>

<template>
    <div class="flex flex-col gap-4 md:flex-row md:items-start">
        <nav
            v-if="hasTree"
            class="w-full shrink-0 rounded-xl border border-line bg-surface p-2 md:w-64"
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
                        :style="{ paddingLeft: `${node.parentId ? 1.5 : 0.5}rem` }"
                    >{{ titleOf(node) }}</a>
                </li>
            </ul>
        </nav>

        <article class="min-w-0 flex-1 rounded-xl border border-line bg-surface p-4 sm:p-6">
            <h2 class="mb-4 text-xl font-semibold text-primary">
                {{ noteTitle?.trim() || t("notes.markdown.untitled") }}
            </h2>
            <!-- eslint-disable-next-line vue/no-v-html -- the renderer sanitises
                 through DOMPurify before this ever reaches the page. -->
            <div class="note-preview" v-html="html" />
        </article>
    </div>
</template>
