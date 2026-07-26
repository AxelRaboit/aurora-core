<script setup>
import { useI18n } from "vue-i18n";
import { Upload, Trash2, Save, SlidersHorizontal } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppFileInput from "@/shared/components/form/file/AppFileInput.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppPasswordStrength from "@/shared/components/form/input/AppPasswordStrength.vue";
import AppAvatar from "@/shared/components/display/AppAvatar.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppCardLink from "@/shared/components/nav/AppCardLink.vue";
import { useProfileLocale } from "@general/backend/profile/composables/useProfileLocale.js";
import { LOCALE_OPTIONS } from "@core/utils/locales.js";
import { useProfileInfo } from "@general/backend/profile/composables/useProfileInfo.js";
import { useProfileMood } from "@general/backend/profile/composables/useProfileMood.js";
import { useProfilePassword } from "@general/backend/profile/composables/useProfilePassword.js";
import { useProfileDelete } from "@general/backend/profile/composables/useProfileDelete.js";
import { useProfilePhoto } from "@general/backend/profile/composables/useProfilePhoto.js";
import { useProfileAccount } from "@general/backend/profile/composables/useProfileAccount.js";

const { t } = useI18n();

const props = defineProps({
    userName: { type: String, default: "" },
    userEmail: { type: String, default: "" },
    userPhotoUrl: { type: String, default: "" },
    userMoodMessage: { type: String, default: "" },
    moodMessageMaxLength: { type: Number, required: true },
    locale: { type: String, default: "fr" },
    updatePath: { type: String, required: true },
    passwordPath: { type: String, required: true },
    localePath: { type: String, required: true },
    moodPath: { type: String, required: true },
    photoUploadPath: { type: String, required: true },
    photoDeletePath: { type: String, required: true },
    deletePath: { type: String, required: true },
    loginPath: { type: String, required: true },
    deleteCsrf: { type: String, default: "" },
    canDeleteAccount: { type: Boolean, default: true },
    sidemenuPreferencesPath: { type: String, default: "" },
    accountInfo: { type: Object, default: () => ({}) },
});

const account = useProfileAccount(props.accountInfo, props.locale);

const { selectedLocale, localeLoading, changeLocale } = useProfileLocale(props.localePath, props.locale);
const { infoName, infoEmail, infoLoading, infoErrors, saveInfo } = useProfileInfo(props.updatePath, props.userName, props.userEmail);
const { moodMessage, moodLoading, moodError, saveMood } = useProfileMood(props.moodPath, props.userMoodMessage, props.moodMessageMaxLength);
const { currentPassword, newPassword, confirmPassword, passwordLoading, passwordErrors, savePassword } = useProfilePassword(props.passwordPath);
const { deleteLoading, deleteAccount } = useProfileDelete(props.deletePath, props.loginPath, props.deleteCsrf);
const { photoUrl, photoLoading, onPhotoSelected, removePhoto } = useProfilePhoto(props.photoUploadPath, props.photoDeletePath, props.userPhotoUrl);
</script>

<template>
    <div class="max-w-5xl mx-auto space-y-6">
        <div class="grid lg:grid-cols-2 gap-6 items-start">
            <!-- Left column: identity & security -->
            <div class="space-y-6">
                <div class="bg-surface border border-line/60 rounded-2xl p-6 shadow-sm">
                    <header class="mb-6">
                        <h2 class="text-lg font-semibold text-primary">{{ t('backend.profile.photo.title') }}</h2>
                        <p class="mt-1 text-sm text-secondary">{{ t('backend.profile.photo.subtitle') }}</p>
                    </header>
                    <div class="flex items-center gap-5">
                        <AppAvatar variant="solid" :name="userName" :photo-url="photoUrl" :size="80" />
                        <div class="flex flex-col gap-2">
                            <AppFileInput accept="image/jpeg,image/png,image/webp" v-on:change="onPhotoSelected">
                                <template #default="{ trigger }">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <AppButton variant="ghost" size="sm" :loading="photoLoading" v-on:click="trigger">
                                            <Upload class="w-3.5 h-3.5" :stroke-width="2" />
                                            {{ t('backend.users.photo.upload') }}
                                        </AppButton>
                                        <AppButton
                                            v-if="photoUrl"
                                            variant="ghost"
                                            size="sm"
                                            :loading="photoLoading"
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
                </div>

                <div class="bg-surface border border-line/60 rounded-2xl p-6 shadow-sm">
                    <header class="mb-6">
                        <h2 class="text-lg font-semibold text-primary">{{ t('backend.profile.info.title') }}</h2>
                        <p class="mt-1 text-sm text-secondary">{{ t('backend.profile.info.subtitle') }}</p>
                    </header>
                    <form class="space-y-4" v-on:submit.prevent="saveInfo">
                        <AppInput
                            v-model="infoName"
                            :label="t('backend.profile.info.name')"
                            :placeholder="t('backend.profile.info.name_placeholder')"
                            :error="infoErrors.name"
                            autocomplete="name"
                            required
                        />
                        <AppInput
                            v-model="infoEmail"
                            type="email"
                            :label="t('backend.profile.info.email')"
                            :placeholder="t('backend.profile.info.email_placeholder')"
                            :error="infoErrors.email"
                            autocomplete="email"
                            required
                        />
                        <div class="pt-1">
                            <AppButton type="submit" :loading="infoLoading"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.save') }}</AppButton>
                        </div>
                    </form>
                </div>

                <div class="bg-surface border border-line/60 rounded-2xl p-6 shadow-sm">
                    <header class="mb-6">
                        <h2 class="text-lg font-semibold text-primary">{{ t('backend.profile.password.title') }}</h2>
                        <p class="mt-1 text-sm text-secondary">{{ t('backend.profile.password.subtitle') }}</p>
                    </header>
                    <form class="space-y-4" v-on:submit.prevent="savePassword">
                        <AppInput
                            v-model="currentPassword"
                            :label="t('backend.profile.password.current')"
                            :error="passwordErrors.current_password"
                            placeholder="••••••••"
                            autocomplete="current-password"
                            toggleable
                            required
                        />
                        <div>
                            <AppInput
                                v-model="newPassword"
                                :label="t('backend.profile.password.new')"
                                :error="passwordErrors.password"
                                placeholder="••••••••"
                                autocomplete="new-password"
                                toggleable
                                required
                            />
                            <AppPasswordStrength :password="newPassword" />
                        </div>
                        <AppInput
                            v-model="confirmPassword"
                            :label="t('backend.profile.password.confirm')"
                            :error="passwordErrors.password_confirmation"
                            placeholder="••••••••"
                            autocomplete="new-password"
                            toggleable
                            required
                        />
                        <div class="pt-1">
                            <AppButton type="submit" :loading="passwordLoading"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.save') }}</AppButton>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right column: personalization -->
            <div class="space-y-6">
                <div class="bg-surface border border-line/60 rounded-2xl p-6 shadow-sm">
                    <header class="mb-6">
                        <h2 class="text-lg font-semibold text-primary">{{ t('backend.profile.locale.title') }}</h2>
                        <p class="mt-1 text-sm text-secondary">{{ t('backend.profile.locale.subtitle') }}</p>
                    </header>
                    <AppSelect
                        v-model="selectedLocale"
                        :label="t('backend.profile.locale.field')"
                        :options="LOCALE_OPTIONS"
                        :disabled="localeLoading"
                        v-on:update:model-value="changeLocale"
                    />
                </div>

                <AppCardLink
                    v-if="sidemenuPreferencesPath"
                    :href="sidemenuPreferencesPath"
                    :icon="SlidersHorizontal"
                    :title="t('backend.profile.preferences.title')"
                    :description="t('backend.profile.preferences.subtitle')"
                />

                <div class="bg-surface border border-line/60 rounded-2xl p-6 shadow-sm">
                    <header class="mb-6">
                        <h2 class="text-lg font-semibold text-primary">{{ t('backend.profile.mood.title') }}</h2>
                        <p class="mt-1 text-sm text-secondary">{{ t('backend.profile.mood.subtitle') }}</p>
                    </header>
                    <form class="space-y-3" v-on:submit.prevent="saveMood">
                        <div>
                            <AppTextarea
                                v-model="moodMessage"
                                :rows="6"
                                :maxlength="moodMessageMaxLength"
                                :placeholder="t('backend.profile.mood.placeholder')"
                                :error="moodError"
                            />
                            <div v-if="!moodError" class="mt-1 flex items-center justify-between">
                                <span class="text-xs text-muted">{{ t('backend.profile.mood.hint') }}</span>
                                <span class="text-xs text-muted">{{ moodMessage.length }}/{{ moodMessageMaxLength }}</span>
                            </div>
                        </div>
                        <div class="pt-1">
                            <AppButton type="submit" :loading="moodLoading"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.save') }}</AppButton>
                        </div>
                    </form>
                </div>

                <div class="bg-surface border border-line/60 rounded-2xl p-6 shadow-sm">
                    <header class="mb-6">
                        <h2 class="text-lg font-semibold text-primary">{{ t('backend.profile.account.title') }}</h2>
                        <p class="mt-1 text-sm text-secondary">{{ t('backend.profile.account.subtitle') }}</p>
                    </header>

                    <div class="flex items-center gap-4 mb-5 pb-5 border-b border-line/40">
                        <AppAvatar
                            variant="solid"
                            :name="userName"
                            :photo-url="photoUrl"
                            :size="56"
                            class="shrink-0"
                        />
                        <div class="min-w-0 flex-1">
                            <p class="text-base font-semibold text-primary truncate">{{ userName }}</p>
                            <p class="text-sm text-secondary truncate">{{ userEmail }}</p>
                            <p v-if="moodMessage" class="mt-1 text-xs text-muted italic truncate">“{{ moodMessage }}”</p>
                        </div>
                    </div>

                    <dl class="divide-y divide-line/40 text-sm">
                        <div v-if="account.info.value.reference" class="grid grid-cols-3 gap-3 py-2.5">
                            <dt class="text-secondary">{{ t('backend.profile.account.reference') }}</dt>
                            <dd class="col-span-2 font-mono text-primary truncate">{{ account.info.value.reference }}</dd>
                        </div>
                        <div v-if="account.info.value.role" class="grid grid-cols-3 gap-3 py-2.5 items-center">
                            <dt class="text-secondary">{{ t('backend.profile.account.role') }}</dt>
                            <dd class="col-span-2">
                                <AppBadge :color="account.roleColor(account.info.value.role)">
                                    {{ t(`backend.users.role.${account.info.value.role}`) }}
                                </AppBadge>
                            </dd>
                        </div>
                        <div v-if="account.info.value.type" class="grid grid-cols-3 gap-3 py-2.5">
                            <dt class="text-secondary">{{ t('backend.profile.account.type') }}</dt>
                            <dd class="col-span-2 text-primary">{{ t(`backend.users.type.${account.info.value.type}`) }}</dd>
                        </div>
                        <div v-if="account.info.value.status" class="grid grid-cols-3 gap-3 py-2.5 items-center">
                            <dt class="text-secondary">{{ t('backend.profile.account.status') }}</dt>
                            <dd class="col-span-2">
                                <AppBadge :color="account.statusColor(account.info.value.status)">
                                    {{ t(`backend.users.status.${account.info.value.status}`) }}
                                </AppBadge>
                            </dd>
                        </div>
                        <div v-if="account.info.value.manager" class="grid grid-cols-3 gap-3 py-2.5">
                            <dt class="text-secondary">{{ t('backend.profile.account.manager') }}</dt>
                            <dd class="col-span-2 text-primary truncate">{{ account.info.value.manager }}</dd>
                        </div>
                        <div v-if="account.formattedCreatedAt.value" class="grid grid-cols-3 gap-3 py-2.5">
                            <dt class="text-secondary">{{ t('backend.profile.account.created_at') }}</dt>
                            <dd class="col-span-2 text-primary">{{ account.formattedCreatedAt.value }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>

        <!-- Full-width danger zone -->
        <div class="bg-surface border border-rose-900/40 rounded-2xl p-6 shadow-sm">
            <header class="mb-6">
                <h2 class="text-lg font-semibold text-rose-400">{{ t('backend.profile.danger.title') }}</h2>
                <p class="mt-1 text-sm text-secondary">{{ t('backend.profile.danger.description') }}</p>
            </header>
            <AppButton
                variant="danger"
                size="md"
                :disabled="deleteLoading || !canDeleteAccount"
                v-on:click="deleteAccount"
            >
                <Trash2 class="w-4 h-4" :stroke-width="2" />
                {{ t('backend.profile.danger.submit') }}
            </AppButton>
            <p v-if="!canDeleteAccount" class="mt-3 text-xs text-muted">
                {{ t('backend.profile.danger.dev_protected') }}
            </p>
        </div>
    </div>
</template>
