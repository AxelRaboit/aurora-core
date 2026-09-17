<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { useNarrowContainer } from "@/shared/composables/list/useNarrowContainer.js";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useCustomerRowActions } from "./composables/useCustomerRowActions.js";
import { useCustomersForm } from "./composables/useCustomersForm.js";
import CustomerFormFields from "./components/CustomerFormFields.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { Building2, Pencil, Plus, Save, Trash2, X } from "lucide-vue-next";

const { t } = useI18n();
const { container, isNarrow } = useNarrowContainer();
const { can } = usePrivileges();

const props = defineProps({
    customers: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    createPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
});

const {
    search,
    filteredItems,
    userOptions,
    showCreate,
    newCustomer,
    createErrors,
    createLoading,
    openCreate,
    submitCreate,
    showEdit,
    editingCustomer,
    editForm,
    editErrors,
    editLoading,
    openEdit,
    convertToClient,
    submitEdit,
    pendingDelete,
    deleteLoading,
    confirmDelete,
    doDelete,
} = useCustomersForm(
    props.customers,
    props.users,
    props.createPath,
    props.updatePath,
    props.deletePath,
);

// Its own rather than the shared edit/delete pair: a prospect has a third
// thing to offer. See the composable.
const actionsFor = useCustomerRowActions({
    can,
    openEdit,
    convertToClient,
    confirmDelete,
});

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

function formatCapital(customer) {
    if (
        customer.shareCapitalCents === null ||
        customer.shareCapitalCents === undefined
    ) {
        return null;
    }

    const currency = customer.shareCapitalCurrency ?? "EUR";

    return new Intl.NumberFormat(undefined, {
        style: "currency",
        currency,
        // A capital is a round figure far more often than not, so the decimals
        // show only when they carry something.
        minimumFractionDigits: customer.shareCapitalCents % 100 === 0 ? 0 : 2,
    }).format(customer.shareCapitalCents / 100);
}

// One entry and still a sheet: every list in the backend opens its actions the
// same way, and a toolbar's width belongs to the search, not to a verb.
const pageActions = computed(() => {
    if (!can("studio.customers.create")) {
        return [];
    }

    return [
        {
            key: "create",
            color: "accent",
            icon: Plus,
            title: t("backend.studio.customers.add"),
            onSelect: openCreate,
        },
    ];
});
</script>

<template>
    <div ref="container" class="space-y-4">
        <AppListToolbar>
            <AppSearchInput
                v-model="search"
                :placeholder="t('backend.studio.customers.search_placeholder')"
            />
            <template #actions>
                <AppPageActions
                    v-if="pageActions.length"
                    :actions="pageActions"
                    class="w-full sm:w-auto"
                />
            </template>
        </AppListToolbar>

        <!-- Mobile cards -->
        <div v-if="isNarrow" class="space-y-2">
            <AppNoData
                v-if="!filteredItems.length"
                :message="t('backend.studio.customers.empty')"
            />
            <div
                v-for="customer in filteredItems"
                :key="customer.id"
                class="bg-surface border border-line/60 rounded-xl overflow-hidden shadow-sm"
            >
                <div class="px-4 py-3 space-y-1">
                    <p class="font-medium text-primary text-sm">
                        {{ customer.legalName }}
                        <span v-if="customer.legalForm" class="text-muted font-normal">
                            · {{ customer.legalForm }}
                        </span>
                        <!-- Seulement sur un prospect : « client » est l'etat
                             ordinaire d'une fiche, et une pastille posee sur
                             chaque ligne ne distingue plus rien. -->
                        <span
                            v-if="'prospect' === customer.status"
                            class="rounded-full border border-accent-500/30 bg-accent-500/10 px-1.5 py-0.5 text-[0.65rem] font-medium uppercase tracking-wide text-accent-500"
                        >
                            {{ t("backend.studio.customers.statuses.prospect") }}
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
                <div
                    class="flex justify-end px-3 py-2 border-t border-line/40 bg-surface-2/40"
                >
                    <AppRowActions
                        :actions="actionsFor(customer)"
                        :label="customer.legalName ?? ''"
                    />
                </div>
            </div>
        </div>

        <!-- Desktop table -->
        <div
            v-else
            class="bg-surface border border-line rounded-lg overflow-x-auto scrollbar-thin"
        >
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-surface-2/50 border-b border-line/40">
                        <th
                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted"
                        >
                            {{ t("backend.studio.customers.col_company") }}
                        </th>
                        <th
                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell"
                        >
                            {{ t("backend.studio.customers.col_representative") }}
                        </th>
                        <th
                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell"
                        >
                            {{ t("backend.studio.customers.col_siret") }}
                        </th>
                        <th
                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted"
                        >
                            {{ t("backend.studio.customers.col_contact") }}
                        </th>
                        <th
                            class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted sticky right-0 bg-surface-2 border-l border-line/40"
                        >
                            {{ t("shared.common.actions") }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line/40">
                    <tr
                        v-for="customer in filteredItems"
                        :key="customer.id"
                        class="group hover:bg-surface-2/40 transition-colors"
                    >
                        <td class="px-6 py-3">
                            <div class="flex items-center gap-2 font-medium text-primary">
                                {{ customer.legalName }}
                                <span
                                    v-if="'prospect' === customer.status"
                                    class="rounded-full border border-accent-500/30 bg-accent-500/10 px-1.5 py-0.5 text-[0.65rem] font-medium uppercase tracking-wide text-accent-500"
                                >
                                    {{ t("backend.studio.customers.statuses.prospect") }}
                                </span>
                            </div>
                            <div class="text-xs text-muted">
                                <span v-if="customer.legalForm">{{ customer.legalForm }}</span>
                                <span v-if="customer.legalForm && formatCapital(customer)">
                                    ·
                                </span>
                                <span v-if="formatCapital(customer)">
                                    {{ formatCapital(customer) }}
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-3 hidden lg:table-cell">
                            <div v-if="customer.representativeFullName" class="text-primary">
                                {{ customer.representativeFullName }}
                            </div>
                            <div v-else class="text-muted">-</div>
                            <div v-if="customer.representativeRole" class="text-xs text-muted">
                                {{ customer.representativeRole }}
                            </div>
                        </td>
                        <td
                            class="px-6 py-3 text-muted font-mono text-xs hidden md:table-cell whitespace-nowrap"
                        >
                            {{ formatSiret(customer.siret) }}
                        </td>
                        <td class="px-6 py-3">
                            <div class="text-primary break-all">
                                {{ customer.contractualEmail }}
                            </div>
                            <div v-if="customer.userName" class="text-xs text-muted">
                                {{
                                    t("backend.studio.customers.linked_account", {
                                        name: customer.userName,
                                    })
                                }}
                            </div>
                        </td>
                        <td class="px-6 py-3 sticky right-0 bg-surface border-l border-line/40">
                            <div class="flex items-center justify-end gap-0.5">
                                <AppRowActions
                                    :actions="actionsFor(customer)"
                                    :label="customer.legalName ?? ''"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!filteredItems.length">
                        <td :colspan="5">
                            <AppNoData
                                :message="t('backend.studio.customers.empty')"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <AppModal
            :show="showCreate"
            max-width="2xl"
            :title="t('backend.studio.customers.create')"
            :icon="Building2"
            :closeable="false"
            v-on:close="showCreate = false"
        >
            <form v-on:submit.prevent="submitCreate">
                <CustomerFormFields
                    v-model="newCustomer"
                    :errors="createErrors"
                    :user-options="userOptions"
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

        <AppModal
            :show="showEdit"
            max-width="2xl"
            :title="
                t('backend.studio.customers.edit', {
                    name: editingCustomer?.legalName ?? '',
                })
            "
            :icon="Pencil"
            :closeable="false"
            v-on:close="showEdit = false"
        >
            <form v-on:submit.prevent="submitEdit">
                <CustomerFormFields
                    v-model="editForm"
                    :errors="editErrors"
                    :user-options="userOptions"
                    :currencies="currencies"
                />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showEdit = false">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="primary"
                        size="md"
                        :loading="editLoading"
                        v-on:click="submitEdit"
                    >
                        <Save class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.save") }}
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
            <p class="text-sm text-primary">
                {{
                    t("backend.studio.customers.delete_confirm", {
                        name: pendingDelete?.legalName ?? "",
                    })
                }}
            </p>
            <p class="text-sm text-secondary">
                {{ t("backend.studio.customers.delete_warning") }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDelete = null">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="danger"
                        size="md"
                        :loading="deleteLoading"
                        v-on:click="doDelete"
                    >
                        <Trash2 class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
