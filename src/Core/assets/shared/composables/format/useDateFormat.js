import { useI18n } from "vue-i18n";
import { siteZone } from "@/shared/utils/format/zonedTime.js";

/**
 * Dates in the reader's language, at the site's time.
 *
 * **Three formats for the suite, and no others** (UI audit of 07/10/2026,
 * which counted seven on as many screens):
 * - `formatDateShort` « 7 oct. 2026 » - a day in a list column or a sentence;
 * - `formatDateTime` « 7 oct., 09:17 » - an instant in a list; the year
 *   shows when it is not the current one (« 7 oct. 2025, 09:17 »);
 * - `formatDate` « 7 octobre 2026 à 09:17 » - an instant on a detail page.
 *
 * The numeric ones stay for the documents that call for them (a client's
 * own screens, an export); the suite does not print « 07/10/2026 ».
 *
 * The zone is the site's (Settings > Localisation) when the page hands it over,
 * so a post scheduled for 09:00 reads 09:00 in the list too, and not the time
 * of the laptop reading it. Pages that do not (public ones) keep the browser's.
 */
export function useDateFormat() {
    const { locale } = useI18n();
    const zone = siteZone() ?? undefined;

    // Only an instant (a value with its offset) moves to the site's time.
    // A bare day (`2026-10-02`) parses as midnight UTC, and read in a zone
    // west of Greenwich would print the day before: it names a day, so it is
    // read in UTC. A bare time (`2026-10-02T09:00`) is already a wall clock -
    // a space's items come in their space's zone - so it prints as it reads.
    const zoneFor = (value) => {
        if (/^\d{4}-\d{2}(-\d{2})?$/.test(value)) return "UTC";
        return /(?:[zZ]|[+-]\d{2}:?\d{2})$/.test(String(value))
            ? zone
            : undefined;
    };

    function formatDate(isoString, placeholder = "-") {
        if (!isoString) return placeholder;
        return new Intl.DateTimeFormat(locale.value, {
            timeZone: zoneFor(isoString),
            day: "numeric",
            month: "long",
            year: "numeric",
            hour: "2-digit",
            minute: "2-digit",
        }).format(new Date(isoString));
    }

    function formatDateShort(isoString, placeholder = "-") {
        if (!isoString) return placeholder;
        return new Intl.DateTimeFormat(locale.value, {
            timeZone: zoneFor(isoString),
            day: "numeric",
            month: "short",
            year: "numeric",
        }).format(new Date(isoString));
    }

    function formatDateTime(isoString, placeholder = "-") {
        if (!isoString) return placeholder;
        const date = new Date(isoString);
        const timeZone = zoneFor(isoString);
        const yearOf = (value) =>
            new Intl.DateTimeFormat("en", { timeZone, year: "numeric" }).format(
                value,
            );

        return new Intl.DateTimeFormat(locale.value, {
            timeZone,
            day: "numeric",
            month: "short",
            // Without the year, last October reads as this one.
            ...(yearOf(date) === yearOf(new Date()) ? {} : { year: "numeric" }),
            hour: "2-digit",
            minute: "2-digit",
        }).format(date);
    }

    /** The hour alone, « 16:33 » in FR, « 4:33 PM » in EN: to follow a date written out. */
    function formatTime(isoString) {
        return new Intl.DateTimeFormat(locale.value, {
            timeZone: zoneFor(isoString),
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
            timeZone: zoneFor(isoString),
            day: "2-digit",
            month: "2-digit",
            year: "numeric",
        }).format(new Date(isoString));
    }

    /** Numeric date + HH:MM (locale-aware), with placeholder fallback. */
    function formatDateTimeNumeric(isoString, placeholder = "-") {
        if (!isoString) return placeholder;
        return new Intl.DateTimeFormat(locale.value, {
            timeZone: zoneFor(isoString),
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
            timeZone: zoneFor(iso),
            month: "long",
            year: "numeric",
        }).format(new Date(iso));
        return raw.charAt(0).toUpperCase() + raw.slice(1);
    }

    return {
        formatDate,
        formatDateShort,
        formatDateTime,
        formatTime,
        formatDateNumeric,
        formatDateTimeNumeric,
        formatMonthYear,
    };
}
