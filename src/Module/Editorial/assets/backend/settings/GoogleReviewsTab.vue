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

defineProps({
    groups: { type: Object, default: () => ({}) },
    updatePath: { type: String, default: "" },
});

const SETTINGS_PATH = "/backend/editorial/google-reviews/settings";

const { t } = useI18n();
const { request } = useRequest();

const loading = ref(true);
const saving = ref(false);
const enabled = ref(false);
const termsAccepted = ref(false);
const hasKey = ref(false);
const placeId = ref("");
const acceptedAt = ref(null);
const acceptedBy = ref(null);
const apiKey = ref("");

const acceptedOn = computed(() => {
    if (!acceptedAt.value) return "";
    const date = new Date(acceptedAt.value);

    return Number.isNaN(date.getTime()) ? acceptedAt.value : formatDateTimeNumeric(date.toISOString());
});

const canEnable = computed(
    () => termsAccepted.value && (hasKey.value || "" !== apiKey.value.trim()) && "" !== placeId.value.trim(),
);

function apply(state) {
    if (!state) return;
    enabled.value = true === state.enabled;
    hasKey.value = true === state.hasKey;
    placeId.value = state.placeId ?? "";
    acceptedAt.value = state.acceptedAt ?? null;
    acceptedBy.value = state.acceptedBy ?? null;
    termsAccepted.value = null !== acceptedAt.value;
    apiKey.value = "";
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
            placeId: placeId.value.trim(),
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

defineExpose({ save, apply, canEnable });
</script>

<template>
    <div class="relative space-y-5">
        <AppLoader :active="loading" />

        <section class="space-y-2">
            <h3 class="text-sm font-medium text-primary">{{ t("backend.editorial.google_reviews.settings.what_title") }}</h3>
            <p class="text-sm text-secondary">{{ t("backend.editorial.google_reviews.settings.what_body") }}</p>
        </section>

        <section class="space-y-2">
            <h3 class="text-sm font-medium text-primary">{{ t("backend.editorial.google_reviews.settings.how_title") }}</h3>
            <ol class="list-decimal space-y-1 pl-5 text-sm text-secondary">
                <li>{{ t("backend.editorial.google_reviews.settings.how_step_console") }}</li>
                <li>{{ t("backend.editorial.google_reviews.settings.how_step_key") }}</li>
                <li>{{ t("backend.editorial.google_reviews.settings.how_step_place") }}</li>
            </ol>
            <a href="https://developers.google.com/maps/documentation/places/web-service/place-id" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-sm text-accent hover:underline">
                {{ t("backend.editorial.google_reviews.settings.how_link") }}
                <ExternalLink class="w-3.5 h-3.5" :stroke-width="2" />
            </a>
        </section>

        <section class="space-y-3 rounded-lg border border-line bg-surface-2 p-4">
            <AppCheckbox v-model="termsAccepted" :label="t('backend.editorial.google_reviews.settings.terms_label')" :hint="t('backend.editorial.google_reviews.settings.terms_hint')" />
            <a href="https://cloud.google.com/maps-platform/terms" target="_blank" rel="noopener" class="pl-6 text-xs text-accent hover:underline">{{ t("backend.editorial.google_reviews.settings.terms_link") }}</a>
            <p v-if="acceptedAt" class="pl-6 text-xs text-muted">{{ t("backend.editorial.google_reviews.settings.accepted_on", { date: acceptedOn, user: acceptedBy ?? "?" }) }}</p>
        </section>

        <section class="space-y-3">
            <AppInput v-model="placeId" :label="t('backend.editorial.google_reviews.settings.place_label')" :hint="t('backend.editorial.google_reviews.settings.place_hint')" placeholder="ChIJ…" />
            <AppInput
                v-model="apiKey"
                type="password"
                :toggleable="true"
                :label="t('backend.editorial.google_reviews.settings.key_label')"
                :hint="hasKey ? t('backend.editorial.google_reviews.settings.key_stored') : t('backend.editorial.google_reviews.settings.key_hint')"
                :placeholder="hasKey ? '••••••••••••••••' : ''"
            />
            <AppCheckbox v-model="enabled" :label="t('backend.editorial.google_reviews.settings.enabled_label')" :hint="canEnable ? t('backend.editorial.google_reviews.settings.enabled_hint') : t('backend.editorial.google_reviews.settings.enabled_blocked')" :disabled="!canEnable" />
        </section>

        <div class="flex justify-end">
            <AppButton variant="primary" size="md" :loading="saving" v-on:click="save">
                <Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}
            </AppButton>
        </div>
    </div>
</template>
