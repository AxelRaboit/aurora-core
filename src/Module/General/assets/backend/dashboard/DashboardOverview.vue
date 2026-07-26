<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { useFileSize } from "@/shared/composables/format/useFileSize.js";
import { statusBadge } from "@/shared/utils/format/statusStyles.js";
import AppChart from "@/shared/components/display/AppChart.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import { FileText, Image as ImageIcon, Menu as MenuIcon, Package, Users } from "lucide-vue-next";
import { useDashboardModule } from "@general/backend/dashboard/composables/useDashboardModule.js";
import { useDashboardCharts } from "@general/backend/dashboard/composables/useDashboardCharts.js";

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    enabledModules: { type: Object, default: () => ({}) },
});

const { t } = useI18n();
const { formatDateTime } = useDateFormat();
const { formatSize } = useFileSize();

const stats = computed(() => props.stats ?? {});
const enabledModules = computed(() => props.enabledModules);

const { activeModule, selectModule, visibleModules } = useDashboardModule(enabledModules);
const { postsByMonthData } = useDashboardCharts(stats);
</script>

<template>
    <div class="space-y-6">
        <div v-if="visibleModules.length === 0" class="flex flex-col items-center justify-center py-24 text-center text-secondary">
            <Package class="w-10 h-10 mb-3 opacity-30" :stroke-width="1.5" />
            <p class="text-sm">{{ t('backend.stats.no_module_enabled') }}</p>
        </div>

        <template v-else>
            <div class="inline-flex p-1 bg-surface-2 border border-line rounded-lg gap-1 max-w-full overflow-x-auto scrollbar-thin">
                <AppTab
                    v-for="module in visibleModules"
                    :key="module.id"
                    size="sm"
                    :active="activeModule === module.id"
                    active-class="bg-surface text-primary shadow-sm"
                    inactive-class="text-secondary hover:text-primary"
                    class="whitespace-nowrap"
                    v-on:click="selectModule(module.id)"
                >
                    <component :is="module.icon" class="w-4 h-4" :stroke-width="2" />
                    {{ module.label() }}
                </AppTab>
            </div>

            <section v-show="activeModule === 'editorial'" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="bg-surface border border-line rounded-xl p-4">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-xs font-medium text-secondary uppercase tracking-wide">{{ t('backend.stats.posts') }}</span>
                            <div class="w-8 h-8 rounded-lg bg-accent-600/10 flex items-center justify-center">
                                <FileText class="w-4 h-4 text-accent-500" :stroke-width="2" />
                            </div>
                        </div>
                        <p class="text-2xl font-bold text-accent-400">{{ stats.posts?.total ?? 0 }}</p>
                        <p class="text-xs text-muted mt-0.5">
                            {{ stats.posts?.published ?? 0 }} {{ t('backend.stats.published') }} ·
                            {{ stats.posts?.draft ?? 0 }} {{ t('backend.stats.draft') }}
                        </p>
                    </div>
                    <div class="bg-surface border border-line rounded-xl p-4">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-xs font-medium text-secondary uppercase tracking-wide">{{ t('backend.stats.media') }}</span>
                            <div class="w-8 h-8 rounded-lg bg-sky-500/10 flex items-center justify-center">
                                <ImageIcon class="w-4 h-4 text-sky-400" :stroke-width="2" />
                            </div>
                        </div>
                        <p class="text-2xl font-bold text-sky-400">{{ stats.media?.total ?? 0 }}</p>
                        <p class="text-xs text-muted mt-0.5">{{ formatSize(stats.media?.totalSize ?? 0) }}</p>
                    </div>
                    <div class="bg-surface border border-line rounded-xl p-4">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-xs font-medium text-secondary uppercase tracking-wide">{{ t('backend.stats.editorial.pending_comments') }}</span>
                            <div class="w-8 h-8 rounded-lg bg-amber-500/10 flex items-center justify-center">
                                <MenuIcon class="w-4 h-4 text-amber-400" :stroke-width="2" />
                            </div>
                        </div>
                        <p class="text-2xl font-bold text-amber-400">{{ stats.comments?.pending ?? 0 }}</p>
                        <p class="text-xs text-muted mt-0.5">
                            {{ stats.comments?.approved ?? 0 }} approuvés · {{ stats.comments?.spam ?? 0 }} spam
                        </p>
                    </div>
                    <div class="bg-surface border border-line rounded-xl p-4">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-xs font-medium text-secondary uppercase tracking-wide">{{ t('backend.stats.users') }}</span>
                            <div class="w-8 h-8 rounded-lg bg-emerald-500/10 flex items-center justify-center">
                                <Users class="w-4 h-4 text-emerald-400" :stroke-width="2" />
                            </div>
                        </div>
                        <p class="text-2xl font-bold text-emerald-400">{{ stats.users?.total ?? 0 }}</p>
                        <p class="text-xs text-muted mt-0.5">
                            {{ stats.posts?.scheduled ?? 0 }} {{ t('backend.stats.editorial.scheduled') }} ·
                            {{ stats.posts?.pendingReview ?? 0 }} {{ t('backend.stats.editorial.pending_review') }}
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <div v-if="stats.postsByMonth?.length" class="lg:col-span-2 bg-surface border border-line/60 rounded-xl p-5">
                        <h3 class="text-sm font-semibold text-primary mb-4">{{ t('backend.stats.posts_by_month') }}</h3>
                        <div class="h-56">
                            <AppChart type="line" :data="postsByMonthData" />
                        </div>
                    </div>
                    <div v-if="stats.posts?.byType?.length" class="bg-surface border border-line/60 rounded-xl p-5">
                        <h3 class="text-sm font-semibold text-primary mb-4">{{ t('backend.stats.by_type') }}</h3>
                        <div class="space-y-2">
                            <div v-for="item in stats.posts.byType" :key="item.slug" class="flex items-center justify-between text-sm">
                                <span class="text-secondary">{{ item.label }}</span>
                                <span class="font-medium text-primary tabular-nums">{{ item.count }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="stats.recentPosts?.length" class="bg-surface border border-line/60 rounded-xl p-5">
                    <h3 class="text-sm font-semibold text-primary mb-4">{{ t('backend.stats.recent') }}</h3>
                    <div class="divide-y divide-line/40">
                        <div v-for="post in stats.recentPosts" :key="post.id" class="flex items-start justify-between gap-3 py-2.5 text-sm first:pt-0 last:pb-0">
                            <div class="min-w-0 flex-1">
                                <div class="font-medium text-primary truncate">{{ post.title }}</div>
                                <div class="flex items-center gap-1.5 flex-wrap mt-0.5">
                                    <span class="px-1.5 py-0.5 text-xs rounded-md shrink-0" :class="statusBadge(post.status)">{{ t(`backend.stats.post_status.${post.status}`, post.status) }}</span>
                                    <span class="text-xs text-muted">{{ post.postType }} · {{ formatDateTime(post.updatedAt) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-surface border border-dashed border-line rounded-xl p-5 flex flex-col items-center justify-center gap-2 text-center min-h-32">
                        <div class="w-9 h-9 rounded-lg bg-surface-2 flex items-center justify-center">
                            <Users class="w-4 h-4 text-muted" :stroke-width="1.5" />
                        </div>
                        <p class="text-sm font-medium text-secondary">{{ t('backend.stats.editorial.posts_by_author') }}</p>
                        <p class="text-xs text-muted">{{ t('backend.stats.coming_soon') }}</p>
                    </div>
                    <div class="bg-surface border border-dashed border-line rounded-xl p-5 flex flex-col items-center justify-center gap-2 text-center min-h-32">
                        <div class="w-9 h-9 rounded-lg bg-surface-2 flex items-center justify-center">
                            <ImageIcon class="w-4 h-4 text-muted" :stroke-width="1.5" />
                        </div>
                        <p class="text-sm font-medium text-secondary">{{ t('backend.stats.editorial.media_by_type') }}</p>
                        <p class="text-xs text-muted">{{ t('backend.stats.coming_soon') }}</p>
                    </div>
                </div>
            </section>
        </template>
    </div>
</template>
