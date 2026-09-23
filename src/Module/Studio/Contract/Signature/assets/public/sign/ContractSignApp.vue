<script setup>
/**
 * The form a customer signs with.
 *
 * Two things about it are deliberate and worth reading before changing.
 *
 * **The button waits for the document to have been read.** Not as a security
 * control - a server cannot verify scrolling, and this one does not pretend to -
 * but because "I have read and accept" under a document nobody scrolled is a
 * box people tick without meaning it. The gate makes the claim slightly truer
 * and costs nothing.
 *
 * **The code is asked for on the page, not before.** Sending it when the mail
 * with the link goes out would mean it expires long before somebody sits down
 * to read nineteen articles. It is requested when the signer says they are
 * ready, which is also the moment it starts proving something.
 *
 * **Declining is offered, quietly.** A page whose only button is "sign" makes
 * not signing feel like an error state, and leaves the provider unable to tell
 * a refusal from silence. It asks for no code and no consent box: a refusal
 * binds nobody and the provider can send the contract again, so the ceremony
 * that protects a signature would only stand between somebody and the word no.
 * The reason is optional, because requiring a justification to decline is a
 * small piece of coercion in a document about consent.
 */
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import AppSignaturePad from "@/shared/components/form/input/AppSignaturePad.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppMessage from "@/shared/components/feedback/AppMessage.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import { Ban, Check, Mail, PenLine, X } from "lucide-vue-next";

const props = defineProps({
    codePath: { type: String, required: true },
    signPath: { type: String, required: true },
    refusePath: { type: String, required: true },
    documentSelector: { type: String, default: ".contract-document" },
});

const { t } = useI18n();
const { request } = useRequest();

const form = ref({
    firstName: "",
    lastName: "",
    email: "",
    place: "",
    date: new Date().toISOString().slice(0, 10),
    signatureImage: "",
    consent: false,
    code: "",
});

const errors = ref({});
const hasDrawn = ref(false);
const hasRead = ref(false);
const codeSentTo = ref(null);
const requestingCode = ref(false);
const signing = ref(false);
const signed = ref(false);

/**
 * Whether the document has been scrolled past.
 *
 * Watched on the article the page already renders rather than on a copy inside
 * this component: the document belongs to the page, and duplicating it here
 * would mean rendering the sealed HTML twice.
 */
let observer = null;

onMounted(() => {
    const article = document.querySelector(props.documentSelector);

    if (!article) {
        // No document to watch means no gate to hold. Better than locking the
        // button on a page whose markup moved.
        hasRead.value = true;

        return;
    }

    // A sentinel after the last article, rather than a scroll listener: it
    // works the same whether the page scrolls, the window does, or the reader
    // jumps with a keyboard.
    const sentinel = document.createElement("div");
    sentinel.style.height = "1px";
    article.after(sentinel);

    if (typeof IntersectionObserver === "undefined") {
        hasRead.value = true;

        return;
    }

    observer = new IntersectionObserver(
        (entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                hasRead.value = true;
                observer?.disconnect();
            }
        },
        { rootMargin: "0px 0px -40px 0px" },
    );

    observer.observe(sentinel);
});

onBeforeUnmount(() => observer?.disconnect());

const identityComplete = computed(
    () =>
        form.value.firstName.trim() !== "" &&
        form.value.lastName.trim() !== "" &&
        form.value.email.trim() !== "" &&
        form.value.place.trim() !== "" &&
        form.value.date !== "",
);

const canRequestCode = computed(
    () => identityComplete.value && hasDrawn.value && !requestingCode.value,
);

const canSign = computed(
    () =>
        canRequestCode.value &&
        codeSentTo.value !== null &&
        form.value.consent &&
        hasRead.value &&
        form.value.code.trim().length > 0 &&
        !signing.value,
);

async function requestCode() {
    if (!canRequestCode.value) return;

    requestingCode.value = true;
    errors.value = {};

    try {
        const data = await request(props.codePath, {}, { noGuard: true });

        if (data?.errors) {
            errors.value = data.errors;

            return;
        }

        if (data?.error) {
            errors.value = { code: t("studio.public.sign.errors.too_many_requests") };

            return;
        }

        // L'adresse est ce qui prouve que l'envoi a eu lieu : sans elle, la
        // requête a échoué, et basculer quand même dans l'état « code envoyé »
        // affichait « Code envoyé à . » à un client qui attendrait ensuite un
        // code que personne n'a expédié.
        if (!data?.sentTo) {
            errors.value = { code: t("studio.public.sign.errors.code_not_sent") };

            return;
        }

        codeSentTo.value = data.sentTo;
    } finally {
        requestingCode.value = false;
    }
}

const showRefuse = ref(false);
const refusal = ref({ reason: "" });
const refusing = ref(false);
const refused = ref(false);

async function refuse() {
    if (refusing.value) return;

    refusing.value = true;
    errors.value = {};

    try {
        const data = await request(props.refusePath, refusal.value, {
            noGuard: true,
        });

        if (data?.errors) {
            errors.value = data.errors;

            return;
        }

        if (data?.refused) {
            refused.value = true;
            showRefuse.value = false;
            // Reloaded like a signature is, and for the same reason: the state
            // worth showing is the one the server recorded.
            window.location.assign(data.reloadPath);
        }
    } finally {
        refusing.value = false;
    }
}

async function sign() {
    if (!canSign.value) return;

    signing.value = true;
    errors.value = {};

    try {
        const data = await request(props.signPath, form.value, { noGuard: true });

        if (data?.errors) {
            errors.value = data.errors;

            return;
        }

        if (data?.signed) {
            signed.value = true;
            // Reloaded rather than patched in place: the page then shows the
            // signed state the server decided on, which is the only version of
            // it worth showing.
            window.location.assign(data.reloadPath);
        }
    } finally {
        signing.value = false;
    }
}
</script>

<template>
    <section class="aurora-card space-y-5 p-3 sm:p-5">
        <header class="space-y-1">
            <h2 class="flex items-center gap-2 font-medium text-primary">
                <PenLine class="h-4 w-4 shrink-0" :stroke-width="2" />
                {{ t("studio.public.sign.heading") }}
            </h2>
            <p class="text-sm text-secondary">
                {{ t("studio.public.sign.intro") }}
            </p>
        </header>

        <AppMessage v-if="errors.status" variant="danger">
            {{ errors.status }}
        </AppMessage>

        <div class="grid gap-4 sm:grid-cols-2">
            <AppInput
                v-model="form.firstName"
                :label="t('studio.public.sign.first_name')"
                :placeholder="t('studio.public.sign.first_name_placeholder')"
                :error="errors.firstName"
                required
            />
            <AppInput
                v-model="form.lastName"
                :label="t('studio.public.sign.last_name')"
                :placeholder="t('studio.public.sign.last_name_placeholder')"
                :error="errors.lastName"
                required
            />
        </div>

        <AppInput
            v-model="form.email"
            :label="t('studio.public.sign.email')"
            :placeholder="t('studio.public.sign.email_placeholder')"
            :hint="t('studio.public.sign.email_hint')"
            :error="errors.email"
            type="email"
            required
        />

        <div class="grid gap-4 sm:grid-cols-2">
            <AppInput
                v-model="form.place"
                :label="t('studio.public.sign.place')"
                :placeholder="t('studio.public.sign.place_placeholder')"
                :error="errors.place"
                required
            />
            <!-- The one date field left native, and it is a decision: this
                 page is opened by a stranger, often on a phone, and the OS
                 wheel beats any picker we ship for somebody who has never
                 seen this interface. Every backend date uses AppDatePicker. -->
            <AppInput
                v-model="form.date"
                :label="t('studio.public.sign.date')"
                :placeholder="t('studio.public.sign.date_placeholder')"
                :error="errors.date"
                type="date"
                required
            />
        </div>

        <AppSignaturePad
            v-model="form.signatureImage"
            :label="t('studio.public.sign.signature')"
            v-on:drawn="hasDrawn = $event"
        />
        <p v-if="errors.signatureImage" class="text-xs text-red-500">
            {{ errors.signatureImage }}
        </p>

        <!-- The code, asked for when the signer says they are ready. Sending it
             with the link would mean it expires long before anybody has read
             the document. -->
        <div class="space-y-3 rounded-lg border border-line bg-surface-2/40 p-4">
            <div v-if="codeSentTo === null" class="space-y-2">
                <p class="text-sm text-secondary">
                    {{ t("studio.public.sign.code_intro") }}
                </p>
                <!-- Pleine largeur sous `sm`, comme « Signer le contrat » plus
                     bas : c'est l'étape d'avant, sur la page qu'un client ouvre
                     presque toujours au téléphone. -->
                <AppButton
                    class="w-full sm:w-auto"
                    variant="secondary"
                    size="md"
                    :disabled="!canRequestCode"
                    :loading="requestingCode"
                    v-on:click="requestCode"
                >
                    <Mail class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("studio.public.sign.request_code") }}
                </AppButton>
                <p v-if="!identityComplete || !hasDrawn" class="text-xs text-muted">
                    {{ t("studio.public.sign.complete_first") }}
                </p>
            </div>

            <div v-else class="space-y-2">
                <AppInput
                    v-model="form.code"
                    :label="t('studio.public.sign.code')"
                    :placeholder="t('studio.public.sign.code_placeholder')"
                    :hint="t('studio.public.sign.code_sent', { email: codeSentTo })"
                    :error="errors.code"
                    required
                />
                <button
                    type="button"
                    class="text-xs text-muted underline hover:text-primary"
                    v-on:click="requestCode"
                >
                    {{ t("studio.public.sign.resend_code") }}
                </button>
            </div>
        </div>

        <label class="flex items-start gap-2 text-sm">
            <input
                v-model="form.consent"
                type="checkbox"
                class="mt-0.5 h-4 w-4 shrink-0 rounded border-line"
            >
            <span class="text-secondary">
                {{ t("studio.public.sign.consent") }}
            </span>
        </label>
        <p v-if="errors.consent" class="text-xs text-red-500">{{ errors.consent }}</p>

        <!-- Points at the notice rather than repeating it: the information has
             to be within reach when the box is ticked, and a second copy of it
             here would be a second copy to keep true. -->
        <p class="text-xs text-muted">
            {{ t("studio.public.sign.privacy_pointer") }}
        </p>

        <div class="space-y-2">
            <AppButton
                variant="primary"
                size="md"
                class="w-full sm:w-auto"
                :disabled="!canSign"
                :loading="signing || signed"
                v-on:click="sign"
            >
                <Check class="h-3.5 w-3.5" :stroke-width="2" />
                {{ t("studio.public.sign.submit") }}
            </AppButton>

            <!-- Says what is still missing rather than leaving a disabled
                 button with no explanation, which is the most common way a form
                 like this loses somebody. -->
            <p v-if="!hasRead" class="text-xs text-muted">
                {{ t("studio.public.sign.read_first") }}
            </p>
            <p v-else-if="codeSentTo === null" class="text-xs text-muted">
                {{ t("studio.public.sign.code_first") }}
            </p>
            <p v-else-if="!form.consent" class="text-xs text-muted">
                {{ t("studio.public.sign.consent_first") }}
            </p>
        </div>

        <!-- Under the primary action and quieter than it, which is the honest
             weight: this page exists to be signed. Never disabled by the
             scroll gate - somebody who has decided not to sign should not
             have to scroll a document to say so. -->
        <div class="border-t border-line pt-4">
            <button
                type="button"
                class="text-xs text-muted underline underline-offset-2 hover:text-primary"
                v-on:click="showRefuse = true"
            >
                {{ t("studio.public.refuse.trigger") }}
            </button>
        </div>

        <AppModal
            :show="showRefuse"
            max-width="md"
            :closeable="false"
            :title="t('studio.public.refuse.heading')"
            :icon="Ban"
            v-on:close="showRefuse = false"
        >
            <p class="text-sm text-secondary">
                {{ t("studio.public.refuse.intro") }}
            </p>
            <AppTextarea
                v-model="refusal.reason"
                :label="t('studio.public.refuse.reason')"
                :placeholder="t('studio.public.refuse.reason_placeholder')"
                :hint="t('studio.public.refuse.reason_hint')"
                :error="errors.reason"
                :rows="4"
            />
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showRefuse = false">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("studio.public.refuse.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="danger"
                        size="md"
                        :loading="refusing || refused"
                        v-on:click="refuse"
                    >
                        <Ban class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("studio.public.refuse.submit") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </section>
</template>
