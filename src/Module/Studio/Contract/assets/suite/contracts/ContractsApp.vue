<script setup>
/**
 * Every contract, in one list read by step.
 *
 * The tabs are the journey: drafts, contracts to send, out with the customer,
 * waiting for the countersignature, running, ended. Each row says where the
 * contract stands and offers only what its status allows; opening it leads to
 * its own screen, where the next step is the main button.
 */
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Eye, FileSignature, Pencil, Plus, Save, X } from "lucide-vue-next";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { contractStatusColor } from "@/shared/utils/format/statusStyles.js";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { safeContractHtml } from "../shared/contractHtml.js";
import { STEPS, useContractsList } from "./composables/useContractsList.js";
import { useContractFlow } from "./composables/useContractFlow.js";
import ContractFormFields from "./components/ContractFormFields.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppMessage from "@/shared/components/feedback/AppMessage.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppPagination from "@/shared/components/nav/AppPagination.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import AppGuide from "@/shared/components/feedback/AppGuide.vue";

const props = defineProps({
    /** How long a signing address stays valid, from the setting. */
    linkDays: { type: Number, default: 30 },
    contracts: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    bodies: { type: Array, default: () => [] },
    annexes: { type: Array, default: () => [] },
    locales: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    createPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    previewPath: { type: String, required: true },
    duplicatePath: { type: String, required: true },
    pdfPath: { type: String, required: true },
    exportPath: { type: String, required: true },
    showPath: { type: String, required: true },
    amendable: { type: Array, default: () => [] },
});

const { t } = useI18n();
const { can } = usePrivileges();
const { request } = useRequest();
const { formatDateNumeric } = useDateFormat();
const { flowOf } = useContractFlow({ linkDays: props.linkDays });

const P = "suite.studio.contracts";
const F = `${P}.flow`;

const {
    search,
    step,
    customerFilter,
    templateFilter,
    page,
    totalPages,
    counts,
    rows,
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
    formatAmount,
} = useContractsList(props);

const steps = computed(() => ["all", ...STEPS]);

const customerOptions = computed(() => [{ value: "", label: t(`${F}.list.all_customers`) }, ...props.customers]);
const templateOptions = computed(() => [
    { value: "", label: t(`${F}.list.all_templates`) },
    ...[...props.bodies, ...props.annexes].map((template) => ({ value: template.value, label: template.label })),
]);

/**
 * `?amends=<id>` opens the form on an amendment of that contract: the
 * contract's own screen links here. Dropped from the address once used, so a
 * reload does not reopen a form somebody closed.
 */
onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    const requested = Number(params.get("amends"));

    if (requested && props.amendable.some((each) => each.id === requested)) {
        openCreate(requested);
    }

    if (params.has("amends")) {
        params.delete("amends");
        const query = params.toString();
        window.history.replaceState(window.history.state, "", `${window.location.pathname}${query ? `?${query}` : ""}`);
    }
});

function showHref(contract, gesture = null) {
    const path = buildPath(props.showPath, { id: contract.id });

    return gesture ? `${path}?do=${gesture}` : path;
}

/* The preview of a draft, without leaving the list. */
const preview = ref({ open: false, loading: false, html: "", error: "", unknownTokens: [] });
const previewHtml = computed(() => safeContractHtml(preview.value.html));

async function openPreview(contract) {
    preview.value = { open: true, loading: true, html: "", error: "", unknownTokens: [] };

    const data = await request(buildPath(props.previewPath, { id: contract.id }), null, { method: HttpMethod.Get });

    preview.value = data?.success
        ? { open: true, loading: false, html: data.html ?? "", error: "", unknownTokens: data.unknownTokens ?? [] }
        : { open: true, loading: false, html: "", error: data?.errors?.preview ?? t(`${P}.preview_failed`), unknownTokens: [] };
}

async function duplicate(contract) {
    const data = await request(buildPath(props.duplicatePath, { id: contract.id }), {});

    if (!data?.success) {
        if (data?.errors) toast.error(Object.values(data.errors)[0]);

        return;
    }

    toast.success(t(`${F}.done.duplicated`));
    if (data.showPath) window.location.assign(data.showPath);
}

/**
 * What a row's menu does. Reading, exporting, editing a draft and previewing
 * it happen here; every other gesture opens the contract's screen straight on
 * its confirmation, so each is written once.
 */
function bind(contract, action) {
    const hrefs = {
        open: showHref(contract),
        download: buildPath(props.pdfPath, { id: contract.id }),
        export: buildPath(props.exportPath, { id: contract.id }),
    };

    if (hrefs[action.key]) return { ...action, href: hrefs[action.key] };

    const handlers = {
        edit: () => openEdit(contract),
        preview: () => openPreview(contract),
        duplicate: () => duplicate(contract),
        amend: () => openCreate(contract.id),
    };

    return handlers[action.key]
        ? { ...action, onSelect: handlers[action.key] }
        : { ...action, href: showHref(contract, action.key) };
}

function rowActions(contract) {
    const { next, others } = flowOf(contract, { list: true });
    const [open, ...rest] = others;

    // « Ouvrir » first, then the next step, then the rest.
    return [open, ...(next ? [next] : []), ...rest].map((action) => bind(contract, action));
}

const pageActions = computed(() =>
    can("studio.contracts.create")
        ? [{ key: "create", color: "accent", icon: Plus, title: t(`${P}.add`), onSelect: () => openCreate() }]
        : [],
);
</script>

<template>
    <div class="aurora-stack">
        <AppListToolbar>
            <AppSearchInput v-model="search" :placeholder="t(`${F}.list.search`)" />
            <template #inline>
                <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    <AppSelect v-model="customerFilter" class="sm:min-w-[12rem]" :options="customerOptions" />
                    <AppSelect v-model="templateFilter" class="sm:min-w-[12rem]" :options="templateOptions" />
                </div>
            </template>
            <template #actions>
                <AppPageActions v-if="pageActions.length" :actions="pageActions" class="w-full sm:w-auto" />
            </template>
        </AppListToolbar>
        <!-- Le mode d'emploi de l'écran, à côté de ce qu'il explique ;
             replié ou déplié, le choix vaut pour tous les encarts. -->
        <AppGuide :title="t('suite.studio.contracts.guide.title')" storage-key="contracts-list">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="n in 5" :key="n">{{ t(`suite.studio.contracts.guide.step_${n}`, { days: linkDays }) }}</li>
            </ol>
        </AppGuide>

        <!-- The journey, as tabs. The count says whether a step is worth
             opening, and the hint what it holds. -->
        <nav class="flex flex-wrap items-center gap-1" :aria-label="t(`${P}.title`)">
            <AppTab
                v-for="key in steps"
                :key="key"
                size="sm"
                :active="step === key"
                :title="'all' === key ? '' : t(`${F}.steps_hint.${key}`)"
                v-on:click="step = key"
            >
                {{ t(`${F}.steps.${key}`) }}
                <span class="ml-1 tabular-nums text-muted">{{ counts[key] ?? 0 }}</span>
            </AppTab>
        </nav>
        <p v-if="'all' !== step" class="text-xs text-muted">{{ t(`${F}.steps_hint.${step}`) }}</p>

        <AppNoData v-if="!rows.length" :message="'all' === step ? t(`${P}.empty`) : t(`${F}.empty_step`)" />

        <div v-else class="aurora-card overflow-x-auto scrollbar-thin hidden md:block">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-surface-2/50 border-b border-line/40 text-left text-xs font-medium uppercase tracking-wider text-muted">
                        <th class="px-4 py-2">{{ t(`${F}.list.reference`) }}</th>
                        <th class="px-4 py-2">{{ t(`${F}.list.customer`) }}</th>
                        <th class="px-4 py-2 hidden lg:table-cell">{{ t(`${F}.list.template`) }}</th>
                        <th class="px-4 py-2 hidden lg:table-cell">{{ t(`${F}.list.amount`) }}</th>
                        <th class="px-4 py-2 hidden xl:table-cell">{{ t(`${F}.list.effective_date`) }}</th>
                        <th class="px-4 py-2">{{ t(`${F}.list.status`) }}</th>
                        <th class="px-4 py-2 hidden xl:table-cell">{{ t(`${F}.list.last_activity`) }}</th>
                        <th class="px-4 py-2 text-right sticky right-0 bg-surface-2 border-l border-line/40">{{ t("shared.common.actions") }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line/40">
                    <tr v-for="contract in rows" :key="contract.id" class="hover:bg-surface-2/40 transition-colors">
                        <td class="px-4 py-2 whitespace-nowrap">
                            <a class="font-mono text-xs text-primary hover:underline" :href="showHref(contract)">
                                {{ contract.reference ?? t(`${F}.list.draft_reference`) }}
                            </a>
                            <span v-if="contract.amends" class="block text-2xs text-muted">
                                {{ t(`${F}.list.amends`, { reference: contract.amends.reference }) }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-primary">{{ contract.customerName }}</td>
                        <td class="px-4 py-2 text-muted hidden lg:table-cell">
                            <span class="text-primary">{{ contract.body?.templateName ?? "-" }}</span>
                            <span v-if="contract.body" class="text-xs"> · v{{ contract.body.versionNumber }}</span>
                            <AppBadge v-if="contract.isAdapted" color="violet" class="ml-1 align-middle">{{ t("suite.studio.contracts.wording.badge") }}</AppBadge>
                            <span v-if="contract.annex" class="block text-xs">+ {{ contract.annex.templateName }}</span>
                        </td>
                        <td class="px-4 py-2 text-primary whitespace-nowrap tabular-nums hidden lg:table-cell">{{ formatAmount(contract) ?? "-" }}</td>
                        <td class="px-4 py-2 text-muted text-xs whitespace-nowrap hidden xl:table-cell">
                            {{ contract.effectiveDate ? formatDateNumeric(contract.effectiveDate) : "-" }}
                        </td>
                        <td class="px-4 py-2">
                            <AppBadge :color="contractStatusColor(contract.status)">{{ t(contract.statusLabel) }}</AppBadge>
                        </td>
                        <td class="px-4 py-2 text-muted text-xs whitespace-nowrap hidden xl:table-cell">
                            {{ contract.lastActivityAt ? formatDateNumeric(contract.lastActivityAt) : "-" }}
                        </td>
                        <td class="px-4 py-2 sticky right-0 bg-surface border-l border-line/40">
                            <AppRowActions :actions="rowActions(contract)" :label="contract.reference ?? contract.customerName ?? ''" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- The same rows as cards on a phone. -->
        <div v-if="rows.length" class="space-y-2 md:hidden">
            <article v-for="contract in rows" :key="contract.id" class="aurora-card p-3 space-y-2 min-w-0">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <a class="font-mono text-xs text-primary hover:underline" :href="showHref(contract)">
                            {{ contract.reference ?? t(`${F}.list.draft_reference`) }}
                        </a>
                        <p class="font-medium text-primary break-words">{{ contract.customerName }}</p>
                        <p class="text-xs text-muted">
                            {{ contract.body?.templateName ?? "-" }}<template v-if="formatAmount(contract)"> · {{ formatAmount(contract) }}</template>
                            <AppBadge v-if="contract.isAdapted" color="violet" class="ml-1 align-middle">{{ t("suite.studio.contracts.wording.badge") }}</AppBadge>
                        </p>
                    </div>
                    <!-- Le statut, puis les gestes derrière le bouton « … », comme
                         sur toutes les listes (décision d'Axel du 04/10/2026). -->
                    <div class="flex shrink-0 items-start gap-1">
                        <AppBadge :color="contractStatusColor(contract.status)">{{ t(contract.statusLabel) }}</AppBadge>
                        <AppRowActions :actions="rowActions(contract)" :label="contract.reference ?? contract.customerName ?? ''" />
                    </div>
                </div>
            </article>
        </div>

        <AppPagination v-if="totalPages > 1" :page="page" :total-pages="totalPages" v-on:change="page = $event" />

        <!-- The document before it is sealed, without leaving the list. -->
        <AppModal
            :show="preview.open"
            max-width="4xl"
            :title="t('suite.studio.contracts.preview')"
            :icon="Eye"
            v-on:close="preview.open = false"
        >
            <div class="space-y-3">
                <AppMessage variant="info">
                    {{ t("suite.studio.contracts.preview_notice") }}
                </AppMessage>

                <!-- Named, not counted. These are the tokens that will stop the
                     seal, and finding them here costs nothing - finding them at
                     the freeze costs a trip back through the form. -->
                <AppMessage v-if="preview.unknownTokens.length" variant="warning">
                    {{
                        t("suite.studio.contracts.preview_unknown_tokens", {
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
                    class="aurora-card p-4 prose-contract max-h-[65vh] overflow-y-auto [overflow-wrap:anywhere]"
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
            :title="t(`${P}.create`)"
            :icon="FileSignature"
            v-on:close="showCreate = false"
        >
            <form v-on:submit.prevent="submitCreate">
                <!-- The parent only when coming from a contract: an amendment
                     is started from what it amends, not from this form. -->
                <ContractFormFields
                    v-model="newContract"
                    :errors="createErrors"
                    :customers="customers"
                    :bodies="bodies"
                    :annexes="annexes"
                    :locales="locales"
                    :currencies="currencies"
                    :amendable="newContract.amendsId ? amendable : []"
                />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showCreate = false">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" :loading="createLoading" v-on:click="submitCreate">
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
            :title="t(`${P}.edit`, { name: editing?.customerName ?? '' })"
            :icon="Pencil"
            v-on:close="showEdit = false"
        >
            <form v-on:submit.prevent="submitEdit">
                <ContractFormFields
                    v-model="editForm"
                    :errors="editErrors"
                    :amendable="editForm.amendsId ? amendable : []"
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
                    <AppButton variant="primary" size="md" :loading="editLoading" v-on:click="submitEdit">
                        <Save class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.save") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
