<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronLeft, ChevronRight } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";

const props = defineProps({
    page: { type: Number, required: true },
    totalPages: { type: Number, required: true },
});

const emit = defineEmits(["change"]);

const { t } = useI18n();

const showNumbers = computed(() => props.totalPages <= 10);

function go(newPage) {
    if (newPage >= 1 && newPage <= props.totalPages) {
        emit("change", newPage);
    }
}
</script>

<template>
    <div v-if="totalPages > 1" class="flex flex-col sm:flex-row items-center sm:justify-between gap-3 text-sm">
        <span class="text-secondary shrink-0 order-2 sm:order-1">
            {{ t("shared.common.pagination", { page, totalPages }) }}
        </span>

        <div class="flex items-center gap-1 order-1 sm:order-2">
            <!-- Icon only below `sm`, with its name as a tooltip and for
                 screen readers: the old `hidden sm:inline` left two buttons
                 without a name on a phone (02/10/2026). -->
            <AppButton
                variant="ghost"
                size="sm"
                :disabled="page <= 1"
                :label="t('shared.pagination.previous')"
                icon-only-on-phone
                v-on:click="go(page - 1)"
            >
                <ChevronLeft class="w-4 h-4" />
            </AppButton>

            <template v-if="showNumbers">
                <button
                    v-for="pageNum in totalPages"
                    :key="pageNum"
                    type="button"
                    class="size-7.5 rounded-lg text-sm font-medium transition-colors"
                    :class="pageNum === page ? 'bg-accent-600 text-white shadow-sm' : 'bg-surface-2 text-secondary hover:bg-surface-3'"
                    v-on:click="go(pageNum)"
                >
                    {{ pageNum }}
                </button>
            </template>

            <AppButton
                variant="ghost"
                size="sm"
                class="flex-row-reverse"
                :disabled="page >= totalPages"
                :label="t('shared.pagination.next')"
                icon-only-on-phone
                v-on:click="go(page + 1)"
            >
                <ChevronRight class="w-4 h-4" />
            </AppButton>
        </div>
    </div>
</template>
