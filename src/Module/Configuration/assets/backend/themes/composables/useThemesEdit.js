import { ref, reactive, computed } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

const DEFAULTS = {
    "--th-accent": "#6366f1",
    "--th-accent-hover": "#4f46e5",
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

const DEFAULT_PRIMARY_COLOR = "#6366f1";

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
    const headerLogoMediaId = ref("");
    const headerCustomText = ref("");
    const headerMode = ref("default");
    const contentWidth = ref("narrow");
    const primaryColor = ref(DEFAULT_PRIMARY_COLOR);

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
        if (headerMode.value === "image" && headerLogoMediaId.value.trim()) {
            result["header_logo_media_id"] = headerLogoMediaId.value.trim();
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
        headerLogoMediaId.value = theme.config?.["header_logo_media_id"] ?? "";
        headerCustomText.value = theme.config?.["header_custom_text"] ?? "";
        headerMode.value = theme.config?.["header_logo_media_id"]
            ? "image"
            : theme.config?.["header_custom_text"]
              ? "text"
              : "default";
        primaryColor.value =
            theme.config?.["primary_color"] ?? DEFAULT_PRIMARY_COLOR;
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
        CSS_SECTIONS,
        DEFAULTS,
        editModal,
        editForm,
        colorFields,
        footerText,
        headerLogoMediaId,
        headerCustomText,
        headerMode,
        primaryColor,
        openEdit,
        resetPrimaryColor,
        submitEdit,
    };
}
