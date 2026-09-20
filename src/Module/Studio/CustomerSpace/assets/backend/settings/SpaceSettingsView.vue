<script setup>
/**
 * Les réglages d'un espace, ouverts au référent.
 *
 * **Un seul réglage aujourd'hui**, et l'écran est écrit pour en recevoir
 * d'autres : une section par sujet, chacune disant ce qu'elle protège avant
 * de demander quoi que ce soit. Un écran de réglages qui n'aligne que des
 * champs oblige à deviner ce que chacun ferme.
 *
 * Le mot de passe n'est jamais relu depuis le serveur, seulement posé ou
 * retiré. L'état se résume donc à un booléen : fermé, ou ouvert.
 */
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { KeyRound, Lock, LockOpen, Save } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

const props = defineProps({
    settingsPath: { type: String, required: true },
});

const emit = defineEmits(["locked-changed"]);

const { t } = useI18n();
const { request } = useRequest();

const loading = ref(true);
const saving = ref(false);
const locked = ref(false);
const minLength = ref(8);

const current = ref("");
const next = ref("");

function apply(settings) {
    if (!settings) return;

    locked.value = true === settings.driveLocked;
    minLength.value = settings.minPasswordLength ?? 8;
    current.value = "";
    next.value = "";

    // La barre d'onglets doit savoir : c'est elle qui décide si la vue Drive
    // demande le mot de passe en arrivant.
    emit("locked-changed", locked.value);
}

onMounted(async () => {
    try {
        // `.settings` et non la réponse entière : `request` rend l'enveloppe,
        // et `apply` lit le contenu. Passé l'enveloppe, tout est `undefined`
        // et l'écran annonce une porte ouverte sur un espace fermé.
        const data = await request(props.settingsPath, null, { method: HttpMethod.Get, noGuard: true });
        apply(data?.settings);
    } finally {
        loading.value = false;
    }
});

async function save() {
    saving.value = true;

    try {
        const data = await request(`${props.settingsPath}/drive-password`, {
            password: next.value,
            currentPassword: current.value,
        });

        if (data?.settings) {
            apply(data.settings);
            toast.success(t("backend.studio.spaces.settings.drive_saved"));
        }
    } finally {
        saving.value = false;
    }
}

async function clear() {
    saving.value = true;

    try {
        const data = await request(`${props.settingsPath}/drive-password/clear`, {
            currentPassword: current.value,
        });

        if (data?.settings) {
            apply(data.settings);
            toast.success(t("backend.studio.spaces.settings.drive_cleared"));
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <section class="relative space-y-4">
        <AppLoader :active="loading" />

        <header class="space-y-1">
            <h2 class="flex items-center gap-2 text-sm font-medium text-primary">
                <KeyRound class="h-4 w-4 shrink-0" :stroke-width="2" />
                {{ t("backend.studio.spaces.settings.drive_title") }}
            </h2>
            <p class="text-xs text-muted">{{ t("backend.studio.spaces.settings.drive_intro") }}</p>
        </header>

        <div class="space-y-3 rounded-lg border border-line bg-surface-2 p-3 sm:p-4">
            <p class="flex items-center gap-2 text-sm text-primary">
                <component :is="locked ? Lock : LockOpen" class="h-4 w-4 shrink-0" :stroke-width="2" />
                {{ t(locked ? "backend.studio.spaces.settings.drive_locked" : "backend.studio.spaces.settings.drive_open") }}
            </p>

            <!-- L'ancien mot de passe n'est demandé que s'il y en a un. Un
                 champ vide obligatoire sur une porte ouverte n'aurait rien à
                 vérifier. -->
            <label v-if="locked" class="block space-y-1">
                <span class="text-xs text-secondary">{{ t("backend.studio.spaces.settings.current_password") }}</span>
                <input
                    v-model="current"
                    type="password"
                    autocomplete="off"
                    :placeholder="t('backend.studio.spaces.settings.current_placeholder')"
                    class="w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-primary"
                >
            </label>

            <label class="block space-y-1">
                <span class="text-xs text-secondary">
                    {{ t(locked ? "backend.studio.spaces.settings.new_password" : "backend.studio.spaces.settings.password") }}
                </span>
                <input
                    v-model="next"
                    type="password"
                    autocomplete="new-password"
                    :placeholder="t('backend.studio.spaces.settings.new_placeholder')"
                    class="w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-primary"
                >
                <span class="block text-xs text-muted">
                    {{ t("backend.studio.spaces.settings.password_hint", { count: minLength }) }}
                </span>
            </label>

            <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                <AppButton
                    v-if="locked"
                    class="w-full sm:w-auto"
                    variant="ghost"
                    size="md"
                    :loading="saving"
                    v-on:click="clear"
                >
                    <LockOpen class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("backend.studio.spaces.settings.drive_clear") }}
                </AppButton>

                <AppButton
                    class="w-full sm:w-auto"
                    variant="primary"
                    size="md"
                    :loading="saving"
                    :disabled="!next"
                    v-on:click="save"
                >
                    <Save class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t(locked ? "backend.studio.spaces.settings.drive_change" : "backend.studio.spaces.settings.drive_set") }}
                </AppButton>
            </div>
        </div>

        <!-- Dit avant l'oubli plutôt qu'après : c'est la contrepartie assumée
             d'une serrure qui ne s'enlève pas sans son mot de passe. -->
        <p class="text-xs text-muted">{{ t("backend.studio.spaces.settings.drive_recovery") }}</p>
    </section>
</template>
