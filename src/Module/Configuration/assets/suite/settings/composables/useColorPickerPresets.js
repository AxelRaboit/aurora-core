import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

const DEFAULT_COLOR_PRESETS = [
    "#ef4444",
    "#f97316",
    "#f59e0b",
    "#eab308",
    "#84cc16",
    "#22c55e",
    "#10b981",
    "#14b8a6",
    "#06b6d4",
    "#3b82f6",
    "#6366f1",
    "#8b5cf6",
    "#a855f7",
    "#ec4899",
    "#f43f5e",
    "#64748b",
];
const HEX_PATTERN = /^#[0-9a-fA-F]{6}$/;
const STORAGE_KEY = "color_picker_presets";

function parseInitialPresets(rawValue) {
    try {
        const decoded = JSON.parse(rawValue ?? "[]");
        const filtered = Array.isArray(decoded)
            ? decoded.filter((color) => HEX_PATTERN.test(color))
            : [];
        return filtered.length > 0 ? filtered : [...DEFAULT_COLOR_PRESETS];
    } catch {
        return [...DEFAULT_COLOR_PRESETS];
    }
}

export function useColorPickerPresets({ groups, updatePath }) {
    const { t } = useI18n();

    const initialValue =
        groups?.appearance?.find?.((setting) => setting.key === STORAGE_KEY)
            ?.value ?? "[]";

    const presets = ref(parseInitialPresets(initialValue));
    const newColor = ref(null);
    const showAddForm = ref(false);
    const { loading: saving, request } = useRequest();

    const canSave = computed(() => presets.value.length > 0);

    function add() {
        const value = newColor.value;
        if (!value || !HEX_PATTERN.test(value)) {
            toast.error(
                t("suite.settings.appearance.color_presets.invalid_hex"),
            );
            return;
        }
        const normalised = value.toLowerCase();
        if (!presets.value.includes(normalised)) {
            presets.value.push(normalised);
        }
        newColor.value = null;
        showAddForm.value = false;
    }

    function remove(color) {
        presets.value = presets.value.filter((preset) => preset !== color);
    }

    function reset() {
        presets.value = [...DEFAULT_COLOR_PRESETS];
    }

    function openAddForm() {
        showAddForm.value = true;
    }

    function cancelAdd() {
        showAddForm.value = false;
        newColor.value = null;
    }

    async function save() {
        const data = await request(updatePath, {
            key: STORAGE_KEY,
            value: JSON.stringify(presets.value),
        });
        if (data?.success) {
            toast.success(t("suite.settings.saved"));
        } else {
            toast.error(t("shared.common.error"));
        }
    }

    return {
        presets,
        newColor,
        showAddForm,
        saving,
        canSave,
        add,
        remove,
        reset,
        openAddForm,
        cancelAdd,
        save,
    };
}
