<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Save, RotateCcw, EyeOff, Eye } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import SectionColorPicker from "@general/backend/profile/preferences/components/SectionColorPicker.vue";
import { useSidemenuPreferences } from "@general/backend/profile/composables/useSidemenuPreferences.js";

const { t } = useI18n();

const props = defineProps({
    navPreferences: { type: Array, default: () => [] },
    sectionAliases: { type: Object, default: () => ({}) },
    itemAliases: { type: Object, default: () => ({}) },
    hiddenNavSections: { type: Array, default: () => [] },
    hiddenNavItems: { type: Array, default: () => [] },
    navSectionColors: { type: Object, default: () => ({}) },
    savePath: { type: String, required: true },
    resetPath: { type: String, required: true },
});

const prefs = useSidemenuPreferences({
    navPreferences: props.navPreferences,
    sectionAliases: props.sectionAliases,
    itemAliases: props.itemAliases,
    initialHiddenSections: props.hiddenNavSections,
    initialHiddenItems: props.hiddenNavItems,
    initialSectionColors: props.navSectionColors,
    savePath: props.savePath,
    resetPath: props.resetPath,
});

const totalCustomisations = computed(() => prefs.hiddenCount.value + prefs.customColorCount.value);
</script>

<template>
    <div>
        <header class="mb-4 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 sm:gap-4">
            <p class="text-sm text-secondary min-w-0">
                {{ t('backend.profile.sidemenu.subtitle') }}
            </p>
            <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto sm:shrink-0">
                <AppButton
                    variant="ghost"
                    size="md"
                    class="w-full sm:w-auto"
                    :loading="prefs.resetting.value"
                    :disabled="totalCustomisations === 0"
                    v-on:click="prefs.reset"
                >
                    <RotateCcw class="w-4 h-4" :stroke-width="2" />
                    {{ t('backend.profile.sidemenu.reset') }}
                </AppButton>
                <AppButton
                    variant="primary"
                    size="md"
                    class="w-full sm:w-auto"
                    :loading="prefs.saving.value"
                    v-on:click="prefs.save"
                >
                    <Save class="w-4 h-4" :stroke-width="2" />
                    {{ t('backend.profile.sidemenu.save') }}
                </AppButton>
            </div>
        </header>

        <AppSearchInput
            v-model="prefs.search.value"
            :placeholder="t('backend.profile.sidemenu.search_placeholder')"
            class="mb-4"
        />

        <AppNoData
            v-if="!prefs.filteredSections.value.length"
            :message="prefs.search.value ? t('backend.profile.sidemenu.empty_search') : t('backend.profile.sidemenu.empty')"
        />

        <div v-else class="space-y-3">
            <div
                v-for="section in prefs.filteredSections.value"
                :key="section.id"
                class="aurora-card overflow-hidden"
                :class="{ 'opacity-60': prefs.isSectionHidden(section.id) }"
            >
                <div class="flex flex-col gap-2 px-3 sm:px-4 py-3 bg-surface-alt/40">
                    <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3">
                        <div class="flex items-center gap-3 flex-1 min-w-0">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-primary truncate">{{ section.label }}</p>
                                <p class="text-xs text-muted mt-0.5 hidden sm:block">
                                    {{ t('backend.profile.sidemenu.section_hint') }}
                                </p>
                            </div>
                            <AppToggle
                                class="sm:hidden shrink-0"
                                :model-value="!prefs.isSectionHidden(section.id)"
                                v-on:update:model-value="prefs.toggleSection(section.id)"
                            />
                        </div>
                        <div class="flex items-center gap-2 sm:shrink-0 flex-wrap">
                            <AppButton
                                variant="ghost"
                                size="sm"
                                :title="t('backend.profile.sidemenu.hide_all')"
                                v-on:click="prefs.hideAllInSection(section)"
                            >
                                <EyeOff class="w-3.5 h-3.5" :stroke-width="2" />
                                <span class="hidden sm:inline">{{ t('backend.profile.sidemenu.hide_all') }}</span>
                            </AppButton>
                            <AppButton
                                variant="ghost"
                                size="sm"
                                :title="t('backend.profile.sidemenu.show_all')"
                                v-on:click="prefs.showAllInSection(section)"
                            >
                                <Eye class="w-3.5 h-3.5" :stroke-width="2" />
                                <span class="hidden sm:inline">{{ t('backend.profile.sidemenu.show_all') }}</span>
                            </AppButton>
                            <AppToggle
                                class="hidden sm:inline-flex"
                                :model-value="!prefs.isSectionHidden(section.id)"
                                v-on:update:model-value="prefs.toggleSection(section.id)"
                            />
                        </div>
                    </div>
                    <div class="flex flex-col sm:flex-row sm:items-center gap-2 pt-2 border-t border-line/40">
                        <span class="text-xs uppercase tracking-wider text-muted shrink-0">{{ t('backend.profile.sidemenu.color_label') }}</span>
                        <SectionColorPicker
                            :model-value="prefs.getSectionColor(section.id)"
                            v-on:update:model-value="(color) => prefs.setSectionColor(section.id, color)"
                        />
                    </div>
                </div>
                <div class="divide-y divide-line/40">
                    <div
                        v-for="item in section.items"
                        :key="item.key"
                        class="flex items-center gap-3 pl-4 sm:pl-6 pr-3 sm:pr-4 py-2.5 hover:bg-surface-2 transition-colors"
                        :class="{ 'opacity-60': prefs.isItemHidden(item.key) || prefs.isSectionHidden(section.id) }"
                    >
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-primary truncate">{{ prefs.resolveItemLabel(item) }}</p>
                            <p v-if="item.descriptionKey" class="text-xs text-muted mt-0.5 line-clamp-2">
                                {{ t(item.descriptionKey) }}
                            </p>
                        </div>
                        <AppToggle
                            :model-value="!prefs.isItemHidden(item.key)"
                            :disabled="prefs.isSectionHidden(section.id)"
                            v-on:update:model-value="prefs.toggleItem(item.key)"
                        />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
