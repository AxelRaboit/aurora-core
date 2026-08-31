<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { UserPlus, Save, Upload, Trash2, X, Send, Pencil, LayoutGrid } from "lucide-vue-next";
import { toast } from "vue-sonner";
import AppPagination from "@/shared/components/nav/AppPagination.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
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
import UserRowActions from "@platform/backend/users/UserRowActions.vue";
import ModuleAccessNode from "@platform/backend/users/ModuleAccessNode.vue";
import AppAvatar from "@/shared/components/display/AppAvatar.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import { useUsersSearch } from "@platform/backend/users/composables/useUsersSearch.js";
import { useUsersInvite } from "@platform/backend/users/composables/useUsersInvite.js";
import { useUsersEdit } from "@platform/backend/users/composables/useUsersEdit.js";
import { useUsersActions } from "@platform/backend/users/composables/useUsersActions.js";
import { useUsersPrivileges } from "@platform/backend/users/composables/useUsersPrivileges.js";
import { useUsersDisabledModules } from "@platform/backend/users/composables/useUsersDisabledModules.js";

const { t } = useI18n();
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
    currentUserEmail: { type: String, default: "" },
    /**
     * Extra fields to register on the invite + edit forms. Lets clients extend
     * the modals + table without forking this component.
     * Example: { phoneNumber: { default: '', fromEntity: (u) => u.phoneNumber ?? '' } }
     */
    extraFields: { type: Object, default: () => ({}) },
});

const { search, roleFilter, users, loading, page, totalPages, fetchUsers, goToPage } = useUsersSearch(props.listPath);
const { inviteModal, inviteForm, openInvite, submitInvite } = useUsersInvite(props.invitePath, props.roles, fetchUsers, { extraFields: props.extraFields });
const { editModal, editForm, managerOptions, openEdit, onPhotoSelected, removePhoto, submitEdit } = useUsersEdit(props, fetchUsers, { extraFields: props.extraFields });

const { viewingUser, openView, resendInvitation, togglingUser, askToggleDisabled, confirmToggleDisabled, deletingUser, confirmDelete, statusBadgeColor, isCurrent, canActOn, canEditUser, UserStatus } = useUsersActions(props, fetchUsers);

function openViewWithPrivileges(user) {
    openView(user);
}

/**
 * Les trois libellés de la bascule d'accès, pour les trois situations qu'elle
 * couvre - et pas deux.
 *
 * Ouvrir un compte que personne n'a jamais contacté (`invitedAt` nul) n'est pas
 * une réactivation : c'est le premier envoi de son invitation. Annoncer
 * « réactiver » ferait croire qu'on rend un accès à quelqu'un qui ne l'a jamais
 * eu, et masquerait qu'un mail part.
 */
const toggleKeys = computed(() => {
    if (togglingUser.value?.status !== UserStatus.Disabled) {
        return {
            confirm: "backend.users.disable_confirm",
            action: "backend.users.disable",
            variant: "danger",
        };
    }

    return togglingUser.value?.invitedAt
        ? {
            confirm: "backend.users.enable_confirm",
            action: "backend.users.enable",
            variant: "primary",
        }
        : {
            confirm: "backend.users.enable_and_invite_confirm",
            action: "backend.users.enable_and_invite",
            variant: "primary",
        };
});

const { privilegesModal, pendingPrivileges, togglePrivilege, openPrivileges, savePrivileges } = useUsersPrivileges(props, fetchUsers);
const { modulesModal, pendingDisabledModules, openModules, toggleModule, saveModules } = useUsersDisabledModules(props, fetchUsers);

</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center gap-3">
            <AppSearchInput v-model="search" :placeholder="t('backend.users.search_placeholder')" class="flex-1" />
            <AppMultiselect
                v-model="roleFilter"
                :options="roles"
                :placeholder="t('backend.users.all_roles')"
                :allow-empty="true"
                class="sm:w-48 shrink-0"
            />
            <AppButton variant="primary" size="md" class="shrink-0" v-on:click="openInvite">
                <UserPlus class="w-4 h-4" :stroke-width="2" />
                {{ t('backend.users.invite') }}
            </AppButton>
        </div>

        <div class="relative space-y-4">
            <div class="sm:hidden space-y-2">
                <AppNoData v-if="!loading && !users.length" :message="t('backend.users.empty')" />
                <div v-for="user in users" :key="user.id" class="bg-surface border border-line/60 rounded-xl overflow-hidden shadow-sm">
                    <!-- Avatar + nom + email -->
                    <div class="flex items-center gap-3 p-4">
                        <AppAvatar
                            variant="solid"
                            :name="user.name"
                            :photo-url="user.profilePhotoUrl ?? ''"
                            :size="40"
                            class="shrink-0"
                        />
                        <div class="min-w-0">
                            <p class="font-medium text-primary text-sm truncate">{{ user.name }}</p>
                            <p class="text-xs text-muted truncate mt-0.5">{{ user.email }}</p>
                        </div>
                    </div>
                    <!-- Badges -->
                    <div class="flex flex-wrap gap-1 px-4 pb-3">
                        <AppBadge :color="statusBadgeColor(user.status)">{{ user.statusLabel }}</AppBadge>
                        <AppBadge :color="user.type === 'backend' ? 'accent' : 'gray'">{{ user.typeLabel }}</AppBadge>
                        <AppBadge v-if="user.isDev" :color="user.devColor">Dev</AppBadge>
                        <AppBadge v-if="user.roleLabel" :color="user.roleColor">{{ user.roleLabel }}</AppBadge>
                        <AppBadge v-if="isCurrent(user)" color="accent">{{ t('backend.users.you') }}</AppBadge>
                    </div>
                    <!-- Footer actions -->
                    <div class="flex justify-end px-3 py-2 border-t border-line/40 bg-surface-2/40">
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

            <div class="hidden sm:block bg-surface border border-line/60 rounded-xl overflow-x-auto scrollbar-thin">
                <AppNoData v-if="!loading && !users.length" :message="t('backend.users.empty')" />
                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="bg-surface-2/50 border-b border-line/40">
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t('backend.users.name') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">{{ t('backend.users.email') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell">{{ t('backend.users.role_label') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">{{ t('backend.users.type_label') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t('backend.users.status_label') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">{{ t('backend.users.created') }}</th>
                            <slot name="extra-headers" />
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted">{{ t('backend.users.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/40">
                        <tr v-for="user in users" :key="user.id" class="group hover:bg-surface-2/40 transition-colors">
                            <td class="px-4 py-3 text-primary font-medium">
                                <div class="flex items-center gap-3 min-w-0">
                                    <AppAvatar variant="solid" :name="user.name" :photo-url="user.profilePhotoUrl ?? ''" :size="32" />
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="truncate">{{ user.name }}</span>
                                            <AppBadge v-if="isCurrent(user)" color="accent">{{ t('backend.users.you') }}</AppBadge>
                                        </div>
                                        <p v-if="user.moodMessage" class="text-xs text-muted italic truncate" :title="user.moodMessage">“{{ user.moodMessage }}”</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-secondary hidden lg:table-cell">{{ user.email }}</td>
                            <td class="px-4 py-3 hidden md:table-cell">
                                <div class="flex items-center gap-1 flex-wrap">
                                    <AppBadge v-if="user.isDev" :color="user.devColor">Dev</AppBadge>
                                    <AppBadge v-if="user.roleLabel" :color="user.roleColor">{{ user.roleLabel }}</AppBadge>
                                </div>
                            </td>
                            <td class="px-4 py-3 hidden lg:table-cell">
                                <AppBadge :color="user.type === 'backend' ? 'accent' : 'gray'">{{ user.typeLabel }}</AppBadge>
                            </td>
                            <td class="px-4 py-3">
                                <AppBadge :color="statusBadgeColor(user.status)">{{ user.statusLabel }}</AppBadge>
                            </td>
                            <td class="px-4 py-3 text-xs text-muted hidden lg:table-cell">{{ formatDateShort(user.createdAt) }}</td>
                            <slot name="extra-cells" :user="user" />
                            <td class="px-4 py-3">
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
            :title="t('backend.users.invite')"
            :icon="UserPlus"
            :closeable="false"
            v-on:close="inviteModal.open = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitInvite">
                <AppInput
                    v-model="inviteForm.name"
                    :label="t('backend.users.name')"
                    :placeholder="t('backend.users.name_placeholder')"
                    :error="inviteModal.errors.name ?? ''"
                    required
                />
                <AppInput
                    v-model="inviteForm.email"
                    :label="t('backend.users.email')"
                    type="email"
                    :placeholder="t('backend.users.email_placeholder')"
                    :error="inviteModal.errors.email ?? ''"
                    required
                />
                <!-- Le type d'abord : il décide si la question du rôle a un
                     sens. Masqué quand il n'y a rien à choisir. -->
                <AppMultiselect
                    v-if="userTypes.length > 1"
                    v-model="inviteForm.type"
                    :options="userTypes"
                    :label="t('backend.users.type_label')"
                    :error="inviteModal.errors.type ?? ''"
                    required
                />
                <!-- Le site public n'a qu'un rôle, et ce n'est pas un choix de
                     l'opérateur : l'inscription publique pose ROLE_USER en dur,
                     et une invitation doit aboutir au même compte. Afficher un
                     sélecteur ici laisserait croire à une décision qui n'existe
                     pas - le serveur force la valeur de toute façon. -->
                <AppMultiselect
                    v-if="inviteForm.type !== 'frontend'"
                    v-model="inviteForm.role"
                    :options="roles"
                    :label="t('backend.users.role_label')"
                    :error="inviteModal.errors.role ?? ''"
                    required
                />
                <p v-else class="text-xs text-muted">{{ t('backend.users.invite_frontend_role_hint') }}</p>
                <!-- Le message n'a plus de destinataire quand rien ne part. -->
                <div v-if="!inviteForm.disabled">
                    <label class="block text-xs text-secondary uppercase tracking-wide mb-1.5">{{ t('backend.users.invite_message') }}</label>
                    <textarea
                        v-model="inviteForm.message"
                        rows="3"
                        class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-primary placeholder-muted focus:border-accent-500 focus:ring-1 focus:ring-accent-500 transition resize-none"
                        :placeholder="t('backend.users.invite_message_placeholder')"
                    />
                </div>

                <!-- Pré-provisionner : quelqu'un qui arrive plus tard. Le compte
                     existe, personne n'est contacté, et la connexion est refusée
                     jusqu'à ce qu'on l'ouvre depuis la liste - c'est cette
                     ouverture qui enverra l'invitation. -->
                <AppCheckbox
                    v-model="inviteForm.disabled"
                    :label="t('backend.users.invite_disabled')"
                    :hint="t('backend.users.invite_disabled_hint')"
                />

                <slot name="extra-invite-form-fields" :form="inviteForm" :errors="inviteModal.errors" />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="inviteModal.open = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.cancel') }}</AppButton>
                    <!-- Le libellé suit ce que le bouton fait vraiment : un
                         « Envoyer l'invitation » qui n'envoie rien est un
                         mensonge. -->
                    <AppButton type="submit" variant="primary" size="md" :loading="inviteModal.saving">
                        <component :is="inviteForm.disabled ? UserPlus : Send" class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ inviteForm.disabled ? t('backend.users.create_disabled_account') : t('backend.users.send_invite') }}
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
                        <dt class="text-xs text-secondary uppercase tracking-wide">{{ t('backend.users.status_label') }}</dt>
                        <dd class="mt-1">
                            <AppBadge :color="statusBadgeColor(viewingUser.status)">{{ viewingUser.statusLabel }}</AppBadge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-secondary uppercase tracking-wide">{{ t('backend.users.role_label') }}</dt>
                        <dd class="mt-1 flex items-center gap-1 flex-wrap">
                            <AppBadge v-if="viewingUser.isDev" :color="viewingUser.devColor">Dev</AppBadge>
                            <AppBadge v-if="viewingUser.roleLabel" :color="viewingUser.roleColor">{{ viewingUser.roleLabel }}</AppBadge>
                            <span v-if="!viewingUser.isDev && !viewingUser.roleLabel" class="text-muted">-</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-secondary uppercase tracking-wide">{{ t('backend.users.detail.type') }}</dt>
                        <dd class="mt-1 text-primary">{{ viewingUser.typeLabel }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-secondary uppercase tracking-wide">{{ t('backend.users.detail.locale') }}</dt>
                        <dd class="mt-1 text-primary">{{ t('shared.locales.' + viewingUser.locale) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-secondary uppercase tracking-wide">{{ t('backend.users.detail.created_at') }}</dt>
                        <dd class="mt-1 text-primary">{{ formatDate(viewingUser.createdAt) }}</dd>
                    </div>
                    <div v-if="viewingUser.invitedAt">
                        <dt class="text-xs text-secondary uppercase tracking-wide">{{ t('backend.users.detail.invited_at') }}</dt>
                        <dd class="mt-1 text-primary">{{ formatDate(viewingUser.invitedAt) }}</dd>
                    </div>
                </dl>

                <div class="border-t border-line/40 pt-4 space-y-4">
                    <div>
                        <p class="text-xs text-secondary uppercase tracking-wide mb-1.5">{{ t('backend.users.manager.label') }}</p>
                        <p v-if="viewingUser.manager" class="text-sm text-primary">{{ viewingUser.manager.name }}</p>
                        <p v-else class="text-sm text-muted">{{ t('backend.users.manager.none') }}</p>
                    </div>
                    <div v-if="viewingUser.subordinates && viewingUser.subordinates.length">
                        <p class="text-xs text-secondary uppercase tracking-wide mb-1.5">
                            {{ t('backend.users.manager.subordinates', { count: viewingUser.subordinatesCount }) }}
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
            :title="t('backend.users.edit_title', { name: editModal.editing?.name ?? '' })"
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
                                    {{ t('backend.users.photo.upload') }}
                                </AppButton>
                                <AppButton
                                    v-if="editModal.editing?.profilePhotoUrl"
                                    variant="ghost"
                                    size="sm"
                                    :loading="editModal.photoUploading"
                                    v-on:click="removePhoto"
                                >
                                    <Trash2 class="w-3.5 h-3.5" :stroke-width="2" />
                                    {{ t('backend.users.photo.remove') }}
                                </AppButton>
                            </div>
                        </template>
                    </AppFileInput>
                    <p class="text-xs text-muted">{{ t('backend.users.photo.hint') }}</p>
                </div>
            </div>

            <form class="space-y-4" v-on:submit.prevent="submitEdit">
                <div class="grid grid-cols-2 gap-4">
                    <AppInput
                        v-model="editForm.name"
                        :label="t('backend.users.name')"
                        :placeholder="t('shared.placeholders.name')"
                        :error="editModal.errors.name ?? ''"
                    />
                    <AppInput
                        v-model="editForm.email"
                        :label="t('backend.users.email')"
                        :placeholder="t('shared.placeholders.email')"
                        type="email"
                        :error="editModal.errors.email ?? ''"
                    />
                    <AppMultiselect
                        v-model="editForm.role"
                        :options="roles"
                        :label="t('backend.users.role_label')"
                        :allow-empty="false"
                        :error="editModal.errors.role ?? ''"
                        open-direction="top"
                        :use-teleport="false"
                        required
                    />
                    <AppMultiselect
                        v-model="editForm.managerId"
                        :options="managerOptions"
                        :label="t('backend.users.manager.label')"
                        :allow-empty="true"
                        :error="editModal.errors.managerId ?? ''"
                        open-direction="top"
                        :use-teleport="false"
                    />
                </div>
                <AppInput
                    v-model="editForm.password"
                    :label="t('backend.users.new_password')"
                    type="password"
                    :placeholder="t('backend.users.new_password_placeholder')"
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
                        <p class="text-xs text-muted">{{ t('backend.users.privileges.title') }}</p>
                    </div>
                </div>
                <div v-for="group in privilegesByModule" :key="group.module" class="space-y-2">
                    <p class="text-xs font-semibold text-secondary uppercase tracking-wider">{{ t('backend.modules.' + group.module, group.module) }}</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <AppCheckbox
                            v-for="priv in group.privileges"
                            :key="priv"
                            :model-value="pendingPrivileges.includes(priv)"
                            v-on:update:model-value="togglePrivilege(priv)"
                        >
                            <span class="text-xs">{{ t('backend.permissions.names.' + priv, priv) }}</span>
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
                        <p class="text-xs text-muted">{{ t('backend.users.modules.title') }}</p>
                    </div>
                </div>
                <p class="text-xs text-muted">{{ t('backend.users.modules.hint') }}</p>
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
            <p class="text-sm text-primary">{{ t('backend.users.delete_confirm', {name: deletingUser?.name ?? ''}) }}</p>
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
