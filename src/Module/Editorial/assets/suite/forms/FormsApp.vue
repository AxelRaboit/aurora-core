<script setup>
/**
 * The forms, as a list.
 *
 * **A list, no longer one menu entry per form.** The menu grew with every
 * creation and said nothing of what matters about a form: is it online, how
 * many questions, is anyone filling it in. Those are the columns here,
 * modelled on Présentations: the title leads to the form, the other gestures
 * are in the row menu.
 *
 * Creation asks for a title and a starting point, nothing else: the rest is
 * set in the form, in front of its questions.
 */
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useNarrowContainer } from "@/shared/composables/list/useNarrowContainer.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import { ArrowRight, ClipboardList, FilePlus2, Mail, Plus, ReceiptText, Ticket, Trash2, X } from "lucide-vue-next";

const props = defineProps({
    forms: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    createPath: { type: String, required: true },
    deletePathTemplate: { type: String, required: true },
});

const { t, d: formatLocalizedDate } = useI18n();
const { can } = usePrivileges();
const { request } = useRequest();
const { container, isNarrow } = useNarrowContainer();

const items = ref([...props.forms]);

const search = ref("");
const statusFilter = ref(null);

const statusOptions = computed(() => [
    { value: "active", label: t("suite.forms.list.status_active") },
    { value: "inactive", label: t("suite.forms.list.status_inactive") },
]);

const filteredItems = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();

    return items.value.filter((form) => {
        if ("active" === statusFilter.value && !form.active) return false;
        if ("inactive" === statusFilter.value && form.active) return false;
        if (!needle) return true;

        return [form.title, form.description, form.reference]
            .filter(Boolean)
            .some((text) => text.toLocaleLowerCase().includes(needle));
    });
});

function formatDate(value) {
    return value ? formatLocalizedDate(new Date(value), "short") : null;
}

// ── Creation ────────────────────────────────────────────────────────────────

/** One icon per starting point: people choose by looking, not by reading. */
const TEMPLATE_ICONS = { blank: FilePlus2, contact: Mail, quote: ReceiptText, event: Ticket };

const showCreate = ref(false);
const newForm = ref({ title: "", template: "contact" });

const {
    errors: createErrors,
    loading: createLoading,
    submit: submitCreate,
    clearErrors,
} = useFormAction({
    url: () => props.createPath,
    body: () => newForm.value,
    onSuccess: (data) => {
        toast.success(t("suite.forms.created"));
        // Straight into the form: that is where the work starts, and going
        // back to the list would mean looking for it to open it.
        if (data?.editPath) window.location.assign(data.editPath);
    },
});

function openCreate() {
    newForm.value = { title: "", template: "contact" };
    clearErrors();
    showCreate.value = true;
}

/** The server files the empty-title error under the translations: here it is the only title. */
const titleError = computed(() => createErrors.value.translations ?? createErrors.value.title ?? "");

// ── Deletion ────────────────────────────────────────────────────────────────

const pendingDelete = ref(null);
const deleteLoading = ref(false);

async function doDelete() {
    if (deleteLoading.value || !pendingDelete.value) return;

    deleteLoading.value = true;
    try {
        const data = await request(buildPath(props.deletePathTemplate, { id: pendingDelete.value.id }));
        if (data?.success) {
            toast.success(t("suite.forms.deleted"));
            items.value = items.value.filter((form) => form.id !== pendingDelete.value.id);
            pendingDelete.value = null;
        }
    } finally {
        deleteLoading.value = false;
    }
}

function actionsFor(form) {
    const actions = [
        {
            key: "open",
            color: "accent",
            icon: ArrowRight,
            title: t("suite.forms.list.open"),
            description: t("suite.forms.list.open_hint"),
            onSelect: () => window.location.assign(form.editPath),
        },
    ];

    if (can("editorial.forms.delete")) {
        actions.push({
            key: "delete",
            color: "rose",
            icon: Trash2,
            title: t("shared.common.delete"),
            description: t("suite.forms.list.delete_hint"),
            onSelect: () => (pendingDelete.value = form),
        });
    }

    return actions;
}

const pageActions = computed(() =>
    can("editorial.forms.create")
        ? [{ key: "create", color: "accent", icon: Plus, title: t("suite.forms.create"), onSelect: openCreate }]
        : [],
);
</script>

<template>
    <div ref="container" class="aurora-stack">
        <AppListToolbar>
            <AppSearchInput v-model="search" :placeholder="t('suite.forms.list.search_placeholder')" />
            <template #inline>
                <AppSelect
                    v-model="statusFilter"
                    :options="statusOptions"
                    :placeholder="t('suite.forms.list.all_statuses')"
                />
            </template>
            <template #actions>
                <AppPageActions v-if="pageActions.length" :actions="pageActions" class="w-full sm:w-auto" />
            </template>
        </AppListToolbar>
        <!-- The screen's how-to guide, next to what it explains; collapsed
             or expanded, the choice applies to every guide. -->
        <AppGuide :title="t('suite.forms.guide.title')" storage-key="forms-list">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 5" :key="step">{{ t(`suite.forms.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <AppNoData
            v-if="!items.length"
            :icon="ClipboardList"
            :message="t('suite.forms.list.empty_title')"
            :hint="t('suite.forms.list.empty_hint')"
        >
            <template v-if="can('editorial.forms.create')" #action>
                <AppButton variant="primary" size="md" v-on:click="openCreate">
                    <Plus class="h-4 w-4" :stroke-width="2" /> {{ t("suite.forms.create") }}
                </AppButton>
            </template>
        </AppNoData>

        <AppNoData v-else-if="!filteredItems.length" :icon="ClipboardList" :message="t('suite.forms.list.no_match')" />

        <div v-else-if="!isNarrow" class="aurora-card overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-xs uppercase tracking-wide text-muted">
                        <th class="px-4 py-2 text-left font-medium">{{ t("suite.forms.list.title_column") }}</th>
                        <th class="px-4 py-2 text-left font-medium">{{ t("suite.forms.list.status_column") }}</th>
                        <th class="hidden px-4 py-2 text-right font-medium lg:table-cell">{{ t("suite.forms.list.fields_column") }}</th>
                        <th class="px-4 py-2 text-right font-medium">{{ t("suite.forms.list.submissions_column") }}</th>
                        <th class="hidden px-4 py-2 text-left font-medium lg:table-cell">{{ t("suite.forms.list.last_column") }}</th>
                        <th class="sticky right-0 border-l border-line/40 bg-surface-2 px-4 py-2 text-right font-medium">{{ t("shared.common.actions") }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="form in filteredItems"
                        :key="form.id"
                        class="border-b border-line last:border-0 hover:bg-surface-2/50"
                    >
                        <td class="px-4 py-2">
                            <!-- The title is the link: opening the form is
                                 the gesture people come here for. -->
                            <a class="block font-medium text-primary no-underline hover:text-accent" :href="form.editPath">{{ form.title }}</a>
                            <span v-if="form.description" class="block text-xs text-muted line-clamp-1">{{ form.description }}</span>
                        </td>
                        <td class="px-4 py-2">
                            <AppBadge :color="form.active ? 'emerald' : 'gray'">
                                {{ t(form.active ? "suite.forms.list.status_active" : "suite.forms.list.status_inactive") }}
                            </AppBadge>
                        </td>
                        <td class="hidden px-4 py-2 text-right tabular-nums text-secondary lg:table-cell">
                            {{ form.fieldCount }}
                            <span v-if="form.stepCount" class="text-xs text-muted">
                                · {{ t("suite.forms.list.steps", { count: form.stepCount }) }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            <a
                                v-if="form.submissionCount"
                                class="text-primary no-underline hover:text-accent"
                                :href="`${form.editPath}#submissions`"
                            >{{ form.submissionCount }}</a>
                            <span v-else class="text-muted">0</span>
                        </td>
                        <td class="hidden px-4 py-2 text-secondary lg:table-cell">
                            {{ formatDate(form.lastSubmittedAt) ?? t("suite.forms.list.never") }}
                        </td>
                        <td class="sticky right-0 border-l border-line/40 bg-surface px-4 py-2 text-right">
                            <AppRowActions :actions="actionsFor(form)" :label="form.title" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- The same row, read top to bottom, as on the Présentations list:
             the title stays the link, the gestures are behind the "…" button
             level with it (Axel's decision of 04/10/2026). -->
        <div v-else class="space-y-2">
            <article v-for="form in filteredItems" :key="form.id" class="aurora-card space-y-2.5 p-3">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <a class="block font-medium text-primary no-underline hover:text-accent" :href="form.editPath">{{ form.title }}</a>
                        <span v-if="form.description" class="block text-xs text-muted">{{ form.description }}</span>
                    </div>
                    <div class="flex shrink-0 items-start gap-1">
                        <AppBadge :color="form.active ? 'emerald' : 'gray'">
                            {{ t(form.active ? "suite.forms.list.status_active" : "suite.forms.list.status_inactive") }}
                        </AppBadge>
                        <AppRowActions :actions="actionsFor(form)" :label="form.title" />
                    </div>
                </div>

                <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                    <span class="tabular-nums">{{ t("suite.forms.list.fields", { count: form.fieldCount }) }}</span>
                    <span class="tabular-nums">{{ t("suite.forms.list.submissions", { count: form.submissionCount }) }}</span>
                    <span>{{ t("suite.forms.list.last_label") }} {{ formatDate(form.lastSubmittedAt) ?? t("suite.forms.list.never") }}</span>
                </p>
            </article>
        </div>

        <AppModal
            :show="showCreate"
            max-width="lg"
            :closeable="false"
            :title="t('suite.forms.create')"
            :icon="ClipboardList"
            v-on:close="showCreate = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitCreate">
                <AppInput
                    v-model="newForm.title"
                    :label="t('suite.forms.title_label')"
                    :placeholder="t('suite.forms.create_title_placeholder')"
                    :hint="t('suite.forms.create_title_hint')"
                    :error="titleError"
                    required
                />

                <!-- The starting point as cards and not a dropdown: what sets
                     them apart is the questions they ask, and a list shows
                     only names. -->
                <fieldset class="space-y-2">
                    <legend class="mb-2 text-xs uppercase tracking-wide text-secondary">{{ t("suite.forms.templates.title") }}</legend>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2" role="radiogroup">
                        <button
                            v-for="template in templates"
                            :key="template.value"
                            type="button"
                            role="radio"
                            :aria-checked="newForm.template === template.value"
                            class="flex items-start gap-3 rounded-lg border p-3 text-left transition-colors"
                            :class="newForm.template === template.value
                                ? 'border-accent-500 bg-accent-600/10'
                                : 'border-line hover:border-line-strong hover:bg-surface-2'"
                            v-on:click="newForm.template = template.value"
                        >
                            <component
                                :is="TEMPLATE_ICONS[template.value] ?? FilePlus2"
                                class="mt-0.5 h-4 w-4 shrink-0"
                                :class="newForm.template === template.value ? 'text-accent-400' : 'text-muted'"
                                :stroke-width="2"
                            />
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-primary">{{ t(template.labelKey) }}</span>
                                <span class="mt-0.5 block text-xs text-muted">{{ t(template.descriptionKey) }}</span>
                            </span>
                        </button>
                    </div>
                </fieldset>
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showCreate = false">
                        <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" :loading="createLoading" v-on:click="submitCreate">
                        <ArrowRight class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("suite.forms.create_submit") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="!!pendingDelete"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="pendingDelete = null"
        >
            <p class="text-sm text-primary">{{ t("suite.forms.delete_confirm", { title: pendingDelete?.title ?? "" }) }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDelete = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" :loading="deleteLoading" v-on:click="doDelete">
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
