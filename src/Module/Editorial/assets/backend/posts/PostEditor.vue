<script setup>
import { useI18n } from "vue-i18n";
import { ArrowLeft, Save, Eye, X, LayoutTemplate, Lock, Unlock, Merge, History } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppListItemButton from "@/shared/components/action/AppListItemButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppDatePicker from "@/shared/components/form/picker/AppDatePicker.vue";
import AppMessage from "@/shared/components/feedback/AppMessage.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppMultiselect from "@/shared/components/form/select/AppMultiselect.vue";
import AppBlockEditor from "@shared/components/editor/AppBlockEditor.vue";
import PostPreviewOverlay from "./PostPreviewOverlay.vue";
import ConflictMergeOverlay from "./ConflictMergeOverlay.vue";
import RevisionsOverlay from "./RevisionsOverlay.vue";
import PostCustomField from "./PostCustomField.vue";
import PostSeoPanel from "./PostSeoPanel.vue";
import PostTaxonomiesPanel from "./PostTaxonomiesPanel.vue";
import PostFeaturedImagePanel from "./PostFeaturedImagePanel.vue";
import PostTemplatesOverlay from "./PostTemplatesOverlay.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import { usePostEditor } from "./composables/usePostEditor.js";
import { PostStatus } from "@editorial/shared/enums/postStatus.js";
import { statusBadge } from "@/shared/utils/format/statusStyles.js";
import { DEFAULT_LOCALES } from "@/shared/utils/lang.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";

const { t } = useI18n();
const { formatDateTime } = useDateFormat();

const props = defineProps({
    /** Initial post payload (PostSerializer::serializeFull). Null in create mode. */
    post: { type: Object, default: null },
    postTypes: { type: Array, default: () => [] },
    taxonomies: { type: Array, default: () => [] },
    locales: { type: Array, default: () => DEFAULT_LOCALES },
    showPath: { type: String, required: true },
    createPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    previewPath: { type: String, required: true },
    /** GET URL to navigate back to the posts list. */
    backPath: { type: String, required: true },
    /** GET URL template (with `__id__`) to switch from /new to /{id}/edit after a successful create. */
    editPath: { type: String, required: true },
    /**
     * Extra fields to register on the post form. Lets clients extend the
     * post editor without forking this component. The extra-form-fields
     * slot is scoped on `form` (the reactive post payload) and `errors`.
     * Example: { highlight: { default: false, fromEntity: (p) => p.highlight ?? false } }
     */
    extraFields: { type: Object, default: () => ({}) },
    /**
     * Editor.js tools contributed by other modules / the client app —
     * merged into the AppBlockEditor `extraTools` config. Editorial itself
     * ships `postsList` (Post-domain). Any tool tied to another module
     * (e.g. Ecommerce's `productGrid`) must be passed in here by the
     * downstream consumer (aurora-client) so Editorial stays decoupled.
     * Shape: { toolName: { class, config?, inlineToolbar? } | ToolClass }
     */
    extraEditorTools: { type: Object, default: () => ({}) },
});

const emit = defineEmits(["saved"]);

// Full orchestration in usePostEditor (~330 lines) — this SFC stays
// a thin shell over the composable per the SFC-thin-presentation rule.
const {
    postId,
    activeLocale, fetching, switchLocale,
    form, activeTranslation, publishedAt, trashed,
    featuredMediaUrl, featuredMediaFocalPosition, isDirty,
    postTypeOptions, statusOptions, availableTaxonomies, customFieldsDefs,
    currentPostTypeSlug, frontUrl,
    editorExtraTools, editorKey,
    version, baseTranslations, remotePost, remoteLoading,
    showMerge, mergeRemoteTranslations,
    openRemoteVersion, closeRemoteVersion, closeMerge,
    relatedSearchQuery, relatedSearchResults, relatedSearchLoading, relatedSearchOpen,
    relatedPosts, addRelatedPost, removeRelatedPost,
    slugLocked, toggleSlugLock, toggleTerm,
    showPreview, showTemplates, showRevisions,
    openPreview, previewPost, applyTemplate, reloadAfterRestore,
    loading, errors, conflict,
    handleSave, forceSave, applyMergeResolution,
} = usePostEditor({ props, emit, t });
</script>

<template>
    <div v-if="fetching" class="flex items-center justify-center py-20 text-secondary text-sm">
        {{ t("shared.common.loading") }}
    </div>

    <div v-else class="space-y-4 lg:space-y-6">
        <AppMessage v-if="trashed" variant="trash">
            {{ t("backend.posts.trashed_banner") }}
        </AppMessage>

        <AppMessage v-if="conflict" variant="warning">
            {{ t("backend.posts.conflict") }}
            <template #actions>
                <AppButton variant="secondary" size="sm" v-on:click="openRemoteVersion">
                    <Eye class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t("backend.posts.conflict_compare") }}
                </AppButton>
                <AppButton variant="primary" size="sm" :loading="remoteLoading" v-on:click="openMerge">
                    <Merge class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t("backend.posts.conflict_merge") }}
                </AppButton>
                <AppButton variant="danger" size="sm" :loading="loading" v-on:click="forceSave">
                    <Save class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t("backend.posts.conflict_force") }}
                </AppButton>
            </template>
        </AppMessage>

        <!-- Top action bar : back + page title (left) and Templates / Revisions / Preview / Save (right).
             Mobile: title row on top, then actions stacked full-width below. From sm+ everything fits on one line. -->
        <div class="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <AppButton variant="ghost" size="none" class="p-2 shrink-0" :href="backPath">
                    <ArrowLeft class="w-5 h-5" :stroke-width="2" />
                </AppButton>
                <h1 class="flex-1 text-lg font-semibold text-primary truncate min-w-0">
                    {{ postId ? t("backend.posts.edit") : t("backend.posts.create") }}
                </h1>
            </div>
            <div class="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2 sm:ml-auto">
                <AppButton variant="secondary" size="md" class="w-full sm:w-auto" v-on:click="showTemplates = true">
                    <LayoutTemplate class="w-4 h-4" :stroke-width="2" />
                    <span>Templates</span>
                </AppButton>
                <AppButton
                    v-if="postId"
                    variant="secondary"
                    size="md"
                    class="w-full sm:w-auto"
                    v-on:click="showRevisions = true"
                >
                    <History class="w-4 h-4" :stroke-width="2" />
                    <span>{{ t("backend.posts.revisions.title") }}</span>
                </AppButton>
                <AppButton
                    v-if="postId"
                    variant="secondary"
                    size="md"
                    class="w-full sm:w-auto"
                    :title="isDirty ? t('backend.posts.preview_saved_hint') : null"
                    v-on:click="openPreview"
                >
                    <Eye class="w-4 h-4" :stroke-width="2" />
                    <span>{{ t("backend.posts.preview") }}</span>
                </AppButton>
                <AppButton
                    variant="primary"
                    size="md"
                    class="relative w-full sm:w-auto"
                    :loading="loading"
                    v-on:click="handleSave"
                >
                    <Save v-if="!loading" class="w-4 h-4" :stroke-width="2" />
                    <span>{{ t("shared.common.save") }}</span>
                    <span v-if="isDirty && !loading" class="absolute -top-1 -right-1 w-2.5 h-2.5 rounded-full bg-amber-400 border-2 border-white dark:border-surface" />
                </AppButton>
            </div>
        </div>

        <AppMessage v-if="Object.keys(errors).length" variant="danger">
            <p v-for="(message, field) in errors" :key="field">{{ message }}</p>
        </AppMessage>

        <!-- 2-column layout : main (title + blocks + translations) + right sidebar (publish + taxonomies + featured + related + SEO). -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 lg:gap-6">
            <!-- Main column. -->
            <div class="lg:col-span-2 space-y-4">
                <!-- Translation locale tabs. -->
                <div class="flex gap-1 border-b border-line overflow-x-auto scrollbar-hide">
                    <AppTab
                        v-for="locale in locales"
                        :key="locale"
                        variant="underline"
                        :active="activeLocale === locale"
                        v-on:click="switchLocale(locale)"
                    >
                        {{ t("shared.locales." + locale) }}
                    </AppTab>
                </div>

                <!-- Title + slug + front-URL preview. -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <AppInput
                        v-model="form.translations[activeLocale].title"
                        :label="t('backend.posts.title')"
                        :placeholder="t('backend.posts.title_placeholder')"
                    />
                    <div class="flex items-end gap-2">
                        <div class="flex-1">
                            <AppInput
                                v-model="form.translations[activeLocale].slug"
                                :label="t('backend.posts.slug')"
                                :placeholder="t('backend.posts.slug_placeholder')"
                                :readonly="slugLocked"
                            />
                        </div>
                        <AppButton
                            variant="secondary"
                            size="none"
                            class="w-9 h-9 mb-0.5 shrink-0"
                            :title="slugLocked ? t('backend.posts.slug_unlock') : t('backend.posts.slug_lock')"
                            v-on:click="toggleSlugLock"
                        >
                            <Lock v-if="slugLocked" class="w-4 h-4" :stroke-width="2" />
                            <Unlock v-else class="w-4 h-4" :stroke-width="2" />
                        </AppButton>
                    </div>
                    <p v-if="frontUrl" class="text-xs text-muted sm:col-span-2">
                        <span class="text-secondary">URL :</span>
                        <a v-if="form.status === 'published'" :href="frontUrl" target="_blank" class="ml-1 font-mono text-accent-400 hover:underline break-all">{{ frontUrl }}</a>
                        <span v-else class="ml-1 font-mono text-muted break-all">{{ frontUrl }}</span>
                    </p>
                </div>

                <!-- Per-PostType custom fields. -->
                <div v-if="customFieldsDefs.length" class="border-t border-line pt-4 space-y-3">
                    <p class="text-xs font-semibold text-secondary uppercase tracking-wide">{{ t("backend.posts.custom_fields") }}</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <PostCustomField
                            v-for="field in customFieldsDefs"
                            :key="field.id"
                            :field="field"
                            :model-value="form.translations[activeLocale].customFields[field.name]"
                            v-on:update:model-value="form.translations[activeLocale].customFields[field.name] = $event"
                        />
                    </div>
                </div>

                <slot name="extra-form-fields" :form="form" :errors="errors" />

                <!-- Blocks editor — full height of the main column (the writing surface). -->
                <div class="border-t border-line pt-4">
                    <label class="block text-xs text-secondary uppercase tracking-wide mb-2">
                        {{ t("backend.posts.blocks") }}
                    </label>
                    <div class="rounded-xl border border-line bg-surface shadow-sm p-4 overflow-auto min-h-120 max-h-[calc(100vh-12rem)]">
                        <AppBlockEditor
                            :key="`${activeLocale}-${editorKey}`"
                            v-model="form.translations[activeLocale].blocks"
                            :extra-tools="editorExtraTools"
                        />
                    </div>
                </div>
            </div>

            <!-- Sidebar : settings cards, WordPress-style. Stays single-column on mobile (collapses under main). -->
            <aside class="space-y-4">
                <!-- Publish / status card. -->
                <section class="rounded-xl border border-line bg-surface p-4 space-y-3">
                    <h3 class="text-xs font-semibold text-secondary uppercase tracking-wide">{{ t("backend.posts.publish") }}</h3>
                    <AppMultiselect
                        v-model="form.status"
                        :options="statusOptions"
                        track-by="value"
                        option-label="label"
                    />
                    <AppDatePicker
                        v-if="form.status === PostStatus.Scheduled"
                        v-model="form.scheduledAt"
                        :enable-time="true"
                    />
                    <p v-if="publishedAt" class="text-xs text-muted">
                        {{ t("backend.posts.published_at") }} {{ formatDateTime(publishedAt) }}
                    </p>
                    <p v-if="postId" class="text-xs text-muted font-mono">ID : {{ postId }}</p>
                </section>

                <!-- Post type. -->
                <section v-if="postTypes.length" class="rounded-xl border border-line bg-surface p-4 space-y-3">
                    <h3 class="text-xs font-semibold text-secondary uppercase tracking-wide">{{ t("backend.posts.post_type") }}</h3>
                    <AppMultiselect
                        v-model="form.postTypeId"
                        :options="postTypeOptions"
                        track-by="value"
                        option-label="label"
                    />
                </section>

                <!-- Taxonomies — embedded component renders its own term tree. -->
                <section v-if="availableTaxonomies.length" class="rounded-xl border border-line bg-surface p-4">
                    <PostTaxonomiesPanel
                        :taxonomies="availableTaxonomies"
                        :selected-term-ids="form.termIds"
                        :active-locale="activeLocale"
                        :default-locale="locales[0]"
                        v-on:toggle-term="toggleTerm"
                    />
                </section>

                <!-- Comments toggle. -->
                <section class="rounded-xl border border-line bg-surface p-4">
                    <AppToggle
                        :model-value="form.commentsEnabled"
                        :label="t('backend.posts.comments_enabled')"
                        v-on:update:model-value="form.commentsEnabled = $event"
                    />
                </section>

                <!-- Featured image. -->
                <section class="rounded-xl border border-line bg-surface p-4 space-y-3">
                    <h3 class="text-xs font-semibold text-secondary uppercase tracking-wide">{{ t("backend.posts.featured_image") }}</h3>
                    <PostFeaturedImagePanel
                        v-model:media-id="form.featuredMediaId"
                        v-model:media-url="featuredMediaUrl"
                        v-model:focal-position="featuredMediaFocalPosition"
                    />
                </section>

                <!-- Related posts. -->
                <section class="rounded-xl border border-line bg-surface p-4 space-y-3">
                    <h3 class="text-xs font-semibold text-secondary uppercase tracking-wide">{{ t("backend.posts.related_posts.title") }}</h3>

                    <div v-if="relatedPosts.length" class="flex flex-col gap-1.5">
                        <div
                            v-for="related in relatedPosts"
                            :key="related.id"
                            class="flex items-center gap-2 px-3 py-2 rounded-md bg-surface-2 border border-line/60"
                        >
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium" :class="statusBadge(related.status)">
                                {{ t("backend.posts.status_options." + related.status) }}
                            </span>
                            <div class="flex-1 min-w-0">
                                <div class="text-sm text-primary truncate">{{ related.title ?? "(—)" }}</div>
                                <div class="text-xs text-muted truncate">{{ related.postType }}</div>
                            </div>
                            <AppIconButton color="rose" v-on:click="removeRelatedPost(related.id)">
                                <X class="w-3.5 h-3.5" :stroke-width="2" />
                            </AppIconButton>
                        </div>
                    </div>

                    <div class="relative">
                        <AppInput
                            v-model="relatedSearchQuery"
                            :placeholder="t('backend.posts.related_posts.search_placeholder')"
                            v-on:focus="relatedSearchOpen = true"
                            v-on:blur="setTimeout(() => { relatedSearchOpen = false; }, 150)"
                        />
                        <div
                            v-if="relatedSearchOpen && (relatedSearchResults.length || relatedSearchLoading)"
                            class="absolute z-10 mt-1 w-full max-h-64 overflow-y-auto scrollbar-thin rounded-md border border-line bg-surface shadow-lg"
                        >
                            <div v-if="relatedSearchLoading" class="px-3 py-2 text-xs text-muted">{{ t("shared.common.loading") }}</div>
                            <AppListItemButton
                                v-for="result in relatedSearchResults"
                                :key="result.id"
                                v-on:mousedown.prevent="addRelatedPost(result)"
                            >
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium" :class="statusBadge(result.status)">
                                    {{ t("backend.posts.status_options." + result.status) }}
                                </span>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm text-primary truncate">{{ result.title ?? "(—)" }}</div>
                                    <div class="text-xs text-muted truncate">{{ result.postType }}</div>
                                </div>
                            </AppListItemButton>
                        </div>
                    </div>
                </section>

                <!-- SEO. PostSeoPanel ships with its own internal `border-t` separator, harmless inside a card. -->
                <section v-if="form.translations[activeLocale]" class="rounded-xl border border-line bg-surface p-4">
                    <PostSeoPanel
                        :translation="form.translations[activeLocale]"
                        :locale="activeLocale"
                        :post-type-slug="currentPostTypeSlug"
                        :published-at="publishedAt"
                    />
                </section>
            </aside>
        </div>
    </div>

    <PostTemplatesOverlay :show="showTemplates" v-on:close="showTemplates = false" v-on:apply="applyTemplate" />

    <PostPreviewOverlay
        :post="showPreview ? previewPost : null"
        :locales="locales"
        :preview-path="previewPath"
        v-on:close="showPreview = false"
    />

    <PostPreviewOverlay
        :post="remotePost"
        :loading="remoteLoading"
        :locales="locales"
        :preview-path="previewPath"
        v-on:close="closeRemoteVersion"
    />

    <ConflictMergeOverlay
        :show="showMerge"
        :base="baseTranslations"
        :local="form.translations"
        :remote="mergeRemoteTranslations ?? {}"
        :locales="locales"
        v-on:close="closeMerge"
        v-on:apply="applyMergeResolution"
    />

    <RevisionsOverlay
        v-if="postId"
        :post-id="postId"
        :show="showRevisions"
        :locales="locales"
        :current-translations="form.translations"
        v-on:close="showRevisions = false"
        v-on:restored="reloadAfterRestore"
    />
</template>
