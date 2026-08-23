<script setup>
import { useI18n } from "vue-i18n";
import { VueDraggable } from "vue-draggable-plus";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import {
    Save,
    X,
    RotateCcw,
    ChevronDown,
    ChevronRight,
    GripVertical,
} from "lucide-vue-next";
import { useNavAliases } from "../composables/useNavAliases.js";

const props = defineProps({
    groups: { type: Object, default: () => ({}) },
    updatePath: { type: String, default: "" },
    navSections: { type: Array, default: () => [] },
});

const { t } = useI18n();

// Destructure the composable so its refs / computeds end up as top-level
// bindings of the setup return - that's the ONLY shape Vue 3 templates
// auto-unwrap. Accessing them via `navAliases.<x>` from a plain object
// passes the ref *object*, not the value, which breaks every truthy check
// (the save spinner stays on forever) and every iteration (VueDraggable
// sees a ComputedRef and renders nothing).
const {
    sectionAliases,
    itemAliases,
    orderedSections,
    orderedItems,
    saving,
    toggleSection,
    isSectionExpanded,
    sectionDefaultLabel,
    sectionLabel,
    itemDefaultLabel,
    resetItem,
    resetSection,
    resetAll,
    applySectionOrder,
    applyItemOrder,
    saveAll,
} = useNavAliases({
    groups: props.groups,
    navSections: props.navSections,
    updatePath: props.updatePath,
});
</script>

<template>
    <div class="space-y-6">
        <p class="text-sm text-secondary">{{ t('backend.settings.tabs.navigation_description') }}</p>

        <div class="bg-surface border border-line rounded-xl p-4 sm:p-6 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-primary">{{ t('backend.settings.nav_aliases.title') }}</h3>
                    <p class="text-xs text-muted mt-1">{{ t('backend.settings.nav_aliases.help') }}</p>
                </div>
                <AppButton variant="ghost" size="sm" class="self-start sm:self-auto shrink-0" v-on:click="resetAll">
                    <RotateCcw class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t('backend.settings.nav_aliases.reset_all') }}
                </AppButton>
            </div>

            <div v-if="!navSections.length" class="text-sm text-muted">{{ t('backend.settings.nav_aliases_empty') }}</div>
            <VueDraggable
                v-else
                :model-value="orderedSections"
                handle=".section-drag-handle"
                :animation="150"
                class="space-y-2"
                v-on:update:model-value="applySectionOrder"
            >
                <div
                    v-for="section in orderedSections"
                    :key="section.id"
                    class="border border-line rounded-lg overflow-hidden"
                >
                    <div class="flex flex-col sm:flex-row sm:items-center gap-2 px-3 py-2 bg-surface-2">
                        <div class="flex items-center gap-2 sm:w-52 sm:shrink-0">
                            <button
                                type="button"
                                class="section-drag-handle flex items-center justify-center text-muted hover:text-primary cursor-grab active:cursor-grabbing shrink-0 w-5 h-5"
                                :title="t('backend.settings.nav_aliases.drag_handle')"
                            >
                                <GripVertical class="w-3.5 h-3.5" :stroke-width="2" />
                            </button>
                            <button
                                type="button"
                                class="flex items-center justify-center text-muted hover:text-primary transition-colors shrink-0 w-5 h-5"
                                :aria-expanded="isSectionExpanded(section.id)"
                                v-on:click="toggleSection(section.id)"
                            >
                                <component
                                    :is="isSectionExpanded(section.id) ? ChevronDown : ChevronRight"
                                    class="w-3.5 h-3.5"
                                    :stroke-width="2"
                                />
                            </button>
                            <span class="text-xs text-secondary truncate flex-1" :title="sectionDefaultLabel(section)">
                                {{ sectionDefaultLabel(section) }}
                            </span>
                            <span class="text-xs text-muted sm:hidden shrink-0">{{ section.items?.length ?? 0 }}</span>
                        </div>
                        <div class="flex items-center gap-2 flex-1 min-w-0">
                            <AppInput
                                v-model="sectionAliases[section.id]"
                                :placeholder="sectionDefaultLabel(section)"
                                class="flex-1"
                            />
                            <AppButton
                                variant="ghost"
                                size="sm"
                                :disabled="!sectionAliases[section.id]"
                                :title="t('backend.settings.nav_aliases.reset_item')"
                                v-on:click="resetSection(section.id)"
                            >
                                <X class="w-3.5 h-3.5" :stroke-width="2" />
                            </AppButton>
                        </div>
                        <span class="hidden sm:inline text-xs text-muted w-8 text-right shrink-0">{{ section.items?.length ?? 0 }}</span>
                    </div>
                    <div v-show="isSectionExpanded(section.id)" class="p-3 space-y-2">
                        <div v-if="!section.items?.length" class="text-xs text-muted italic">
                            {{ t('backend.settings.nav_aliases.items_empty') }}
                        </div>
                        <VueDraggable
                            v-else
                            :model-value="orderedItems(section)"
                            :handle="`.item-drag-handle-${section.id}`"
                            :animation="150"
                            class="space-y-2"
                            v-on:update:model-value="(items) => applyItemOrder(section.id, items)"
                        >
                            <div
                                v-for="item in orderedItems(section)"
                                :key="item.route ?? item.key"
                                class="flex flex-col sm:flex-row sm:items-center gap-2"
                            >
                                <div class="flex items-center gap-2 sm:w-48 sm:shrink-0">
                                    <button
                                        type="button"
                                        :class="['flex items-center justify-center text-muted hover:text-primary cursor-grab active:cursor-grabbing shrink-0 w-5 h-5', `item-drag-handle-${section.id}`]"
                                        :title="t('backend.settings.nav_aliases.drag_handle')"
                                    >
                                        <GripVertical class="w-3.5 h-3.5" :stroke-width="2" />
                                    </button>
                                    <span class="text-xs text-secondary truncate flex-1" :title="itemDefaultLabel(item)">
                                        {{ itemDefaultLabel(item) }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-2 flex-1 min-w-0">
                                    <AppInput
                                        v-model="itemAliases[item.route ?? item.key]"
                                        :placeholder="itemDefaultLabel(item)"
                                        class="flex-1"
                                    />
                                    <AppButton
                                        variant="ghost"
                                        size="sm"
                                        :disabled="!itemAliases[item.route ?? item.key]"
                                        :title="t('backend.settings.nav_aliases.reset_item')"
                                        v-on:click="resetItem(item.route ?? item.key)"
                                    >
                                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                                    </AppButton>
                                </div>
                            </div>
                        </VueDraggable>
                    </div>
                </div>
            </VueDraggable>

            <div class="pt-2 border-t border-line flex justify-end">
                <AppButton variant="primary" size="md" :loading="saving" v-on:click="saveAll">
                    <Save class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t('backend.settings.nav_aliases.save') }}
                </AppButton>
            </div>
        </div>
    </div>
</template>
