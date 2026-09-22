<script setup>
/**
 * The deck's look, chosen against a slide that changes as you choose.
 *
 * **The preview is the point of the panel.** Five theme names in a select say
 * nothing about what they look like, and a hexadecimal field says even less;
 * what a reader is deciding here is a visual question, so the answer is drawn
 * rather than named. The preview is a real `SlideFrame` fed the merged look,
 * so what it shows is what the deck will be, down to the footer band.
 *
 * The slide it previews is the deck's own first slide when there is one. A
 * made-up sample would show the theme on words nobody wrote, which is exactly
 * how a theme gets chosen for a deck it does not suit.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Check, Palette, RotateCcw, X } from "lucide-vue-next";
import SlideFrame from "./SlideFrame.vue";
import SlideColorField from "./SlideColorField.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import AppImagePickerField from "@/shared/components/form/file/AppImagePickerField.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";

const props = defineProps({
    show: { type: Boolean, default: false },
    themes: { type: Array, default: () => [] },
    fontPairs: { type: Array, default: () => [] },
    gradients: { type: Array, default: () => [] },
    patterns: { type: Array, default: () => [] },
    margins: { type: Array, default: () => [] },
    logoPlacements: { type: Array, default: () => [] },
    transitions: { type: Array, default: () => [] },
    /** The first slide of the deck, or null for a deck with none yet. */
    sample: { type: Object, default: null },
    theme: { type: String, default: "slate" },
    /**
     * The overrides being composed. Named `overrides` and not `style`: Vue
     * treats `style` as a fallthrough attribute whatever a component declares,
     * so a prop by that name is merged onto the root element instead of
     * arriving.
     */
    overrides: { type: Object, required: true },
    logo: { type: Object, default: () => ({ id: null, url: null }) },
    inherited: { type: Object, required: true },
    preview: { type: Object, required: true },
    isOverridden: { type: Boolean, default: false },
    carriesOverrides: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
});

/**
 * `write` rather than `v-model` on the style object.
 *
 * The style is a prop here, and writing into a prop's own properties is a
 * mutation Vue cannot track back to its owner - the composable that holds it.
 * One event carrying a key and a value keeps the ownership where the saving
 * happens, and costs a line per control.
 */
const emit = defineEmits([
    "close",
    "save",
    "write",
    "update:theme",
    "update:logo",
    "reset-colours",
]);

const write = (key, value) => emit("write", key, value);

const { t } = useI18n();

/** A stand-in only when the deck is empty: a panel with no preview is a form. */
const previewed = computed(
    () =>
        props.sample ?? {
            id: 0,
            layout: "title",
            content: {
                title: t("backend.studio.decks.appearance_sample_title"),
                subtitle: t("backend.studio.decks.appearance_sample_subtitle"),
            },
        },
);

const fontOptions = computed(() => [
    { value: "", label: t("backend.studio.decks.font_from_theme") },
    ...props.fontPairs.map((pair) => ({
        value: pair.value,
        label: t(pair.labelKey),
    })),
]);

const gradientOptions = computed(() =>
    props.gradients.map((gradient) => ({
        value: gradient.value,
        label: t(gradient.labelKey),
    })),
);

const marginOptions = computed(() =>
    props.margins.map((margin) => ({
        value: margin,
        label: t(`backend.studio.decks.margins.${margin}`),
    })),
);

const patternOptions = computed(() =>
    props.patterns.map((pattern) => ({
        value: pattern.value,
        label: t(pattern.labelKey),
    })),
);

const transitionOptions = computed(() =>
    props.transitions.map((transition) => ({
        value: transition.value,
        label: t(transition.labelKey),
    })),
);

const placementOptions = computed(() =>
    props.logoPlacements.map((placement) => ({
        value: placement.value,
        label: t(placement.labelKey),
    })),
);
</script>

<template>
    <AppModal
        :show="show"
        max-width="3xl"
        :title="t('backend.studio.decks.appearance')"
        :icon="Palette"
        v-on:close="emit('close')"
    >
        <div class="flex flex-col gap-5 lg:flex-row lg:items-start">
            <!-- L'aperçu d'abord, et il reste visible pendant qu'on règle :
                 c'est lui qui répond à la question posée par ce panneau. -->
            <div class="w-full shrink-0 lg:w-80">
                <SlideFrame :slide="previewed" :appearance="preview" :index="1" />
                <p class="mt-2 text-xs text-muted">
                    {{ t("backend.studio.decks.appearance_preview_hint") }}
                </p>
            </div>

            <div class="flex min-w-0 flex-1 flex-col gap-5">
                <div class="flex flex-col gap-2">
                    <span class="text-xs uppercase tracking-wide text-muted">
                        {{ t("backend.studio.decks.theme") }}
                    </span>

                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        <button
                            v-for="option in themes"
                            :key="option.value"
                            type="button"
                            class="flex cursor-pointer items-center gap-2 rounded-lg border p-2 text-left transition-colors"
                            :class="option.value === theme
                                ? 'border-accent bg-accent-600/10'
                                : 'border-line hover:border-line-strong'"
                            :aria-pressed="option.value === theme"
                            v-on:click="emit('update:theme', option.value)"
                        >
                            <!-- La pastille dit la palette en trois couches :
                                 le fond, une barre d'encre, un point d'accent.
                                 C'est la plus petite chose qui distingue deux
                                 thèmes sans les dessiner en entier. -->
                            <span
                                class="flex h-8 w-8 shrink-0 flex-col justify-end gap-1 rounded border border-line/60 p-1"
                                :style="{ background: option.palette.background }"
                            >
                                <span
                                    class="h-1 w-full rounded-full"
                                    :style="{ background: option.palette.ink }"
                                />
                                <span
                                    class="h-1.5 w-1.5 rounded-full"
                                    :style="{ background: option.palette.accent }"
                                />
                            </span>
                            <span class="min-w-0 truncate text-xs font-medium text-primary">
                                {{ t(option.labelKey) }}
                            </span>
                        </button>
                    </div>

                    <p v-if="carriesOverrides" class="m-0 text-xs text-amber-400">
                        {{ t("backend.studio.decks.appearance_overrides_kept") }}
                    </p>
                </div>

                <div class="flex flex-col gap-3 rounded-lg border border-line p-3">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs uppercase tracking-wide text-muted">
                            {{ t("backend.studio.decks.colours") }}
                        </span>
                        <AppButton
                            v-if="isOverridden"
                            variant="ghost"
                            size="sm"
                            v-on:click="emit('reset-colours')"
                        >
                            <RotateCcw class="h-3 w-3" :stroke-width="2" />
                            {{ t("backend.studio.decks.colour_reset_all") }}
                        </AppButton>
                    </div>

                    <SlideColorField
                        :model-value="overrides.background"
                        :label="t('backend.studio.decks.colour_background')"
                        :inherited="inherited.background"
                        v-on:update:model-value="(value) => write('background', value)"
                    />
                    <SlideColorField
                        :model-value="overrides.ink"
                        :label="t('backend.studio.decks.colour_ink')"
                        :inherited="inherited.ink"
                        v-on:update:model-value="(value) => write('ink', value)"
                    />
                    <SlideColorField
                        :model-value="overrides.accent"
                        :label="t('backend.studio.decks.colour_accent')"
                        :inherited="inherited.accent"
                        v-on:update:model-value="(value) => write('accent', value)"
                    />

                    <AppSelect
                        :model-value="overrides.gradient ?? 'none'"
                        :options="gradientOptions"
                        :label="t('backend.studio.decks.gradient_label')"
                        :hint="t('backend.studio.decks.gradient_hint')"
                        v-on:update:model-value="(value) => write('gradient', value)"
                    />

                    <AppSelect
                        :model-value="overrides.margins ?? 'normal'"
                        :options="marginOptions"
                        :label="t('backend.studio.decks.margins_label')"
                        v-on:update:model-value="(value) => write('margins', value)"
                    />

                    <AppToggle
                        :model-value="overrides.hairline === true"
                        :label="t('backend.studio.decks.hairline')"
                        :hint="t('backend.studio.decks.hairline_hint')"
                        v-on:update:model-value="(value) => write('hairline', value)"
                    />

                    <AppSelect
                        :model-value="overrides.pattern ?? 'none'"
                        :options="patternOptions"
                        :label="t('backend.studio.decks.pattern_label')"
                        :hint="t('backend.studio.decks.pattern_hint')"
                        v-on:update:model-value="(value) => write('pattern', value)"
                    />

                    <AppSelect
                        :model-value="overrides.fontPair ?? ''"
                        :options="fontOptions"
                        :label="t('backend.studio.decks.fonts_label')"
                        v-on:update:model-value="(value) => write('fontPair', value || null)"
                    />
                </div>

                <div class="flex flex-col gap-3 rounded-lg border border-line p-3">
                    <span class="text-xs uppercase tracking-wide text-muted">
                        {{ t("backend.studio.decks.signature") }}
                    </span>

                    <AppImagePickerField
                        :model-value="logo"
                        :label="t('backend.studio.decks.logo')"
                        :hint="t('backend.studio.decks.logo_hint')"
                        :size="96"
                        v-on:update:model-value="(value) => emit('update:logo', value)"
                    />

                    <AppSelect
                        v-if="logo.id"
                        :model-value="overrides.logoPlacement"
                        :options="placementOptions"
                        :label="t('backend.studio.decks.logo_placement')"
                        v-on:update:model-value="(value) => write('logoPlacement', value)"
                    />

                    <AppInput
                        :model-value="overrides.footerText"
                        :label="t('backend.studio.decks.footer_text')"
                        :placeholder="t('backend.studio.decks.footer_text_placeholder')"
                        :maxlength="120"
                        v-on:update:model-value="(value) => write('footerText', value)"
                    />

                    <AppToggle
                        :model-value="overrides.slideNumbers"
                        :label="t('backend.studio.decks.slide_numbers')"
                        :hint="t('backend.studio.decks.slide_numbers_hint')"
                        v-on:update:model-value="(value) => write('slideNumbers', value)"
                    />

                    <!-- La transition ne change ni le papier ni le lien de
                         partage : elle n'existe qu'au plein écran, d'où sa
                         place en bas, après ce qui se voit partout. -->
                    <AppSelect
                        :model-value="overrides.transition ?? 'fade'"
                        :options="transitionOptions"
                        :label="t('backend.studio.decks.transition')"
                        :hint="t('backend.studio.decks.transition_hint')"
                        v-on:update:model-value="(value) => write('transition', value)"
                    />
                </div>
            </div>
        </div>

        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="emit('close')">
                    <X class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton variant="primary" size="md" :loading="saving" v-on:click="emit('save')">
                    <Check class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("shared.common.save") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
