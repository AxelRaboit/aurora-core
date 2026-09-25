<script setup>
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Save } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
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

const SETTINGS_PATH = "/backend/editorial/github/settings";

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
        toast.success(t("backend.settings.saved"));
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
            <h3 class="text-sm font-medium text-primary">{{ t("backend.editorial.github.settings.what_title") }}</h3>
            <p class="text-sm text-secondary">{{ t("backend.editorial.github.settings.what_body") }}</p>
        </section>

        <section class="space-y-2">
            <h3 class="text-sm font-medium text-primary">{{ t("backend.editorial.github.settings.source_title") }}</h3>
            <p class="text-sm text-secondary">{{ t("backend.editorial.github.settings.source_body") }}</p>
        </section>

        <section class="space-y-3">
            <AppTextarea
                v-model="logins"
                :rows="4"
                :label="t('backend.editorial.github.settings.logins_label')"
                :hint="t('backend.editorial.github.settings.logins_hint')"
                placeholder="AxelRaboit"
            />

            <AppCheckbox
                v-model="enabled"
                :label="t('backend.editorial.github.settings.enabled_label')"
                :hint="canEnable ? t('backend.editorial.github.settings.enabled_hint') : t('backend.editorial.github.settings.enabled_blocked')"
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
