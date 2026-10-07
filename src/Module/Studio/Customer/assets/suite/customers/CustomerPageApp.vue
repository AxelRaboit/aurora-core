<script setup>
/**
 * A customer's page: their whole sheet, and what surrounds it.
 *
 * **The whole sheet in one form, in a single place.** The list edited it in
 * one dialog, a space's Informations tab in another, and the two did not
 * carry the same fields: the SIREN, landline, links and notes could only be
 * entered from a space. Here, everything; elsewhere, you read and come here.
 *
 * On the right, their spaces, contracts and Studio deliverables, as links,
 * depending on what the reader has the right to open. The layout is the one
 * of detail screens: the bar (back, "…", Enregistrer), the title below.
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
    /** `{contracts, deliverables, spaces}`: null for what the reader cannot open. */
    related: { type: Object, default: () => ({}) },
    currencies: { type: Array, default: () => [] },
    indexPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    convertPath: { type: String, required: true },
    deletePath: { type: String, required: true },
    /** The spaces list filtered on them, or null. */
    spacesPath: { type: String, default: null },
    /** The contracts list filtered on them, or null. */
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
 * The form's `inert` attribute, or nothing: Vue writes `inert="false"` for a
 * false, and for a browser the mere presence of the attribute makes the area
 * inert. So it must be omitted when writing is allowed.
 */
const inertWhenReadOnly = computed(() => (canEdit.value ? undefined : true));

/** Convert, then delete last: the gesture that removes reads after the others. */
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
        <AppPageBar :back-href="indexPath" :back-label="t('suite.studio.customers.title')">
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
            <h1 class="m-0 break-words text-xl font-semibold tracking-tight text-primary sm:text-2xl">{{ customer.legalName }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-muted">
                <AppBadge :color="isProspect ? 'amber' : 'emerald'">{{ t(customer.statusLabel) }}</AppBadge>
                <span v-if="customer.legalForm">{{ customer.legalForm }}</span>
                <!-- Said in words beside the title, as on a deliverable: the
                     greyed Enregistrer alone did not say why it woke up. -->
                <AppBadge v-if="dirty" color="amber">{{ t("shared.common.autosave.pending") }}</AppBadge>
            </div>
        </div>

        <!-- The screen's how-to, next to what it explains; collapsed or
             expanded, the choice applies to every guide. -->
        <AppGuide :title="t('suite.studio.customers.page_guide.title')" storage-key="customer-page">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 4" :key="step">{{ t(`suite.studio.customers.page_guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <AppMessage v-if="!canEdit" variant="neutral">{{ t("suite.studio.customers.read_only") }}</AppMessage>

        <!-- The sheet on the left, what surrounds it on the right on a large
             screen; one under the other elsewhere. -->
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
                        <!-- Their complete lists, beyond what the page summarizes. -->
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
