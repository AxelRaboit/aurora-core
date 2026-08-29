<script setup>
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { usePostsList } from "./composables/usePostsList.js";
import { usePostRowActions } from "./composables/usePostRowActions.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppPagination from "@/shared/components/nav/AppPagination.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import { Plus, Trash2, X, FileText, Filter, Flame } from "lucide-vue-next";

const { t } = useI18n();
const { can } = usePrivileges();
const { request } = useRequest();
const { formatDateTime } = useDateFormat();

const props = defineProps({
    posts: { type: Object, default: () => ({ items: [], total: 0, page: 1, totalPages: 1 }) },
    search: { type: String, default: "" },
    trashed: { type: Boolean, default: false },
    postTypes: { type: Array, default: () => [] },
    taxonomies: { type: Array, default: () => [] },
    locales: { type: Array, default: () => [] },
    statusOptions: { type: Array, default: () => [] },
    postTypeIds: { type: Array, default: () => [] },
    termIds: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    listPath: { type: String, required: true },
    newPath: { type: String, required: true },
    editPathTemplate: { type: String, required: true },
    deletePathTemplate: { type: String, required: true },
    duplicatePathTemplate: { type: String, default: "" },
    bulkPath: { type: String, default: "" },
    restorePathTemplate: { type: String, required: true },
    forceDeletePathTemplate: { type: String, required: true },
    emptyTrashPath: { type: String, required: true },
});

const {
    items, total, page, totalPages, loading,
    search, trashed, postTypeIds, termIds, statuses,
    activeFilterCount, goToPage, toggleIn, clearFilters,
    pendingDelete, deleteLoading, confirmDelete, doDelete,
    pendingForceDelete, forceDelete, confirmEmptyTrash, emptyingTrash, emptyTrash, showingTrash, restore,
    editPath, reload,
} = usePostsList(props);

// What a row offers depends on the permission and on whether the post sits in
// the trash - a rule about the record, not a layout decision, so it is not four
// `v-if` in a table cell. `pendingForceDelete` rather than `forceDelete`: that
// one cannot be undone and keeps its confirmation.
const actionsFor = usePostRowActions({
    can,
    editPath,
    restore,
    confirmDelete,
    forceDelete: (post) => {
        pendingForceDelete.value = post;
    },
    duplicate: duplicatePost,
});

/**
 * Copies a post and goes straight to the copy.
 *
 * Navigating rather than staying on the list: the reason anybody duplicates is to
 * change something, and leaving them on a list with a near-identical new row is
 * asking them to find it again.
 */
async function duplicatePost(post) {
    const data = await request(props.duplicatePathTemplate.replace("__id__", String(post.id)));

    if (!data?.editPath) {
        return;
    }

    window.location.href = data.editPath;
}

/**
 * The rows the reader has ticked, by id.
 *
 * A `Set` rather than a list: the questions asked of it are "is this one in" on
 * every row of every render, and "how many" once. Kept as a plain ref reassigned on
 * change, because Vue does not track mutations of a Set.
 */
const selected = ref(new Set());

/**
 * Cleared whenever the list underneath changes.
 *
 * Paging or filtering with a selection still held would apply an action to rows
 * that scrolled out of sight - the reader ticks three, filters, presses publish,
 * and three posts they can no longer see change.
 */
watch([items, trashed], () => {
    selected.value = new Set();
});

const allOnPageSelected = computed(
    () => items.value.length > 0 && items.value.every((post) => selected.value.has(post.id)),
);

function toggleRow(post) {
    const next = new Set(selected.value);

    if (next.has(post.id)) {
        next.delete(post.id);
    } else {
        next.add(post.id);
    }

    selected.value = next;
}

/** All of this page, or none of it. Never "all of every page", which nobody means. */
function toggleAllOnPage() {
    selected.value = allOnPageSelected.value
        ? new Set()
        : new Set(items.value.map((post) => post.id));
}

const bulkRunning = ref(false);
const bulkResult = ref(null);

/**
 * The actions worth offering on what is currently listed.
 *
 * Trashed rows restore or burn; live rows publish, unpublish or go to the trash.
 * The same rule the row menu follows, for the same reason: offering "restore" on a
 * live post is a button that does nothing.
 */
const bulkActions = computed(() => (showingTrash.value
    ? [
        { value: "restore", label: t("backend.posts.bulk.action_restore"), variant: "secondary" },
        { value: "force_delete", label: t("backend.posts.bulk.action_force_delete"), variant: "danger" },
    ]
    : [
        { value: "publish", label: t("backend.posts.bulk.action_publish"), variant: "secondary" },
        { value: "draft", label: t("backend.posts.bulk.action_draft"), variant: "secondary" },
        { value: "trash", label: t("backend.posts.bulk.action_trash"), variant: "danger" },
    ]));

async function runBulk(action) {
    if (bulkRunning.value || 0 === selected.value.size) {
        return;
    }

    bulkRunning.value = true;
    bulkResult.value = null;

    try {
        const data = await request(props.bulkPath, {
            action,
            ids: [...selected.value],
        });

        if (!data) return;

        // Both numbers, kept on screen. A reader who selected ten and changed eight
        // would otherwise have to count rows to find that out.
        bulkResult.value = { done: data.done, skipped: data.skipped };
        selected.value = new Set();
        await reload();
    } finally {
        bulkRunning.value = false;
    }
}

const statusColors = {
    draft: "gray",
    pending_review: "amber",
    scheduled: "sky",
    published: "emerald",
    archived: "zinc",
};

/** Terms of every taxonomy, flattened once for the filter list. */
const allTerms = computed(() =>
    props.taxonomies.flatMap((taxonomy) =>
        (taxonomy.terms ?? []).map((term) => ({
            id: term.id,
            label: term.translations?.[props.locales[0]]?.name ?? `#${term.id}`,
            taxonomy: taxonomy.slug,
        })),
    ),
);
</script>

<template>
    <div class="space-y-4">
        <AppListToolbar>
            <AppSearchInput v-model="search" :placeholder="t('backend.posts.search_placeholder')" />
            <template #actions>
                <AppButton
                    v-if="can('editorial.posts.create')"
                    variant="primary"
                    size="md"
                    :href="newPath"
                    class="w-full sm:w-auto"
                >
                    <Plus class="w-4 h-4" :stroke-width="2" /> {{ t("backend.posts.create") }}
                </AppButton>
            </template>
        </AppListToolbar>

        <!-- Two lists, not one list narrowed. A trashed post supports different
             actions from a live one - restore and delete for good, never edit -
             so this picks *which* list you are working on, the same job the
             tabs do everywhere else in the backend. A toggle button read as
             "apply something to what I'm looking at", which is not what it does. -->
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <nav class="flex items-center gap-1" :aria-label="t('backend.posts.views')">
                <AppTab :active="!trashed" size="sm" v-on:click="trashed = false">
                    <FileText class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.posts.view_active") }}
                </AppTab>
                <AppTab :active="trashed" color="rose" size="sm" v-on:click="trashed = true">
                    <Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.posts.view_trash") }}
                </AppTab>
            </nav>

            <!-- Keyed on the list that is on screen, not on the one being
                 fetched: reading `trashed` here made this flash into view
                 against the previous list's rows and vanish a moment later. -->
            <AppButton
                v-if="showingTrash && can('editorial.posts.delete') && items.length"
                variant="danger"
                size="sm"
                v-on:click="confirmEmptyTrash = true"
            >
                <Flame class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.posts.empty_trash") }}
            </AppButton>
        </div>

        <!-- Only once something is ticked. A permanently visible bar of disabled
             buttons is furniture; one that appears is an answer to what the reader
             just did. -->
        <div
            v-if="selected.size"
            class="flex flex-wrap items-center gap-2 rounded-xl border border-accent-600/40 bg-accent-600/10 p-3"
        >
            <span class="text-sm text-primary">
                {{ t("backend.posts.bulk.selected", { count: selected.size }, selected.size) }}
            </span>

            <div class="ms-auto flex flex-wrap items-center gap-2">
                <AppButton
                    v-for="action in bulkActions"
                    :key="action.value"
                    :variant="action.variant"
                    size="sm"
                    :loading="bulkRunning"
                    v-on:click="runBulk(action.value)"
                >
                    {{ action.label }}
                </AppButton>
                <AppButton variant="ghost" size="sm" v-on:click="selected = new Set()">
                    {{ t("backend.posts.bulk.clear") }}
                </AppButton>
            </div>
        </div>

        <!-- What actually happened, both numbers. A selection spans posts with
             different authors, so some of it may have been refused - and a reader
             told only "done" would count ten rows and believe it. -->
        <p v-if="bulkResult" class="text-xs text-muted">
            {{ t("backend.posts.bulk.result", { done: bulkResult.done, skipped: bulkResult.skipped }) }}
        </p>

        <div class="bg-surface border border-line rounded-xl p-4 space-y-3">
            <div class="flex items-center justify-between gap-3">
                <span class="flex items-center gap-2 text-sm font-medium text-primary">
                    <Filter class="w-4 h-4" :stroke-width="2" /> {{ t("backend.posts.filters") }}
                </span>
                <div class="flex items-center gap-2">
                    <AppButton v-if="activeFilterCount" variant="ghost" size="sm" v-on:click="clearFilters">
                        <X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.posts.clear_filters") }}
                    </AppButton>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                <div class="space-y-1">
                    <p class="text-xs uppercase tracking-wide text-muted">{{ t("backend.posts.filter_type") }}</p>
                    <AppCheckbox
                        v-for="postType in postTypes"
                        :key="postType.id"
                        :model-value="postTypeIds.includes(postType.id)"
                        :label="postType.label"
                        v-on:update:model-value="toggleIn(postTypeIds, postType.id)"
                    />
                </div>
                <div class="space-y-1">
                    <p class="text-xs uppercase tracking-wide text-muted">{{ t("backend.posts.filter_status") }}</p>
                    <AppCheckbox
                        v-for="status in statusOptions"
                        :key="status"
                        :model-value="statuses.includes(status)"
                        :label="t(`backend.posts.status.${status}`)"
                        v-on:update:model-value="toggleIn(statuses, status)"
                    />
                </div>
                <div class="space-y-1">
                    <p class="text-xs uppercase tracking-wide text-muted">{{ t("backend.posts.filter_term") }}</p>
                    <AppNoData v-if="!allTerms.length" :message="t('backend.posts.no_terms')" />
                    <AppCheckbox
                        v-for="term in allTerms"
                        :key="term.id"
                        :model-value="termIds.includes(term.id)"
                        :label="term.label"
                        v-on:update:model-value="toggleIn(termIds, term.id)"
                    />
                </div>
            </div>
        </div>

        <div class="bg-surface border border-line rounded-lg overflow-x-auto scrollbar-thin">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-surface-2/50 border-b border-line/40">
                        <th class="w-10 px-6 py-3">
                            <input
                                type="checkbox"
                                class="cursor-pointer accent-accent-600"
                                :checked="allOnPageSelected"
                                :aria-label="t('backend.posts.bulk.select_all')"
                                v-on:change="toggleAllOnPage"
                            >
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t("backend.posts.title_column") }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell">{{ t("backend.posts.type_column") }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t("backend.posts.status_column") }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">{{ t("backend.posts.updated_column") }}</th>
                        <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted">{{ t("shared.common.actions") }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line/40">
                    <tr
                        v-for="post in items"
                        :key="post.id"
                        class="group transition-colors"
                        :class="selected.has(post.id) ? 'bg-accent-600/10' : 'hover:bg-surface-2/40'"
                    >
                        <td class="px-6 py-3">
                            <input
                                type="checkbox"
                                class="cursor-pointer accent-accent-600"
                                :checked="selected.has(post.id)"
                                :aria-label="post.title || t('backend.posts.untitled')"
                                v-on:change="toggleRow(post)"
                            >
                        </td>
                        <td class="px-6 py-3">
                            <p class="font-medium text-primary truncate">{{ post.title || t("backend.posts.untitled") }}</p>
                            <p class="text-xs text-muted font-mono mt-0.5 truncate">{{ post.reference }}</p>
                        </td>
                        <td class="px-6 py-3 text-secondary hidden md:table-cell">{{ post.postType.label }}</td>
                        <td class="px-6 py-3">
                            <AppBadge :color="statusColors[post.status] ?? 'gray'">
                                {{ t(`backend.posts.status.${post.status}`) }}
                            </AppBadge>
                        </td>
                        <td class="px-6 py-3 text-muted text-xs hidden lg:table-cell">{{ formatDateTime(post.updatedAt) }}</td>
                        <td class="px-6 py-3">
                            <AppRowActions :actions="actionsFor(post)" :label="post.title" />
                        </td>
                    </tr>
                    <tr v-if="!items.length && !loading">
                        <!-- "No post" and "the trash is empty" are different
                             facts, and the second is the reassuring one. -->
                        <td :colspan="6">
                            <AppNoData :message="t(showingTrash ? 'backend.posts.trash_empty' : 'backend.posts.empty')" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="totalPages > 1" class="flex items-center justify-between gap-3">
            <p class="text-xs text-muted">{{ t("backend.posts.total", { count: total }) }}</p>
            <AppPagination :page="page" :total-pages="totalPages" v-on:change="goToPage" />
        </div>

        <AppModal
            :show="!!pendingDelete"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="FileText"
            v-on:close="pendingDelete = null"
        >
            <p class="text-sm text-primary">{{ t("backend.posts.delete_confirm", { title: pendingDelete?.title ?? "" }) }}</p>
            <p class="text-sm text-secondary">{{ t("backend.posts.delete_hint") }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDelete = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="danger" size="md" :loading="deleteLoading" v-on:click="doDelete"><Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
        <AppModal
            :show="!!pendingForceDelete"
            max-width="sm"
            :closeable="false"
            :title="t('backend.posts.force_delete')"
            :icon="Flame"
            v-on:close="pendingForceDelete = null"
        >
            <p class="text-sm text-primary">{{ t("backend.posts.force_delete_confirm", { title: pendingForceDelete?.title ?? "" }) }}</p>
            <p class="text-sm text-secondary">{{ t("backend.posts.irreversible") }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingForceDelete = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="danger" size="md" v-on:click="forceDelete"><Flame class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.posts.force_delete") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="confirmEmptyTrash"
            max-width="sm"
            :closeable="false"
            :title="t('backend.posts.empty_trash')"
            :icon="Flame"
            v-on:close="confirmEmptyTrash = false"
        >
            <p class="text-sm text-primary">{{ t("backend.posts.empty_trash_confirm") }}</p>
            <p class="text-sm text-secondary">{{ t("backend.posts.irreversible") }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="confirmEmptyTrash = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="danger" size="md" :loading="emptyingTrash" v-on:click="emptyTrash"><Flame class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.posts.empty_trash") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
