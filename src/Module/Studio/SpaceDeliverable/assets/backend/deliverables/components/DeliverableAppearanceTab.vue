<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Palette, PanelTop, Type } from "lucide-vue-next";
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
    { value: "", label: t("backend.studio.space_deliverables.appearance.highlight_inherit") },
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
        <!-- L'accord des couleurs, d'un coup d'œil. -->
        <div class="aurora-card space-y-3 p-3 sm:p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="m-0 text-sm font-semibold text-primary">{{ t("backend.studio.space_deliverables.appearance.preview_title") }}</h3>
                <p class="m-0 text-xs text-muted">{{ t("backend.studio.space_deliverables.appearance.preview_hint") }}</p>
            </div>
            <div class="overflow-hidden rounded-lg border border-line text-left" aria-hidden="true">
                <div class="px-4 py-2 text-xs font-medium text-secondary" :style="{ background: swatch.header, color: swatch.headerInk }">
                    {{ t("backend.studio.space_deliverables.appearance.sample_header") }}
                </div>
                <div class="space-y-3 px-4 py-5 text-primary" :style="{ background: swatch.background, color: swatch.pageInk }">
                    <p class="m-0 text-lg font-semibold" :style="appearance.headingColor ? { color: swatch.heading } : {}">
                        {{ title || t("backend.studio.space_deliverables.appearance.sample_title") }}
                    </p>
                    <p v-if="summary" class="m-0 text-xs opacity-80">{{ summary }}</p>
                    <div class="flex flex-wrap items-center gap-4">
                        <span class="text-2xl font-bold tabular-nums" :style="{ color: swatch.figure }">+18 %</span>
                        <span class="rounded-md px-3 py-1.5 text-xs font-medium text-white" :style="{ background: swatch.accent }">
                            {{ t("backend.studio.space_deliverables.appearance.sample_button") }}
                        </span>
                    </div>
                </div>
                <div class="px-4 py-2 text-xs text-muted" :style="{ background: swatch.footer, color: swatch.footerInk }">
                    {{ t("backend.studio.space_deliverables.appearance.sample_footer") }}
                </div>
            </div>
        </div>

        <section class="aurora-card space-y-4 p-3 sm:p-5">
            <div>
                <h3 class="m-0 flex items-center gap-2 text-sm font-semibold text-primary">
                    <PanelTop class="h-4 w-4 text-muted" :stroke-width="2" /> {{ t("backend.studio.space_deliverables.appearance.page_title") }}
                </h3>
                <p class="m-0 mt-0.5 text-xs text-muted">{{ t("backend.studio.space_deliverables.appearance.page_hint") }}</p>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <BannerColorField
                    v-for="entry in PAGE_COLORS"
                    :key="entry.key"
                    :model-value="appearance[entry.key]"
                    :label="t(`backend.studio.space_deliverables.appearance.${entry.labelKey}`)"
                    :hint="t(`backend.studio.space_deliverables.appearance.${entry.hintKey}`)"
                    v-on:update:model-value="set(entry.key, $event)"
                />
            </div>
        </section>

        <section class="aurora-card space-y-4 p-3 sm:p-5">
            <div>
                <h3 class="m-0 flex items-center gap-2 text-sm font-semibold text-primary">
                    <Palette class="h-4 w-4 text-muted" :stroke-width="2" /> {{ t("backend.studio.space_deliverables.appearance.content_title") }}
                </h3>
                <p class="m-0 mt-0.5 text-xs text-muted">{{ t("backend.studio.space_deliverables.appearance.content_hint") }}</p>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <BannerColorField
                    v-for="entry in CONTENT_COLORS"
                    :key="entry.key"
                    :model-value="appearance[entry.key]"
                    :label="t(`backend.studio.space_deliverables.appearance.${entry.labelKey}`)"
                    :hint="t(`backend.studio.space_deliverables.appearance.${entry.hintKey}`)"
                    v-on:update:model-value="set(entry.key, $event)"
                />
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <AppSelect
                    v-model="highlight"
                    :label="t('backend.studio.space_deliverables.appearance.highlight')"
                    :options="highlightOptions"
                />
                <BannerColorField
                    v-if="'custom' === highlight"
                    :model-value="appearance.highlightColor"
                    :label="t('backend.studio.space_deliverables.appearance.highlight_color')"
                    v-on:update:model-value="set('highlightColor', $event)"
                />
            </div>
        </section>

        <section class="aurora-card space-y-3 p-3 sm:p-5">
            <h3 class="m-0 flex items-center gap-2 text-sm font-semibold text-primary">
                <Type class="h-4 w-4 text-muted" :stroke-width="2" /> {{ t("backend.studio.space_deliverables.appearance.title_section") }}
            </h3>
            <AppToggle
                v-model="titleVisible"
                :label="t('backend.studio.space_deliverables.appearance.title_visible')"
                :hint="t('backend.studio.space_deliverables.appearance.title_visible_hint')"
            />
        </section>
    </div>
</template>
