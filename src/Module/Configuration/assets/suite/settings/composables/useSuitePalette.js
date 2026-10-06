import { reactive } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

export const PALETTE_MODES = ["light", "dark"];
const STORAGE_KEY = "suite_palette";
const STYLE_ELEMENT_ID = "aurora-suite-palette";

// The families and their steps come from the server (SuitePalette::FAMILIES),
// put in the page by suite_globals: a single source for the emitted CSS and
// for the preview.
export function useSuitePalette({
    updatePath,
    config = window.__auroraConfig?.suitePalette,
}) {
    const { t } = useI18n();
    const families = config?.families ?? {};
    const tokens = config?.tokens ?? {};
    const states = config?.states ?? {};
    const defaultFamily = config?.defaultFamily ?? "gray";

    const palette = reactive(
        Object.fromEntries(
            PALETTE_MODES.map((mode) => [
                mode,
                {
                    family: config?.value?.[mode]?.family ?? defaultFamily,
                    overrides: { ...(config?.value?.[mode]?.overrides ?? {}) },
                },
            ]),
        ),
    );

    const { loading: saving, request } = useRequest();

    // The color of a token as long as it has not been retouched: the family
    // step for a gray, the theme.css default for a state color.
    function familyColor(mode, token) {
        if (token in states) return states[token][mode];
        const step = tokens[token]?.[mode];
        if ("white" === step) return "#ffffff";
        return families[palette[mode].family]?.[step] ?? "#000000";
    }

    function colorOf(mode, token) {
        return palette[mode].overrides[token] ?? familyColor(mode, token);
    }

    function isOverridden(mode, token) {
        return token in palette[mode].overrides;
    }

    // A color brought back to the family's one is no longer a retouch: it
    // will follow the family if the family changes.
    function setColor(mode, token, hex) {
        const value = String(hex ?? "").toLowerCase();
        if (!value || value === familyColor(mode, token)) {
            delete palette[mode].overrides[token];
            return;
        }
        palette[mode].overrides[token] = value;
    }

    function resetColor(mode, token) {
        delete palette[mode].overrides[token];
    }

    function resetMode(mode) {
        palette[mode].family = defaultFamily;
        palette[mode].overrides = {};
    }

    function isDefault(mode) {
        return (
            palette[mode].family === defaultFamily &&
            Object.keys(palette[mode].overrides).length === 0
        );
    }

    async function save() {
        const data = await request(updatePath, {
            key: STORAGE_KEY,
            value: JSON.stringify(palette),
        });
        if (!data?.success) {
            toast.error(t("shared.common.error"));
            return;
        }
        const styleElement = document.getElementById(STYLE_ELEMENT_ID);
        if (styleElement) styleElement.textContent = data.css ?? "";
        toast.success(t("suite.settings.saved"));
    }

    return {
        palette,
        families: Object.keys(families),
        tokens: Object.keys(tokens),
        states: Object.keys(states),
        familyScale: (family) => families[family] ?? {},
        colorOf,
        isOverridden,
        setColor,
        resetColor,
        resetMode,
        isDefault,
        saving,
        save,
    };
}
