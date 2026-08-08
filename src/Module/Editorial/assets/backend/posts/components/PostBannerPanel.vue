<script setup>
/**
 * Banner panel of the post editor.
 *
 * A builder: add a text or an image, reorder, remove. The arrangements —
 * text then image, two images, text alone — are what the list produces rather
 * than options to pick from.
 *
 * Presentation only: every field arrives as a writable computed from
 * usePostBanner, so nothing here writes to the prop directly.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppImagePickerField from "@/shared/components/form/file/AppImagePickerField.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppRange from "@/shared/components/form/toggle/AppRange.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import BannerColorField from "./BannerColorField.vue";
import { Plus, Trash2, ChevronUp, ChevronDown, Type, Image, MousePointerClick } from "lucide-vue-next";
import { usePostBanner } from "../composables/usePostBanner.js";
import { useBannerPreview } from "../composables/useBannerPreview.js";

const props = defineProps({
    banner: { type: Object, required: true },
    previewPath: { type: String, required: true },
});

const { t } = useI18n();

const {
    heightOptions,
    alignOptions,
    fillOptions,
    widthModeOptions,
    verticalAlignOptions,
    titleSizeOptions,
    widthOptions,
    items,
    canAddItem,
    addItem,
    removeItem,
    moveItem,
    hasBackgroundImage,
    isSolidFill,
    isGradientFill,
    fillPreviewStyle,
    fields,
    itemFields,
} = usePostBanner(computed(() => props.banner));

const { html: previewHtml, loading: previewLoading } = useBannerPreview(
    computed(() => props.banner),
    props.previewPath,
);
</script>

<template>
    <div class="space-y-4">
        <AppToggle v-model="fields.enabled.value" :label="t('backend.posts.banner.enabled')" />

        <template v-if="fields.enabled.value">
            <div class="relative space-y-2">
                <p class="text-sm font-medium text-primary">{{ t("backend.posts.banner.preview") }}</p>
                <!-- Rendered by the server from the same Twig the public page
                     uses, so what shows here is what gets published. -->
                <div class="rounded-lg border border-line overflow-hidden bg-surface-2/30 p-3">
                    <div v-html="previewHtml" />
                </div>
                <AppLoader :active="previewLoading" />
            </div>

            <div class="space-y-3">
                <AppNoData v-if="!items.length" :message="t('backend.posts.banner.empty')" />

                <div
                    v-for="(item, index) in items"
                    :key="index"
                    class="rounded-lg border border-line p-4 space-y-4"
                >
                    <div class="flex items-center gap-2">
                        <component
                            :is="{ text: Type, image: Image, button: MousePointerClick }[item.type]"
                            class="w-4 h-4 text-muted"
                            :stroke-width="2"
                        />
                        <p class="text-sm font-medium text-primary flex-1">
                            {{ t(`backend.posts.banner.item_types.${item.type}`) }}
                        </p>
                        <AppIconButton
                            color="default"
                            :title="t('backend.posts.banner.move_up')"
                            :disabled="index === 0"
                            v-on:click="moveItem(index, -1)"
                        >
                            <ChevronUp class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                        <AppIconButton
                            color="default"
                            :title="t('backend.posts.banner.move_down')"
                            :disabled="index === items.length - 1"
                            v-on:click="moveItem(index, 1)"
                        >
                            <ChevronDown class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                        <AppIconButton
                            color="rose"
                            :title="t('backend.posts.banner.remove_item')"
                            v-on:click="removeItem(index)"
                        >
                            <Trash2 class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                    </div>

                    <AppSelect
                        v-model="itemFields(index).width.value"
                        :label="t('backend.posts.banner.width')"
                        :options="widthOptions"
                    />

                    <template v-if="item.type === 'text'">
                        <AppInput
                            v-model="itemFields(index).title.value"
                            :label="t('backend.posts.banner.slot_title')"
                            :placeholder="t('backend.posts.banner.slot_title_placeholder')"
                        />
                        <AppTextarea
                            v-model="itemFields(index).description.value"
                            :label="t('backend.posts.banner.slot_description')"
                            :placeholder="t('backend.posts.banner.slot_description_placeholder')"
                        />
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <BannerColorField
                                v-model="itemFields(index).titleColor.value"
                                :label="t('backend.posts.banner.slot_title_color')"
                            />
                            <BannerColorField
                                v-model="itemFields(index).descriptionColor.value"
                                :label="t('backend.posts.banner.slot_description_color')"
                            />
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <AppSelect
                                v-model="itemFields(index).align.value"
                                :label="t('backend.posts.banner.slot_align')"
                                :options="alignOptions"
                            />
                            <AppSelect
                                v-model="itemFields(index).titleSize.value"
                                :label="t('backend.posts.banner.title_size')"
                                :options="titleSizeOptions"
                            />
                        </div>
                    </template>

                    <template v-else-if="item.type === 'button'">
                        <AppInput
                            v-model="itemFields(index).label.value"
                            :label="t('backend.posts.banner.button_label')"
                            :placeholder="t('backend.posts.banner.button_label_placeholder')"
                        />
                        <AppInput
                            v-model="itemFields(index).url.value"
                            :label="t('backend.posts.banner.button_url')"
                            :placeholder="t('backend.posts.banner.button_url_placeholder')"
                        />
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <BannerColorField
                                v-model="itemFields(index).buttonColor.value"
                                :label="t('backend.posts.banner.button_color')"
                            />
                            <BannerColorField
                                v-model="itemFields(index).buttonTextColor.value"
                                :label="t('backend.posts.banner.button_text_color')"
                            />
                        </div>
                        <AppSelect
                            v-model="itemFields(index).align.value"
                            :label="t('backend.posts.banner.slot_align')"
                            :options="alignOptions"
                        />
                    </template>

                    <template v-else>
                        <AppImagePickerField
                            v-model="itemFields(index).media.value"
                            :label="t('backend.posts.banner.slot_image')"
                        />
                        <AppInput
                            v-model="itemFields(index).alt.value"
                            :label="t('backend.posts.banner.slot_alt')"
                            :placeholder="t('backend.posts.banner.slot_alt_placeholder')"
                        />
                    </template>
                </div>

                <div class="flex flex-wrap gap-2">
                    <AppButton
                        variant="ghost"
                        size="md"
                        :disabled="!canAddItem"
                        v-on:click="addItem('text')"
                    >
                        <Plus class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("backend.posts.banner.add_text") }}
                    </AppButton>
                    <AppButton
                        variant="ghost"
                        size="md"
                        :disabled="!canAddItem"
                        v-on:click="addItem('image')"
                    >
                        <Plus class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("backend.posts.banner.add_image") }}
                    </AppButton>
                    <AppButton
                        variant="ghost"
                        size="md"
                        :disabled="!canAddItem"
                        v-on:click="addItem('button')"
                    >
                        <Plus class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("backend.posts.banner.add_button") }}
                    </AppButton>
                </div>
            </div>

            <!-- Appearance last: an author fills the banner before deciding
                 how tall it is or what sits behind it. -->
            <div class="rounded-lg border border-line p-4 space-y-4">
                <p class="text-sm font-medium text-primary">{{ t("backend.posts.banner.appearance") }}</p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <AppSelect
                        v-model="fields.widthMode.value"
                        :label="t('backend.posts.banner.width_mode')"
                        :options="widthModeOptions"
                    />
                    <AppSelect
                        v-model="fields.height.value"
                        :label="t('backend.posts.banner.height')"
                        :options="heightOptions"
                    />
                    <AppSelect
                        v-model="fields.verticalAlign.value"
                        :label="t('backend.posts.banner.vertical_align')"
                        :options="verticalAlignOptions"
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
                            <AppRange v-model="fields.gradientAngle.value" :min="0" :max="360" :step="15" />
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
