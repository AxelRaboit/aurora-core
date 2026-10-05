import { onBeforeUnmount, ref } from "vue";
import {
    fromDisplay,
    isKnownZone,
    toDisplay,
} from "@/shared/utils/format/zonedTime.js";

// The conversions moved to a shared helper once the date picker needed them
// too; re-exported so the calendar's imports keep working.
export { fromDisplay, isKnownZone, toDisplay };

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

/** The reader's own zone, which is what the screen uses until they change it. */
export function viewerZone() {
    return Intl.DateTimeFormat().resolvedOptions().timeZone;
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
