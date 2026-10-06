<script setup>
/**
 * La page d'un client : toute sa fiche, et ce qui l'entoure.
 *
 * **Toute la fiche en un formulaire, en un seul endroit.** La liste la
 * modifiait dans une fenêtre, l'onglet Informations d'un espace dans une
 * autre, et les deux ne portaient pas les mêmes champs : le SIREN, le fixe,
 * les liens et les notes ne se saisissaient que depuis un espace. Ici, tout ;
 * ailleurs, on lit et on vient ici.
 *
 * À droite, ses espaces, ses contrats et ses livrables de Studio, en liens,
 * selon ce que le lecteur a le droit d'ouvrir. La disposition est celle des
 * écrans de détail : la barre (retour, « … », Enregistrer), le titre dessous.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { BadgeCheck, Save, Trash2 } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import AppMessage from "@/shared/components/feedback/AppMessage.vue";
import AppPageBar from "@/shared/components/nav/AppPageBar.vue";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import CustomerRelatedLists from "../components/CustomerRelatedLists.vue";
import ConvertProspectModal from "./components/ConvertProspectModal.vue";
import CustomerDeleteModal from "./components/CustomerDeleteModal.vue";
import CustomerFormFields from "./components/CustomerFormFields.vue";
import { useCustomerPage } from "./composables/useCustomerPage.js";

const props = defineProps({
    customer: { type: Object, required: true },
    /** `{contracts, deliverables, spaces}` : null pour ce que le lecteur ne peut pas ouvrir. */
    related: { type: Object, default: () => ({}) },
    currencies: { type: Array, default: () => [] },
    indexPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    convertPath: { type: String, required: true },
    deletePath: { type: String, required: true },
    /** La liste des espaces filtrée sur lui, ou null. */
    spacesPath: { type: String, default: null },
    /** La liste des contrats filtrée sur lui, ou null. */
    contractsPath: { type: String, default: null },
});

const { t } = useI18n();
const { can } = usePrivileges();

const canEdit = computed(() => can("studio.customers.edit"));

const {
    customer,
    form,
    dirty,
    errors,
    saving,
    save,
    pendingDelete,
    deleteLoading,
    confirmDelete,
    doDelete,
    conversion,
    openConversion,
} = useCustomerPage(props);

const { pending: converting, email: convertEmail, error: convertError, loading: convertLoading } = conversion;

const isProspect = computed(() => "prospect" === customer.value.status);

/**
 * L'attribut `inert` du formulaire, ou rien : Vue écrit `inert="false"` pour
 * un faux, et pour un navigateur la seule présence de l'attribut rend la zone
 * inerte. Il faut donc l'omettre quand on peut écrire.
 */
const inertWhenReadOnly = computed(() => (canEdit.value ? undefined : true));

/** Convertir, puis supprimer en dernier : le geste qui retire se lit après les autres. */
const pageActions = computed(() => {
    const actions = [];

    if (canEdit.value && isProspect.value) {
        actions.push({
            key: "convert",
            color: "emerald",
            icon: BadgeCheck,
            title: t("suite.studio.customers.convert"),
            description: t("suite.studio.customers.row_actions.convert_description"),
            onSelect: openConversion,
        });
    }

    if (can("studio.customers.delete")) {
        actions.push({
            key: "delete",
            color: "rose",
            icon: Trash2,
            title: t("shared.common.delete"),
            description: t("suite.studio.customers.row_actions.delete_description"),
            onSelect: () => confirmDelete(customer.value),
        });
    }

    return actions;
});
</script>

<template>
    <div class="aurora-stack">
        <AppPageBar :back-href="indexPath" :back-label="t('shared.common.back')">
            <AppPageActions
                v-if="pageActions.length"
                :actions="pageActions"
                :label="customer.legalName"
                icon-only-on-phone
            />
            <AppButton
                v-if="canEdit"
                variant="primary"
                size="md"
                :loading="saving"
                :disabled="!dirty || saving"
                :label="t('shared.common.save')"
                icon-only-on-phone
                v-on:click="save"
            >
                <Save class="h-4 w-4" :stroke-width="2" />
            </AppButton>
        </AppPageBar>

        <div class="min-w-0">
            <h1 class="m-0 break-words text-lg font-semibold text-primary">{{ customer.legalName }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-muted">
                <AppBadge :color="isProspect ? 'amber' : 'emerald'">{{ t(customer.statusLabel) }}</AppBadge>
                <span v-if="customer.legalForm">{{ customer.legalForm }}</span>
            </div>
        </div>

        <!-- Le mode d'emploi de l'écran, à côté de ce qu'il explique ;
             replié ou déplié, le choix vaut pour tous les encarts. -->
        <AppGuide :title="t('suite.studio.customers.page_guide.title')" storage-key="customer-page">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 4" :key="step">{{ t(`suite.studio.customers.page_guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <AppMessage v-if="!canEdit" variant="neutral">{{ t("suite.studio.customers.read_only") }}</AppMessage>

        <!-- La fiche à gauche, ce qui l'entoure à droite sur un grand écran ;
             l'une sous l'autre ailleurs. -->
        <div class="grid grid-cols-1 items-start aurora-gap lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <section class="aurora-card min-w-0 p-3 sm:p-4">
                <form :inert="inertWhenReadOnly" v-on:submit.prevent="save">
                    <CustomerFormFields v-model="form" :errors="errors" :currencies="currencies" />
                </form>
            </section>

            <section class="flex min-w-0 flex-col gap-3">
                <h2 class="m-0 text-xs font-semibold uppercase tracking-wider text-muted">
                    {{ t("suite.studio.customers.group_related") }}
                </h2>
                <article class="aurora-card p-3 sm:p-4">
                    <CustomerRelatedLists :related="related">
                        <!-- Ses listes complètes, au-delà de ce que la page résume. -->
                        <div v-if="spacesPath || contractsPath" class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
                            <a v-if="spacesPath" :href="spacesPath" class="text-accent-500 hover:underline">
                                {{ t("suite.studio.customers.related.all_spaces") }}
                            </a>
                            <a v-if="contractsPath" :href="contractsPath" class="text-accent-500 hover:underline">
                                {{ t("suite.studio.customers.related.all_contracts") }}
                            </a>
                        </div>
                    </CustomerRelatedLists>
                </article>
            </section>
        </div>

        <CustomerDeleteModal
            :show="!!pendingDelete"
            :name="customer.legalName"
            :loading="deleteLoading"
            v-on:cancel="pendingDelete = null"
            v-on:confirm="doDelete"
        />

        <ConvertProspectModal
            :show="!!converting"
            :name="customer.legalName"
            :model-value="convertEmail"
            :error="convertError"
            :loading="convertLoading"
            v-on:update:model-value="convertEmail = $event"
            v-on:close="conversion.close"
            v-on:submit="conversion.submit"
        />
    </div>
</template>
