<script setup>
import { useI18n } from "vue-i18n";
import { usePostSearch } from "./composables/usePostSearch.js";
import PostCard from "./PostCard.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppPagination from "@/shared/components/nav/AppPagination.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";

const { t } = useI18n();

const props = defineProps({
    initialPosts: { type: Array, default: () => [] },
    initialPage: { type: Number, default: 1 },
    initialTotalPages: { type: Number, default: 1 },
    initialTotal: { type: Number, default: 0 },
    searchPath: { type: String, required: true },
    postTypeSlug: { type: String, default: "article" },
    locale: { type: String, default: "fr" },
});

const { query, posts, page, totalPages, total, loading, onSearch, goToPage } = usePostSearch(props);

function postUrl(post) {
    return `/${props.locale}/${post.postTypeSlug ?? props.postTypeSlug}/${post.slug}`;
}
</script>

<template>
    <section class="space-y-8">
        <div class="flex flex-col sm:flex-row sm:items-center gap-4">
            <h1 class="text-3xl font-bold flex-1">{{ t('frontend.theme.title') }}</h1>
            <div class="w-full sm:w-72">
                <AppSearchInput
                    :model-value="query"
                    :placeholder="t('frontend.theme.search_placeholder')"
                    v-on:search="onSearch"
                />
            </div>
        </div>

        <div v-if="loading" class="text-muted text-sm">{{ t('shared.common.load_more') }}…</div>

        <AppNoData
            v-else-if="!posts.length"
            :message="query ? t('frontend.theme.no_results') : t('frontend.theme.no_posts')"
        />

        <template v-else>
            <p class="text-xs text-muted">{{ t('shared.pagination.results', { from: 1, to: posts.length, total }) }}</p>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <PostCard
                    v-for="post in posts"
                    :key="post.id"
                    :post="post"
                    :post-url="postUrl(post)"
                />
            </div>

            <AppPagination :page="page" :total-pages="totalPages" v-on:change="goToPage" />
        </template>
    </section>
</template>
