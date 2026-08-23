import { beforeEach, describe, expect, it } from "vitest";
import {
    registerSearchSection,
    searchSectionKeys,
    searchSections,
} from "./searchSectionRegistry.js";

const ICON = { name: "icon" };

/**
 * The registry is module state, so each test starts from what the previous one
 * left. Registering the same keys again is the reset, which is also the property
 * being relied on - HMR does exactly this.
 */
beforeEach(() => {
    for (const section of [...searchSections()]) {
        registerSearchSection({ ...section, icon: ICON });
    }
});

describe("searchSectionRegistry", () => {
    it("keeps what was registered", () => {
        registerSearchSection({
            key: "things",
            kind: "thing",
            labelKey: "a.b",
            icon: ICON,
        });

        expect(searchSectionKeys()).toContain("things");
    });

    it("orders by the order given, then by key", () => {
        registerSearchSection({
            key: "late",
            kind: "late",
            labelKey: "a",
            icon: ICON,
            order: 90,
        });
        registerSearchSection({
            key: "early",
            kind: "early",
            labelKey: "a",
            icon: ICON,
            order: 10,
        });

        const keys = searchSectionKeys();

        expect(keys.indexOf("early")).toBeLessThan(keys.indexOf("late"));
    });

    it("replaces rather than duplicating on a second registration", () => {
        // HMR re-runs the register files, and a duplicated section heading is a
        // confusing way to find that out.
        registerSearchSection({
            key: "once",
            kind: "once",
            labelKey: "a",
            icon: ICON,
        });
        registerSearchSection({
            key: "once",
            kind: "once",
            labelKey: "b",
            icon: ICON,
        });

        expect(
            searchSectionKeys().filter((key) => "once" === key),
        ).toHaveLength(1);
        expect(
            searchSections().find((section) => "once" === section.key).labelKey,
        ).toBe("b");
    });

    it("reports the keys the composable has to copy", () => {
        // The defect this registry exists for: a key nobody wrote into the
        // composable was dropped after the provider had already run.
        registerSearchSection({
            key: "widgets",
            kind: "widget",
            labelKey: "a",
            icon: ICON,
        });

        expect(searchSectionKeys()).toEqual(
            searchSections().map((section) => section.key),
        );
    });
});
