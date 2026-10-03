import { reactive } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

export const PALETTE_MODES = ["light", "dark"];
const STORAGE_KEY = "backend_palette";
const STYLE_ELEMENT_ID = "aurora-backend-palette";

// Les familles et leurs paliers viennent du serveur (BackendPalette::FAMILIES),
// posés dans la page par backend_globals : une seule source pour le CSS émis
// et pour l'aperçu.
export function useBackendPalette({
    updatePath,
    config = window.__auroraConfig?.backendPalette,
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

    // La couleur d'un jeton tant qu'on ne l'a pas retouchée : le palier de la
    // famille pour un gris, le défaut de theme.css pour une couleur d'état.
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

    // Une couleur ramenée sur celle de la famille n'est plus une retouche :
    // elle suivra la famille si on en change.
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
        toast.success(t("backend.settings.saved"));
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
