<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import AppTooltip from "@/shared/components/overlay/AppTooltip.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppMultiselect from "@/shared/components/form/select/AppMultiselect.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import AppImagePickerField from "@/shared/components/form/file/AppImagePickerField.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppPagination from "@/shared/components/nav/AppPagination.vue";
import AppListItemButton from "@/shared/components/action/AppListItemButton.vue";
import AppTextLinkButton from "@/shared/components/action/AppTextLinkButton.vue";
import { Search, FileText, Lock, Save, Code2 } from "lucide-vue-next";
import { ParameterType } from "@core/utils/enums/settings/parameterType.js";
import { useSettingsForm } from "@configuration/backend/settings/composables/useSettingsForm.js";
import { useSettingsPostPicker } from "@configuration/backend/settings/composables/useSettingsPostPicker.js";
import { useSettingsSequenceFilter } from "@configuration/backend/settings/composables/useSettingsSequenceFilter.js";
import { useSettingsTabs } from "@configuration/backend/settings/composables/useSettingsTabs.js";
import { getSettingsTabComponent } from "@configuration/backend/settings/tabRegistry.js";

const props = defineProps({
    groups: { type: Object, default: () => ({}) },
    tabs: { type: Array, default: () => [] },
    updatePath: { type: String, default: "" },
    postSearchPath: { type: String, default: "" },
    navSections: { type: Array, default: () => [] },
});

const { t } = useI18n();

const { availableGroups, activeTab, selectTab, tabLabel, tabDescription } = useSettingsTabs(props.groups, props.tabs);

// Resolve `componentName` → Vue component for each visible tab. Tabs whose
// componentName is null/unknown fall back to the generic field renderer.
const customComponentByGroup = computed(() => {
    const map = {};
    for (const tab of props.tabs) {
        if (!availableGroups.includes(tab.id)) continue;
        const component = getSettingsTabComponent(tab.componentName);
        if (component) {
            map[tab.id] = component;
        }
    }
    return map;
});

const genericGroups = computed(() =>
    availableGroups.filter((groupName) => !(groupName in customComponentByGroup.value)),
);

const { fieldValues, mediaState, isLocked, lockReason, onBoolChange, onMediaChange, savingGroups, saveGroup } =
    useSettingsForm(props.groups, genericGroups.value, props.updatePath);

const { postPickerLabels, postPickerSearch, postPickerResults, postPickerOpen, resolvePostLabel, searchPosts, selectPost, clearPost, onPostPickerBlur, onPostPickerFocus } =
    useSettingsPostPicker(props.groups, genericGroups.value, fieldValues, props.postSearchPath);

const { sequenceSearch, paginatedSequences, sequencePage, sequenceTotalPages, goToSequencePage } =
    useSettingsSequenceFilter(props.groups);

const tabMeta = Object.fromEntries(props.tabs.map((t) => [t.id, t]));
const isDevOnly = (id) => tabMeta[id]?.devOnly ?? false;
</script>

<template>
    <div class="flex flex-col md:flex-row gap-6">
        <nav class="hidden md:flex flex-col w-44 shrink-0 gap-0.5">
            <AppTooltip
                v-for="groupName in availableGroups"
                :key="groupName"
                :title="tabLabel(groupName)"
                :description="tabDescription(groupName)"
                placement="right"
            >
                <AppTab
                    :active="activeTab === groupName"
                    :color="isDevOnly(groupName) ? 'rose' : 'accent'"
                    v-on:click="selectTab(groupName)"
                >
                    <span class="flex items-center gap-1.5">
                        {{ tabLabel(groupName) }}
                        <Code2 v-if="isDevOnly(groupName)" class="w-3 h-3 shrink-0" :stroke-width="2" />
                    </span>
                </AppTab>
            </AppTooltip>
        </nav>

        <div class="flex md:hidden gap-1 flex-wrap mb-4 w-full">
            <AppTooltip
                v-for="groupName in availableGroups"
                :key="groupName"
                :title="tabLabel(groupName)"
                :description="tabDescription(groupName)"
                placement="bottom"
            >
                <AppTab
                    :active="activeTab === groupName"
                    :color="isDevOnly(groupName) ? 'rose' : 'accent'"
                    size="sm"
                    v-on:click="selectTab(groupName)"
                >
                    <span class="flex items-center gap-1.5">
                        {{ tabLabel(groupName) }}
                        <Code2 v-if="isDevOnly(groupName)" class="w-3 h-3 shrink-0" :stroke-width="2" />
                    </span>
                </AppTab>
            </AppTooltip>
        </div>

        <div class="flex-1 min-w-0">
            <!-- Tabs whose body is owned by a registered Vue component -->
            <component
                :is="component"
                v-for="(component, groupName) in customComponentByGroup"
                v-show="activeTab === groupName"
                :key="groupName"
                :groups="groups"
                :update-path="updatePath"
                :nav-sections="navSections"
                :post-search-path="postSearchPath"
            />

            <!-- Generic field renderer for parameter-driven tabs -->
            <div
                v-for="groupName in genericGroups"
                v-show="activeTab === groupName"
                :key="groupName"
            >
                <div class="bg-surface border border-line rounded-xl p-6 space-y-6">
                    <AppSearchInput
                        v-if="groupName === 'sequences'"
                        v-model="sequenceSearch"
                        :placeholder="t('backend.settings.sequence_search')"
                    />

                    <div
                        v-for="parameter in (groupName === 'sequences' ? paginatedSequences : groups[groupName])"
                        :key="parameter.key"
                    >
                        <template v-if="parameter.type === ParameterType.Bool">
                            <div class="flex items-center justify-between gap-4" :class="{ 'opacity-60': isLocked(parameter) }">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-primary flex items-center gap-1.5">
                                        {{ parameter.label }}
                                        <Lock v-if="isLocked(parameter)" class="w-3.5 h-3.5 text-muted" :stroke-width="2" />
                                    </p>
                                    <p v-if="parameter.description" class="text-xs text-muted mt-0.5">{{ parameter.description }}</p>
                                    <p v-if="isLocked(parameter)" class="text-xs text-warning mt-0.5">{{ lockReason(parameter) }}</p>
                                </div>
                                <AppToggle
                                    :model-value="!isLocked(parameter) && fieldValues[parameter.key] === '1'"
                                    :disabled="isLocked(parameter)"
                                    v-on:update:model-value="onBoolChange(parameter, $event)"
                                />
                            </div>
                        </template>

                        <template v-else-if="parameter.type === ParameterType.Post">
                            <p class="text-sm font-medium text-primary mb-1">{{ parameter.label }}</p>
                            <p v-if="parameter.description" class="text-xs text-muted mb-2">{{ parameter.description }}</p>
                            <div v-if="postPickerLabels[parameter.key]" class="flex items-center gap-3 p-3 border border-line rounded-lg bg-surface-2 mb-2">
                                <FileText class="w-4 h-4 shrink-0 text-accent" :stroke-width="2" />
                                <span class="flex-1 text-sm font-medium text-primary truncate">{{ postPickerLabels[parameter.key].title }}</span>
                                <span class="text-xs text-muted shrink-0">#{{ postPickerLabels[parameter.key].id }}</span>
                                <AppTextLinkButton color="danger" size="xs" class="shrink-0" v-on:click="clearPost(parameter.key)">
                                    {{ t("shared.common.remove") }}
                                </AppTextLinkButton>
                            </div>
                            <div v-else class="flex items-center gap-1.5 text-sm text-muted italic mb-2">
                                <FileText class="w-3.5 h-3.5 opacity-60" :stroke-width="1.5" />
                                {{ t("backend.settings.no_page_selected") }}
                            </div>
                            <div class="relative">
                                <AppInput
                                    type="text"
                                    :placeholder="t('backend.settings.search_post')"
                                    :model-value="postPickerSearch[parameter.key] ?? ''"
                                    v-on:update:model-value="postPickerSearch[parameter.key] = $event; searchPosts(parameter.key, $event)"
                                    v-on:blur="onPostPickerBlur(parameter.key)"
                                    v-on:focus="onPostPickerFocus(parameter.key)"
                                >
                                    <template #prefix>
                                        <Search class="w-3.5 h-3.5" :stroke-width="2" />
                                    </template>
                                </AppInput>
                                <div v-if="postPickerOpen[parameter.key] && postPickerResults[parameter.key]?.length" class="absolute z-20 left-0 right-0 mt-1 border border-line rounded-lg bg-surface shadow-lg overflow-hidden">
                                    <AppListItemButton
                                        v-for="post in postPickerResults[parameter.key]"
                                        :key="post.id"
                                        class="justify-between border-b border-line last:border-0"
                                        v-on:click="selectPost(parameter.key, post)"
                                    >
                                        <span class="font-medium text-primary truncate">{{ post.title ?? "-" }}</span>
                                        <span class="text-xs text-muted shrink-0">{{ post.postType }}</span>
                                    </AppListItemButton>
                                </div>
                            </div>
                            <div class="mt-2 flex items-center gap-2">
                                <span class="text-xs text-muted shrink-0">{{ t("backend.settings.or_id") }}</span>
                                <div class="w-28">
                                    <AppInput
                                        type="number"
                                        :placeholder="'ID'"
                                        :model-value="fieldValues[parameter.key]"
                                        v-on:update:model-value="fieldValues[parameter.key] = $event; resolvePostLabel(parameter.key)"
                                    />
                                </div>
                            </div>
                        </template>

                        <template v-else-if="parameter.type === ParameterType.Media">
                            <AppImagePickerField
                                :label="parameter.label"
                                :hint="parameter.description ? parameter.description + ' - ' + t('backend.settings.media_square_hint') : t('backend.settings.media_square_hint')"
                                :model-value="mediaState[parameter.key]"
                                :size="96"
                                v-on:update:model-value="onMediaChange(parameter, $event)"
                            />
                        </template>

                        <template v-else-if="parameter.type === ParameterType.Int">
                            <AppInput
                                type="number"
                                :label="parameter.label"
                                :placeholder="parameter.placeholder ?? ''"
                                :model-value="fieldValues[parameter.key]"
                                v-on:update:model-value="fieldValues[parameter.key] = $event"
                            />
                            <p v-if="parameter.description" class="text-xs text-muted mt-1">{{ parameter.description }}</p>
                        </template>

                        <template v-else-if="parameter.type === ParameterType.Select">
                            <AppMultiselect
                                v-if="(parameter.options ?? []).length > 10"
                                :label="parameter.label"
                                :options="parameter.options ?? []"
                                :model-value="fieldValues[parameter.key]"
                                v-on:update:model-value="fieldValues[parameter.key] = $event"
                            />
                            <AppSelect
                                v-else
                                :label="parameter.label"
                                :options="parameter.options ?? []"
                                :model-value="fieldValues[parameter.key]"
                                v-on:update:model-value="fieldValues[parameter.key] = $event"
                            />
                            <p v-if="parameter.description" class="text-xs text-muted mt-1">{{ parameter.description }}</p>
                        </template>

                        <template v-else-if="parameter.type === ParameterType.Textarea">
                            <label class="block text-sm font-medium text-secondary mb-1">{{ parameter.label }}</label>
                            <textarea
                                :placeholder="parameter.placeholder ?? ''"
                                :value="fieldValues[parameter.key]"
                                rows="6"
                                class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-primary resize-y focus:border-accent-500 focus:ring-1 focus:ring-accent-500 transition"
                                v-on:input="fieldValues[parameter.key] = $event.target.value"
                            />
                            <p v-if="parameter.description" class="text-xs text-muted mt-1">{{ parameter.description }}</p>
                        </template>

                        <template v-else>
                            <AppInput
                                type="text"
                                :label="parameter.label"
                                :placeholder="parameter.placeholder ?? ''"
                                :model-value="fieldValues[parameter.key]"
                                v-on:update:model-value="fieldValues[parameter.key] = $event"
                            />
                            <p v-if="parameter.description" class="text-xs text-muted mt-1">{{ parameter.description }}</p>
                        </template>
                    </div>

                    <AppPagination
                        v-if="groupName === 'sequences' && sequenceTotalPages > 1"
                        :page="sequencePage"
                        :total-pages="sequenceTotalPages"
                        v-on:change="goToSequencePage"
                    />

                    <div class="pt-2 border-t border-line flex justify-end">
                        <AppButton
                            type="button"
                            variant="primary"
                            size="md"
                            :loading="savingGroups[groupName]"
                            v-on:click="saveGroup(groupName)"
                        >
                            <Save class="w-3.5 h-3.5" :stroke-width="2" />
                            {{ t("backend.settings.save") }}
                        </AppButton>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
