<script setup>
/**
 * Banner panel of the post editor.
 *
 * A builder: add a text or an image, reorder, remove. The arrangements -
 * text then image, two images, text alone - are what the list produces rather
 * than options to pick from.
 *
 * Presentation only: every field arrives as a writable computed from
 * usePostBanner, so nothing here writes to the prop directly.
 *
 * Two props because the banner is stored in two halves - the design on the
 * post, the words on the open translation. The panel does not arrange them
 * differently for it: a field is a field, and usePostBanner is what knows
 * which half each one belongs to.
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
import AppChoiceRow from "@/shared/components/form/select/AppChoiceRow.vue";
import BannerColorField from "./BannerColorField.vue";
import BannerTitleInput from "./BannerTitleInput.vue";
import { Plus, Trash2, ChevronUp, ChevronDown, Type, Image, MousePointerClick } from "lucide-vue-next";
import { usePostBanner } from "../composables/usePostBanner.js";
import { useServerPreview } from "@/shared/composables/http/backend/useServerPreview.js";

const props = defineProps({
    /** The design, shared by every language. */
    layout: { type: Object, required: true },
    /** The words, for the language currently open. */
    texts: { type: Object, required: true },
    /** Which language that is - shown on the fields that are per-language. */
    locale: { type: String, required: true },
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
    stripeSideOptions,
    stripeColors,
    canAddStripe,
    setStripeColor,
    addStripe,
    removeStripe,
    widthOptions,
    tabletWidthOptions,
    fontOptions,
    items,
    canAddItem,
    addItem,
    removeItem,
    moveItem,
    hasBackgroundImage,
    isSolidFill,
    isGradientFill,
    fillPreviewStyle,
    fillWarning,
    fields,
    itemFields,
} = usePostBanner(
    computed(() => props.layout),
    computed(() => props.texts),
);

// Both halves, because a preview is per language: the same layout with the
// German copy is a different picture from the same layout with the French.
const { html: previewHtml, loading: previewLoading } = useServerPreview(
    () => ({ layout: props.layout, texts: props.texts }),
    [() => props.layout, () => props.texts],
    props.previewPath,
);
</script>

<template>
    <div class="space-y-4">
        <AppToggle v-model="fields.enabled.value" :label="t('backend.posts.banner.enabled')" />

        <!-- Without this the card is a lone toggle, which reads as collapsed
             rather than as off. It also says the thing the model makes true
             and nothing else would: the design is shared, so switching it on
             switches it on in every language. -->
        <p v-if="!fields.enabled.value" class="text-sm text-muted">
            {{ t("backend.posts.banner.disabled_hint") }}
        </p>

        <template v-if="fields.enabled.value">
            <div class="relative space-y-2">
                <p class="text-sm font-medium text-primary">{{ t("backend.posts.banner.preview") }}</p>
                <!-- Rendered by the server from the same Twig the public page
                     uses, so what shows here is what gets published.

                     `aurora-banner-preview` and `data-theme` are what the
                     response's own <style> targets to repaint this box in the
                     theme's page background: without them a title with no
                     colour of its own drew in the backend's ambient text
                     colour, not the one the public page shows it in. `bg-bg`
                     is the fallback for a theme with no background configured
                     - the same "light page, dark text" default the public
                     page falls back to. -->
                <div class="aurora-banner-preview bg-bg rounded-lg border border-line overflow-hidden p-3" data-theme>
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

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <AppSelect
                            v-model="itemFields(index).width.value"
                            :label="t('backend.posts.banner.width')"
                            :options="widthOptions"
                        />
                        <AppSelect
                            v-model="itemFields(index).tabletWidth.value"
                            :label="t('backend.posts.banner.tablet_width')"
                            :options="tabletWidthOptions"
                        />
                    </div>

                    <template v-if="item.type === 'text'">
                        <div class="rounded-lg border border-dashed border-line p-3 space-y-4">
                            <p class="text-xs uppercase tracking-wide text-muted">
                                {{ t("backend.posts.banner.translated_fields", { locale }) }}
                            </p>
                            <BannerTitleInput
                                v-model="itemFields(index).title.value"
                                :label="t('backend.posts.banner.slot_title')"
                                :placeholder="t('backend.posts.banner.slot_title_placeholder')"
                            />
                            <AppTextarea
                                v-model="itemFields(index).description.value"
                                :label="t('backend.posts.banner.slot_description')"
                                :placeholder="t('backend.posts.banner.slot_description_placeholder')"
                            />
                            <!-- The call to action under the words. The page
                                 already drew it from these two fields; only the
                                 editor had no way to fill them. -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <AppInput
                                    v-model="itemFields(index).label.value"
                                    :label="t('backend.posts.banner.text_button_label')"
                                    :placeholder="t('backend.posts.banner.button_label_placeholder')"
                                />
                                <AppInput
                                    v-model="itemFields(index).url.value"
                                    :label="t('backend.posts.banner.button_url')"
                                    :placeholder="t('backend.posts.banner.button_url_placeholder')"
                                />
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <BannerColorField
                                v-model="itemFields(index).titleColor.value"
                                :label="t('backend.posts.banner.slot_title_color')"
                            />
                            <BannerColorField
                                v-model="itemFields(index).descriptionColor.value"
                                :label="t('backend.posts.banner.slot_description_color')"
                            />
                            <BannerColorField
                                v-model="itemFields(index).buttonColor.value"
                                :label="t('backend.posts.banner.button_color')"
                            />
                            <BannerColorField
                                v-model="itemFields(index).buttonTextColor.value"
                                :label="t('backend.posts.banner.button_text_color')"
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
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <AppSelect
                                v-model="itemFields(index).titleFont.value"
                                :label="t('backend.posts.banner.title_font')"
                                :options="fontOptions"
                            />
                            <AppSelect
                                v-model="itemFields(index).descriptionSize.value"
                                :label="t('backend.posts.banner.description_size')"
                                :options="titleSizeOptions"
                            />
                        </div>
                        <AppSelect
                            v-model="itemFields(index).descriptionFont.value"
                            :label="t('backend.posts.banner.description_font')"
                            :options="fontOptions"
                        />
                    </template>

                    <template v-else-if="item.type === 'button'">
                        <!-- The link is in here on purpose: a localised page
                             has a localised address, and the first banner ever
                             written pointed at /fr/page/premiers-pas. -->
                        <div class="rounded-lg border border-dashed border-line p-3 space-y-4">
                            <p class="text-xs uppercase tracking-wide text-muted">
                                {{ t("backend.posts.banner.translated_fields", { locale }) }}
                            </p>
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
                        </div>
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
                        <div class="rounded-lg border border-dashed border-line p-3 space-y-4">
                            <p class="text-xs uppercase tracking-wide text-muted">
                                {{ t("backend.posts.banner.translated_fields", { locale }) }}
                            </p>
                            <AppInput
                                v-model="itemFields(index).alt.value"
                                :label="t('backend.posts.banner.slot_alt')"
                                :placeholder="t('backend.posts.banner.slot_alt_placeholder')"
                            />
                        </div>
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

                    <!-- The renderer refuses an incomplete fill rather than
                         guessing the missing half, which is right - and silent.
                         A header that draws nothing, with white text on a white
                         panel, reads as the editor being broken. This says
                         which half is missing. -->
                    <p v-if="fillWarning" class="text-xs text-amber-600 dark:text-amber-500">
                        {{ fillWarning }}
                    </p>

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

                <AppImagePickerField
                    v-model="fields.mobileBackgroundMedia.value"
                    :label="t('backend.posts.banner.mobile_background_image')"
                    :hint="t('backend.posts.banner.mobile_background_image_hint')"
                />

                <AppImagePickerField
                    v-model="fields.tabletBackgroundMedia.value"
                    :label="t('backend.posts.banner.tablet_background_image')"
                    :hint="t('backend.posts.banner.tablet_background_image_hint')"
                />

                <!-- Per language, and marked as such like an item's words: a
                     picture with a title set in it reads in one language. -->
                <div class="rounded-lg border border-dashed border-line p-3 space-y-4">
                    <p class="text-xs uppercase tracking-wide text-muted">
                        {{ t("backend.posts.banner.local_background", { locale }) }}
                    </p>
                    <p class="text-sm text-secondary">{{ t("backend.posts.banner.local_background_hint") }}</p>
                    <AppImagePickerField
                        v-model="fields.localBackgroundMedia.value"
                        :label="t('backend.posts.banner.background_image')"
                    />
                    <AppImagePickerField
                        v-model="fields.localMobileBackgroundMedia.value"
                        :label="t('backend.posts.banner.mobile_background_image')"
                    />
                    <AppImagePickerField
                        v-model="fields.localTabletBackgroundMedia.value"
                        :label="t('backend.posts.banner.tablet_background_image')"
                    />
                </div>

                <div v-if="hasBackgroundImage">
                    <p class="text-sm text-secondary mb-1">
                        {{ t("backend.posts.banner.overlay", { percent: fields.overlay.value }) }}
                    </p>
                    <AppRange v-model="fields.overlay.value" :min="0" :max="100" :step="5" />
                </div>

                <!-- Not inside the `hasBackgroundImage` branch above: a
                     banner filled with a flat colour has just as much of an
                     edge to dissolve. -->
                <AppToggle
                    v-model="fields.fadeOut.value"
                    :label="t('backend.posts.banner.fade_out')"
                    :hint="t('backend.posts.banner.fade_out_hint')"
                />

                <!-- Bands drawn by the site, over any background: changing
                     their colours or their slant is a setting here, not a new
                     picture to make. -->
                <AppToggle
                    v-model="fields.stripesEnabled.value"
                    :label="t('backend.posts.banner.stripes')"
                    :hint="t('backend.posts.banner.stripes_hint')"
                />
                <div v-if="fields.stripesEnabled.value" class="space-y-3 rounded-lg border border-line p-3">
                    <div class="space-y-2">
                        <p class="text-sm text-secondary">{{ t("backend.posts.banner.stripe_colors") }}</p>
                        <div v-for="(colour, index) in stripeColors" :key="index" class="flex items-end gap-2">
                            <BannerColorField
                                class="flex-1"
                                :model-value="colour"
                                :label="t('backend.posts.banner.stripe_color', { number: index + 1 })"
                                v-on:update:model-value="setStripeColor(index, $event)"
                            />
                            <AppIconButton
                                v-if="stripeColors.length > 1"
                                variant="ghost"
                                size="sm"
                                :title="t('backend.posts.banner.stripe_remove')"
                                v-on:click="removeStripe(index)"
                            >
                                <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                            </AppIconButton>
                        </div>
                        <AppButton
                            v-if="canAddStripe"
                            variant="ghost"
                            size="sm"
                            type="button"
                            v-on:click="addStripe"
                        >
                            <Plus class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("backend.posts.banner.stripe_add") }}
                        </AppButton>
                    </div>
                    <AppChoiceRow
                        v-model="fields.stripesSide.value"
                        :options="stripeSideOptions"
                        :label="t('backend.posts.banner.stripe_side')"
                    />
                    <div>
                        <p class="text-sm text-secondary mb-1">{{ t("backend.posts.banner.stripe_thickness", { px: fields.stripesThickness.value }) }}</p>
                        <AppRange v-model="fields.stripesThickness.value" :min="4" :max="240" :step="2" />
                    </div>
                    <div>
                        <p class="text-sm text-secondary mb-1">{{ t("backend.posts.banner.stripe_gap", { px: fields.stripesGap.value }) }}</p>
                        <AppRange v-model="fields.stripesGap.value" :min="0" :max="160" :step="2" />
                    </div>
                    <div>
                        <p class="text-sm text-secondary mb-1">{{ t("backend.posts.banner.stripe_angle", { degrees: fields.stripesAngle.value }) }}</p>
                        <AppRange v-model="fields.stripesAngle.value" :min="-60" :max="60" :step="1" />
                    </div>
                    <div>
                        <p class="text-sm text-secondary mb-1">{{ t("backend.posts.banner.stripe_offset", { percent: fields.stripesOffset.value }) }}</p>
                        <AppRange v-model="fields.stripesOffset.value" :min="0" :max="90" :step="1" />
                    </div>
                    <div>
                        <p class="text-sm text-secondary mb-1">{{ t("backend.posts.banner.stripe_opacity", { percent: fields.stripesOpacity.value }) }}</p>
                        <AppRange v-model="fields.stripesOpacity.value" :min="10" :max="100" :step="5" />
                    </div>
                    <AppToggle
                        v-model="fields.stripesHideOnPhone.value"
                        :label="t('backend.posts.banner.stripe_hide_on_phone')"
                        :hint="t('backend.posts.banner.stripe_hide_on_phone_hint')"
                    />
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
