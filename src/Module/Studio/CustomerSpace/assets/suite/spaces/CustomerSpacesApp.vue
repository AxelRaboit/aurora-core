<script setup>
/**
 * The list of client spaces.
 *
 * A list and not yet a page per space: this lot gives the space a row, a team
 * and a colour, and the tabbed page it will open onto arrives with the content
 * that has to go in it. Shipping the shell first would have been a click that
 * leads to an empty screen.
 */
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useNarrowContainer } from "@/shared/composables/list/useNarrowContainer.js";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useSpaceRowActions } from "./composables/useSpaceRowActions.js";
// Meme module, un autre sous-domaine : chemin relatif, comme ailleurs.
import { useProspectConversion } from "../../../../Customer/assets/suite/customers/composables/useProspectConversion.js";
import ConvertProspectModal from "../../../../Customer/assets/suite/customers/components/ConvertProspectModal.vue";
import { useCustomerSpacesForm } from "./composables/useCustomerSpacesForm.js";
import CustomerSpaceFormFields from "./components/CustomerSpaceFormFields.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import CustomerSpaceTeamModal from "./components/CustomerSpaceTeamModal.vue";
import CustomerSpaceTeamCell from "./components/CustomerSpaceTeamCell.vue";
import SpaceWorkloadBadges from "../../../../SpaceContent/assets/shared/SpaceWorkloadBadges.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { PanelsTopLeft, Pencil, Plus, Save, Trash2, X } from "lucide-vue-next";

const { t } = useI18n();
const { container, isNarrow } = useNarrowContainer();

/** Des unités qu'on lit, pas des octets qu'on compte. */
function weigh(bytes) {
    if (!bytes) return "—";

    if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(1)} Go`;
    if (bytes >= 1024 ** 2) return `${(bytes / 1024 ** 2).toFixed(1)} Mo`;

    return `${Math.max(1, Math.round(bytes / 1024))} ko`;
}
const { can } = usePrivileges();

const props = defineProps({
    spaces: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    /**
     * Ce que chaque espace a fait déposer, en octets, par identifiant.
     *
     * Le poids du dossier de l'espace dans la médiathèque, c'est-à-dire ce qui
     * est arrivé *par* lui : un document choisi dans la médiathèque était déjà
     * là et le serait resté sans lui.
     */
    storage: { type: Object, default: () => ({}) },
    roles: { type: Array, default: () => [] },
    timezones: { type: Array, default: () => [] },
    /** Ouvrir un espace pour un prospect crée sa fiche : réservé à qui crée des clients. */
    canCreateCustomer: { type: Boolean, default: false },
    boardPath: { type: String, required: true },
    createPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    convertPath: { type: String, required: true },
    deletePath: { type: String, required: true },
});

const {
    search,
    filteredCustomer,
    clearCustomerFilter,
    items,
    visibleItems,
    tab,
    tabs,
    showArchived,
    archivedCount,
    customerOptions,
    showCreate,
    newSpace,
    createErrors,
    createLoading,
    openCreate,
    submitCreate,
    showEdit,
    editingSpace,
    editForm,
    editErrors,
    editLoading,
    openEdit,
    submitEdit,
    pendingDelete,
    deleteLoading,
    confirmDelete,
    doDelete,
} = useCustomerSpacesForm(
    props.spaces,
    props.customers,
    props.users,
    props.createPath,
    props.updatePath,
    props.deletePath,
);

// Its own rather than the shared edit/delete pair: the menu also opens the
// space, which is the thing one actually does to a row. See the composable.
/**
 * La conversion ne renvoie pas des espaces mais des clients, donc la liste
 * n'est pas remplacee : on marque sur place les lignes de la societe qui vient
 * de signer. Elles changent d'onglet aussitot, ce qui est exactement ce que la
 * conversion veut dire.
 */
const {
    pending: converting,
    email: convertEmail,
    error: convertError,
    loading: convertLoading,
    open: openConversion,
    close: closeConversion,
    submit: submitConversion,
} = useProspectConversion(props.convertPath, (_data, space) => {
    for (const row of items.value) {
        if (row.customerId === space.customerId) row.customerStatus = "client";
    }
});

const actionsFor = useSpaceRowActions({
    can,
    boardHref,
    openEdit,
    convertToClient: (space) =>
        openConversion(space, {
            id: space.customerId,
            name: space.customerName,
            email: "",
        }),
    confirmDelete,
});

/** Where a row leads: the space's board. */
function boardHref(space) {
    return buildPath(props.boardPath, { id: space.id });
}

/**
 * The team, as a figure that opens the list.
 *
 * It used to be three names and a "+2" written into the cell, which answered
 * "is anybody on this" and hid the fourth person, the roles and the addresses.
 * A cell is not the place to read five people: it is the place to see that
 * there are five. The names live in the modal, where they have room.
 */
const teamOf = ref(null);

const { formatDate } = useDateFormat();

/**
 * Le plus urgent en haut, sur demande : parutions manquées, relectures en
 * retard, contenus à reprendre, puis ce qui attend le client. Sans la case,
 * l'ordre reste celui des noms, qu'on parcourt pour retrouver un espace.
 */
const byUrgency = ref(false);

function urgencyOf(space) {
    const w = space.workload ?? {};

    return [w.missed ?? 0, w.lateReview ?? 0, w.changesRequested ?? 0, w.withClient ?? 0];
}

const rows = computed(() => {
    if (!byUrgency.value) return visibleItems.value;

    return [...visibleItems.value].sort((a, b) => {
        const [x, y] = [urgencyOf(a), urgencyOf(b)];
        for (let i = 0; i < x.length; i += 1) {
            if (x[i] !== y[i]) return y[i] - x[i];
        }

        return 0;
    });
});

const pageActions = computed(() => {
    if (!can("studio.spaces.create")) {
        return [];
    }

    return [
        {
            key: "create",
            color: "accent",
            icon: Plus,
            title: t("suite.studio.spaces.add"),
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
                :placeholder="t('suite.studio.spaces.search_placeholder')"
            />
            <template #actions>
                <AppPageActions
                    v-if="pageActions.length"
                    :actions="pageActions"
                    class="w-full sm:w-auto"
                />
            </template>
        </AppListToolbar>
        <!-- Arrivé depuis la fiche d'une société : on le dit, et on se défait
             du filtre d'un geste, plutôt qu'une liste mystérieusement courte. -->
        <div
            v-if="filteredCustomer"
            class="flex flex-wrap items-center gap-2 text-sm text-secondary"
        >
            <span>{{ t("suite.studio.spaces.customer_filter", { name: filteredCustomer.name }) }}</span>
            <AppButton variant="ghost" size="sm" v-on:click="clearCustomerFilter">
                <X class="w-3.5 h-3.5" :stroke-width="2" />
                {{ t("suite.studio.spaces.customer_filter_clear") }}
            </AppButton>
        </div>
        <!-- Le mode d'emploi de l'écran, à côté de ce qu'il explique ;
             replié ou déplié, le choix vaut pour tous les encarts. -->
        <AppGuide :title="t('suite.studio.spaces.guide.title')" storage-key="spaces-list">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 5" :key="step">{{ t(`suite.studio.spaces.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <!-- Deux onglets plutot qu'une colonne : « ce sur quoi je travaille »
             et « ce que j'essaie de decrocher » ne se lisent pas dans la meme
             minute, et un statut a deux valeurs sur lequel on veut filtrer est
             un filtre.

             Le compte est sur l'etiquette parce que c'est lui qui rend l'autre
             onglet visible : un espace ouvert pour un prospect serait sinon
             range quelque part que personne ne pense a ouvrir. -->
        <div
            class="flex items-center gap-0.5 rounded-lg border border-line bg-surface-2/40 p-0.5"
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
                {{ t(`suite.studio.customers.statuses.${entry.key}_plural`) }}
                <span class="text-xs tabular-nums text-muted">{{ entry.count }}</span>
            </button>
        </div>

        <!-- Only offered when there is something to reveal: a switch that does
             nothing is a switch people learn to distrust. -->
        <AppCheckbox
            v-if="archivedCount"
            v-model="showArchived"
            :label="t('suite.studio.spaces.show_archived')"
        />
        <AppCheckbox v-model="byUrgency" :label="t('suite.studio.spaces.sort_by_urgency')" />

        <!-- Mobile cards -->
        <div v-if="isNarrow" class="space-y-2">
            <AppNoData
                v-if="!visibleItems.length"
                :message="t('suite.studio.spaces.empty')"
                :hint="t('suite.studio.spaces.empty_hint')"
            />
            <div
                v-for="space in rows"
                :key="space.id"
                class="aurora-card overflow-hidden"
            >
                <div class="flex items-start gap-3 px-4 py-3">
                    <span
                        class="mt-1 w-2.5 h-2.5 shrink-0 rounded-full"
                        :style="{ backgroundColor: `var(--chart-cat-${space.colourSlot})` }"
                    />
                    <div class="min-w-0 flex-1 space-y-1">
                        <p class="font-medium text-primary text-sm">
                            <a :href="boardHref(space)" class="hover:underline">
                                {{ space.name }}
                            </a>
                            <span
                                v-if="space.archived"
                                class="ml-1 text-xs font-normal text-muted"
                            >
                                · {{ t("suite.studio.spaces.archived_badge") }}
                            </span>
                        </p>
                        <p class="text-xs text-secondary">{{ space.customerName }}</p>
                        <p v-if="space.workload?.nextPublication" class="text-xs text-muted">
                            {{ t("suite.studio.workload.next_publication", { date: formatDate(space.workload.nextPublication) }) }}
                        </p>
                        <SpaceWorkloadBadges :workload="space.workload" />
                        <CustomerSpaceTeamCell
                            :members="space.members"
                            v-on:open="teamOf = space"
                        />
                    </div>
                    <!-- Les gestes derrière le bouton « … », à hauteur du titre,
                     comme sur toutes les listes (décision d'Axel du 04/10/2026) :
                     la carte garde sa place pour son contenu. -->
                    <AppRowActions class="shrink-0" :actions="actionsFor(space)" :label="space.name" />
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
                            {{ t("suite.studio.spaces.col_space") }}
                        </th>
                        <th
                            class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted"
                        >
                            {{ t("suite.studio.spaces.col_customer") }}
                        </th>
                        <th
                            class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell"
                        >
                            {{ t("suite.studio.spaces.col_workload") }}
                        </th>
                        <th
                            class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell"
                        >
                            {{ t("suite.studio.spaces.col_team") }}
                        </th>
                        <th
                            class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell"
                        >
                            {{ t("suite.studio.spaces.col_status") }}
                        </th>
                        <th
                            class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-muted hidden xl:table-cell"
                        >
                            {{ t("suite.studio.spaces.col_storage") }}
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
                        v-for="space in rows"
                        :key="space.id"
                        class="group hover:bg-surface-2/40 transition-colors"
                        :class="space.archived ? 'opacity-60' : ''"
                    >
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-2.5">
                                <span
                                    class="w-2.5 h-2.5 shrink-0 rounded-full"
                                    :style="{
                                        backgroundColor: `var(--chart-cat-${space.colourSlot})`,
                                    }"
                                />
                                <div class="min-w-0">
                                    <a
                                        :href="boardHref(space)"
                                        class="block truncate font-medium text-primary hover:text-accent-500 hover:underline"
                                    >
                                        {{ space.name }}
                                    </a>
                                    <div
                                        v-if="space.description"
                                        class="text-xs text-muted truncate"
                                    >
                                        {{ space.description }}
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-2 text-primary">{{ space.customerName }}</td>
                        <td class="px-4 py-2 hidden md:table-cell">
                            <div class="space-y-1">
                                <SpaceWorkloadBadges :workload="space.workload" />
                                <p v-if="space.workload?.nextPublication" class="text-xs text-muted whitespace-nowrap">
                                    {{ t("suite.studio.workload.next_publication", { date: formatDate(space.workload.nextPublication) }) }}
                                </p>
                            </div>
                        </td>
                        <td class="px-4 py-2 hidden lg:table-cell">
                            <CustomerSpaceTeamCell
                                :members="space.members"
                                v-on:open="teamOf = space"
                            />
                        </td>
                        <td class="px-4 py-2 hidden md:table-cell">
                            <span
                                v-if="space.archived"
                                class="text-xs text-muted"
                            >
                                {{ t("suite.studio.spaces.statuses.archived") }}
                            </span>
                            <span v-else class="text-xs text-primary">
                                {{ t("suite.studio.spaces.statuses.active") }}
                            </span>
                        </td>
                        <!-- Le poids de ce que cet espace a fait déposer. Une
                             colonne discrète, à droite et masquée sur les
                             écrans étroits : on ne la lit pas tous les jours,
                             mais le jour où le disque se remplit, c'est elle
                             qui dit chez qui. -->
                        <td class="px-4 py-2 text-right text-xs text-muted tabular-nums hidden xl:table-cell">
                            {{ weigh(storage[space.id] ?? 0) }}
                        </td>
                        <td class="px-4 py-2 sticky right-0 bg-surface border-l border-line/40">
                            <div class="flex items-center justify-end gap-0.5">
                                <AppRowActions
                                    :actions="actionsFor(space)"
                                    :label="space.name ?? ''"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!visibleItems.length">
                        <td :colspan="6">
                            <AppNoData
                                :message="t('suite.studio.spaces.empty')"
                                :hint="t('suite.studio.spaces.empty_hint')"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <CustomerSpaceTeamModal
            :space="teamOf"
            :roles="roles"
            v-on:close="teamOf = null"
        />

        <AppModal
            :show="showCreate"
            max-width="2xl"
            :title="t('suite.studio.spaces.create')"
            :icon="PanelsTopLeft"
            :closeable="false"
            v-on:close="showCreate = false"
        >
            <form v-on:submit.prevent="submitCreate">
                <CustomerSpaceFormFields
                    v-model="newSpace"
                    :errors="createErrors"
                    :can-create-customer="canCreateCustomer"
                    :customer-options="customerOptions"
                    :users="users"
                    :statuses="statuses"
                    :roles="roles"
                    :timezones="timezones"
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
                t('suite.studio.spaces.edit', { name: editingSpace?.name ?? '' })
            "
            :icon="Pencil"
            :closeable="false"
            v-on:close="showEdit = false"
        >
            <form v-on:submit.prevent="submitEdit">
                <CustomerSpaceFormFields
                    v-model="editForm"
                    :errors="editErrors"
                    :can-create-customer="canCreateCustomer"
                    :customer-options="customerOptions"
                    :users="users"
                    :statuses="statuses"
                    :roles="roles"
                    :timezones="timezones"
                    :can-edit-team="editingSpace?.canConfigure ?? false"
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
                    t("suite.studio.spaces.delete_confirm", {
                        name: pendingDelete?.name ?? "",
                    })
                }}
            </p>
            <p class="text-sm text-secondary">
                {{ t("suite.studio.spaces.delete_warning") }}
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
