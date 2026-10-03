<script setup>
import { computed, toRef } from 'vue';
import { useI18n } from 'vue-i18n';
import { Link2, FileSearch, X, FileText, ListTree } from 'lucide-vue-next';
import { outlineOf, readingMinutes, wordCount } from '@notes/backend/markdown/composables/noteOutline.js';
import { useNoteSidePanel } from '@notes/backend/markdown/composables/useNoteSidePanel.js';
import AppIconButton from '@shared/components/action/AppIconButton.vue';
import AppListItemButton from '@shared/components/action/AppListItemButton.vue';
import AppTab from '@shared/components/nav/AppTab.vue';
import AppNoData from '@shared/components/feedback/AppNoData.vue';

const props = defineProps({
    noteId: { type: Number, default: null },
    fetchBacklinks: { type: Function, required: true },
    fetchUnlinkedMentions: { type: Function, required: true },
    /** Le texte de la note ouverte, d'où se lit son plan. */
    content: { type: String, default: '' },
});

const emit = defineEmits(['close', 'navigate', 'jump']);

const { t } = useI18n();
const { tab, items, loading } = useNoteSidePanel({
    noteIdRef: toRef(props, 'noteId'),
    fetchBacklinks: props.fetchBacklinks,
    fetchUnlinkedMentions: props.fetchUnlinkedMentions,
});

// Le plan : les titres de la note, et sa longueur en pied. Lu dans le texte,
// il suit la frappe sans rien demander au serveur.
const outline = computed(() => outlineOf(props.content));
const words = computed(() => wordCount(props.content));
const minutes = computed(() => readingMinutes(words.value));
const topLevel = computed(() => Math.min(...outline.value.map((heading) => heading.level), 6));
</script>

<template>
    <aside
        class="flex flex-col bg-surface fixed inset-0 z-40 md:z-auto md:relative md:inset-auto md:w-72 md:shrink-0 md:border-l md:border-line md:bg-surface-2/30"
    >
        <header class="p-3 border-b border-line flex items-center justify-between gap-2">
            <h3 class="text-sm font-semibold text-primary">{{ t('notes.markdown.outline.panel_title') }}</h3>
            <AppIconButton
                :title="t('notes.markdown.links.close')"
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
                data-side-tab="outline"
                :active="tab === 'outline'"
                v-on:click="tab = 'outline'"
            >
                <ListTree class="w-3.5 h-3.5" :stroke-width="2" />
                <span>{{ t('notes.markdown.outline.tab') }}</span>
            </AppTab>
            <AppTab
                size="xs"
                align="center"
                class="flex-1"
                data-side-tab="backlinks"
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
                data-side-tab="mentions"
                :active="tab === 'mentions'"
                v-on:click="tab = 'mentions'"
            >
                <FileSearch class="w-3.5 h-3.5" :stroke-width="2" />
                <span>{{ t('notes.markdown.links.mentions') }}</span>
            </AppTab>
        </div>

        <!-- Le plan de la note : un titre se clique pour y aller, en retrait
             selon son niveau. -->
        <div v-if="tab === 'outline'" class="flex flex-1 flex-col overflow-hidden" data-note-outline>
            <ul v-if="outline.length" class="m-0 flex-1 list-none overflow-auto p-2">
                <li v-for="heading in outline" :key="heading.line">
                    <button
                        type="button"
                        class="block w-full truncate rounded-md px-2 py-1 text-left text-sm text-secondary transition-colors hover:bg-surface-2 hover:text-primary"
                        :class="heading.level === topLevel ? 'font-medium text-primary' : ''"
                        :style="{ paddingLeft: `${0.5 + (heading.level - topLevel) * 0.75}rem` }"
                        :title="heading.text"
                        v-on:click="emit('jump', heading)"
                    >
                        {{ heading.text }}
                    </button>
                </li>
            </ul>
            <AppNoData
                v-else
                class="flex-1"
                :message="t('notes.markdown.outline.empty')"
                :hint="t('notes.markdown.outline.empty_hint')"
                :icon="ListTree"
            />
            <p class="m-0 border-t border-line px-3 py-2 text-xs text-muted tabular-nums" data-note-length>
                {{ t('notes.markdown.outline.words', { count: words }, words) }}
                <template v-if="minutes"> · {{ t('notes.markdown.outline.minutes', { minutes }) }}</template>
            </p>
        </div>

        <div v-else class="flex-1 overflow-auto p-2">
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
                :message="tab === 'backlinks' ? t('notes.markdown.links.empty_backlinks') : t('notes.markdown.links.empty_mentions')"
                :hint="tab === 'backlinks' ? t('notes.markdown.links.empty_backlinks_description') : t('notes.markdown.links.empty_mentions_description')"
                :icon="tab === 'backlinks' ? Link2 : FileSearch"
            />
        </div>
    </aside>
</template>
