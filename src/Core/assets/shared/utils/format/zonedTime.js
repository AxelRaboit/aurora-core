/**
 * Wall clocks in a named zone, for screens that show and take times in a zone
 * other than the browser's own.
 *
 * Written for the calendar (`displayZone.js`) and shared once the date picker
 * needed the same two conversions: a post scheduled for 09:00 is 09:00 at the
 * site's time, whatever zone the laptop that typed it is set to.
 */

/** What `Intl` needs to hand back a wall clock we can read field by field. */
const PARTS = {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false,
};

function pad(number) {
    return String(number).padStart(2, "0");
}

function partsIn(instant, zone) {
    const parts = new Intl.DateTimeFormat("en-CA", { timeZone: zone, ...PARTS })
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
 * An instant as the wall clock it reads in `zone`, with no offset.
 *
 * `Date` parses a datetime string with no offset in the browser's own zone, so the
 * result is a value whose *local* fields are the display zone's fields - which is
 * exactly what the grids need and nothing else should ever see.
 *
 * @param {string} iso
 * @param {string} zone
 * @returns {string} `YYYY-MM-DDTHH:mm:ss`
 */
export function toDisplay(iso, zone) {
    const at = new Date(iso);
    if (Number.isNaN(at.getTime())) {
        return iso;
    }

    const wallClock = partsIn(at, zone);

    return `${wallClock.year}-${pad(wallClock.month)}-${pad(wallClock.day)}T${pad(wallClock.hour)}:${pad(wallClock.minute)}:${pad(wallClock.second)}`;
}

/**
 * A wall clock in `zone` back to the instant it names.
 *
 * Two passes, for the reason `eventTime.js` needs two: the offset that applies to
 * the wall clock read as UTC is the wrong side of a clock change by an hour, and
 * applying that guess then asking again gives the offset that actually applies.
 *
 * @param {Date|string} local a Date whose local fields are the zone's wall clock
 * @param {string} zone
 * @returns {string} an ISO instant
 */
export function fromDisplay(local, zone) {
    const at = local instanceof Date ? local : new Date(local);
    if (Number.isNaN(at.getTime())) {
        return new Date().toISOString();
    }

    const asUtc = Date.UTC(
        at.getFullYear(),
        at.getMonth(),
        at.getDate(),
        at.getHours(),
        at.getMinutes(),
        at.getSeconds(),
    );

    const offsetAt = (instant) => {
        const wallClock = partsIn(new Date(instant), zone);

        return (
            Date.UTC(
                wallClock.year,
                wallClock.month - 1,
                wallClock.day,
                wallClock.hour,
                wallClock.minute,
                wallClock.second,
            ) - instant
        );
    };

    const guess = asUtc - offsetAt(asUtc);

    return new Date(asUtc - offsetAt(guess)).toISOString();
}

/**
 * Whether a zone name is one this runtime can resolve.
 *
 * A stored name can outlive a browser update or come from another machine, and an
 * unresolvable one makes every `Intl` call throw - which would empty the calendar
 * rather than misdate it.
 */
export function isKnownZone(name) {
    if (!name) {
        return false;
    }

    try {
        new Intl.DateTimeFormat("en", { timeZone: name });

        return true;
    } catch {
        return false;
    }
}

/**
 * The site's zone (Localisation > Timezone), as the back office layout hands
 * it over, or `null` when the page was not given one.
 *
 * @returns {string|null}
 */
export function siteZone() {
    const zone =
        typeof window !== "undefined" ? window.__auroraConfig?.timezone : null;

    return isKnownZone(zone) ? zone : null;
}

/**
 * The offset of `zone` at a given instant, as `+02:00`.
 *
 * @param {string} iso an ISO instant
 * @param {string} zone
 * @returns {string}
 */
export function offsetIn(iso, zone) {
    const at = new Date(iso);
    const wallClock = partsIn(at, zone);
    const minutes = Math.round(
        (Date.UTC(
            wallClock.year,
            wallClock.month - 1,
            wallClock.day,
            wallClock.hour,
            wallClock.minute,
            wallClock.second,
        ) -
            Math.floor(at.getTime() / 1000) * 1000) /
            60000,
    );
    const sign = minutes < 0 ? "-" : "+";
    const absoluteMinutes = Math.abs(minutes);

    return `${sign}${pad(Math.floor(absoluteMinutes / 60))}:${pad(absoluteMinutes % 60)}`;
}
