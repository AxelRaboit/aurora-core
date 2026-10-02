<script setup>
/**
 * Les formulaires, en liste.
 *
 * **Une liste, et plus une entrée de menu par formulaire.** Le menu grandissait
 * à chaque création et ne disait rien de ce qui compte devant un formulaire :
 * est-il en ligne, combien de questions, est-ce que quelqu'un le remplit. Ce
 * sont les colonnes d'ici, sur le modèle des Présentations : le titre mène au
 * formulaire, le reste des gestes est dans le menu de ligne.
 *
 * La création demande un titre et un point de départ, rien d'autre : le reste
 * se règle dans le formulaire, devant ses questions.
 */
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useNarrowContainer } from "@/shared/composables/list/useNarrowContainer.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppCardActions from "@/shared/components/action/AppCardActions.vue";
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

const { t, d } = useI18n();
const { can } = usePrivileges();
const { request } = useRequest();
const { container, isNarrow } = useNarrowContainer();

const items = ref([...props.forms]);

const search = ref("");
const statusFilter = ref(null);

const statusOptions = computed(() => [
    { value: "active", label: t("backend.forms.list.status_active") },
    { value: "inactive", label: t("backend.forms.list.status_inactive") },
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
    return value ? d(new Date(value), "short") : null;
}

// ── Création ────────────────────────────────────────────────────────────────

/** Une icône par point de départ : on choisit en regardant, pas en lisant. */
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
        toast.success(t("backend.forms.created"));
        // Directement dans le formulaire : c'est là que le travail commence,
        // et revenir à la liste obligerait à le chercher pour l'ouvrir.
        if (data?.editPath) window.location.assign(data.editPath);
    },
});

function openCreate() {
    newForm.value = { title: "", template: "contact" };
    clearErrors();
    showCreate.value = true;
}

/** Le serveur range l'erreur d'un titre vide sous les traductions : c'est ici le seul titre. */
const titleError = computed(() => createErrors.value.translations ?? createErrors.value.title ?? "");

// ── Suppression ─────────────────────────────────────────────────────────────

const pendingDelete = ref(null);
const deleteLoading = ref(false);

async function doDelete() {
    if (deleteLoading.value || !pendingDelete.value) return;

    deleteLoading.value = true;
    try {
        const data = await request(buildPath(props.deletePathTemplate, { id: pendingDelete.value.id }));
        if (data?.success) {
            toast.success(t("backend.forms.deleted"));
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
            title: t("backend.forms.list.open"),
            description: t("backend.forms.list.open_hint"),
            onSelect: () => window.location.assign(form.editPath),
        },
    ];

    if (can("editorial.forms.delete")) {
        actions.push({
            key: "delete",
            color: "rose",
            icon: Trash2,
            title: t("shared.common.delete"),
            description: t("backend.forms.list.delete_hint"),
            onSelect: () => (pendingDelete.value = form),
        });
    }

    return actions;
}

const pageActions = computed(() =>
    can("editorial.forms.create")
        ? [{ key: "create", color: "accent", icon: Plus, title: t("backend.forms.create"), onSelect: openCreate }]
        : [],
);
</script>

<template>
    <div ref="container" class="space-y-2 sm:space-y-4">
        <AppListToolbar>
            <AppSearchInput v-model="search" :placeholder="t('backend.forms.list.search_placeholder')" />
            <template #inline>
                <AppSelect
                    v-model="statusFilter"
                    :options="statusOptions"
                    :placeholder="t('backend.forms.list.all_statuses')"
                />
            </template>
            <template #actions>
                <AppPageActions v-if="pageActions.length" :actions="pageActions" class="w-full sm:w-auto" />
            </template>
        </AppListToolbar>
        <!-- Le mode d'emploi de l'écran, à côté de ce qu'il explique ;
             replié une fois, il le reste (`storage-key`). -->
        <AppGuide :title="t('backend.forms.guide.title')" storage-key="forms-list">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 5" :key="step">{{ t(`backend.forms.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <AppNoData
            v-if="!items.length"
            :icon="ClipboardList"
            :message="t('backend.forms.list.empty_title')"
            :hint="t('backend.forms.list.empty_hint')"
        >
            <template v-if="can('editorial.forms.create')" #action>
                <AppButton variant="primary" size="md" v-on:click="openCreate">
                    <Plus class="h-4 w-4" :stroke-width="2" /> {{ t("backend.forms.create") }}
                </AppButton>
            </template>
        </AppNoData>

        <AppNoData v-else-if="!filteredItems.length" :icon="ClipboardList" :message="t('backend.forms.list.no_match')" />

        <div v-else-if="!isNarrow" class="aurora-card overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-xs uppercase tracking-wide text-muted">
                        <th class="px-4 py-2 text-left font-medium">{{ t("backend.forms.list.title_column") }}</th>
                        <th class="px-4 py-2 text-left font-medium">{{ t("backend.forms.list.status_column") }}</th>
                        <th class="hidden px-4 py-2 text-right font-medium lg:table-cell">{{ t("backend.forms.list.fields_column") }}</th>
                        <th class="px-4 py-2 text-right font-medium">{{ t("backend.forms.list.submissions_column") }}</th>
                        <th class="hidden px-4 py-2 text-left font-medium lg:table-cell">{{ t("backend.forms.list.last_column") }}</th>
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
                            <!-- Le titre est le lien : ouvrir le formulaire est
                                 le geste qu'on vient faire ici. -->
                            <a class="block font-medium text-primary no-underline hover:text-accent" :href="form.editPath">{{ form.title }}</a>
                            <span v-if="form.description" class="block text-xs text-muted line-clamp-1">{{ form.description }}</span>
                        </td>
                        <td class="px-4 py-2">
                            <AppBadge :color="form.active ? 'emerald' : 'gray'">
                                {{ t(form.active ? "backend.forms.list.status_active" : "backend.forms.list.status_inactive") }}
                            </AppBadge>
                        </td>
                        <td class="hidden px-4 py-2 text-right tabular-nums text-secondary lg:table-cell">
                            {{ form.fieldCount }}
                            <span v-if="form.stepCount" class="text-xs text-muted">
                                · {{ t("backend.forms.list.steps", { count: form.stepCount }) }}
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
                            {{ formatDate(form.lastSubmittedAt) ?? t("backend.forms.list.never") }}
                        </td>
                        <td class="sticky right-0 border-l border-line/40 bg-surface px-4 py-2 text-right">
                            <AppRowActions :actions="actionsFor(form)" :label="form.title" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- La même ligne, lue de haut en bas, comme sur la liste des
             Présentations : le titre reste le lien, les gestes sont dépliés. -->
        <div v-else class="space-y-2">
            <article v-for="form in filteredItems" :key="form.id" class="aurora-card space-y-2.5 p-3">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <a class="block font-medium text-primary no-underline hover:text-accent" :href="form.editPath">{{ form.title }}</a>
                        <span v-if="form.description" class="block text-xs text-muted">{{ form.description }}</span>
                    </div>
                    <AppBadge class="shrink-0" :color="form.active ? 'emerald' : 'gray'">
                        {{ t(form.active ? "backend.forms.list.status_active" : "backend.forms.list.status_inactive") }}
                    </AppBadge>
                </div>

                <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                    <span class="tabular-nums">{{ t("backend.forms.list.fields", { count: form.fieldCount }) }}</span>
                    <span class="tabular-nums">{{ t("backend.forms.list.submissions", { count: form.submissionCount }) }}</span>
                    <span>{{ t("backend.forms.list.last_label") }} {{ formatDate(form.lastSubmittedAt) ?? t("backend.forms.list.never") }}</span>
                </p>

                <div class="border-t border-line/40 pt-2">
                    <AppCardActions :actions="actionsFor(form)" />
                </div>
            </article>
        </div>

        <AppModal
            :show="showCreate"
            max-width="lg"
            :closeable="false"
            :title="t('backend.forms.create')"
            :icon="ClipboardList"
            v-on:close="showCreate = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitCreate">
                <AppInput
                    v-model="newForm.title"
                    :label="t('backend.forms.title_label')"
                    :placeholder="t('backend.forms.create_title_placeholder')"
                    :hint="t('backend.forms.create_title_hint')"
                    :error="titleError"
                    required
                />

                <!-- Le point de départ en cartes et pas en liste déroulante :
                     ce qui les distingue, ce sont les questions qu'elles posent,
                     et une liste ne montre que des noms. -->
                <fieldset class="space-y-2">
                    <legend class="mb-2 text-xs uppercase tracking-wide text-secondary">{{ t("backend.forms.templates.title") }}</legend>
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
                        <ArrowRight class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("backend.forms.create_submit") }}
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
            <p class="text-sm text-primary">{{ t("backend.forms.delete_confirm", { title: pendingDelete?.title ?? "" }) }}</p>
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
