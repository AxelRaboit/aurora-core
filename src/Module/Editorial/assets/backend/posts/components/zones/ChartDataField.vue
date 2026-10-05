<script setup>
/**
 * The values of a chart, one row per value: a name, a number, a colour.
 *
 * The zone still stores the text the server reads (see chartData.js), so the
 * table and the text are two views of one value. The rows are kept here
 * rather than read back on every keystroke: a row just added is blank, and
 * the text has no line for it, so reading the text back would make it
 * vanish under the cursor. They are read again only when the text changes
 * from elsewhere - the text view, a locale switch, a section inserted.
 *
 * The text view stays for pasting: a column copied from a spreadsheet is one
 * paste there, and twenty rows here.
 */
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { VueDraggable } from "vue-draggable-plus";
import { GripVertical, Plus, Table2, TextCursorInput, Trash2, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppColorSwatch from "@/shared/components/form/picker/AppColorSwatch.vue";
import { MAX_CHART_ROWS, formatChartRows, parseChartRows } from "./chartData.js";

const props = defineProps({
    modelValue: { type: String, default: "" },
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();

const asText = ref(false);

/** An empty chart opens on one blank row, whose placeholders show what to type. */
function read(code) {
    const rows = parseChartRows(code);

    return rows.length ? rows : [blank()];
}

function blank() {
    return { label: "", value: "", color: null };
}

const rows = ref(read(props.modelValue));

watch(
    () => props.modelValue,
    (code) => {
        if (formatChartRows(rows.value) !== (code ?? "")) {
            rows.value = read(code);
        }
    },
);

function commit(next) {
    rows.value = next;
    emit("update:modelValue", formatChartRows(next));
}

function update(index, patch) {
    commit(rows.value.map((row, i) => (i === index ? { ...row, ...patch } : row)));
}

function remove(index) {
    const next = rows.value.filter((_, i) => i !== index);

    commit(next.length ? next : [blank()]);
}
</script>

<template>
    <div class="space-y-2">
        <div class="flex items-center justify-between gap-3">
            <span class="text-xs font-medium uppercase tracking-wide text-secondary">{{ t("backend.posts.grid.chart_data") }}</span>
            <AppButton variant="ghost" size="sm" v-on:click="asText = !asText">
                <template v-if="asText">
                    <Table2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.posts.grid.chart_as_table") }}
                </template>
                <template v-else>
                    <TextCursorInput class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.posts.grid.chart_as_text") }}
                </template>
            </AppButton>
        </div>

        <AppTextarea
            v-if="asText"
            :model-value="modelValue"
            :hint="t('backend.posts.grid.chart_data_hint')"
            :placeholder="t('backend.posts.grid.examples.chart_data')"
            :rows="6"
            v-on:update:model-value="emit('update:modelValue', $event)"
        />

        <template v-else>
            <!-- The grip is the handle: the rest of the row is inputs, and a
                 drag started in a text field is a selection gone wrong. -->
            <VueDraggable
                :model-value="rows"
                :animation="150"
                handle=".chart-row-handle"
                class="space-y-2"
                v-on:update:model-value="commit"
            >
                <div
                    v-for="(row, index) in rows"
                    :key="index"
                    role="group"
                    :aria-label="t('backend.posts.grid.chart_row', { number: index + 1 })"
                    class="flex items-center gap-2"
                >
                    <span class="chart-row-handle cursor-grab text-muted active:cursor-grabbing" :title="t('backend.posts.grid.chart_row_move')">
                        <GripVertical class="w-4 h-4" :stroke-width="2" />
                    </span>
                    <AppInput
                        class="min-w-0 flex-1"
                        :model-value="row.label"
                        :placeholder="t('backend.posts.grid.examples.chart_row_name')"
                        v-on:update:model-value="update(index, { label: $event })"
                    />
                    <AppInput
                        class="w-20 shrink-0"
                        :model-value="row.value"
                        :placeholder="t('backend.posts.grid.examples.chart_row_value')"
                        v-on:update:model-value="update(index, { value: $event })"
                    />
                    <!-- A colour input always holds a colour: dimmed, it says
                         « the theme's shade », which a black swatch would not. -->
                    <span :title="row.color ?? t('backend.posts.grid.chart_row_theme_color')" class="flex shrink-0">
                        <AppColorSwatch
                            size="sm"
                            :model-value="row.color ?? '#808080'"
                            :class="row.color ? '' : 'opacity-40'"
                            v-on:update:model-value="update(index, { color: $event })"
                        />
                    </span>
                    <AppIconButton
                        v-if="row.color"
                        :title="t('backend.posts.grid.chart_row_clear_color')"
                        v-on:click="update(index, { color: null })"
                    >
                        <X class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                    <AppIconButton color="rose" :title="t('backend.posts.grid.chart_row_remove')" v-on:click="remove(index)">
                        <Trash2 class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                </div>
            </VueDraggable>

            <AppButton
                variant="ghost"
                size="sm"
                :disabled="rows.length >= MAX_CHART_ROWS"
                v-on:click="rows = [...rows, blank()]"
            >
                <Plus class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.posts.grid.chart_row_add") }}
            </AppButton>
            <p class="text-xs text-muted">{{ t("backend.posts.grid.chart_rows_hint") }}</p>
        </template>
    </div>
</template>
