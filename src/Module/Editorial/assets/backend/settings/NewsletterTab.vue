<script setup>
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Save } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppChoiceRow from "@/shared/components/form/select/AppChoiceRow.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import IntegrationLayout from "@configuration/backend/settings/components/IntegrationLayout.vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";

const { formatDateTimeNumeric } = useDateFormat();

defineProps({
    groups: { type: Object, default: () => ({}) },
    updatePath: { type: String, default: "" },
});

const SETTINGS_PATH = "/backend/editorial/newsletter/settings";
const PROVIDERS = ["brevo", "mailchimp"];

const { t } = useI18n();
const { request } = useRequest();

const loading = ref(true);
const saving = ref(false);
const enabled = ref(false);
const termsAccepted = ref(false);
const provider = ref(PROVIDERS[0]);
const hasKey = ref(false);
const listId = ref("");
const acceptedAt = ref(null);
const acceptedBy = ref(null);
const apiKey = ref("");
// On by default, like the server: an address typed on a public page proves
// nothing about who typed it.
const doubleOptIn = ref(true);
const brevoTemplateId = ref("");
const privacyUrl = ref("");

const needsBrevoTemplate = computed(() => "brevo" === provider.value && doubleOptIn.value);

const providerOptions = computed(() => PROVIDERS.map((value) => ({ value, label: t(`backend.editorial.newsletter.settings.providers.${value}`) })));

const acceptedOn = computed(() => {
    if (!acceptedAt.value) return "";
    const date = new Date(acceptedAt.value);

    return Number.isNaN(date.getTime()) ? acceptedAt.value : formatDateTimeNumeric(date.toISOString());
});

const canEnable = computed(
    () =>
        termsAccepted.value &&
        (hasKey.value || "" !== apiKey.value.trim()) &&
        "" !== listId.value.trim() &&
        "" !== privacyUrl.value.trim() &&
        (!needsBrevoTemplate.value || "" !== brevoTemplateId.value.trim()),
);

function apply(state) {
    if (!state) return;
    enabled.value = true === state.enabled;
    provider.value = state.provider ?? PROVIDERS[0];
    hasKey.value = true === state.hasKey;
    listId.value = state.listId ?? "";
    acceptedAt.value = state.acceptedAt ?? null;
    acceptedBy.value = state.acceptedBy ?? null;
    termsAccepted.value = null !== acceptedAt.value;
    apiKey.value = "";
    doubleOptIn.value = false !== state.doubleOptIn;
    brevoTemplateId.value = null !== (state.brevoTemplateId ?? null) ? String(state.brevoTemplateId) : "";
    privacyUrl.value = state.privacyUrl ?? "";
}

onMounted(async () => {
    try {
        apply(await request(SETTINGS_PATH, null, { method: HttpMethod.Get, noGuard: true }));
    } finally {
        loading.value = false;
    }
});

async function save() {
    saving.value = true;
    try {
        const payload = {
            enabled: enabled.value && canEnable.value,
            termsAccepted: termsAccepted.value,
            provider: provider.value,
            listId: listId.value.trim(),
            doubleOptIn: doubleOptIn.value,
            brevoTemplateId: brevoTemplateId.value.trim(),
            privacyUrl: privacyUrl.value.trim(),
        };
        if ("" !== apiKey.value.trim()) payload.apiKey = apiKey.value.trim();

        const state = await request(SETTINGS_PATH, payload, { noGuard: true });

        if (state) {
            apply(state);
            toast.success(t("backend.settings.saved"));
        }
    } finally {
        saving.value = false;
    }
}

/** Allumée, prête mais éteinte, ou encore à configurer. */
const status = computed(() => {
    if (enabled.value) return "active";

    return termsAccepted.value && hasKey.value && "" !== listId.value.trim() ? "off" : "todo";
});

defineExpose({ save, apply, canEnable });
</script>

<template>
    <IntegrationLayout :summary="t('backend.editorial.newsletter.settings.what_body')" :status="status" :loading="loading">
        <AppChoiceRow v-model="provider" :label="t('backend.editorial.newsletter.settings.provider_label')" :options="providerOptions" />

        <section class="flex flex-col gap-3 rounded-lg border border-line bg-surface-2 p-3 sm:p-4">
            <AppCheckbox v-model="termsAccepted" :label="t('backend.editorial.newsletter.settings.terms_label')" :hint="t('backend.editorial.newsletter.settings.terms_hint')" />
            <p v-if="acceptedAt" class="m-0 pl-6 text-xs text-muted">{{ t("backend.editorial.newsletter.settings.accepted_on", { date: acceptedOn, user: acceptedBy ?? "?" }) }}</p>
        </section>

        <AppInput v-model="listId" :label="'brevo' === provider ? t('backend.editorial.newsletter.settings.list_label_brevo') : t('backend.editorial.newsletter.settings.list_label_mailchimp')" :placeholder="'brevo' === provider ? '3' : 'a1b2c3d4e5'" />
        <AppInput
            v-model="apiKey"
            type="password"
            :toggleable="true"
            :label="t('backend.editorial.newsletter.settings.key_label')"
            :hint="hasKey ? t('backend.editorial.newsletter.settings.key_stored') : t('backend.editorial.newsletter.settings.key_hint')"
            :placeholder="hasKey ? '••••••••••••••••' : ''"
        />

        <!-- La conformité dans la même carte, au-dessus du bouton : elle
             s'enregistre avec le reste, et une carte à part sous le bouton
             laissait croire le contraire. -->
        <section class="flex flex-col gap-3 border-t border-line/60 pt-4">
            <div class="flex flex-col gap-1">
                <h4 class="m-0 text-sm font-medium text-primary">{{ t("backend.editorial.newsletter.settings.compliance_title") }}</h4>
                <p class="m-0 text-xs text-muted">{{ t("backend.editorial.newsletter.settings.compliance_body") }}</p>
            </div>
            <AppInput
                v-model="privacyUrl"
                :label="t('backend.editorial.newsletter.settings.privacy_label')"
                :hint="t('backend.editorial.newsletter.settings.privacy_hint')"
                placeholder="/fr/page/confidentialite"
            />
            <AppCheckbox v-model="doubleOptIn" :label="t('backend.editorial.newsletter.settings.double_opt_in_label')" :hint="t('backend.editorial.newsletter.settings.double_opt_in_hint')" />
            <AppInput
                v-if="needsBrevoTemplate"
                v-model="brevoTemplateId"
                :label="t('backend.editorial.newsletter.settings.brevo_template_label')"
                :hint="t('backend.editorial.newsletter.settings.brevo_template_hint')"
                placeholder="12"
            />
        </section>

        <AppCheckbox v-model="enabled" :label="t('backend.editorial.newsletter.settings.enabled_label')" :hint="canEnable ? t('backend.editorial.newsletter.settings.enabled_hint') : t('backend.editorial.newsletter.settings.enabled_blocked')" :disabled="!canEnable" />

        <template #actions>
            <AppButton
                variant="primary"
                size="md"
                class="w-full sm:w-auto"
                :loading="saving"
                v-on:click="save"
            >
                <Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}
            </AppButton>
        </template>


        <template #guide>
            <p class="m-0">{{ "brevo" === provider ? t("backend.editorial.newsletter.settings.how_brevo") : t("backend.editorial.newsletter.settings.how_mailchimp") }}</p>
        </template>
    </IntegrationLayout>
</template>
