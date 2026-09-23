<script setup>
/**
 * One contract: the document that was sealed, and the seal itself.
 *
 * This is where somebody comes after suspecting something, so the seal block
 * shows the whole answer rather than a green tick: the hash, the algorithm,
 * the canonical form, when it was sealed, and whether it still matches - that
 * last one recomputed on this request, not read back from a column.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { safeContractHtml } from "../shared/contractHtml.js";
import { toast } from "vue-sonner";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import AppSignaturePad from "@/shared/components/form/input/AppSignaturePad.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppDatePicker from "@/shared/components/form/picker/AppDatePicker.vue";
import AppMessage from "@/shared/components/feedback/AppMessage.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";

const { formatDateNumeric, formatDateTimeNumeric } = useDateFormat();
import {
    ArrowLeft,
    CalendarX,
    Check,
    FileDown,
    FilePlus2,
    Lock,
    PenLine,
    ShieldAlert,
    ShieldCheck,
    X,
} from "lucide-vue-next";

const { t } = useI18n();

const props = defineProps({
    contract: { type: Object, required: true },
    indexPath: { type: String, required: true },
    freezePath: { type: String, required: true },
    countersignPath: { type: String, required: true },
    pdfPath: { type: String, required: true },
    terminatePath: { type: String, required: true },
    amendPath: { type: String, required: true },
    terminationOrigins: { type: Array, default: () => [] },
});

const { can } = usePrivileges();
const { request } = useRequest();

const contract = ref({ ...props.contract });
const seal = computed(() => contract.value.seal ?? {});
const isFrozen = computed(() => contract.value.isFrozen === true);

/**
 * Only after the customer has signed, and only once.
 *
 * The countersignature concludes, so there has to be something to conclude -
 * the server enforces that too, and this is the affordance that matches it.
 */
const canCountersign = computed(
    () =>
        contract.value.status === "signed_by_customer" &&
        can("studio.contracts.countersign"),
);

const showCountersign = ref(false);
const countersigning = ref(false);
const countersignErrors = ref({});
const countersignForm = ref({
    firstName: "",
    lastName: "",
    email: "",
    place: "",
    date: new Date().toISOString().slice(0, 10),
    signatureImage: "",
    consent: true,
    // Unused by this path: the provider is authenticated, so identity comes
    // from the session rather than from a mailed code. Sent because the DTO is
    // shared with the public form, where it is the credential.
    code: "n/a",
});

const canSubmitCountersign = computed(
    () =>
        countersignForm.value.firstName.trim() !== "" &&
        countersignForm.value.lastName.trim() !== "" &&
        countersignForm.value.email.trim() !== "" &&
        countersignForm.value.place.trim() !== "" &&
        countersignForm.value.signatureImage !== "" &&
        !countersigning.value,
);

async function countersign() {
    if (!canSubmitCountersign.value) return;

    countersigning.value = true;
    countersignErrors.value = {};

    try {
        const data = await request(
            props.countersignPath,
            countersignForm.value,
            { noGuard: true },
        );

        if (data?.errors) {
            countersignErrors.value = data.errors;

            return;
        }

        if (data?.contract) contract.value = data.contract;
        showCountersign.value = false;
        toast.success(t("backend.studio.contracts.countersigned"));
    } finally {
        countersigning.value = false;
    }
}

const sealedAt = computed(() => {
    if (!seal.value.frozenAt) return null;

    return formatDateTimeNumeric(seal.value.frozenAt);
});

/** Dates the server computed, formatted where the reader is. */
const retainedUntil = computed(() =>
    contract.value.retainedUntil
        ? formatDateNumeric(contract.value.retainedUntil)
        : null,
);

const lastReminderAt = computed(() =>
    contract.value.reminders?.lastAt
        ? formatDateNumeric(contract.value.reminders.lastAt)
        : "-",
);

const isConcluded = computed(() => "countersigned" === contract.value.status);

const originOptions = computed(() =>
    props.terminationOrigins.map((origin) => ({
        value: origin.value,
        label: t(origin.labelKey),
    })),
);

const showTerminate = ref(false);
const terminating = ref(false);
const terminationErrors = ref({});
const termination = ref({
    // Today for the notice, because that is when somebody records it, and
    // nothing for the effective date: the notice period is a decision, and a
    // default would quietly make it whatever this form guessed.
    noticedAt: new Date().toISOString().slice(0, 10),
    effectiveAt: "",
    origin: "",
    reason: "",
});

async function terminate() {
    if (terminating.value) return;

    terminating.value = true;
    terminationErrors.value = {};

    try {
        const data = await request(props.terminatePath, termination.value, {
            noGuard: true,
        });

        if (data?.errors) {
            terminationErrors.value = data.errors;

            return;
        }

        if (data?.contract) {
            contract.value = data.contract;
            showTerminate.value = false;
            toast.success(t("backend.studio.contracts.terminated"));
        }
    } finally {
        terminating.value = false;
    }
}

/**
 * Les trois champs que le serveur exige, vérifiés avant de laisser cliquer.
 *
 * Le motif est facultatif : une résiliation se constate, elle ne se justifie
 * pas forcément.
 */
const canSubmitTerminate = computed(
    () =>
        termination.value.noticedAt !== "" &&
        termination.value.effectiveAt !== "" &&
        termination.value.origin !== "" &&
        !terminating.value,
);

const terminationDates = computed(() => {
    const record = contract.value.termination;

    if (!record) return null;

    return {
        noticedAt: formatDateNumeric(record.noticedAt),
        effectiveAt: formatDateNumeric(record.effectiveAt),
    };
});

const refusedAt = computed(() =>
    contract.value.refusal?.refusedAt
        ? formatDateTimeNumeric(contract.value.refusal.refusedAt)
        : "-",
);

/**
 * The stored document, cleaned again before it is put in the DOM.
 *
 * The renderer that produced it already sanitises every text and escapes every
 * substituted value, so this is belt and braces - but it is HTML read out of a
 * column and handed to `v-html`, and the seal that would report tampering is
 * checked by the reader after the markup has already run. The tag list is the
 * one the contract renderer can emit, so cleaning here can only ever remove
 * something that had no business being there.
 */
const documentHtml = computed(() =>
    safeContractHtml(props.contract.renderedHtml),
);

/**
 * The three things that can be done to a sealed contract, in one list.
 *
 * Amending and terminating used to sit in a row of their own halfway down the
 * page, under the seal and the amendment history. That put the two verbs that
 * change a contract's life below a wall of hashes, where nobody looking for
 * them would think to scroll. They belong with the PDF, at the top, and the
 * conditions that governed them are unchanged: only a concluded contract, only
 * one that is not itself an amendment, and only until it has been terminated.
 *
 * Countersigning stays out: when it is offered it is the only thing the reader
 * came to do.
 */
const contractActions = computed(() => {
    const actions = [];

    // Only once there is a file. The contract says whether one exists, so the
    // entry never leads to a 404.
    if (contract.value.hasPdf) {
        actions.push({
            key: "pdf",
            icon: FileDown,
            title: t("backend.studio.contracts.download_pdf"),
            href: props.pdfPath,
        });
    }

    const liveAndConcluded =
        isConcluded.value && !contract.value.amends && !contract.value.termination;

    if (liveAndConcluded && can("studio.contracts.create")) {
        actions.push({
            key: "amend",
            color: "accent",
            icon: FilePlus2,
            title: t("backend.studio.contracts.amend"),
            href: props.amendPath,
        });
    }

    // Last: it is the one that ends something.
    if (liveAndConcluded && can("studio.contracts.edit")) {
        actions.push({
            key: "terminate",
            color: "amber",
            icon: CalendarX,
            title: t("backend.studio.contracts.terminate"),
            onSelect: () => (showTerminate.value = true),
        });
    }

    return actions;
});
</script>

<template>
    <div class="space-y-2 sm:space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="space-y-1">
                <h1 class="text-lg font-semibold text-primary">
                    {{ contract.reference ?? t("backend.studio.contracts.draft_title") }}
                </h1>
                <p class="text-sm text-muted">
                    {{ contract.customerName }} · {{ t(contract.statusLabel) }}
                </p>
            </div>
            <!-- Les trois gestes prennent la ligne sous `sm` : « Contresigner »
                 est le geste de la page, et il se retrouvait à cent quarante
                 pixels entre un retour et une feuille d'actions. -->
            <div class="flex w-full flex-col items-stretch gap-2 sm:w-auto sm:flex-row sm:flex-wrap sm:items-center">
                <AppButton class="w-full sm:w-auto" variant="ghost" size="md" :href="indexPath">
                    <ArrowLeft class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t("shared.common.back") }}
                </AppButton>
                <AppPageActions
                    v-if="contractActions.length"
                    class="w-full sm:w-auto"
                    :actions="contractActions"
                    :label="contract.reference ?? ''"
                    variant="ghost"
                />
                <AppButton
                    v-if="canCountersign"
                    class="w-full sm:w-auto"
                    variant="primary"
                    size="md"
                    v-on:click="showCountersign = true"
                >
                    <PenLine class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t("backend.studio.contracts.countersign") }}
                </AppButton>
            </div>
        </div>

        <!-- A draft has no document yet, and saying so is more useful than an
             empty frame. -->
        <AppMessage v-if="!isFrozen" variant="info">
            <span class="flex items-center gap-2">
                <Lock class="w-4 h-4 shrink-0" :stroke-width="2" />
                {{ t("backend.studio.contracts.not_sealed_yet") }}
            </span>
        </AppMessage>

        <template v-else>
            <div
                class="rounded-lg border p-4 space-y-3"
                :class="
                    seal.verified
                        ? 'border-emerald-500/40 bg-emerald-500/5'
                        : 'border-red-500/50 bg-red-500/5'
                "
            >
                <div class="flex items-start gap-2">
                    <ShieldCheck
                        v-if="seal.verified"
                        class="w-5 h-5 shrink-0 text-emerald-500 mt-0.5"
                        :stroke-width="2"
                    />
                    <ShieldAlert
                        v-else
                        class="w-5 h-5 shrink-0 text-red-500 mt-0.5"
                        :stroke-width="2"
                    />
                    <div class="space-y-1 min-w-0">
                        <p
                            class="font-medium"
                            :class="seal.verified ? 'text-emerald-500' : 'text-red-500'"
                        >
                            {{
                                seal.verified
                                    ? t("backend.studio.contracts.seal_intact")
                                    : t("backend.studio.contracts.seal_broken")
                            }}
                        </p>
                        <p class="text-xs text-secondary">
                            {{
                                seal.verified
                                    ? t("backend.studio.contracts.seal_intact_hint")
                                    : t("backend.studio.contracts.seal_broken_hint")
                            }}
                        </p>
                    </div>
                </div>

                <dl class="grid gap-2 sm:grid-cols-2 text-xs">
                    <div class="space-y-0.5 sm:col-span-2">
                        <dt class="text-muted uppercase tracking-wider">
                            {{ t("backend.studio.contracts.seal_hash") }}
                        </dt>
                        <dd class="font-mono text-primary break-all">
                            {{ seal.contentHash }}
                        </dd>
                    </div>
                    <div class="space-y-0.5">
                        <dt class="text-muted uppercase tracking-wider">
                            {{ t("backend.studio.contracts.seal_algo") }}
                        </dt>
                        <dd class="font-mono text-primary">
                            {{ seal.hashAlgo }} · c{{ seal.canonicalVersion }}
                        </dd>
                    </div>
                    <div class="space-y-0.5">
                        <dt class="text-muted uppercase tracking-wider">
                            {{ t("backend.studio.contracts.seal_sealed_at") }}
                        </dt>
                        <dd class="text-primary">{{ sealedAt ?? "-" }}</dd>
                    </div>
                    <!-- How long this evidence has to be kept. Beside the seal
                         rather than in a settings screen: the question is
                         asked about this document, and the answer is the date
                         the delete button starts working. -->
                    <div class="space-y-0.5">
                        <dt class="text-muted uppercase tracking-wider">
                            {{ t("backend.studio.contracts.retained_until") }}
                        </dt>
                        <dd class="text-primary">{{ retainedUntil ?? "-" }}</dd>
                        <dd class="text-muted">
                            {{ t("backend.studio.contracts.retention_hint") }}
                        </dd>
                    </div>
                    <div class="space-y-0.5">
                        <dt class="text-muted uppercase tracking-wider">
                            {{ t("backend.studio.contracts.reminders") }}
                        </dt>
                        <dd class="text-primary">
                            {{
                                contract.reminders?.count
                                    ? t("backend.studio.contracts.reminders_count", {
                                        count: contract.reminders.count,
                                        date: lastReminderAt,
                                    })
                                    : t("backend.studio.contracts.reminders_none")
                            }}
                        </dd>
                    </div>
                </dl>
            </div>

            <!-- The other answer, when it is the one that came back. Its own
                 block rather than a status word: a refusal carries a reason
                 somebody wrote, and that is the part worth reading. -->
            <div
                v-if="contract.refusal"
                class="bg-surface border border-amber-500/40 rounded-lg p-4 space-y-2 text-sm"
            >
                <p class="font-medium text-amber-500">
                    {{ t("backend.studio.contracts.refused_at") }}
                    {{ refusedAt }}
                </p>
                <p class="text-xs text-muted">
                    {{
                        t("backend.studio.contracts.refused_from", {
                            ip: contract.refusal.ip ?? "-",
                        })
                    }}
                </p>
                <div class="space-y-1">
                    <p class="text-xs uppercase tracking-wider text-muted">
                        {{ t("backend.studio.contracts.refusal_reason") }}
                    </p>
                    <p v-if="contract.refusal.reason" class="text-primary whitespace-pre-line">
                        {{ contract.refusal.reason }}
                    </p>
                    <p v-else class="text-muted">
                        {{ t("backend.studio.contracts.refusal_no_reason") }}
                    </p>
                </div>
            </div>

            <!-- What this document changes, and what changed it. A contract
                 and its amendments are read as a history: the parent first,
                 then each amendment in the order it was signed, because the
                 last one is what is in force. -->
            <div
                v-if="contract.amends || contract.amendments?.length"
                class="aurora-card p-4 space-y-2 text-sm"
            >
                <p class="text-xs uppercase tracking-wider text-muted">
                    {{ t("backend.studio.contracts.chain") }}
                </p>

                <p v-if="contract.amends" class="text-primary">
                    {{
                        t("backend.studio.contracts.amends_long", {
                            reference: contract.amends.reference,
                            rank: contract.amends.rank ?? "-",
                        })
                    }}
                </p>

                <ul v-if="contract.amendments?.length" class="space-y-1">
                    <li
                        v-for="amendment in contract.amendments"
                        :key="amendment.id"
                        class="flex flex-wrap items-baseline gap-2"
                    >
                        <span class="font-mono text-xs text-primary">
                            {{ amendment.reference ?? t("backend.studio.contracts.draft_title") }}
                        </span>
                        <span class="text-xs text-muted">{{ t(amendment.statusLabel) }}</span>
                    </li>
                </ul>

                <p v-else-if="contract.amends" class="text-xs text-muted">
                    {{ t("backend.studio.contracts.chain_leaf") }}
                </p>
            </div>

            <!-- The end of the relationship, which is not the end of the
                 document: the seal above stays intact and stays true. Two
                 dates, because a notice period is the gap between them. -->
            <div
                v-if="contract.termination"
                class="bg-surface border border-amber-500/40 rounded-lg p-4 space-y-2 text-sm"
            >
                <p class="font-medium text-amber-500">
                    {{
                        contract.termination.isEffective
                            ? t("backend.studio.contracts.termination.ended", { date: terminationDates.effectiveAt })
                            : t("backend.studio.contracts.termination.ending", { date: terminationDates.effectiveAt })
                    }}
                </p>
                <p class="text-xs text-muted">
                    {{
                        t("backend.studio.contracts.termination.noticed", {
                            date: terminationDates.noticedAt,
                            origin: t(contract.termination.originLabel),
                        })
                    }}
                </p>
                <p v-if="contract.termination.reason" class="text-primary whitespace-pre-line">
                    {{ contract.termination.reason }}
                </p>
            </div>

            <!-- The document as it was rendered and hashed. Printed from the
                 stored HTML, never re-rendered: re-rendering would show what
                 today's code produces rather than what was signed. Cleaned
                 once more on the way into the DOM, see documentHtml. -->
            <article
                class="aurora-card p-4 sm:p-6 prose-contract"
                v-html="documentHtml"
            />

            <p class="text-xs text-muted">
                {{ t("backend.studio.contracts.deferred_tokens_hint") }}
            </p>
        </template>

        <AppModal
            :show="showCountersign"
            max-width="lg"
            :closeable="false"
            :title="t('backend.studio.contracts.countersign')"
            :icon="PenLine"
            v-on:close="showCountersign = false"
        >
            <div class="space-y-4">
                <AppMessage v-if="countersignErrors.status" variant="danger">
                    {{ countersignErrors.status }}
                </AppMessage>

                <!-- The consequence, before the click: this is what concludes
                     the contract. -->
                <p class="text-sm text-secondary">
                    {{ t("backend.studio.contracts.countersign_intro") }}
                </p>

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

                <!-- The same pad the customer used, so the two signatures are
                     the same kind of thing. -->
                <AppSignaturePad
                    v-model="countersignForm.signatureImage"
                    :label="t('studio.public.sign.signature')"
                />
                <p v-if="countersignErrors.signatureImage" class="text-xs text-red-500">
                    {{ countersignErrors.signatureImage }}
                </p>
            </div>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showCountersign = false">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="primary"
                        size="md"
                        :disabled="!canSubmitCountersign"
                        :loading="countersigning"
                        v-on:click="countersign"
                    >
                        <Check class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("backend.studio.contracts.countersign") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Résilier.
             Le bouton existait, son état aussi, la fonction d'envoi aussi, et
             les traductions aussi : seule cette fenêtre manquait, si bien que
             le clic ne faisait rien du tout. -->
        <AppModal
            :show="showTerminate"
            max-width="lg"
            :title="t('backend.studio.contracts.terminate')"
            :icon="CalendarX"
            v-on:close="showTerminate = false"
        >
            <div class="space-y-4">
                <AppMessage v-if="terminationErrors.status" variant="danger">
                    {{ terminationErrors.status }}
                </AppMessage>

                <p class="text-sm text-secondary">
                    {{ t("backend.studio.contracts.termination.intro") }}
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <AppDatePicker
                        v-model="termination.noticedAt"
                        :label="t('backend.studio.contracts.termination.noticed_at')"
                        :hint="t('backend.studio.contracts.termination.noticed_at_hint')"
                        :error="terminationErrors.noticedAt"
                        required
                    />
                    <AppDatePicker
                        v-model="termination.effectiveAt"
                        :label="t('backend.studio.contracts.termination.effective_at')"
                        :hint="t('backend.studio.contracts.termination.effective_at_hint')"
                        :error="terminationErrors.effectiveAt"
                        required
                    />
                </div>

                <AppSelect
                    v-model="termination.origin"
                    :label="t('backend.studio.contracts.termination.origin_label')"
                    :placeholder="t('backend.studio.contracts.termination.origin_placeholder')"
                    :options="originOptions"
                    :error="terminationErrors.origin"
                    required
                />

                <AppTextarea
                    v-model="termination.reason"
                    :label="t('backend.studio.contracts.termination.reason')"
                    :placeholder="t('backend.studio.contracts.termination.reason_placeholder')"
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
                    <AppButton
                        variant="primary"
                        size="md"
                        :disabled="!canSubmitTerminate"
                        :loading="terminating"
                        v-on:click="terminate"
                    >
                        <CalendarX class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("backend.studio.contracts.terminate") }}
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
