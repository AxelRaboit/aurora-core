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
import { FolderOpen, KeyRound, Lock, LockOpen, RotateCcw, Save } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
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
const confirm = ref("");

/**
 * Les deux saisies diffèrent.
 *
 * Signalée dès la frappe et pas au refus du serveur : le serveur ne voit
 * qu'un mot de passe, c'est ici qu'on sait qu'il devait y en avoir deux
 * identiques.
 */
const mismatch = computed(() => "" !== confirm.value && confirm.value !== next.value);

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
    confirm.value = "";

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

/**
 * Un envoi, sa réponse, et le refus dit à voix haute.
 *
 * **Le refus est la moitié qui manquait.** `request` rend l'enveloppe d'un
 * 400 sans rien annoncer ; les trois appels n'en lisaient que le succès. Un
 * mot de passe erroné, trop court ou un dossier mal collé ne produisaient
 * alors rien du tout à l'écran, ce qui se lit comme un bouton mort.
 */
async function submit(suffix, payload) {
    saving.value = true;

    try {
        const data = await request(`${props.settingsPath}${suffix}`, payload);

        if (!data?.success) {
            toast.error(t(data?.error ?? "shared.common.error"));

            return null;
        }

        apply(data.settings);

        return data.settings;
    } finally {
        saving.value = false;
    }
}

async function saveFolder() {
    const settings = await submit("/drive-folder", { folder: folder.value.trim() });

    if (!settings) return;

    toast.success(t(settings.driveFolderId
        ? "backend.studio.spaces.settings.folder_saved"
        : "backend.studio.spaces.settings.folder_cleared"));
}

async function savePassword() {
    const settings = await submit("/drive-password", {
        password: next.value,
        currentPassword: current.value,
    });

    if (settings) toast.success(t("backend.studio.spaces.settings.drive_saved"));
}

/**
 * Redemander le mot de passe à tout le monde.
 *
 * Aucune saisie, contrairement au retrait : ce geste ne donne accès à rien.
 */
async function revokeSessions() {
    const settings = await submit("/drive-revoke", {});

    if (settings) toast.success(t("backend.studio.spaces.settings.drive_revoked"));
}

async function clearPassword() {
    const settings = await submit("/drive-password/clear", { currentPassword: current.value });

    if (settings) toast.success(t("backend.studio.spaces.settings.drive_cleared"));
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
                    <AppInput
                        class="min-w-0 flex-1"
                        :model-value="folder"
                        type="text"
                        placeholder="https://drive.google.com/drive/folders/…"
                        v-on:update:model-value="folder = $event"
                    />
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
                <!-- `AppInput` et non un champ brut : il porte déjà
                     l'étiquette, l'aide, l'erreur et l'œil qui dévoile la
                     saisie. Trois champs écrits à la main les auraient perdus
                     tous les quatre. -->
                <AppInput
                    v-if="locked"
                    :model-value="current"
                    type="password"
                    toggleable
                    :label="t('backend.studio.spaces.settings.current_password')"
                    :placeholder="t('backend.studio.spaces.settings.current_placeholder')"
                    v-on:update:model-value="current = $event"
                />

                <AppInput
                    :model-value="next"
                    type="password"
                    toggleable
                    :label="t(locked ? 'backend.studio.spaces.settings.new_password' : 'backend.studio.spaces.settings.password')"
                    :placeholder="t('backend.studio.spaces.settings.new_placeholder')"
                    :hint="t('backend.studio.spaces.settings.password_hint', { count: minLength })"
                    v-on:update:model-value="next = $event"
                />

                <!-- La confirmation, parce qu'un mot de passe qu'on ne relit
                     pas se tape de travers une fois sur dix, et qu'ici la
                     faute de frappe ferme une porte dont personne n'a la
                     clé. -->
                <AppInput
                    :model-value="confirm"
                    type="password"
                    toggleable
                    :label="t('backend.studio.spaces.settings.confirm_password')"
                    :placeholder="t('backend.studio.spaces.settings.confirm_placeholder')"
                    :error="mismatch ? t('backend.studio.spaces.settings.errors.password_mismatch') : ''"
                    v-on:update:model-value="confirm = $event"
                />

                <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                    <!-- Refermer partout, sans rien changer : le geste qu'on
                         cherche quand un écran est resté ouvert ailleurs, et
                         qu'on n'a aucune envie de choisir un nouveau mot de
                         passe pour ça. -->
                    <AppButton
                        v-if="locked"
                        class="w-full sm:w-auto"
                        variant="ghost"
                        size="md"
                        :loading="saving"
                        v-on:click="revokeSessions"
                    >
                        <RotateCcw class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("backend.studio.spaces.settings.drive_revoke") }}
                    </AppButton>

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
                        :disabled="!next || mismatch"
                        v-on:click="savePassword"
                    >
                        <Save class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t(locked ? "backend.studio.spaces.settings.drive_change" : "backend.studio.spaces.settings.drive_set") }}
                    </AppButton>
                </div>

                <!-- Les deux conséquences qu'on découvrirait sinon trop tard. -->
                <p class="text-xs text-muted">{{ t("backend.studio.spaces.settings.drive_closes_now") }}</p>
                <p v-if="locked" class="text-xs text-muted">{{ t("backend.studio.spaces.settings.drive_revoke_hint") }}</p>
                <p class="text-xs text-muted">{{ t("backend.studio.spaces.settings.drive_recovery") }}</p>
            </section>
        </template>
    </section>
</template>
