<script setup>
/**
 * The Drive section of a space's Settings tab, open to the lead: the client's
 * folder, the agency's, and the password that locks the Drive view. The tab
 * around it ({@see SpaceSettingsView}) draws the sections.
 *
 * The password is never read back from the server, only set or removed. The
 * state therefore comes down to a boolean: locked, or open.
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
    /** The agency's folder, shared by every space; null if not chosen. */
    agencyFolderId: { type: String, default: null },
    /** Where to save it, for whoever controls the configuration; null otherwise. */
    agencyFolderPath: { type: String, default: null },
    /** The address to share a folder with, to copy in the dialog. */
    serviceAccountEmail: { type: String, default: null },
});

const emit = defineEmits(["locked-changed", "folder-changed", "agency-folder-changed"]);

const { t } = useI18n();
const { request } = useRequest();
const { copy } = useClipboard();

/** Connect a folder, in the order it is done: share, paste, find again. */
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
 * The two inputs differ.
 *
 * Flagged while typing and not on the server's refusal: the server only sees
 * one password, it is here that we know there had to be two identical ones.
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

    // The two things the bar must know without a reload: that the Drive view
    // asks for the password, and that a folder exists.
    emit("locked-changed", locked.value);
    emit("folder-changed", folder.value);
}

onMounted(async () => {
    try {
        // `.settings` and not the whole response: `request` returns the
        // envelope, and `apply` reads the content. Given the envelope,
        // everything is `undefined` and the screen announces an open door on
        // a locked space.
        const data = await request(props.settingsPath, null, { method: HttpMethod.Get, noGuard: true });
        apply(data?.settings);
    } finally {
        loading.value = false;
    }
});

/**
 * A submission, its response, and the refusal said out loud.
 *
 * **The refusal is the missing half.** `request` returns a 400's envelope
 * without announcing anything; the three calls only read its success. A
 * wrong or too short password or a badly pasted folder then produced nothing
 * at all on screen, which reads as a dead button.
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

/** A folder's address in Google Drive, from its id. */
function folderUrl(id) {
    return id ? `https://drive.google.com/drive/folders/${encodeURIComponent(id)}` : "";
}

// ── The two folders, set by the same dialog ─────────────────────────────────
// The client's belongs to the space; the agency's is shared by all and
// lives in the Drive configuration, but is set from here because this is
// where you see it is missing. The same gesture, so the same dialog: `null`
// when it is closed, otherwise the folder it sets.
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

// ── The password ──────────────────────────────────────────────────────────────
// The state first, the form next: people mostly come here to read whether
// the tab is locked, rarely to change the key. The form opens on demand, and
// closes again once the gesture is done.
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
 * Ask everyone for the password again.
 *
 * No input, unlike removal: this gesture grants access to nothing.
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

        <!-- Two blocks: what applies only to this space, then what applies
             to all. Side by side on a large screen, where a single column
             left the right half empty or stretched the explanations over
             1 200 px; one under the other, below a rule, elsewhere. -->
        <div class="grid grid-cols-1 items-start gap-8 lg:grid-cols-2 lg:gap-6">
            <section class="flex flex-col gap-3">
                <h2 class="m-0 text-xs font-semibold uppercase tracking-wider text-muted">
                    {{ t("suite.studio.spaces.settings.group_space") }}
                </h2>

                <!-- The folder first: without it, the password locks an empty
                     room. -->
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

                    <!-- Locked: locking again everywhere needs no input, and
                         stays within reach; the rest opens the form. -->
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
                        <!-- The old password is only asked for if there is
                             one. A required empty field on an open door would
                             have nothing to check. -->
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

                        <!-- The confirmation, because a password nobody reads
                             back is mistyped one time in ten, and here the
                             typo locks a door nobody has the key to. -->
                        <AppInput
                            :model-value="confirm"
                            type="password"
                            toggleable
                            :label="t('suite.studio.spaces.settings.confirm_password')"
                            :placeholder="t('suite.studio.spaces.settings.confirm_placeholder')"
                            :error="mismatch ? t('suite.studio.spaces.settings.errors.password_mismatch') : ''"
                            v-on:update:model-value="confirm = $event"
                        />

                        <!-- The two consequences that would otherwise be found out too late. -->
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

            <!-- What applies to every space, below a rule: setting it here
                 changes it everywhere, and the screen must say so before the
                 button rather than after. -->
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

                <!-- The how-to, next to the settings rather than in separate
                     help: connecting a folder starts with sharing on Google's
                     side, which nothing here does in the client's place. The
                     address to give is at hand, with a way to copy it,
                     without opening the dialog. -->
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
