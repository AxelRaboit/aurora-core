/**
 * Opening hours as an author types them, and back.
 *
 * One line per weekday, ranges separated by commas: `9:00-12:30, 14h-18h`.
 * Loose on the way in - a colon, an `h`, a single digit hour, an en dash -
 * and strict on the way out, where GridZoneOptions keeps only `HH:MM` pairs
 * that close after they open.
 */
const RANGE =
    /^(\d{1,2})(?:[:h](\d{2}))?h?\s*[-–—à]\s*(\d{1,2})(?:[:h](\d{2}))?h?$/i;

function clock(hours, minutes) {
    const h = Number(hours);
    const m = Number(minutes ?? 0);

    if (h > 24 || m > 59 || (24 === h && 0 !== m)) {
        return null;
    }

    return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`;
}

/** `"9-12, 14:00-18:30"` to `[["09:00", "12:00"], ["14:00", "18:30"]]`. */
export function parseRanges(text) {
    return String(text ?? "")
        .split(/[,;/]|\bet\b|\band\b/i)
        .map((part) => part.trim())
        .filter(Boolean)
        .map((part) => part.match(RANGE))
        .filter(Boolean)
        .map((match) => [clock(match[1], match[2]), clock(match[3], match[4])])
        .filter(
            ([open, close]) => null !== open && null !== close && open < close,
        );
}

/** The reverse, for the field: `[["09:00", "12:00"]]` to `"09:00-12:00"`. */
export function formatRanges(ranges) {
    return (Array.isArray(ranges) ? ranges : [])
        .map(([open, close]) => `${open}-${close}`)
        .join(", ");
}

/** One value per line, blank lines dropped. */
export function parseLines(text) {
    return String(text ?? "")
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter(Boolean);
}
