import { describe, expect, it } from "vitest";
import {
    ZONE_ICONS,
    ZONE_TYPES,
} from "@editorial/suite/posts/composables/usePostGrid.js";

/**
 * Every zone an author can add has a picture in the palette.
 *
 * The map used to live in the two components that draw it, and both stopped at
 * the five types that existed when it was written. Everything added since - a
 * button, a separator, a list, an automatic list, a form, a snippet, a summary
 * - arrived in the palette as a bare word beside six that had a glyph, and
 * nothing said so: `<component :is="undefined">` renders nothing at all.
 *
 * Driven off ZONE_TYPES rather than a list of its own, so a type added
 * tomorrow fails here until it is given one.
 */
describe("the grid palette", () => {
    it.each(ZONE_TYPES)("has an icon for %s", (type) => {
        expect(ZONE_ICONS[type]).toBeTruthy();
    });

    /** An icon for a type that no longer exists is a stale entry, not a spare. */
    it("has no icon for a type that is not offered", () => {
        expect(Object.keys(ZONE_ICONS).sort()).toStrictEqual(
            [...ZONE_TYPES].sort(),
        );
    });
});
