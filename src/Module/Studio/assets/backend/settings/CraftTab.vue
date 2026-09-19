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

/**
 * L'onglet Craft de l'écran des réglages.
 *
 * Il se dessine lui-même plutôt que de déclarer des champs : le jeton n'a rien
 * à faire dans le source d'une page, et le rendu générique l'y mettrait comme
 * une valeur ordinaire.
 *
 * **La marche à suivre est sur la page.** Une connexion Craft se crée dans
 * Craft, dans un onglet que personne ne trouve du premier coup, et la portée
 * qu'on lui donne là-bas décide de ce qu'Aurora verra. Les trois pas comptent
 * donc plus que les deux champs.
 */
defineProps({
    groups: { type: Object, default: () => ({}) },
    updatePath: { type: String, default: "" },
});

const SETTINGS_PATH = "/backend/studio/craft/settings";

const { t } = useI18n();
const { request } = useRequest();

const loading = ref(true);
const saving = ref(false);
const enabled = ref(false);
const endpoint = ref("");
const hasToken = ref(false);

// Jamais prérempli depuis le serveur : à partir d'ici le jeton ne se lit plus,
// il ne s'écrit que. Laissé vide, l'enregistrement garde celui qui est stocké.
const token = ref("");

/**
 * L'interrupteur demande une adresse et un jeton, et le serveur refuse sans
 * eux. Le désactiver ici met la raison devant le clic plutôt qu'après.
 */
const canEnable = computed(
    () => "" !== endpoint.value.trim() && (hasToken.value || "" !== token.value.trim()),
);

function apply(state) {
    if (!state) return;
    enabled.value = true === state.enabled;
    endpoint.value = state.endpoint ?? "";
    hasToken.value = true === state.hasToken;
    token.value = "";
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
            endpoint: endpoint.value.trim(),
        };
        // Envoyé seulement si quelqu'un a tapé, pour qu'enregistrer l'onglet
        // sans toucher au champ garde le jeton déjà en place.
        if ("" !== token.value.trim()) payload.token = token.value.trim();

        const state = await request(SETTINGS_PATH, payload, { noGuard: true });

        // Rien ne change visiblement quand l'enregistrement marche - le jeton
        // revient en « un jeton est enregistré », pas en lui-même - donc sans
        // un mot, appuyer ressemble à ne rien appuyer.
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
    <div class="relative space-y-6">
        <AppLoader :active="loading" />

        <section class="space-y-2">
            <h3 class="text-sm font-medium text-primary">{{ t("backend.studio.craft.settings.what_title") }}</h3>
            <p class="text-sm text-secondary">{{ t("backend.studio.craft.settings.what_body") }}</p>
        </section>

        <section class="space-y-2">
            <h3 class="text-sm font-medium text-primary">{{ t("backend.studio.craft.settings.how_title") }}</h3>
            <ol class="list-decimal space-y-1 pl-5 text-sm text-secondary">
                <li>{{ t("backend.studio.craft.settings.how_step_connection") }}</li>
                <li>{{ t("backend.studio.craft.settings.how_step_select") }}</li>
                <li>{{ t("backend.studio.craft.settings.how_step_paste") }}</li>
            </ol>
            <a
                href="https://support.craft.do/en/integrate/api"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center gap-1 text-sm text-accent hover:underline"
            >
                {{ t("backend.studio.craft.settings.how_link") }}
                <ExternalLink class="w-3.5 h-3.5" :stroke-width="2" />
            </a>
        </section>

        <section class="space-y-3">
            <AppInput
                v-model="endpoint"
                :label="t('backend.studio.craft.settings.endpoint_label')"
                :hint="t('backend.studio.craft.settings.endpoint_hint')"
                placeholder="https://connect.craft.do/..."
            />

            <AppInput
                v-model="token"
                type="password"
                :toggleable="true"
                :label="t('backend.studio.craft.settings.token_label')"
                :hint="hasToken ? t('backend.studio.craft.settings.token_stored') : t('backend.studio.craft.settings.token_hint')"
                :placeholder="hasToken ? '••••••••••••••••' : ''"
            />

            <AppCheckbox
                v-model="enabled"
                :label="t('backend.studio.craft.settings.enabled_label')"
                :hint="canEnable ? t('backend.studio.craft.settings.enabled_hint') : t('backend.studio.craft.settings.enabled_blocked')"
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
