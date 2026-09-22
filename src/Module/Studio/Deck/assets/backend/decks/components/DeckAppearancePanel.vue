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
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Check, Palette, RotateCcw, X } from "lucide-vue-next";
import SlideFrame from "./SlideFrame.vue";
import SlideColorField from "./SlideColorField.vue";
import { readability, tonesFrom } from "../colour.js";
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
    looks: { type: Array, default: () => [] },
    gradients: { type: Array, default: () => [] },
    patterns: { type: Array, default: () => [] },
    margins: { type: Array, default: () => [] },
    titleCases: { type: Array, default: () => [] },
    bulletShapes: { type: Array, default: () => [] },
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
    "apply-look",
    "update:logo",
    "reset-colours",
]);

const write = (key, value) => emit("write", key, value);

/**
 * Ce que dit le rapport entre l'encre et le fond, sans jamais l'interdire.
 *
 * Le contrepoids de tous les réglages que ce panneau a fini par porter : un
 * lavis, un aplat et une inversion sont chacun raisonnables, et ensemble ils
 * rendent facile d'écrire un texte que personne ne lira, sans s'en apercevoir
 * sur un portable dans une pièce éclairée. Le panneau le dit, il ne refuse pas :
 * un deck est le document de quelqu'un.
 */
const contrastNote = computed(() => readability(props.preview.ink, props.preview.background));

/**
 * Les teintes du logo, proposées comme accent.
 *
 * Choisir une couleur d'accent à l'aveugle donne des decks qui jurent. Ici, la
 * proposition vient de la marque elle-même. Une liste vide est une réponse
 * normale : un logo en noir et blanc n'a pas d'accent à offrir.
 */
const tones = ref([]);

watch(
    () => props.logo.url,
    (url) => {
        tones.value = [];

        if (!url) return;

        const image = new Image();

        image.crossOrigin = "anonymous";
        image.addEventListener("load", () => {
            tones.value = tonesFrom(image);
        });
        image.addEventListener("error", () => {
            tones.value = [];
        });
        image.src = url;
    },
    { immediate: true },
);

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

/**
 * Le premier choix est « celui du thème », comme pour la typographie.
 *
 * Sans lui, le lavis d'un thème deviendrait inatteignable dès qu'un deck a
 * touché au select une fois : `none` dirait à la fois « à plat » et « je n'ai
 * rien choisi », et le deck ne pourrait plus revenir à ce que son thème porte.
 */
const gradientOptions = computed(() => [
    { value: "", label: t("backend.studio.decks.wash_from_theme") },
    ...props.gradients.map((gradient) => ({
        value: gradient.value,
        label: t(gradient.labelKey),
    })),
]);

const titleCaseOptions = computed(() =>
    props.titleCases.map((value) => ({
        value,
        label: t(`backend.studio.decks.title_cases.${value}`),
    })),
);

const bulletOptions = computed(() =>
    props.bulletShapes.map((value) => ({
        value,
        label: t(`backend.studio.decks.bullet_shapes.${value}`),
    })),
);

const marginOptions = computed(() =>
    props.margins.map((margin) => ({
        value: margin,
        label: t(`backend.studio.decks.margins.${margin}`),
    })),
);

const patternOptions = computed(() => [
    { value: "", label: t("backend.studio.decks.wash_from_theme") },
    ...props.patterns.map((pattern) => ({
        value: pattern.value,
        label: t(pattern.labelKey),
    })),
]);

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

                <!-- Avant les huit réglages plutôt qu'après : quelqu'un qui
                     ouvre ce panneau cherche d'abord à ce que son deck
                     ressemble à quelque chose, pas à choisir une trame. -->
                <div class="flex flex-col gap-3 rounded-lg border border-line p-3">
                    <span class="text-xs uppercase tracking-wide text-muted">
                        {{ t("backend.studio.decks.looks_label") }}
                    </span>
                    <p class="m-0 text-xs text-muted">
                        {{ t("backend.studio.decks.looks_hint") }}
                    </p>

                    <div class="grid gap-2 sm:grid-cols-2">
                        <button
                            v-for="look in looks"
                            :key="look.value"
                            type="button"
                            class="flex flex-col gap-0.5 rounded-lg border border-line p-2 text-left transition-colors hover:border-accent"
                            v-on:click="emit('apply-look', look)"
                        >
                            <span class="text-sm font-medium">{{ t(look.labelKey) }}</span>
                            <span class="text-xs text-muted">{{ t(look.descriptionKey) }}</span>
                        </button>
                    </div>
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

                    <!-- Proposées et non appliquées : la marque donne l'idée,
                         la décision reste au lecteur. -->
                    <div v-if="tones.length" class="flex flex-col gap-1.5">
                        <span class="text-xs text-muted">
                            {{ t("backend.studio.decks.accent_from_logo") }}
                        </span>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="tone in tones"
                                :key="tone"
                                type="button"
                                class="h-6 w-6 rounded-full border border-line transition-transform hover:scale-110"
                                :style="{ background: tone }"
                                :title="tone"
                                :aria-label="tone"
                                v-on:click="write('accent', tone)"
                            />
                        </div>
                    </div>

                    <p
                        v-if="contrastNote"
                        class="m-0 text-xs"
                        :class="contrastNote.level === 'poor' ? 'text-amber-400' : 'text-muted'"
                    >
                        {{ t(`backend.studio.decks.contrast_${contrastNote.level}`, { ratio: contrastNote.ratio }) }}
                    </p>

                    <AppSelect
                        :model-value="overrides.gradient ?? ''"
                        :options="gradientOptions"
                        :label="t('backend.studio.decks.gradient_label')"
                        :hint="t('backend.studio.decks.gradient_hint')"
                        v-on:update:model-value="(value) => write('gradient', value || null)"
                    />

                    <AppSelect
                        :model-value="overrides.titleCase ?? 'normal'"
                        :options="titleCaseOptions"
                        :label="t('backend.studio.decks.title_case_label')"
                        v-on:update:model-value="(value) => write('titleCase', value)"
                    />

                    <AppSelect
                        :model-value="overrides.bullets ?? 'disc'"
                        :options="bulletOptions"
                        :label="t('backend.studio.decks.bullets_label')"
                        v-on:update:model-value="(value) => write('bullets', value)"
                    />

                    <AppSelect
                        :model-value="overrides.margins ?? 'normal'"
                        :options="marginOptions"
                        :label="t('backend.studio.decks.margins_label')"
                        v-on:update:model-value="(value) => write('margins', value)"
                    />

                    <AppToggle
                        :model-value="overrides.rules === true"
                        :label="t('backend.studio.decks.rules')"
                        :hint="t('backend.studio.decks.rules_hint')"
                        v-on:update:model-value="(value) => write('rules', value)"
                    />

                    <AppToggle
                        :model-value="overrides.hairline === true"
                        :label="t('backend.studio.decks.hairline')"
                        :hint="t('backend.studio.decks.hairline_hint')"
                        v-on:update:model-value="(value) => write('hairline', value)"
                    />

                    <AppSelect
                        :model-value="overrides.pattern ?? ''"
                        :options="patternOptions"
                        :label="t('backend.studio.decks.pattern_label')"
                        :hint="t('backend.studio.decks.pattern_hint')"
                        v-on:update:model-value="(value) => write('pattern', value || null)"
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
