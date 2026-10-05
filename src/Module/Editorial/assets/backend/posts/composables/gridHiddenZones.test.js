import { describe, expect, it } from "vitest";
import { withoutHiddenTypes } from "./gridHiddenZones.js";

const zone = (id, type, extra = {}) => ({ id, type, ...extra });

describe("withoutHiddenTypes", () => {
    it("passes everything through when the target hides nothing", () => {
        const payload = { zones: [zone("a", "comments")], content: {} };

        expect(withoutHiddenTypes(payload, [])).toEqual({
            payload,
            dropped: 0,
        });
        expect(withoutHiddenTypes(payload, undefined)).toEqual({
            payload,
            dropped: 0,
        });
    });

    it("drops the zones of a hidden type and counts them", () => {
        const { payload, dropped } = withoutHiddenTypes(
            {
                zones: [
                    zone("a", "text"),
                    zone("b", "comments"),
                    zone("c", "form"),
                ],
                content: { a: 1 },
            },
            ["comments", "form"],
        );

        expect(payload.zones.map((entry) => entry.id)).toEqual(["a"]);
        expect(payload.content).toEqual({ a: 1 });
        expect(dropped).toBe(2);
    });

    it("drops hidden zones inside a stack and keeps the stack", () => {
        const { payload, dropped } = withoutHiddenTypes(
            {
                zones: [
                    zone("s", "stack", {
                        children: [zone("x", "text"), zone("y", "shared")],
                    }),
                ],
            },
            ["shared"],
        );

        expect(payload.zones[0].children.map((entry) => entry.id)).toEqual([
            "x",
        ]);
        expect(dropped).toBe(1);
    });

    it("counts a dropped stack's children with it", () => {
        const { payload, dropped } = withoutHiddenTypes(
            {
                zones: [
                    zone("s", "stack", {
                        children: [zone("x", "text"), zone("y", "text")],
                    }),
                ],
            },
            ["stack"],
        );

        expect(payload.zones).toEqual([]);
        expect(dropped).toBe(3);
    });

    it("leaves a payload without zones alone", () => {
        expect(withoutHiddenTypes({}, ["comments"])).toEqual({
            payload: {},
            dropped: 0,
        });
        expect(withoutHiddenTypes(null, ["comments"])).toEqual({
            payload: null,
            dropped: 0,
        });
    });
});
