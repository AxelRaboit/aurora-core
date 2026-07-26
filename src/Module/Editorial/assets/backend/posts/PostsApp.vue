<script setup>
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { usePostsTrash } from "@editorial/backend/posts/composables/usePostsTrash.js";
import { usePostsPreview } from "@editorial/backend/posts/composables/usePostsPreview.js";
import { useUrlSyncedState } from "@/shared/composables/list/useUrlSyncedState.js";
import { useI18n } from "vue-i18n";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { toast } from "vue-sonner";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { statusBadgeColor } from "@/shared/utils/format/statusStyles.js";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import { DEFAULT_LOCALES } from "@/shared/utils/lang.js";
import { usePostList } from "@editorial/backend/posts/composables/usePostList.js";
import { usePostDelete } from "@editorial/backend/posts/composables/usePostDelete.js";
import { usePostFilters } from "@editorial/backend/posts/composables/usePostFilters.js";
import { usePostTermLabels } from "@editorial/backend/posts/composables/usePostTermLabels.js";
import { FileText, Eye, Inbox, LayoutList, List, Pencil, Plus, RotateCcw, Trash2, X } from "lucide-vue-next";
import PostPreviewOverlay from "@editorial/backend/posts/PostPreviewOverlay.vue";
import PostTaxonomiesPanel from "@editorial/backend/posts/PostTaxonomiesPanel.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppMultiselect from "@/shared/components/form/select/AppMultiselect.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppPagination from "@/shared/components/nav/AppPagination.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import AppTooltip from "@/shared/components/overlay/AppTooltip.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import { useUrlSearchSync } from "@/shared/composables/list/useUrlSearchSync.js";
import { usePostViewMode } from "@editorial/backend/posts/composables/usePostViewMode.js";
import { PostStatus } from "@editorial/shared/enums/postStatus.js";

const { t } = useI18n();
const { can } = usePrivileges();
const { formatDateShort } = useDateFormat();

const props = defineProps({
    postsPath: { type: String, required: true },
    posts: { type: Object, default: () => ({ items: [], total: 0, page: 1, totalPages: 1 }) },
    search: { type: String, default: "" },
    postTypes: { type: Array, default: () => [] },
    taxonomies: { type: Array, default: () => [] },
    locales: { type: Array, default: () => DEFAULT_LOCALES },
    trashed: { type: Boolean, default: false },
    postTypeIds: { type: Array, default: () => [] },
    termIds: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    /** GET URL to the standalone "new post" page. */
    newPath: { type: String, required: true },
    /** GET URL to the standalone "edit post" page, with `__id__` placeholder. */
    editPath: { type: String, required: true },
    showPath: { type: String, required: true },
    previewPath: { type: String, required: true },
    deletePath: { type: String, required: true },
    restorePath: { type: String, required: true },
    forceDeletePath: { type: String, required: true },
    emptyTrashPath: { type: String, default: "" },
    extraFields: { type: Object, default: () => ({}) },
    /**
     * Editor.js tools contributed by aurora-client (or another module)
     * — forwarded to PostEditor. Lets a deployment that bundles e.g.
     * Ecommerce inject `productGrid` without Editorial having to import
     * it. See AppBlockEditor's `extraTools` prop for the shape.
     */
    extraEditorTools: { type: Object, default: () => ({}) },
});

const parsedPostTypes  = props.postTypes ?? [];
const parsedTaxonomies = props.taxonomies ?? [];
const parsedLocales    = props.locales ?? DEFAULT_LOCALES;
const defaultLocale    = parsedLocales[0] ?? "fr";

// --- Filters (state + URL sync) ---
// `performSearch` is referenced via a closure because it's only defined
// below by `usePostList`. The closure resolves the binding at call time.
const {
    selectedPostTypeIds, selectedTermIds, selectedStatuses,
    hasActiveFilters, postTypeOptions, statusOptions, visibleTaxonomies,
    onPostTypeFilterChange, onStatusFilterChange, toggleTerm, clearFilters,
} = usePostFilters({
    parsedPostTypes,
    parsedTaxonomies,
    initial: { postTypeIds: props.postTypeIds, termIds: props.termIds, statuses: props.statuses },
    performSearch: () => performSearch(),
});

// --- List ---
const { state: trashed, set: setTrashedFilter } = useUrlSyncedState({
    initial: props.trashed,
    serialize: (value) => {
        const url = new URL(window.location.href);
        if (value) url.searchParams.set("trashed", "1");
        else url.searchParams.delete("trashed");
        return url;
    },
    deserialize: (event) =>
        event.state?.value ?? (new URLSearchParams(window.location.search).get("trashed") === "1"),
    onSync: () => performSearch(),
});

const { posts, page, totalPages, loading, search: searchInput, addPost, updatePost, removePost, performSearch, goToPage } =
    usePostList(props.postsPath, props.posts, props.search, () => ({
        ...(trashed.value ? { trashed: "1" } : {}),
        ...Object.fromEntries(selectedPostTypeIds.value.map((id, i) => [`postTypeIds[${i}]`, id])),
        ...Object.fromEntries(selectedStatuses.value.map((s, i) => [`statuses[${i}]`, s])),
        ...Object.fromEntries(selectedTermIds.value.map((id, i) => [`termIds[${i}]`, id])),
    }));

const { emptyingTrash, confirmEmptyTrash, emptyTrash, restorePost } = usePostsTrash(props, removePost, setTrashedFilter);

function editUrl(id) {
    return props.editPath.replace("__id__", String(id));
}

const syncSearchUrl = useUrlSearchSync();
function onSearch(value) {
    syncSearchUrl(value);
    performSearch();
}

const deletePost = usePostDelete(
    () => (trashed.value ? props.forceDeletePath : props.deletePath),
    (id) => removePost(id),
    () => (trashed.value ? "backend.posts.deleted_forever" : "backend.posts.deleted"),
);

const { previewPost, previewLoading, frontUrl, openPreview } = usePostsPreview(props.showPath, props.locales);

// --- View mode ---
const { mode: viewMode, setMode: setViewMode } = usePostViewMode();

const { termMap, postTermLabels } = usePostTermLabels({ parsedTaxonomies, defaultLocale });
</script>

<template>
    <div class="flex flex-col md:flex-row gap-6">
        <!-- Desktop sidemenu -->
        <nav class="hidden md:flex flex-col w-52 shrink-0 gap-4">
            <div class="flex flex-col gap-0.5">
                <AppTooltip :title="t('backend.posts.tabs.active')" :description="t('backend.posts.tabs.active_description')" placement="right">
                    <AppTab :active="!trashed" v-on:click="setTrashedFilter(false)">
                        <FileText class="w-4 h-4 shrink-0" :stroke-width="2" />
                        {{ t("backend.posts.tabs.active") }}
                    </AppTab>
                </AppTooltip>
                <AppTooltip :title="t('backend.posts.tabs.trash')" :description="t('backend.posts.tabs.trash_description')" placement="right">
                    <AppTab :active="trashed" color="rose" v-on:click="setTrashedFilter(true)">
                        <Inbox class="w-4 h-4 shrink-0" :stroke-width="2" />
                        {{ t("backend.posts.tabs.trash") }}
                    </AppTab>
                </AppTooltip>
            </div>

            <template v-if="!trashed">
                <div v-if="visibleTaxonomies.length" class="flex flex-col gap-3">
                    <p class="text-xs font-medium text-muted uppercase tracking-wide px-2">{{ t('backend.posts.filter_by_term') }}</p>
                    <PostTaxonomiesPanel
                        :taxonomies="visibleTaxonomies"
                        :selected-term-ids="selectedTermIds"
                        :active-locale="defaultLocale"
                        :default-locale="defaultLocale"
                        :collapsible="true"
                        v-on:toggle-term="toggleTerm"
                    />
                </div>

                <AppButton
                    v-if="hasActiveFilters"
                    variant="ghost"
                    size="none"
                    class="flex items-center gap-1.5 px-2 text-xs text-muted hover:text-rose-400 transition-colors"
                    v-on:click="clearFilters"
                >
                    <X class="w-3 h-3" :stroke-width="2" />
                    {{ t('backend.posts.clear_filters') }}
                </AppButton>
            </template>
        </nav>

        <!-- Mobile: status tabs -->
        <div class="flex md:hidden gap-1 flex-wrap w-full">
            <AppTooltip :title="t('backend.posts.tabs.active')" :description="t('backend.posts.tabs.active_description')" placement="bottom">
                <AppTab :active="!trashed" size="sm" v-on:click="setTrashedFilter(false)">
                    <FileText class="w-4 h-4" :stroke-width="2" />
                    {{ t("backend.posts.tabs.active") }}
                </AppTab>
            </AppTooltip>
            <AppTooltip :title="t('backend.posts.tabs.trash')" :description="t('backend.posts.tabs.trash_description')" placement="bottom">
                <AppTab :active="trashed" color="rose" size="sm" v-on:click="setTrashedFilter(true)">
                    <Inbox class="w-4 h-4" :stroke-width="2" />
                    {{ t("backend.posts.tabs.trash") }}
                </AppTab>
            </AppTooltip>
        </div>

        <div class="flex-1 min-w-0 space-y-4">
            <AppListToolbar>
                <AppSearchInput
                    v-model="searchInput"
                    :placeholder="t('backend.posts.search_placeholder')"
                    v-on:search="onSearch"
                />
                <template #actions>
                    <div class="flex items-center gap-2 w-full sm:w-auto">
                        <div class="flex items-center rounded-lg border border-line overflow-hidden shrink-0">
                            <AppButton
                                variant="ghost"
                                size="none"
                                class="flex items-center justify-center w-8 h-8 transition-colors"
                                :class="viewMode === 'compact' ? 'bg-surface-2 text-primary' : 'text-muted hover:text-secondary'"
                                :title="t('backend.posts.view_compact')"
                                v-on:click="setViewMode('compact')"
                            >
                                <List class="w-4 h-4" :stroke-width="2" />
                            </AppButton>
                            <AppButton
                                variant="ghost"
                                size="none"
                                class="flex items-center justify-center w-8 h-8 border-l border-line transition-colors"
                                :class="viewMode === 'detailed' ? 'bg-surface-2 text-primary' : 'text-muted hover:text-secondary'"
                                :title="t('backend.posts.view_detailed')"
                                v-on:click="setViewMode('detailed')"
                            >
                                <LayoutList class="w-4 h-4" :stroke-width="2" />
                            </AppButton>
                        </div>
                        <AppButton
                            v-if="!trashed && can('editorial.posts.create')"
                            variant="primary"
                            size="md"
                            class="flex-1 sm:flex-none"
                            :href="newPath"
                        >
                            <Plus class="w-4 h-4" :stroke-width="2" />
                            {{ t("backend.posts.add") }}
                        </AppButton>
                        <AppButton
                            v-if="trashed && posts.length"
                            variant="danger"
                            size="md"
                            class="flex-1 sm:flex-none"
                            :loading="emptyingTrash"
                            v-on:click="confirmEmptyTrash = true"
                        >
                            <Trash2 class="w-4 h-4" :stroke-width="2" />
                            {{ t("backend.posts.empty_trash") }}
                        </AppButton>
                    </div>
                </template>
            </AppListToolbar>

            <div v-if="!trashed" class="flex flex-wrap gap-2 items-end">
                <AppMultiselect
                    v-if="postTypeOptions.length > 1"
                    :model-value="selectedPostTypeIds"
                    :options="postTypeOptions"
                    :multiple="true"
                    :allow-empty="true"
                    :searchable="false"
                    :placeholder="t('backend.posts.filter_by_type')"
                    class="min-w-40 flex-1 sm:flex-none"
                    v-on:update:model-value="onPostTypeFilterChange"
                />
                <AppMultiselect
                    :model-value="selectedStatuses"
                    :options="statusOptions"
                    :multiple="true"
                    :allow-empty="true"
                    :searchable="false"
                    :placeholder="t('backend.posts.filter_by_status')"
                    class="min-w-40 flex-1 sm:flex-none"
                    v-on:update:model-value="onStatusFilterChange"
                />
                <AppButton
                    v-if="hasActiveFilters"
                    variant="ghost"
                    size="none"
                    class="flex items-center gap-1.5 px-2 py-1 text-xs text-muted hover:text-rose-400 transition-colors self-center"
                    v-on:click="clearFilters"
                >
                    <X class="w-3 h-3" :stroke-width="2" />
                    {{ t('backend.posts.clear_filters') }}
                </AppButton>
            </div>

            <div class="relative space-y-4">
                <div class="sm:hidden space-y-2">
                    <AppNoData v-if="!posts.length" :message="t('backend.posts.empty')" />
                    <div v-for="post in posts" :key="post.id" class="bg-surface border border-line/60 rounded-xl p-4 space-y-3 shadow-sm">
                        <div class="flex items-start gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-primary truncate text-sm">{{ post.title ?? "-" }}</p>
                                <p class="text-xs text-muted mt-0.5">{{ post.postType?.label }}</p>
                                <p v-if="frontUrl(post) && !trashed" class="text-xs text-accent-400 truncate mt-0.5 font-mono">{{ frontUrl(post) }}</p>
                                <div v-if="viewMode === 'detailed' && postTermLabels(post).length" class="flex flex-wrap gap-1 mt-1.5">
                                    <span
                                        v-for="label in postTermLabels(post)"
                                        :key="label"
                                        class="px-1.5 py-0.5 text-xs rounded-md bg-surface-2 border border-line/60 text-secondary"
                                    >{{ label }}</span>
                                </div>
                            </div>
                            <AppBadge :color="post.trashed ? 'rose' : statusBadgeColor(post.status)" class="shrink-0">
                                {{ post.trashed ? t("backend.posts.status_trashed") : t("backend.posts.status_options." + post.status) }}
                            </AppBadge>
                        </div>
                        <div class="flex items-center justify-between pt-2 border-t border-line/40">
                            <p class="text-xs text-muted">{{ formatDateShort(post.createdAt) }}</p>
                            <div class="flex items-center gap-0.5">
                                <AppIconButton color="sky" v-on:click="openPreview(post)">
                                    <Eye class="w-4 h-4" :stroke-width="2" />
                                </AppIconButton>
                                <AppIconButton v-if="!trashed && can('editorial.posts.edit')" color="accent" :href="editUrl(post.id)">
                                    <Pencil class="w-4 h-4" :stroke-width="2" />
                                </AppIconButton>
                                <AppIconButton v-if="trashed" color="emerald" v-on:click="restorePost(post)">
                                    <RotateCcw class="w-4 h-4" :stroke-width="2" />
                                </AppIconButton>
                                <AppIconButton color="rose" v-on:click="deletePost.confirm(post)">
                                    <Trash2 class="w-4 h-4" :stroke-width="2" />
                                </AppIconButton>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="hidden sm:block bg-surface border border-line rounded-lg overflow-x-auto scrollbar-thin">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-surface-2/50 border-b border-line/40">
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t("backend.posts.title") }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell">{{ t("backend.posts.post_type") }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t("backend.posts.status") }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">{{ t("backend.tags.created_at") }}</th>
                                <slot name="extra-headers" />
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted">{{ t("backend.tags.actions") }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line/40">
                            <tr v-if="!posts.length">
                                <td colspan="6"><AppNoData :message="t('backend.posts.empty')" /></td>
                            </tr>
                            <tr v-for="post in posts" :key="post.id" class="group hover:bg-surface-2/40 transition-colors">
                                <td class="px-6 py-3 text-xs text-muted font-mono hidden lg:table-cell">{{ post.id }}</td>
                                <td class="px-6 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <FileText class="w-3.5 h-3.5 text-muted shrink-0 self-start mt-0.5" :stroke-width="2" />
                                        <div class="min-w-0 flex-1">
                                            <p class="font-medium text-primary text-sm truncate">{{ post.title ?? "-" }}</p>
                                            <p v-if="frontUrl(post) && !trashed" class="text-xs text-accent-400 truncate font-mono">{{ frontUrl(post) }}</p>
                                            <div v-if="viewMode === 'detailed' && postTermLabels(post).length" class="flex flex-wrap gap-1 mt-1">
                                                <span
                                                    v-for="label in postTermLabels(post)"
                                                    :key="label"
                                                    class="px-1.5 py-0.5 text-xs rounded-md bg-surface-2 border border-line/60 text-secondary"
                                                >{{ label }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-3 text-sm text-secondary hidden md:table-cell">{{ post.postType?.label ?? "-" }}</td>
                                <td class="px-6 py-3">
                                    <AppBadge :color="post.trashed ? 'rose' : statusBadgeColor(post.status)">
                                        {{ post.trashed ? t("backend.posts.status_trashed") : t("backend.posts.status_options." + post.status) }}
                                    </AppBadge>
                                </td>
                                <td class="px-6 py-3 text-sm text-secondary hidden lg:table-cell">{{ formatDateShort(post.createdAt) }}</td>
                                <slot name="extra-cells" :post="post" />
                                <td class="px-6 py-3">
                                    <div class="flex items-center justify-end gap-0.5">
                                        <AppIconButton color="sky" v-on:click="openPreview(post)">
                                            <Eye class="w-4 h-4" :stroke-width="2" />
                                        </AppIconButton>
                                        <AppIconButton v-if="!trashed && can('editorial.posts.edit')" color="accent" :href="editUrl(post.id)">
                                            <Pencil class="w-4 h-4" :stroke-width="2" />
                                        </AppIconButton>
                                        <AppIconButton v-if="trashed" color="emerald" v-on:click="restorePost(post)">
                                            <RotateCcw class="w-4 h-4" :stroke-width="2" />
                                        </AppIconButton>
                                        <AppIconButton color="rose" v-on:click="deletePost.confirm(post)">
                                            <Trash2 class="w-4 h-4" :stroke-width="2" />
                                        </AppIconButton>
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
                :show="!!deletePost.pendingDelete.value"
                max-width="sm"
                :closeable="false"
                :title="t('shared.common.delete')"
                :icon="Trash2"
                v-on:close="deletePost.pendingDelete.value = null"
            >
                <p class="text-sm text-primary">
                    {{ t(trashed ? "backend.posts.force_delete_confirm" : "backend.posts.delete_confirm", { title: deletePost.pendingDelete.value?.title ?? "?" }) }}
                </p>
                <template #footer>
                    <AppModalFooter>
                        <AppButton variant="ghost" size="md" v-on:click="deletePost.pendingDelete.value = null">
                            <X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                        </AppButton>
                        <AppButton variant="danger" size="md" :loading="deletePost.loading.value" v-on:click="deletePost.submit()">
                            <Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t(trashed ? "backend.posts.force_delete" : "common.delete") }}
                        </AppButton>
                    </AppModalFooter>
                </template>
            </AppModal>

            <AppModal
                :show="confirmEmptyTrash"
                max-width="sm"
                :closeable="false"
                :title="t('shared.common.delete')"
                :icon="Trash2"
                v-on:close="confirmEmptyTrash = false"
            >
                <p class="text-sm text-primary">{{ t("backend.posts.empty_trash_confirm") }}</p>
                <template #footer>
                    <AppModalFooter>
                        <AppButton variant="secondary" size="md" v-on:click="confirmEmptyTrash = false">
                            <X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                        </AppButton>
                        <AppButton variant="danger" size="md" :loading="emptyingTrash" v-on:click="emptyTrash">
                            <Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.posts.empty_trash") }}
                        </AppButton>
                    </AppModalFooter>
                </template>
            </AppModal>
        </div>

        <PostPreviewOverlay
            :post="previewPost"
            :loading="previewLoading"
            :locales="parsedLocales"
            :preview-path="previewPath"
            v-on:close="previewPost = null"
        />
    </div>
</template>
