<script setup>
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { ExternalLink, Save } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import IntegrationLayout from "@configuration/suite/settings/components/IntegrationLayout.vue";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/**
 * The anti-robot tab of the settings screen.
 *
 * It draws itself rather than declaring fields, because the secret key must
 * not travel through the generic renderer: that one ships every value it
 * draws to the browser, and this is the one value that must not go back out.
 *
 * The privacy line is not decoration. The check sends the reader's IP address
 * to Cloudflare or to Google, and on a site delivered to a client that is the
 * client's decision and the client's privacy notice.
 */
defineProps({
    groups: { type: Object, default: () => ({}) },
    updatePath: { type: String, default: "" },
});

const SETTINGS_PATH = "/suite/editorial/captcha/settings";

const { t } = useI18n();
const { request } = useRequest();

const loading = ref(true);
const saving = ref(false);
const enabled = ref(false);
const provider = ref("turnstile");
const siteKey = ref("");
const secretKey = ref("");
const hasSecret = ref(false);

const providerOptions = [
    { value: "turnstile", label: "suite.parameters.captcha.providers.turnstile" },
    { value: "recaptcha", label: "suite.parameters.captcha.providers.recaptcha" },
];

/** On, ready but off, or still to be configured. */
const status = computed(() => {
    const ready = "" !== siteKey.value.trim() && (hasSecret.value || "" !== secretKey.value.trim());
    if (enabled.value && ready) return "active";

    return ready ? "off" : "todo";
});

function apply(state) {
    if (!state) return;

    enabled.value = Boolean(state.enabled);
    provider.value = state.provider ?? "turnstile";
    siteKey.value = state.siteKey ?? "";
    hasSecret.value = Boolean(state.hasSecret);
    // Never filled from the server: it does not send the secret, and a field
    // showing dots would suggest it did.
    secretKey.value = "";
}

onMounted(async () => {
    const data = await request(SETTINGS_PATH, null, HttpMethod.Get);
    apply(data?.data ?? data);
    loading.value = false;
});

async function save() {
    saving.value = true;

    try {
        const payload = {
            enabled: enabled.value,
            provider: provider.value,
            siteKey: siteKey.value,
        };

        // Sent only when somebody typed in it, so saving the rest of the tab
        // leaves the stored secret alone.
        if ("" !== secretKey.value) {
            payload.secretKey = secretKey.value;
        }

        const data = await request(SETTINGS_PATH, payload);

        if (false === data?.success) {
            toast.error(t(data.error ?? "shared.common.error"));

            return;
        }

        apply(data?.data ?? data);
        toast.success(t("shared.common.saved"));
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <IntegrationLayout
        :summary="t('suite.parameters.captcha.intro')"
        :status="status"
        :loading="loading"
        :settings-title="t('suite.parameters.captcha.title')"
    >
        <p class="m-0 text-xs text-amber-600 dark:text-amber-500">{{ t("suite.parameters.captcha.privacy") }}</p>

        <AppSelect
            v-model="provider"
            :label="t('suite.parameters.captcha.provider')"
            :options="providerOptions.map((option) => ({ value: option.value, label: t(option.label) }))"
        />

        <AppInput
            v-model="siteKey"
            :label="t('suite.parameters.captcha.site_key')"
            :placeholder="t('suite.parameters.captcha.site_key_placeholder')"
            :hint="t('suite.parameters.captcha.site_key_hint')"
        />

        <AppInput
            v-model="secretKey"
            type="password"
            autocomplete="off"
            :label="t('suite.parameters.captcha.secret_key')"
            :placeholder="t('suite.parameters.captcha.secret_key_placeholder')"
            :hint="t(hasSecret ? 'suite.parameters.captcha.secret_set' : 'suite.parameters.captcha.secret_key_hint')"
        />

        <AppCheckbox v-model="enabled" :label="t('suite.parameters.captcha.enabled')" />

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

        <!-- The steps of the chosen service, not of both: they only look
             alike from a distance. -->
        <template #guide>
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 3" :key="step">{{ t(`suite.parameters.captcha.guide_${provider}_${step}`) }}</li>
            </ol>
            <a
                :href="'recaptcha' === provider ? 'https://www.google.com/recaptcha/admin/create' : 'https://dash.cloudflare.com/?to=/:account/turnstile'"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center gap-1 text-sm text-accent hover:underline"
            >
                {{ t(`suite.parameters.captcha.guide_link_${provider}`) }}
                <ExternalLink class="w-3.5 h-3.5" :stroke-width="2" />
            </a>
        </template>
    </IntegrationLayout>
</template>
