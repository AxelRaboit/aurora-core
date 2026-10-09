<script setup>
/**
 * Quick search, Cmd/Ctrl+P (09/10/2026), as Notion and Obsidian have it:
 * a field, the notes whose title holds what is typed - the recent ones first
 * when nothing is typed yet - and Enter to open. A title nobody has written
 * yet offers to create the note.
 *
 * Cmd/Ctrl+K stays the suite's own search, which looks everywhere.
 */
import { computed, nextTick, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { FilePlus, FileText, Search } from "lucide-vue-next";
import AppModal from "@shared/components/overlay/AppModal.vue";
import AppSearchInput from "@shared/components/form/input/AppSearchInput.vue";
import { foldText } from "@notes/suite/markdown/composables/noteEmoji.js";

const props = defineProps({
    show: { type: Boolean, default: false },
    /** list<{id, title, updatedAt, icon?}> */
    notes: { type: Array, default: () => [] },
});

const emit = defineEmits(["close", "open", "create"]);

const { t } = useI18n();
const query = ref("");
const activeIndex = ref(0);
const input = ref(null);

watch(
    () => props.show,
    async (open) => {
        if (!open) return;
        query.value = "";
        activeIndex.value = 0;
        await nextTick();
        input.value?.focus();
    },
);

const MAX_RESULTS = 12;

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

const choiceCount = computed(() => results.value.length + (offersCreation.value ? 1 : 0));

watch(query, () => {
    activeIndex.value = 0;
});

function choose(index) {
    if (index < results.value.length) {
        emit("open", results.value[index].id);
    } else if (offersCreation.value) {
        emit("create", query.value.trim());
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
                <li v-if="offersCreation">
                    <button
                        type="button"
                        role="option"
                        data-note-quick-create
                        :aria-selected="results.length === activeIndex"
                        class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm"
                        :class="results.length === activeIndex ? 'bg-surface-2 text-primary' : 'text-secondary hover:bg-surface-2'"
                        v-on:mouseenter="activeIndex = results.length"
                        v-on:click="choose(results.length)"
                    >
                        <FilePlus class="h-4 w-4 shrink-0 text-accent-500" :stroke-width="2" />
                        <span class="truncate">{{ t('notes.markdown.quick_open.create', { title: query.trim() }) }}</span>
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
