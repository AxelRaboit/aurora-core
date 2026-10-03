import { ref, reactive, computed } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

const DEFAULTS = {
    "--th-accent": "#10b981",
    "--th-accent-hover": "#059669",
    "--th-bg": "#f9fafb",
    "--th-surface": "#ffffff",
    "--th-surface-2": "#f3f4f6",
    "--th-primary": "#111827",
    "--th-secondary": "#6b7280",
    "--th-muted": "#9ca3af",
    "--th-header-bg": "#ffffff",
    "--th-header-border": "#e5e7eb",
    "--th-header-text": "#111827",
    "--th-footer-bg": "#ffffff",
    "--th-footer-border": "#e5e7eb",
    "--th-footer-text": "#9ca3af",
};

// Mirrors ThemeContext::DEFAULT_PRIMARY_COLOR. Held by
// ThemeDefaultColourMirrorTest: the form showed the old indigo for a while
// after the PHP moved to green, because a second copy of a default drifts the
// moment nothing compares them.
const DEFAULT_PRIMARY_COLOR = "#10b981";

// Miroir de ThemeFontEnum::default(). La liste des familles, elle, arrive du
// serveur : c'est la seule valeur de l'enum que le formulaire a besoin de
// connaitre avant d'avoir recu quoi que ce soit.
const DEFAULT_FONT_FAMILY = "poppins";

// Les couleurs d'origine des encadrés, celles de content-blocks.css ; tenu en
// phase par useThemesEdit.test.js. Le thème ne stocke que celles qu'il change,
// sous `callout_<type>_color`, et ThemeStyleRenderer::calloutCss() les pose.
export const CALLOUT_DEFAULTS = {
    info: "#3b82f6",
    success: "#22c55e",
    warning: "#eab308",
    danger: "#ef4444",
    tip: "#a855f7",
    note: "#64748b",
    question: "#0ea5e9",
    important: "#f97316",
    update: "#14b8a6",
    rose: "#ec4899",
    lime: "#84cc16",
    amber: "#f59e0b",
    fuchsia: "#d946ef",
};

const calloutKey = (type) => `callout_${type}_color`;

/**
 * @typedef {Object} ExtraField
 * @property {*} default - Initial/reset value.
 * @property {(theme: object) => *} fromEntity - Reads field value from existing theme on openEdit.
 */

export function useThemesEdit(themeList, updatePath, options = {}) {
    const { t } = useI18n();
    const extraFields = options.extraFields ?? {};

    const CSS_SECTIONS = computed(() => [
        {
            key: "general",
            label: t("backend.themes.sections.general"),
            vars: [
                { key: "--th-accent", label: t("backend.themes.vars.accent") },
                {
                    key: "--th-accent-hover",
                    label: t("backend.themes.vars.accent_hover"),
                },
                { key: "--th-bg", label: t("backend.themes.vars.bg") },
                {
                    key: "--th-surface",
                    label: t("backend.themes.vars.surface"),
                },
                {
                    key: "--th-surface-2",
                    label: t("backend.themes.vars.surface2"),
                },
                {
                    key: "--th-primary",
                    label: t("backend.themes.vars.primary"),
                },
                {
                    key: "--th-secondary",
                    label: t("backend.themes.vars.secondary"),
                },
                { key: "--th-muted", label: t("backend.themes.vars.muted") },
            ],
        },
        {
            key: "header",
            label: t("backend.themes.sections.header"),
            vars: [
                { key: "--th-header-bg", label: t("backend.themes.vars.bg") },
                {
                    key: "--th-header-border",
                    label: t("backend.themes.vars.border"),
                },
                {
                    key: "--th-header-text",
                    label: t("backend.themes.vars.text"),
                },
            ],
        },
        {
            key: "footer",
            label: t("backend.themes.sections.footer"),
            vars: [
                { key: "--th-footer-bg", label: t("backend.themes.vars.bg") },
                {
                    key: "--th-footer-border",
                    label: t("backend.themes.vars.border"),
                },
                {
                    key: "--th-footer-text",
                    label: t("backend.themes.vars.text"),
                },
            ],
        },
    ]);

    const ALL_CSS_VARS = computed(() =>
        CSS_SECTIONS.value.flatMap((s) => s.vars),
    );

    const editModal = reactive({
        open: false,
        editing: null,
        saving: false,
        errors: {},
        advanced: false,
    });
    const editForm = reactive({
        name: "",
        description: "",
        ...Object.fromEntries(
            Object.entries(extraFields).map(([key, def]) => [key, def.default]),
        ),
    });
    const colorFields = reactive(
        Object.fromEntries(Object.keys(DEFAULTS).map((k) => [k, ""])),
    );
    const footerText = ref("");
    // Picked in the library like every other image of the back-office, not
    // typed as a number: `{ id, url }`, the shape AppImagePickerField speaks.
    const headerLogo = ref({ id: null, url: null });
    const headerCustomText = ref("");
    const headerMode = ref("default");
    // Le logo seul sur téléphone : le nom du site part sous `sm`, le logo
    // reste. Stocké seulement s'il est demandé, et seulement avec un logo
    // (sans lui, la barre n'aurait plus rien à montrer).
    const headerTextHiddenOnPhone = ref(false);
    const contentWidth = ref("narrow");
    // La barre de lecture du site public : affichée par défaut, donc stockée
    // seulement quand on la coupe.
    const readingProgress = ref(true);
    const highlight = ref("accent");
    const highlightColor = ref(DEFAULT_PRIMARY_COLOR);
    const menuActive = ref("accent");
    const menuActiveColor = ref(DEFAULT_PRIMARY_COLOR);
    // Pictogrammes des cartes : `original` garde chaque SVG dans sa couleur
    // (rien n'est stocke), `accent` suit la couleur principale, `custom` prend
    // iconColor. Stocke en une seule cle, `icon_color` = "accent" ou un hex.
    const iconMode = ref("original");
    const iconColor = ref(DEFAULT_PRIMARY_COLOR);
    const fontFamily = ref(DEFAULT_FONT_FAMILY);
    const primaryColor = ref(DEFAULT_PRIMARY_COLOR);

    // Couleurs de surface du frontend public. Vides par defaut, et c'est le
    // point : une surface sans couleur n'emet aucune regle CSS, donc garde
    // l'apparence historique. Cote serveur, SurfaceContrast deduit de chacune
    // le jeu de texte et de bordures qui la rend lisible.
    // text_color et line_color ne sont pas des surfaces mais s'y appliquent :
    // la couleur du texte et des traits, posee par-dessus ce que chaque
    // surface en deduit. Meme stockage, meme vide par defaut.
    const SURFACE_KEYS = [
        "background_color",
        "header_color",
        "footer_color",
        "text_color",
        "line_color",
        "card_line_color",
        "card_color",
        "heading_color",
        "success_color",
        "figure_color",
    ];
    const surfaceColors = reactive(
        Object.fromEntries(SURFACE_KEYS.map((k) => [k, ""])),
    );
    const calloutColors = reactive(
        Object.fromEntries(
            Object.keys(CALLOUT_DEFAULTS).map((type) => [type, ""]),
        ),
    );

    const configFromColors = computed(() => {
        const result = {};
        for (const key of Object.keys(DEFAULTS)) {
            if (colorFields[key] && colorFields[key] !== DEFAULTS[key])
                result[key] = colorFields[key];
        }
        if (footerText.value.trim())
            result["footer_text"] = footerText.value.trim();
        // Only persisted when it differs from the default, like the colours
        // above: an untouched theme keeps an empty config rather than one
        // spelling out every default.
        if (contentWidth.value !== "narrow")
            result["content_width"] = contentWidth.value;
        if (!readingProgress.value) result["reading_progress"] = "hidden";
        if (highlight.value !== "accent") result["highlight"] = highlight.value;
        if (highlight.value === "custom")
            result["highlight_color"] = highlightColor.value;
        if (menuActive.value !== "accent")
            result["menu_active"] = menuActive.value;
        if (menuActive.value === "custom")
            result["menu_active_color"] = menuActiveColor.value;
        if (iconMode.value === "accent") result["icon_color"] = "accent";
        if (iconMode.value === "custom") result["icon_color"] = iconColor.value;
        if (fontFamily.value !== DEFAULT_FONT_FAMILY)
            result["font_family"] = fontFamily.value;
        if (headerMode.value === "image" && headerLogo.value?.id) {
            result["header_logo_media_id"] = String(headerLogo.value.id);
            if (headerTextHiddenOnPhone.value)
                result["header_text_on_phone"] = "hidden";
        }
        if (headerMode.value === "text" && headerCustomText.value.trim()) {
            result["header_custom_text"] = headerCustomText.value.trim();
        }
        if (
            primaryColor.value &&
            primaryColor.value.toLowerCase() !== DEFAULT_PRIMARY_COLOR
        ) {
            result["primary_color"] = primaryColor.value;
        }
        for (const key of SURFACE_KEYS) {
            if (surfaceColors[key]) result[key] = surfaceColors[key];
        }
        for (const type of Object.keys(CALLOUT_DEFAULTS)) {
            if (calloutColors[type])
                result[calloutKey(type)] = calloutColors[type];
        }
        return result;
    });

    const { request } = useRequest();

    function openEdit(theme) {
        editModal.editing = theme;
        editModal.errors = {};
        editModal.advanced = false;
        editForm.name = theme.name;
        editForm.description = theme.description ?? "";
        for (const [key, def] of Object.entries(extraFields)) {
            editForm[key] = def.fromEntity
                ? def.fromEntity(theme)
                : (theme[key] ?? def.default);
        }
        for (const { key } of ALL_CSS_VARS.value) {
            colorFields[key] = theme.config?.[key] ?? DEFAULTS[key];
        }
        footerText.value = theme.config?.["footer_text"] ?? "";
        contentWidth.value = theme.config?.["content_width"] ?? "narrow";
        readingProgress.value = theme.config?.["reading_progress"] !== "hidden";
        highlight.value = theme.config?.["highlight"] ?? "accent";
        fontFamily.value = theme.config?.["font_family"] ?? DEFAULT_FONT_FAMILY;
        const logoId = theme.config?.["header_logo_media_id"];
        headerLogo.value = {
            id: logoId ? Number(logoId) : null,
            url: theme.headerLogoUrl ?? null,
        };
        headerCustomText.value = theme.config?.["header_custom_text"] ?? "";
        headerTextHiddenOnPhone.value =
            "hidden" === theme.config?.["header_text_on_phone"];
        headerMode.value = theme.config?.["header_logo_media_id"]
            ? "image"
            : theme.config?.["header_custom_text"]
              ? "text"
              : "default";
        primaryColor.value =
            theme.config?.["primary_color"] ?? DEFAULT_PRIMARY_COLOR;
        highlightColor.value =
            theme.config?.["highlight_color"] ?? primaryColor.value;
        menuActive.value = theme.config?.["menu_active"] ?? "accent";
        menuActiveColor.value =
            theme.config?.["menu_active_color"] ?? primaryColor.value;
        for (const key of SURFACE_KEYS) {
            surfaceColors[key] = theme.config?.[key] ?? "";
        }
        for (const type of Object.keys(CALLOUT_DEFAULTS)) {
            calloutColors[type] = theme.config?.[calloutKey(type)] ?? "";
        }
        const storedIcon = theme.config?.["icon_color"] ?? "";
        iconMode.value =
            storedIcon === "accent"
                ? "accent"
                : /^#[0-9a-fA-F]{6}$/.test(storedIcon)
                  ? "custom"
                  : "original";
        iconColor.value =
            iconMode.value === "custom" ? storedIcon : primaryColor.value;
        editModal.open = true;
    }

    function resetPrimaryColor() {
        primaryColor.value = DEFAULT_PRIMARY_COLOR;
    }

    async function submitEdit() {
        if (!editModal.editing) return;
        editModal.saving = true;
        editModal.errors = {};
        const url = buildPath(updatePath, { id: editModal.editing.id });
        const data = await request(url, {
            ...Object.fromEntries(
                Object.keys(extraFields).map((key) => [key, editForm[key]]),
            ),
            name: editForm.name,
            description: editForm.description,
            config: configFromColors.value,
        });
        editModal.saving = false;
        if (!data) return;
        if (!data.success) {
            editModal.errors = data.errors ?? {};
            return;
        }
        const index = themeList.value.findIndex(
            (item) => item.id === editModal.editing.id,
        );
        if (index !== -1) themeList.value[index] = data.theme;
        editModal.open = false;
        toast.success(t("backend.themes.updated"));
    }

    return {
        contentWidth,
        readingProgress,
        highlight,
        highlightColor,
        menuActive,
        menuActiveColor,
        fontFamily,
        CSS_SECTIONS,
        DEFAULTS,
        editModal,
        editForm,
        colorFields,
        footerText,
        headerLogo,
        headerCustomText,
        headerTextHiddenOnPhone,
        headerMode,
        primaryColor,
        surfaceColors,
        SURFACE_KEYS,
        calloutColors,
        iconMode,
        iconColor,
        openEdit,
        resetPrimaryColor,
        submitEdit,
    };
}
