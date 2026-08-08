import { useI18n } from "vue-i18n";
import { useTabState } from "@/shared/composables/useTabState.js";

// Tab order + always-visible flag now come from the backend payload, sourced
// from each ConfigurationTabProvider's contributions. A tab appears iff:
//   - it carries at least one field for the current user, OR
//   - it is marked `alwaysVisible` (custom-UI tabs like navigation/appearance
//     that draw their own content even without persisted fields).

export function useSettingsTabs(groups, tabs = []) {
    const { t } = useI18n();

    const availableGroups = (Array.isArray(tabs) ? tabs : [])
        .filter(
            (tab) => tab.alwaysVisible || (groups?.[tab.id]?.length ?? 0) > 0,
        )
        .map((tab) => tab.id);

    // In the URL, so "the modules tab of the settings" is a link someone can
    // send rather than a place to be walked to.
    const { activeTab, select: selectTab } = useTabState(availableGroups, {
        hash: true,
    });

    function tabLabel(groupName) {
        return t(`backend.settings.tabs.${groupName}`);
    }

    function tabDescription(groupName) {
        return t(`backend.settings.tabs.${groupName}_description`);
    }

    return {
        availableGroups,
        activeTab,
        selectTab,
        tabLabel,
        tabDescription,
    };
}
