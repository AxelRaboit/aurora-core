<script setup>
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { ExternalLink, Save } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import IntegrationLayout from "@configuration/suite/settings/components/IntegrationLayout.vue";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";

const { formatDateTime } = useDateFormat();

/**
 * The Instagram tab of the settings screen, on the model of Pexels': it
 * draws itself because a token has no business in a page, and the record of
 * who accepted Meta's terms is a fact about a person, not a value to retype.
 */
defineProps({
    groups: { type: Object, default: () => ({}) },
    updatePath: { type: String, default: "" },
});

const SETTINGS_PATH = "/suite/editorial/instagram/settings";

const { t } = useI18n();
const { request } = useRequest();

const loading = ref(true);
const saving = ref(false);
const enabled = ref(false);
const termsAccepted = ref(false);
const hasToken = ref(false);
const businessAccountId = ref("");
const acceptedAt = ref(null);
const acceptedBy = ref(null);
const accessToken = ref("");

const acceptedOn = computed(() => {
    if (!acceptedAt.value) return "";
    const date = new Date(acceptedAt.value);

    return Number.isNaN(date.getTime()) ? acceptedAt.value : formatDateTime(date.toISOString());
});

const canEnable = computed(
    () => termsAccepted.value
        && (hasToken.value || "" !== accessToken.value.trim())
        && "" !== businessAccountId.value.trim(),
);

function apply(state) {
    if (!state) return;
    enabled.value = true === state.enabled;
    hasToken.value = true === state.hasToken;
    businessAccountId.value = state.businessAccountId ?? "";
    acceptedAt.value = state.acceptedAt ?? null;
    acceptedBy.value = state.acceptedBy ?? null;
    termsAccepted.value = null !== acceptedAt.value;
    accessToken.value = "";
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
            businessAccountId: businessAccountId.value.trim(),
        };
        if ("" !== accessToken.value.trim()) payload.accessToken = accessToken.value.trim();

        const state = await request(SETTINGS_PATH, payload, { noGuard: true });

        if (state) {
            apply(state);
            toast.success(t("suite.settings.saved"));
        }
    } finally {
        saving.value = false;
    }
}

/** On, ready but off, or still to be configured. */
const status = computed(() => {
    if (enabled.value) return "active";

    return termsAccepted.value && hasToken.value && "" !== businessAccountId.value.trim() ? "off" : "todo";
});

defineExpose({ save, apply, canEnable });
</script>

<template>
    <IntegrationLayout :summary="t('suite.editorial.instagram.settings.what_body')" :status="status" :loading="loading">
        <section class="flex flex-col gap-3 rounded-lg border border-line bg-surface-2 p-3 sm:p-4">
            <AppCheckbox v-model="termsAccepted" :label="t('suite.editorial.instagram.settings.terms_label')" :hint="t('suite.editorial.instagram.settings.terms_hint')" />
            <a href="https://www.facebook.com/legal/terms" target="_blank" rel="noopener" class="pl-6 text-xs text-accent hover:underline">{{ t("suite.editorial.instagram.settings.terms_link") }}</a>
            <p v-if="acceptedAt" class="m-0 pl-6 text-xs text-muted">{{ t("suite.editorial.instagram.settings.accepted_on", { date: acceptedOn, user: acceptedBy ?? "?" }) }}</p>
        </section>

        <AppInput v-model="businessAccountId" :label="t('suite.editorial.instagram.settings.business_id_label')" :hint="t('suite.editorial.instagram.settings.business_id_hint')" placeholder="17841400000000000" />
        <AppInput
            v-model="accessToken"
            type="password"
            :toggleable="true"
            :label="t('suite.editorial.instagram.settings.token_label')"
            :hint="hasToken ? t('suite.editorial.instagram.settings.token_stored') : t('suite.editorial.instagram.settings.token_hint')"
            :placeholder="hasToken ? '••••••••••••••••' : ''"
        />
        <AppCheckbox v-model="enabled" :label="t('suite.editorial.instagram.settings.enabled_label')" :hint="canEnable ? t('suite.editorial.instagram.settings.enabled_hint') : t('suite.editorial.instagram.settings.enabled_blocked')" :disabled="!canEnable" />

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
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li>{{ t("suite.editorial.instagram.settings.how_step_business") }}</li>
                <li>{{ t("suite.editorial.instagram.settings.how_step_app") }}</li>
                <li>{{ t("suite.editorial.instagram.settings.how_step_token") }}</li>
                <li>{{ t("suite.editorial.instagram.settings.how_step_paste") }}</li>
            </ol>
            <a href="https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-sm text-accent hover:underline">
                {{ t("suite.editorial.instagram.settings.how_link") }}
                <ExternalLink class="w-3.5 h-3.5" :stroke-width="2" />
            </a>
        </template>
    </IntegrationLayout>
</template>
