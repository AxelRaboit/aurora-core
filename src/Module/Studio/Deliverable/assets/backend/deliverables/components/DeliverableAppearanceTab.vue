<script setup>
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Palette, PanelTop, Sparkles, Type } from "lucide-vue-next";
import { toast } from "vue-sonner";
import { openDocumentPicker } from "@/shared/utils/documentPicker.js";
import { paletteFromImage } from "../composables/logoPalette.js";
import AppChoiceRow from "@/shared/components/form/select/AppChoiceRow.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import { highlightModeOptions } from "@configuration/backend/themes/highlightModes.js";
import BannerColorField from "../../../../../../Editorial/assets/backend/posts/components/BannerColorField.vue";

/**
 * L'habit d'un livrable : ses couleurs, par-dessus celles du thème.
 *
 * Chaque couleur vide veut dire « celle du thème », jamais « aucune » : un
 * livrable qui ne change que son en-tête garde le fond et le pied du site.
 * Un document aux couleurs du client se fait ici, sans toucher au thème.
 *
 * L'aperçu en tête est indicatif : il montre l'accord des couleurs entre
 * elles. La page elle-même, par « Aperçu », montre le rendu exact.
 */
const appearance = defineModel("appearance", { type: Object, required: true });

defineProps({
    title: { type: String, default: "" },
    summary: { type: String, default: "" },
});

const { t } = useI18n();

function set(key, value) {
    appearance.value = { ...appearance.value, [key]: value || null };
}

/**
 * Whole looks in one click, each a set of the colours below: the site's own,
 * a white report like a slide deck, a dark one. Applying one only sets
 * colours and the heading style; everything else stays as chosen.
 */
const PRESETS = [
    {
        key: "theme",
        swatches: ["var(--color-surface, #fff)", "var(--color-accent-500, #10b981)"],
        values: { backgroundColor: null, headerColor: null, footerColor: null, accentColor: null, headingColor: null, figureColor: null, headingStyle: "theme" },
    },
    {
        key: "report",
        swatches: ["#ffffff", "#111111"],
        values: { backgroundColor: "#ffffff", headerColor: "#ffffff", footerColor: "#ffffff", headingColor: null, headingStyle: "display" },
    },
    {
        key: "night",
        swatches: ["#0f1115", "#e5e7eb"],
        values: { backgroundColor: "#0f1115", headerColor: "#0f1115", footerColor: "#0f1115", headingColor: null, headingStyle: "display" },
    },
];

function applyPreset(preset) {
    appearance.value = { ...appearance.value, ...preset.values };
}

const readingLogo = ref(false);

/**
 * A client's colours, read off their logo: the main one for the accent and
 * the figures, the second for the headings, on a white page.
 */
async function fromLogo() {
    const picked = await openDocumentPicker({ imagesOnly: true });
    const url = picked?.fileUrl ?? picked?.url ?? null;
    if (!url) return;

    readingLogo.value = true;
    try {
        const [main, second] = await paletteFromImage(url);
        if (!main) {
            toast.error(t("backend.studio.deliverables.appearance.presets.logo_failed"));

            return;
        }

        appearance.value = {
            ...appearance.value,
            backgroundColor: "#ffffff",
            headerColor: "#ffffff",
            footerColor: "#ffffff",
            accentColor: main,
            figureColor: main,
            headingColor: second ?? null,
            headingStyle: "display",
        };
        toast.success(t("backend.studio.deliverables.appearance.presets.logo_applied"));
    } finally {
        readingLogo.value = false;
    }
}

const PAGE_COLORS = [
    { key: "backgroundColor", labelKey: "background", hintKey: "background_hint" },
    { key: "headerColor", labelKey: "header", hintKey: "header_hint" },
    { key: "footerColor", labelKey: "footer", hintKey: "footer_hint" },
];

const CONTENT_COLORS = [
    { key: "accentColor", labelKey: "accent", hintKey: "accent_hint" },
    { key: "headingColor", labelKey: "heading", hintKey: "heading_hint" },
    { key: "figureColor", labelKey: "figure", hintKey: "figure_hint" },
];

const highlightOptions = computed(() => [
    { value: "", label: t("backend.studio.deliverables.appearance.highlight_inherit") },
    ...highlightModeOptions(t),
]);

const highlight = computed({
    get: () => appearance.value.highlight ?? "",
    set: (value) => {
        appearance.value = {
            ...appearance.value,
            highlight: value || null,
            highlightColor: "custom" === value ? appearance.value.highlightColor : null,
        };
    },
});

/** Les grands titres : ceux du thème, ou en capitales grasses. */
const headingStyleOptions = computed(() =>
    ["theme", "display"].map((value) => ({
        value,
        label: t(`backend.studio.deliverables.appearance.heading_styles.${value}`),
    })),
);

const headingStyle = computed({
    get: () => appearance.value.headingStyle ?? "theme",
    set: (value) => {
        appearance.value = { ...appearance.value, headingStyle: value };
    },
});

/** Page web ou présentation. */
const displayOptions = computed(() =>
    ["page", "slides"].map((value) => ({
        value,
        label: t(`backend.studio.deliverables.appearance.displays.${value}`),
    })),
);

const display = computed({
    get: () => appearance.value.display ?? "page",
    set: (value) => {
        appearance.value = { ...appearance.value, display: value };
    },
});

const readerPdf = computed({
    get: () => true === appearance.value.readerPdf,
    set: (value) => {
        appearance.value = { ...appearance.value, readerPdf: value };
    },
});

const titleVisible = computed({
    get: () => false !== appearance.value.titleVisible,
    set: (value) => {
        appearance.value = { ...appearance.value, titleVisible: value };
    },
});

/**
 * Un texte lisible sur ce fond : sombre sur clair, clair sur sombre. Rien
 * quand le fond est celui du thème : le texte du thème va déjà avec.
 */
function inkOn(hex) {
    if (!hex) return null;

    const value = Number.parseInt(hex.slice(1), 16);
    const [r, g, b] = [(value >> 16) & 255, (value >> 8) & 255, value & 255];
    const luminance = (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;

    return luminance > 0.6 ? "#111827" : "#f9fafb";
}

/** Les variables du thème, quand le livrable n'a pas choisi. */
const swatch = computed(() => ({
    headerInk: inkOn(appearance.value.headerColor),
    pageInk: inkOn(appearance.value.backgroundColor),
    footerInk: inkOn(appearance.value.footerColor),
    background: appearance.value.backgroundColor ?? "var(--color-surface, #ffffff)",
    header: appearance.value.headerColor ?? "var(--color-surface-2, #f4f4f5)",
    footer: appearance.value.footerColor ?? "var(--color-surface-2, #f4f4f5)",
    accent: appearance.value.accentColor ?? "var(--color-accent-500, #10b981)",
    heading: appearance.value.headingColor ?? "inherit",
    figure: appearance.value.figureColor ?? appearance.value.accentColor ?? "currentColor",
}));
</script>

<template>
    <div class="space-y-4">
        <!-- Une ambiance entière d'un clic ; les réglages ci-dessous restent
             là pour l'ajuster. -->
        <section class="aurora-card space-y-3 p-3 sm:p-5">
            <div>
                <h3 class="m-0 flex items-center gap-2 text-sm font-semibold text-primary">
                    <Sparkles class="h-4 w-4 text-muted" :stroke-width="2" /> {{ t("backend.studio.deliverables.appearance.presets.title") }}
                </h3>
                <p class="m-0 mt-0.5 text-xs text-muted">{{ t("backend.studio.deliverables.appearance.presets.hint") }}</p>
            </div>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <button
                    v-for="preset in PRESETS"
                    :key="preset.key"
                    type="button"
                    class="flex flex-col items-start gap-2 rounded-lg border border-line bg-surface p-3 text-left transition-colors hover:border-accent"
                    v-on:click="applyPreset(preset)"
                >
                    <span class="flex gap-1" aria-hidden="true">
                        <span v-for="(colour, index) in preset.swatches" :key="index" class="h-5 w-5 rounded-full border border-line" :style="{ background: colour }" />
                    </span>
                    <span class="text-sm font-medium text-primary">{{ t(`backend.studio.deliverables.appearance.presets.${preset.key}`) }}</span>
                </button>
                <button
                    type="button"
                    class="flex flex-col items-start gap-2 rounded-lg border border-dashed border-line bg-surface p-3 text-left transition-colors hover:border-accent disabled:opacity-60"
                    :disabled="readingLogo"
                    v-on:click="fromLogo"
                >
                    <span class="flex gap-1" aria-hidden="true">
                        <span class="h-5 w-5 rounded-full border border-line bg-gradient-to-br from-rose-400 to-indigo-500" />
                    </span>
                    <span class="text-sm font-medium text-primary">{{ t("backend.studio.deliverables.appearance.presets.logo") }}</span>
                </button>
            </div>
        </section>

        <!-- L'accord des couleurs, d'un coup d'œil. -->
        <div class="aurora-card space-y-3 p-3 sm:p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="m-0 text-sm font-semibold text-primary">{{ t("backend.studio.deliverables.appearance.preview_title") }}</h3>
                <p class="m-0 text-xs text-muted">{{ t("backend.studio.deliverables.appearance.preview_hint") }}</p>
            </div>
            <div class="overflow-hidden rounded-lg border border-line text-left" aria-hidden="true">
                <div class="px-4 py-2 text-xs font-medium text-secondary" :style="{ background: swatch.header, color: swatch.headerInk }">
                    {{ t("backend.studio.deliverables.appearance.sample_header") }}
                </div>
                <div class="space-y-3 px-4 py-5 text-primary" :style="{ background: swatch.background, color: swatch.pageInk }">
                    <p class="m-0 text-lg font-semibold" :style="appearance.headingColor ? { color: swatch.heading } : {}">
                        {{ title || t("backend.studio.deliverables.appearance.sample_title") }}
                    </p>
                    <p v-if="summary" class="m-0 text-xs opacity-80">{{ summary }}</p>
                    <div class="flex flex-wrap items-center gap-4">
                        <span class="text-2xl font-bold tabular-nums" :style="{ color: swatch.figure }">+18 %</span>
                        <span class="rounded-md px-3 py-1.5 text-xs font-medium text-white" :style="{ background: swatch.accent }">
                            {{ t("backend.studio.deliverables.appearance.sample_button") }}
                        </span>
                    </div>
                </div>
                <div class="px-4 py-2 text-xs text-muted" :style="{ background: swatch.footer, color: swatch.footerInk }">
                    {{ t("backend.studio.deliverables.appearance.sample_footer") }}
                </div>
            </div>
        </div>

        <section class="aurora-card space-y-4 p-3 sm:p-5">
            <div>
                <h3 class="m-0 flex items-center gap-2 text-sm font-semibold text-primary">
                    <PanelTop class="h-4 w-4 text-muted" :stroke-width="2" /> {{ t("backend.studio.deliverables.appearance.page_title") }}
                </h3>
                <p class="m-0 mt-0.5 text-xs text-muted">{{ t("backend.studio.deliverables.appearance.page_hint") }}</p>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <BannerColorField
                    v-for="entry in PAGE_COLORS"
                    :key="entry.key"
                    :model-value="appearance[entry.key]"
                    :label="t(`backend.studio.deliverables.appearance.${entry.labelKey}`)"
                    :hint="t(`backend.studio.deliverables.appearance.${entry.hintKey}`)"
                    v-on:update:model-value="set(entry.key, $event)"
                />
            </div>
        </section>

        <section class="aurora-card space-y-4 p-3 sm:p-5">
            <div>
                <h3 class="m-0 flex items-center gap-2 text-sm font-semibold text-primary">
                    <Palette class="h-4 w-4 text-muted" :stroke-width="2" /> {{ t("backend.studio.deliverables.appearance.content_title") }}
                </h3>
                <p class="m-0 mt-0.5 text-xs text-muted">{{ t("backend.studio.deliverables.appearance.content_hint") }}</p>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <BannerColorField
                    v-for="entry in CONTENT_COLORS"
                    :key="entry.key"
                    :model-value="appearance[entry.key]"
                    :label="t(`backend.studio.deliverables.appearance.${entry.labelKey}`)"
                    :hint="t(`backend.studio.deliverables.appearance.${entry.hintKey}`)"
                    v-on:update:model-value="set(entry.key, $event)"
                />
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <AppSelect
                    v-model="highlight"
                    :label="t('backend.studio.deliverables.appearance.highlight')"
                    :options="highlightOptions"
                />
                <BannerColorField
                    v-if="'custom' === highlight"
                    :model-value="appearance.highlightColor"
                    :label="t('backend.studio.deliverables.appearance.highlight_color')"
                    v-on:update:model-value="set('highlightColor', $event)"
                />
            </div>
        </section>

        <section class="aurora-card space-y-3 p-3 sm:p-5">
            <h3 class="m-0 flex items-center gap-2 text-sm font-semibold text-primary">
                <Type class="h-4 w-4 text-muted" :stroke-width="2" /> {{ t("backend.studio.deliverables.appearance.title_section") }}
            </h3>
            <AppToggle
                v-model="titleVisible"
                :label="t('backend.studio.deliverables.appearance.title_visible')"
                :hint="t('backend.studio.deliverables.appearance.title_visible_hint')"
            />
            <AppChoiceRow
                v-model="display"
                :label="t('backend.studio.deliverables.appearance.display')"
                :hint="t('backend.studio.deliverables.appearance.display_hint')"
                :options="displayOptions"
            />
            <AppToggle
                v-model="readerPdf"
                :label="t('backend.studio.deliverables.appearance.reader_pdf')"
                :hint="t('backend.studio.deliverables.appearance.reader_pdf_hint')"
            />
            <AppChoiceRow
                v-model="headingStyle"
                :label="t('backend.studio.deliverables.appearance.heading_style')"
                :hint="t('backend.studio.deliverables.appearance.heading_style_hint')"
                :options="headingStyleOptions"
            />
        </section>
    </div>
</template>
