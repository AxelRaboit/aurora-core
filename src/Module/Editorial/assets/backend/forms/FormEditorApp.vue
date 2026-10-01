<script setup>
/**
 * Un formulaire, sur sa propre page.
 *
 * Trois onglets, parce qu'on y vient pour trois choses différentes : composer
 * les questions, lire les réponses, régler ce qui l'entoure. Avant, les trois
 * s'empilaient sur une seule page, et les réglages vivaient dans une fenêtre
 * ouverte par le menu d'une carte. L'onglet est dans l'adresse : un lien vers
 * les réponses d'un formulaire s'envoie tel quel.
 *
 * L'état est partagé par `provide` entre les onglets et le panneau d'une
 * question : le même formulaire, mis à jour par chaque réponse du serveur.
 */
import { computed, provide, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { ExternalLink, Trash2, X } from "lucide-vue-next";
import AppBackLink from "@/shared/components/nav/AppBackLink.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useTabState } from "@/shared/composables/useTabState.js";
import { useFormFields } from "./composables/useFormFields.js";
import { useFormSettings } from "./composables/useFormSettings.js";
import FormFieldsTab from "./components/FormFieldsTab.vue";
import FormSettingsTab from "./components/FormSettingsTab.vue";
import FormSubmissionsTab from "./components/FormSubmissionsTab.vue";

const props = defineProps({
    form: { type: Object, required: true },
    publicPaths: { type: Object, default: () => ({}) },
    locales: { type: Array, default: () => [] },
    fieldTypes: { type: Array, default: () => [] },
    conditionLogics: { type: Array, default: () => [] },
    listPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
    fieldCreatePath: { type: String, required: true },
    fieldEditPathTemplate: { type: String, required: true },
    fieldDeletePathTemplate: { type: String, required: true },
    fieldReorderPath: { type: String, required: true },
    submissionsPath: { type: String, required: true },
    exportPath: { type: String, required: true },
});

const { t } = useI18n();
const { can } = usePrivileges();
const { request } = useRequest();

const current = ref({ ...props.form });

/** Chaque réponse du serveur porte le formulaire entier : on le remplace, sans recoller. */
function upsert(form) {
    if (!form) return;
    current.value = { ...form, submissionCount: current.value.submissionCount };
}

const primaryLocale = computed(() => props.locales[0] ?? "fr");

/** La langue qu'on édite, la même dans le panneau, l'aperçu et les réglages. */
const editLocale = ref(primaryLocale.value);

const title = computed(
    () => current.value.translations?.[primaryLocale.value]?.title || current.value.reference || `#${current.value.id}`,
);

function labelOf(field) {
    const translations = field.translations ?? {};
    const label = translations[primaryLocale.value]?.label || Object.values(translations).find((entry) => entry?.label)?.label;

    return label || t(`backend.forms.field_types.${field.type}`);
}

/**
 * L'adresse publique suit le slug enregistré, pas celui de la première
 * ouverture : après un changement de slug, le lien menait à une page 404.
 */
const publicPaths = computed(() =>
    Object.fromEntries(
        Object.entries(props.publicPaths).map(([locale, path]) => {
            const slug = current.value.translations?.[locale]?.slug;

            return [locale, slug ? path.replace(/[^/]+$/, slug) : path];
        }),
    ),
);

const fieldsApi = useFormFields(props, current, upsert);
const { settings, errors: settingsErrors, loading: settingsLoading, submit: submitSettings, savingSteps, saveSteps } = useFormSettings(props, current, upsert);

provide("formFields", fieldsApi);

// Une question s'ouvre toujours sur la langue principale. L'aperçu partage la
// langue du panneau : laissé en anglais après un coup d'œil, il faisait taper
// la question suivante en français dans sa version anglaise.
watch(fieldsApi.editorOpen, (open) => {
    if (open) editLocale.value = primaryLocale.value;
});
provide("formEditor", {
    locales: props.locales,
    fieldTypes: props.fieldTypes,
    conditionLogics: props.conditionLogics,
    steps: computed(() => current.value.steps),
    editLocale,
    labelOf,
    saveSteps,
    savingSteps,
    settings,
    settingsErrors,
    settingsLoading,
    submitSettings,
    publicPaths,
});

// English keys because they end up in the URL, like the post editor's.
const TABS = ["fields", "submissions", "settings"];
const { select: selectTab, isActive: isTabActive } = useTabState(TABS, { hash: true });

// ── En-tête ─────────────────────────────────────────────────────────────────

const pendingDelete = ref(false);
const deleteLoading = ref(false);

async function doDelete() {
    if (deleteLoading.value) return;

    deleteLoading.value = true;
    try {
        const data = await request(props.deletePath);
        if (data?.success) {
            toast.success(t("backend.forms.deleted"));
            window.location.assign(props.listPath);
        }
    } finally {
        deleteLoading.value = false;
    }
}

const headerActions = computed(() => {
    const actions = [];
    const path = publicPaths.value[primaryLocale.value];

    // Seulement en ligne : hors ligne, la page répond 404 et le lien mènerait
    // à une erreur qui ressemble à une panne.
    if (path && current.value.active) {
        actions.push({
            key: "public",
            icon: ExternalLink,
            title: t("backend.forms.editor.view_public"),
            description: t("backend.forms.editor.view_public_hint"),
            onSelect: () => window.open(path, "_blank", "noopener"),
        });
    }

    if (can("editorial.forms.delete")) {
        actions.push({
            key: "delete",
            color: "rose",
            icon: Trash2,
            title: t("shared.common.delete"),
            description: t("backend.forms.list.delete_hint"),
            onSelect: () => (pendingDelete.value = true),
        });
    }

    return actions;
});
</script>

<template>
    <div class="space-y-2 sm:space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
            <AppBackLink :href="listPath" :label="t('backend.forms.editor.back')" />
            <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                <!-- En ligne ou non, visible quel que soit l'onglet : on doit
                     savoir qu'on modifie un formulaire que des gens remplissent. -->
                <AppBadge :color="current.active ? 'emerald' : 'gray'">
                    {{ t(current.active ? "backend.forms.list.status_active" : "backend.forms.list.status_inactive") }}
                </AppBadge>
                <AppPageActions v-if="headerActions.length" :actions="headerActions" icon-only-on-phone />
            </div>
        </div>

        <div class="min-w-0">
            <h1 class="m-0 truncate text-lg font-semibold text-primary">{{ title }}</h1>
            <p v-if="current.translations?.[primaryLocale]?.description" class="m-0 mt-0.5 text-sm text-secondary">
                {{ current.translations[primaryLocale].description }}
            </p>
        </div>

        <div class="flex max-w-full gap-1 overflow-x-auto border-b border-line scrollbar-thin">
            <AppTab
                v-for="tab in TABS"
                :key="tab"
                class="shrink-0"
                variant="underline"
                :active="isTabActive(tab)"
                v-on:click="selectTab(tab)"
            >
                {{ t(`backend.forms.editor.tabs.${tab}`) }}
                <span v-if="'submissions' === tab && current.submissionCount" class="rounded-full bg-surface-2 px-1.5 text-2xs tabular-nums text-secondary">
                    {{ current.submissionCount }}
                </span>
            </AppTab>
        </div>

        <FormFieldsTab v-if="isTabActive('fields')" :form="current" />
        <FormSubmissionsTab v-else-if="isTabActive('submissions')" :submissions-path="submissionsPath" :export-path="exportPath" />
        <FormSettingsTab v-else />

        <AppModal
            :show="pendingDelete"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="pendingDelete = false"
        >
            <p class="text-sm text-primary">{{ t("backend.forms.delete_confirm", { title }) }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDelete = false">
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
