<script setup>
/**
 * The list of client spaces.
 *
 * A list and not yet a page per space: this lot gives the space a row, a team
 * and a colour, and the tabbed page it will open onto arrives with the content
 * that has to go in it. Shipping the shell first would have been a click that
 * leads to an empty screen.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useNarrowContainer } from "@/shared/composables/list/useNarrowContainer.js";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useSpaceRowActions } from "./composables/useSpaceRowActions.js";
// Meme module, un autre sous-domaine : chemin relatif, comme ailleurs.
import { useProspectConversion } from "../../../../Customer/assets/backend/customers/composables/useProspectConversion.js";
import ConvertProspectModal from "../../../../Customer/assets/backend/customers/components/ConvertProspectModal.vue";
import { useCustomerSpacesForm } from "./composables/useCustomerSpacesForm.js";
import CustomerSpaceFormFields from "./components/CustomerSpaceFormFields.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppCardActions from "@/shared/components/action/AppCardActions.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import CustomerSpaceTeamModal from "./components/CustomerSpaceTeamModal.vue";
import CustomerSpaceTeamCell from "./components/CustomerSpaceTeamCell.vue";
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
    boardPath: { type: String, required: true },
    createPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    convertPath: { type: String, required: true },
    deletePath: { type: String, required: true },
});

const {
    search,
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

const pageActions = computed(() => {
    if (!can("studio.spaces.create")) {
        return [];
    }

    return [
        {
            key: "create",
            color: "accent",
            icon: Plus,
            title: t("backend.studio.spaces.add"),
            onSelect: openCreate,
        },
    ];
});
</script>

<template>
    <div ref="container" class="space-y-2 sm:space-y-4">
        <AppListToolbar>
            <AppSearchInput
                v-model="search"
                :placeholder="t('backend.studio.spaces.search_placeholder')"
            />
            <template #actions>
                <AppPageActions
                    v-if="pageActions.length"
                    :actions="pageActions"
                    class="w-full sm:w-auto"
                />
            </template>
        </AppListToolbar>

        <!-- Deux onglets plutot qu'une colonne : « ce sur quoi je travaille »
             et « ce que j'essaie de decrocher » ne se lisent pas dans la meme
             minute, et un statut a deux valeurs sur lequel on veut filtrer est
             un filtre.

             Le compte est sur l'etiquette parce que c'est lui qui rend l'autre
             onglet visible : un espace ouvert pour un prospect serait sinon
             range quelque part que personne ne pense a ouvrir. -->
        <div
            class="flex items-center gap-0.5 rounded-lg border border-line/60 bg-surface-2/40 p-0.5"
            role="group"
            :aria-label="t('backend.studio.customers.status')"
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
                {{ t(`backend.studio.customers.statuses.${entry.key}_plural`) }}
                <span class="text-xs tabular-nums text-muted">{{ entry.count }}</span>
            </button>
        </div>

        <!-- Only offered when there is something to reveal: a switch that does
             nothing is a switch people learn to distrust. -->
        <AppCheckbox
            v-if="archivedCount"
            v-model="showArchived"
            :label="t('backend.studio.spaces.show_archived')"
        />

        <!-- Mobile cards -->
        <div v-if="isNarrow" class="space-y-2">
            <AppNoData
                v-if="!visibleItems.length"
                :message="t('backend.studio.spaces.empty')"
                :hint="t('backend.studio.spaces.empty_hint')"
            />
            <div
                v-for="space in visibleItems"
                :key="space.id"
                class="bg-surface border border-line/60 rounded-xl overflow-hidden shadow-sm"
            >
                <div class="flex items-start gap-3 px-4 py-3">
                    <span
                        class="mt-1 w-2.5 h-2.5 shrink-0 rounded-full"
                        :style="{ backgroundColor: `var(--chart-cat-${space.colourSlot})` }"
                    />
                    <div class="min-w-0 space-y-1">
                        <p class="font-medium text-primary text-sm">
                            <a :href="boardHref(space)" class="hover:underline">
                                {{ space.name }}
                            </a>
                            <span
                                v-if="space.archived"
                                class="ml-1 text-xs font-normal text-muted"
                            >
                                · {{ t("backend.studio.spaces.archived_badge") }}
                            </span>
                        </p>
                        <p class="text-xs text-secondary">{{ space.customerName }}</p>
                        <CustomerSpaceTeamCell
                            :members="space.members"
                            v-on:open="teamOf = space"
                        />
                    </div>
                </div>
                <!-- Les gestes en toutes lettres plutôt que derrière trois
                     points : la carte a la largeur de les nommer. -->
                <div class="px-2 pb-2 pt-1 border-t border-line/40 bg-surface-2/40">
                    <AppCardActions :actions="actionsFor(space)" />
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
                            {{ t("backend.studio.spaces.col_space") }}
                        </th>
                        <th
                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted"
                        >
                            {{ t("backend.studio.spaces.col_customer") }}
                        </th>
                        <th
                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell"
                        >
                            {{ t("backend.studio.spaces.col_team") }}
                        </th>
                        <th
                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell"
                        >
                            {{ t("backend.studio.spaces.col_status") }}
                        </th>
                        <th
                            class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted hidden xl:table-cell"
                        >
                            {{ t("backend.studio.spaces.col_storage") }}
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
                        v-for="space in visibleItems"
                        :key="space.id"
                        class="group hover:bg-surface-2/40 transition-colors"
                        :class="space.archived ? 'opacity-60' : ''"
                    >
                        <td class="px-6 py-3">
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
                        <td class="px-6 py-3 text-primary">{{ space.customerName }}</td>
                        <td class="px-6 py-3 hidden lg:table-cell">
                            <CustomerSpaceTeamCell
                                :members="space.members"
                                v-on:open="teamOf = space"
                            />
                        </td>
                        <td class="px-6 py-3 hidden md:table-cell">
                            <span
                                v-if="space.archived"
                                class="text-xs text-muted"
                            >
                                {{ t("backend.studio.spaces.statuses.archived") }}
                            </span>
                            <span v-else class="text-xs text-primary">
                                {{ t("backend.studio.spaces.statuses.active") }}
                            </span>
                        </td>
                        <!-- Le poids de ce que cet espace a fait déposer. Une
                             colonne discrète, à droite et masquée sur les
                             écrans étroits : on ne la lit pas tous les jours,
                             mais le jour où le disque se remplit, c'est elle
                             qui dit chez qui. -->
                        <td class="px-6 py-3 text-right text-xs text-muted tabular-nums hidden xl:table-cell">
                            {{ weigh(storage[space.id] ?? 0) }}
                        </td>
                        <td class="px-6 py-3 sticky right-0 bg-surface border-l border-line/40">
                            <div class="flex items-center justify-end gap-0.5">
                                <AppRowActions
                                    :actions="actionsFor(space)"
                                    :label="space.name ?? ''"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!visibleItems.length">
                        <td :colspan="5">
                            <AppNoData
                                :message="t('backend.studio.spaces.empty')"
                                :hint="t('backend.studio.spaces.empty_hint')"
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
            :title="t('backend.studio.spaces.create')"
            :icon="PanelsTopLeft"
            :closeable="false"
            v-on:close="showCreate = false"
        >
            <form v-on:submit.prevent="submitCreate">
                <CustomerSpaceFormFields
                    v-model="newSpace"
                    :errors="createErrors"
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
                t('backend.studio.spaces.edit', { name: editingSpace?.name ?? '' })
            "
            :icon="Pencil"
            :closeable="false"
            v-on:close="showEdit = false"
        >
            <form v-on:submit.prevent="submitEdit">
                <CustomerSpaceFormFields
                    v-model="editForm"
                    :errors="editErrors"
                    :customer-options="customerOptions"
                    :users="users"
                    :statuses="statuses"
                    :roles="roles"
                    :timezones="timezones"
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
                    t("backend.studio.spaces.delete_confirm", {
                        name: pendingDelete?.name ?? "",
                    })
                }}
            </p>
            <p class="text-sm text-secondary">
                {{ t("backend.studio.spaces.delete_warning") }}
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
