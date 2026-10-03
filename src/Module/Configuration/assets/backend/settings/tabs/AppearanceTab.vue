<script setup>
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { useI18n } from "vue-i18n";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppColorSwatch from "@/shared/components/form/picker/AppColorSwatch.vue";
import AppColorField from "@/shared/components/form/picker/AppColorField.vue";
import AppTextLinkButton from "@/shared/components/action/AppTextLinkButton.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import { Save, Plus, X, RotateCcw, Sun, Moon } from "lucide-vue-next";
import { useColorPickerPresets } from "@configuration/backend/settings/composables/useColorPickerPresets.js";
import { useBackendPalette, PALETTE_MODES } from "@configuration/backend/settings/composables/useBackendPalette.js";

const props = defineProps({
    groups: { type: Object, default: () => ({}) },
    updatePath: { type: String, default: "" },
});

const { t } = useI18n();

const colorPresets = useColorPickerPresets({ groups: props.groups, updatePath: props.updatePath });
const backendPalette = useBackendPalette({ updatePath: props.updatePath });

const familyOptions = backendPalette.families.map((family) => ({
    value: family,
    label: t(`backend.settings.appearance.palette.families.${family}`),
}));
const SCALE_STEPS = ["100", "300", "500", "700", "900"];
const tokenGroups = [
    { key: "tones", tokens: backendPalette.tokens },
    { key: "states", tokens: backendPalette.states },
];
</script>

<template>
    <div class="aurora-stack">
        <div class="aurora-card p-4 space-y-5">
            <AppGuide :title="t('backend.settings.appearance.palette.guide.title')" storage-key="settings-appearance-palette">
                <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                    <li v-for="step in 4" :key="step">{{ t(`backend.settings.appearance.palette.guide.step_${step}`) }}</li>
                </ol>
            </AppGuide>
            <div>
                <h3 class="text-sm font-semibold text-primary">{{ t('backend.settings.appearance.palette.title') }}</h3>
                <p class="text-xs text-muted mt-1">{{ t('backend.settings.appearance.palette.help') }}</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <section v-for="mode in PALETTE_MODES" :key="mode" class="min-w-0 space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <span class="flex items-center gap-1.5 text-xs text-secondary uppercase tracking-wide font-semibold">
                            <Sun v-if="mode === 'light'" class="w-3.5 h-3.5" :stroke-width="2" />
                            <Moon v-else class="w-3.5 h-3.5" :stroke-width="2" />
                            {{ t(`backend.settings.appearance.palette.modes.${mode}`) }}
                        </span>
                        <AppTextLinkButton
                            v-if="!backendPalette.isDefault(mode)"
                            color="muted"
                            size="xs"
                            v-on:click="backendPalette.resetMode(mode)"
                        >
                            {{ t('backend.settings.appearance.palette.reset_mode') }}
                        </AppTextLinkButton>
                    </div>

                    <AppSelect
                        v-model="backendPalette.palette[mode].family"
                        :label="t('backend.settings.appearance.palette.family')"
                        :options="familyOptions"
                    />
                    <div class="flex h-2 rounded-full overflow-hidden border border-line" aria-hidden="true">
                        <span
                            v-for="step in SCALE_STEPS"
                            :key="step"
                            class="flex-1"
                            :style="{ backgroundColor: backendPalette.familyScale(backendPalette.palette[mode].family)[step] }"
                        />
                    </div>

                    <!-- Aperçu peint avec les couleurs du mode, quel que soit
                     celui dans lequel on regarde l'écran. -->
                    <div
                        class="rounded-lg border p-3 space-y-2"
                        :style="{ backgroundColor: backendPalette.colorOf(mode, 'bg'), borderColor: backendPalette.colorOf(mode, 'line') }"
                        aria-hidden="true"
                    >
                        <div
                            class="rounded-md border p-3 space-y-1"
                            :style="{ backgroundColor: backendPalette.colorOf(mode, 'surface'), borderColor: backendPalette.colorOf(mode, 'line') }"
                        >
                            <p class="text-sm font-semibold" :style="{ color: backendPalette.colorOf(mode, 'primary') }">
                                {{ t('backend.settings.appearance.palette.preview.title') }}
                            </p>
                            <p class="text-xs" :style="{ color: backendPalette.colorOf(mode, 'secondary') }">
                                {{ t('backend.settings.appearance.palette.preview.body') }}
                            </p>
                            <p class="text-xs" :style="{ color: backendPalette.colorOf(mode, 'muted') }">
                                {{ t('backend.settings.appearance.palette.preview.meta') }}
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <span
                                class="flex-1 rounded-md border px-2 py-1 text-xs"
                                :style="{ backgroundColor: backendPalette.colorOf(mode, 'surface_2'), borderColor: backendPalette.colorOf(mode, 'line_strong'), color: backendPalette.colorOf(mode, 'secondary') }"
                            >{{ t('backend.settings.appearance.palette.preview.field') }}</span>
                            <span
                                class="rounded-md px-2 py-1 text-xs"
                                :style="{ backgroundColor: backendPalette.colorOf(mode, 'surface_3'), color: backendPalette.colorOf(mode, 'subtle') }"
                            >{{ t('backend.settings.appearance.palette.preview.chip') }}</span>
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            <span
                                v-for="state in backendPalette.states"
                                :key="state"
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :style="{ color: backendPalette.colorOf(mode, state), backgroundColor: `${backendPalette.colorOf(mode, state)}${mode === 'dark' ? '26' : '1a'}` }"
                            >{{ t(`backend.settings.appearance.palette.tokens.${state}`) }}</span>
                        </div>
                    </div>

                    <div v-for="group in tokenGroups" :key="group.key" class="space-y-2">
                        <span class="block text-xs text-secondary font-semibold">{{ t(`backend.settings.appearance.palette.groups.${group.key}`) }}</span>
                        <div
                            v-for="token in group.tokens"
                            :key="token"
                            class="flex items-center gap-3 bg-surface-2 rounded-lg px-3 py-2"
                        >
                            <AppColorSwatch
                                :model-value="backendPalette.colorOf(mode, token)"
                                size="sm"
                                v-on:update:model-value="backendPalette.setColor(mode, token, $event)"
                            />
                            <div class="flex flex-col min-w-0 flex-1">
                                <span class="text-xs font-medium text-primary">{{ t(`backend.settings.appearance.palette.tokens.${token}`) }}</span>
                                <span class="text-xs text-muted truncate">
                                    {{ backendPalette.isOverridden(mode, token) ? t('backend.settings.appearance.palette.overridden') : t(`backend.settings.appearance.palette.unchanged.${group.key}`) }}
                                </span>
                            </div>
                            <span class="text-xs font-mono text-muted">{{ backendPalette.colorOf(mode, token) }}</span>
                            <AppTextLinkButton
                                v-if="backendPalette.isOverridden(mode, token)"
                                color="muted"
                                size="xs"
                                :title="t(`backend.settings.appearance.palette.reset_color.${group.key}`)"
                                v-on:click="backendPalette.resetColor(mode, token)"
                            >
                                ↺
                            </AppTextLinkButton>
                        </div>
                    </div>
                </section>
            </div>

            <div class="pt-2 border-t border-line flex justify-end *:w-full sm:*:w-auto">
                <AppButton
                    variant="primary"
                    size="md"
                    :loading="backendPalette.saving.value"
                    v-on:click="backendPalette.save"
                >
                    <Save class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t('backend.settings.save') }}
                </AppButton>
            </div>
        </div>

        <div class="aurora-card p-4 space-y-5">
            <!-- Le mode d'emploi de l'écran, à côté de ce qu'il explique ;
             replié ou déplié, le choix vaut pour tous les encarts. -->
            <AppGuide :title="t('backend.settings.appearance.guide.title')" storage-key="settings-appearance">
                <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                    <li v-for="step in 5" :key="step">{{ t(`backend.settings.appearance.guide.step_${step}`) }}</li>
                </ol>
            </AppGuide>
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
                        class="absolute -top-1 -right-1 w-5 h-5 rounded-full bg-surface border border-line text-muted hover:text-danger hover:border-danger flex items-center justify-center transition shadow-sm sm:opacity-0 sm:group-hover:opacity-100"
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
                    <AppButton variant="ghost" size="sm" v-on:click="colorPresets.openAddForm">
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
    </div>
</template>
