<script setup>
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Save } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import IntegrationLayout from "@configuration/suite/settings/components/IntegrationLayout.vue";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/**
 * L'onglet GitHub de l'écran des réglages.
 *
 * Il se dessine lui-même pour que le serveur vérifie chaque identifiant avant
 * de l'enregistrer : un compte mal recopié doit être refusé à la saisie, pas
 * découvert plus tard comme une grille qui n'apparaît pas.
 */
defineProps({
    groups: { type: Object, default: () => ({}) },
    updatePath: { type: String, default: "" },
});

const SETTINGS_PATH = "/suite/editorial/github/settings";

const { t } = useI18n();
const { request } = useRequest();

const loading = ref(true);
const saving = ref(false);
const enabled = ref(false);
const logins = ref("");

/** Le serveur refuse l'interrupteur sans compte : la raison se lit avant le clic. */
const canEnable = computed(() => "" !== logins.value.trim());

function apply(state) {
    if (!state) return;
    enabled.value = true === state.enabled;
    logins.value = (state.logins ?? []).join("\n");
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
        const state = await request(
            SETTINGS_PATH,
            { enabled: enabled.value && canEnable.value, logins: logins.value },
            { noGuard: true },
        );

        if (!state) return;

        if (false === state.success) {
            toast.error(t(state.error, { logins: state.logins ?? "", max: state.max ?? "" }));

            return;
        }

        apply(state);
        toast.success(t("suite.settings.saved"));
    } finally {
        saving.value = false;
    }
}

/** Allumée, prête mais éteinte, ou encore à configurer. */
const status = computed(() => {
    if (enabled.value) return "active";

    return canEnable.value ? "off" : "todo";
});

defineExpose({ save, apply, canEnable });
</script>

<template>
    <IntegrationLayout :summary="t('suite.editorial.github.settings.what_body')" :status="status" :loading="loading">
        <AppTextarea
            v-model="logins"
            :rows="4"
            :label="t('suite.editorial.github.settings.logins_label')"
            :hint="t('suite.editorial.github.settings.logins_hint')"
            placeholder="AxelRaboit"
        />

        <AppCheckbox
            v-model="enabled"
            :label="t('suite.editorial.github.settings.enabled_label')"
            :hint="canEnable ? t('suite.editorial.github.settings.enabled_hint') : t('suite.editorial.github.settings.enabled_blocked')"
            :disabled="!canEnable"
        />

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
            <p class="m-0 font-medium text-primary">{{ t("suite.editorial.github.settings.source_title") }}</p>
            <p class="m-0">{{ t("suite.editorial.github.settings.source_body") }}</p>
        </template>
    </IntegrationLayout>
</template>
