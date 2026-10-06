<script setup>
/**
 * The Drive section of a space's Settings tab, open to the lead: the client's
 * folder, the agency's, and the password that locks the Drive view. The tab
 * around it ({@see SpaceSettingsView}) draws the sections.
 *
 * Le mot de passe n'est jamais relu depuis le serveur, seulement posé ou
 * retiré. L'état se résume donc à un booléen : fermé, ou ouvert.
 */
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Building2, Copy, ExternalLink, FolderOpen, KeyRound, Lock, LockOpen, Pencil, RotateCcw, Save, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import DriveFolderModal from "./DriveFolderModal.vue";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { useClipboard } from "@/shared/composables/useClipboard.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

const props = defineProps({
    settingsPath: { type: String, required: true },
    /** Le dossier de l'agence, commun à tous les espaces ; nul s'il n'est pas choisi. */
    agencyFolderId: { type: String, default: null },
    /** Où l'enregistrer, pour qui a la main sur la configuration ; nul sinon. */
    agencyFolderPath: { type: String, default: null },
    /** L'adresse avec laquelle partager un dossier, à copier dans la fenêtre. */
    serviceAccountEmail: { type: String, default: null },
});

const emit = defineEmits(["locked-changed", "folder-changed", "agency-folder-changed"]);

const { t } = useI18n();
const { request } = useRequest();
const { copy } = useClipboard();

/** Brancher un dossier, dans l'ordre où ça se fait : partager, coller, retrouver. */
const howtoSteps = ["share", "paste", "find"];

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

/** L'adresse d'un dossier dans Google Drive, depuis son identifiant. */
function folderUrl(id) {
    return id ? `https://drive.google.com/drive/folders/${encodeURIComponent(id)}` : "";
}

// ── Les deux dossiers, réglés par la même fenêtre ───────────────────────────
// Celui du client appartient à l'espace ; celui de l'agence est commun à tous
// et vit dans la configuration du Drive, mais se règle d'ici parce que c'est
// ici qu'on voit qu'il manque. Le même geste, donc la même fenêtre : `null`
// quand elle est fermée, sinon le dossier qu'elle règle.
const { request: agencyRequest } = useRequest();
const agencyFolder = ref(props.agencyFolderId);
const editing = ref(null);
const folderError = ref("");
const folderSaving = ref(false);

const clientFolderUrl = computed(() => folderUrl(folder.value));
const agencyFolderUrl = computed(() => folderUrl(agencyFolder.value));

const modal = computed(() => {
    if ("client" === editing.value) {
        return {
            title: t("suite.studio.spaces.settings.folder_modal_title"),
            intro: t("suite.studio.spaces.settings.folder_modal_intro"),
            currentUrl: clientFolderUrl.value,
        };
    }

    return {
        title: t("suite.studio.spaces.settings.agency_modal_title"),
        intro: t("suite.studio.spaces.settings.agency_modal_intro"),
        currentUrl: agencyFolderUrl.value,
    };
});

function openFolder(which) {
    folderError.value = "";
    editing.value = which;
}

async function saveEditedFolder(value) {
    if (folderSaving.value) return;

    folderSaving.value = true;
    folderError.value = "";
    try {
        const ok = "client" === editing.value ? await saveClientFolder(value.trim()) : await saveAgencyFolder(value.trim());
        if (ok) editing.value = null;
    } finally {
        folderSaving.value = false;
    }
}

async function saveClientFolder(value) {
    const data = await request(`${props.settingsPath}/drive-folder`, { folder: value });
    if (!data) return false;
    if (!data.success) {
        folderError.value = t(data.error ?? "shared.common.error");

        return false;
    }

    apply(data.settings);
    toast.success(t(data.settings?.driveFolderId
        ? "suite.studio.spaces.settings.folder_saved"
        : "suite.studio.spaces.settings.folder_cleared"));

    return true;
}

async function saveAgencyFolder(value) {
    if (!props.agencyFolderPath) return false;

    const data = await agencyRequest(props.agencyFolderPath, { agencyFolderId: value });
    if (!data) return false;
    if (!data.success) {
        folderError.value = t(data.error ?? "shared.common.error");

        return false;
    }

    agencyFolder.value = data.agencyFolderId ?? null;
    emit("agency-folder-changed", agencyFolder.value);
    toast.success(t(agencyFolder.value
        ? "suite.studio.spaces.settings.agency_saved"
        : "suite.studio.spaces.settings.agency_cleared"));

    return true;
}

// ── Le mot de passe ──────────────────────────────────────────────────────────
// L'état d'abord, le formulaire ensuite : on vient ici le plus souvent pour
// lire si l'onglet est fermé, rarement pour changer la clé. Le formulaire
// s'ouvre à la demande, et se referme une fois le geste fait.
const passwordFormOpen = ref(false);

function closePasswordForm() {
    passwordFormOpen.value = false;
    current.value = "";
    next.value = "";
    confirm.value = "";
}

async function savePassword() {
    const settings = await submit("/drive-password", {
        password: next.value,
        currentPassword: current.value,
    });

    if (!settings) return;

    toast.success(t("suite.studio.spaces.settings.drive_saved"));
    passwordFormOpen.value = false;
}

/**
 * Redemander le mot de passe à tout le monde.
 *
 * Aucune saisie, contrairement au retrait : ce geste ne donne accès à rien.
 */
async function revokeSessions() {
    const settings = await submit("/drive-revoke", {});

    if (settings) toast.success(t("suite.studio.spaces.settings.drive_revoked"));
}

async function clearPassword() {
    const settings = await submit("/drive-password/clear", { currentPassword: current.value });

    if (!settings) return;

    toast.success(t("suite.studio.spaces.settings.drive_cleared"));
    passwordFormOpen.value = false;
}
</script>

<template>
    <section class="relative aurora-stack">
        <AppLoader :active="loading" />

        <!-- Deux blocs : ce qui ne vaut que pour cet espace, puis ce qui vaut
             pour tous. Côte à côte sur un grand écran, où une seule colonne
             laissait la moitié droite vide ou étirait les explications sur
             1 200 px ; l'un sous l'autre, sous un filet, ailleurs. -->
        <div class="grid grid-cols-1 items-start gap-8 lg:grid-cols-2 lg:gap-6">
            <section class="flex flex-col gap-3">
                <h2 class="m-0 text-xs font-semibold uppercase tracking-wider text-muted">
                    {{ t("suite.studio.spaces.settings.group_space") }}
                </h2>

                <!-- Le dossier d'abord : sans lui, le mot de passe ferme une
                     pièce vide. -->
                <article class="flex flex-col gap-3 rounded-lg border border-line bg-surface-2 p-3 sm:p-4">
                    <div class="flex flex-col gap-1">
                        <header class="flex flex-wrap items-start justify-between gap-2">
                            <h3 class="m-0 flex items-center gap-2 text-sm font-medium text-primary">
                                <FolderOpen class="h-4 w-4 shrink-0" :stroke-width="2" />
                                {{ t("suite.studio.spaces.settings.folder_title") }}
                            </h3>
                            <AppBadge :color="folder ? 'emerald' : 'gray'">
                                {{ t(folder ? "suite.studio.spaces.settings.state_linked" : "suite.studio.spaces.settings.state_none") }}
                            </AppBadge>
                        </header>
                        <p class="m-0 text-xs text-muted">{{ t("suite.studio.spaces.settings.folder_intro") }}</p>
                    </div>

                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <a
                            v-if="clientFolderUrl"
                            class="inline-flex min-w-0 items-center gap-1.5 text-xs font-medium text-accent no-underline hover:underline"
                            :href="clientFolderUrl"
                            target="_blank"
                            rel="noopener"
                        >
                            <ExternalLink class="h-3.5 w-3.5 shrink-0" :stroke-width="2" />
                            <span class="truncate">{{ t("suite.studio.spaces.settings.open_in_drive") }}</span>
                        </a>
                        <AppButton class="w-full sm:ml-auto sm:w-auto" variant="secondary" size="sm" v-on:click="openFolder('client')">
                            <Pencil class="h-3.5 w-3.5" :stroke-width="2" />
                            {{ t(folder ? "suite.studio.spaces.settings.folder_change" : "suite.studio.spaces.settings.folder_choose") }}
                        </AppButton>
                    </div>
                </article>

                <article class="flex flex-col gap-3 rounded-lg border border-line bg-surface-2 p-3 sm:p-4">
                    <div class="flex flex-col gap-1">
                        <header class="flex flex-wrap items-start justify-between gap-2">
                            <h3 class="m-0 flex items-center gap-2 text-sm font-medium text-primary">
                                <KeyRound class="h-4 w-4 shrink-0" :stroke-width="2" />
                                {{ t("suite.studio.spaces.settings.drive_title") }}
                            </h3>
                            <AppBadge :color="locked ? 'amber' : 'gray'">
                                <span class="inline-flex items-center gap-1">
                                    <component :is="locked ? Lock : LockOpen" class="h-3 w-3" :stroke-width="2" />
                                    {{ t(locked ? "suite.studio.spaces.settings.state_locked" : "suite.studio.spaces.settings.state_open") }}
                                </span>
                            </AppBadge>
                        </header>
                        <p class="m-0 text-xs text-muted">{{ t("suite.studio.spaces.settings.drive_intro") }}</p>
                    </div>

                    <!-- Fermé : refermer partout se fait sans rien saisir, et
                         reste à portée ; le reste ouvre le formulaire. -->
                    <div v-if="!passwordFormOpen" class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                        <AppButton
                            v-if="locked"
                            class="w-full sm:w-auto"
                            variant="ghost"
                            size="sm"
                            :loading="saving"
                            v-on:click="revokeSessions"
                        >
                            <RotateCcw class="h-3.5 w-3.5" :stroke-width="2" />
                            {{ t("suite.studio.spaces.settings.drive_revoke") }}
                        </AppButton>
                        <AppButton class="w-full sm:w-auto" variant="secondary" size="sm" v-on:click="passwordFormOpen = true">
                            <component :is="locked ? Pencil : Lock" class="h-3.5 w-3.5" :stroke-width="2" />
                            {{ t(locked ? "suite.studio.spaces.settings.password_manage" : "suite.studio.spaces.settings.password_close_tab") }}
                        </AppButton>
                    </div>
                    <p v-if="!passwordFormOpen && locked" class="m-0 text-xs text-muted">{{ t("suite.studio.spaces.settings.drive_revoke_hint") }}</p>

                    <div v-if="passwordFormOpen" class="flex flex-col gap-3 border-t border-line/60 pt-3">
                        <!-- L'ancien mot de passe n'est demandé que s'il y en a
                             un. Un champ vide obligatoire sur une porte ouverte
                             n'aurait rien à vérifier. -->
                        <AppInput
                            v-if="locked"
                            :model-value="current"
                            type="password"
                            toggleable
                            :label="t('suite.studio.spaces.settings.current_password')"
                            :placeholder="t('suite.studio.spaces.settings.current_placeholder')"
                            v-on:update:model-value="current = $event"
                        />

                        <AppInput
                            :model-value="next"
                            type="password"
                            toggleable
                            :label="t(locked ? 'suite.studio.spaces.settings.new_password' : 'suite.studio.spaces.settings.password')"
                            :placeholder="t('suite.studio.spaces.settings.new_placeholder')"
                            :hint="t('suite.studio.spaces.settings.password_hint', { count: minLength })"
                            v-on:update:model-value="next = $event"
                        />

                        <!-- La confirmation, parce qu'un mot de passe qu'on ne
                             relit pas se tape de travers une fois sur dix, et
                             qu'ici la faute de frappe ferme une porte dont
                             personne n'a la clé. -->
                        <AppInput
                            :model-value="confirm"
                            type="password"
                            toggleable
                            :label="t('suite.studio.spaces.settings.confirm_password')"
                            :placeholder="t('suite.studio.spaces.settings.confirm_placeholder')"
                            :error="mismatch ? t('suite.studio.spaces.settings.errors.password_mismatch') : ''"
                            v-on:update:model-value="confirm = $event"
                        />

                        <!-- Les deux conséquences qu'on découvrirait sinon trop tard. -->
                        <p class="m-0 text-xs text-muted">{{ t("suite.studio.spaces.settings.drive_closes_now") }}</p>
                        <p class="m-0 text-xs text-muted">{{ t("suite.studio.spaces.settings.drive_recovery") }}</p>

                        <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                            <AppButton
                                v-if="locked"
                                class="w-full sm:mr-auto sm:w-auto"
                                variant="ghost"
                                size="md"
                                :loading="saving"
                                v-on:click="clearPassword"
                            >
                                <LockOpen class="h-3.5 w-3.5" :stroke-width="2" />
                                {{ t("suite.studio.spaces.settings.drive_clear") }}
                            </AppButton>
                            <AppButton class="w-full sm:w-auto" variant="ghost" size="md" v-on:click="closePasswordForm">
                                <X class="h-3.5 w-3.5" :stroke-width="2" />
                                {{ t("shared.common.cancel") }}
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
                                {{ t(locked ? "suite.studio.spaces.settings.drive_change" : "suite.studio.spaces.settings.drive_set") }}
                            </AppButton>
                        </div>
                    </div>
                </article>
            </section>

            <!-- Ce qui vaut pour tous les espaces, sous un filet : le régler
                 ici le change partout, et l'écran doit le dire avant le
                 bouton plutôt qu'après. -->
            <section class="flex flex-col gap-3 border-t border-line pt-8 lg:border-t-0 lg:pt-0">
                <h2 class="m-0 text-xs font-semibold uppercase tracking-wider text-muted">
                    {{ t("suite.studio.spaces.settings.group_all") }}
                </h2>

                <article class="flex flex-col gap-3 rounded-lg border border-line bg-surface-2 p-3 sm:p-4">
                    <div class="flex flex-col gap-1">
                        <header class="flex flex-wrap items-start justify-between gap-2">
                            <h3 class="m-0 flex items-center gap-2 text-sm font-medium text-primary">
                                <Building2 class="h-4 w-4 shrink-0" :stroke-width="2" />
                                {{ t("suite.studio.spaces.settings.agency_title") }}
                            </h3>
                            <div class="flex flex-wrap gap-1.5">
                                <AppBadge color="violet">{{ t("suite.studio.spaces.settings.badge_all_spaces") }}</AppBadge>
                                <AppBadge :color="agencyFolder ? 'emerald' : 'gray'">
                                    {{ t(agencyFolder ? "suite.studio.spaces.settings.state_linked" : "suite.studio.spaces.settings.state_none") }}
                                </AppBadge>
                            </div>
                        </header>
                        <p class="m-0 text-xs text-muted">
                            {{ t(agencyFolder ? "suite.studio.spaces.settings.agency_set" : "suite.studio.spaces.settings.agency_unset") }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <a
                            v-if="agencyFolderUrl"
                            class="inline-flex min-w-0 items-center gap-1.5 text-xs font-medium text-accent no-underline hover:underline"
                            :href="agencyFolderUrl"
                            target="_blank"
                            rel="noopener"
                        >
                            <ExternalLink class="h-3.5 w-3.5 shrink-0" :stroke-width="2" />
                            <span class="truncate">{{ t("suite.studio.spaces.settings.open_in_drive") }}</span>
                        </a>
                        <AppButton
                            v-if="agencyFolderPath"
                            class="w-full sm:ml-auto sm:w-auto"
                            variant="secondary"
                            size="sm"
                            v-on:click="openFolder('agency')"
                        >
                            <Pencil class="h-3.5 w-3.5" :stroke-width="2" />
                            {{ t(agencyFolder ? "suite.studio.spaces.settings.folder_change" : "suite.studio.spaces.settings.agency_choose") }}
                        </AppButton>
                    </div>
                    <p v-if="!agencyFolderPath" class="m-0 text-xs text-muted">{{ t("suite.studio.spaces.settings.agency_ask_admin") }}</p>
                </article>

                <!-- Le mode d'emploi, à côté des réglages plutôt que dans une
                     aide à part : brancher un dossier commence par un partage
                     côté Google, que rien ici ne fait à la place du client.
                     L'adresse à donner est sous la main, avec de quoi la
                     copier, sans ouvrir la fenêtre. -->
                <AppGuide :title="t('suite.studio.spaces.settings.howto_title')" storage-key="space-drive-howto">
                    <ol class="m-0 flex list-none flex-col gap-3 p-0">
                        <li v-for="(step, index) in howtoSteps" :key="step" class="flex gap-3">
                            <span
                                class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-surface-3 text-[11px] font-semibold text-secondary tabular-nums"
                                aria-hidden="true"
                            >{{ index + 1 }}</span>
                            <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                                <p class="m-0 text-xs text-secondary">{{ t(`suite.studio.spaces.settings.howto_${'share' === step && !serviceAccountEmail ? 'share_unknown' : step}`) }}</p>
                                <div v-if="'share' === step && serviceAccountEmail" class="flex items-center gap-2">
                                    <code class="min-w-0 flex-1 truncate rounded bg-surface-2 px-2 py-1 font-mono text-xs text-primary">{{ serviceAccountEmail }}</code>
                                    <AppButton
                                        variant="secondary"
                                        size="sm"
                                        :label="t('suite.studio.spaces.settings.copy_email')"
                                        icon-only-on-phone
                                        v-on:click="copy(serviceAccountEmail, 'suite.studio.spaces.settings.email_copied')"
                                    >
                                        <Copy class="h-3.5 w-3.5" :stroke-width="2" />
                                    </AppButton>
                                </div>
                            </div>
                        </li>
                    </ol>
                </AppGuide>
            </section>

            <DriveFolderModal
                :show="null !== editing"
                :title="modal.title"
                :intro="modal.intro"
                :current-url="modal.currentUrl"
                :service-account-email="serviceAccountEmail"
                :error="folderError"
                :saving="folderSaving"
                v-on:close="editing = null"
                v-on:save="saveEditedFolder"
                v-on:unlink="saveEditedFolder('')"
            />
        </div>
    </section>
</template>
