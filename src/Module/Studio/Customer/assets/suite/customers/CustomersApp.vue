<script setup>
import { computed } from "vue";
import { usePersistedChoice } from "@/shared/composables/usePersistedChoice.js";
import { useI18n } from "vue-i18n";
import { useMoneyFormat } from "@/shared/composables/format/useMoneyFormat.js";
import { useNarrowContainer } from "@/shared/composables/list/useNarrowContainer.js";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useCustomerRowActions } from "./composables/useCustomerRowActions.js";
import { useProspectConversion } from "./composables/useProspectConversion.js";
import ConvertProspectModal from "./components/ConvertProspectModal.vue";
import { useCustomersForm } from "./composables/useCustomersForm.js";
import CustomerFormFields from "./components/CustomerFormFields.vue";
import CustomerDeleteModal from "./components/CustomerDeleteModal.vue";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { Building2, Plus, Save, X } from "lucide-vue-next";
import AppGuide from "@/shared/components/feedback/AppGuide.vue";

const { t } = useI18n();
const { formatMoney } = useMoneyFormat();
const { container, isNarrow } = useNarrowContainer();
const { can } = usePrivileges();

const props = defineProps({
    customers: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    createPath: { type: String, required: true },
    /** A customer's page, with `__id__`: the sheet is edited there. */
    showPath: { type: String, required: true },
    convertPath: { type: String, required: true },
    deletePath: { type: String, required: true },
    /** The spaces list, reached from a customer. */
    spacesPath: { type: String, default: "" },
});

const {
    search,
    filteredItems,
    applyUpdatedList,
    showCreate,
    newCustomer,
    createErrors,
    createLoading,
    openCreate,
    submitCreate,
    pendingDelete,
    deleteLoading,
    confirmDelete,
    doDelete,
} = useCustomersForm(props.customers, props.createPath, props.deletePath);

// Its own rather than the shared edit/delete pair: a prospect has a third
// thing to offer. See the composable.
const {
    pending: converting,
    email: convertEmail,
    error: convertError,
    loading: convertLoading,
    open: openConversion,
    close: closeConversion,
    submit: submitConversion,
} = useProspectConversion(props.convertPath, (data) => applyUpdatedList(data));

const actionsFor = useCustomerRowActions({
    showPath: props.showPath,
    spacesPath: props.spacesPath,
    can,
    convertToClient: (customer) =>
        openConversion(customer, {
            id: customer.id,
            name: customer.legalName,
            email: customer.contractualEmail,
        }),
    confirmDelete,
});

/**
 * Customers or prospects, never both.
 *
 * Remembered from one screen to the next, like a space's views: someone
 * working their leads for a whole morning does not want to choose again
 * every time they come back to the list.
 */
const { choice: tab } = usePersistedChoice("studio.customers.tab", "client", [
    "client",
    "prospect",
]);

const visibleItems = computed(() =>
    filteredItems.value.filter((customer) => customer.status === tab.value),
);

const tabs = computed(() =>
    ["client", "prospect"].map((key) => ({
        key,
        label: t(`suite.studio.customers.statuses.${key}_plural`),
        count: filteredItems.value.filter((customer) => customer.status === key)
            .length,
    })),
);

/** The customer's page: the name leads there, on the list as on the cards. */
function pageOf(customer) {
    return buildPath(props.showPath, { id: customer.id });
}

/**
 * A SIRET is read back in the groups it is printed in, not as fourteen run-on
 * digits: 3 + 3 + 3 + 5, the way it appears on every document it comes from.
 */
function formatSiret(siret) {
    if (!siret) return "-";

    return (
        siret.replace(/^(\d{3})(\d{3})(\d{3})(\d{5})$/, "$1 $2 $3 $4") || siret
    );
}

// A capital is a round figure far more often than not, so the decimals show
// only when they carry something - which is what formatMoney does.
function formatCapital(customer) {
    return formatMoney(customer.shareCapitalCents, customer.shareCapitalCurrency ?? "EUR");
}

// The create verb is marked `primary`: AppPageActions sets it beside the
// sheet as the page's main button, and the sheet keeps whatever else there is.
const pageActions = computed(() => {
    if (!can("studio.customers.create")) {
        return [];
    }

    return [
        {
            key: "create",
            primary: true,
            color: "accent",
            icon: Plus,
            title: t("suite.studio.customers.add"),
            onSelect: openCreate,
        },
    ];
});
</script>

<template>
    <div ref="container" class="aurora-stack">
        <AppListToolbar>
            <AppSearchInput
                v-model="search"
                :placeholder="t('suite.studio.customers.search_placeholder')"
            />
            <template #actions>
                <AppPageActions
                    v-if="pageActions.length"
                    :actions="pageActions"
                    class="w-full sm:w-auto"
                />
            </template>
        </AppListToolbar>
        <!-- The screen's how-to, next to what it explains; collapsed or
             expanded, the choice applies to every guide. -->
        <AppGuide :title="t('suite.studio.customers.guide.title')" storage-key="customers-list">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 5" :key="step">{{ t(`suite.studio.customers.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <!-- Two tabs rather than a column: a two-valued status people want to
             filter on is a filter, not a column - and a badge repeated on
             every row of a tab that already carries the word no longer
             distinguishes anything.

             The count is on the label because it is what makes the other tab
             visible: a prospect created from a space would otherwise be
             filed somewhere nobody thinks to open. -->
        <div
            class="flex items-center gap-0.5 aurora-segmented"
            role="group"
            :aria-label="t('suite.studio.customers.status')"
        >
            <button
                v-for="entry in tabs"
                :key="entry.key"
                type="button"
                class="flex items-center gap-1.5 rounded-md px-2.5 py-1 text-sm transition-colors"
                :class="
                    tab === entry.key
                        ? 'bg-surface font-medium text-primary shadow-sm'
                        : 'text-muted hover:text-primary'
                "
                :aria-pressed="tab === entry.key"
                v-on:click="tab = entry.key"
            >
                {{ entry.label }}
                <span class="text-xs tabular-nums text-muted">{{ entry.count }}</span>
            </button>
        </div>

        <!-- Mobile cards -->
        <div v-if="isNarrow" class="space-y-2">
            <AppNoData
                v-if="!visibleItems.length"
                :message="t('suite.studio.customers.empty')"
            />
            <div
                v-for="customer in visibleItems"
                :key="customer.id"
                class="aurora-card overflow-hidden"
            >
                <div class="flex items-start gap-3 px-4 py-3">
                    <div class="min-w-0 flex-1 space-y-1">
                        <p class="font-medium text-primary text-sm">
                            <a :href="pageOf(customer)" class="text-primary hover:underline">{{ customer.legalName }}</a>
                            <span v-if="customer.legalForm" class="text-muted font-normal">
                                · {{ customer.legalForm }}
                            </span>
                        </p>
                        <p v-if="customer.representativeFullName" class="text-xs text-secondary">
                            {{ customer.representativeFullName }}
                            <span v-if="customer.representativeRole">
                                - {{ customer.representativeRole }}
                            </span>
                        </p>
                        <p class="text-xs text-muted">{{ customer.contractualEmail }}</p>
                        <p v-if="customer.siret" class="text-xs text-muted font-mono">
                            {{ formatSiret(customer.siret) }}
                        </p>
                    </div>
                    <!-- The gestures behind the "…" button, level with the title,
                     as on every list (Axel's decision of 04/10/2026): the card
                     keeps its room for its content. -->
                    <AppRowActions class="shrink-0" :actions="actionsFor(customer)" :label="customer.legalName" />
                </div>
            </div>
        </div>

        <!-- Desktop table -->
        <div
            v-else
            class="aurora-card overflow-x-auto scrollbar-thin"
        >
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-surface-2/50 border-b border-line/40">
                        <th
                            class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted"
                        >
                            {{ t("suite.studio.customers.col_company") }}
                        </th>
                        <th
                            class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell"
                        >
                            {{ t("suite.studio.customers.col_representative") }}
                        </th>
                        <th
                            class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell"
                        >
                            {{ t("suite.studio.customers.col_siret") }}
                        </th>
                        <th
                            class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted"
                        >
                            {{ t("suite.studio.customers.col_contact") }}
                        </th>
                        <th
                            class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-muted sticky right-0 bg-surface-2 border-l border-line/40"
                        >
                            {{ t("shared.common.actions") }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line/40">
                    <tr
                        v-for="customer in visibleItems"
                        :key="customer.id"
                        class="group hover:bg-surface-2/40 transition-colors"
                    >
                        <td class="px-4 py-2">
                            <!-- The name leads to their page, where the whole sheet
                                 is read and edited. -->
                            <a :href="pageOf(customer)" class="font-medium text-primary hover:underline">
                                {{ customer.legalName }}
                            </a>
                            <div class="text-xs text-muted">
                                <span v-if="customer.legalForm">{{ customer.legalForm }}</span>
                                <span v-if="customer.legalForm && formatCapital(customer)">
                                    ·
                                </span>
                                <span v-if="formatCapital(customer)">
                                    {{ formatCapital(customer) }}
                                </span>
                            </div>
                            <!-- Their spaces, in one click: a customer sheet did
                                 not say which projects were running for them. -->
                            <div v-if="customer.spaces?.length" class="mt-1 flex flex-wrap gap-x-2 gap-y-0.5 text-xs">
                                <a
                                    v-for="space in customer.spaces"
                                    :key="space.id"
                                    :href="space.url"
                                    class="text-accent-500 hover:underline"
                                    :class="space.archived ? 'opacity-60' : ''"
                                >
                                    {{ space.name }}
                                </a>
                            </div>
                            <!-- Their contracts, in one click: the list opens filtered on them. -->
                            <a
                                v-if="customer.contracts?.count"
                                :href="customer.contracts.url"
                                class="mt-0.5 inline-block text-xs text-accent-500 hover:underline"
                            >
                                {{ t("suite.studio.customers.contracts_count", { count: customer.contracts.count }) }}
                            </a>
                        </td>
                        <td class="px-4 py-2 hidden lg:table-cell">
                            <div v-if="customer.representativeFullName" class="text-primary">
                                {{ customer.representativeFullName }}
                            </div>
                            <div v-else class="text-muted">-</div>
                            <div v-if="customer.representativeRole" class="text-xs text-muted">
                                {{ customer.representativeRole }}
                            </div>
                        </td>
                        <td
                            class="px-4 py-2 text-muted font-mono text-xs hidden md:table-cell whitespace-nowrap"
                        >
                            {{ formatSiret(customer.siret) }}
                        </td>
                        <td class="px-4 py-2">
                            <div class="text-primary whitespace-nowrap">
                                {{ customer.contractualEmail }}
                            </div>
                        </td>
                        <td class="px-4 py-2 sticky right-0 bg-surface border-l border-line/40">
                            <div class="flex items-center justify-end gap-0.5">
                                <AppRowActions
                                    :actions="actionsFor(customer)"
                                    :label="customer.legalName ?? ''"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!visibleItems.length">
                        <td :colspan="5">
                            <AppNoData
                                :message="t('suite.studio.customers.empty')"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <AppModal
            :show="showCreate"
            max-width="2xl"
            :title="t('suite.studio.customers.create')"
            :icon="Building2"
            :closeable="false"
            v-on:close="showCreate = false"
        >
            <form v-on:submit.prevent="submitCreate">
                <CustomerFormFields
                    v-model="newCustomer"
                    :errors="createErrors"
                    :currencies="currencies"
                />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showCreate = false">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="primary"
                        size="md"
                        :loading="createLoading"
                        v-on:click="submitCreate"
                    >
                        <Save class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.save") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <CustomerDeleteModal
            :show="!!pendingDelete"
            :name="pendingDelete?.legalName ?? ''"
            :loading="deleteLoading"
            v-on:cancel="pendingDelete = null"
            v-on:confirm="doDelete"
        />

        <ConvertProspectModal
            :show="!!converting"
            :name="converting?.customer.name ?? ''"
            :model-value="convertEmail"
            :error="convertError"
            :loading="convertLoading"
            v-on:update:model-value="convertEmail = $event"
            v-on:close="closeConversion"
            v-on:submit="submitConversion"
        />
    </div>
</template>
