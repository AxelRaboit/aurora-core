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
import { BellRing, Building2, Columns3, List, Plus, Save, X } from "lucide-vue-next";
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import { useQueryState } from "@/shared/composables/useQueryState.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import ProspectBoard from "../pipeline/ProspectBoard.vue";
import PipelineStageModal from "../pipeline/components/PipelineStageModal.vue";
import LostReasonModal from "../pipeline/components/LostReasonModal.vue";
import { useProspectPipeline } from "../pipeline/composables/useProspectPipeline.js";
import { dayOf, followUpTone, isFollowUpDue } from "../pipeline/followUp.js";

const { t } = useI18n();
const { formatMoney } = useMoneyFormat();
const { container, isNarrow } = useNarrowContainer();
const { can } = usePrivileges();
const { formatDateShort } = useDateFormat();

// The board is created after the list it reads; until then an answer has
// nowhere to deliver its board, and none arrives before the first gesture.
let deliverBoard = null;

const props = defineProps({
    customers: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    locales: { type: Array, default: () => [] },
    createPath: { type: String, required: true },
    /** A customer's page, with `__id__`: the sheet is edited there. */
    showPath: { type: String, required: true },
    convertPath: { type: String, required: true },
    deletePath: { type: String, required: true },
    /** The spaces list, reached from a customer. */
    spacesPath: { type: String, default: "" },
    /** The prospect board: `{stages, columns, lastInteractions, outcomeWindowDays}`. */
    pipeline: { type: Object, default: null },
    /** Where the board's gestures go. */
    pipelinePaths: { type: Object, default: () => ({}) },
    /** How a customer first came in: `[{value, labelKey}]`. */
    sources: { type: Array, default: () => [] },
});

const {
    items,
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
} = useCustomersForm(props.customers, props.createPath, props.deletePath, (data) => deliverBoard?.(data));

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

/**
 * The prospects as a board. Its cards are the list's rows: one search filters
 * both, and a conversion or a deletion shows on both at once.
 */
const pipeline = useProspectPipeline({
    initialBoard: props.pipeline,
    paths: props.pipelinePaths,
    customers: items,
    applyResult: (data) => applyUpdatedList(data),
    askConversion: (customer) =>
        openConversion(customer, {
            id: customer.id,
            name: customer.legalName,
            email: customer.contractualEmail,
        }),
});
deliverBoard = pipeline.applyBoard;

/** A conversion cancelled from the board puts the card back where it was. */
function closeConversionDialog() {
    closeConversion();
    pipeline.cancelConversion();
}

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

/**
 * The follow-ups due, across both tabs: where the dashboard's "to handle"
 * line and the side menu's pill lead. In the address, so the link opens it.
 */
// The empty value is listed: leaving the tab writes it, and `set` silently
// ignores anything not in this list, which kept the reader on the tab.
const { value: followUps, set: setFollowUps } = useQueryState("followUps", {
    defaultValue: "",
    valid: ["", "due"],
});

/** What the list shows: a status tab, or the follow-ups due. */
const view = computed(() => ("due" === followUps.value ? "follow_ups" : tab.value));

function selectView(key) {
    if ("follow_ups" === key) {
        setFollowUps("due");

        return;
    }
    setFollowUps("");
    tab.value = key;
}

const visibleItems = computed(() =>
    "follow_ups" === view.value
        ? filteredItems.value.filter(isFollowUpDue)
        : filteredItems.value.filter((customer) => customer.status === tab.value),
);

const tabs = computed(() => [
    ...["client", "prospect"].map((key) => ({
        key,
        label: t(`suite.studio.customers.statuses.${key}_plural`),
        count: filteredItems.value.filter((customer) => customer.status === key)
            .length,
    })),
    {
        key: "follow_ups",
        label: t("suite.studio.pipeline.follow_up.tab"),
        count: filteredItems.value.filter(isFollowUpDue).length,
    },
]);

/**
 * The prospects drawn as a board, or as the list. In the address like every
 * shape toggle; a narrow screen gets the list whatever it says, a board on a
 * phone scrolling sideways past its second column.
 */
const { value: storedShape, set: setShape } = useQueryState("shape", {
    defaultValue: "board",
    valid: ["board", "list"],
});
const showsBoard = computed(() => "prospect" === view.value && !isNarrow.value && "board" === storedShape.value);

/** Dragging only while the whole board is shown: a filtered order is a partial one. */
const searching = computed(() => "" !== (search.value ?? "").trim());
const searchedIds = computed(() => new Set(filteredItems.value.map((customer) => customer.id)));
const boardGroups = computed(() => pipeline.grouped((customer) => searchedIds.value.has(customer.id)));

const stagesById = computed(() => new Map(pipeline.stages.value.map((stage) => [stage.id, stage])));

/** The stage a row is in: its own, or the first stage in progress. */
function stageOf(customer) {
    const own = stagesById.value.get(customer.pipelineStageId);
    if (own) return own;

    return pipeline.stages.value.find((stage) => !stage.role) ?? null;
}

const sourceLabels = computed(() => new Map(props.sources.map((source) => [source.value, t(source.labelKey)])));

const canEditPipeline = computed(() => can("studio.customers.edit"));

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
        <AppListToolbar :title="t('suite.nav.studio_customers')" :subtitle="t('suite.nav.studio_customers_description')">
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
                <li v-for="step in 7" :key="step">{{ t(`suite.studio.customers.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <!-- Two tabs rather than a column: a two-valued status people want to
             filter on is a filter, not a column - and a badge repeated on
             every row of a tab that already carries the word no longer
             distinguishes anything.

             The count is on the label because it is what makes the other tab
             visible: a prospect created from a space would otherwise be
             filed somewhere nobody thinks to open. -->
        <div class="flex flex-wrap items-center justify-between gap-2">
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
                        view === entry.key
                            ? 'bg-surface font-medium text-primary shadow-sm'
                            : 'text-muted hover:text-primary'
                    "
                    :aria-pressed="view === entry.key"
                    :data-customers-view="entry.key"
                    v-on:click="selectView(entry.key)"
                >
                    <BellRing v-if="'follow_ups' === entry.key" class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ entry.label }}
                    <span
                        class="text-xs tabular-nums"
                        :class="'follow_ups' === entry.key && entry.count ? 'rounded-full bg-warning-soft px-1.5 font-semibold text-warning' : 'text-muted'"
                    >{{ entry.count }}</span>
                </button>
            </div>

            <!-- The prospects' shape. Absent on a narrow screen, where the
                 board is refused anyway: a switch that changes nothing
                 reads as a broken one. -->
            <div
                v-if="'prospect' === view && !isNarrow"
                class="flex rounded-lg border border-line p-0.5"
            >
                <AppIconButton
                    :title="t('suite.studio.pipeline.shape_board')"
                    :class="storedShape === 'board' ? 'bg-surface-3 text-primary' : 'text-muted hover:text-primary'"
                    data-shape="board"
                    v-on:click="setShape('board')"
                >
                    <Columns3 class="h-4 w-4" :stroke-width="2" />
                </AppIconButton>
                <AppIconButton
                    :title="t('suite.studio.pipeline.shape_list')"
                    :class="storedShape === 'list' ? 'bg-surface-3 text-primary' : 'text-muted hover:text-primary'"
                    data-shape="list"
                    v-on:click="setShape('list')"
                >
                    <List class="h-4 w-4" :stroke-width="2" />
                </AppIconButton>
            </div>
        </div>

        <ProspectBoard
            v-if="showsBoard"
            :grouped="boardGroups"
            :editable="canEditPipeline"
            :draggable="canEditPipeline && !searching && !pipeline.sending.value"
            :outcome-window-days="pipeline.board.value.outcomeWindowDays ?? 30"
            :actions-for="actionsFor"
            :page-of="pageOf"
            :last-interaction-of="pipeline.lastInteractionOf"
            v-on:move="pipeline.onStageChange"
            v-on:reorder-stages="pipeline.reorderStages"
            v-on:add-stage="pipeline.openStageCreate"
            v-on:edit-stage="pipeline.openStageEdit"
            v-on:delete-stage="pipeline.pendingStageDelete.value = $event"
        />

        <!-- Mobile cards -->
        <div v-else-if="isNarrow" class="space-y-2">
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
                        <div v-if="'client' !== view" class="flex flex-wrap items-center gap-1.5 pt-0.5">
                            <AppBadge v-if="'prospect' === customer.status && stageOf(customer)" color="slate">{{ stageOf(customer).name }}</AppBadge>
                            <span
                                v-if="customer.nextFollowUpOn"
                                class="flex items-center gap-1 rounded-full px-1.5 py-0.5 text-xs"
                                :class="followUpTone(customer.followUpState)"
                            >
                                <BellRing class="h-3 w-3" :stroke-width="2" />
                                {{ formatDateShort(dayOf(customer.nextFollowUpOn)) }}
                            </span>
                        </div>
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
            <table class="aurora-table w-full text-sm">
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
                            v-if="'client' !== view"
                            class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted"
                        >
                            {{ t("suite.studio.pipeline.col_follow_up") }}
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
                        <!-- Where they stand and when to get back to them: what
                             the prospects and the follow-ups are read for. -->
                        <td v-if="'client' !== view" class="px-4 py-2">
                            <div class="flex flex-col items-start gap-1">
                                <AppBadge v-if="'prospect' === customer.status && stageOf(customer)" color="slate">{{ stageOf(customer).name }}</AppBadge>
                                <span
                                    v-if="customer.nextFollowUpOn"
                                    class="flex items-center gap-1 whitespace-nowrap rounded-full px-1.5 py-0.5 text-xs"
                                    :class="followUpTone(customer.followUpState)"
                                    :title="customer.followUpNote || undefined"
                                >
                                    <BellRing class="h-3 w-3" :stroke-width="2" />
                                    {{ formatDateShort(dayOf(customer.nextFollowUpOn)) }}
                                </span>
                                <span v-if="customer.followUpNote" class="max-w-56 truncate text-xs text-muted">{{ customer.followUpNote }}</span>
                                <span v-if="customer.source" class="text-xs text-muted">{{ sourceLabels.get(customer.source) }}</span>
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
                        <td :colspan="'client' !== view ? 6 : 5">
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
                    :locales="locales"
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
            v-on:close="closeConversionDialog"
            v-on:submit="submitConversion"
        />

        <PipelineStageModal
            :form="pipeline.stageForm.value"
            :errors="pipeline.stageErrors.value"
            :loading="pipeline.stageSaving.value"
            v-on:update:form="pipeline.stageForm.value = $event"
            v-on:close="pipeline.closeStageForm"
            v-on:submit="pipeline.saveStage"
        />

        <LostReasonModal
            :show="!!pipeline.pendingLoss.value"
            :name="pipeline.pendingLoss.value?.customer.legalName ?? ''"
            :model-value="pipeline.lostReason.value"
            :loading="pipeline.sending.value"
            v-on:update:model-value="pipeline.lostReason.value = $event"
            v-on:close="pipeline.cancelLoss"
            v-on:submit="pipeline.confirmLoss"
        />

        <AppModal
            :show="!!pipeline.pendingStageDelete.value"
            max-width="sm"
            :title="t('suite.studio.pipeline.delete_stage_title', { name: pipeline.pendingStageDelete.value?.name ?? '' })"
            :icon="Columns3"
            v-on:close="pipeline.pendingStageDelete.value = null"
        >
            <p class="m-0 text-sm text-secondary">{{ t("suite.studio.pipeline.delete_stage_body") }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pipeline.pendingStageDelete.value = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" :loading="pipeline.stageDeleting.value" v-on:click="pipeline.deleteStage">
                        {{ t("shared.common.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
