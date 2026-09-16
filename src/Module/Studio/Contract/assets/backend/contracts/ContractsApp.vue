<script setup>
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { safeContractHtml } from "../shared/contractHtml.js";
import { useContractsList } from "./composables/useContractsList.js";
import { useContractActions } from "./composables/useContractActions.js";
import ContractFormFields from "./components/ContractFormFields.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import { useListViewMode } from "@/shared/composables/list/useListViewMode.js";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppMessage from "@/shared/components/feedback/AppMessage.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";

const { formatDateNumeric } = useDateFormat();
import {
    AlertTriangle,
    Ban,
    Eye,
    FileSignature,
    LayoutGrid,
    List,
    Lock,
    Mail,
    MailCheck,
    Pencil,
    Plus,
    Save,
    Trash2,
    X,
} from "lucide-vue-next";

const { t } = useI18n();
const { request } = useRequest();
const { can } = usePrivileges();

const props = defineProps({
    contracts: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    bodies: { type: Array, default: () => [] },
    annexes: { type: Array, default: () => [] },
    locales: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    createPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
    freezePath: { type: String, required: true },
    previewPath: { type: String, required: true },
    sendPath: { type: String, required: true },
    revokeLinkPath: { type: String, required: true },
    countersignPath: { type: String, required: true },
    pdfPath: { type: String, required: true },
    exportPath: { type: String, required: true },
    showPath: { type: String, required: true },
    terminatePath: { type: String, required: true },
    terminationOrigins: { type: Array, default: () => [] },
    amendable: { type: Array, default: () => [] },
});

const {
    search,
    drafts,
    sealed,
    showCreate,
    newContract,
    createErrors,
    createLoading,
    openCreate,
    submitCreate,
    showEdit,
    editing,
    editForm,
    editErrors,
    editLoading,
    openEdit,
    submitEdit,
    pendingDelete,
    pendingFreeze,
    pendingSend,
    pendingRevoke,
    busy,
    confirmDelete,
    confirmFreeze,
    confirmSend,
    confirmRevoke,
    documentPath,
    formatAmount,
} = useContractsList(props);

/**
 * List first here too, and for the same reason: the sealed half of this page
 * is already a table, so the list view makes it one continuous list rather
 * than cards above rows.
 */
const { viewMode, setViewMode, storedViewMode, isNarrow, container } =
    useListViewMode(["list", "grid"], "list");

/**
 * `?amends=<id>` opens the form on an amendment of that contract.
 *
 * The document page of a concluded contract links here rather than growing its
 * own form: one screen creates contracts, and an amendment is a contract. The
 * parameter is dropped from the URL once used, so a reload does not reopen a
 * modal somebody closed.
 */
onMounted(() => {
    const requested = Number(
        new URLSearchParams(window.location.search).get("amends"),
    );

    if (!requested || !props.amendable.some((each) => each.id === requested)) {
        return;
    }

    openCreate(requested);

    const params = new URLSearchParams(window.location.search);
    params.delete("amends");
    const query = params.toString();
    window.history.replaceState(
        window.history.state,
        "",
        `${window.location.pathname}${query ? `?${query}` : ""}`,
    );
});

/**
 * One list of actions per half of the page, two presentations for the drafts.
 *
 * The rows fold them behind a single button, like every other list in the app;
 * the draft cards keep them laid out. Defined once so the two cannot drift.
 */
const { draftActions, sealedActions } = useContractActions();

/**
 * The contract as the client will read it, before it is sealed.
 *
 * Fetched rather than assembled here: the substitution is the application's,
 * and a second implementation in JavaScript would be a second answer to the
 * only question that matters - what the signer will see. The server renders
 * with the real customer, the real amount and the real date; what is not
 * knowable yet comes back as a slot.
 */
const preview = ref({
    open: false,
    loading: false,
    html: "",
    error: "",
    unknownTokens: [],
    reference: "",
});

const previewHtml = computed(() => safeContractHtml(preview.value.html));

async function openPreview(contract) {
    preview.value = {
        open: true,
        loading: true,
        html: "",
        error: "",
        unknownTokens: [],
        reference: contract.reference ?? contract.customerName ?? "",
    };

    const data = await request(buildPath(props.previewPath, { id: contract.id }), {
        method: "GET",
    });

    if (!data?.success) {
        preview.value = {
            ...preview.value,
            loading: false,
            error: data?.errors?.preview ?? t("backend.studio.contracts.preview_failed"),
        };

        return;
    }

    preview.value = {
        ...preview.value,
        loading: false,
        html: data.html ?? "",
        // Named rather than counted: these are the tokens that will stop the
        // seal, and the author needs to know which.
        unknownTokens: data.unknownTokens ?? [],
    };
}

/**
 * The address the export link points at.
 *
 * Built here rather than in the composable, which has no idea where the
 * application lives: the path template comes down with the page, and this is
 * the only place that holds it.
 */
function exportHref(contract) {
    return buildPath(props.exportPath, { id: contract.id });
}

const draftHandlers = {
    preview: openPreview,
    exportPath: exportHref,
    edit: openEdit,
    freeze: (contract) => (pendingFreeze.value = contract),
    remove: (contract) => (pendingDelete.value = contract),
};

const sealedHandlers = {
    send: (contract) => (pendingSend.value = contract),
    revoke: (contract) => (pendingRevoke.value = contract),
    documentPath,
    exportPath: exportHref,
};

function draftRowActions(contract) {
    return draftActions(contract, draftHandlers);
}

function sealedRowActions(contract) {
    return sealedActions(contract, sealedHandlers);
}

// One entry and still a sheet: every list in the backend opens its actions the
// same way, and a toolbar's width belongs to the search, not to a verb.
const pageActions = computed(() => {
    if (!can("studio.contracts.create")) {
        return [];
    }

    return [
        {
            key: "create",
            color: "accent",
            icon: Plus,
            title: t("backend.studio.contracts.add"),
            onSelect: () => openCreate(),
        },
    ];
});
</script>

<template>
    <div ref="container" class="space-y-6">
        <AppListToolbar>
            <AppSearchInput
                v-model="search"
                :placeholder="t('backend.studio.contracts.search_placeholder')"
            />
            <!-- The toggle drives the drafts: sealed contracts are already a
                 table, so list mode makes the whole page one list. It stays
                 beside the search on a phone, where stacked under the field it
                 read as a second filter. -->
            <template #inline>
                <div v-if="!isNarrow" class="flex shrink-0 border border-line/60 rounded-lg p-0.5">
                    <AppIconButton
                        :title="t('shared.common.list_view')"
                        :class="storedViewMode === 'list' ? 'bg-surface-3 text-primary' : 'text-muted hover:text-primary'"
                        v-on:click="setViewMode('list')"
                    >
                        <List class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                    <AppIconButton
                        :title="t('shared.common.grid_view')"
                        :class="storedViewMode === 'grid' ? 'bg-surface-3 text-primary' : 'text-muted hover:text-primary'"
                        v-on:click="setViewMode('grid')"
                    >
                        <LayoutGrid class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                </div>
            </template>
            <template #actions>
                <AppPageActions
                    v-if="pageActions.length"
                    :actions="pageActions"
                    class="w-full sm:w-auto"
                />
            </template>
        </AppListToolbar>

        <AppNoData
            v-if="!drafts.length && !sealed.length"
            :message="t('backend.studio.contracts.empty')"
        />

        <!-- Drafts first: what somebody is working on comes before history. -->
        <section v-if="drafts.length" class="space-y-2">
            <h2 class="text-xs font-medium uppercase tracking-wider text-muted">
                {{ t("backend.studio.contracts.in_preparation") }}
            </h2>
            <div v-if="viewMode === 'grid'" class="grid gap-3 md:grid-cols-2">
                <!-- `min-w-0` for the same reason as on the trames cards next
                     door: a grid item is `min-width: auto`, so the single
                     column below `md` sizes itself on the card's minimum
                     content width rather than on the space there is. Nothing
                     overflowed here yet, no text in this card being long
                     enough, but a customer name in one piece would widen the
                     track - and a widened track hangs every card in the list
                     past the right edge, not just the one at fault. -->
                <article
                    v-for="contract in drafts"
                    :key="contract.id"
                    class="bg-surface border border-line rounded-lg p-4 space-y-3 min-w-0"
                >
                    <div class="space-y-1">
                        <h3 class="font-medium text-primary">
                            {{ contract.customerName }}
                        </h3>
                        <p class="text-xs text-muted">
                            {{ contract.body?.templateName ?? "-" }}
                            <span v-if="contract.body">
                                ·
                                {{
                                    t("backend.studio.contracts.version_label", {
                                        number: contract.body.versionNumber,
                                    })
                                }}
                            </span>
                            <span v-if="formatAmount(contract)">
                                · {{ formatAmount(contract) }}
                            </span>
                        </p>
                        <p v-if="contract.annex" class="text-xs text-muted">
                            {{ t("backend.studio.contracts.with_annex", {
                                name: contract.annex.templateName,
                            }) }}
                        </p>
                    </div>

                    <!-- A draft pinned to an older version is not wrong, but it
                         is a choice somebody should see and be able to redo. -->
                    <p
                        v-if="contract.body?.isOutdated || contract.annex?.isOutdated"
                        class="flex items-start gap-2 text-xs text-amber-500"
                    >
                        <AlertTriangle class="w-3.5 h-3.5 shrink-0 mt-0.5" :stroke-width="2" />
                        {{ t("backend.studio.contracts.outdated_version") }}
                    </p>

                    <!-- The same actions as the row, laid out rather than
                         folded: a card has the room, and sealing keeps the
                         weight it has, being the one that cannot be undone. -->
                    <div class="flex flex-wrap gap-2 pt-1 border-t border-line/40">
                        <AppButton
                            v-for="action in draftRowActions(contract)"
                            :key="action.key"
                            :variant="action.key === 'freeze' ? 'secondary' : 'ghost'"
                            size="sm"
                            :href="action.href"
                            v-on:click="action.onSelect?.()"
                        >
                            <component :is="action.icon" v-if="action.icon" class="w-3.5 h-3.5" :stroke-width="2" />
                            {{ action.title }}
                        </AppButton>
                    </div>
                </article>
            </div>

            <!-- The same drafts as rows. No colour: the one thing worth
                 flagging here is a version left behind, and it says so in
                 words. -->
            <div
                v-else
                class="bg-surface border border-line rounded-lg overflow-x-auto scrollbar-thin"
            >
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-surface-2/50 border-b border-line/40">
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">
                                {{ t("backend.studio.contracts.col_customer") }}
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">
                                {{ t("backend.studio.contracts.body") }}
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">
                                {{ t("backend.studio.contracts.annex") }}
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell">
                                {{ t("backend.studio.contracts.amount") }}
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden xl:table-cell">
                                {{ t("backend.studio.contracts.effective_date") }}
                            </th>
                            <!-- Named, and the only column aligned right: it is
                                 where the hand goes, not something to read
                                 across with the rest. -->
                            <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted sticky right-0 bg-surface-2 border-l border-line/40">
                                {{ t("shared.common.actions") }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/40">
                        <tr
                            v-for="contract in drafts"
                            :key="contract.id"
                            class="hover:bg-surface-2/40 transition-colors"
                        >
                            <td class="px-6 py-3 text-primary">
                                {{ contract.customerName }}
                            </td>
                            <td class="px-6 py-3 text-muted">
                                <span class="text-primary">
                                    {{ contract.body?.templateName ?? "-" }}
                                </span>
                                <span v-if="contract.body" class="text-xs">
                                    ·
                                    {{
                                        t("backend.studio.contracts.version_label", {
                                            number: contract.body.versionNumber,
                                        })
                                    }}
                                </span>
                                <span
                                    v-if="contract.body?.isOutdated || contract.annex?.isOutdated"
                                    class="block text-xs text-amber-500"
                                >
                                    {{ t("backend.studio.contracts.outdated_version") }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-muted hidden lg:table-cell">
                                {{ contract.annex?.templateName ?? "-" }}
                            </td>
                            <td class="px-6 py-3 text-primary hidden md:table-cell whitespace-nowrap">
                                {{ formatAmount(contract) || "-" }}
                            </td>
                            <td class="px-6 py-3 text-muted text-xs hidden xl:table-cell whitespace-nowrap">
                                {{
                                    contract.effectiveDate
                                        ? formatDateNumeric(contract.effectiveDate)
                                        : "-"
                                }}
                            </td>
                            <td class="px-6 py-3 sticky right-0 bg-surface border-l border-line/40">
                                <AppRowActions
                                    :actions="draftRowActions(contract)"
                                    :label="contract.customerName ?? ''"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="sealed.length" class="space-y-2">
            <h2 class="text-xs font-medium uppercase tracking-wider text-muted">
                {{ t("backend.studio.contracts.sealed") }}
            </h2>
            <div
                v-if="!isNarrow"
                class="bg-surface border border-line rounded-lg overflow-x-auto scrollbar-thin"
            >
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-surface-2/50 border-b border-line/40">
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">
                                {{ t("backend.studio.contracts.col_reference") }}
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">
                                {{ t("backend.studio.contracts.col_customer") }}
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell">
                                {{ t("backend.studio.contracts.col_status") }}
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">
                                {{ t("backend.studio.contracts.col_sealed_at") }}
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden xl:table-cell">
                                {{ t("backend.studio.contracts.col_link") }}
                            </th>
                            <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted sticky right-0 bg-surface-2 border-l border-line/40">
                                {{ t("shared.common.actions") }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/40">
                        <tr
                            v-for="contract in sealed"
                            :key="contract.id"
                            class="hover:bg-surface-2/40 transition-colors"
                        >
                            <td class="px-6 py-3 font-mono text-xs text-primary whitespace-nowrap">
                                {{ contract.reference }}
                                <!-- An amendment reads as what it changes: the
                                     reference already carries the parentage,
                                     and this says it in words. -->
                                <span v-if="contract.amends" class="block text-2xs text-muted">
                                    {{
                                        t("backend.studio.contracts.amends_short", {
                                            reference: contract.amends.reference,
                                        })
                                    }}
                                </span>
                                <span v-if="contract.termination" class="block text-2xs text-amber-500">
                                    {{
                                        t("backend.studio.contracts.terminated_short", {
                                            date: formatDateNumeric(contract.termination.effectiveAt),
                                        })
                                    }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-primary">
                                {{ contract.customerName }}
                            </td>
                            <td class="px-6 py-3 text-muted hidden md:table-cell">
                                {{ t(contract.statusLabel) }}
                            </td>
                            <td class="px-6 py-3 text-muted text-xs hidden lg:table-cell whitespace-nowrap">
                                {{
                                    contract.frozenAt
                                        ? formatDateNumeric(contract.frozenAt)
                                        : "-"
                                }}
                            </td>
                            <!-- The one thing a link answers that nothing else
                                 can: whether the customer ever opened it. -->
                            <td class="px-6 py-3 text-xs hidden xl:table-cell">
                                <template v-if="contract.link">
                                    <div class="text-primary truncate max-w-[14rem]">
                                        {{ contract.link.recipientEmail }}
                                    </div>
                                    <div
                                        class="flex items-center gap-1"
                                        :class="
                                            contract.link.firstOpenedAt
                                                ? 'text-emerald-500'
                                                : 'text-muted'
                                        "
                                    >
                                        <MailCheck
                                            v-if="contract.link.firstOpenedAt"
                                            class="w-3 h-3 shrink-0"
                                            :stroke-width="2"
                                        />
                                        {{
                                            contract.link.firstOpenedAt
                                                ? t("backend.studio.contracts.link_opened_at", {
                                                    date: formatDateNumeric(
                                                        contract.link.firstOpenedAt,
                                                    ),
                                                })
                                                : t("backend.studio.contracts.link_never_opened")
                                        }}
                                    </div>
                                </template>
                                <span v-else class="text-muted">
                                    {{ t("backend.studio.contracts.no_link") }}
                                </span>
                            </td>
                            <td class="px-6 py-3 sticky right-0 bg-surface border-l border-line/40">
                                <AppRowActions
                                    :actions="sealedRowActions(contract)"
                                    :label="contract.reference ?? ''"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="space-y-2">
                <article
                    v-for="contract in sealed"
                    :key="contract.id"
                    class="bg-surface border border-line rounded-lg p-3 space-y-2.5"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-mono text-xs text-primary">{{ contract.reference }}</p>
                            <p class="font-medium text-primary break-words">{{ contract.customerName }}</p>
                            <p v-if="contract.amends" class="text-2xs text-muted">
                                {{ t("backend.studio.contracts.amends_short", { reference: contract.amends.reference }) }}
                            </p>
                            <p v-if="contract.termination" class="text-2xs text-amber-500">
                                {{ t("backend.studio.contracts.terminated_short", {
                                    date: formatDateNumeric(contract.termination.effectiveAt),
                                }) }}
                            </p>
                        </div>
                        <span class="text-xs text-muted shrink-0">{{ t(contract.statusLabel) }}</span>
                    </div>

                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted">
                        <span v-if="contract.frozenAt">{{ formatDateNumeric(contract.frozenAt) }}</span>
                        <span v-if="contract.link">{{ contract.link.recipientEmail }}</span>
                    </p>

                    <div class="flex flex-wrap gap-x-4 gap-y-1.5 border-t border-line/40 pt-2">
                        <AppButton
                            v-for="action in sealedRowActions(contract)"
                            :key="action.key"
                            variant="ghost"
                            size="sm"
                            :href="action.href"
                            v-on:click="action.onSelect?.()"
                        >
                            <component :is="action.icon" v-if="action.icon" class="w-3.5 h-3.5" :stroke-width="2" />
                            {{ action.title }}
                        </AppButton>
                    </div>
                </article>
            </div>
        </section>

        <!-- The document before it is sealed. Wide, because line length is
             part of what somebody proofreading is checking. -->
        <AppModal
            :show="preview.open"
            max-width="4xl"
            :title="t('backend.studio.contracts.preview')"
            :icon="Eye"
            v-on:close="preview.open = false"
        >
            <div class="space-y-3">
                <AppMessage variant="info">
                    {{ t("backend.studio.contracts.preview_notice") }}
                </AppMessage>

                <!-- Named, not counted. These are the tokens that will stop the
                     seal, and finding them here costs nothing - finding them at
                     the freeze costs a trip back through the form. -->
                <AppMessage v-if="preview.unknownTokens.length" variant="warning">
                    {{
                        t("backend.studio.contracts.preview_unknown_tokens", {
                            tokens: preview.unknownTokens.join(", "),
                        })
                    }}
                </AppMessage>

                <AppMessage v-if="preview.error" variant="danger">
                    {{ preview.error }}
                </AppMessage>

                <p v-else-if="preview.loading" class="text-sm text-muted">
                    {{ t("shared.common.loading") }}
                </p>

                <article
                    v-else
                    class="bg-surface border border-line rounded-lg p-6 prose-contract max-h-[65vh] overflow-y-auto"
                    v-html="previewHtml"
                />
            </div>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="preview.open = false">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.close") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="showCreate"
            max-width="lg"
            :closeable="false"
            :title="t('backend.studio.contracts.create')"
            :icon="FileSignature"
            v-on:close="showCreate = false"
        >
            <form v-on:submit.prevent="submitCreate">
                <ContractFormFields
                    v-model="newContract"
                    :errors="createErrors"
                    :customers="customers"
                    :bodies="bodies"
                    :annexes="annexes"
                    :locales="locales"
                    :currencies="currencies"
                    :amendable="amendable"
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
            max-width="lg"
            :closeable="false"
            :title="t('backend.studio.contracts.edit', { name: editing?.customerName ?? '' })"
            :icon="Pencil"
            v-on:close="showEdit = false"
        >
            <form v-on:submit.prevent="submitEdit">
                <ContractFormFields
                    v-model="editForm"
                    :errors="editErrors"
                    :customers="customers"
                    :bodies="bodies"
                    :annexes="annexes"
                    :locales="locales"
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
            :show="!!pendingFreeze"
            max-width="md"
            :closeable="false"
            :title="t('backend.studio.contracts.freeze')"
            :icon="Lock"
            v-on:close="pendingFreeze = null"
        >
            <p class="text-sm text-primary">
                {{
                    t("backend.studio.contracts.freeze_confirm", {
                        name: pendingFreeze?.customerName ?? "",
                    })
                }}
            </p>
            <!-- The consequence, before the click. This is the one irreversible
                 action on the page. -->
            <p class="text-sm text-secondary">
                {{ t("backend.studio.contracts.freeze_warning") }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingFreeze = null">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="primary"
                        size="md"
                        :loading="busy"
                        v-on:click="confirmFreeze"
                    >
                        <Lock class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("backend.studio.contracts.freeze") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="!!pendingSend"
            max-width="md"
            :closeable="false"
            :title="t('backend.studio.contracts.send')"
            :icon="Mail"
            v-on:close="pendingSend = null"
        >
            <p class="text-sm text-primary">
                {{
                    t("backend.studio.contracts.send_confirm", {
                        email: pendingSend?.link?.recipientEmail ?? "",
                    })
                }}
            </p>
            <p class="text-sm text-secondary">
                {{ t("backend.studio.contracts.send_warning") }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingSend = null">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="primary"
                        size="md"
                        :loading="busy"
                        v-on:click="confirmSend"
                    >
                        <Mail class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("backend.studio.contracts.send") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="!!pendingRevoke"
            max-width="md"
            :closeable="false"
            :title="t('backend.studio.contracts.revoke_link')"
            :icon="Ban"
            v-on:close="pendingRevoke = null"
        >
            <p class="text-sm text-primary">
                {{
                    t("backend.studio.contracts.revoke_link_confirm", {
                        email: pendingRevoke?.link?.recipientEmail ?? "",
                    })
                }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingRevoke = null">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="danger"
                        size="md"
                        :loading="busy"
                        v-on:click="confirmRevoke"
                    >
                        <Ban class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("backend.studio.contracts.revoke_link") }}
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
                    t("backend.studio.contracts.delete_confirm", {
                        name: pendingDelete?.customerName ?? "",
                    })
                }}
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
                        :loading="busy"
                        v-on:click="confirmDelete"
                    >
                        <Trash2 class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
