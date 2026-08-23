/**
 * The two conversions between a picker and the wire.
 *
 * They exist as functions with tests rather than as three lines inside a modal
 * because they are where a calendar gets timezones wrong. The rule, in one
 * sentence: the wire carries instants, the picker shows wall clocks, and nothing
 * in between is allowed to guess.
 *
 * Before this, the form sent `2026-09-01T10:00` with no offset. PHP read it as
 * UTC, stored it, and served it back as `10:00+00:00` - which a browser in Paris
 * renders as 12:00. Typing a time and reopening the event showed a different
 * one.
 */

/**
 * A picker value (`YYYY-MM-DDTHH:mm`, local wall clock) as an instant.
 *
 * `new Date(local)` is the conversion: a datetime string with no offset is read
 * in the browser's own zone, which is exactly the zone the reader typed in.
 *
 * @param {string} local
 * @returns {string|null} an ISO instant, or null if there is nothing to convert
 */
export function toInstant(local) {
    if (!local) return null;

    const date = new Date(local);

    return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

/**
 * An instant as a picker value, in the reader's own zone.
 *
 * Built field by field from the local getters rather than by slicing
 * `toISOString()`, which would hand back UTC and shift every time by the
 * reader's offset - the same defect in the other direction.
 *
 * @param {string|null} iso
 * @returns {string} `YYYY-MM-DDTHH:mm`, or an empty string
 */
export function toPickerValue(iso) {
    if (!iso) return "";

    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return "";

    const pad = (n) => String(n).padStart(2, "0");

    return (
        `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
        `T${pad(date.getHours())}:${pad(date.getMinutes())}`
    );
}
