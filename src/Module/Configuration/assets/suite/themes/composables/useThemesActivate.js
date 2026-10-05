import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

export function useThemesActivate(themeList, activatePath) {
    const { t } = useI18n();
    const { request } = useRequest();

    async function activateTheme(theme) {
        const url = buildPath(activatePath, { id: theme.id });
        const data = await request(url);
        if (!data) return;
        if (!data.success) {
            toast.error(t("shared.common.error"));
            return;
        }
        themeList.value = themeList.value.map((item) => ({
            ...item,
            active: item.id === theme.id,
        }));
        toast.success(t("suite.themes.activated"));
    }

    return { activateTheme };
}
