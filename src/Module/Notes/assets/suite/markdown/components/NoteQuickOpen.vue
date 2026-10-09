<script setup>
/**
 * Quick search, Cmd/Ctrl+P (09/10/2026), as Notion and Obsidian have it:
 * a field, the notes whose title holds what is typed - the recent ones first
 * when nothing is typed yet - and Enter to open. A title nobody has written
 * yet offers to create the note.
 *
 * Cmd/Ctrl+K stays the suite's own search, which looks everywhere.
 *
 * Below the titles, the notes whose text holds the words (10/10/2026), each
 * with the passage they were found in, and a last line that opens the full
 * search with what was typed.
 */
import { computed, nextTick, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { FilePlus, FileText, Search, TextSearch } from "lucide-vue-next";
import AppModal from "@shared/components/overlay/AppModal.vue";
import AppSearchInput from "@shared/components/form/input/AppSearchInput.vue";
import { foldText } from "@notes/suite/markdown/composables/noteEmoji.js";
import { findRanges, highlightParts } from "@notes/suite/markdown/composables/noteSearchHighlight.js";
import { useDebounce } from "@/shared/composables/useDebounce.js";

const props = defineProps({
    show: { type: Boolean, default: false },
    /** list<{id, title, updatedAt, icon?}> */
    notes: { type: Array, default: () => [] },
    /** (query) => Promise<{payload: {ids, snippets, needles}}>; none, no content section. */
    searchContent: { type: Function, default: null },
});

const emit = defineEmits(["close", "open", "open-found", "create", "search"]);

const { t } = useI18n();
const query = ref("");
const activeIndex = ref(0);
const input = ref(null);
/** What the server found in the notes' text, for the "in the content" section. */
const found = ref({ ids: [], snippets: {}, needles: [] });

watch(
    () => props.show,
    async (open) => {
        if (!open) return;
        query.value = "";
        activeIndex.value = 0;
        found.value = { ids: [], snippets: {}, needles: [] };
        await nextTick();
        input.value?.focus();
    },
);

const MAX_RESULTS = 12;
const MAX_FOUND = 6;

const results = computed(() => {
    const words = foldText(query.value).split(/\s+/).filter(Boolean);
    const byRecency = [...props.notes].sort((left, right) => Date.parse(right.updatedAt ?? 0) - Date.parse(left.updatedAt ?? 0));
    if (0 === words.length) return byRecency.slice(0, MAX_RESULTS);

    return byRecency
        .map((note) => ({ note, title: foldText(note.title ?? "") }))
        .filter(({ title }) => words.every((word) => title.includes(word)))
        .sort((left, right) => Number(!left.title.startsWith(words[0])) - Number(!right.title.startsWith(words[0])))
        .slice(0, MAX_RESULTS)
        .map(({ note }) => note);
});

/** Whether to offer creating a note with the title typed. */
const offersCreation = computed(() => {
    const wanted = query.value.trim();
    if ("" === wanted) return false;

    return !props.notes.some((note) => foldText(note.title ?? "") === foldText(wanted));
});

const runContentSearch = useDebounce(async (wanted) => {
    const { payload } = await props.searchContent(wanted);
    if (wanted !== query.value.trim()) return;
    found.value = {
        ids: payload?.ids ?? [],
        snippets: payload?.snippets ?? {},
        needles: payload?.needles ?? [],
    };
}, 250);

/** The notes found by their text and not already listed by their title. */
const contentResults = computed(() => {
    if ("" === query.value.trim()) return [];
    const listed = new Set(results.value.map((note) => Number(note.id)));
    const byId = new Map(props.notes.map((note) => [Number(note.id), note]));

    return found.value.ids
        .map(Number)
        .filter((id) => !listed.has(id) && byId.has(id))
        .slice(0, MAX_FOUND)
        .map((id) => {
            const text = found.value.snippets[id] ?? "";

            return { note: byId.get(id), snippet: text ? { text, ranges: findRanges(text, found.value.needles) } : null };
        });
});

const offersFullSearch = computed(() => null !== props.searchContent && "" !== query.value.trim());

/** Every line one can land on, in order: titles, content, create, search. */
const choices = computed(() => [
    ...results.value.map((note) => ({ kind: "title", note })),
    ...contentResults.value.map((one) => ({ kind: "content", ...one })),
    ...(offersCreation.value ? [{ kind: "create" }] : []),
    ...(offersFullSearch.value ? [{ kind: "search" }] : []),
]);

const choiceCount = computed(() => choices.value.length);

/** Index of the first line of a kind, to place the section headings. */
function indexOf(kind) {
    return choices.value.findIndex((choice) => choice.kind === kind);
}

watch(query, (value) => {
    activeIndex.value = 0;
    const wanted = value.trim();
    if ("" === wanted || null === props.searchContent) {
        found.value = { ids: [], snippets: {}, needles: [] };

        return;
    }
    runContentSearch(wanted);
});

function choose(index) {
    const choice = choices.value[index];
    if (!choice) return;
    if ("title" === choice.kind) {
        emit("open", choice.note.id);
    } else if ("content" === choice.kind) {
        emit("open-found", { id: choice.note.id, needles: found.value.needles });
    } else if ("create" === choice.kind) {
        emit("create", query.value.trim());
    } else {
        emit("search", query.value.trim());
    }
    emit("close");
}

function onKeydown(event) {
    if ("ArrowDown" === event.key) {
        event.preventDefault();
        activeIndex.value = Math.min(activeIndex.value + 1, choiceCount.value - 1);
    } else if ("ArrowUp" === event.key) {
        event.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
    } else if ("Enter" === event.key) {
        event.preventDefault();
        if (choiceCount.value > 0) choose(activeIndex.value);
    }
}
</script>

<template>
    <AppModal
        :show="show"
        max-width="xl"
        :title="t('notes.markdown.quick_open.title')"
        :icon="Search"
        v-on:close="emit('close')"
    >
        <div class="flex flex-col gap-3">
            <AppSearchInput
                ref="input"
                v-model="query"
                data-note-quick-open
                :placeholder="t('notes.markdown.quick_open.placeholder')"
                :debounce="0"
                :clearable="false"
                v-on:keydown="onKeydown"
            />
            <ul class="m-0 flex max-h-96 list-none flex-col overflow-auto p-0" role="listbox">
                <li v-for="(note, index) in results" :key="note.id">
                    <button
                        type="button"
                        role="option"
                        data-note-quick-result
                        :aria-selected="index === activeIndex"
                        class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm"
                        :class="index === activeIndex ? 'bg-surface-2 text-primary' : 'text-secondary hover:bg-surface-2'"
                        v-on:mouseenter="activeIndex = index"
                        v-on:click="choose(index)"
                    >
                        <span v-if="note.icon" class="w-4 shrink-0 text-center">{{ note.icon }}</span>
                        <FileText v-else class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
                        <span class="truncate">{{ note.title || t('notes.markdown.untitled') }}</span>
                    </button>
                </li>
                <template v-if="contentResults.length">
                    <li class="px-2 pb-1 pt-2 text-2xs font-semibold uppercase tracking-wide text-muted" role="presentation">
                        {{ t('notes.markdown.quick_open.in_content') }}
                    </li>
                    <li v-for="(one, position) in contentResults" :key="`content-${one.note.id}`">
                        <button
                            type="button"
                            role="option"
                            data-note-quick-found
                            :aria-selected="indexOf('content') + position === activeIndex"
                            class="flex w-full flex-col gap-0.5 rounded-md px-2 py-1.5 text-left text-sm"
                            :class="indexOf('content') + position === activeIndex ? 'bg-surface-2 text-primary' : 'text-secondary hover:bg-surface-2'"
                            v-on:mouseenter="activeIndex = indexOf('content') + position"
                            v-on:click="choose(indexOf('content') + position)"
                        >
                            <span class="flex min-w-0 items-center gap-2">
                                <span v-if="one.note.icon" class="w-4 shrink-0 text-center">{{ one.note.icon }}</span>
                                <FileText v-else class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
                                <span class="truncate">{{ one.note.title || t('notes.markdown.untitled') }}</span>
                            </span>
                            <span v-if="one.snippet" class="line-clamp-1 break-words pl-6 text-xs text-muted">
                                <template v-for="(part, partIndex) in highlightParts(one.snippet.text, one.snippet.ranges)" :key="partIndex">
                                    <mark v-if="part.mark" class="rounded bg-amber-300/40 px-0.5 text-inherit dark:bg-amber-400/30">{{ part.text }}</mark>
                                    <template v-else>{{ part.text }}</template>
                                </template>
                            </span>
                        </button>
                    </li>
                </template>
                <li v-if="offersCreation">
                    <button
                        type="button"
                        role="option"
                        data-note-quick-create
                        :aria-selected="indexOf('create') === activeIndex"
                        class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm"
                        :class="indexOf('create') === activeIndex ? 'bg-surface-2 text-primary' : 'text-secondary hover:bg-surface-2'"
                        v-on:mouseenter="activeIndex = indexOf('create')"
                        v-on:click="choose(indexOf('create'))"
                    >
                        <FilePlus class="h-4 w-4 shrink-0 text-accent-500" :stroke-width="2" />
                        <span class="truncate">{{ t('notes.markdown.quick_open.create', { title: query.trim() }) }}</span>
                    </button>
                </li>
                <li v-if="offersFullSearch">
                    <button
                        type="button"
                        role="option"
                        data-note-quick-search
                        :aria-selected="indexOf('search') === activeIndex"
                        class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm"
                        :class="indexOf('search') === activeIndex ? 'bg-surface-2 text-primary' : 'text-secondary hover:bg-surface-2'"
                        v-on:mouseenter="activeIndex = indexOf('search')"
                        v-on:click="choose(indexOf('search'))"
                    >
                        <TextSearch class="h-4 w-4 shrink-0 text-accent-500" :stroke-width="2" />
                        <span class="truncate">{{ t('notes.markdown.search.in_content', { query: query.trim() }) }}</span>
                    </button>
                </li>
                <li v-if="0 === choiceCount" class="px-2 py-4 text-center text-sm text-muted">
                    {{ t('notes.markdown.quick_open.empty') }}
                </li>
            </ul>
            <p class="m-0 text-2xs text-muted">{{ t('notes.markdown.quick_open.hint') }}</p>
        </div>
    </AppModal>
</template>
