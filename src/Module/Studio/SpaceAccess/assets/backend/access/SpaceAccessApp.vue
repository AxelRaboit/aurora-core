<script setup>
/**
 * The addresses that open this space from outside.
 *
 * **The address is shown once, and the screen says so.** Only the request that
 * minted a link ever holds its secret - the database keeps a hash - so a copy
 * button on an older row would have nothing to copy. Rather than hide that
 * behind a disabled control, the freshly minted address gets a panel of its
 * own, and the list below carries what a list can honestly answer: who it went
 * to, whether it was opened, and whether it still works.
 *
 * Revoking and deleting both exist because they say different things. Revoking
 * closes an address and keeps the record; deleting is for the one sent to the
 * wrong mailbox thirty seconds ago, where the record is noise.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useDelete } from "@/shared/composables/form/useDelete.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useClipboard } from "@/shared/composables/useClipboard.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { required } from "@/shared/utils/validation/validators.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import { Ban, Copy, Eye, Link2, Trash2, X } from "lucide-vue-next";

const { t, d } = useI18n();
const { can } = usePrivileges();
const { request } = useRequest();
const { copy } = useClipboard();

const props = defineProps({
    space: { type: Object, required: true },
    links: { type: Array, default: () => [] },
    defaultValidDays: { type: Number, default: 90 },
    maxValidDays: { type: Number, default: 365 },
    issuePath: { type: String, required: true },
    revokePath: { type: String, required: true },
    deletePath: { type: String, required: true },
    /** Non nul quand l'espace a un dossier Drive branché. */
    driveFolderId: { type: String, default: null },
    /** Gabarit d'adresse de l'aperçu, `__id__` remplacé par le lien. */
    previewPath: { type: String, default: "" },
});

const canShare = computed(() => can("studio.spaces.share"));

const links = ref(props.links ?? []);

function applyLinks(data) {
    if (Array.isArray(data?.links)) links.value = data.links;
}

const showIssue = ref(false);
const issueForm = ref({
    recipientEmail: "",
    label: "",
    validForDays: props.defaultValidDays,
    canApprove: true,
    // Off, unlike the one above: sending a file writes bytes to our storage
    // from an address with no account behind it, so it is granted per link
    // rather than assumed.
    canUpload: false,
    canSeeDrive: true,
});

/** The one and only moment the address exists in readable form. */
const mintedUrl = ref("");

const {
    errors: issueErrors,
    loading: issueLoading,
    submit: submitIssue,
    clearErrors: clearIssue,
} = useFormAction({
    rules: () => ({
        recipientEmail: () =>
            required(t("backend.studio.space_access.errors.email_required"))(
                issueForm.value.recipientEmail,
            ),
    }),
    url: () => props.issuePath,
    body: () => issueForm.value,
    onSuccess: (data) => {
        showIssue.value = false;
        applyLinks(data);
        mintedUrl.value = data?.url ?? "";
        toast.success(t("backend.studio.space_access.issued"));
    },
});

function openIssue() {
    issueForm.value = {
        recipientEmail: "",
        label: "",
        validForDays: props.defaultValidDays,
        canApprove: true,
        canUpload: false,
        canSeeDrive: true,
    };
    clearIssue();
    showIssue.value = true;
}

const revoking = ref(null);

async function revoke(link) {
    revoking.value = link.id;
    try {
        const data = await request(buildPath(props.revokePath, { id: link.id }));
        applyLinks(data);
        toast.success(t("backend.studio.space_access.revoked"));
    } finally {
        revoking.value = null;
    }
}

const {
    pendingDelete,
    loading: deleteLoading,
    confirm: confirmDelete,
    submit: doDelete,
} = useDelete(
    props.deletePath,
    (id) => {
        links.value = links.value.filter((link) => link.id !== id);
    },
    "backend.studio.space_access.deleted",
);

/** Active, revoked or expired, which is what the row's badge says. */
function stateOf(link) {
    if (link.revoked) return "revoked";
    if (link.expired) return "expired";

    return "active";
}

function openedLabel(link) {
    if (!link.firstOpenedAt) {
        return t("backend.studio.space_access.never_opened");
    }

    return t("backend.studio.space_access.opened_on", {
        date: d(new Date(link.firstOpenedAt), "short"),
    });
}
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <p class="max-w-xl text-sm text-secondary">
                {{ t("backend.studio.space_access.intro") }}
            </p>
            <!-- Pleine largeur sur téléphone, comme partout ailleurs dans
                 l'espace : c'est le seul geste de la page, il n'a pas à se
                 serrer contre le bord droit. -->
            <AppButton
                v-if="canShare"
                class="w-full sm:w-auto"
                variant="primary"
                size="sm"
                v-on:click="openIssue"
            >
                <Link2 class="h-3.5 w-3.5" :stroke-width="2" />
                {{ t("backend.studio.space_access.issue") }}
            </AppButton>
        </div>

        <!-- The address, once. It is deliberately loud and deliberately not in
             the list: nothing can show it again, and a row that pretended it
             could would be a copy button with nothing behind it. -->
        <section
            v-if="mintedUrl"
            class="rounded-xl border border-accent-500/40 bg-accent-500/5 p-4"
        >
            <h2 class="text-sm font-medium text-primary">
                {{ t("backend.studio.space_access.minted_title") }}
            </h2>
            <p class="mt-1 text-xs text-secondary">
                {{ t("backend.studio.space_access.minted_hint") }}
            </p>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                <code
                    class="min-w-0 flex-1 truncate rounded-md border border-line bg-surface px-3 py-2 font-mono text-xs text-primary"
                >
                    {{ mintedUrl }}
                </code>
                <AppButton class="w-full sm:w-auto" variant="ghost" size="sm" v-on:click="copy(mintedUrl)">
                    <Copy class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("shared.common.copy") }}
                </AppButton>
                <AppButton class="w-full sm:w-auto" variant="ghost" size="sm" v-on:click="mintedUrl = ''">
                    <X class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("shared.common.close") }}
                </AppButton>
            </div>
        </section>

        <AppNoData
            v-if="!links.length"
            :message="t('backend.studio.space_access.empty')"
            :hint="t('backend.studio.space_access.empty_hint')"
        />

        <ul v-else class="divide-y divide-line/40 rounded-xl border border-line/60 bg-surface">
            <!-- **Empilé sur téléphone.** Le nom, l'adresse, l'état et les
                 deux gestes tenaient sur une ligne qui repliait chaque mot :
                 « Camille, g… », « Jamais ouvert · Expire » sur cinq lignes, et
                 « Révoquer » coincé entre les deux. En colonne, chaque chose a
                 sa ligne et la largeur qui va avec ; côte à côte dès qu'il y a
                 la place. -->
            <li
                v-for="link in links"
                :key="link.id"
                class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-4"
            >
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-primary">
                        {{ link.label || link.recipientEmail }}
                    </p>
                    <p v-if="link.label" class="truncate text-xs text-muted">
                        {{ link.recipientEmail }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted">
                        {{ openedLabel(link) }}
                        ·
                        {{
                            t("backend.studio.space_access.expires_on", {
                                date: d(new Date(link.expiresAt), "short"),
                            })
                        }}
                        <template v-if="!link.canApprove">
                            · {{ t("backend.studio.space_access.read_only") }}
                        </template>
                        <template v-if="link.canUpload">
                            · {{ t("backend.studio.space_access.may_upload") }}
                        </template>
                        <!-- Dit seulement quand c'est retiré : la liste
                             nomme ce qui sort de l'ordinaire, pas ce qui est
                             le cas pour tous les liens. -->
                        <template v-if="false === link.canSeeDrive">
                            · {{ t("backend.studio.space_access.no_drive") }}
                        </template>
                    </p>
                </div>

                <span
                    class="w-fit shrink-0 rounded-full px-2 py-0.5 text-xs"
                    :class="{
                        'bg-emerald-500/10 text-emerald-500': stateOf(link) === 'active',
                        'bg-surface-2 text-muted': stateOf(link) !== 'active',
                    }"
                >
                    {{ t(`backend.studio.space_access.states.${stateOf(link)}`) }}
                </span>

                <!-- **Deux gestes, une même forme.** L'un était un mot sans
                 contour, l'autre une corbeille sans mot : côte à côte ils ne
                 se ressemblaient pas assez pour se lire comme le couple qu'ils
                 sont, et sur téléphone le second n'offrait qu'une cible de
                 vingt-six pixels. Chacun porte maintenant son icône, son nom
                 et son cadre, et ils se partagent la ligne. -->
                <div v-if="canShare" class="flex w-full shrink-0 items-center gap-2 sm:w-auto">
                    <!-- Voir avant d'envoyer, et après avoir changé un
                         réglage. Sans lui, la seule façon de savoir ce qu'un
                         lien montre est de l'ouvrir dans une fenêtre privée,
                         et un réglage qu'on ne peut pas vérifier d'un coup
                         d'œil cesse d'être utilisé. -->
                    <AppButton
                        v-if="previewPath && link.usable"
                        class="flex-1 sm:flex-none"
                        variant="ghost"
                        size="sm"
                        :href="buildPath(previewPath, { id: link.id })"
                        target="_blank"
                    >
                        <Eye class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("backend.studio.space_access.preview") }}
                    </AppButton>
                    <AppButton
                        v-if="link.usable"
                        class="flex-1 sm:flex-none"
                        variant="secondary"
                        size="sm"
                        :loading="revoking === link.id"
                        v-on:click="revoke(link)"
                    >
                        <Ban class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("backend.studio.space_access.revoke") }}
                    </AppButton>
                    <AppButton
                        class="flex-1 sm:flex-none"
                        variant="danger-outline"
                        size="sm"
                        v-on:click="confirmDelete(link)"
                    >
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.delete") }}
                    </AppButton>
                </div>
            </li>
        </ul>

        <AppModal
            :show="showIssue"
            max-width="lg"
            :title="t('backend.studio.space_access.issue')"
            :icon="Link2"
            :closeable="false"
            v-on:close="showIssue = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitIssue">
                <AppInput
                    :model-value="issueForm.recipientEmail"
                    type="email"
                    :label="t('backend.studio.space_access.recipient_email')"
                    :placeholder="t('backend.studio.space_access.recipient_email_placeholder')"
                    :hint="t('backend.studio.space_access.recipient_email_hint')"
                    :error="issueErrors.recipientEmail"
                    required
                    v-on:update:model-value="issueForm.recipientEmail = $event"
                />
                <AppInput
                    :model-value="issueForm.label"
                    :label="t('backend.studio.space_access.label')"
                    :placeholder="t('backend.studio.space_access.label_placeholder')"
                    :hint="t('backend.studio.space_access.label_hint')"
                    :error="issueErrors.label"
                    v-on:update:model-value="issueForm.label = $event"
                />
                <AppInput
                    :model-value="String(issueForm.validForDays)"
                    type="number"
                    :label="t('backend.studio.space_access.valid_for_days')"
                    :placeholder="String(defaultValidDays)"
                    :hint="
                        t('backend.studio.space_access.valid_for_days_hint', {
                            max: maxValidDays,
                        })
                    "
                    :error="issueErrors.validForDays"
                    v-on:update:model-value="issueForm.validForDays = Number($event)"
                />
                <AppCheckbox
                    :model-value="issueForm.canApprove"
                    :label="t('backend.studio.space_access.can_approve')"
                    :hint="t('backend.studio.space_access.can_approve_hint')"
                    v-on:update:model-value="issueForm.canApprove = $event"
                />
                <AppCheckbox
                    :model-value="issueForm.canUpload"
                    :label="t('backend.studio.space_access.can_upload')"
                    :hint="t('backend.studio.space_access.can_upload_hint')"
                    v-on:update:model-value="issueForm.canUpload = $event"
                />

                <!-- Offerte seulement quand l'espace a un dossier branché :
                     une case qui ne gouverne rien se lit comme cassée. -->
                <AppCheckbox
                    v-if="driveFolderId"
                    :model-value="false !== issueForm.canSeeDrive"
                    :label="t('backend.studio.space_access.can_see_drive')"
                    :hint="t('backend.studio.space_access.can_see_drive_hint')"
                    v-on:update:model-value="issueForm.canSeeDrive = $event"
                />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showIssue = false">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="primary"
                        size="md"
                        :loading="issueLoading"
                        v-on:click="submitIssue"
                    >
                        <Link2 class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("backend.studio.space_access.issue") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="!!pendingDelete"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="pendingDelete = null"
        >
            <p class="text-sm text-primary">
                {{
                    t("backend.studio.space_access.delete_confirm", {
                        name: pendingDelete?.label || pendingDelete?.recipientEmail || "",
                    })
                }}
            </p>
            <p class="text-sm text-secondary">
                {{ t("backend.studio.space_access.delete_warning") }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDelete = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="danger"
                        size="md"
                        :loading="deleteLoading"
                        v-on:click="doDelete"
                    >
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
