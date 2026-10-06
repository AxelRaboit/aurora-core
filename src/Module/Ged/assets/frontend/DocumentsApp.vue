<script setup>
import { useI18n } from "vue-i18n";
import { useDocumentSearch } from "./composables/useDocumentSearch.js";
import DocumentItem from "./components/DocumentItem.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppPagination from "@/shared/components/nav/AppPagination.vue";

const { t } = useI18n();

const props = defineProps({
    initialItems: { type: Array, default: () => [] },
    initialPage: { type: Number, default: 1 },
    initialTotalPages: { type: Number, default: 1 },
    initialTotal: { type: Number, default: 0 },
    searchPath: { type: String, required: true },
});

const { query, items, page, totalPages, total, loading, onSearch, goToPage } = useDocumentSearch(props);
</script>

<template>
    <div class="max-w-4xl mx-auto px-4 py-10 space-y-8">
        <div class="flex flex-col sm:flex-row sm:items-center gap-4">
            <h1 class="text-2xl font-bold text-primary flex-1">{{ t('ged.frontend.documents.title') }}</h1>
            <div class="w-full sm:w-72">
                <AppSearchInput
                    :model-value="query"
                    :placeholder="t('ged.frontend.documents.search_placeholder')"
                    v-on:search="onSearch"
                />
            </div>
        </div>

        <div v-if="loading" class="text-muted text-sm">{{ t('shared.common.load_more') }}…</div>

        <template v-else-if="items.length === 0">
            <p class="text-muted text-sm">
                {{ query ? t('ged.frontend.documents.no_results') : t('ged.frontend.documents.empty') }}
            </p>
        </template>

        <template v-else>
            <p class="text-xs text-muted">{{ t('ged.frontend.documents.result_count', { count: total }) }}</p>

            <ul class="space-y-2">
                <DocumentItem v-for="gedDocument in items" :key="gedDocument.id" :doc="gedDocument" />
            </ul>

            <AppPagination :page="page" :total-pages="totalPages" v-on:change="goToPage" />
        </template>
    </div>
</template>
