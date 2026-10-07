<script setup>
/**
 * The two questions that open a deliverable's creation: a page or a
 * presentation, and which template to start from.
 *
 * One copy for the two modals that ask them, Studio's and a space's
 * Deliverables tab: two copies of one form drift the day a format is added
 * to only one of them.
 *
 * The templates offered are those of the chosen format, drawn from the rows
 * received (`rows`): one does not start from a page to write a presentation.
 * Changing the format forgets a template of the other format. With no
 * template to offer, the picker is not drawn.
 */
import { computed, watch } from "vue";
import { useI18n } from "vue-i18n";
import AppChoiceRow from "@/shared/components/form/select/AppChoiceRow.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import { templateOptions } from "../composables/templateOptions.js";

/** A page or a presentation: decided here, once and for all. */
const format = defineModel("format", { type: String, default: "page" });
/** The chosen template's id, or the empty string to start from nothing. */
const template = defineModel("template", { type: [String, Number], default: "" });

const props = defineProps({
    /** The rows to look for templates in: `{ id, title, format, template, category }`. */
    rows: { type: Array, default: () => [] },
    /** The key of a format error sent back by the server. */
    error: { type: String, default: "" },
});

const { t } = useI18n();

const formatOptions = computed(() =>
    ["page", "slides"].map((value) => ({ value, label: t(`suite.studio.deliverables.formats.${value}`) })),
);

const templateSelectOptions = computed(() => templateOptions(props.rows, format.value));

/** What the chosen format is, and that it will not change. */
const formatHint = computed(() =>
    [
        "slides" === format.value
            ? t("suite.studio.deliverables.format.slides_hint")
            : t("suite.studio.deliverables.format.page_hint"),
        t("suite.studio.deliverables.format.fixed_hint"),
    ].join(" "),
);

/** Without a template one starts from nothing: a blank page or an empty presentation. */
const templatePlaceholder = computed(() =>
    "slides" === format.value
        ? t("suite.studio.deliverables.template.from_nothing_slides")
        : t("suite.studio.deliverables.template.from_nothing"),
);

watch(format, () => {
    if (!templateSelectOptions.value.some((option) => String(option.value) === String(template.value))) template.value = "";
});
</script>

<template>
    <!-- The format first, then the template: they decide everything that
         follows, and the templates offered are those of the chosen format. -->
    <AppChoiceRow
        v-model="format"
        :label="t('suite.studio.deliverables.format.label')"
        :hint="formatHint"
        :options="formatOptions"
    />
    <p v-if="error" class="m-0 text-xs text-red-500">{{ t(error) }}</p>
    <AppSelect
        v-if="templateSelectOptions.length"
        v-model="template"
        :label="t('suite.studio.deliverables.template.from')"
        :placeholder="templatePlaceholder"
        :hint="template ? t('suite.studio.deliverables.template.from_hint') : ''"
        :options="templateSelectOptions"
    />
</template>
