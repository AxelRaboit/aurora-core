<script setup>
/**
 * A note shown as slides (09/10/2026), full screen, as Obsidian's Slides and
 * Notion's presentation mode: the title first, then one slide per `---` or,
 * without any, per heading. The arrows, Space and a click move on; Escape
 * leaves.
 *
 * Rendered like the note - formulas, diagrams, emoji, tables - so what is
 * shown is what was written.
 */
import "./preview.css";

import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronLeft, ChevronRight, X } from "lucide-vue-next";
import { useMarkdownRenderer } from "@notes/suite/markdown/composables/useMarkdownRenderer.js";
import { useFootnoteLabels, useNoteHtmlEnhancer } from "@notes/suite/markdown/composables/useNoteHtmlEnhancer.js";
import { splitSlides } from "@notes/suite/markdown/composables/noteSlides.js";
import { withoutLeadingTitle } from "@notes/suite/markdown/composables/noteBody.js";

const props = defineProps({
    title: { type: String, default: "" },
    icon: { type: String, default: null },
    content: { type: String, default: "" },
    /** `(html) => string`: a share page rewrites its images and links. */
    transformHtml: { type: Function, default: null },
});

const emit = defineEmits(["close"]);

const { t } = useI18n();
const { render } = useMarkdownRenderer({ footnotes: useFootnoteLabels() });

const slides = computed(() => splitSlides(withoutLeadingTitle(props.content, props.title)));
/** The title slide is slide 0. */
const index = ref(0);
const total = computed(() => slides.value.length + 1);

const html = computed(() => {
    if (0 === index.value) return "";
    const rendered = render(slides.value[index.value - 1] ?? "");

    return props.transformHtml ? props.transformHtml(rendered) : rendered;
});

const stage = ref(null);
const body = ref(null);
useNoteHtmlEnhancer(body, html);

function go(step) {
    index.value = Math.min(Math.max(index.value + step, 0), total.value - 1);
}

function onKeydown(event) {
    if ("Escape" === event.key) {
        event.preventDefault();
        emit("close");
    } else if (["ArrowRight", "ArrowDown", "PageDown", " "].includes(event.key)) {
        event.preventDefault();
        go(1);
    } else if (["ArrowLeft", "ArrowUp", "PageUp"].includes(event.key)) {
        event.preventDefault();
        go(-1);
    } else if ("Home" === event.key) {
        index.value = 0;
    } else if ("End" === event.key) {
        index.value = total.value - 1;
    }
}

/** A click on the slide moves on, except on a link, a box or a button. */
function onStageClick(event) {
    if (event.target instanceof Element && event.target.closest("a, button, input, summary, details")) return;
    go(event.clientX < window.innerWidth / 3 ? -1 : 1);
}

function onFullscreenChange() {
    // Leaving full screen with the browser's own Escape leaves the show.
    if (!document.fullscreenElement) emit("close");
}

onMounted(async () => {
    window.addEventListener("keydown", onKeydown);
    await nextTick();
    try {
        await stage.value?.requestFullscreen?.();
        document.addEventListener("fullscreenchange", onFullscreenChange);
    } catch {
        // Refused by the browser: the show stays in the window.
    }
});

onBeforeUnmount(() => {
    window.removeEventListener("keydown", onKeydown);
    document.removeEventListener("fullscreenchange", onFullscreenChange);
    if (document.fullscreenElement) void document.exitFullscreen?.();
});

watch(index, () => {
    if (body.value) body.value.scrollTop = 0;
});
</script>

<template>
    <div
        ref="stage"
        data-note-presentation
        class="fixed inset-0 z-[70] flex flex-col bg-surface text-primary"
        role="dialog"
        aria-modal="true"
        :aria-label="t('notes.markdown.present.label', { title: title || t('notes.markdown.untitled') })"
        v-on:click="onStageClick"
    >
        <div class="flex min-h-0 flex-1 items-center justify-center overflow-hidden p-6 sm:p-12">
            <div v-if="0 === index" data-note-slide-title class="flex max-w-4xl flex-col items-center gap-6 text-center">
                <span v-if="icon" class="text-7xl leading-none">{{ icon }}</span>
                <h1 class="m-0 text-4xl font-bold sm:text-6xl">{{ title || t('notes.markdown.untitled') }}</h1>
            </div>
            <!-- eslint-disable-next-line vue/no-v-html -- sanitised by the renderer, through DOMPurify. -->
            <div
                v-else
                ref="body"
                data-note-slide
                class="note-preview note-slide prose prose-lg dark:prose-invert max-h-full w-full max-w-5xl overflow-y-auto sm:prose-xl"
                v-html="html"
            />
        </div>

        <footer class="flex items-center justify-between gap-3 px-4 pb-4 text-sm text-muted" v-on:click.stop>
            <button type="button" class="rounded-md p-2 hover:bg-surface-2 hover:text-primary" :aria-label="t('notes.markdown.present.close')" v-on:click="emit('close')">
                <X class="h-5 w-5" :stroke-width="2" />
            </button>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="rounded-md p-2 hover:bg-surface-2 hover:text-primary disabled:opacity-30"
                    :disabled="0 === index"
                    :aria-label="t('notes.markdown.present.previous')"
                    v-on:click="go(-1)"
                >
                    <ChevronLeft class="h-5 w-5" :stroke-width="2" />
                </button>
                <span data-note-slide-count class="tabular-nums">{{ index + 1 }} / {{ total }}</span>
                <button
                    type="button"
                    class="rounded-md p-2 hover:bg-surface-2 hover:text-primary disabled:opacity-30"
                    :disabled="index === total - 1"
                    :aria-label="t('notes.markdown.present.next')"
                    v-on:click="go(1)"
                >
                    <ChevronRight class="h-5 w-5" :stroke-width="2" />
                </button>
            </div>
            <span class="w-9" aria-hidden="true" />
        </footer>
    </div>
</template>
