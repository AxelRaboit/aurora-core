import { ref, reactive, computed } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

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

// Mirror of ThemeFontEnum::default(). The list of families arrives from the
// server: this is the only enum value the form needs to know before having
// received anything.
const DEFAULT_FONT_FAMILY = "sora";

// The original colors of the callouts, the ones in content-blocks.css; kept
// in sync by useThemesEdit.test.js. The theme only stores the ones it changes,
// under `callout_<type>_color`, and ThemeStyleRenderer::calloutCss() applies them.
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
            label: t("suite.themes.sections.general"),
            vars: [
                { key: "--th-accent", label: t("suite.themes.vars.accent") },
                {
                    key: "--th-accent-hover",
                    label: t("suite.themes.vars.accent_hover"),
                },
                { key: "--th-bg", label: t("suite.themes.vars.bg") },
                {
                    key: "--th-surface",
                    label: t("suite.themes.vars.surface"),
                },
                {
                    key: "--th-surface-2",
                    label: t("suite.themes.vars.surface2"),
                },
                {
                    key: "--th-primary",
                    label: t("suite.themes.vars.primary"),
                },
                {
                    key: "--th-secondary",
                    label: t("suite.themes.vars.secondary"),
                },
                { key: "--th-muted", label: t("suite.themes.vars.muted") },
            ],
        },
        {
            key: "header",
            label: t("suite.themes.sections.header"),
            vars: [
                { key: "--th-header-bg", label: t("suite.themes.vars.bg") },
                {
                    key: "--th-header-border",
                    label: t("suite.themes.vars.border"),
                },
                {
                    key: "--th-header-text",
                    label: t("suite.themes.vars.text"),
                },
            ],
        },
        {
            key: "footer",
            label: t("suite.themes.sections.footer"),
            vars: [
                { key: "--th-footer-bg", label: t("suite.themes.vars.bg") },
                {
                    key: "--th-footer-border",
                    label: t("suite.themes.vars.border"),
                },
                {
                    key: "--th-footer-text",
                    label: t("suite.themes.vars.text"),
                },
            ],
        },
    ]);

    const ALL_CSS_VARS = computed(() =>
        CSS_SECTIONS.value.flatMap((section) => section.vars),
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
            Object.entries(extraFields).map(([key, definition]) => [
                key,
                definition.default,
            ]),
        ),
    });
    const colorFields = reactive(
        Object.fromEntries(
            Object.keys(DEFAULTS).map((cssVariable) => [cssVariable, ""]),
        ),
    );
    const footerText = ref("");
    // Picked in the library like every other image of the back-office, not
    // typed as a number: `{ id, url }`, the shape AppImagePickerField speaks.
    const headerLogo = ref({ id: null, url: null });
    const headerCustomText = ref("");
    const headerMode = ref("default");
    // Logo only on phone: the site name goes below `sm`, the logo stays.
    // Stored only if requested, and only with a logo (without it, the bar
    // would have nothing left to show).
    const headerTextHiddenOnPhone = ref(false);
    const contentWidth = ref("narrow");
    // The reading bar of the public site: shown by default, so stored only
    // when turned off.
    const readingProgress = ref(true);
    // The "Aurora" credit in the footer: hidden by default, so stored only
    // when turned on.
    const watermarkVisible = ref(false);
    const highlight = ref("accent");
    const highlightColor = ref(DEFAULT_PRIMARY_COLOR);
    const menuActive = ref("accent");
    const menuActiveColor = ref(DEFAULT_PRIMARY_COLOR);
    // Card pictograms: `original` keeps each SVG in its own color (nothing is
    // stored), `accent` follows the main color, `custom` takes iconColor.
    // Stored in a single key, `icon_color` = "accent" or a hex.
    const iconMode = ref("original");
    const iconColor = ref(DEFAULT_PRIMARY_COLOR);
    const fontFamily = ref(DEFAULT_FONT_FAMILY);
    const primaryColor = ref(DEFAULT_PRIMARY_COLOR);

    // Surface colors of the public frontend. Empty by default, and that is
    // the point: a surface without a color emits no CSS rule, so it keeps the
    // historical look. On the server side, SurfaceContrast derives from each
    // one the set of text and border colors that makes it readable.
    // text_color and line_color are not surfaces but apply to them: the color
    // of the text and of the rules, applied on top of what each surface
    // derives from them. Same storage, same empty default.
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
        Object.fromEntries(SURFACE_KEYS.map((surfaceKey) => [surfaceKey, ""])),
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
        if (watermarkVisible.value) result["watermark_visible"] = true;
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
        for (const [key, definition] of Object.entries(extraFields)) {
            editForm[key] = definition.fromEntity
                ? definition.fromEntity(theme)
                : (theme[key] ?? definition.default);
        }
        for (const { key } of ALL_CSS_VARS.value) {
            colorFields[key] = theme.config?.[key] ?? DEFAULTS[key];
        }
        footerText.value = theme.config?.["footer_text"] ?? "";
        contentWidth.value = theme.config?.["content_width"] ?? "narrow";
        readingProgress.value = theme.config?.["reading_progress"] !== "hidden";
        watermarkVisible.value = theme.config?.["watermark_visible"] === true;
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
        toast.success(t("suite.themes.updated"));
    }

    return {
        contentWidth,
        readingProgress,
        watermarkVisible,
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
