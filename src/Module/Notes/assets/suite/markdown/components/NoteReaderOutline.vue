<script setup>
/**
 * The note's outline beside the text, while reading on a wide screen.
 *
 * The editor has its outline in the side panel; reading had none, and a long
 * note (a brief, a procedure) was scrolled blind. The headings are read from
 * the rendered note rather than from the Markdown: they are the elements to
 * scroll to, and what the reader sees is what the outline names.
 *
 * The heading in view is highlighted as the text scrolls. Two headings or
 * more, or nothing: a single title needs no table of contents.
 */
import { nextTick, onUnmounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

const props = defineProps({
    /** Where the rendered note lives; its headings are looked for inside. */
    root: { type: Object, default: null },
});

const { t } = useI18n();

const headings = ref([]);
const activeIndex = ref(0);

let observer = null;

function collect() {
    const container = props.root?.querySelector(".note-preview");
    if (!container) return;

    const elements = [...container.querySelectorAll("h1, h2, h3")];
    const levels = elements.map((element) => Number(element.tagName.slice(1)));
    const top = Math.min(...levels, 6);

    headings.value = elements
        .map((element, index) => ({ element, text: element.textContent.trim(), depth: levels[index] - top }))
        .filter((heading) => "" !== heading.text);

    observer?.disconnect();
    // Not every browser has it (nor the test DOM): the outline still works,
    // only without following the scroll.
    if ("undefined" === typeof IntersectionObserver) return;
    observer = new IntersectionObserver(
        (entries) => {
            // The highest heading that has crossed into the upper part of
            // the screen is the one being read.
            const visible = entries.filter((entry) => entry.isIntersecting);
            if (!visible.length) return;
            const first = visible.sort((one, other) => one.boundingClientRect.top - other.boundingClientRect.top)[0];
            const index = headings.value.findIndex((heading) => heading.element === first.target);
            if (index >= 0) activeIndex.value = index;
        },
        { rootMargin: "-64px 0px -60% 0px" },
    );
    headings.value.forEach((heading) => observer.observe(heading.element));
}

function go(index) {
    activeIndex.value = index;
    headings.value[index].element.scrollIntoView({ behavior: "smooth", block: "start" });
}

// The root arrives once the parent has mounted it: its template ref is set
// after this component's own mount, so the outline waits for it.
watch(
    () => props.root,
    (root) => {
        if (root) void nextTick(collect);
    },
    { immediate: true },
);

onUnmounted(() => observer?.disconnect());
</script>

<template>
    <nav
        v-if="headings.length > 1"
        data-reader-outline
        class="flex flex-col gap-2"
        :aria-label="t('notes.markdown.outline.panel_title')"
    >
        <span class="px-2 text-xs font-semibold uppercase tracking-wide text-muted">{{ t('notes.markdown.outline.tab') }}</span>
        <ul class="m-0 flex list-none flex-col p-0">
            <li v-for="(heading, index) in headings" :key="index">
                <button
                    type="button"
                    class="w-full truncate rounded-md border-l-2 py-1 pr-2 text-left text-sm transition-colors hover:bg-surface-2 hover:text-primary"
                    :class="index === activeIndex ? 'border-accent-500 text-primary' : 'border-transparent text-muted'"
                    :style="{ paddingLeft: `${0.5 + heading.depth * 0.75}rem` }"
                    :aria-current="index === activeIndex ? 'location' : undefined"
                    v-on:click="go(index)"
                >
                    {{ heading.text }}
                </button>
            </li>
        </ul>
    </nav>
</template>
