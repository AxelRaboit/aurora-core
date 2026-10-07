<script setup>
/**
 * The wording of one part of a contract, adapted for its client.
 *
 * Opens on the adapted text when there is one, or on a copy of the trame's:
 * the first save is what creates the adaptation, so there is no separate
 * « start adapting » step to find. The trame itself is never written from
 * here.
 *
 * Beside the editor, the differences with the trame, live as the text
 * changes: what a reviewer reads before the contract goes out.
 *
 * Read-only once the contract is sealed, or for a reader who may not edit:
 * the page then shows the text that was (or will be) sealed.
 */
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { wordingDiff } from "./composables/wordingDiff.js";
import ContractVariablePanel from "../contract-templates/components/ContractVariablePanel.vue";
import AppBlockEditor from "@/shared/components/editor/AppBlockEditor.vue";
import AppPageBar from "@/shared/components/nav/AppPageBar.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppMessage from "@/shared/components/feedback/AppMessage.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import { Lock, RotateCcw, Save, X } from "lucide-vue-next";

/** The blocks a contract can print, as in the trame editor. */
const CONTRACT_BLOCKS = ["header", "paragraph", "list", "quote", "table"];

const WORDING_KEYS = "suite.studio.contracts.wording";

/**
 * A plain copy. `structuredClone` refuses Vue's reactive proxies, and the
 * editor must never write into the props it was given.
 */
function copy(value) {
    return JSON.parse(JSON.stringify(value));
}

const props = defineProps({
    contract: { type: Object, required: true },
    part: { type: String, required: true },
    parts: { type: Array, default: () => [] },
    template: { type: Object, default: null },
    original: { type: Object, default: null },
    adapted: { type: Object, default: null },
    locale: { type: String, required: true },
    variableGroups: { type: Array, default: () => [] },
    savePath: { type: String, required: true },
    resetPath: { type: String, required: true },
    showPath: { type: String, required: true },
    templateVersionPath: { type: String, required: true },
});

const { t } = useI18n();
const { can } = usePrivileges();
const { request } = useRequest();
const { formatDateTime } = useDateFormat();

const isFrozen = computed(() => true === props.contract.isFrozen);
const canEdit = computed(() => !isFrozen.value && can("studio.contracts.edit"));

const adaptation = ref(props.adapted ? copy(props.adapted) : null);

function startingWording() {
    const source = adaptation.value ?? props.original ?? { title: "", blocks: [] };

    return { title: source.title ?? "", blocks: copy(source.blocks ?? []) };
}

const wording = ref(startingWording());
const savedSnapshot = ref(JSON.stringify(wording.value));

const isDirty = computed(() => canEdit.value && JSON.stringify(wording.value) !== savedSnapshot.value);

function warnBeforeLeaving(event) {
    if (!isDirty.value) return;

    event.preventDefault();
    event.returnValue = "";
}

onMounted(() => window.addEventListener("beforeunload", warnBeforeLeaving));
onBeforeUnmount(() => window.removeEventListener("beforeunload", warnBeforeLeaving));

/* What differs from the trame, as the text changes. */
const difference = computed(() => wordingDiff(props.original, wording.value));

const templateHref = computed(() =>
    props.template
        ? buildPath(props.templateVersionPath, { id: props.template.id, versionId: props.template.versionId })
        : null,
);

/** A duplicate moved to a newer version keeps the text adapted from the older one. */
const fromOlderVersion = computed(
    () =>
        null !== adaptation.value &&
        null !== props.template &&
        null !== adaptation.value.baseVersionId &&
        adaptation.value.baseVersionId !== props.template.versionId,
);

/* Saving. */

const saving = ref(false);
const errors = ref({});

async function save() {
    if (!canEdit.value || saving.value) return;

    saving.value = true;
    errors.value = {};

    try {
        const data = await request(
            props.savePath,
            { title: wording.value.title, content: { blocks: wording.value.blocks } },
            { noGuard: true },
        );

        if (!data?.success) {
            if (data?.errors) errors.value = data.errors;

            return;
        }

        adaptation.value = {
            title: wording.value.title,
            blocks: copy(wording.value.blocks),
            adaptedAt: data.contract?.[props.part]?.adaptedAt ?? new Date().toISOString(),
            baseVersionId: data.contract?.[props.part]?.adaptedFromVersionId ?? props.template?.versionId ?? null,
        };
        savedSnapshot.value = JSON.stringify(wording.value);
        toast.success(t(`${WORDING_KEYS}.saved`));
    } finally {
        saving.value = false;
    }
}

/* Going back to the trame. */

const showReset = ref(false);
const resetting = ref(false);

async function reset() {
    if (resetting.value) return;

    resetting.value = true;
    errors.value = {};

    try {
        const data = await request(props.resetPath, {}, { noGuard: true });

        if (!data?.success) {
            if (data?.errors) errors.value = data.errors;
            showReset.value = false;

            return;
        }

        adaptation.value = null;
        wording.value = startingWording();
        savedSnapshot.value = JSON.stringify(wording.value);
        showReset.value = false;
        toast.success(t(`${WORDING_KEYS}.reset_done`));
    } finally {
        resetting.value = false;
    }
}

const pageActions = computed(() =>
    canEdit.value && null !== adaptation.value
        ? [
            {
                key: "reset",
                color: "rose",
                icon: RotateCcw,
                title: t(`${WORDING_KEYS}.reset`),
                onSelect: () => (showReset.value = true),
            },
        ]
        : [],
);

const otherParts = computed(() => props.parts.filter((each) => each.key !== props.part));
</script>

<template>
    <div class="aurora-stack">
        <!-- The page's bar first: back on the left, the commands on the right
             (AppPageBar, 02/10/2026). -->
        <AppPageBar :back-href="showPath" :back-label="contract.reference ? t(`${WORDING_KEYS}.back`, { reference: contract.reference }) : t(`${WORDING_KEYS}.back_draft`)">
            <AppPageActions
                v-if="pageActions.length"
                :actions="pageActions"
                :label="t(`${WORDING_KEYS}.title`)"
                icon-only-on-phone
            />
            <AppButton
                v-if="canEdit"
                :loading="saving"
                :label="t(`${WORDING_KEYS}.save`)"
                icon-only-on-phone
                v-on:click="save"
            >
                <Save class="w-4 h-4" :stroke-width="2" />
            </AppButton>
        </AppPageBar>

        <!-- The screen's how-to, next to what it explains; collapsed or
             expanded, the choice applies to every guide box. -->
        <AppGuide :title="t('suite.studio.contracts.wording.guide.title')" storage-key="contract-wording">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 5" :key="step">{{ t(`suite.studio.contracts.wording.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <AppMessage v-if="isFrozen" variant="info">
            <span class="flex items-center gap-2">
                <Lock class="w-4 h-4 shrink-0" :stroke-width="2" />
                {{ t(`${WORDING_KEYS}.frozen_notice`) }}
            </span>
        </AppMessage>
        <AppMessage v-else-if="!canEdit" variant="info">{{ t(`${WORDING_KEYS}.readonly_notice`) }}</AppMessage>
        <AppMessage v-else-if="null === adaptation" variant="info">{{ t(`${WORDING_KEYS}.not_adapted`) }}</AppMessage>

        <AppMessage v-if="fromOlderVersion" variant="warning">
            {{ t(`${WORDING_KEYS}.from_older_version`) }}
            <a v-if="templateHref" class="underline" :href="templateHref">{{ template.name }}</a>
        </AppMessage>
        <AppMessage v-if="errors.status" variant="danger">{{ errors.status }}</AppMessage>
        <AppMessage v-if="errors.content" variant="danger">{{ errors.content }}</AppMessage>
        <AppMessage v-if="errors.part" variant="danger">{{ errors.part }}</AppMessage>

        <div class="min-w-0 space-y-1">
            <div class="flex flex-wrap items-baseline gap-2">
                <h1 class="text-xl font-semibold tracking-tight text-primary sm:text-2xl">
                    {{ t(`${WORDING_KEYS}.heading`, { customer: contract.customerName }) }}
                </h1>
                <span class="text-sm text-muted">{{ t(`${WORDING_KEYS}.parts.${part}`) }}</span>
                <AppBadge v-if="adaptation" color="violet">{{ t(`${WORDING_KEYS}.badge`) }}</AppBadge>
                <AppBadge v-if="isDirty" color="amber">{{ t(`${WORDING_KEYS}.unsaved`) }}</AppBadge>
            </div>
            <p v-if="template" class="text-sm text-secondary">
                {{ t(`${WORDING_KEYS}.intro`, { template: template.name, number: template.versionNumber }) }}
            </p>
            <p v-if="adaptation?.adaptedAt" class="text-xs text-muted">
                {{ t(`${WORDING_KEYS}.adapted_on`, { date: formatDateTime(adaptation.adaptedAt) }) }}
            </p>
        </div>

        <!-- The other part of the contract, when it has one: the body and its
             annex are adapted separately, each against its own trame. -->
        <div v-if="otherParts.length" class="flex flex-wrap gap-1 border-b border-line" role="tablist">
            <span class="px-3 py-2 text-sm border-b-2 -mb-px border-accent-500 text-primary" role="tab" aria-selected="true">
                {{ t(`${WORDING_KEYS}.parts.${part}`) }}
            </span>
            <a
                v-for="each in otherParts"
                :key="each.key"
                :href="each.path"
                role="tab"
                aria-selected="false"
                class="px-3 py-2 text-sm border-b-2 -mb-px border-transparent text-muted hover:text-primary flex items-center gap-1.5"
            >
                {{ t(`${WORDING_KEYS}.parts.${each.key}`) }}
                <span v-if="each.isAdapted" class="w-1.5 h-1.5 rounded-full bg-violet-400" :title="t(`${WORDING_KEYS}.badge`)" />
            </a>
        </div>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
            <div class="min-w-0 space-y-3">
                <AppInput
                    v-model="wording.title"
                    :label="t(`${WORDING_KEYS}.document_title`)"
                    :placeholder="t('suite.studio.contract_templates.document_title_placeholder')"
                    :error="errors.title"
                    :readonly="!canEdit"
                />
                <!-- A long token breaks instead of spilling out of the card. -->
                <div class="aurora-card p-3 [overflow-wrap:anywhere]" :class="{ 'opacity-80': !canEdit }">
                    <AppBlockEditor
                        v-model="wording.blocks"
                        :block-tools="CONTRACT_BLOCKS"
                        :read-only="!canEdit"
                    />
                </div>
            </div>

            <aside class="min-w-0 space-y-4">
                <section class="aurora-card p-3 space-y-2 text-sm">
                    <p class="text-xs font-medium uppercase tracking-wider text-muted">{{ t(`${WORDING_KEYS}.differences`) }}</p>
                    <p v-if="!difference.changes.length" class="text-muted">{{ t(`${WORDING_KEYS}.differences_none`) }}</p>
                    <template v-else>
                        <p class="text-xs text-secondary">
                            {{ t(`${WORDING_KEYS}.differences_summary`, { added: difference.added, removed: difference.removed }) }}
                        </p>
                        <ul class="space-y-1.5 max-h-[28rem] overflow-y-auto">
                            <li
                                v-for="(change, index) in difference.changes"
                                :key="index"
                                class="rounded-md px-2 py-1 text-xs break-words"
                                :class="
                                    'added' === change.type
                                        ? 'bg-emerald-500/10 text-emerald-300'
                                        : 'bg-rose-500/10 text-rose-300 line-through'
                                "
                            >
                                <span class="sr-only">{{ t(`${WORDING_KEYS}.diff_${change.type}`) }} :</span>
                                {{ change.text }}
                            </li>
                        </ul>
                    </template>
                </section>

                <ContractVariablePanel v-if="canEdit" :groups="variableGroups" />
            </aside>
        </div>

        <AppModal
            :show="showReset"
            max-width="md"
            :title="t(`${WORDING_KEYS}.reset_title`)"
            :icon="RotateCcw"
            v-on:close="showReset = false"
        >
            <p class="text-sm text-secondary">
                {{ t(`${WORDING_KEYS}.reset_confirm`, { template: template?.name ?? "" }) }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showReset = false">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" :loading="resetting" v-on:click="reset">
                        <RotateCcw class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t(`${WORDING_KEYS}.reset`) }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
