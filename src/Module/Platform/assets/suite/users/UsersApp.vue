<script setup>
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { computed, onMounted, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useNarrowContainer } from "@/shared/composables/list/useNarrowContainer.js";
import { UserPlus, Save, Upload, Trash2, X, Send, Pencil, LayoutGrid } from "lucide-vue-next";
import { toast } from "vue-sonner";
import AppPagination from "@/shared/components/nav/AppPagination.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppFileInput from "@/shared/components/form/file/AppFileInput.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppMultiselect from "@/shared/components/form/select/AppMultiselect.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import UserRowActions from "@platform/suite/users/UserRowActions.vue";
import ModuleAccessNode from "@platform/suite/users/ModuleAccessNode.vue";
import AppAvatar from "@/shared/components/display/AppAvatar.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import { useUsersSearch } from "@platform/suite/users/composables/useUsersSearch.js";
import { useUsersInvite } from "@platform/suite/users/composables/useUsersInvite.js";
import { useUsersEdit } from "@platform/suite/users/composables/useUsersEdit.js";
import { useUsersActions } from "@platform/suite/users/composables/useUsersActions.js";
import { useUsersPrivileges } from "@platform/suite/users/composables/useUsersPrivileges.js";
import { useUsersDisabledModules } from "@platform/suite/users/composables/useUsersDisabledModules.js";

const { t } = useI18n();
const { container, isNarrow } = useNarrowContainer();
const { formatDate, formatDateShort } = useDateFormat();

const props = defineProps({
    roles: { type: Array, default: () => [] },
    userTypes: { type: Array, default: () => [] },
    isDev: { type: Boolean, default: false },
    currentUserPriority: { type: Number, default: 0 },
    privilegesByModule: { type: Array, default: () => [] },
    privilegesPath: { type: String, default: "" },
    modulesForAccess: { type: Array, default: () => [] },
    canManageDisabledModules: { type: Boolean, default: false },
    disabledModulesPath: { type: String, default: "" },
    listPath: { type: String, required: true },
    invitePath: { type: String, required: true },
    updatePath: { type: String, required: true },
    resendInvitationPath: { type: String, required: true },
    toggleDisabledPath: { type: String, required: true },
    impersonatePath: { type: String, default: "" },
    impersonateFrontPath: { type: String, default: "" },
    deletePath: { type: String, required: true },
    photoUploadPath: { type: String, required: true },
    photoDeletePath: { type: String, required: true },
    selectablePath: { type: String, required: true },
    showPath: { type: String, required: true },
    currentUserId: { type: Number, default: 0 },
    /**
     * Extra fields to register on the invite + edit forms. Lets clients extend
     * the modals + table without forking this component.
     * Example: { phoneNumber: { default: '', fromEntity: (u) => u.phoneNumber ?? '' } }
     */
    extraFields: { type: Object, default: () => ({}) },
    /** The user this URL names, opened on arrival. Decided by the server. */
    activeId: { type: Number, default: null },
});

const { search, roleFilter, users, loading, page, totalPages, fetchUsers, goToPage } = useUsersSearch(props.listPath);
const { inviteModal, inviteForm, openInvite, submitInvite } = useUsersInvite(props.invitePath, props.roles, fetchUsers, { extraFields: props.extraFields });
const { editModal, editForm, managerOptions, openEdit, onPhotoSelected, removePhoto, submitEdit } = useUsersEdit(props, fetchUsers, { extraFields: props.extraFields });

const { viewingUser, openView, resendInvitation, togglingUser, askToggleDisabled, confirmToggleDisabled, deletingUser, confirmDelete, statusBadgeColor, isCurrent, canActOn, canEditUser, UserStatus } = useUsersActions(props, fetchUsers);

/**
 * A user is an address now, and the address is kept in step with the modal.
 *
 * Arriving on `/users/42` opens that user; opening one from the table rewrites
 * the bar so the link can be sent from wherever the reader got to. `replace`
 * rather than `push`: opening and closing a modal is not a page to go back
 * through, and stacking history entries would make Back feel broken.
 */
function syncAddress(user) {
    const path = user ? buildPath(props.showPath, { id: user.id }) : props.listPath.replace(/\/list$/, "");

    window.history.replaceState(window.history.state, "", path);
}

watch(viewingUser, syncAddress);

onMounted(async () => {
    if (!props.activeId) return;

    // Wait for the list, so the modal opens with a full record rather than an
    // id it has to fill in a moment later.
    await fetchUsers();
    const known = users.value.find((user) => user.id === props.activeId);

    await openView(known ?? { id: props.activeId });
});

function openViewWithPrivileges(user) {
    openView(user);
}

/**
 * The three labels of the access toggle, for the three situations it covers -
 * and not two.
 *
 * Opening an account nobody has ever contacted (`invitedAt` null) is not a
 * reactivation: it is the first sending of its invitation. Saying
 * "réactiver" would suggest access is being given back to someone who never
 * had it, and would hide that an email goes out.
 */
const toggleKeys = computed(() => {
    if (togglingUser.value?.status !== UserStatus.Disabled) {
        return {
            confirm: "suite.users.disable_confirm",
            action: "suite.users.disable",
            variant: "danger",
        };
    }

    return togglingUser.value?.invitedAt
        ? {
            confirm: "suite.users.enable_confirm",
            action: "suite.users.enable",
            variant: "primary",
        }
        : {
            confirm: "suite.users.enable_and_invite_confirm",
            action: "suite.users.enable_and_invite",
            variant: "primary",
        };
});

const { privilegesModal, pendingPrivileges, togglePrivilege, openPrivileges, savePrivileges } = useUsersPrivileges(props, fetchUsers);
const { modulesModal, pendingDisabledModules, openModules, toggleModule, saveModules } = useUsersDisabledModules(props, fetchUsers);


// The create verb is marked `primary`: AppPageActions sets it beside the
// sheet as the page's main button, and the sheet keeps whatever else there is.
const pageActions = computed(() => {
    return [
        {
            key: "invite",
            primary: true,
            color: "accent",
            icon: UserPlus,
            title: t("suite.users.invite"),
            onSelect: openInvite,
        },
    ];
});
</script>

<template>
    <div ref="container" class="aurora-stack">
        <AppListToolbar :title="t('suite.nav.users')" :subtitle="t('suite.nav.users_description')">
            <AppSearchInput v-model="search" :placeholder="t('suite.users.search_placeholder')" />
            <template #inline>
                <AppMultiselect
                    v-model="roleFilter"
                    :options="roles"
                    :placeholder="t('suite.users.all_roles')"
                    :allow-empty="true"
                    class="sm:w-48 shrink-0"
                />
            </template>
            <template #actions>
                <AppPageActions :actions="pageActions" />
            </template>
        </AppListToolbar>

        <!-- The screen's how-to, next to what it explains; collapsed or
             expanded, the choice applies to every guide. -->
        <AppGuide :title="t('suite.users.guide.title')" storage-key="users">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 4" :key="step">{{ t(`suite.users.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>
        <div class="relative space-y-4">
            <div v-if="isNarrow" class="space-y-2">
                <AppNoData v-if="!loading && !users.length" :message="t('suite.users.empty')" />
                <!-- One person, one block.
                     The card used to stack three: the avatar and the name,
                     then the badges, then a footer crossed by a line that
                     only carried a three-dot menu, aligned right in an empty
                     strip. Forty-five pixels and a border per person for a
                     single button, on the screen with the least room. The
                     menu moves up to the height of the name, where people
                     look for it, and the footer goes away. -->
                <div v-for="user in users" :key="user.id" class="aurora-card flex items-start gap-3 p-3">
                    <AppAvatar
                        variant="solid"
                        :name="user.name"
                        :photo-url="user.profilePhotoUrl ?? ''"
                        :size="40"
                        class="shrink-0"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-primary text-sm truncate">{{ user.name }}</p>
                        <p class="text-xs text-muted truncate mt-0.5">{{ user.email }}</p>
                        <div class="mt-2 flex flex-wrap gap-1">
                            <AppBadge :color="statusBadgeColor(user.status)">{{ user.statusLabel }}</AppBadge>
                            <AppBadge color="gray">{{ user.typeLabel }}</AppBadge>
                            <AppBadge v-if="user.isDev" :color="user.devColor">Dev</AppBadge>
                            <AppBadge v-if="user.roleLabel" :color="user.roleColor">{{ user.roleLabel }}</AppBadge>
                            <AppBadge v-if="isCurrent(user)" color="accent">{{ t('suite.users.you') }}</AppBadge>
                        </div>
                    </div>
                    <div class="shrink-0">
                        <UserRowActions
                            :user="user"
                            :is-dev="isDev"
                            :can-act="canActOn(user)"
                            :can-edit="canEditUser(user)"
                            :has-privileges="privilegesByModule.length > 0"
                            :can-manage-disabled-modules="canManageDisabledModules && modulesForAccess.length > 0"
                            :impersonate-path="impersonatePath"
                            :impersonate-front-path="impersonateFrontPath"
                            v-on:view="openViewWithPrivileges"
                            v-on:resend="resendInvitation"
                            v-on:edit="openEdit"
                            v-on:privileges="openPrivileges"
                            v-on:modules="openModules"
                            v-on:toggle-disabled="askToggleDisabled"
                            v-on:delete="deletingUser = $event"
                        />
                    </div>
                </div>
            </div>

            <div v-else class="aurora-card overflow-x-auto scrollbar-thin">
                <AppNoData v-if="!loading && !users.length" :message="t('suite.users.empty')" />
                <table v-else class="aurora-table w-full text-sm">
                    <thead>
                        <tr class="bg-surface-2/50 border-b border-line/40">
                            <!-- A single column for the person: their name,
                                 what they wrote as a tagline, and their
                                 address. The address had its own column,
                                 which disappeared below 1024 pixels - so the
                                 screen where someone is looked up by email
                                 was precisely the one that did not show it. -->
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t('suite.users.user_label') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell">{{ t('suite.users.role_label') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">{{ t('suite.users.type_label') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t('suite.users.status_label') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">{{ t('suite.users.created') }}</th>
                            <slot name="extra-headers" />
                            <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-muted sticky right-0 bg-surface-2 border-l border-line/40">{{ t('suite.users.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/40">
                        <tr v-for="user in users" :key="user.id" class="group hover:bg-surface-2/40 transition-colors">
                            <td class="px-4 py-2 text-primary font-medium">
                                <div class="flex items-center gap-3 min-w-0">
                                    <AppAvatar variant="solid" :name="user.name" :photo-url="user.profilePhotoUrl ?? ''" :size="32" />
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="truncate">{{ user.name }}</span>
                                            <AppBadge v-if="isCurrent(user)" color="accent">{{ t('suite.users.you') }}</AppBadge>
                                        </div>
                                        <p v-if="user.moodMessage" class="text-xs text-muted italic truncate" :title="user.moodMessage">“{{ user.moodMessage }}”</p>
                                        <p class="truncate text-xs text-muted" :title="user.email">{{ user.email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-2 hidden md:table-cell">
                                <div class="flex items-center gap-1 flex-wrap">
                                    <AppBadge v-if="user.isDev" :color="user.devColor">Dev</AppBadge>
                                    <AppBadge v-if="user.roleLabel" :color="user.roleColor">{{ user.roleLabel }}</AppBadge>
                                </div>
                            </td>
                            <td class="px-4 py-2 hidden lg:table-cell">
                                <!-- Plain text: a type is not a state, and colour is kept
                                     for the states (UI audit of 07/10/2026). -->
                                <span class="text-sm text-secondary">{{ user.typeLabel }}</span>
                            </td>
                            <td class="px-4 py-2">
                                <AppBadge :color="statusBadgeColor(user.status)">{{ user.statusLabel }}</AppBadge>
                            </td>
                            <td class="px-4 py-2 text-xs text-muted hidden lg:table-cell">{{ formatDateShort(user.createdAt) }}</td>
                            <slot name="extra-cells" :user="user" />
                            <td class="px-4 py-2 sticky right-0 bg-surface border-l border-line/40">
                                <div class="flex justify-end">
                                    <UserRowActions
                                        :user="user"
                                        :is-dev="isDev"
                                        :can-act="canActOn(user)"
                                        :can-edit="canEditUser(user)"
                                        :has-privileges="privilegesByModule.length > 0"
                                        :can-manage-disabled-modules="canManageDisabledModules && modulesForAccess.length > 0"
                                        :impersonate-path="impersonatePath"
                                        :impersonate-front-path="impersonateFrontPath"
                                        v-on:view="openViewWithPrivileges"
                                        v-on:resend="resendInvitation"
                                        v-on:edit="openEdit"
                                        v-on:privileges="openPrivileges"
                                        v-on:modules="openModules"
                                        v-on:toggle-disabled="askToggleDisabled"
                                        v-on:delete="deletingUser = $event"
                                    />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <AppPagination :page="page" :total-pages="totalPages" v-on:change="goToPage" />
            <AppLoader :active="loading" />
        </div>

        <AppModal
            :show="inviteModal.open"
            max-width="md"
            :title="t('suite.users.invite')"
            :icon="UserPlus"
            :closeable="false"
            v-on:close="inviteModal.open = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitInvite">
                <AppInput
                    v-model="inviteForm.name"
                    :label="t('suite.users.name')"
                    :placeholder="t('suite.users.name_placeholder')"
                    :error="inviteModal.errors.name ?? ''"
                    required
                />
                <AppInput
                    v-model="inviteForm.email"
                    :label="t('suite.users.email')"
                    type="email"
                    :placeholder="t('suite.users.email_placeholder')"
                    :error="inviteModal.errors.email ?? ''"
                    required
                />
                <!-- The type first: it decides whether the role question
                     makes sense. Hidden when there is nothing to choose. -->
                <AppMultiselect
                    v-if="userTypes.length > 1"
                    v-model="inviteForm.type"
                    :options="userTypes"
                    :label="t('suite.users.type_label')"
                    :error="inviteModal.errors.type ?? ''"
                    required
                />
                <!-- The public site has only one role, and it is not the
                     operator's choice: public sign-up hardcodes ROLE_USER,
                     and an invitation must lead to the same account. Showing
                     a selector here would suggest a decision that does not
                     exist - the server forces the value anyway. -->
                <AppMultiselect
                    v-if="inviteForm.type !== 'frontend'"
                    v-model="inviteForm.role"
                    :options="roles"
                    :label="t('suite.users.role_label')"
                    :error="inviteModal.errors.role ?? ''"
                    required
                />
                <p v-else class="text-xs text-muted">{{ t('suite.users.invite_frontend_role_hint') }}</p>
                <!-- The message has no recipient when nothing is sent. -->
                <div v-if="!inviteForm.disabled">
                    <label class="mb-1.5 block text-[0.8125rem] font-medium text-primary">{{ t('suite.users.invite_message') }}</label>
                    <textarea
                        v-model="inviteForm.message"
                        rows="3"
                        class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-primary placeholder-muted focus:border-accent-500 focus:ring-1 focus:ring-accent-500 transition resize-none"
                        :placeholder="t('suite.users.invite_message_placeholder')"
                    />
                </div>

                <!-- Pre-provision: someone arriving later. The account exists,
                     nobody is contacted, and sign-in is refused until it is
                     opened from the list - that opening is what sends the
                     invitation. -->
                <AppCheckbox
                    v-model="inviteForm.disabled"
                    :label="t('suite.users.invite_disabled')"
                    :hint="t('suite.users.invite_disabled_hint')"
                />

                <slot name="extra-invite-form-fields" :form="inviteForm" :errors="inviteModal.errors" />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="inviteModal.open = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.cancel') }}</AppButton>
                    <!-- The label follows what the button really does: a
                         "Envoyer l'invitation" that sends nothing is a
                         lie. -->
                    <AppButton type="submit" variant="primary" size="md" :loading="inviteModal.saving">
                        <component :is="inviteForm.disabled ? UserPlus : Send" class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ inviteForm.disabled ? t('suite.users.create_disabled_account') : t('suite.users.send_invite') }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal :show="!!viewingUser" max-width="md" :closeable="false" v-on:close="viewingUser = null">
            <div v-if="viewingUser" class="space-y-5">
                <div class="flex items-center gap-4">
                    <AppAvatar variant="solid" :name="viewingUser.name" :photo-url="viewingUser.profilePhotoUrl ?? ''" :size="64" />
                    <div class="min-w-0">
                        <h3 class="text-lg font-semibold text-primary truncate">{{ viewingUser.name }}</h3>
                        <p class="text-sm text-muted truncate">{{ viewingUser.email }}</p>
                    </div>
                </div>

                <p v-if="viewingUser.moodMessage" class="text-sm text-secondary italic border-l-2 border-accent-500/40 pl-3">
                    "{{ viewingUser.moodMessage }}"
                </p>

                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                    <div>
                        <dt class="text-xs text-secondary uppercase tracking-wide">{{ t('suite.users.status_label') }}</dt>
                        <dd class="mt-1">
                            <AppBadge :color="statusBadgeColor(viewingUser.status)">{{ viewingUser.statusLabel }}</AppBadge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-secondary uppercase tracking-wide">{{ t('suite.users.role_label') }}</dt>
                        <dd class="mt-1 flex items-center gap-1 flex-wrap">
                            <AppBadge v-if="viewingUser.isDev" :color="viewingUser.devColor">Dev</AppBadge>
                            <AppBadge v-if="viewingUser.roleLabel" :color="viewingUser.roleColor">{{ viewingUser.roleLabel }}</AppBadge>
                            <span v-if="!viewingUser.isDev && !viewingUser.roleLabel" class="text-muted">-</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-secondary uppercase tracking-wide">{{ t('suite.users.detail.type') }}</dt>
                        <dd class="mt-1 text-primary">{{ viewingUser.typeLabel }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-secondary uppercase tracking-wide">{{ t('suite.users.detail.locale') }}</dt>
                        <dd class="mt-1 text-primary">{{ t('shared.locales.' + viewingUser.locale) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-secondary uppercase tracking-wide">{{ t('suite.users.detail.created_at') }}</dt>
                        <dd class="mt-1 text-primary">{{ formatDate(viewingUser.createdAt) }}</dd>
                    </div>
                    <div v-if="viewingUser.invitedAt">
                        <dt class="text-xs text-secondary uppercase tracking-wide">{{ t('suite.users.detail.invited_at') }}</dt>
                        <dd class="mt-1 text-primary">{{ formatDate(viewingUser.invitedAt) }}</dd>
                    </div>
                </dl>

                <div class="border-t border-line/40 pt-4 space-y-4">
                    <div>
                        <p class="text-xs text-secondary uppercase tracking-wide mb-1.5">{{ t('suite.users.manager.label') }}</p>
                        <p v-if="viewingUser.manager" class="text-sm text-primary">{{ viewingUser.manager.name }}</p>
                        <p v-else class="text-sm text-muted">{{ t('suite.users.manager.none') }}</p>
                    </div>
                    <div v-if="viewingUser.subordinates && viewingUser.subordinates.length">
                        <p class="text-xs text-secondary uppercase tracking-wide mb-1.5">
                            {{ t('suite.users.manager.subordinates', { count: viewingUser.subordinatesCount }) }}
                        </p>
                        <div class="flex flex-wrap gap-1.5">
                            <AppBadge v-for="sub in viewingUser.subordinates" :key="sub.id" color="accent">{{ sub.name }}</AppBadge>
                        </div>
                    </div>
                </div>
            </div>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="viewingUser = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.close') }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="editModal.open"
            max-width="lg"
            :title="t('suite.users.edit_title', { name: editModal.editing?.name ?? '' })"
            :icon="Pencil"
            :closeable="false"
            v-on:close="editModal.open = false"
        >
            <div class="flex items-center gap-4 py-3 border-b border-line/40">
                <AppAvatar variant="solid" :name="editModal.editing?.name ?? ''" :photo-url="editModal.editing?.profilePhotoUrl ?? ''" :size="56" />
                <div class="flex flex-col gap-1.5">
                    <AppFileInput accept="image/jpeg,image/png,image/webp" v-on:change="onPhotoSelected">
                        <template #default="{ trigger }">
                            <div class="flex items-center gap-2 flex-wrap">
                                <AppButton variant="ghost" size="sm" :loading="editModal.photoUploading" v-on:click="trigger">
                                    <Upload class="w-3.5 h-3.5" :stroke-width="2" />
                                    {{ t('suite.users.photo.upload') }}
                                </AppButton>
                                <AppButton
                                    v-if="editModal.editing?.profilePhotoUrl"
                                    variant="ghost"
                                    size="sm"
                                    :loading="editModal.photoUploading"
                                    v-on:click="removePhoto"
                                >
                                    <Trash2 class="w-3.5 h-3.5" :stroke-width="2" />
                                    {{ t('suite.users.photo.remove') }}
                                </AppButton>
                            </div>
                        </template>
                    </AppFileInput>
                    <p class="text-xs text-muted">{{ t('suite.users.photo.hint') }}</p>
                </div>
            </div>

            <form class="space-y-4" v-on:submit.prevent="submitEdit">
                <div class="grid grid-cols-2 gap-4">
                    <AppInput
                        v-model="editForm.name"
                        :label="t('suite.users.name')"
                        :placeholder="t('shared.placeholders.name')"
                        :error="editModal.errors.name ?? ''"
                    />
                    <AppInput
                        v-model="editForm.email"
                        :label="t('suite.users.email')"
                        :placeholder="t('shared.placeholders.email')"
                        type="email"
                        :error="editModal.errors.email ?? ''"
                    />
                    <AppMultiselect
                        v-model="editForm.role"
                        :options="roles"
                        :label="t('suite.users.role_label')"
                        :allow-empty="false"
                        :error="editModal.errors.role ?? ''"
                        open-direction="top"
                        :use-teleport="false"
                        required
                    />
                    <AppMultiselect
                        v-model="editForm.managerId"
                        :options="managerOptions"
                        :label="t('suite.users.manager.label')"
                        :allow-empty="true"
                        :error="editModal.errors.managerId ?? ''"
                        open-direction="top"
                        :use-teleport="false"
                    />
                </div>
                <AppInput
                    v-model="editForm.password"
                    :label="t('suite.users.new_password')"
                    type="password"
                    :placeholder="t('suite.users.new_password_placeholder')"
                    :error="editModal.errors.password ?? ''"
                />
                <slot name="extra-edit-form-fields" :form="editForm" :errors="editModal.errors" />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="editModal.open = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.cancel') }}</AppButton>
                    <AppButton type="submit" variant="primary" size="md" :loading="editModal.saving">
                        <Save class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t('shared.common.save') }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Privileges modal - dedicated, Dev only -->
        <AppModal :show="privilegesModal.open" max-width="2xl" :closeable="false" v-on:close="privilegesModal.open = false">
            <div v-if="privilegesModal.user" class="space-y-4">
                <div class="flex items-center gap-3">
                    <AppAvatar variant="solid" :name="privilegesModal.user.name" :photo-url="privilegesModal.user.profilePhotoUrl ?? ''" :size="40" />
                    <div>
                        <h3 class="text-base font-semibold text-primary">{{ privilegesModal.user.name }}</h3>
                        <p class="text-xs text-muted">{{ t('suite.users.privileges.title') }}</p>
                    </div>
                </div>
                <div v-for="group in privilegesByModule" :key="group.module" class="space-y-2">
                    <p class="text-xs font-semibold text-secondary uppercase tracking-wider">{{ t('suite.modules.' + group.module, group.module) }}</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <AppCheckbox
                            v-for="priv in group.privileges"
                            :key="priv"
                            :model-value="pendingPrivileges.includes(priv)"
                            v-on:update:model-value="togglePrivilege(priv)"
                        >
                            <span class="text-xs">{{ t('suite.permissions.names.' + priv, priv) }}</span>
                        </AppCheckbox>
                    </div>
                </div>
            </div>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="privilegesModal.open = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.cancel') }}</AppButton>
                    <AppButton variant="primary" size="md" :loading="privilegesModal.saving" v-on:click="savePrivileges">
                        <Save class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t('shared.common.save') }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Per-user module access modal - gated by platform.users.module_access.manage -->
        <AppModal :show="modulesModal.open" max-width="2xl" :closeable="false" v-on:close="modulesModal.open = false">
            <div v-if="modulesModal.user" class="space-y-4">
                <div class="flex items-center gap-3">
                    <AppAvatar variant="solid" :name="modulesModal.user.name" :photo-url="modulesModal.user.profilePhotoUrl ?? ''" :size="40" />
                    <div>
                        <h3 class="text-base font-semibold text-primary">{{ modulesModal.user.name }}</h3>
                        <p class="text-xs text-muted">{{ t('suite.users.modules.title') }}</p>
                    </div>
                </div>
                <p class="text-xs text-muted">{{ t('suite.users.modules.hint') }}</p>
                <div class="space-y-4 max-h-[60vh] overflow-y-auto pr-2">
                    <ModuleAccessNode
                        v-for="entry in modulesForAccess"
                        :key="entry.key"
                        :node="entry"
                        :disabled-keys="pendingDisabledModules"
                        v-on:toggle="toggleModule"
                    />
                </div>
            </div>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="modulesModal.open = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.cancel') }}</AppButton>
                    <AppButton variant="primary" size="md" :loading="modulesModal.saving" v-on:click="saveModules">
                        <Save class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t('shared.common.save') }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal :show="!!deletingUser" max-width="sm" :closeable="false" v-on:close="deletingUser = null">
            <p class="text-sm text-primary">{{ t('suite.users.delete_confirm', {name: deletingUser?.name ?? ''}) }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="deletingUser = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.cancel') }}</AppButton>
                    <AppButton variant="danger" size="md" v-on:click="confirmDelete"><Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.delete') }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal :show="!!togglingUser" max-width="sm" :closeable="false" v-on:close="togglingUser = null">
            <p class="text-sm text-primary">
                {{ t(toggleKeys.confirm, {name: togglingUser?.name ?? ''}) }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="togglingUser = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.cancel') }}</AppButton>
                    <AppButton :variant="toggleKeys.variant" size="md" v-on:click="confirmToggleDisabled">
                        {{ t(toggleKeys.action) }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
