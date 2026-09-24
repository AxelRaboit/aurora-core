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
 * The Pexels tab of the settings screen.
 *
 * It draws itself rather than declaring fields, because two of the four
 * values behind it must not travel through the generic renderer: the API key,
 * which has no business being echoed into a page, and the record of who
 * accepted the terms, which is a fact about something a person did rather
 * than a value they may retype.
 *
 * Most of what follows is instructions. Aurora is delivered to clients, and
 * the account behind the key is theirs to open: someone who has never heard
 * of Pexels has to be able to finish this on their own, so the steps are on
 * the page rather than in a document nobody kept.
 */
defineProps({
    groups: { type: Object, default: () => ({}) },
    updatePath: { type: String, default: "" },
});

const SETTINGS_PATH = "/backend/ged/pexels/settings";

const { t } = useI18n();
const { request } = useRequest();

const loading = ref(true);
const saving = ref(false);
const enabled = ref(false);
const termsAccepted = ref(false);
const hasKey = ref(false);
const acceptedAt = ref(null);
const acceptedBy = ref(null);

// Never pre-filled from the server: the key is write-only from here on. Left
// empty, the save leaves the stored one alone.
const apiKey = ref("");

const acceptedOn = computed(() => {
    if (!acceptedAt.value) return "";
    const date = new Date(acceptedAt.value);

    return Number.isNaN(date.getTime()) ? acceptedAt.value : formatDateTimeNumeric(date.toISOString());
});

/**
 * The toggle needs a key and an acceptance, and the server refuses without
 * them. Disabling it here means the reason is visible before the click
 * rather than reported after it.
 */
const canEnable = computed(() => termsAccepted.value && (hasKey.value || "" !== apiKey.value.trim()));

function apply(state) {
    if (!state) return;
    enabled.value = true === state.enabled;
    hasKey.value = true === state.hasKey;
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
        };
        // Sent only when something was typed, so saving the tab without
        // touching the field keeps the key that is already stored.
        if ("" !== apiKey.value.trim()) payload.apiKey = apiKey.value.trim();

        const state = await request(SETTINGS_PATH, payload, { noGuard: true });

        // Nothing on this screen changes visibly when a save works - the key
        // comes back as "a key is stored", not as itself - so without a word
        // said, pressing the button looks like pressing nothing. `useRequest`
        // already reports a failure; this is the other half.
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
            <h3 class="text-sm font-medium text-primary">{{ t("backend.ged.pexels.settings.what_title") }}</h3>
            <p class="text-sm text-secondary">{{ t("backend.ged.pexels.settings.what_body") }}</p>
        </section>

        <section class="space-y-2">
            <h3 class="text-sm font-medium text-primary">{{ t("backend.ged.pexels.settings.how_title") }}</h3>
            <ol class="list-decimal space-y-1 pl-5 text-sm text-secondary">
                <li>{{ t("backend.ged.pexels.settings.how_step_account") }}</li>
                <li>{{ t("backend.ged.pexels.settings.how_step_request") }}</li>
                <li>{{ t("backend.ged.pexels.settings.how_step_paste") }}</li>
            </ol>
            <a
                href="https://www.pexels.com/api/"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center gap-1 text-sm text-accent hover:underline"
            >
                {{ t("backend.ged.pexels.settings.how_link") }}
                <ExternalLink class="w-3.5 h-3.5" :stroke-width="2" />
            </a>
        </section>

        <section class="space-y-3 rounded-lg border border-line bg-surface-2 p-4">
            <AppCheckbox
                v-model="termsAccepted"
                :label="t('backend.ged.pexels.settings.terms_label')"
                :hint="t('backend.ged.pexels.settings.terms_hint')"
            />
            <div class="flex flex-wrap gap-3 pl-6 text-xs">
                <a href="https://www.pexels.com/terms-of-service/" target="_blank" rel="noopener" class="text-accent hover:underline">
                    {{ t("backend.ged.pexels.settings.terms_link") }}
                </a>
                <a href="https://www.pexels.com/license/" target="_blank" rel="noopener" class="text-accent hover:underline">
                    {{ t("backend.ged.pexels.settings.license_link") }}
                </a>
            </div>
            <p v-if="acceptedAt" class="pl-6 text-xs text-muted">
                {{ t("backend.ged.pexels.settings.accepted_on", { date: acceptedOn, user: acceptedBy ?? "?" }) }}
            </p>
        </section>

        <section class="space-y-3">
            <AppInput
                v-model="apiKey"
                type="password"
                :toggleable="true"
                :label="t('backend.ged.pexels.settings.key_label')"
                :hint="hasKey ? t('backend.ged.pexels.settings.key_stored') : t('backend.ged.pexels.settings.key_hint')"
                :placeholder="hasKey ? '••••••••••••••••' : ''"
            />

            <AppCheckbox
                v-model="enabled"
                :label="t('backend.ged.pexels.settings.enabled_label')"
                :hint="canEnable ? t('backend.ged.pexels.settings.enabled_hint') : t('backend.ged.pexels.settings.enabled_blocked')"
                :disabled="!canEnable"
            />
        </section>

        <div class="flex justify-end">
            <AppButton variant="primary" size="md" :loading="saving" v-on:click="save">
                <Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}
            </AppButton>
        </div>
    </div>
</template>
