import { useI18n } from "vue-i18n";

const SIZE_UNITS = {
    fr: { b: "o", kb: "Ko", mb: "Mo", gb: "Go" },
    en: { b: "B", kb: "KB", mb: "MB", gb: "GB" },
    es: { b: "B", kb: "KB", mb: "MB", gb: "GB" },
    de: { b: "B", kb: "KB", mb: "MB", gb: "GB" },
};

function getUnits(locale) {
    const language = (locale ?? "en").split("-")[0].toLowerCase();
    return SIZE_UNITS[language] ?? SIZE_UNITS.en;
}

export function useFileSize() {
    const { locale } = useI18n();

    function formatSize(bytes) {
        const units = getUnits(locale.value);
        if (bytes < 1024) return `${bytes} ${units.b}`;
        if (bytes < 1024 * 1024)
            return `${(bytes / 1024).toFixed(1)} ${units.kb}`;
        if (bytes < 1024 * 1024 * 1024)
            return `${(bytes / 1024 / 1024).toFixed(1)} ${units.mb}`;
        return `${(bytes / 1024 / 1024 / 1024).toFixed(2)} ${units.gb}`;
    }

    return { formatSize };
}
