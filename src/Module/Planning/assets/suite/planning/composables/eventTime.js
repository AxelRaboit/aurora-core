/**
 * The two conversions between a picker and the wire.
 *
 * They exist as functions with tests rather than as three lines inside a modal
 * because they are where a calendar gets timezones wrong. The rule, in one
 * sentence: the wire carries instants, the picker shows a wall clock in the
 * calendar's own zone, and nothing in between is allowed to guess.
 *
 * Two defects live behind that sentence. The form used to send
 * `2026-09-01T10:00` with no offset at all, which PHP read as UTC and served
 * back as `10:00+00:00` - so a browser in Paris drew it at 12:00 and typing a
 * time meant reopening the event to a different one. And once that was fixed the
 * wall clock was still the browser's: a calendar set to Europe/Paris, edited
 * from another zone, recorded 10:00 in the editor's zone rather than in the
 * calendar's, which is not what "10:00" means on a calendar that carries a
 * timezone.
 */

/** What `Intl` needs to hand back a wall clock we can read digit by digit. */
const WALL_CLOCK_PARTS = {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false,
};

function pad(n) {
    return String(n).padStart(2, "0");
}

/**
 * The picker's contract: a date, a T, and a time.
 *
 * Matched rather than left to `new Date`, whose parsing is lenient enough to be
 * dangerous - `new Date("pas une date:00Z")` is not NaN in Node, it is New
 * Year's Eve 1999. A test caught that, and only in the named-zone path, because
 * that path appends the seconds and the Z itself.
 */
const PICKER_SHAPE = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/;

/**
 * The wall clock an instant reads as in a zone, as numbers.
 *
 * `Intl` is the only thing in the platform that knows what Europe/Paris was doing
 * on a given date, daylight saving included. Going through it is what makes this
 * correct in October, when a fixed offset is wrong for half the month.
 */
function partsIn(instant, zone) {
    const parts = new Intl.DateTimeFormat("en-CA", {
        timeZone: zone,
        ...WALL_CLOCK_PARTS,
    })
        .formatToParts(instant)
        .reduce((all, part) => ({ ...all, [part.type]: part.value }), {});

    return {
        year: Number(parts.year),
        month: Number(parts.month),
        day: Number(parts.day),
        // 24-hour formatting says "24" for midnight in some locales.
        hour: Number(parts.hour) % 24,
        minute: Number(parts.minute),
        second: Number(parts.second),
    };
}

/**
 * How far a zone was from UTC at a given instant, in milliseconds.
 *
 * Read rather than looked up: format the instant in the zone, then read those
 * digits back as though they were UTC. The gap between that and the real instant
 * is the offset that applied.
 */
function offsetAt(instant, zone) {
    const p = partsIn(instant, zone);
    const asUtc = Date.UTC(
        p.year,
        p.month - 1,
        p.day,
        p.hour,
        p.minute,
        p.second,
    );

    return asUtc - instant.getTime();
}

/**
 * A picker value (`YYYY-MM-DDTHH:mm`) read as a wall clock in `zone`.
 *
 * Two passes, and the second is the daylight-saving correction. The first guess
 * uses the offset that applies to the wall clock read as UTC, which is the wrong
 * side of a clock change by an hour; applying that guess and asking again gives
 * the offset that actually applies at the resulting instant.
 *
 * @param {string} local
 * @param {string} [zone] an IANA name; the browser's own zone when absent
 * @returns {string|null} an ISO instant, or null if there is nothing to convert
 */
export function toInstant(local, zone) {
    if (!local || !PICKER_SHAPE.test(local)) return null;

    if (!zone) {
        const date = new Date(local);

        return Number.isNaN(date.getTime()) ? null : date.toISOString();
    }

    const asUtc = new Date(`${16 === local.length ? `${local}:00` : local}Z`);
    if (Number.isNaN(asUtc.getTime())) return null;

    const guess = new Date(asUtc.getTime() - offsetAt(asUtc, zone));
    const corrected = new Date(asUtc.getTime() - offsetAt(guess, zone));

    return corrected.toISOString();
}

/**
 * An instant as a picker value, on the wall clock of `zone`.
 *
 * Built from parts rather than by slicing `toISOString()`, which would hand back
 * UTC and shift every time by the zone's offset - the same defect in the other
 * direction.
 *
 * @param {string|null} iso
 * @param {string} [zone] an IANA name; the browser's own zone when absent
 * @returns {string} `YYYY-MM-DDTHH:mm`, or an empty string
 */
export function toPickerValue(iso, zone) {
    if (!iso) return "";

    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return "";

    if (!zone) {
        return (
            `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
            `T${pad(date.getHours())}:${pad(date.getMinutes())}`
        );
    }

    const p = partsIn(date, zone);

    return `${p.year}-${pad(p.month)}-${pad(p.day)}T${pad(p.hour)}:${pad(p.minute)}`;
}

/**
 * Whether a zone is telling a different time from the reader's own right now.
 *
 * Used to decide whether the form has to name the zone. Saying "Europe/Paris"
 * under a field a reader in Paris is filling in is noise; leaving it out when
 * they are somewhere else lets them enter the wrong time and see no reason why.
 *
 * @param {string} [zone]
 * @param {Date} [now]
 * @returns {boolean}
 */
export function zoneDiffersFromViewer(zone, now = new Date()) {
    if (!zone) return false;

    const viewer = Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (zone === viewer) return false;

    // Compared by what they read, not by name: Europe/Paris and Europe/Brussels
    // are different names for the same clock, and naming one under the other's
    // field would explain nothing.
    return offsetAt(now, zone) !== offsetAt(now, viewer);
}
