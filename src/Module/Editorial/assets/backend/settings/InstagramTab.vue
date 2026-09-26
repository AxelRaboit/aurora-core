<script setup>
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { ExternalLink, Save } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";

const { formatDateTimeNumeric } = useDateFormat();

/**
 * The Instagram tab of the settings screen, on the model of Pexels': it
 * draws itself because a token has no business in a page, and the record of
 * who accepted Meta's terms is a fact about a person, not a value to retype.
 */
defineProps({
    groups: { type: Object, default: () => ({}) },
    updatePath: { type: String, default: "" },
});

const SETTINGS_PATH = "/backend/editorial/instagram/settings";

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

    return Number.isNaN(date.getTime()) ? acceptedAt.value : formatDateTimeNumeric(date.toISOString());
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
            toast.success(t("backend.settings.saved"));
        }
    } finally {
        saving.value = false;
    }
}

defineExpose({ save, apply, canEnable });
</script>

<template>
    <div class="relative space-y-5">
        <AppLoader :active="loading" />

        <section class="space-y-2">
            <h3 class="text-sm font-medium text-primary">{{ t("backend.editorial.instagram.settings.what_title") }}</h3>
            <p class="text-sm text-secondary">{{ t("backend.editorial.instagram.settings.what_body") }}</p>
        </section>

        <section class="space-y-2">
            <h3 class="text-sm font-medium text-primary">{{ t("backend.editorial.instagram.settings.how_title") }}</h3>
            <ol class="list-decimal space-y-1 pl-5 text-sm text-secondary">
                <li>{{ t("backend.editorial.instagram.settings.how_step_business") }}</li>
                <li>{{ t("backend.editorial.instagram.settings.how_step_app") }}</li>
                <li>{{ t("backend.editorial.instagram.settings.how_step_token") }}</li>
                <li>{{ t("backend.editorial.instagram.settings.how_step_paste") }}</li>
            </ol>
            <a href="https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-sm text-accent hover:underline">
                {{ t("backend.editorial.instagram.settings.how_link") }}
                <ExternalLink class="w-3.5 h-3.5" :stroke-width="2" />
            </a>
        </section>

        <section class="space-y-3 rounded-lg border border-line bg-surface-2 p-4">
            <AppCheckbox v-model="termsAccepted" :label="t('backend.editorial.instagram.settings.terms_label')" :hint="t('backend.editorial.instagram.settings.terms_hint')" />
            <a href="https://www.facebook.com/legal/terms" target="_blank" rel="noopener" class="pl-6 text-xs text-accent hover:underline">{{ t("backend.editorial.instagram.settings.terms_link") }}</a>
            <p v-if="acceptedAt" class="pl-6 text-xs text-muted">{{ t("backend.editorial.instagram.settings.accepted_on", { date: acceptedOn, user: acceptedBy ?? "?" }) }}</p>
        </section>

        <section class="space-y-3">
            <AppInput v-model="businessAccountId" :label="t('backend.editorial.instagram.settings.business_id_label')" :hint="t('backend.editorial.instagram.settings.business_id_hint')" placeholder="17841400000000000" />
            <AppInput
                v-model="accessToken"
                type="password"
                :toggleable="true"
                :label="t('backend.editorial.instagram.settings.token_label')"
                :hint="hasToken ? t('backend.editorial.instagram.settings.token_stored') : t('backend.editorial.instagram.settings.token_hint')"
                :placeholder="hasToken ? '••••••••••••••••' : ''"
            />
            <AppCheckbox v-model="enabled" :label="t('backend.editorial.instagram.settings.enabled_label')" :hint="canEnable ? t('backend.editorial.instagram.settings.enabled_hint') : t('backend.editorial.instagram.settings.enabled_blocked')" :disabled="!canEnable" />
        </section>

        <div class="flex justify-end">
            <AppButton variant="primary" size="md" :loading="saving" v-on:click="save">
                <Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}
            </AppButton>
        </div>
    </div>
</template>
