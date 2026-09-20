<script setup>
/**
 * Les réglages d'un espace, ouverts au référent.
 *
 * **Des sous-onglets, dès le premier sujet.** Un écran de réglages grandit
 * toujours, et il grandit mal : les champs s'empilent et on finit par chercher
 * le sien dans une colonne de vingt. Un onglet par sujet coûte trois lignes
 * aujourd'hui et évite la refonte de demain.
 *
 * Le mot de passe n'est jamais relu depuis le serveur, seulement posé ou
 * retiré. L'état se résume donc à un booléen : fermé, ou ouvert.
 */
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { FolderOpen, KeyRound, Lock, LockOpen, Save } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

const props = defineProps({
    settingsPath: { type: String, required: true },
});

const emit = defineEmits(["locked-changed", "folder-changed"]);

const { t } = useI18n();
const { request } = useRequest();

const loading = ref(true);
const saving = ref(false);
const locked = ref(false);
const minLength = ref(8);
const folder = ref("");

const current = ref("");
const next = ref("");

/**
 * Un onglet par sujet. Le Drive est le seul aujourd'hui ; la barre n'est
 * dessinée qu'à partir du second, parce qu'un sélecteur à un choix est un
 * ornement.
 */
const SECTIONS = [{ key: "drive", labelKey: "backend.studio.spaces.settings.section_drive", icon: FolderOpen }];

const section = ref(SECTIONS[0].key);
const sections = computed(() => SECTIONS);

function apply(settings) {
    if (!settings) return;

    locked.value = true === settings.driveLocked;
    minLength.value = settings.minPasswordLength ?? 8;
    folder.value = settings.driveFolderId ?? "";
    current.value = "";
    next.value = "";

    // Les deux choses que la barre doit savoir sans rechargement : que la vue
    // Drive demande le mot de passe, et qu'un dossier existe.
    emit("locked-changed", locked.value);
    emit("folder-changed", folder.value);
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

async function saveFolder() {
    saving.value = true;

    try {
        const data = await request(`${props.settingsPath}/drive-folder`, { folder: folder.value.trim() });

        if (data?.settings) {
            apply(data.settings);
            toast.success(t(data.settings.driveFolderId
                ? "backend.studio.spaces.settings.folder_saved"
                : "backend.studio.spaces.settings.folder_cleared"));
        }
    } finally {
        saving.value = false;
    }
}

async function savePassword() {
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

async function clearPassword() {
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

        <!-- Dessinée à partir du second sujet : un sélecteur à un choix
             n'aide personne à choisir. -->
        <div
            v-if="sections.length > 1"
            class="inline-flex max-w-full gap-1 overflow-x-auto rounded-lg border border-line bg-surface-2 p-1"
            role="group"
        >
            <AppTab
                v-for="entry in sections"
                :key="entry.key"
                size="sm"
                :active="section === entry.key"
                active-class="bg-surface text-primary shadow-sm"
                inactive-class="text-secondary hover:text-primary"
                class="whitespace-nowrap"
                v-on:click="section = entry.key"
            >
                <component :is="entry.icon" class="h-4 w-4 shrink-0" :stroke-width="2" />
                {{ t(entry.labelKey) }}
            </AppTab>
        </div>

        <template v-if="'drive' === section">
            <!-- Le dossier d'abord : sans lui, le mot de passe ferme une
                 pièce vide. -->
            <section class="space-y-3 rounded-lg border border-line bg-surface-2 p-3 sm:p-4">
                <header class="space-y-1">
                    <h3 class="flex items-center gap-2 text-sm font-medium text-primary">
                        <FolderOpen class="h-4 w-4 shrink-0" :stroke-width="2" />
                        {{ t("backend.studio.spaces.settings.folder_title") }}
                    </h3>
                    <p class="text-xs text-muted">{{ t("backend.studio.spaces.settings.folder_intro") }}</p>
                </header>

                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <input
                        v-model="folder"
                        type="text"
                        spellcheck="false"
                        placeholder="https://drive.google.com/drive/folders/…"
                        class="min-w-0 flex-1 rounded-lg border border-line bg-surface px-3 py-2 text-sm text-primary"
                    >
                    <AppButton
                        class="w-full shrink-0 sm:w-auto"
                        variant="primary"
                        size="sm"
                        :loading="saving"
                        v-on:click="saveFolder"
                    >
                        <Save class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.save") }}
                    </AppButton>
                </div>

                <p class="text-xs text-muted">{{ t("backend.studio.spaces.settings.folder_hint") }}</p>
            </section>

            <section class="space-y-3 rounded-lg border border-line bg-surface-2 p-3 sm:p-4">
                <header class="space-y-1">
                    <h3 class="flex items-center gap-2 text-sm font-medium text-primary">
                        <KeyRound class="h-4 w-4 shrink-0" :stroke-width="2" />
                        {{ t("backend.studio.spaces.settings.drive_title") }}
                    </h3>
                    <p class="text-xs text-muted">{{ t("backend.studio.spaces.settings.drive_intro") }}</p>
                </header>

                <p class="flex items-center gap-2 text-sm text-primary">
                    <component :is="locked ? Lock : LockOpen" class="h-4 w-4 shrink-0" :stroke-width="2" />
                    {{ t(locked ? "backend.studio.spaces.settings.drive_locked" : "backend.studio.spaces.settings.drive_open") }}
                </p>

                <!-- L'ancien mot de passe n'est demandé que s'il y en a un. Un
                     champ vide obligatoire sur une porte ouverte n'aurait rien
                     à vérifier. -->
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
                        v-on:click="clearPassword"
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
                        v-on:click="savePassword"
                    >
                        <Save class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t(locked ? "backend.studio.spaces.settings.drive_change" : "backend.studio.spaces.settings.drive_set") }}
                    </AppButton>
                </div>

                <!-- Les deux conséquences qu'on découvrirait sinon trop tard. -->
                <p class="text-xs text-muted">{{ t("backend.studio.spaces.settings.drive_closes_now") }}</p>
                <p class="text-xs text-muted">{{ t("backend.studio.spaces.settings.drive_recovery") }}</p>
            </section>
        </template>
    </section>
</template>
