<script setup>
import { toRef } from 'vue';
import { useI18n } from 'vue-i18n';
import { Link2, FileSearch, X, FileText } from 'lucide-vue-next';
import { useNoteSidePanel } from '@notes/backend/markdown/composables/useNoteSidePanel.js';
import AppIconButton from '@shared/components/action/AppIconButton.vue';
import AppListItemButton from '@shared/components/action/AppListItemButton.vue';
import AppTab from '@shared/components/nav/AppTab.vue';
import AppNoData from '@shared/components/feedback/AppNoData.vue';

const props = defineProps({
    noteId: { type: Number, default: null },
    fetchBacklinks: { type: Function, required: true },
    fetchUnlinkedMentions: { type: Function, required: true },
});

const emit = defineEmits(['close', 'navigate']);

const { t } = useI18n();
const { tab, items, loading } = useNoteSidePanel({
    noteIdRef: toRef(props, 'noteId'),
    fetchBacklinks: props.fetchBacklinks,
    fetchUnlinkedMentions: props.fetchUnlinkedMentions,
});
</script>

<template>
    <aside
        class="flex flex-col bg-surface fixed inset-0 z-40 md:relative md:inset-auto md:w-72 md:shrink-0 md:border-l md:border-line md:bg-surface-2/30"
    >
        <header class="p-3 border-b border-line flex items-center justify-between gap-2">
            <h3 class="text-sm font-semibold text-primary">{{ t('notes.markdown.links.title') }}</h3>
            <AppIconButton
                :title="t('notes.markdown.links.close')"
                size="sm"
                variant="ghost"
                v-on:click="emit('close')"
            >
                <X class="w-4 h-4" :stroke-width="2" />
            </AppIconButton>
        </header>

        <div class="px-3 pt-2 flex gap-1">
            <AppTab
                size="xs"
                align="center"
                class="flex-1"
                :active="tab === 'backlinks'"
                v-on:click="tab = 'backlinks'"
            >
                <Link2 class="w-3.5 h-3.5" :stroke-width="2" />
                <span>{{ t('notes.markdown.links.backlinks') }}</span>
            </AppTab>
            <AppTab
                size="xs"
                align="center"
                class="flex-1"
                :active="tab === 'mentions'"
                v-on:click="tab = 'mentions'"
            >
                <FileSearch class="w-3.5 h-3.5" :stroke-width="2" />
                <span>{{ t('notes.markdown.links.mentions') }}</span>
            </AppTab>
        </div>

        <div class="flex-1 overflow-auto p-2">
            <div v-if="loading" class="text-xs text-muted px-2 py-3">
                {{ t('notes.markdown.links.loading') }}
            </div>

            <ul v-else-if="items.length > 0" class="space-y-0.5">
                <li v-for="item in items" :key="item.id">
                    <AppListItemButton v-on:click="emit('navigate', item.id)">
                        <template #icon>
                            <FileText class="w-3.5 h-3.5 text-muted" :stroke-width="1.75" />
                        </template>
                        {{ item.title || t('notes.markdown.untitled') }}
                    </AppListItemButton>
                </li>
            </ul>

            <AppNoData
                v-else
                :title="tab === 'backlinks' ? t('notes.markdown.links.empty_backlinks') : t('notes.markdown.links.empty_mentions')"
                :description="tab === 'backlinks' ? t('notes.markdown.links.empty_backlinks_description') : t('notes.markdown.links.empty_mentions_description')"
                :icon="tab === 'backlinks' ? Link2 : FileSearch"
            />
        </div>
    </aside>
</template>
