<script setup>
/**
 * The publications of a month, one row per post: a date, a format or a
 * network, a subject.
 *
 * The zone still stores the text the server reads (see calendarData.js), so
 * the table and the text are two views of one value, as for a chart. The rows
 * are kept here rather than read back on every keystroke: a row just added is
 * blank, the text has no line for it, and reading the text back would make it
 * vanish under the cursor.
 *
 * The second cell gives the colour: a format (carrousel, post, réel, story)
 * or a network (Instagram, LinkedIn…). The suggestions say so without
 * forbidding anything else.
 */
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Plus, Table2, TextCursorInput, Trash2 } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppDatePicker from "@/shared/components/form/picker/AppDatePicker.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import { MAX_CALENDAR_ENTRIES, formatCalendarEntries, parseCalendarEntries } from "./calendarData.js";

const props = defineProps({
    modelValue: { type: String, default: "" },
    /** The month shown, `YYYY-MM`: a new row starts on its first day. */
    month: { type: String, default: null },
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();

const asText = ref(false);
const listId = `calendar-kinds-${Math.random().toString(36).slice(2, 8)}`;
const KINDS = ["Carrousel", "Post", "Réel", "Story", "Instagram", "LinkedIn", "Facebook", "TikTok"];

function blank(date = "") {
    return { date, kind: "", title: "" };
}

function read(code) {
    const entries = parseCalendarEntries(code);

    return entries.length ? entries : [blank()];
}

const rows = ref(read(props.modelValue));

watch(
    () => props.modelValue,
    (code) => {
        if (formatCalendarEntries(rows.value) !== (code ?? "")) {
            rows.value = read(code);
        }
    },
);

function commit(next) {
    rows.value = next;
    emit("update:modelValue", formatCalendarEntries(next));
}

function update(index, patch) {
    commit(rows.value.map((row, i) => (i === index ? { ...row, ...patch } : row)));
}

function remove(index) {
    const next = rows.value.filter((_, i) => i !== index);

    commit(next.length ? next : [blank()]);
}

/** A new row in the same month as the last one, or the month shown. */
function add() {
    const last = rows.value[rows.value.length - 1]?.date ?? "";
    const month = /^\d{4}-\d{2}/.test(last) ? last.slice(0, 7) : props.month;

    rows.value = [...rows.value, blank(month ? `${month}-01` : "")];
}
</script>

<template>
    <div class="space-y-2">
        <div class="flex items-center justify-between gap-3">
            <span class="text-xs font-medium uppercase tracking-wide text-secondary">{{ t("suite.posts.grid.calendar_entries") }}</span>
            <AppButton variant="ghost" size="sm" v-on:click="asText = !asText">
                <template v-if="asText">
                    <Table2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("suite.posts.grid.chart_as_table") }}
                </template>
                <template v-else>
                    <TextCursorInput class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("suite.posts.grid.chart_as_text") }}
                </template>
            </AppButton>
        </div>

        <AppTextarea
            v-if="asText"
            :model-value="modelValue"
            :hint="t('suite.posts.grid.calendar_entries_hint')"
            :placeholder="t('suite.posts.grid.examples.calendar_data')"
            :rows="8"
            v-on:update:model-value="emit('update:modelValue', $event)"
        />

        <template v-else>
            <datalist :id="listId">
                <option v-for="kind in KINDS" :key="kind" :value="kind" />
            </datalist>
            <div
                v-for="(row, index) in rows"
                :key="index"
                role="group"
                :aria-label="t('suite.posts.grid.calendar_entry', { number: index + 1 })"
                class="flex flex-wrap items-center gap-2 sm:flex-nowrap"
            >
                <AppDatePicker
                    class="w-40 shrink-0"
                    :model-value="row.date"
                    :placeholder="t('suite.posts.grid.calendar_entry_date')"
                    v-on:update:model-value="update(index, { date: $event ?? '' })"
                />
                <AppInput
                    class="w-32 shrink-0"
                    :list="listId"
                    :model-value="row.kind"
                    :placeholder="t('suite.posts.grid.examples.calendar_entry_kind')"
                    :aria-label="t('suite.posts.grid.calendar_entry_kind')"
                    v-on:update:model-value="update(index, { kind: $event })"
                />
                <AppInput
                    class="min-w-0 flex-1"
                    :model-value="row.title"
                    :placeholder="t('suite.posts.grid.examples.calendar_entry_title')"
                    :aria-label="t('suite.posts.grid.calendar_entry_title')"
                    v-on:update:model-value="update(index, { title: $event })"
                />
                <AppIconButton color="rose" :title="t('suite.posts.grid.calendar_entry_remove')" v-on:click="remove(index)">
                    <Trash2 class="w-4 h-4" :stroke-width="2" />
                </AppIconButton>
            </div>

            <AppButton variant="ghost" size="sm" :disabled="rows.length >= MAX_CALENDAR_ENTRIES" v-on:click="add">
                <Plus class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("suite.posts.grid.calendar_entry_add") }}
            </AppButton>
            <p class="text-xs text-muted">{{ t("suite.posts.grid.calendar_entries_table_hint") }}</p>
        </template>
    </div>
</template>
