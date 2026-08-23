import { computed } from "vue";
import { COLUMNS, placeZones } from "./usePostGrid.js";

/**
 * Where the canvas draws everything: the zones, the holes between them, and the
 * strips that open a row.
 *
 * Pure geometry, derived from the zones and the snap and nothing else. Pulled
 * out of `PostGridCanvas.vue` because it is the kind of thing the SFC convention
 * names outright - computed values derived from several sources - and because
 * arithmetic that decides where boxes land should be testable without mounting a
 * component.
 *
 * **The canvas lays zones on every *other* grid track.** `placeZones` numbers
 * rows 1, 2, 3; `styleOf` emits 2, 4, 6, leaving the odd tracks free for the
 * strips that sit between rows. The page is untouched - it emits the walk's own
 * numbers, and only this drawing doubles them.
 *
 * @param {import("vue").Ref<Array<object>>} zones the arrangement, in order
 * @param {import("vue").Ref<number>} snap the step widths are rounded to
 */
export function usePostGridPlacement(zones, snap) {
    const placements = computed(() => placeZones(zones.value));

    function widthOf(index) {
        return zones.value[index]?.span?.lg ?? COLUMNS;
    }

    /** The column a zone begins on, zero-based - what its left edge is holding. */
    function startOf(index) {
        return (placements.value[index]?.column ?? 1) - 1;
    }

    /**
     * One zone's placement, as the custom properties `.aurora-grid` reads.
     *
     * All three go into the `-base` slot for the same reason the width does: the
     * real chain only applies the large-screen values above a 1024px *viewport*,
     * and this panel is usually read in a narrower window. What an author edits
     * is the large-screen arrangement, so the canvas draws that one at any panel
     * width.
     */
    function styleOf(index) {
        const at = placements.value[index];

        return {
            "--span-base": widthOf(index),
            "--row-base": at ? at.row * 2 : "auto",
            "--start-base": at?.column ?? "auto",
        };
    }

    /**
     * The empty stretches of each row, each one an offer to put something there.
     *
     * A row that is not full has a hole in it, and without this the only way to
     * fill one was to add a zone at the end and drag it back.
     *
     * Two kinds of hole, and both are safe to fill without moving anything else.
     * A hole *before* a zone can only exist because that zone asked for a
     * column, so it stays where it asked to be. A hole at the *end* of a row is
     * space the next zone already declined - it is elsewhere because it broke
     * the row or because it did not fit - and taking more of that room cannot
     * change either answer.
     *
     * Holes narrower than the step are left alone: a zone that thin is not one
     * anyone is placing, and a target that small between two boxes is a place to
     * click by accident.
     */
    const rowGaps = computed(() => {
        const rows = new Map();

        placements.value.forEach((place, index) => {
            const start = place.column - 1;
            const row = rows.get(place.row) ?? [];

            row.push({ index, start, end: start + widthOf(index) });
            rows.set(place.row, row);
        });

        const gaps = [];

        rows.forEach((onThisRow, row) => {
            const sorted = [...onThisRow].sort((a, b) => a.start - b.start);
            let cursor = 0;

            sorted.forEach((zone) => {
                if (zone.start - cursor >= snap.value) {
                    gaps.push({
                        row,
                        start: cursor,
                        width: zone.start - cursor,
                        target: zone.index,
                    });
                }

                cursor = Math.max(cursor, zone.end);
            });

            if (COLUMNS - cursor >= snap.value) {
                gaps.push({
                    row,
                    start: cursor,
                    width: COLUMNS - cursor,
                    target: sorted[sorted.length - 1].index + 1,
                });
            }
        });

        return gaps;
    });

    /**
     * The strips between the rows, each one an offer to open a row there.
     *
     * They live on the odd tracks the doubling above leaves free, which is what
     * makes them land exactly in the gaps rather than being positioned to look
     * as though they do.
     *
     * A strip carries the place in the order a zone added there would take, and
     * whether it needs a break to hold its row: the first sits above everything,
     * where there is no row to break out of.
     *
     * There is no strip for an *empty* row, because there is no such thing to
     * make. A row is what zones put on it - it appears with the first and goes
     * when the last leaves, so one can never be left behind empty and never
     * needs deleting.
     */
    const rowStrips = computed(() => {
        const strips = [{ track: 1, target: 0, newRow: false }];
        let seen = 0;

        placements.value.forEach((place) => {
            seen += 1;
            strips[place.row] = {
                track: place.row * 2 + 1,
                target: seen,
                newRow: true,
            };
        });

        return strips.filter(Boolean);
    });

    return { placements, widthOf, startOf, styleOf, rowGaps, rowStrips };
}
