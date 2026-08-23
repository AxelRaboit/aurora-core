<script setup>
/**
 * The publications, listed so somebody can go and file photographs.
 *
 * The same rows as the posts list and a different question, which is why it is a
 * list of its own rather than a filter on that one: there, a row is a thing you
 * write; here it is a gallery you fill. So the column that matters is how many
 * pictures it already has, and the only action is the one this screen grants.
 *
 * No status, no type filter, no trash. Everything this screen does not let you
 * change has no business taking up space in it - and a control that cannot be
 * acted on reads as broken rather than absent.
 */
import { useI18n } from "vue-i18n";
import { Images } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppPagination from "@/shared/components/nav/AppPagination.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import { useListPage } from "@/shared/composables/list/useListPage.js";

const props = defineProps({
    posts: { type: Object, required: true },
    search: { type: String, default: "" },
    listPath: { type: String, required: true },
    editPathTemplate: { type: String, required: true },
});

const { t } = useI18n();

const { items, loading, page, totalPages, search, onSearch, goToPage } = useListPage(
    props.listPath,
    { initialSearch: props.search, initialData: props.posts },
);

function editPath(post) {
    return props.editPathTemplate.replace("__id__", String(post.id));
}
</script>

<template>
    <div class="relative space-y-4">
        <AppLoader :active="loading" />

        <AppListToolbar>
            <AppSearchInput
                :model-value="search"
                :placeholder="t('backend.post_galleries.search_placeholder')"
                v-on:update:model-value="onSearch"
            />
        </AppListToolbar>

        <AppNoData v-if="!items.length" :message="t('backend.post_galleries.empty')" />

        <div v-else class="space-y-2">
            <div
                v-for="post in items"
                :key="post.id"
                class="flex flex-wrap items-center gap-3 rounded-xl border border-line bg-surface p-3"
            >
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-primary">
                        {{ post.title || t("backend.post_galleries.untitled") }}
                    </p>
                    <p class="text-2xs text-muted">{{ post.postType?.label }}</p>
                </div>

                <!-- The number, in words rather than a bare figure: "0" beside a
                     title reads as a fact about the publication, and this one is
                     the reason to open it. Zero says so in its own phrase so an
                     empty gallery is findable at a glance down the column. -->
                <span
                    class="shrink-0 text-xs tabular-nums"
                    :class="post.galleryItemCount ? 'text-secondary' : 'text-muted'"
                >
                    {{ post.galleryItemCount
                        ? t(
                            "backend.post_galleries.photo_count",
                            { count: post.galleryItemCount },
                            post.galleryItemCount,
                        )
                        : t("backend.post_galleries.no_photos") }}
                </span>

                <AppButton variant="secondary" size="sm" :href="editPath(post)" class="shrink-0">
                    <Images class="h-4 w-4" :stroke-width="2" />
                    {{ t("backend.post_galleries.edit") }}
                </AppButton>
            </div>
        </div>

        <AppPagination
            v-if="totalPages > 1"
            :page="page"
            :total-pages="totalPages"
            v-on:change="goToPage"
        />
    </div>
</template>
