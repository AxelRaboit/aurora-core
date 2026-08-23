<script setup>
import { onMounted } from "vue";
import { useI18n } from "vue-i18n";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppAvatar from "@/shared/components/display/AppAvatar.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { Plus, Save, X, Trash2, Check, UserPlus, Pencil } from "lucide-vue-next";
import { useUsers } from "./composables/useUsers.js";
import UserBadges from "./UserBadges.vue";
import UserActions from "./UserActions.vue";
import { LOCALE_OPTIONS } from "@core/utils/locales.js";

const { t } = useI18n();
const { formatDateShort } = useDateFormat();

const props = defineProps({
    usersPath: { type: String, required: true },
    userCreatePath: { type: String, required: true },
    userUpdatePath: { type: String, required: true },
    userToggleRolePath: { type: String, required: true },
    userDeletePath: { type: String, required: true },
    impersonatePath: { type: String, required: true },
    csrfToken: { type: String, default: "" },
    initialData: { type: Object, default: null },
    initialSearch: { type: String, default: "" },
});

const users = useUsers(
    props.usersPath,
    props.userCreatePath,
    props.userUpdatePath,
    props.userToggleRolePath,
    props.userDeletePath,
    props.impersonatePath,
    props.csrfToken,
    props.initialData,
    props.initialSearch,
);

onMounted(() => {
    if (!users.parsedUsers.value.items?.length) users.load();
});
</script>

<template>
    <div class="space-y-4">
        <p class="text-sm text-secondary">{{ t('backend.users.intro') }}</p>
        <div class="grid grid-cols-1 sm:grid-cols-[1fr_auto] gap-2">
            <AppSearchInput
                v-model="users.searchInput.value"
                :placeholder="t('backend.users.search_placeholder')"
                v-on:search="users.performSearch"
            />
            <AppButton variant="primary" size="md" class="w-full sm:w-auto" v-on:click="users.openCreate">
                <Plus class="w-3.5 h-3.5" :stroke-width="2" />
                {{ t('shared.common.add') }}
            </AppButton>
        </div>

        <div class="sm:hidden space-y-3">
            <p v-if="!users.parsedUsers.value.items?.length" class="py-8 text-center text-sm text-muted">{{ t('backend.users.empty') }}</p>
            <div v-for="user in users.parsedUsers.value.items" :key="user.id" class="bg-surface border border-line rounded-lg overflow-hidden">
                <div class="flex items-center gap-3 p-4">
                    <AppAvatar :name="user.name" :email="user.email" size="lg" class="shrink-0" />
                    <div class="min-w-0">
                        <p class="font-medium text-primary truncate">{{ user.name }}</p>
                        <p class="text-xs text-secondary truncate">{{ user.email }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-1 px-4 pb-3">
                    <UserBadges :user="user" />
                </div>
                <div class="flex items-center justify-between px-3 py-2 border-t border-line bg-surface-2/40">
                    <p class="text-xs text-muted">{{ formatDateShort(user.createdAt) }}</p>
                    <UserActions
                        :user="user"
                        :impersonate-path="users.impersonatePath"
                        v-on:edit="users.openEdit"
                        v-on:toggle-role="users.confirmToggleRole"
                        v-on:delete="users.confirmDelete"
                    />
                </div>
            </div>
        </div>

        <div class="hidden sm:block bg-surface border border-line rounded-lg overflow-x-auto scrollbar-thin">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-surface-2/50 border-b border-line/40">
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t('backend.users.name') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t('backend.users.email') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell">{{ t('backend.users.role_label') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">{{ t('backend.users.locale') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">{{ t('backend.users.created') }}</th>
                        <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted">{{ t('backend.users.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line/40">
                    <tr v-for="user in users.parsedUsers.value.items" :key="user.id" class="group hover:bg-surface-2/40 transition-colors">
                        <td class="px-6 py-3">
                            <div class="flex items-center gap-3">
                                <AppAvatar :name="user.name" :email="user.email" size="md" />
                                <p class="font-medium text-primary inline-flex items-center gap-1.5">
                                    {{ user.name }}
                                    <AppBadge v-if="user.isCurrent" color="accent">{{ t('backend.users.you') }}</AppBadge>
                                </p>
                            </div>
                        </td>
                        <td class="px-6 py-3 text-secondary">{{ user.email }}</td>
                        <td class="px-6 py-3 hidden md:table-cell">
                            <AppBadge :color="user.isDevRole ? 'accent' : 'gray'">
                                {{ user.isDevRole ? t('backend.users.role_dev') : t('backend.users.role_user') }}
                            </AppBadge>
                        </td>
                        <td class="px-6 py-3 hidden lg:table-cell">
                            <AppBadge color="gray" class="uppercase">{{ user.locale }}</AppBadge>
                        </td>
                        <td class="px-6 py-3 text-sm text-secondary hidden lg:table-cell">{{ formatDateShort(user.createdAt) }}</td>
                        <td class="px-6 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <UserActions
                                    :user="user"
                                    :impersonate-path="users.impersonatePath"
                                    v-on:edit="users.openEdit"
                                    v-on:toggle-role="users.confirmToggleRole"
                                    v-on:delete="users.confirmDelete"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!users.parsedUsers.value.items?.length">
                        <td colspan="6" class="px-6 py-8 text-center text-sm text-muted">{{ t('backend.users.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <AppModal
            :show="!!users.pendingDelete.value"
            max-width="sm"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="users.pendingDelete.value = null"
        >
            <p class="text-sm text-primary">{{ t('backend.users.delete_confirm', { name: users.pendingDelete.value?.name }) }}</p>
            <div class="flex justify-end gap-2">
                <AppButton variant="ghost" size="md" v-on:click="users.pendingDelete.value = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.cancel') }}</AppButton>
                <AppButton variant="danger" size="md" v-on:click="users.doDelete"><Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.delete') }}</AppButton>
            </div>
        </AppModal>

        <AppModal
            :show="users.showCreateModal.value"
            max-width="md"
            :title="t('backend.users.add')"
            :icon="UserPlus"
            :closeable="false"
            v-on:close="users.showCreateModal.value = false"
        >
            <form class="space-y-4" v-on:submit.prevent="users.submitCreate">
                <AppInput
                    v-model="users.newUser.value.name"
                    :label="t('backend.users.name')"
                    :placeholder="t('shared.placeholders.name')"
                    :error="users.createErrors.value.name"
                    autocomplete="name"
                    required
                />
                <AppInput
                    v-model="users.newUser.value.email"
                    type="email"
                    :label="t('backend.users.email')"
                    :placeholder="t('shared.placeholders.email')"
                    :error="users.createErrors.value.email"
                    autocomplete="email"
                    required
                />
                <AppInput
                    v-model="users.newUser.value.password"
                    :label="t('backend.users.password')"
                    :placeholder="t('shared.placeholders.password')"
                    :error="users.createErrors.value.password"
                    autocomplete="new-password"
                    toggleable
                    required
                />
                <AppSelect v-model="users.newUser.value.locale" :label="t('backend.users.locale')" :options="LOCALE_OPTIONS" />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="users.showCreateModal.value = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.cancel') }}</AppButton>
                    <AppButton type="submit" variant="primary" size="md" :loading="users.createLoading.value"><Plus class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.create') }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="users.showEditModal.value"
            max-width="md"
            :title="t('backend.users.edit_title', { name: users.editingUser.value?.name ?? '' })"
            :icon="Pencil"
            :closeable="false"
            v-on:close="users.closeEdit"
        >
            <form class="space-y-4" v-on:submit.prevent="users.submitEdit">
                <AppInput
                    v-model="users.editUserForm.value.name"
                    :label="t('backend.users.name')"
                    :placeholder="t('shared.placeholders.name')"
                    :error="users.editErrors.value.name"
                    autocomplete="name"
                    required
                />
                <AppInput
                    v-model="users.editUserForm.value.email"
                    type="email"
                    :label="t('backend.users.email')"
                    :placeholder="t('shared.placeholders.email')"
                    :error="users.editErrors.value.email"
                    autocomplete="email"
                    required
                />
                <AppInput
                    v-model="users.editUserForm.value.password"
                    :label="t('backend.users.password_optional')"
                    :placeholder="t('shared.placeholders.password')"
                    :error="users.editErrors.value.password"
                    autocomplete="new-password"
                    toggleable
                />
                <AppSelect v-model="users.editUserForm.value.locale" :label="t('backend.users.locale')" :options="LOCALE_OPTIONS" />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="users.closeEdit"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.cancel') }}</AppButton>
                    <AppButton type="submit" variant="primary" size="md" :loading="users.editLoading.value"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.save') }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal :show="!!users.pendingToggleRole.value" max-width="sm" :closeable="false" v-on:close="users.pendingToggleRole.value = null">
            <p class="text-sm text-primary">
                {{ users.pendingToggleRole.value?.isDevRole
                    ? t('backend.users.revoke_dev_confirm', { name: users.pendingToggleRole.value?.name })
                    : t('backend.users.grant_dev_confirm', { name: users.pendingToggleRole.value?.name }) }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="users.pendingToggleRole.value = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.cancel') }}</AppButton>
                    <AppButton variant="primary" size="md" v-on:click="users.doToggleRole"><Check class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.confirm') }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
