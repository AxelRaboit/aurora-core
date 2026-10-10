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
 *
 * **Read before the detail** (visual redesign of the suite, 10/10/2026): a
 * monogram and the name, the status and what the company is on one line,
 * then a strip of what surrounds them - contracts, spaces, deliverables, who
 * to talk to. The sheet is three titled cards; the right column stays in
 * view while the sheet scrolls.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { BadgeCheck, MessageSquareText, Save, Trash2, X } from "lucide-vue-next";
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
import { followUpFormFrom } from "./composables/customerFormModel.js";
import CustomerPipelineCard from "../pipeline/components/CustomerPipelineCard.vue";
import LostReasonModal from "../pipeline/components/LostReasonModal.vue";
import { useCustomerStage } from "../pipeline/composables/useCustomerStage.js";
import CustomerInteractionsCard from "../interactions/CustomerInteractionsCard.vue";
import CustomerInteractionModal from "../interactions/CustomerInteractionModal.vue";
import { useCustomerInteractions } from "../interactions/useCustomerInteractions.js";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";

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
    /** The pipeline's stages, to name and change theirs. */
    stages: { type: Array, default: () => [] },
    movePath: { type: String, default: "" },
    sources: { type: Array, default: () => [] },
    interactionKinds: { type: Array, default: () => [] },
    /** Their exchanges, newest first. */
    interactions: { type: Array, default: () => [] },
    interactionCreatePath: { type: String, default: "" },
    /** With `__id__`. */
    interactionUpdatePath: { type: String, default: "" },
    /** With `__id__`. */
    interactionDeletePath: { type: String, default: "" },
});

const { t } = useI18n();
const { can } = usePrivileges();

const FACT_KEYS = "suite.studio.customers.facts";

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

/**
 * The sheet read again after a gesture outside its form (a stage changed, an
 * exchange recorded): the saved sheet takes it whole, the form only its
 * follow-up - which the gesture may have moved - so input in progress in the
 * rest of the sheet is kept.
 */
function takeFollowUp(row) {
    const fields = { ...row };
    delete fields.spaces;
    delete fields.contracts;
    customer.value = { ...customer.value, ...fields };
    form.value = { ...form.value, ...followUpFormFrom(customer.value) };
}

const stages = computed(() => props.stages);

const stage = useCustomerStage({
    movePath: props.movePath,
    customer,
    stages,
    askConversion: () => openConversion(),
    onMoved: takeFollowUp,
});

const timeline = useCustomerInteractions({
    initial: props.interactions,
    paths: {
        create: props.interactionCreatePath,
        update: props.interactionUpdatePath,
        delete: props.interactionDeletePath,
    },
    customer,
    onCustomer: takeFollowUp,
});

const isProspect = computed(() => "prospect" === customer.value.status);

/** Two letters for the monogram: the first of the first two words. */
const monogram = computed(() =>
    (customer.value.legalName ?? "")
        .split(/[\s'’-]+/)
        .filter((word) => /\p{L}|\p{N}/u.test(word))
        .slice(0, 2)
        .map((word) => word.match(/\p{L}|\p{N}/u)[0].toLocaleUpperCase())
        .join(""),
);

/** What the company is, after its status: form and sector, when known. */
const metaParts = computed(() => [customer.value.legalForm, customer.value.activitySector].filter(Boolean));

/** How many rows of each detail a list holds: "Scellé : 2 · Brouillon : 1". */
function countByDetail(rows) {
    const counts = new Map();
    for (const row of rows) {
        if (row.detail) counts.set(row.detail, (counts.get(row.detail) ?? 0) + 1);
    }

    return [...counts].map(([detail, count]) => t(`${FACT_KEYS}.detail_count`, { detail, count })).join(" · ");
}

/**
 * The strip under the title. A list the reader cannot open (`null`) has no
 * cell, as it has no list on the right; the contact is always there, since
 * it is the sheet's own.
 */
const facts = computed(() => {
    const cells = [];
    const { contracts, spaces, deliverables } = props.related ?? {};

    if (Array.isArray(contracts)) {
        cells.push({ key: "contracts", value: contracts.length, caption: countByDetail(contracts), href: props.contractsPath });
    }
    if (Array.isArray(spaces)) {
        cells.push({ key: "spaces", value: spaces.length, caption: spaces.map((space) => space.label).join(", "), href: props.spacesPath });
    }
    if (Array.isArray(deliverables)) {
        cells.push({ key: "deliverables", value: deliverables.length, caption: countByDetail(deliverables), href: null });
    }

    const person = customer.value.representativeFullName;
    cells.push({
        key: "contact",
        value: person || customer.value.contractualEmail || t(`${FACT_KEYS}.no_contact`),
        caption: person ? [customer.value.representativeRole, customer.value.contractualEmail].filter(Boolean).join(" · ") : "",
        href: null,
        text: true,
    });

    return cells;
});

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

        <div class="flex min-w-0 items-center gap-4" data-customer-heading>
            <span
                v-if="monogram"
                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-accent/10 text-base font-semibold text-accent-500"
                aria-hidden="true"
            >{{ monogram }}</span>
            <div class="min-w-0">
                <h1 class="m-0 break-words text-[1.625rem] font-semibold leading-tight tracking-tight text-primary">{{ customer.legalName }}</h1>
                <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[0.8125rem] text-secondary">
                    <AppBadge :color="isProspect ? 'amber' : 'emerald'">{{ t(customer.statusLabel) }}</AppBadge>
                    <template v-for="(part, index) in metaParts" :key="index">
                        <span v-if="index" aria-hidden="true">·</span>
                        <span>{{ part }}</span>
                    </template>
                    <!-- Said in words beside the title, as on a deliverable: the
                         greyed Enregistrer alone did not say why it woke up. -->
                    <AppBadge v-if="dirty" color="amber">{{ t("shared.common.autosave.pending") }}</AppBadge>
                </div>
            </div>
        </div>

        <!-- What surrounds them, before the detail. -->
        <div class="aurora-card grid grid-cols-2 sm:auto-cols-fr sm:grid-flow-col" data-customer-facts>
            <component
                :is="fact.href ? 'a' : 'div'"
                v-for="fact in facts"
                :key="fact.key"
                :href="fact.href || undefined"
                class="min-w-0 border-line p-4 no-underline odd:border-r sm:border-r sm:p-5 sm:last:border-r-0 max-sm:[&:nth-child(n+3)]:border-t"
                :class="fact.href ? 'transition-colors hover:bg-surface-2/40' : ''"
                :data-customer-fact="fact.key"
            >
                <p class="m-0 text-xs font-semibold uppercase tracking-wider text-secondary">{{ t(`${FACT_KEYS}.${fact.key}`) }}</p>
                <p
                    class="m-0 truncate font-semibold text-primary"
                    :class="fact.text ? 'mt-3 text-[0.9375rem]' : 'mt-2 text-[1.625rem] leading-tight tracking-tight tabular-nums'"
                >
                    {{ fact.value }}
                </p>
                <p v-if="fact.caption" class="m-0 mt-1 line-clamp-2 text-xs text-secondary" :title="fact.caption">{{ fact.caption }}</p>
            </component>
        </div>

        <!-- The screen's how-to, next to what it explains; collapsed or
             expanded, the choice applies to every guide. -->
        <AppGuide :title="t('suite.studio.customers.page_guide.title')" storage-key="customer-page">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 5" :key="step">{{ t(`suite.studio.customers.page_guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <AppMessage v-if="!canEdit" variant="neutral">{{ t("suite.studio.customers.read_only") }}</AppMessage>

        <!-- The sheet on the left, what surrounds it on the right on a large
             screen, in view while the sheet scrolls; one under the other
             elsewhere. -->
        <div class="grid grid-cols-1 items-start aurora-gap lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <form class="min-w-0" :inert="inertWhenReadOnly" v-on:submit.prevent="save">
                <CustomerFormFields v-model="form" :errors="errors" :currencies="currencies" framed />
            </form>

            <!-- The follow-up and the history first: they are what the page
                 is opened for while a deal is in progress. No longer sticky:
                 a column taller than the window cannot stay in view. -->
            <section class="flex min-w-0 flex-col gap-3">
                <form :inert="inertWhenReadOnly" v-on:submit.prevent="save">
                    <CustomerPipelineCard
                        v-model="form"
                        :errors="errors"
                        :customer="customer"
                        :stages="stages"
                        :sources="sources"
                        :currencies="currencies"
                        :editable="canEdit"
                        :moving="stage.moving.value"
                        v-on:change-stage="stage.change"
                    />
                </form>
                <CustomerInteractionsCard
                    :interactions="timeline.interactions.value"
                    :kinds="interactionKinds"
                    :editable="canEdit"
                    v-on:add="timeline.openCreate()"
                    v-on:edit="timeline.openEdit"
                    v-on:delete="timeline.pendingDelete.value = $event"
                />
                <h2 class="m-0 mt-2 text-xs font-semibold uppercase tracking-wider text-secondary">
                    {{ t("suite.studio.customers.group_related") }}
                </h2>
                <article class="aurora-card p-4 sm:p-5">
                    <CustomerRelatedLists :related="related" :all-paths="{ contracts: contractsPath, spaces: spacesPath }" />
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

        <LostReasonModal
            :show="!!stage.pendingLoss.value"
            :name="customer.legalName"
            :model-value="stage.lostReason.value"
            :loading="stage.moving.value"
            v-on:update:model-value="stage.lostReason.value = $event"
            v-on:close="stage.cancelLoss"
            v-on:submit="stage.confirmLoss"
        />

        <CustomerInteractionModal
            :form="timeline.form.value"
            :errors="timeline.errors.value"
            :kinds="interactionKinds"
            :loading="timeline.saving.value"
            v-on:update:form="timeline.form.value = $event"
            v-on:close="timeline.close"
            v-on:submit="timeline.save"
        />

        <AppModal
            :show="!!timeline.pendingDelete.value"
            max-width="sm"
            :title="t('suite.studio.customer_interactions.delete_title')"
            :icon="MessageSquareText"
            v-on:close="timeline.pendingDelete.value = null"
        >
            <p class="m-0 text-sm text-secondary">{{ t("suite.studio.customer_interactions.delete_body") }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="timeline.pendingDelete.value = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" :loading="timeline.deleting.value" v-on:click="timeline.remove">
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
