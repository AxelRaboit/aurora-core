import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

export function useThemesDelete(themeList, deletePath) {
    const { t } = useI18n();
    const deletingTheme = ref(null);
    const { request } = useRequest();

    async function confirmDelete() {
        const theme = deletingTheme.value;
        if (!theme) return;
        const url = buildPath(deletePath, { id: theme.id });
        const data = await request(url);
        if (!data) return;
        if (!data.success) {
            toast.error(t(data.error ?? "shared.common.error"));
            deletingTheme.value = null;
            return;
        }
        themeList.value = themeList.value.filter(
            (item) => item.id !== theme.id,
        );
        deletingTheme.value = null;
        toast.success(t("suite.themes.deleted"));
    }

    return { deletingTheme, confirmDelete };
}
