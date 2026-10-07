<script setup>
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { usePostsList } from "./composables/usePostsList.js";
import { useNarrowContainer } from "@/shared/composables/list/useNarrowContainer.js";
import { usePostRowActions } from "./composables/usePostRowActions.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppRevealList from "@/shared/components/list/AppRevealList.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppPagination from "@/shared/components/nav/AppPagination.vue";
import { Globe, Plus, Trash2, X, FileText, Filter } from "lucide-vue-next";

const { t } = useI18n();
const { can } = usePrivileges();
const { request } = useRequest();
const { formatDateTime } = useDateFormat();

const props = defineProps({
    posts: { type: Object, default: () => ({ items: [], total: 0, page: 1, totalPages: 1 }) },
    search: { type: String, default: "" },
    postTypes: { type: Array, default: () => [] },
    taxonomies: { type: Array, default: () => [] },
    locales: { type: Array, default: () => [] },
    statusOptions: { type: Array, default: () => [] },
    visibilityOptions: { type: Array, default: () => [] },
    postTypeIds: { type: Array, default: () => [] },
    termIds: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    visibilities: { type: Array, default: () => [] },
    listPath: { type: String, required: true },
    newPath: { type: String, required: true },
    editPathTemplate: { type: String, required: true },
    deletePathTemplate: { type: String, required: true },
    duplicatePathTemplate: { type: String, default: "" },
    previewPathTemplate: { type: String, default: "" },
    bulkPath: { type: String, default: "" },
});

const {
    items, total, page, totalPages, loading,
    search, postTypeIds, termIds, statuses, visibilities,
    activeFilterCount, goToPage, toggleIn, clearFilters,
    pendingDelete, deleteLoading, confirmDelete, doDelete,
    editPath, reload,
} = usePostsList(props);

// What a row offers depends on the permission, not on a `v-if` in a table
// cell. Restoring and destroying are not here: this list holds live
// publications, and the Trash screen owns what has been deleted.
const actionsFor = usePostRowActions({
    can,
    editPath,
    confirmDelete,
    duplicate: duplicatePost,
    preview: previewPost,
    canPreview: Boolean(props.previewPathTemplate),
});

/**
 * Opens the page as a visitor will see it, in another tab.
 *
 * Nothing is saved first, unlike the editor's own button: from a list there
 * is nothing on screen to save, and the last saved state is exactly what the
 * reader is asking to look at.
 *
 * The tab is opened *before* the request, because a `window.open` that
 * follows an await is a pop-up the browser did not see the click for, and
 * gets blocked. `opener` is cleared rather than passing `noopener`, which by
 * specification would return null and lose the handle.
 */
async function previewPost(post) {
    const tab = window.open("", "_blank");

    if (null !== tab) {
        tab.opener = null;
    }

    try {
        const data = await request(props.previewPathTemplate.replace("__id__", String(post.id)));

        if (!data?.url) {
            tab?.close();

            return;
        }

        if (null === tab) {
            window.location.href = data.url;

            return;
        }

        tab.location.href = data.url;
    } catch (error) {
        tab?.close();

        throw error;
    }
}

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
const { container, isNarrow } = useNarrowContainer();

const selected = ref(new Set());

/**
 * Cleared whenever the list underneath changes.
 *
 * Paging or filtering with a selection still held would apply an action to rows
 * that scrolled out of sight - the reader ticks three, filters, presses publish,
 * and three posts they can no longer see change.
 */
watch(items, () => {
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
 * What a selection of live publications can be put through.
 *
 * Restoring and destroying are not here any more: they belong to the Trash
 * screen, which owns them for every module at once. This list only ever shows
 * what has not been deleted.
 */
const bulkActions = computed(() => [
    {
        key: "publish",
        icon: Globe,
        title: t("suite.posts.bulk.action_publish"),
        loading: bulkRunning.value,
        onSelect: () => runBulk("publish"),
    },
    {
        key: "draft",
        icon: FileText,
        title: t("suite.posts.bulk.action_draft"),
        loading: bulkRunning.value,
        onSelect: () => runBulk("draft"),
    },
    // Last, as everywhere: the one that takes something away.
    {
        key: "trash",
        color: "rose",
        icon: Trash2,
        title: t("suite.posts.bulk.action_trash"),
        loading: bulkRunning.value,
        onSelect: () => runBulk("trash"),
    },
]);

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

// The create verb is marked `primary`: AppPageActions sets it beside the
// sheet as the page's main button, and the sheet keeps whatever else there is.
const pageActions = computed(() => {
    if (!can("editorial.posts.create")) {
        return [];
    }

    return [
        {
            key: "create",
            primary: true,
            color: "accent",
            icon: Plus,
            title: t("suite.posts.create"),
            href: props.newPath,
        },
    ];
});
</script>

<template>
    <div ref="container" class="aurora-stack">
        <AppListToolbar>
            <AppSearchInput v-model="search" :placeholder="t('suite.posts.search_placeholder')" />
            <template #actions>
                <AppPageActions
                    v-if="pageActions.length"
                    :actions="pageActions"
                    class="w-full sm:w-auto"
                />
            </template>
        </AppListToolbar>
        <!-- The screen's how-to guide, next to what it explains; collapsed
             or expanded, the choice applies to every guide. -->
        <AppGuide :title="t('suite.posts.guide.title')" storage-key="posts-list">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 5" :key="step">{{ t(`suite.posts.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <!-- Only once something is ticked. A permanently visible bar of disabled
             buttons is furniture; one that appears is an answer to what the reader
             just did. -->
        <div
            v-if="selected.size"
            class="flex flex-wrap items-center gap-2 rounded-xl border border-accent-600/40 bg-accent-600/10 p-3"
        >
            <span class="text-sm text-primary">
                {{ t("suite.posts.bulk.selected", { count: selected.size }, selected.size) }}
            </span>

            <!-- The count and the way out stay: they are what the bar is for.
                 The three verbs sit behind one button, which is also what keeps
                 the destructive one from being a thumb's width from the other
                 two on a phone. -->
            <div class="ms-auto flex flex-wrap items-center gap-2">
                <AppPageActions :actions="bulkActions" variant="secondary" size="sm" :busy="bulkRunning" />
                <AppButton variant="ghost" size="sm" v-on:click="selected = new Set()">
                    {{ t("suite.posts.bulk.clear") }}
                </AppButton>
            </div>
        </div>

        <!-- What actually happened, both numbers. A selection spans posts with
             different authors, so some of it may have been refused - and a reader
             told only "done" would count ten rows and believe it. -->
        <p v-if="bulkResult" class="text-xs text-muted">
            {{ t("suite.posts.bulk.result", { done: bulkResult.done, skipped: bulkResult.skipped }) }}
        </p>

        <div class="aurora-card p-4 space-y-3">
            <div class="flex items-center justify-between gap-3">
                <span class="flex items-center gap-2 text-sm font-medium text-primary">
                    <Filter class="w-4 h-4" :stroke-width="2" /> {{ t("suite.posts.filters") }}
                </span>
                <div class="flex items-center gap-2">
                    <AppButton v-if="activeFilterCount" variant="ghost" size="sm" v-on:click="clearFilters">
                        <X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("suite.posts.clear_filters") }}
                    </AppButton>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                <div class="space-y-1">
                    <p class="text-xs uppercase tracking-wide text-muted">{{ t("suite.posts.filter_type") }}</p>
                    <AppRevealList :items="postTypes" :is-active="(postType) => postTypeIds.includes(postType.id)">
                        <template #default="{ item }">
                            <AppCheckbox
                                :model-value="postTypeIds.includes(item.id)"
                                :label="item.label"
                                v-on:update:model-value="toggleIn(postTypeIds, item.id)"
                            />
                        </template>
                    </AppRevealList>
                </div>
                <div class="space-y-1">
                    <p class="text-xs uppercase tracking-wide text-muted">{{ t("suite.posts.filter_status") }}</p>
                    <AppRevealList :items="statusOptions" :is-active="(status) => statuses.includes(status)">
                        <template #default="{ item }">
                            <AppCheckbox
                                :model-value="statuses.includes(item)"
                                :label="t(`suite.posts.status.${item}`)"
                                v-on:update:model-value="toggleIn(statuses, item)"
                            />
                        </template>
                    </AppRevealList>
                    <p class="pt-2 text-xs uppercase tracking-wide text-muted">{{ t("suite.posts.filter_visibility") }}</p>
                    <AppCheckbox
                        v-for="visibility in visibilityOptions"
                        :key="visibility"
                        :model-value="visibilities.includes(visibility)"
                        :label="t(`suite.posts.visibility.${visibility}`)"
                        v-on:update:model-value="toggleIn(visibilities, visibility)"
                    />
                </div>
                <div class="space-y-1">
                    <p class="text-xs uppercase tracking-wide text-muted">{{ t("suite.posts.filter_term") }}</p>
                    <AppNoData v-if="!allTerms.length" :message="t('suite.posts.no_terms')" />
                    <AppRevealList :items="allTerms" :is-active="(term) => termIds.includes(term.id)">
                        <template #default="{ item }">
                            <AppCheckbox
                                :model-value="termIds.includes(item.id)"
                                :label="item.label"
                                v-on:update:model-value="toggleIn(termIds, item.id)"
                            />
                        </template>
                    </AppRevealList>
                </div>
            </div>
        </div>

        <div v-if="!isNarrow" class="aurora-card overflow-x-auto scrollbar-thin">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-surface-2/50 border-b border-line/40">
                        <th class="w-10 px-4 py-2">
                            <input
                                type="checkbox"
                                class="cursor-pointer accent-accent-600"
                                :checked="allOnPageSelected"
                                :aria-label="t('suite.posts.bulk.select_all')"
                                v-on:change="toggleAllOnPage"
                            >
                        </th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t("suite.posts.title_column") }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell">{{ t("suite.posts.type_column") }}</th>
                        <th
                            v-if="locales.length > 1"
                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell"
                        >
                            {{ t("suite.posts.translations.column") }}
                        </th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t("suite.posts.status_column") }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">{{ t("suite.posts.updated_column") }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-muted sticky right-0 bg-surface-2 border-l border-line/40">{{ t("shared.common.actions") }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line/40">
                    <tr
                        v-for="post in items"
                        :key="post.id"
                        class="group transition-colors"
                        :class="selected.has(post.id) ? 'bg-accent-600/10' : 'hover:bg-surface-2/40'"
                    >
                        <td class="px-4 py-2">
                            <input
                                type="checkbox"
                                class="cursor-pointer accent-accent-600"
                                :checked="selected.has(post.id)"
                                :aria-label="post.title || t('suite.posts.untitled')"
                                v-on:change="toggleRow(post)"
                            >
                        </td>
                        <td class="px-4 py-2">
                            <p class="font-medium text-primary truncate">{{ post.title || t("suite.posts.untitled") }}</p>
                            <p class="text-xs text-muted font-mono mt-0.5 truncate">{{ post.reference }}</p>
                        </td>
                        <td class="px-4 py-2 text-secondary hidden md:table-cell">{{ post.postType.label }}</td>
                        <!-- Every language, with the missing ones dimmed rather than
                             absent. A row listing only what exists cannot be scanned
                             down a column for holes, which is the one thing this is
                             for. -->
                        <td v-if="locales.length > 1" class="px-6 py-3 hidden lg:table-cell">
                            <span class="flex items-center gap-1">
                                <span
                                    v-for="code in locales"
                                    :key="code"
                                    class="rounded px-1.5 py-0.5 text-2xs font-medium uppercase"
                                    :class="(post.translatedLocales ?? []).includes(code)
                                        ? 'bg-emerald-500/15 text-emerald-500'
                                        : 'bg-surface-2 text-muted/60'"
                                    :title="(post.translatedLocales ?? []).includes(code)
                                        ? t('suite.posts.translations.translated', { locale: code })
                                        : t('suite.posts.translations.missing', { locale: code })"
                                >{{ code }}</span>
                            </span>
                        </td>
                        <td class="px-4 py-2">
                            <span class="flex flex-wrap items-center gap-1">
                                <AppBadge :color="statusColors[post.status] ?? 'gray'">
                                    {{ t(`suite.posts.status.${post.status}`) }}
                                </AppBadge>
                                <!-- Only the exception is marked: a list where
                                     every row says "on the site" says nothing. -->
                                <AppBadge v-if="post.visibility === 'link'" color="violet">
                                    {{ t("suite.posts.visibility.link") }}
                                </AppBadge>
                            </span>
                        </td>
                        <td class="px-4 py-2 text-muted text-xs hidden lg:table-cell">{{ formatDateTime(post.updatedAt) }}</td>
                        <td class="px-4 py-2 sticky right-0 bg-surface border-l border-line/40">
                            <AppRowActions :actions="actionsFor(post)" :label="post.title" />
                        </td>
                    </tr>
                    <tr v-if="!items.length && !loading">
                        <td :colspan="locales.length > 1 ? 7 : 6">
                            <AppNoData :message="t('suite.posts.empty')" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- The same row, read down instead of across. Every fact the table
             column carries is here, in the same order, and the actions sit
             behind the "…" button next to the title, as on every list (Axel's
             call, 04/10/2026). -->
        <div v-else class="space-y-2">
            <article
                v-for="post in items"
                :key="post.id"
                class="bg-surface border rounded-lg p-3 space-y-2.5 transition-colors"
                :class="selected.has(post.id) ? 'border-accent-600/50 bg-accent-600/5' : 'border-line'"
            >
                <div class="flex items-start gap-3">
                    <input
                        type="checkbox"
                        class="mt-1 cursor-pointer accent-accent-600 shrink-0"
                        :checked="selected.has(post.id)"
                        :aria-label="post.title || t('suite.posts.untitled')"
                        v-on:change="toggleRow(post)"
                    >
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-primary break-words">{{ post.title || t("suite.posts.untitled") }}</p>
                        <p class="text-xs text-muted font-mono mt-0.5">{{ post.reference }}</p>
                    </div>
                    <span class="flex shrink-0 flex-col items-end gap-1">
                        <AppBadge :color="statusColors[post.status] ?? 'gray'">
                            {{ t(`suite.posts.status.${post.status}`) }}
                        </AppBadge>
                        <AppBadge v-if="post.visibility === 'link'" color="violet">
                            {{ t("suite.posts.visibility.link") }}
                        </AppBadge>
                    </span>
                    <AppRowActions class="shrink-0" :actions="actionsFor(post)" :label="post.title || t('suite.posts.untitled')" />
                </div>

                <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted">
                    <span class="text-secondary">{{ post.postType.label }}</span>
                    <span v-if="locales.length > 1" class="flex items-center gap-1">
                        <span
                            v-for="code in locales"
                            :key="code"
                            class="rounded px-1.5 py-0.5 text-2xs font-medium uppercase"
                            :class="(post.translatedLocales ?? []).includes(code)
                                ? 'bg-emerald-500/15 text-emerald-500'
                                : 'bg-surface-2 text-muted/60'"
                            :title="(post.translatedLocales ?? []).includes(code)
                                ? t('suite.posts.translations.translated', { locale: code })
                                : t('suite.posts.translations.missing', { locale: code })"
                        >{{ code }}</span>
                    </span>
                    <span>{{ formatDateTime(post.updatedAt) }}</span>
                </p>
            </article>

            <AppNoData v-if="!items.length && !loading" :message="t('suite.posts.empty')" />
        </div>

        <div v-if="totalPages > 1" class="flex items-center justify-between gap-3">
            <p class="text-xs text-muted">{{ t("suite.posts.total", { count: total }) }}</p>
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
            <p class="text-sm text-primary">{{ t("suite.posts.delete_confirm", { title: pendingDelete?.title ?? "" }) }}</p>
            <p class="text-sm text-secondary">{{ t("suite.posts.delete_hint") }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDelete = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="danger" size="md" :loading="deleteLoading" v-on:click="doDelete"><Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
