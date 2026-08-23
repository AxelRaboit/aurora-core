import { useI18n } from "vue-i18n";

export function useDateFormat() {
    const { locale } = useI18n();

    function formatDate(isoString) {
        return new Intl.DateTimeFormat(locale.value, {
            day: "numeric",
            month: "long",
            year: "numeric",
            hour: "2-digit",
            minute: "2-digit",
        }).format(new Date(isoString));
    }

    function formatDateShort(isoString) {
        return new Intl.DateTimeFormat(locale.value, {
            day: "numeric",
            month: "short",
            year: "numeric",
        }).format(new Date(isoString));
    }

    function formatDateTime(isoString) {
        return new Intl.DateTimeFormat(locale.value, {
            day: "numeric",
            month: "short",
            hour: "2-digit",
            minute: "2-digit",
        }).format(new Date(isoString));
    }

    /**
     * Strict numeric date (locale-aware): DD/MM/YYYY in FR, MM/DD/YYYY in EN, …
     * Returns the placeholder when the input is empty / null - handy in
     * table cells where we don't want to special-case on every call site.
     */
    function formatDateNumeric(isoString, placeholder = "-") {
        if (!isoString) return placeholder;
        return new Intl.DateTimeFormat(locale.value, {
            day: "2-digit",
            month: "2-digit",
            year: "numeric",
        }).format(new Date(isoString));
    }

    /** Numeric date + HH:MM (locale-aware), with placeholder fallback. */
    function formatDateTimeNumeric(isoString, placeholder = "-") {
        if (!isoString) return placeholder;
        return new Intl.DateTimeFormat(locale.value, {
            day: "2-digit",
            month: "2-digit",
            year: "numeric",
            hour: "2-digit",
            minute: "2-digit",
        }).format(new Date(isoString));
    }

    /**
     * Month + year, capitalised first letter - "Mai 2026" / "May 2026".
     * Accepts either a full ISO string or a "YYYY-MM" prefix (handy for
     * month-pickers / budget switchers that store the month as a key).
     */
    function formatMonthYear(input, placeholder = "-") {
        if (!input) return placeholder;
        const iso = /^\d{4}-\d{2}$/.test(input) ? `${input}-01` : input;
        const raw = new Intl.DateTimeFormat(locale.value, {
            month: "long",
            year: "numeric",
        }).format(new Date(iso));
        return raw.charAt(0).toUpperCase() + raw.slice(1);
    }

    return {
        formatDate,
        formatDateShort,
        formatDateTime,
        formatDateNumeric,
        formatDateTimeNumeric,
        formatMonthYear,
    };
}
