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

    // The number in the reader's language: « 42,8 Ko » in French, never
    // « 42.8 Ko ». A round figure drops its decimal (« 1 Ko », not « 1.0 Ko »).
    function number(value, maximumFractionDigits) {
        return new Intl.NumberFormat(locale.value, {
            maximumFractionDigits,
        }).format(value);
    }

    function formatSize(bytes) {
        const units = getUnits(locale.value);
        if (bytes < 1024) return `${number(bytes, 0)} ${units.b}`;
        if (bytes < 1024 * 1024)
            return `${number(bytes / 1024, 1)} ${units.kb}`;
        if (bytes < 1024 * 1024 * 1024)
            return `${number(bytes / 1024 / 1024, 1)} ${units.mb}`;
        return `${number(bytes / 1024 / 1024 / 1024, 2)} ${units.gb}`;
    }

    return { formatSize };
}
