import { beforeEach, describe, expect, it } from "vitest";
import { queueFlash, takeFlashes } from "./flash.js";

describe("flash", () => {
    beforeEach(() => window.sessionStorage.clear());

    it("keeps a message for the next page and hands it over once", () => {
        queueFlash("success", "Livrable dupliqué.");
        queueFlash("info", "Ouvert");

        expect(takeFlashes()).toEqual([
            { type: "success", message: "Livrable dupliqué." },
            { type: "info", message: "Ouvert" },
        ]);
        expect(takeFlashes()).toEqual([]);
    });

    it("refuses what is not a toast", () => {
        queueFlash("explode", "x");
        queueFlash("success", "");
        queueFlash("success", 12);

        expect(takeFlashes()).toEqual([]);
    });

    it("ignores a corrupted queue instead of throwing", () => {
        window.sessionStorage.setItem("aurora.flash", "{not json");
        expect(takeFlashes()).toEqual([]);

        window.sessionStorage.setItem(
            "aurora.flash",
            JSON.stringify([
                { type: "bad", message: "x" },
                { type: "error", message: "oui" },
                null,
            ]),
        );
        expect(takeFlashes()).toEqual([{ type: "error", message: "oui" }]);
    });
});
