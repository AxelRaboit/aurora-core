<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Eye, FileText, PanelTop, Users } from "lucide-vue-next";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppImagePickerField from "@/shared/components/form/file/AppImagePickerField.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import DeliverableScopePicker from "./DeliverableScopePicker.vue";
import { categoryOptions } from "../composables/categoryOptions.js";

/**
 * What surrounds the document: its name, its language, what its page header
 * says, and whether the client sees it.
 *
 * Visibility is here, spelled out, and not as a status: nothing is scheduled
 * or reviewed, a checkbox decides whether the client reads it in their space.
 * Reading links, for their part, open the deliverable whether it is visible
 * or not: that is a separate decision to send it.
 *
 * Without a space, no client: the section gives way to the deliverable's
 * shelf, personal or shared, which only its author changes. A Studio
 * deliverable can also be a template, and name the client it is written for
 * (the selector only shows with the right to see clients).
 */
const title = defineModel("title", { type: String, required: true });
const summary = defineModel("summary", { type: String, default: "" });
const locale = defineModel("locale", { type: String, required: true });
const readingHeader = defineModel("readingHeader", { type: Object, required: true });
const visibleToClient = defineModel("visibleToClient", { type: Boolean, default: false });
const scope = defineModel("scope", { type: String, default: null });
const categoryId = defineModel("categoryId", { type: [Number, null], default: null });
/** Studio only: offered when creating a deliverable. */
const template = defineModel("template", { type: Boolean, default: false });
/** Studio only: the client it is written for, before their space exists. */
const customerId = defineModel("customerId", { type: [Number, null], default: null });
/** The card image: `{ id, url }`, taken from the media library. */
const thumbnail = defineModel("thumbnail", { type: Object, default: () => ({ id: null, url: null }) });

const props = defineProps({
    locales: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    customerName: { type: String, default: "" },
    /** False for a Studio deliverable: there is no client to open it to. */
    withClient: { type: Boolean, default: true },
    /**
     * Show or hide from the client: the right to share the space. Without it,
     * the state can be read, the checkbox is not offered.
     */
    canShowToClient: { type: Boolean, default: true },
    canChangeScope: { type: Boolean, default: false },
    /** The Studio deliverable categories: `{ id, name, color }`. */
    categories: { type: Array, default: () => [] },
    /** The clients that can be named, `{ id, legalName }`; empty without the right to see them. */
    customers: { type: Array, default: () => [] },
    canPickCustomer: { type: Boolean, default: false },
    /** The [blanks] still in the document: a client should not read one. */
    placeholders: { type: Number, default: 0 },
    /** False for a slideshow: the header is the page's, and it has no page. */
    withReadingHeader: { type: Boolean, default: true },
});

const { t } = useI18n();

/** Each language's name in that language: that is how people look for it. */
const localeOptions = computed(() =>
    props.locales.map((code) => {
        let label = code.toUpperCase();
        try {
            const name = new Intl.DisplayNames([code], { type: "language" }).of(code);
            if (name) label = name.charAt(0).toUpperCase() + name.slice(1);
        } catch {
            // A browser without Intl.DisplayNames keeps the code.
        }

        return { value: code, label };
    }),
);

const categorySelectOptions = computed(() => categoryOptions(props.categories));

/** The selector speaks in strings, the deliverable in ids. */
const categoryValue = computed({
    get: () => (null === categoryId.value || undefined === categoryId.value ? "" : String(categoryId.value)),
    set: (value) => (categoryId.value = value ? Number(value) : null),
});

const customerSelectOptions = computed(() =>
    props.customers.map((customer) => ({ value: customer.id, label: customer.legalName })),
);

const customerValue = computed({
    get: () => (null === customerId.value || undefined === customerId.value ? "" : String(customerId.value)),
    set: (value) => (customerId.value = value ? Number(value) : null),
});

function setHeader(key, value) {
    readingHeader.value = { ...readingHeader.value, [key]: value };
}

const preparedFor = computed({
    get: () => readingHeader.value.preparedFor ?? "",
    set: (value) => setHeader("preparedFor", value),
});
const showDate = computed({
    get: () => false !== readingHeader.value.showDate,
    set: (value) => setHeader("showDate", value),
});
const showLogo = computed({
    get: () => false !== readingHeader.value.showLogo,
    set: (value) => setHeader("showLogo", value),
});
</script>

<template>
    <div class="space-y-4">
        <section class="aurora-card space-y-4 p-3 sm:p-5">
            <h3 class="m-0 flex items-center gap-2 text-sm font-semibold text-primary">
                <FileText class="h-4 w-4 text-muted" :stroke-width="2" /> {{ t("suite.studio.deliverables.settings.document_title") }}
            </h3>
            <AppInput
                v-model="title"
                :label="t('suite.studio.deliverables.title')"
                :placeholder="t('suite.studio.deliverables.title_placeholder')"
                :error="errors.title ?? ''"
                required
            />
            <AppTextarea
                v-model="summary"
                :label="t('suite.studio.deliverables.settings.summary')"
                :placeholder="t('suite.studio.deliverables.settings.summary_placeholder')"
                :hint="t(withClient ? 'suite.studio.deliverables.settings.summary_hint' : 'suite.studio.deliverables.settings.summary_hint_studio')"
                :rows="2"
            />
            <AppSelect
                v-if="locales.length > 1"
                v-model="locale"
                :label="t('suite.studio.deliverables.settings.locale')"
                :hint="t('suite.studio.deliverables.settings.locale_hint')"
                :options="localeOptions"
                :error="errors.locale ?? ''"
            />
            <!-- The card thumbnail, in both lists: you spot a deliverable by
                 its image before reading its title. -->
            <AppImagePickerField
                v-model="thumbnail"
                :label="t('suite.studio.deliverables.settings.thumbnail')"
                :hint="t('suite.studio.deliverables.settings.thumbnail_hint')"
                :size="96"
            />
            <!-- Studio only: a space deliverable is filed by its space. -->
            <AppSelect
                v-if="!withClient && scope"
                v-model="categoryValue"
                :label="t('suite.studio.deliverables.categories.label')"
                :placeholder="t('suite.studio.deliverables.categories.none')"
                :hint="t(categories.length ? 'suite.studio.deliverables.categories.settings_hint' : 'suite.studio.deliverables.categories.settings_empty_hint')"
                :options="categorySelectOptions"
            />
            <!-- Studio only, and with the right to see clients: the whole
                 client list is behind this selector. -->
            <AppSelect
                v-if="!withClient && scope && canPickCustomer"
                v-model="customerValue"
                :label="t('suite.studio.deliverables.customer.label')"
                :placeholder="t('suite.studio.deliverables.customer.none')"
                :hint="t('suite.studio.deliverables.customer.hint')"
                :options="customerSelectOptions"
            />
            <AppToggle
                v-if="!withClient && scope"
                v-model="template"
                :label="t('suite.studio.deliverables.template.toggle')"
                :hint="t('suite.studio.deliverables.template.toggle_hint')"
            />
        </section>

        <section v-if="!withClient && scope" class="aurora-card space-y-3 p-3 sm:p-5">
            <div>
                <h3 class="m-0 flex items-center gap-2 text-sm font-semibold text-primary">
                    <Users class="h-4 w-4 text-muted" :stroke-width="2" /> {{ t("suite.studio.deliverables.scope.label") }}
                </h3>
                <p class="m-0 mt-0.5 text-xs text-muted">
                    {{ t(canChangeScope ? "suite.studio.deliverables.scope.settings_hint" : "suite.studio.deliverables.scope.owner_only") }}
                </p>
            </div>
            <DeliverableScopePicker v-model="scope" :disabled="!canChangeScope" />
        </section>

        <section v-if="withClient" class="aurora-card space-y-4 p-3 sm:p-5">
            <h3 class="m-0 flex items-center gap-2 text-sm font-semibold text-primary">
                <Eye class="h-4 w-4 text-muted" :stroke-width="2" /> {{ t("suite.studio.deliverables.settings.client_title") }}
            </h3>
            <AppToggle
                v-if="canShowToClient"
                v-model="visibleToClient"
                :label="t('suite.studio.deliverables.settings.visible')"
                :hint="t('suite.studio.deliverables.settings.visible_hint')"
            />
            <p v-else class="m-0 text-sm text-secondary">
                {{ t(visibleToClient ? "suite.studio.deliverables.visible_badge" : "suite.studio.deliverables.hidden_badge") }}.
                <span class="text-muted">{{ t("suite.studio.client_visibility.share_needed") }}</span>
            </p>
            <p
                v-if="placeholders"
                class="rounded-md bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400"
            >
                {{ t(visibleToClient ? "suite.studio.deliverables.settings.placeholders_visible" : "suite.studio.deliverables.settings.placeholders_hidden", { count: placeholders }) }}
            </p>
        </section>

        <section v-if="withReadingHeader" class="aurora-card space-y-4 p-3 sm:p-5">
            <div>
                <h3 class="m-0 flex items-center gap-2 text-sm font-semibold text-primary">
                    <PanelTop class="h-4 w-4 text-muted" :stroke-width="2" /> {{ t("suite.studio.deliverables.settings.header_title") }}
                </h3>
                <p class="m-0 mt-0.5 text-xs text-muted">{{ t(withClient ? "suite.studio.deliverables.settings.header_hint" : "suite.studio.deliverables.settings.header_hint_studio") }}</p>
            </div>
            <AppInput
                v-model="preparedFor"
                :label="t('suite.studio.deliverables.settings.prepared_for')"
                :placeholder="customerName || t('suite.studio.deliverables.settings.prepared_for_placeholder')"
                :hint="t('suite.studio.deliverables.settings.prepared_for_hint')"
            />
            <AppToggle v-model="showDate" :label="t('suite.studio.deliverables.settings.show_date')" />
            <AppToggle v-model="showLogo" :label="t('suite.studio.deliverables.settings.show_logo')" />
        </section>
    </div>
</template>
