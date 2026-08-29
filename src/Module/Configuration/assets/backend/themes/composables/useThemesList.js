import { ref } from "vue";

export function useThemesList(initialThemes) {
    const themeList = ref(initialThemes.map((theme) => ({ ...theme })));

    function accentColor(theme) {
        return (
            theme.config?.["primary_color"] ??
            theme.config?.["--th-accent"] ??
            // Mirrors ThemeContext::DEFAULT_PRIMARY_COLOR.
            "#10b981"
        );
    }

    return { themeList, accentColor };
}
