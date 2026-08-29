<script setup>
import { toRef } from 'vue';
import { useI18n } from 'vue-i18n';
import { Tag, Pencil, Trash2, Save, X, Check, Combine } from 'lucide-vue-next';
import AppModal from '@shared/components/overlay/AppModal.vue';
import AppModalFooter from '@shared/components/overlay/AppModalFooter.vue';
import AppButton from '@shared/components/action/AppButton.vue';
import AppIconButton from '@shared/components/action/AppIconButton.vue';
import AppInput from '@shared/components/form/input/AppInput.vue';
import AppSearchInput from '@shared/components/form/input/AppSearchInput.vue';
import AppCheckbox from '@shared/components/form/toggle/AppCheckbox.vue';
import AppNoData from '@shared/components/feedback/AppNoData.vue';
import { useNoteTagManager } from '@notes/backend/markdown/composables/useNoteTagManager.js';

const props = defineProps({
    show: { type: Boolean, default: false },
    api: { type: Object, required: true },
});

const emit = defineEmits(['close', 'changed']);

const { t } = useI18n();

const {
    loading,
    query,
    renaming,
    pendingDelete,
    mergeTarget,
    submitting,
    filteredTags,
    selectedTags,
    toggleSelected,
    isSelected,
    beginRename,
    cancelRename,
    confirmRename,
    beginDelete,
    cancelDelete,
    confirmDelete,
    confirmMerge,
} = useNoteTagManager({
    api: props.api,
    show: toRef(props, 'show'),
    onChanged: () => emit('changed'),
});

function close() {
    emit('close');
}
</script>

<template>
    <AppModal
        :show="show"
        max-width="lg"
        :closeable="!submitting"
        :title="t('notes.markdown.tags.manage.title')"
        :icon="Tag"
        v-on:close="close"
    >
        <div class="space-y-3">
            <AppSearchInput
                v-model="query"
                :placeholder="t('notes.markdown.tags.manage.search_placeholder')"
            />

            <div
                v-if="filteredTags.length > 0"
                class="border border-line rounded-md divide-y divide-line max-h-96 overflow-auto"
            >
                <div
                    v-for="entry in filteredTags"
                    :key="entry.tag"
                    class="flex items-center gap-2 px-3 py-2 hover:bg-surface-2"
                >
                    <AppCheckbox
                        :model-value="isSelected(entry.tag)"
                        :disabled="submitting"
                        v-on:update:model-value="toggleSelected(entry.tag)"
                    />

                    <template v-if="renaming && renaming.source === entry.tag">
                        <AppInput
                            v-model="renaming.draft"
                            :placeholder="t('notes.markdown.tags.manage.new_name_placeholder')"
                            class="flex-1"
                            v-on:keydown.enter="confirmRename"
                            v-on:keydown.esc="cancelRename"
                        />
                        <div class="flex gap-0.5 shrink-0">
                            <AppIconButton
                                color="accent"
                                :title="t('notes.markdown.tags.manage.rename_confirm')"
                                :disabled="submitting"
                                v-on:click="confirmRename"
                            >
                                <Save class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton
                                :title="t('notes.markdown.cancel')"
                                :disabled="submitting"
                                v-on:click="cancelRename"
                            >
                                <X class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                        </div>
                    </template>

                    <template v-else-if="pendingDelete === entry.tag">
                        <span class="flex-1 text-sm text-rose-400">
                            {{ t('notes.markdown.tags.manage.delete_confirm', { tag: entry.tag, count: entry.count }, entry.count) }}
                        </span>
                        <div class="flex gap-0.5 shrink-0">
                            <AppIconButton
                                color="rose"
                                :title="t('notes.markdown.tags.manage.delete_yes')"
                                :disabled="submitting"
                                v-on:click="confirmDelete"
                            >
                                <Check class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton
                                :title="t('notes.markdown.cancel')"
                                :disabled="submitting"
                                v-on:click="cancelDelete"
                            >
                                <X class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                        </div>
                    </template>

                    <template v-else>
                        <span class="flex-1 text-sm text-primary truncate">{{ entry.tag }}</span>
                        <span class="text-xs text-muted shrink-0">
                            {{ t('notes.markdown.tags.manage.note_count', { count: entry.count }, entry.count) }}
                        </span>
                        <div class="flex gap-0.5 shrink-0">
                            <AppIconButton
                                color="accent"
                                :title="t('notes.markdown.tags.manage.rename')"
                                v-on:click="beginRename(entry)"
                            >
                                <Pencil class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton
                                color="rose"
                                :title="t('notes.markdown.tags.manage.delete')"
                                v-on:click="beginDelete(entry)"
                            >
                                <Trash2 class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                        </div>
                    </template>
                </div>
            </div>

            <AppNoData
                v-else-if="!loading"
                :title="query.trim() !== '' ? t('notes.markdown.tags.manage.search_empty') : t('notes.markdown.tags.manage.empty_title')"
                :description="query.trim() !== '' ? t('notes.markdown.tags.manage.search_empty_description', { query }) : t('notes.markdown.tags.manage.empty_description')"
                :icon="Tag"
            />

            <div
                v-if="selectedTags.length >= 2"
                class="rounded-md border border-accent-600/30 bg-accent-600/10 p-3 space-y-2"
            >
                <p class="text-sm text-primary">
                    {{ t('notes.markdown.tags.manage.merge_prompt', { count: selectedTags.length }, selectedTags.length) }}
                </p>
                <p class="text-xs text-muted">
                    {{ selectedTags.join(', ') }}
                </p>
                <div class="flex items-center gap-2">
                    <AppInput
                        v-model="mergeTarget"
                        :placeholder="t('notes.markdown.tags.manage.merge_target_placeholder')"
                        class="flex-1"
                        v-on:keydown.enter="confirmMerge"
                    />
                    <AppButton
                        variant="primary"
                        size="md"
                        :disabled="submitting || mergeTarget.trim() === ''"
                        :loading="submitting"
                        v-on:click="confirmMerge"
                    >
                        <Combine class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t('notes.markdown.tags.manage.merge_confirm') }}
                    </AppButton>
                </div>
            </div>
        </div>

        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" :disabled="submitting" v-on:click="close">
                    <X class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t('notes.markdown.tags.manage.close') }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
