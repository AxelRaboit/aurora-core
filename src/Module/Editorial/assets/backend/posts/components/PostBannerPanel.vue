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
import AppColorPicker from "@/shared/components/form/picker/AppColorPicker.vue";
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
    bothSlotsFilled,
    hasBackgroundImage,
    fields,
    slotFields,
} = usePostBanner(computed(() => props.banner));
</script>

<template>
    <div class="space-y-4">
        <AppToggle v-model="fields.enabled.value" :label="t('backend.posts.banner.enabled')" />

        <template v-if="fields.enabled.value">
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

            <div class="rounded-lg border border-line p-4 space-y-4">
                <p class="text-sm font-medium text-primary">{{ t("backend.posts.banner.background") }}</p>

                <AppColorPicker
                    v-model="fields.backgroundColor.value"
                    :label="t('backend.posts.banner.background_color')"
                />

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
                />
            </div>

            <div
                v-for="(slot, index) in banner.slots"
                :key="index"
                class="rounded-lg border border-line p-4 space-y-4"
            >
                <p class="text-sm font-medium text-primary">
                    {{ t("backend.posts.banner.slot", { number: index + 1 }) }}
                </p>

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
                        <AppColorPicker
                            v-model="slotFields(index).titleColor.value"
                            :label="t('backend.posts.banner.slot_title_color')"
                        />
                        <AppColorPicker
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
        </template>
    </div>
</template>
