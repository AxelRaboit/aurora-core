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
 * **The short address can be shown again** (10/10/2026): `/c/fournier-k7m2q9`
 * opens the same page with the same rights, and its name is kept, so the row
 * carries it with a copy button. Giving a new one retires the old.
 *
 * Revoking and deleting both exist because they say different things. Revoking
 * closes an address and keeps the record; deleting is for the one sent to the
 * wrong mailbox thirty seconds ago, where the record is noise.
 */
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useDelete } from "@/shared/composables/form/useDelete.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { useClipboard } from "@/shared/composables/useClipboard.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { required } from "@/shared/utils/validation/validators.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import { Ban, CalendarPlus, Copy, Eye, Link, Link2, Mail, Trash2, Unlink, X } from "lucide-vue-next";

const { t, d: formatDate } = useI18n();
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
    /** Not null when the space has a Drive folder connected. */
    driveFolderId: { type: String, default: null },
    /** Address template of the preview, `__id__` replaced by the link. */
    previewPath: { type: String, default: "" },
    /** Address templates of the short address, `__id__` replaced by the link. */
    aliasPath: { type: String, default: "" },
    aliasRemovePath: { type: String, default: "" },
    /** With `__id__`: writes the link's address to its recipient again. */
    invitePath: { type: String, default: "" },
    /** With `__id__`: the full validity again, from today, same address. */
    extendPath: { type: String, default: "" },
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
    canComment: true,
    canChat: true,
    // Off, unlike the ones above: sending a file writes bytes to our storage
    // from an address with no account behind it, so it is granted per link
    // rather than assumed.
    canUpload: false,
    canSeeDrive: true,
    // Off, as everything the client is shown: contracts are chosen per link.
    canSeeContracts: false,
    // The application writes the invitation itself unless told not to: the
    // address used to leave by copy and paste, and a link nobody pasted was
    // a link never sent.
    sendInvitation: true,
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
            required(t("suite.studio.space_access.errors.email_required"))(
                issueForm.value.recipientEmail,
            ),
    }),
    url: () => props.issuePath,
    body: () => issueForm.value,
    onSuccess: (data) => {
        showIssue.value = false;
        applyLinks(data);
        mintedUrl.value = data?.url ?? "";
        toast.success(
            data?.invited
                ? t("suite.studio.space_access.issued_and_invited", { email: issueForm.value.recipientEmail })
                : t("suite.studio.space_access.issued"),
        );
    },
});

function openIssue() {
    issueForm.value = {
        recipientEmail: "",
        label: "",
        validForDays: props.defaultValidDays,
        canApprove: true,
        canComment: true,
        canChat: true,
        canUpload: false,
        canSeeDrive: true,
        canSeeContracts: false,
        sendInvitation: true,
    };
    clearIssue();
    showIssue.value = true;
}

/**
 * The short address: a name, to which the server adds a random end so that
 * the client's name alone does not open their space.
 */
const aliasFor = ref(null);
const aliasName = ref("");
const aliasSaving = ref(false);

function openAlias(link) {
    aliasFor.value = link;
    // The client's name rather than the space's: « atelier-dupont » reads
    // better on a card than « atelier-dupont-reseaux-sociaux ».
    aliasName.value = props.space?.customerName || props.space?.name || "";
}

async function saveAlias() {
    if (!aliasFor.value || aliasSaving.value) return;
    aliasSaving.value = true;
    try {
        const data = await request(buildPath(props.aliasPath, { id: aliasFor.value.id }), { name: aliasName.value });
        if (data) {
            applyLinks(data);
            const updated = links.value.find((link) => link.id === aliasFor.value.id);
            // Copied when the browser allows it, and said either way: the
            // address is in the row, with its own copy button.
            let copied = false;
            try {
                await navigator.clipboard.writeText(updated?.shortUrl ?? "");
                copied = Boolean(updated?.shortUrl);
            } catch {
                copied = false;
            }
            toast.success(t(copied ? "suite.studio.space_access.alias.given" : "suite.studio.space_access.alias.created"));
            aliasFor.value = null;
        }
    } finally {
        aliasSaving.value = false;
    }
}

async function removeAlias(link) {
    const data = await request(buildPath(props.aliasRemovePath, { id: link.id }));
    if (data) {
        applyLinks(data);
        toast.success(t("suite.studio.space_access.alias.removed"));
    }
}

const inviting = ref(null);

/** The address, written to its recipient again. Nothing is revoked. */
async function invite(link) {
    inviting.value = link.id;
    try {
        const data = await request(buildPath(props.invitePath, { id: link.id }));
        if (data) {
            applyLinks(data);
            toast.success(t("suite.studio.space_access.invited", { email: link.recipientEmail }));
        }
    } finally {
        inviting.value = null;
    }
}

const extending = ref(null);

/** Renewed in place: the client's address and the mails already sent keep working. */
async function extend(link) {
    extending.value = link.id;
    try {
        const data = await request(buildPath(props.extendPath, { id: link.id }));
        if (data) {
            applyLinks(data);
            toast.success(t("suite.studio.space_access.extended", { days: props.defaultValidDays }));
        }
    } finally {
        extending.value = null;
    }
}

/** A live link that runs out within two weeks, or one that already has. */
function expiresSoon(link) {
    if (link.revoked) return false;

    return link.expired || new Date(link.expiresAt).getTime() - Date.now() < 14 * 24 * 3600 * 1000;
}

const revoking = ref(null);

async function revoke(link) {
    revoking.value = link.id;
    try {
        const data = await request(buildPath(props.revokePath, { id: link.id }));
        applyLinks(data);
        toast.success(t("suite.studio.space_access.revoked"));
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
    "suite.studio.space_access.deleted",
);

/**
 * What can be done with a link, behind the "…" button (Axel's decision of
 * 04/10/2026): see the space as the recipient will see it, revoke the link,
 * delete it.
 */
function linkActions(link) {
    const actions = [];
    // See before sending, and after changing a setting. Without it, the only
    // way to know what a link shows is to open it in a private window.
    if (props.previewPath && link.usable) {
        actions.push({
            key: "preview",
            icon: Eye,
            title: t("suite.studio.space_access.preview"),
            onSelect: () => window.open(buildPath(props.previewPath, { id: link.id }), "_blank", "noopener"),
        });
    }
    if (props.extendPath && expiresSoon(link)) {
        actions.push({
            key: "extend",
            icon: CalendarPlus,
            title: t("suite.studio.space_access.extend", { days: props.defaultValidDays }),
            loading: extending.value === link.id,
            onSelect: () => extend(link),
        });
    }
    if (props.invitePath && link.usable) {
        actions.push({
            key: "invite",
            icon: Mail,
            title: t("suite.studio.space_access.invite"),
            loading: inviting.value === link.id,
            onSelect: () => invite(link),
        });
    }
    if (props.aliasPath && link.usable) {
        actions.push({
            key: "alias",
            icon: Link,
            title: link.shortUrl ? t("suite.studio.space_access.alias.change") : t("suite.studio.space_access.alias.give"),
            onSelect: () => openAlias(link),
        });
    }
    if (props.aliasRemovePath && link.shortUrl) {
        actions.push({
            key: "alias-remove",
            icon: Unlink,
            title: t("suite.studio.space_access.alias.remove"),
            onSelect: () => removeAlias(link),
        });
    }
    if (link.usable) {
        actions.push({
            key: "revoke",
            icon: Ban,
            title: t("suite.studio.space_access.revoke"),
            loading: revoking.value === link.id,
            onSelect: () => revoke(link),
        });
    }
    actions.push({
        key: "delete",
        color: "rose",
        icon: Trash2,
        title: t("shared.common.delete"),
        onSelect: () => confirmDelete(link),
    });

    return actions;
}

/** Active, revoked or expired, which is what the row's badge says. */
function stateOf(link) {
    if (link.revoked) return "revoked";
    if (link.expired) return "expired";

    return "active";
}

function openedLabel(link) {
    if (!link.firstOpenedAt) {
        return t("suite.studio.space_access.never_opened");
    }

    return t("suite.studio.space_access.opened_on", {
        date: formatDate(new Date(link.firstOpenedAt), "short"),
    });
}
</script>

<template>
    <div class="mx-auto max-w-3xl aurora-stack">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <p class="max-w-xl text-sm text-secondary">
                {{ t("suite.studio.space_access.intro") }}
            </p>
            <!-- Full width on a phone, as everywhere else in the space: it
                 is the page's only gesture, it has no reason to squeeze
                 against the right edge. -->
            <AppButton
                v-if="canShare"
                class="w-full sm:w-auto"
                variant="primary"
                size="sm"
                v-on:click="openIssue"
            >
                <Link2 class="h-3.5 w-3.5" :stroke-width="2" />
                {{ t("suite.studio.space_access.issue") }}
            </AppButton>
        </div>

        <!-- The screen's how-to guide, next to what it explains;
     collapsed or expanded, the choice applies to every guide. -->
        <AppGuide :title="t('suite.studio.space_access.guide.title')" storage-key="space-access">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 6" :key="step">{{ t(`suite.studio.space_access.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>
        <!-- The address, once. It is deliberately loud and deliberately not in
             the list: nothing can show it again, and a row that pretended it
             could would be a copy button with nothing behind it. -->
        <section
            v-if="mintedUrl"
            class="rounded-xl border border-accent-500/40 bg-accent-500/5 p-4"
        >
            <h2 class="text-sm font-medium text-primary">
                {{ t("suite.studio.space_access.minted_title") }}
            </h2>
            <p class="mt-1 text-xs text-secondary">
                {{ t("suite.studio.space_access.minted_hint") }}
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
            :message="t('suite.studio.space_access.empty')"
            :hint="t('suite.studio.space_access.empty_hint')"
        />

        <ul v-else class="aurora-card divide-y divide-line/40">
            <!-- The name, the address and the dates take the line; the state
                 and the "…" button stay on the right. The gestures spelled
                 out wrapped every word on a phone ("Camille, g…", "Révoquer"
                 caught between two lines): behind the button, they take no
                 room any more. -->
            <li
                v-for="link in links"
                :key="link.id"
                class="flex items-start gap-3 px-4 py-3 sm:items-center sm:gap-x-4"
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
                            t("suite.studio.space_access.expires_on", {
                                date: formatDate(new Date(link.expiresAt), "short"),
                            })
                        }}
                        <template v-if="!link.canApprove">
                            · {{ t("suite.studio.space_access.read_only") }}
                        </template>
                        <template v-if="link.canUpload">
                            · {{ t("suite.studio.space_access.may_upload") }}
                        </template>
                        <!-- Said only when it is withdrawn: the list names
                             what is out of the ordinary, not what holds for
                             every link. -->
                        <template v-if="false === link.canSeeDrive">
                            · {{ t("suite.studio.space_access.no_drive") }}
                        </template>
                        <template v-if="false === link.canChat">
                            · {{ t("suite.studio.space_access.no_chat") }}
                        </template>
                    </p>
                    <!-- The short address, which can be copied again at any
                         time, unlike the long one. -->
                    <div v-if="link.shortUrl" data-space-short-address class="mt-1.5 flex min-w-0 items-center gap-1.5">
                        <Link class="h-3.5 w-3.5 shrink-0 text-muted" :stroke-width="2" />
                        <code class="min-w-0 truncate font-mono text-xs" :class="link.usable ? 'text-primary' : 'text-muted line-through'">{{ link.shortUrl }}</code>
                        <AppIconButton
                            v-if="link.usable"
                            size="sm"
                            :title="t('shared.common.copy')"
                            v-on:click="copy(link.shortUrl)"
                        >
                            <Copy class="h-3.5 w-3.5" :stroke-width="2" />
                        </AppIconButton>
                    </div>
                </div>

                <!-- The state, then the gestures behind the "…" button, as on
                     every list (Axel's decision of 04/10/2026). -->
                <div class="flex shrink-0 items-center gap-1">
                    <span
                        class="w-fit shrink-0 rounded-full px-2 py-0.5 text-xs"
                        :class="{
                            'bg-emerald-500/10 text-emerald-500': stateOf(link) === 'active',
                            'bg-surface-2 text-muted': stateOf(link) !== 'active',
                        }"
                    >
                        {{ t(`suite.studio.space_access.states.${stateOf(link)}`) }}
                    </span>
                    <AppRowActions v-if="canShare" :actions="linkActions(link)" :label="link.label || link.recipientEmail" />
                </div>
            </li>
        </ul>

        <AppModal
            :show="showIssue"
            max-width="lg"
            :title="t('suite.studio.space_access.issue')"
            :icon="Link2"
            :closeable="false"
            v-on:close="showIssue = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitIssue">
                <AppInput
                    :model-value="issueForm.recipientEmail"
                    type="email"
                    :label="t('suite.studio.space_access.recipient_email')"
                    :placeholder="t('suite.studio.space_access.recipient_email_placeholder')"
                    :hint="t('suite.studio.space_access.recipient_email_hint')"
                    :error="issueErrors.recipientEmail"
                    required
                    v-on:update:model-value="issueForm.recipientEmail = $event"
                />
                <AppInput
                    :model-value="issueForm.label"
                    :label="t('suite.studio.space_access.label')"
                    :placeholder="t('suite.studio.space_access.label_placeholder')"
                    :hint="t('suite.studio.space_access.label_hint')"
                    :error="issueErrors.label"
                    v-on:update:model-value="issueForm.label = $event"
                />
                <AppInput
                    :model-value="String(issueForm.validForDays)"
                    type="number"
                    :label="t('suite.studio.space_access.valid_for_days')"
                    :placeholder="String(defaultValidDays)"
                    :hint="
                        t('suite.studio.space_access.valid_for_days_hint', {
                            max: maxValidDays,
                        })
                    "
                    :error="issueErrors.validForDays"
                    v-on:update:model-value="issueForm.validForDays = Number($event)"
                />
                <AppCheckbox
                    :model-value="issueForm.canApprove"
                    :label="t('suite.studio.space_access.can_approve')"
                    :hint="t('suite.studio.space_access.can_approve_hint')"
                    v-on:update:model-value="issueForm.canApprove = $event"
                />
                <!-- Two conversations, two boxes. A single right used to
                     control both: allowing a remark under a post also opened
                     the relationship thread, which is not the same thing and
                     is not given to the same people. -->
                <AppCheckbox
                    :model-value="issueForm.canComment"
                    :label="t('suite.studio.space_access.can_comment')"
                    :hint="t('suite.studio.space_access.can_comment_hint')"
                    v-on:update:model-value="issueForm.canComment = $event"
                />
                <AppCheckbox
                    :model-value="issueForm.canChat"
                    :label="t('suite.studio.space_access.can_chat')"
                    :hint="t('suite.studio.space_access.can_chat_hint')"
                    v-on:update:model-value="issueForm.canChat = $event"
                />
                <AppCheckbox
                    :model-value="issueForm.canUpload"
                    :label="t('suite.studio.space_access.can_upload')"
                    :hint="t('suite.studio.space_access.can_upload_hint')"
                    v-on:update:model-value="issueForm.canUpload = $event"
                />

                <AppCheckbox
                    :model-value="issueForm.canSeeContracts"
                    :label="t('suite.studio.space_access.can_see_contracts')"
                    :hint="t('suite.studio.space_access.can_see_contracts_hint')"
                    v-on:update:model-value="issueForm.canSeeContracts = $event"
                />
                <AppCheckbox
                    :model-value="issueForm.sendInvitation"
                    :label="t('suite.studio.space_access.send_invitation')"
                    :hint="t('suite.studio.space_access.send_invitation_hint')"
                    v-on:update:model-value="issueForm.sendInvitation = $event"
                />

                <!-- Offered only when the space has a folder connected: a box
                     that controls nothing reads as broken. -->
                <AppCheckbox
                    v-if="driveFolderId"
                    :model-value="false !== issueForm.canSeeDrive"
                    :label="t('suite.studio.space_access.can_see_drive')"
                    :hint="t('suite.studio.space_access.can_see_drive_hint')"
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
                        {{ t("suite.studio.space_access.issue_submit") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="!!aliasFor"
            max-width="md"
            :title="aliasFor?.shortUrl ? t('suite.studio.space_access.alias.change') : t('suite.studio.space_access.alias.give')"
            :icon="Link"
            v-on:close="aliasFor = null"
        >
            <form class="space-y-3" v-on:submit.prevent="saveAlias">
                <AppInput
                    v-model="aliasName"
                    data-space-alias-name
                    :label="t('suite.studio.space_access.alias.name')"
                    :placeholder="t('suite.studio.space_access.alias.name_placeholder')"
                    :hint="t('suite.studio.space_access.alias.name_hint')"
                />
                <p v-if="aliasFor?.shortUrl" class="text-xs text-secondary">
                    {{ t("suite.studio.space_access.alias.replaces", { url: aliasFor.shortUrl }) }}
                </p>
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="aliasFor = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" :loading="aliasSaving" v-on:click="saveAlias">
                        <Link class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("suite.studio.space_access.alias.submit") }}
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
                    t("suite.studio.space_access.delete_confirm", {
                        name: pendingDelete?.label || pendingDelete?.recipientEmail || "",
                    })
                }}
            </p>
            <p class="text-sm text-secondary">
                {{ t("suite.studio.space_access.delete_warning") }}
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
