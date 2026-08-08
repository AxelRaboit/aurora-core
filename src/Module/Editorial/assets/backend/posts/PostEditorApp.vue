<script setup>
import { useI18n } from "vue-i18n";
import { usePostEditor } from "./composables/usePostEditor.js";
import { useTabState } from "@/shared/composables/useTabState.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
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

/**
 * Sections, kept in the URL fragment rather than in localStorage.
 *
 * There is one editor per post: a remembered key would be shared by all of
 * them, and two browser tabs on two posts would fight over it. The fragment
 * belongs to the page being looked at, survives the reload, and makes a link
 * to a specific section shareable.
 *
 * English keys because they end up in the URL, where the rest of the routing
 * is English too. The labels are translated; the identifier is not.
 */
const TABS = ["content", "header", "settings", "seo"];
const { activeTab, select: selectTab, isActive: isTabActive } = useTabState(TABS, {
    hash: true,
    defaultKey: "content",
});

const postTypeOptions = props.postTypes.map((type) => ({ value: type.id, label: type.label }));
const statusSelectOptions = props.statusOptions.map((status) => ({
    value: status,
    label: t(`backend.posts.status.${status}`),
}));

// Mirrors the list's own palette, so a post reads the same in both places.
const STATUS_COLORS = {
    draft: "gray",
    pending_review: "amber",
    scheduled: "sky",
    published: "emerald",
    archived: "zinc",
};

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
            <div class="flex items-center gap-3">
                <!-- Status stays visible whatever section is open. Knowing you
                     are editing a live page should not require opening a tab. -->
                <AppBadge :color="STATUS_COLORS[form.status] ?? 'gray'">
                    {{ t(`backend.posts.status.${form.status}`) }}
                </AppBadge>
                <AppButton variant="primary" size="md" :loading="saving" v-on:click="save(false)">
                    <Save class="w-4 h-4" :stroke-width="2" /> {{ t("shared.common.save") }}
                </AppButton>
            </div>
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

        <!-- The record's own name and summary, outside the sections because
             they are not one: they identify the publication in the admin list
             and on any card that embeds it. They also head the page itself,
             but only as a fallback — an in-page header with a title of its own
             takes over, which is why they are not filed under Content. -->
        <div class="bg-surface border border-line rounded-xl p-5 space-y-4">
            <AppInput
                v-model="current.title"
                :label="t('backend.posts.field_title')"
                :error="errors.title"
            />
            <AppTextarea
                v-model="current.description"
                :label="t('backend.posts.field_description')"
                :rows="2"
            />
            <p class="text-xs text-muted">{{ t("backend.posts.identity_hint") }}</p>
        </div>

        <div>
            <section class="space-y-4">
                <div class="flex gap-1 border-b border-line">
                    <AppTab
                        v-for="tab in TABS"
                        :key="tab"
                        variant="underline"
                        :active="isTabActive(tab)"
                        v-on:click="selectTab(tab)"
                    >
                        {{ t(`backend.posts.tabs.${tab}`) }}
                    </AppTab>
                </div>

                <!-- v-show, not v-if: the block editor holds its own state,
                     and remounting it per tab would throw away the undo stack
                     and flicker — the same reason one instance serves every
                     locale. -->
                <div v-show="isTabActive('content')" class="space-y-4">
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
                </div>

                <div v-show="isTabActive('header')" class="space-y-4">
                    <div class="bg-surface border border-line rounded-xl p-5 space-y-4">
                        <h3 class="text-sm font-semibold text-primary">{{ t("backend.posts.banner.title") }}</h3>
                        <!-- Two halves: the design is one per post, the words
                             are one set per language. Switching the locale tab
                             swaps the second and leaves the first standing. -->
                        <PostBannerPanel
                            :layout="form.bannerLayout"
                            :texts="current.banner"
                            :locale="locale"
                            :preview-path="bannerPreviewPath"
                        />
                    </div>
                </div>

                <div v-show="isTabActive('settings')" class="space-y-4">
                    <div class="bg-surface border border-line rounded-xl p-5 space-y-4">
                        <AppInput
                            v-model="current.slug"
                            :label="t('backend.posts.field_slug')"
                            :hint="t('backend.posts.slug_hint')"
                        />
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
                </div>

                <div v-show="isTabActive('seo')" class="space-y-4">
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
                </div>
            </section>
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
