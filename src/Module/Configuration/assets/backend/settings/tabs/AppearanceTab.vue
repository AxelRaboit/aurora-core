<script setup>
import { useI18n } from "vue-i18n";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppColorSwatch from "@/shared/components/form/picker/AppColorSwatch.vue";
import AppColorField from "@/shared/components/form/picker/AppColorField.vue";
import AppTextLinkButton from "@/shared/components/action/AppTextLinkButton.vue";
import { Save, Plus, X, RotateCcw } from "lucide-vue-next";
import { useColorPickerPresets } from "@configuration/backend/settings/composables/useColorPickerPresets.js";

const props = defineProps({
    groups: { type: Object, default: () => ({}) },
    updatePath: { type: String, default: "" },
});

const { t } = useI18n();

const colorPresets = useColorPickerPresets({ groups: props.groups, updatePath: props.updatePath });
</script>

<template>
    <div class="bg-surface border border-line rounded-xl p-4 sm:p-6 space-y-6">
        <div>
            <h3 class="text-sm font-semibold text-primary">{{ t('backend.settings.appearance.color_presets.title') }}</h3>
            <p class="text-xs text-muted mt-1">{{ t('backend.settings.appearance.color_presets.help') }}</p>
        </div>

        <div class="flex flex-wrap gap-2">
            <div
                v-for="color in colorPresets.presets.value"
                :key="color"
                class="relative group"
            >
                <AppColorSwatch :model-value="color" size="md" :disabled="true" />
                <button
                    type="button"
                    class="absolute -top-1 -right-1 w-5 h-5 rounded-full bg-surface border border-line text-muted hover:text-danger hover:border-danger flex items-center justify-center opacity-0 group-hover:opacity-100 transition shadow-sm"
                    :title="t('backend.settings.appearance.color_presets.remove')"
                    v-on:click="colorPresets.remove(color)"
                >
                    <X class="w-3 h-3" :stroke-width="2.5" />
                </button>
            </div>
        </div>

        <div>
            <div v-if="colorPresets.showAddForm.value" class="border border-line rounded-lg p-4 bg-surface-2 space-y-3">
                <AppColorField
                    v-model="colorPresets.newColor.value"
                    :label="t('backend.settings.appearance.color_presets.add')"
                    :show-hex="true"
                    size="md"
                />
                <div class="flex gap-2 justify-end">
                    <AppTextLinkButton color="muted" size="sm" v-on:click="colorPresets.cancelAdd">
                        {{ t('shared.common.cancel') }}
                    </AppTextLinkButton>
                    <AppButton variant="primary" size="sm" v-on:click="colorPresets.add">
                        {{ t('backend.settings.appearance.color_presets.confirm_add') }}
                    </AppButton>
                </div>
            </div>
            <div v-else class="flex flex-wrap gap-2">
                <AppButton variant="secondary" size="sm" v-on:click="colorPresets.openAddForm">
                    <Plus class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t('backend.settings.appearance.color_presets.add') }}
                </AppButton>
                <AppButton variant="ghost" size="sm" v-on:click="colorPresets.reset">
                    <RotateCcw class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t('backend.settings.appearance.color_presets.reset') }}
                </AppButton>
            </div>
        </div>

        <div class="pt-2 border-t border-line flex justify-end *:w-full sm:*:w-auto">
            <AppButton
                variant="primary"
                size="md"
                :loading="colorPresets.saving.value"
                :disabled="!colorPresets.canSave.value"
                v-on:click="colorPresets.save"
            >
                <Save class="w-3.5 h-3.5" :stroke-width="2" />
                {{ t('backend.settings.save') }}
            </AppButton>
        </div>
    </div>
</template>
