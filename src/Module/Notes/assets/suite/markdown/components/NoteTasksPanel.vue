<script setup>
/**
 * Every checkbox of every note, in one place (09/10/2026), as Obsidian's
 * Tasks and Craft's task view gather them: what is left to do, what is late,
 * by note, ticked right here.
 *
 * A due date is written in the task, `📅 2026-10-12`, as Obsidian Tasks
 * writes it; the cheat sheet says so.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { ListChecks } from "lucide-vue-next";
import AppModal from "@shared/components/overlay/AppModal.vue";
import AppSearchInput from "@shared/components/form/input/AppSearchInput.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import AppCheckbox from "@shared/components/form/toggle/AppCheckbox.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { foldText } from "@notes/suite/markdown/composables/noteEmoji.js";

const props = defineProps({
    show: { type: Boolean, default: false },
    /** `() => Promise<Array>`, every task of the notebook. */
    loadTasks: { type: Function, required: true },
    /** `(task) => Promise<boolean>`, ticks or unticks one in its note. */
    toggleTask: { type: Function, required: true },
});

const emit = defineEmits(["close", "open-note"]);

const { t } = useI18n();
const { formatDateShort } = useDateFormat();

const tasks = ref([]);
const loading = ref(false);
const filter = ref("todo");
const query = ref("");
const busy = ref(new Set());

const todayKey = new Date().toISOString().slice(0, 10);

async function refresh() {
    loading.value = true;
    tasks.value = (await props.loadTasks()) ?? [];
    loading.value = false;
}

watch(
    () => props.show,
    (open) => {
        if (open) void refresh();
    },
);

function isLate(task) {
    return !task.done && null !== task.due && task.due < todayKey;
}

const counts = computed(() => ({
    todo: tasks.value.filter((task) => !task.done).length,
    late: tasks.value.filter(isLate).length,
    all: tasks.value.length,
}));

const groups = computed(() => {
    const words = foldText(query.value).split(/\s+/).filter(Boolean);
    const shown = tasks.value
        .filter((task) => ("todo" === filter.value ? !task.done : "late" === filter.value ? isLate(task) : true))
        .filter((task) => words.every((word) => foldText(`${task.text} ${task.noteTitle ?? ""}`).includes(word)))
        // Dated first, soonest first; then the rest in the note's order.
        .sort((left, right) => (left.due ?? "9999").localeCompare(right.due ?? "9999"));

    const byNote = new Map();
    for (const task of shown) {
        if (!byNote.has(task.noteId)) byNote.set(task.noteId, { noteId: task.noteId, title: task.noteTitle, icon: task.noteIcon, tasks: [] });
        byNote.get(task.noteId).tasks.push(task);
    }

    return [...byNote.values()];
});

async function toggle(task) {
    const key = `${task.noteId}:${task.index}`;
    if (busy.value.has(key)) return;
    busy.value = new Set([...busy.value, key]);
    const done = await props.toggleTask(task);
    if (done) task.done = !task.done;
    busy.value = new Set([...busy.value].filter((one) => one !== key));
}

/** A task as it reads: `[[Note|alias]]` shows its alias, `[[Note#part]]` its note. */
function readable(text) {
    return String(text ?? "").replace(/\[\[([^\]|#]*)(?:#[^\]|]*)?(?:\|([^\]]*))?\]\]/g, (_, title, alias) => (alias || title).trim());
}

const FILTERS = ["todo", "late", "all"];
</script>

<template>
    <AppModal
        :show="show"
        max-width="2xl"
        :title="t('notes.markdown.tasks.title')"
        :icon="ListChecks"
        mobile-fullscreen
        v-on:close="emit('close')"
    >
        <div class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <div class="inline-flex gap-1 rounded-lg border border-line bg-surface-2 p-1">
                    <AppTab
                        v-for="option in FILTERS"
                        :key="option"
                        :data-note-tasks-filter="option"
                        size="sm"
                        :active="filter === option"
                        active-class="bg-surface text-primary shadow-sm"
                        inactive-class="text-secondary hover:text-primary"
                        v-on:click="filter = option"
                    >
                        {{ t(`notes.markdown.tasks.filters.${option}`) }}
                        <span class="ml-1 text-xs text-muted">{{ counts[option] }}</span>
                    </AppTab>
                </div>
                <AppSearchInput v-model="query" class="min-w-48 flex-1" :placeholder="t('notes.markdown.tasks.search')" :debounce="0" />
            </div>

            <p v-if="loading && 0 === tasks.length" class="py-6 text-center text-sm text-muted">…</p>
            <p v-else-if="0 === groups.length" data-note-tasks-empty class="py-6 text-center text-sm text-muted">
                {{ t('notes.markdown.tasks.empty') }}
            </p>

            <section v-for="group in groups" :key="group.noteId" class="flex flex-col gap-1">
                <button
                    type="button"
                    class="flex items-center gap-1.5 text-left text-xs font-semibold uppercase tracking-wide text-muted hover:text-primary"
                    v-on:click="emit('open-note', group.noteId)"
                >
                    <span v-if="group.icon">{{ group.icon }}</span>
                    {{ group.title || t('notes.markdown.untitled') }}
                </button>
                <ul class="m-0 flex list-none flex-col gap-0.5 p-0">
                    <li v-for="task in group.tasks" :key="`${task.noteId}-${task.index}`" data-note-task class="flex items-start gap-2 rounded-md px-1 py-1 hover:bg-surface-2">
                        <AppCheckbox
                            class="mt-0.5 shrink-0"
                            :model-value="task.done"
                            :disabled="task.noteLocked || busy.has(`${task.noteId}:${task.index}`)"
                            :aria-label="task.text"
                            v-on:update:model-value="toggle(task)"
                        />
                        <span class="min-w-0 flex-1 text-sm" :class="task.done ? 'text-muted line-through' : 'text-primary'">{{ readable(task.text) || '…' }}</span>
                        <span
                            v-if="task.due"
                            class="shrink-0 rounded-full px-2 py-0.5 text-2xs"
                            :class="isLate(task) ? 'bg-danger/15 text-danger' : 'bg-surface-2 text-muted'"
                        >📅 {{ formatDateShort(task.due) }}</span>
                    </li>
                </ul>
            </section>
        </div>
    </AppModal>
</template>
