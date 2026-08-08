<script setup>
import { useI18n } from "vue-i18n";
import { usePostEditor } from "./composables/usePostEditor.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppBlockEditor from "@/shared/components/editor/AppBlockEditor.vue";
import AppImagePickerField from "@/shared/components/form/file/AppImagePickerField.vue";
import PostBannerPanel from "./components/PostBannerPanel.vue";
import { Save, ArrowLeft, AlertTriangle, RefreshCw } from "lucide-vue-next";

const { t } = useI18n();

const props = defineProps({
    post: { type: Object, default: null },
    postTypes: { type: Array, default: () => [] },
    taxonomies: { type: Array, default: () => [] },
    locales: { type: Array, default: () => [] },
    statusOptions: { type: Array, default: () => [] },
    createPath: { type: String, required: true },
    updatePathTemplate: { type: String, required: true },
    editPathTemplate: { type: String, required: true },
    listPath: { type: String, required: true },
    bannerPreviewPath: { type: String, required: true },
    searchPath: { type: String, required: true },
});

const {
    form, locale, current, errors, saving, conflict,
    availableTaxonomies, supportsBlocks, supportsThumbnail, customFieldDefinitions,
    switchLocale, save, saveAnyway, reloadFromServer, toggleTerm, setCustomField,
} = usePostEditor(props);

const postTypeOptions = props.postTypes.map((type) => ({ value: type.id, label: type.label }));
const statusSelectOptions = props.statusOptions.map((status) => ({
    value: status,
    label: t(`backend.posts.status.${status}`),
}));

function termLabel(term) {
    return term.translations?.[props.locales[0]]?.name ?? `#${term.id}`;
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between gap-3">
            <AppButton variant="ghost" size="md" :href="listPath">
                <ArrowLeft class="w-4 h-4" :stroke-width="2" /> {{ t("backend.posts.back_to_list") }}
            </AppButton>
            <AppButton variant="primary" size="md" :loading="saving" v-on:click="save(false)">
                <Save class="w-4 h-4" :stroke-width="2" /> {{ t("shared.common.save") }}
            </AppButton>
        </div>

        <div v-if="locales.length > 1" class="inline-flex p-1 bg-surface-2 border border-line rounded-lg gap-1">
            <AppTab
                v-for="code in locales"
                :key="code"
                size="sm"
                :active="locale === code"
                active-class="bg-surface text-primary shadow-sm"
                inactive-class="text-secondary hover:text-primary"
                v-on:click="switchLocale(code)"
            >
                {{ code }}
            </AppTab>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-[1fr_20rem] gap-4">
            <!-- Content -->
            <section class="space-y-4">
                <div class="bg-surface border border-line rounded-xl p-5 space-y-4">
                    <AppInput
                        v-model="current.title"
                        :label="t('backend.posts.field_title')"
                        :error="errors.title"
                    />
                    <AppInput
                        v-model="current.slug"
                        :label="t('backend.posts.field_slug')"
                        :hint="t('backend.posts.slug_hint')"
                    />
                    <AppTextarea
                        v-model="current.description"
                        :label="t('backend.posts.field_description')"
                        :hint="t('backend.posts.description_hint')"
                        :rows="3"
                    />
                </div>

                <div class="bg-surface border border-line rounded-xl p-5 space-y-4">
                    <h3 class="text-sm font-semibold text-primary">{{ t("backend.posts.banner.title") }}</h3>
                    <PostBannerPanel :banner="current.banner" :preview-path="bannerPreviewPath" />
                </div>

                <div v-if="supportsBlocks" class="bg-surface border border-line rounded-xl p-5 space-y-3">
                    <h3 class="text-sm font-semibold text-primary">{{ t("backend.posts.content") }}</h3>
                    <AppBlockEditor v-model="current.blocks" :placeholder="t('backend.posts.content_placeholder')" />
                </div>

                <div v-if="customFieldDefinitions.length" class="bg-surface border border-line rounded-xl p-5 space-y-4">
                    <h3 class="text-sm font-semibold text-primary">{{ t("backend.posts.custom_fields") }}</h3>
                    <template v-for="field in customFieldDefinitions" :key="field.id">
                        <AppCheckbox
                            v-if="field.type === 'checkbox'"
                            :model-value="!!current.customFields[field.name]"
                            :label="field.label"
                            v-on:update:model-value="setCustomField(field.name, $event)"
                        />
                        <AppSelect
                            v-else-if="field.type === 'select'"
                            :model-value="current.customFields[field.name] ?? null"
                            :label="field.label"
                            :options="(field.options?.choices ?? []).map((choice) => ({ value: choice, label: choice }))"
                            v-on:update:model-value="setCustomField(field.name, $event)"
                        />
                        <AppTextarea
                            v-else-if="field.type === 'textarea'"
                            :model-value="current.customFields[field.name] ?? ''"
                            :label="field.label"
                            :rows="3"
                            v-on:update:model-value="setCustomField(field.name, $event)"
                        />
                        <AppInput
                            v-else
                            :model-value="current.customFields[field.name] ?? ''"
                            :label="field.label"
                            :type="field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'"
                            :required="field.required"
                            v-on:update:model-value="setCustomField(field.name, $event)"
                        />
                    </template>
                </div>

                <div class="bg-surface border border-line rounded-xl p-5 space-y-4">
                    <h3 class="text-sm font-semibold text-primary">{{ t("backend.posts.seo") }}</h3>
                    <AppInput v-model="current.metaTitle" :label="t('backend.posts.meta_title')" />
                    <AppTextarea
                        v-model="current.metaDescription"
                        :label="t('backend.posts.meta_description')"
                        :hint="t('backend.posts.meta_description_hint')"
                        :rows="2"
                    />
                    <AppInput v-model="current.focusKeyword" :label="t('backend.posts.focus_keyword')" />
                    <AppInput v-model="current.canonicalUrl" :label="t('backend.posts.canonical_url')" />
                    <AppCheckbox v-model="current.noindex" :label="t('backend.posts.noindex')" :hint="t('backend.posts.noindex_hint')" />
                </div>
            </section>

            <!-- Settings -->
            <aside class="space-y-4">
                <div class="bg-surface border border-line rounded-xl p-5 space-y-4">
                    <AppSelect
                        v-model="form.postTypeId"
                        :label="t('backend.posts.field_post_type')"
                        :options="postTypeOptions"
                        :error="errors.postTypeId"
                    />
                    <AppSelect
                        v-model="form.status"
                        :label="t('backend.posts.field_status')"
                        :options="statusSelectOptions"
                        :error="errors.status"
                    />
                    <AppInput
                        v-if="form.status === 'scheduled'"
                        v-model="form.scheduledAt"
                        type="datetime-local"
                        :label="t('backend.posts.field_scheduled_at')"
                        :error="errors.scheduledAt"
                    />
                    <AppCheckbox v-model="form.commentsEnabled" :label="t('backend.posts.comments_enabled')" />
                </div>

                <div v-if="supportsThumbnail" class="bg-surface border border-line rounded-xl p-5">
                    <AppImagePickerField
                        v-model="form.featuredMedia"
                        :label="t('backend.posts.featured_media')"
                    />
                </div>

                <div v-if="availableTaxonomies.length" class="bg-surface border border-line rounded-xl p-5 space-y-3">
                    <h3 class="text-sm font-semibold text-primary">{{ t("backend.posts.terms") }}</h3>
                    <div v-for="taxonomy in availableTaxonomies" :key="taxonomy.id" class="space-y-1">
                        <p class="text-xs uppercase tracking-wide text-muted">
                            {{ taxonomy.translations?.[locales[0]]?.label ?? taxonomy.slug }}
                        </p>
                        <AppCheckbox
                            v-for="term in taxonomy.terms ?? []"
                            :key="term.id"
                            :model-value="form.termIds.includes(term.id)"
                            :label="termLabel(term)"
                            v-on:update:model-value="toggleTerm(term.id)"
                        />
                    </div>
                </div>
            </aside>
        </div>

        <AppModal
            :show="conflict"
            max-width="sm"
            :closeable="false"
            :title="t('backend.posts.conflict_title')"
            :icon="AlertTriangle"
            v-on:close="conflict = false"
        >
            <p class="text-sm text-primary">{{ t("backend.posts.conflict_body") }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="reloadFromServer">
                        <RefreshCw class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.posts.conflict_reload") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" :loading="saving" v-on:click="saveAnyway">
                        <Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.posts.conflict_overwrite") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
