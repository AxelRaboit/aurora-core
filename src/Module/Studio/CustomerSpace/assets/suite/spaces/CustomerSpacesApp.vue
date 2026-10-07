<script setup>
/**
 * The list of client spaces.
 *
 * A list and not yet a page per space: this lot gives the space a row, a team
 * and a colour, and the tabbed page it will open onto arrives with the content
 * that has to go in it. Shipping the shell first would have been a click that
 * leads to an empty screen.
 */
import StudioSectionTabs from "../../../../assets/suite/components/StudioSectionTabs.vue";
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useNarrowContainer } from "@/shared/composables/list/useNarrowContainer.js";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useSpaceRowActions } from "./composables/useSpaceRowActions.js";
// Same module, another subdomain: relative path, as elsewhere.
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
import { PanelsTopLeft, Plus, Save, Trash2, X } from "lucide-vue-next";

const { t } = useI18n();
const { container, isNarrow } = useNarrowContainer();

/** Units people read, not bytes people count. */
function weigh(bytes) {
    if (!bytes) return "—";

    if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(1)} Go`;
    if (bytes >= 1024 ** 2) return `${(bytes / 1024 ** 2).toFixed(1)} Mo`;

    return `${Math.max(1, Math.round(bytes / 1024))} ko`;
}
const { can } = usePrivileges();

const props = defineProps({
    /** The two tabs of the "Espaces clients" entry: the list and the calendar. */
    spacesPath: { type: String, default: "" },
    calendarPath: { type: String, default: "" },
    spaces: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    /**
     * What each space has had uploaded, in bytes, by id.
     *
     * The size of the space's folder in the media library, that is what
     * arrived *through* it: a document picked from the media library was
     * already there and would have stayed there without it.
     */
    storage: { type: Object, default: () => ({}) },
    roles: { type: Array, default: () => [] },
    timezones: { type: Array, default: () => [] },
    /** Opening a space for a prospect creates their sheet: reserved to whoever creates customers. */
    canCreateCustomer: { type: Boolean, default: false },
    boardPath: { type: String, required: true },
    createPath: { type: String, required: true },
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
    pendingDelete,
    deleteLoading,
    confirmDelete,
    doDelete,
} = useCustomerSpacesForm(
    props.spaces,
    props.customers,
    props.users,
    props.createPath,
    props.deletePath,
);

// Its own rather than the shared edit/delete pair: the menu also opens the
// space, which is the thing one actually does to a row. See the composable.
/**
 * The conversion does not return spaces but customers, so the list is not
 * replaced: the rows of the company that just signed are marked in place.
 * They change tab right away, which is exactly what the conversion means.
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
    // Editing a space happens on its Settings tab, where everything about
    // it is gathered; the list keeps only the creation modal.
    settingsHref: (space) => `${boardHref(space)}?view=settings`,
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
 * The most urgent at the top, on demand: missed publications, late reviews,
 * content to rework, then what is waiting on the client. Without the
 * checkbox, the order stays by name, which is scanned to find a space.
 */
const byUrgency = ref(false);

function urgencyOf(space) {
    const workload = space.workload ?? {};

    return [workload.missed ?? 0, workload.lateReview ?? 0, workload.changesRequested ?? 0, workload.withClient ?? 0];
}

const rows = computed(() => {
    if (!byUrgency.value) return visibleItems.value;

    return [...visibleItems.value].sort((left, right) => {
        const [leftUrgency, rightUrgency] = [urgencyOf(left), urgencyOf(right)];
        for (let level = 0; level < leftUrgency.length; level += 1) {
            if (leftUrgency[level] !== rightUrgency[level]) return rightUrgency[level] - leftUrgency[level];
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
        <StudioSectionTabs
            current="spaces"
            :tabs="[
                { key: 'spaces', label: t('suite.studio.spaces.tab_list'), path: spacesPath },
                { key: 'calendar', label: t('suite.studio.spaces.tab_calendar'), path: calendarPath },
            ]"
            :label="t('suite.studio.spaces.tabs_label')"
        />
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
        <!-- Arrived from a company's sheet: say so, and drop the filter in one
             gesture, rather than a mysteriously short list. -->
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
        <!-- The screen's how-to, next to what it explains; collapsed or
             expanded, the choice applies to every guide. -->
        <AppGuide :title="t('suite.studio.spaces.guide.title')" storage-key="spaces-list">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 5" :key="step">{{ t(`suite.studio.spaces.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <!-- Two tabs rather than a column: "what I am working on" and "what I
             am trying to land" are not read in the same minute, and a
             two-valued status people want to filter on is a filter.

             The count is on the label because it is what makes the other tab
             visible: a space opened for a prospect would otherwise be filed
             somewhere nobody thinks to open. -->
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
                    <!-- The gestures behind the "…" button, level with the title,
                     as on every list (Axel's decision of 04/10/2026): the card
                     keeps its room for its content. -->
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
                        <!-- The size of what this space has had uploaded. A
                             discreet column, on the right and hidden on
                             narrow screens: nobody reads it every day, but
                             the day the disk fills up, it is what says
                             whose. -->
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
            :show="!!pendingDelete"
            max-width="sm"
            :closeable="false"
            :title="t('suite.studio.spaces.trash_action')"
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
                        {{ t("suite.studio.spaces.trash_action") }}
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
