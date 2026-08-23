import { onBeforeUnmount, ref } from "vue";

/**
 * The zone the whole calendar screen is drawn in.
 *
 * **One zone for the screen, not one per calendar**, and that is a constraint
 * rather than a simplification: a grid shows several calendars at once and a
 * "Tuesday" column cannot be Tuesday in two zones. Google answers this the same
 * way - the calendars carry their own zones for defining events, and the view is
 * drawn in a single display zone.
 *
 * The grids do their arithmetic with `Date` and its local getters, which is what
 * makes them readable. So instead of teaching every comparison about zones, an
 * event's instant is rewritten as a wall clock in the display zone and handed over
 * as a string `Date` parses locally. All the existing arithmetic then works, and
 * the conversion lives in exactly two places instead of forty.
 *
 * The cost is real and worth naming: a shifted value is a lie about which instant
 * it is, so it must never travel back to the server. Everything that writes goes
 * through `fromDisplay` first, and the events keep their true instants under
 * `realStartAt` and `realEndAt` so nothing has to reconstruct them.
 */

const STORAGE_KEY = "aurora.planning.displayZone";

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

function pad(n) {
    return String(n).padStart(2, "0");
}

/** The reader's own zone, which is what the screen uses until they change it. */
export function viewerZone() {
    return Intl.DateTimeFormat().resolvedOptions().timeZone;
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

    const p = partsIn(at, zone);

    return `${p.year}-${pad(p.month)}-${pad(p.day)}T${pad(p.hour)}:${pad(p.minute)}:${pad(p.second)}`;
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
        const p = partsIn(new Date(instant), zone);

        return (
            Date.UTC(p.year, p.month - 1, p.day, p.hour, p.minute, p.second) -
            instant
        );
    };

    const guess = asUtc - offsetAt(asUtc);

    return new Date(asUtc - offsetAt(guess)).toISOString();
}

/**
 * Rewrites one row's dates as display wall clocks, keeping the true instants.
 *
 * @param {object} row
 * @param {string} zone
 * @param {string[]} fields
 */
export function toDisplayRow(row, zone, fields) {
    const shifted = { ...row };

    for (const field of fields) {
        if (row[field]) {
            // Kept under a name nothing draws with, so anything that writes has the
            // real instant to hand rather than having to convert back.
            shifted[`real${field[0].toUpperCase()}${field.slice(1)}`] =
                row[field];
            shifted[field] = toDisplay(row[field], zone);
        }
    }

    return shifted;
}

/**
 * The chosen display zone, remembered per browser.
 *
 * Per browser and not per account, because it is a property of where the reader is
 * sitting rather than of who they are - somebody who travels wants the zone to
 * follow the laptop, not the login. And not in the URL, because you do not send
 * anybody your timezone when you send them a week.
 */
export function useDisplayZone() {
    const stored =
        typeof window !== "undefined"
            ? window.localStorage?.getItem(STORAGE_KEY)
            : null;
    const zone = ref(isKnownZone(stored) ? stored : viewerZone());

    function setZone(next) {
        if (!isKnownZone(next)) {
            return;
        }

        zone.value = next;
        window.localStorage?.setItem(STORAGE_KEY, next);
    }

    // Nothing to tear down, but the hook keeps the shape of the other composables
    // here so a future listener has an obvious place to be removed from.
    onBeforeUnmount(() => {});

    return { zone, setZone };
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
