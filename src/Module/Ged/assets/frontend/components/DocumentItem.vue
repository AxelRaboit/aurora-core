<script setup>
import { useI18n } from "vue-i18n";

const { t } = useI18n();

defineProps({
    doc: { type: Object, required: true },
});
</script>

<template>
    <li class="aurora-card flex flex-col sm:flex-row sm:items-center gap-3 p-4 hover:bg-surface-2/40 transition-colors">
        <div class="flex-1 min-w-0">
            <p class="font-medium text-primary">{{ doc.title }}</p>
            <p v-if="doc.description" class="text-sm text-secondary mt-0.5">{{ doc.description }}</p>
            <div class="flex flex-wrap gap-1 mt-1.5">
                <span v-if="doc.categoryName" class="text-xs px-1.5 py-0.5 rounded-full bg-surface-2 text-secondary border border-line">{{ doc.categoryName }}</span>
                <span
                    v-for="tag in doc.tags"
                    :key="tag.name"
                    class="text-xs px-1.5 py-0.5 rounded-full border border-line"
                >{{ tag.name }}</span>
            </div>
        </div>
        <a
            v-if="doc.fileUrl"
            :href="doc.fileUrl"
            target="_blank"
            rel="noopener"
            download
            class="w-full sm:w-auto text-center sm:text-left shrink-0 text-sm font-medium text-accent hover:underline py-2 sm:py-0 border border-accent/30 sm:border-0 rounded-lg sm:rounded-none"
        >{{ t('shared.common.download') }}</a>
    </li>
</template>
