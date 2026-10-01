<script setup>
import { inject } from "vue";
import { useI18n } from "vue-i18n";
import { Bell, Globe, Save, Type } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";

/**
 * Ce qui entoure les questions, rangé par la question qu'on se pose.
 *
 * Comment il s'appelle et où il vit, s'il est en ligne, et ce qui se passe
 * quand quelqu'un répond. L'ancienne fenêtre mettait les trois dans une seule
 * colonne, le webhook avant le titre, et chaque langue répétait les mêmes
 * trois champs les uns sous les autres.
 */
const { t } = useI18n();
const { can } = usePrivileges();
const canEdit = can("editorial.forms.edit");

const { locales, editLocale, settings, settingsErrors, settingsLoading, submitSettings, publicPaths } = inject("formEditor");

function hasTitleIn(locale) {
    return "" !== (settings.value.translations[locale]?.title ?? "").trim();
}
</script>

<template>
    <form class="space-y-4" v-on:submit.prevent="submitSettings">
        <section class="aurora-card space-y-4 p-3 sm:p-5">
            <div>
                <h3 class="flex items-center gap-2 text-sm font-semibold text-primary">
                    <Type class="h-4 w-4 text-muted" :stroke-width="2" /> {{ t("backend.forms.settings.identity_title") }}
                </h3>
                <p class="m-0 mt-0.5 text-xs text-muted">{{ t("backend.forms.settings.identity_hint") }}</p>
            </div>

            <div v-if="locales.length > 1" class="inline-flex gap-1 rounded-lg border border-line bg-surface-2 p-1">
                <AppTab
                    v-for="code in locales"
                    :key="code"
                    size="sm"
                    :active="editLocale === code"
                    active-class="bg-surface text-primary shadow-sm"
                    inactive-class="text-secondary hover:text-primary"
                    v-on:click="editLocale = code"
                >
                    {{ code.toUpperCase() }}
                    <span
                        v-if="editLocale !== code && !hasTitleIn(code)"
                        class="ms-1 inline-block h-1.5 w-1.5 rounded-full bg-current opacity-50"
                        :title="t('backend.forms.settings.untranslated')"
                    />
                </AppTab>
            </div>

            <AppInput
                v-model="settings.translations[editLocale].title"
                :label="t('backend.forms.title_label')"
                :placeholder="t('backend.forms.create_title_placeholder')"
                :readonly="!canEdit"
                :error="settingsErrors.translations ?? ''"
            />
            <AppTextarea
                v-model="settings.translations[editLocale].description"
                :label="t('backend.forms.description')"
                :placeholder="t('backend.forms.settings.description_placeholder')"
                :hint="t('backend.forms.settings.description_hint')"
                :rows="2"
            />
            <AppInput
                v-model="settings.translations[editLocale].slug"
                :label="t('backend.forms.settings.slug_label')"
                :placeholder="t('shared.placeholders.slug')"
                :hint="t('backend.forms.slug_hint')"
                :readonly="!canEdit"
                :error="settingsErrors[`translations[${editLocale}].slug`] ?? ''"
            />
            <p v-if="publicPaths[editLocale]" class="m-0 text-xs text-muted">
                {{ t("backend.forms.settings.public_address") }}
                <a class="font-mono text-accent hover:underline" :href="publicPaths[editLocale]" target="_blank" rel="noopener">{{ publicPaths[editLocale] }}</a>
            </p>
        </section>

        <section class="aurora-card space-y-4 p-3 sm:p-5">
            <div>
                <h3 class="flex items-center gap-2 text-sm font-semibold text-primary">
                    <Globe class="h-4 w-4 text-muted" :stroke-width="2" /> {{ t("backend.forms.settings.online_title") }}
                </h3>
            </div>
            <AppToggle
                v-model="settings.active"
                :disabled="!canEdit"
                :label="t('backend.forms.settings.active_label')"
                :hint="t('backend.forms.settings.active_hint')"
            />
            <AppToggle
                v-model="settings.standalonePageIndexed"
                :disabled="!canEdit"
                :label="t('backend.forms.standalone_page_indexed')"
                :hint="t('backend.forms.standalone_page_indexed_hint')"
            />
        </section>

        <section class="aurora-card space-y-4 p-3 sm:p-5">
            <div>
                <h3 class="flex items-center gap-2 text-sm font-semibold text-primary">
                    <Bell class="h-4 w-4 text-muted" :stroke-width="2" /> {{ t("backend.forms.settings.answers_title") }}
                </h3>
                <p class="m-0 mt-0.5 text-xs text-muted">{{ t("backend.forms.settings.answers_hint") }}</p>
            </div>
            <AppInput
                v-model="settings.notifyEmail"
                :label="t('backend.forms.settings.notify_label')"
                :placeholder="t('shared.placeholders.email')"
                :hint="t('backend.forms.notify_email_hint')"
                :readonly="!canEdit"
                :error="settingsErrors.notifyEmail ?? ''"
            />
            <AppToggle
                v-model="settings.crmSync"
                :disabled="!canEdit"
                :label="t('backend.forms.settings.crm_label')"
                :hint="t('backend.forms.settings.crm_hint')"
            />
            <AppInput
                v-model="settings.webhookUrl"
                :label="t('backend.forms.settings.webhook_label')"
                :placeholder="t('shared.placeholders.url')"
                :hint="t('backend.forms.settings.webhook_hint')"
                :readonly="!canEdit"
                :error="settingsErrors.webhookUrl ?? ''"
            />
        </section>

        <div v-if="canEdit" class="flex justify-end">
            <AppButton
                variant="primary"
                size="md"
                class="w-full justify-center sm:w-auto"
                :loading="settingsLoading"
                v-on:click="submitSettings"
            >
                <Save class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}
            </AppButton>
        </div>
    </form>
</template>
