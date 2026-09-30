<script setup>
/**
 * One contract, from its first draft to its end.
 *
 * The screen answers two questions before anything else: where does this
 * contract stand, and what is the next thing to do. It used to show a seal and
 * a « Retour » link, and nothing else until the countersignature: a draft was
 * an empty page, a sealed contract could not be sent from here, and after
 * sealing the next step was on another screen.
 *
 * Now the step comes first, with its button, and the document is always on
 * the page - rendered from today's trames while it is a draft, printed from
 * the sealed HTML afterwards. Beside it: the facts, the link, the signatures
 * as proof, the seal and what happened, in that order.
 */
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import {
    CalendarX,
    Check,
    Copy,
    Lock,
    PenLine,
    ShieldAlert,
    ShieldCheck,
    Trash2,
    X,
} from "lucide-vue-next";
import { safeContractHtml } from "../shared/contractHtml.js";
import { useContractFlow } from "./composables/useContractFlow.js";
import { formFromContract } from "./composables/contractForm.js";
import ContractFormFields from "./components/ContractFormFields.vue";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { localIsoDate } from "@/shared/utils/format/localDate.js";
import { contractStatusColor } from "@/shared/utils/format/statusStyles.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import AppBackLink from "@/shared/components/nav/AppBackLink.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import AppDatePicker from "@/shared/components/form/picker/AppDatePicker.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppMessage from "@/shared/components/feedback/AppMessage.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppSignaturePad from "@/shared/components/form/input/AppSignaturePad.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";

const props = defineProps({
    contract: { type: Object, required: true },
    indexPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
    previewPath: { type: String, required: true },
    freezePath: { type: String, required: true },
    sendPath: { type: String, required: true },
    remindPath: { type: String, required: true },
    revokeLinkPath: { type: String, required: true },
    cancelPath: { type: String, required: true },
    duplicatePath: { type: String, required: true },
    countersignPath: { type: String, required: true },
    pdfPath: { type: String, required: true },
    exportPath: { type: String, required: true },
    terminatePath: { type: String, required: true },
    terminationOrigins: { type: Array, default: () => [] },
    amendPath: { type: String, required: true },
    showPath: { type: String, required: true },
    templateVersionPath: { type: String, required: true },
    customers: { type: Array, default: () => [] },
    bodies: { type: Array, default: () => [] },
    annexes: { type: Array, default: () => [] },
    locales: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    amendable: { type: Array, default: () => [] },
});

const { t } = useI18n();
const { request } = useRequest();
const { formatDateNumeric, formatDateTimeNumeric } = useDateFormat();
const { flowOf, summaryOf } = useContractFlow();

const P = "backend.studio.contracts";
const F = `${P}.flow`;

const contract = ref({ ...props.contract });
const isDraft = computed(() => "draft" === contract.value.status);
const seal = computed(() => contract.value.seal ?? {});
const flow = computed(() => flowOf(contract.value));
const summary = computed(() => summaryOf(contract.value, formatDateNumeric));
const busy = ref(false);

const date = (value) => (value ? formatDateNumeric(value) : "-");
const dateTime = (value) => (value ? formatDateTimeNumeric(value) : "-");

/* ------------------------------------------------------------------ */
/* The document                                                        */
/* ------------------------------------------------------------------ */

/**
 * A draft has no sealed text, so its document is asked of the server as the
 * seal would build it today, variables and all. Before, a draft opened on an
 * empty page that only said it was not sealed yet.
 */
const draftPreview = ref({ loading: false, html: "", error: "", unknownTokens: [] });

async function loadDraftPreview() {
    draftPreview.value = { loading: true, html: "", error: "", unknownTokens: [] };

    const data = await request(props.previewPath, null, { method: HttpMethod.Get, noGuard: true, silent: true });

    draftPreview.value = data?.success
        ? { loading: false, html: data.html ?? "", error: "", unknownTokens: data.unknownTokens ?? [] }
        : { loading: false, html: "", error: data?.errors?.preview ?? t(`${P}.preview_failed`), unknownTokens: [] };
}

onMounted(() => {
    if (isDraft.value) loadDraftPreview();
    openRequestedGesture();
});

/**
 * `?do=<gesture>` opens that gesture's confirmation on arrival.
 *
 * The list's menus lead here rather than repeating every confirmation: each
 * gesture is written once, on the contract's own screen. Only a gesture the
 * status allows is opened, and the parameter leaves the address once used.
 */
function openRequestedGesture() {
    const params = new URLSearchParams(window.location.search);
    const key = params.get("do");

    if (!key) return;

    params.delete("do");
    const query = params.toString();
    window.history.replaceState(window.history.state, "", `${window.location.pathname}${query ? `?${query}` : ""}`);

    const all = [flow.value.next, ...flow.value.others].filter(Boolean);
    const action = all.find((each) => each.key === key);

    if (action) bind(action).onSelect?.();
}

/** Cleaned once more on the way into the DOM, like every stored HTML. */
const documentHtml = computed(() =>
    safeContractHtml(isDraft.value ? draftPreview.value.html : contract.value.renderedHtml),
);

/* ------------------------------------------------------------------ */
/* The facts beside it                                                 */
/* ------------------------------------------------------------------ */

function versionHref(part) {
    return part ? buildPath(props.templateVersionPath, { id: part.templateId, versionId: part.versionId }) : null;
}

const amount = computed(() => {
    const cents = contract.value.amountCents;

    if (null === cents || undefined === cents) return "-";

    return new Intl.NumberFormat(undefined, {
        style: "currency",
        currency: contract.value.amountCurrency ?? "EUR",
        minimumFractionDigits: 0 === cents % 100 ? 0 : 2,
    }).format(cents / 100);
});

const localeLabel = computed(
    () => props.locales.find((locale) => locale.code === contract.value.locale)?.label ?? contract.value.locale,
);

const signatures = computed(() => contract.value.signatures ?? []);

/* ------------------------------------------------------------------ */
/* Gestures                                                            */
/* ------------------------------------------------------------------ */

/** A gesture that answers with the contract, which replaces the page's copy. */
async function act(path, doneKey, params = {}) {
    busy.value = true;

    try {
        const data = await request(path, {}, { noGuard: true });

        if (!data?.success) {
            if (data?.errors) toast.error(Object.values(data.errors)[0]);

            return null;
        }

        if (data.contract) contract.value = data.contract;
        if (doneKey) toast.success(t(doneKey, params));

        return data;
    } finally {
        busy.value = false;
    }
}

const pending = ref(null);

function confirm(key) {
    pending.value = key;
}

const cancelAlsoDuplicates = ref(true);

async function runPending() {
    const key = pending.value;
    const email = contract.value.link?.recipientEmail ?? contract.value.customerEmail ?? "";

    if ("freeze" === key) {
        const data = await act(props.freezePath, `${F}.done.frozen`);
        if (data) pending.value = null;
    } else if ("send" === key || "resend" === key) {
        const data = await act(props.sendPath, `${F}.done.sent`, { email });
        if (data) pending.value = null;
    } else if ("remind" === key) {
        const data = await act(props.remindPath, `${F}.done.reminded`, { email });
        if (data) pending.value = null;
    } else if ("revoke" === key) {
        const data = await act(props.revokeLinkPath, `${F}.done.revoked`);
        if (data) pending.value = null;
    } else if ("cancel" === key) {
        const data = await act(props.cancelPath, `${F}.done.cancelled`);
        if (data) {
            pending.value = null;
            if (cancelAlsoDuplicates.value) await duplicate();
        }
    } else if ("delete" === key) {
        const data = await act(props.deletePath, `${F}.done.deleted`);
        if (data) window.location.assign(props.indexPath);
    }
}

async function duplicate() {
    const data = await act(props.duplicatePath, `${F}.done.duplicated`);

    if (data?.showPath) window.location.assign(data.showPath);
}

/* Edit, on the page where the draft is read. */
const showEdit = ref(false);
const editForm = ref(formFromContract(contract.value));
const editErrors = ref({});
const saving = ref(false);

function openEdit() {
    editForm.value = formFromContract(contract.value);
    editErrors.value = {};
    showEdit.value = true;
}

async function saveEdit() {
    saving.value = true;
    editErrors.value = {};

    try {
        const data = await request(props.updatePath, editForm.value, { noGuard: true });

        if (!data?.success) {
            if (data?.errors) editErrors.value = data.errors;

            return;
        }

        if (data.contract) contract.value = data.contract;
        showEdit.value = false;
        toast.success(t(`${P}.updated`));
        loadDraftPreview();
    } finally {
        saving.value = false;
    }
}

/* Countersign. */
const showCountersign = ref(false);
const countersigning = ref(false);
const countersignErrors = ref({});
const countersignForm = ref({
    firstName: "",
    lastName: "",
    email: "",
    place: "",
    date: localIsoDate(),
    signatureImage: "",
    consent: true,
    // The provider is authenticated: identity comes from the session, not
    // from a mailed code. Sent because the payload is shared with the public
    // form, where it is the credential.
    code: "n/a",
});

const canSubmitCountersign = computed(
    () =>
        "" !== countersignForm.value.firstName.trim() &&
        "" !== countersignForm.value.lastName.trim() &&
        "" !== countersignForm.value.email.trim() &&
        "" !== countersignForm.value.place.trim() &&
        "" !== countersignForm.value.signatureImage &&
        !countersigning.value,
);

async function countersign() {
    if (!canSubmitCountersign.value) return;

    countersigning.value = true;
    countersignErrors.value = {};

    try {
        const data = await request(props.countersignPath, countersignForm.value, { noGuard: true });

        if (!data?.success) {
            if (data?.errors) countersignErrors.value = data.errors;

            return;
        }

        if (data.contract) contract.value = data.contract;
        showCountersign.value = false;
        toast.success(t(`${P}.countersigned`));
    } finally {
        countersigning.value = false;
    }
}

/* Terminate. */
const originOptions = computed(() =>
    props.terminationOrigins.map((origin) => ({ value: origin.value, label: t(origin.labelKey) })),
);

const showTerminate = ref(false);
const terminating = ref(false);
const terminationErrors = ref({});
const termination = ref({
    // Today for the notice, because that is when somebody records it; nothing
    // for the effective date, which is a decision a default would make for them.
    noticedAt: localIsoDate(),
    effectiveAt: "",
    origin: "",
    reason: "",
});

const canSubmitTerminate = computed(
    () => "" !== termination.value.noticedAt && "" !== termination.value.effectiveAt && "" !== termination.value.origin && !terminating.value,
);

async function terminate() {
    if (!canSubmitTerminate.value) return;

    terminating.value = true;
    terminationErrors.value = {};

    try {
        const data = await request(props.terminatePath, termination.value, { noGuard: true });

        if (!data?.success) {
            if (data?.errors) terminationErrors.value = data.errors;

            return;
        }

        if (data.contract) contract.value = data.contract;
        showTerminate.value = false;
        toast.success(t(`${P}.terminated`));
    } finally {
        terminating.value = false;
    }
}

/**
 * What each key does on this page. Navigations get an address, so they open
 * in a new tab like any link; the rest a modal or a request.
 */
function bind(action) {
    const href = {
        download: props.pdfPath,
        export: props.exportPath,
        amend: props.amendPath,
    }[action.key];

    if (href) return { ...action, href };

    const handlers = {
        edit: openEdit,
        countersign: () => (showCountersign.value = true),
        terminate: () => (showTerminate.value = true),
        duplicate,
    };

    return { ...action, onSelect: handlers[action.key] ?? (() => confirm(action.key)) };
}

const nextAction = computed(() => (flow.value.next ? bind(flow.value.next) : null));
const otherActions = computed(() => flow.value.others.map(bind));

function runNext() {
    const action = nextAction.value;

    if (!action) return;
    if (action.href) window.location.assign(action.href);
    else action.onSelect();
}

const confirmText = computed(() => {
    const email = contract.value.link?.recipientEmail ?? contract.value.customerEmail ?? "";

    switch (pending.value) {
        case "freeze":
            return t(`${P}.freeze_confirm`, { name: contract.value.customerName });
        case "send":
        case "resend":
            return email ? t(`${F}.confirm.send`, { email }) : t(`${F}.confirm.send_no_address`);
        case "remind":
            return t(`${F}.confirm.remind`, { email });
        default:
            return pending.value ? t(`${F}.confirm.${pending.value}`) : "";
    }
});

const confirmBlocked = computed(
    () => ["send", "resend"].includes(pending.value) && !(contract.value.link?.recipientEmail ?? contract.value.customerEmail),
);
</script>

<template>
    <div class="space-y-4">
        <AppBackLink :href="indexPath" :label="t('shared.common.back')" />

        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 space-y-1">
                <h1 class="text-lg font-semibold text-primary">
                    {{ contract.reference ?? t(`${P}.draft_title`) }}
                </h1>
                <p class="flex flex-wrap items-center gap-2 text-sm text-muted">
                    <span>{{ contract.customerName }}</span>
                    <AppBadge :color="contractStatusColor(contract.status)">{{ t(contract.statusLabel) }}</AppBadge>
                    <span v-if="contract.amends" class="text-xs">
                        {{ t(`${P}.amends_long`, { reference: contract.amends.reference }) }}
                    </span>
                </p>
            </div>
        </header>

        <!-- The step first: where it stands, and the one thing to do next. -->
        <section class="aurora-card p-4 space-y-3" data-contract-step>
            <p class="text-xs uppercase tracking-wider text-muted">{{ t(`${F}.next_step`) }}</p>
            <p class="text-sm text-primary">{{ summary }}</p>
            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                <AppButton
                    v-if="nextAction"
                    class="w-full sm:w-auto"
                    variant="primary"
                    size="md"
                    :loading="busy"
                    v-on:click="runNext"
                >
                    <component :is="nextAction.icon" class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ nextAction.title }}
                </AppButton>
                <AppPageActions
                    v-if="otherActions.length"
                    class="w-full sm:w-auto"
                    :actions="otherActions"
                    :label="contract.reference ?? contract.customerName"
                    :busy="busy"
                    variant="ghost"
                />
            </div>
        </section>

        <AppMessage v-if="contract.isFrozen && false === seal.verified" variant="danger">
            <span class="flex items-start gap-2">
                <ShieldAlert class="w-4 h-4 shrink-0 mt-0.5" :stroke-width="2" />
                <span>
                    <strong>{{ t(`${P}.seal_broken`) }}</strong><br>
                    {{ t(`${P}.seal_broken_hint`) }}
                </span>
            </span>
        </AppMessage>

        <!-- The refusal carries a reason somebody wrote: worth its own block. -->
        <section v-if="contract.refusal" class="bg-surface border border-rose-500/40 rounded-lg p-4 space-y-2 text-sm">
            <p class="font-medium text-rose-400">{{ t(`${P}.refused_at`) }} {{ dateTime(contract.refusal.refusedAt) }}</p>
            <p class="text-xs text-muted">{{ t(`${P}.refused_from`, { ip: contract.refusal.ip ?? "-" }) }}</p>
            <p class="text-xs uppercase tracking-wider text-muted">{{ t(`${P}.refusal_reason`) }}</p>
            <p v-if="contract.refusal.reason" class="text-primary whitespace-pre-line">{{ contract.refusal.reason }}</p>
            <p v-else class="text-muted">{{ t(`${P}.refusal_no_reason`) }}</p>
        </section>

        <section v-if="contract.termination" class="bg-surface border border-amber-500/40 rounded-lg p-4 space-y-2 text-sm">
            <p class="font-medium text-amber-500">
                {{
                    contract.termination.isEffective
                        ? t(`${P}.termination.ended`, { date: date(contract.termination.effectiveAt) })
                        : t(`${P}.termination.ending`, { date: date(contract.termination.effectiveAt) })
                }}
            </p>
            <p class="text-xs text-muted">
                {{ t(`${P}.termination.noticed`, { date: date(contract.termination.noticedAt), origin: t(contract.termination.originLabel) }) }}
            </p>
            <p v-if="contract.termination.reason" class="text-primary whitespace-pre-line">{{ contract.termination.reason }}</p>
        </section>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- The document, always. -->
            <div class="lg:col-span-2 min-w-0 space-y-2">
                <template v-if="isDraft">
                    <p class="text-xs text-muted">{{ t(`${P}.preview_notice`) }}</p>
                    <AppMessage v-if="draftPreview.unknownTokens.length" variant="warning">
                        {{ t(`${P}.preview_unknown_tokens`, { tokens: draftPreview.unknownTokens.join(", ") }) }}
                    </AppMessage>
                    <AppMessage v-if="draftPreview.error" variant="danger">{{ draftPreview.error }}</AppMessage>
                    <p v-else-if="draftPreview.loading" class="text-sm text-muted">{{ t("shared.common.loading") }}</p>
                </template>
                <article v-if="documentHtml" class="aurora-card p-4 sm:p-6 prose-contract" v-html="documentHtml" />
            </div>

            <aside class="min-w-0 space-y-4">
                <section class="aurora-card p-4 space-y-2 text-sm">
                    <p class="text-xs uppercase tracking-wider text-muted">{{ t(`${F}.panels.information`) }}</p>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5">
                        <dt class="text-muted">{{ t(`${F}.panels.customer`) }}</dt>
                        <dd class="text-primary min-w-0 break-words">{{ contract.customerName }}</dd>
                        <template v-if="contract.body">
                            <dt class="text-muted">{{ t(`${F}.panels.template`) }}</dt>
                            <dd class="min-w-0">
                                <a class="text-accent-400 hover:underline" :href="versionHref(contract.body)">{{ contract.body.templateName }}</a>
                                <span class="text-muted"> · {{ t(`${F}.panels.version`, { number: contract.body.versionNumber }) }}</span>
                                <p v-if="contract.body.isOutdated && isDraft" class="text-xs text-amber-500">
                                    {{ t(`${F}.panels.version_outdated`, { number: contract.body.versionNumber, latest: contract.body.latestVersionNumber }) }}
                                </p>
                            </dd>
                        </template>
                        <template v-if="contract.annex">
                            <dt class="text-muted">{{ t(`${F}.panels.annex`) }}</dt>
                            <dd class="min-w-0">
                                <a class="text-accent-400 hover:underline" :href="versionHref(contract.annex)">{{ contract.annex.templateName }}</a>
                                <span class="text-muted"> · {{ t(`${F}.panels.version`, { number: contract.annex.versionNumber }) }}</span>
                            </dd>
                        </template>
                        <dt class="text-muted">{{ t(`${F}.panels.amount`) }}</dt>
                        <dd class="text-primary tabular-nums">{{ amount }}</dd>
                        <dt class="text-muted">{{ t(`${F}.panels.effective_date`) }}</dt>
                        <dd class="text-primary">{{ date(contract.effectiveDate) }}</dd>
                        <dt class="text-muted">{{ t(`${F}.panels.language`) }}</dt>
                        <dd class="text-primary">{{ localeLabel }}</dd>
                        <dt class="text-muted">{{ t(`${F}.panels.created_at`) }}</dt>
                        <dd class="text-primary">{{ date(contract.createdAt) }}</dd>
                        <template v-if="contract.frozenAt">
                            <dt class="text-muted">{{ t(`${F}.panels.sealed_at`) }}</dt>
                            <dd class="text-primary">{{ dateTime(contract.frozenAt) }}</dd>
                        </template>
                    </dl>
                </section>

                <section v-if="contract.isFrozen" class="aurora-card p-4 space-y-2 text-sm">
                    <p class="text-xs uppercase tracking-wider text-muted">{{ t(`${F}.panels.link`) }}</p>
                    <dl v-if="contract.link" class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5">
                        <dt class="text-muted">{{ t(`${F}.panels.recipient`) }}</dt>
                        <dd class="text-primary min-w-0 break-all">{{ contract.link.recipientEmail }}</dd>
                        <dt class="text-muted">{{ t(`${F}.panels.sent_at`) }}</dt>
                        <dd class="text-primary">{{ dateTime(contract.link.sentAt) }}</dd>
                        <dt class="text-muted">{{ t(`${F}.panels.opened_at`) }}</dt>
                        <dd class="text-primary">{{ dateTime(contract.link.firstOpenedAt) }}</dd>
                        <dt class="text-muted">{{ t(`${F}.panels.expires_at`) }}</dt>
                        <dd class="text-primary">{{ date(contract.link.expiresAt) }}</dd>
                    </dl>
                    <p v-else class="text-muted">{{ t(`${F}.panels.no_link`) }}</p>
                    <p class="text-xs text-muted">
                        {{ t(`${F}.panels.reminders`) }} :
                        {{
                            contract.reminders?.count
                                ? t(`${P}.reminders_count`, { count: contract.reminders.count, date: date(contract.reminders.lastAt) })
                                : t(`${P}.reminders_none`)
                        }}
                    </p>
                </section>

                <!-- The signatures as proof: who, where, when, and how they
                     were identified. -->
                <section v-if="contract.isFrozen" class="aurora-card p-4 space-y-3 text-sm">
                    <p class="text-xs uppercase tracking-wider text-muted">{{ t(`${F}.panels.signatures`) }}</p>
                    <p v-if="!signatures.length" class="text-muted">{{ t(`${F}.panels.no_signature`) }}</p>
                    <div v-for="signature in signatures" :key="signature.role" class="space-y-0.5">
                        <p class="font-medium text-primary">
                            {{ "customer" === signature.role ? t(`${F}.panels.role_customer`) : t(`${F}.panels.role_provider`) }}
                            · {{ signature.name }}
                        </p>
                        <p class="text-xs text-secondary">{{ t(`${F}.panels.signed_in`, { place: signature.place, date: date(signature.date) }) }}</p>
                        <p class="text-xs text-muted">{{ t(`${F}.panels.signed_at_trace`, { at: dateTime(signature.signedAt), ip: signature.ip ?? "-" }) }}</p>
                        <p v-if="signature.codeSentTo" class="text-xs text-muted">{{ t(`${F}.panels.code_sent_to`, { email: signature.codeSentTo }) }}</p>
                        <p v-if="signature.byUser" class="text-xs text-muted">{{ t(`${F}.panels.by_account`, { name: signature.byUser }) }}</p>
                        <p v-if="!signature.hashMatches" class="text-xs text-rose-400">{{ t(`${F}.panels.hash_mismatch`) }}</p>
                    </div>
                </section>

                <!-- The chain: what this amends, and what amended it. -->
                <section v-if="contract.amends || contract.amendments?.length" class="aurora-card p-4 space-y-2 text-sm">
                    <p class="text-xs uppercase tracking-wider text-muted">{{ t(`${P}.chain`) }}</p>
                    <p v-if="contract.amends">
                        <a
                            v-if="contract.amends.id"
                            class="text-accent-400 hover:underline"
                            :href="buildPath(showPath, { id: contract.amends.id })"
                        >{{ t(`${P}.amends_long`, { reference: contract.amends.reference }) }}</a>
                        <span v-else>{{ t(`${P}.amends_long`, { reference: contract.amends.reference }) }}</span>
                    </p>
                    <ul v-if="contract.amendments?.length" class="space-y-1">
                        <li v-for="amendment in contract.amendments" :key="amendment.id" class="flex flex-wrap items-baseline gap-2">
                            <a class="font-mono text-xs text-accent-400 hover:underline" :href="buildPath(showPath, { id: amendment.id })">
                                {{ amendment.reference ?? t(`${P}.draft_title`) }}
                            </a>
                            <AppBadge :color="contractStatusColor(amendment.status)">{{ t(amendment.statusLabel) }}</AppBadge>
                        </li>
                    </ul>
                </section>

                <section v-if="contract.isFrozen" class="aurora-card p-4 space-y-2 text-sm">
                    <p class="flex items-center gap-2" :class="seal.verified ? 'text-emerald-500' : 'text-rose-400'">
                        <ShieldCheck v-if="seal.verified" class="w-4 h-4" :stroke-width="2" />
                        <ShieldAlert v-else class="w-4 h-4" :stroke-width="2" />
                        {{ seal.verified ? t(`${P}.seal_intact`) : t(`${P}.seal_broken`) }}
                    </p>
                    <p class="text-xs text-muted">{{ t(`${P}.seal_hash`) }}</p>
                    <p class="font-mono text-xs text-primary break-all">{{ seal.contentHash }}</p>
                    <p class="text-xs text-muted">{{ seal.hashAlgo }} · c{{ seal.canonicalVersion }}</p>
                    <p class="text-xs text-muted">{{ t(`${P}.retained_until`) }} {{ date(contract.retainedUntil) }}</p>
                </section>

                <section class="aurora-card p-4 space-y-2 text-sm">
                    <p class="text-xs uppercase tracking-wider text-muted">{{ t(`${F}.panels.history`) }}</p>
                    <p v-if="!contract.history?.length" class="text-muted">{{ t(`${F}.panels.no_history`) }}</p>
                    <ul v-else class="space-y-1.5">
                        <li v-for="(entry, index) in contract.history" :key="index" class="text-xs">
                            <span class="text-primary">{{ entry.label }}</span>
                            <span class="text-muted"> · {{ dateTime(entry.at) }}<template v-if="entry.userName"> · {{ entry.userName }}</template></span>
                        </li>
                    </ul>
                </section>
            </aside>
        </div>

        <!-- Confirmations: one modal, the sentence says what will happen. -->
        <AppModal :show="null !== pending" max-width="md" :title="pending ? t(`${F}.actions.${pending}.title`) : ''" v-on:close="pending = null">
            <div class="space-y-3">
                <p class="text-sm text-secondary">{{ confirmText }}</p>
                <AppCheckbox
                    v-if="'cancel' === pending"
                    v-model="cancelAlsoDuplicates"
                    :label="t(`${F}.confirm.cancel_duplicate`)"
                />
            </div>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pending = null">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        :variant="['cancel', 'delete'].includes(pending) ? 'danger' : 'primary'"
                        size="md"
                        :loading="busy"
                        :disabled="confirmBlocked"
                        v-on:click="runPending"
                    >
                        <Trash2 v-if="'delete' === pending" class="w-3.5 h-3.5" :stroke-width="2" />
                        <Lock v-else-if="'freeze' === pending" class="w-3.5 h-3.5" :stroke-width="2" />
                        <Check v-else class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ pending ? t(`${F}.actions.${pending}.title`) : "" }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="showEdit"
            max-width="lg"
            :closeable="false"
            :title="t(`${P}.edit`, { name: contract.customerName })"
            v-on:close="showEdit = false"
        >
            <form v-on:submit.prevent="saveEdit">
                <ContractFormFields
                    v-model="editForm"
                    :errors="editErrors"
                    :amendable="amendable"
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
                    <AppButton variant="primary" size="md" :loading="saving" v-on:click="saveEdit">
                        <Check class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.save") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="showCountersign"
            max-width="lg"
            :closeable="false"
            :title="t(`${P}.countersign`)"
            :icon="PenLine"
            v-on:close="showCountersign = false"
        >
            <div class="space-y-4">
                <AppMessage v-if="countersignErrors.status" variant="danger">{{ countersignErrors.status }}</AppMessage>
                <p class="text-sm text-secondary">{{ t(`${P}.countersign_intro`) }}</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput
                        v-model="countersignForm.firstName"
                        :label="t('studio.public.sign.first_name')"
                        :placeholder="t('studio.public.sign.first_name_placeholder')"
                        :error="countersignErrors.firstName"
                        required
                    />
                    <AppInput
                        v-model="countersignForm.lastName"
                        :label="t('studio.public.sign.last_name')"
                        :placeholder="t('studio.public.sign.last_name_placeholder')"
                        :error="countersignErrors.lastName"
                        required
                    />
                </div>
                <AppInput
                    v-model="countersignForm.email"
                    :label="t('studio.public.sign.email')"
                    :placeholder="t('studio.public.sign.email_placeholder')"
                    :error="countersignErrors.email"
                    type="email"
                    required
                />
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput
                        v-model="countersignForm.place"
                        :label="t('studio.public.sign.place')"
                        :placeholder="t('studio.public.sign.place_placeholder')"
                        :error="countersignErrors.place"
                        required
                    />
                    <AppDatePicker
                        v-model="countersignForm.date"
                        :label="t('studio.public.sign.date')"
                        :placeholder="t('studio.public.sign.date_placeholder')"
                        :error="countersignErrors.date"
                        required
                    />
                </div>
                <AppSignaturePad v-model="countersignForm.signatureImage" :label="t('studio.public.sign.signature')" />
                <p v-if="countersignErrors.signatureImage" class="text-xs text-red-500">{{ countersignErrors.signatureImage }}</p>
            </div>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showCountersign = false">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" :disabled="!canSubmitCountersign" :loading="countersigning" v-on:click="countersign">
                        <Check class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t(`${P}.countersign`) }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal :show="showTerminate" max-width="lg" :title="t(`${P}.terminate`)" :icon="CalendarX" v-on:close="showTerminate = false">
            <div class="space-y-4">
                <AppMessage v-if="terminationErrors.status" variant="danger">{{ terminationErrors.status }}</AppMessage>
                <p class="text-sm text-secondary">{{ t(`${P}.termination.intro`) }}</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppDatePicker
                        v-model="termination.noticedAt"
                        :label="t(`${P}.termination.noticed_at`)"
                        :hint="t(`${P}.termination.noticed_at_hint`)"
                        :error="terminationErrors.noticedAt"
                        required
                    />
                    <AppDatePicker
                        v-model="termination.effectiveAt"
                        :label="t(`${P}.termination.effective_at`)"
                        :hint="t(`${P}.termination.effective_at_hint`)"
                        :error="terminationErrors.effectiveAt"
                        required
                    />
                </div>
                <AppSelect
                    v-model="termination.origin"
                    :label="t(`${P}.termination.origin_label`)"
                    :placeholder="t(`${P}.termination.origin_placeholder`)"
                    :options="originOptions"
                    :error="terminationErrors.origin"
                    required
                />
                <AppTextarea
                    v-model="termination.reason"
                    :label="t(`${P}.termination.reason`)"
                    :placeholder="t(`${P}.termination.reason_placeholder`)"
                    :error="terminationErrors.reason"
                    :rows="4"
                />
            </div>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showTerminate = false">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" :disabled="!canSubmitTerminate" :loading="terminating" v-on:click="terminate">
                        <CalendarX class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t(`${P}.terminate`) }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>

<style scoped>
/**
 * The document's own typography, scoped to it.
 *
 * The stored HTML is semantic and carries no classes on purpose - it has to
 * survive a PDF engine and a decade of storage - so the styling lives here
 * rather than in the markup that was hashed.
 */
.prose-contract :deep(h1) {
    font-size: 1.125rem;
    font-weight: 600;
    text-align: center;
    margin: 0 0 1.5rem;
}

.prose-contract :deep(h2) {
    font-size: 0.95rem;
    font-weight: 600;
    margin: 1.75rem 0 0.5rem;
}

.prose-contract :deep(h3),
.prose-contract :deep(h4) {
    font-size: 0.9rem;
    font-weight: 600;
    margin: 1.25rem 0 0.35rem;
}

.prose-contract :deep(p) {
    margin: 0 0 0.75rem;
    line-height: 1.65;
    text-align: justify;
}

.prose-contract :deep(ul),
.prose-contract :deep(ol) {
    margin: 0 0 0.75rem 1.25rem;
    line-height: 1.65;
}

.prose-contract :deep(blockquote) {
    margin: 0 0 0.75rem;
    padding-left: 0.75rem;
    border-left: 2px solid currentColor;
    opacity: 0.85;
}

.prose-contract :deep(table) {
    width: 100%;
    border-collapse: collapse;
    margin: 0 0 1rem;
    font-size: 0.85rem;
}

.prose-contract :deep(th),
.prose-contract :deep(td) {
    border: 1px solid currentColor;
    padding: 0.35rem 0.5rem;
    text-align: left;
}

.prose-contract :deep(th) {
    font-weight: 600;
}

.prose-contract :deep(hr) {
    margin: 1.5rem 0;
    border: 0;
    border-top: 1px solid currentColor;
    opacity: 0.25;
}

.prose-contract :deep(section + section) {
    margin-top: 3rem;
    padding-top: 2rem;
    border-top: 1px solid currentColor;
}
</style>
