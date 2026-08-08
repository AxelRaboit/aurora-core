<script setup>
/**
 * Banner panel of the post editor.
 *
 * Presentation only: every field is a writable computed handed over by
 * usePostBanner, so nothing here writes to the prop directly. The banner
 * itself belongs to the current translation, which is why this component owns
 * no state and knows nothing about saving or locales.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppTab from "@/shared/components/nav/AppTab.vue";
import BannerColorField from "./BannerColorField.vue";
import AppImagePickerField from "@/shared/components/form/file/AppImagePickerField.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppRange from "@/shared/components/form/toggle/AppRange.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import { usePostBanner } from "../composables/usePostBanner.js";

const props = defineProps({
    banner: { type: Object, required: true },
});

const { t } = useI18n();

const {
    heightOptions,
    ratioOptions,
    slotTypeOptions,
    alignOptions,
    fillOptions,
    presetOptions,
    applyPreset,
    bothSlotsFilled,
    hasBackgroundImage,
    isSolidFill,
    isGradientFill,
    fillPreviewStyle,
    fields,
    slotFields,
} = usePostBanner(computed(() => props.banner));

// Slots are laid out side by side above the mobile breakpoint and stacked
// below it, so "left" and "right" are the desktop reading — which is the one
// an author is composing against.
const slotLabels = computed(() => [
    t("backend.posts.banner.slot_left"),
    t("backend.posts.banner.slot_right"),
]);
</script>

<template>
    <div class="space-y-4">
        <AppToggle v-model="fields.enabled.value" :label="t('backend.posts.banner.enabled')" />

        <template v-if="fields.enabled.value">
            <div class="space-y-2">
                <p class="text-sm font-medium text-primary">{{ t("backend.posts.banner.preset") }}</p>
                <div class="flex flex-wrap gap-2">
                    <AppTab
                        v-for="preset in presetOptions"
                        :key="preset.key"
                        variant="pill"
                        size="sm"
                        :active="preset.active"
                        v-on:click="applyPreset(preset.types)"
                    >
                        {{ preset.label }}
                    </AppTab>
                </div>
                <p class="text-xs text-muted">{{ t("backend.posts.banner.preset_hint") }}</p>
            </div>

            <div
                v-for="(slot, index) in banner.slots"
                :key="index"
                class="rounded-lg border border-line p-4 space-y-4"
            >
                <p class="text-sm font-medium text-primary">{{ slotLabels[index] }}</p>

                <AppSelect
                    v-model="slotFields(index).type.value"
                    :label="t('backend.posts.banner.slot_type')"
                    :options="slotTypeOptions"
                />

                <template v-if="slot.type === 'text'">
                    <AppInput
                        v-model="slotFields(index).title.value"
                        :label="t('backend.posts.banner.slot_title')"
                        :placeholder="t('backend.posts.banner.slot_title_placeholder')"
                    />
                    <AppTextarea
                        v-model="slotFields(index).description.value"
                        :label="t('backend.posts.banner.slot_description')"
                        :placeholder="t('backend.posts.banner.slot_description_placeholder')"
                    />
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <BannerColorField
                            v-model="slotFields(index).titleColor.value"
                            :label="t('backend.posts.banner.slot_title_color')"
                        />
                        <BannerColorField
                            v-model="slotFields(index).descriptionColor.value"
                            :label="t('backend.posts.banner.slot_description_color')"
                        />
                    </div>
                    <AppSelect
                        v-model="slotFields(index).align.value"
                        :label="t('backend.posts.banner.slot_align')"
                        :options="alignOptions"
                    />
                </template>

                <template v-else-if="slot.type === 'image'">
                    <AppImagePickerField
                        v-model="slotFields(index).media.value"
                        :label="t('backend.posts.banner.slot_image')"
                    />
                    <AppInput
                        v-model="slotFields(index).alt.value"
                        :label="t('backend.posts.banner.slot_alt')"
                        :placeholder="t('backend.posts.banner.slot_alt_placeholder')"
                    />
                </template>
            </div>

            <!-- Appearance last: an author fills the banner before deciding
                 how tall it is or what sits behind it. -->
            <div class="rounded-lg border border-line p-4 space-y-4">
                <p class="text-sm font-medium text-primary">{{ t("backend.posts.banner.appearance") }}</p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <AppSelect
                        v-model="fields.height.value"
                        :label="t('backend.posts.banner.height')"
                        :options="heightOptions"
                    />
                    <AppSelect
                        v-if="bothSlotsFilled"
                        v-model="fields.ratio.value"
                        :label="t('backend.posts.banner.ratio')"
                        :options="ratioOptions"
                    />
                </div>

                <div class="space-y-3">
                    <div class="flex items-end gap-3">
                        <AppSelect
                            v-model="fields.fillType.value"
                            :label="t('backend.posts.banner.fill')"
                            :options="fillOptions"
                            class="flex-1"
                        />
                        <!-- Live swatch of the fill: the panel has no preview
                             of the banner yet, and a gradient's direction is
                             not something to discover after saving. -->
                        <span
                            v-if="fillPreviewStyle"
                            class="h-9 w-16 shrink-0 rounded-md border border-line"
                            :style="fillPreviewStyle"
                        />
                    </div>

                    <BannerColorField
                        v-if="isSolidFill"
                        v-model="fields.backgroundColor.value"
                        :label="t('backend.posts.banner.background_color')"
                    />

                    <template v-if="isGradientFill">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <BannerColorField
                                v-model="fields.gradientFrom.value"
                                :label="t('backend.posts.banner.gradient_from')"
                            />
                            <BannerColorField
                                v-model="fields.gradientTo.value"
                                :label="t('backend.posts.banner.gradient_to')"
                            />
                        </div>
                        <div>
                            <p class="text-sm text-secondary mb-1">
                                {{ t("backend.posts.banner.gradient_angle", { degrees: fields.gradientAngle.value }) }}
                            </p>
                            <AppRange
                                v-model="fields.gradientAngle.value"
                                :min="0"
                                :max="360"
                                :step="15"
                            />
                        </div>
                    </template>
                </div>

                <AppImagePickerField
                    v-model="fields.backgroundMedia.value"
                    :label="t('backend.posts.banner.background_image')"
                    :hint="t('backend.posts.banner.background_image_hint')"
                />

                <div v-if="hasBackgroundImage">
                    <p class="text-sm text-secondary mb-1">
                        {{ t("backend.posts.banner.overlay", { percent: fields.overlay.value }) }}
                    </p>
                    <AppRange v-model="fields.overlay.value" :min="0" :max="100" :step="5" />
                </div>

                <AppImagePickerField
                    v-model="fields.logoMedia.value"
                    :label="t('backend.posts.banner.logo')"
                    :hint="t('backend.posts.banner.logo_hint')"
                />
            </div>
        </template>
    </div>
</template>
