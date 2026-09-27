import { describe, expect, it } from "vitest";
import { familyMembers, labelSwatch } from "./familyLabels.js";

describe("labelSwatch", () => {
    it("gives a dot to a colour name, in any of the three languages", () => {
        expect(labelSwatch("jaune")).toBe("#e0a21a");
        expect(labelSwatch("Yellow")).toBe("#e0a21a");
        expect(labelSwatch(" amarillo ")).toBe("#e0a21a");
    });

    it("shows the house style's variants as its violet", () => {
        expect(labelSwatch("arc")).toBe("#8b6cff");
    });

    it("reads a colour through its accents", () => {
        expect(labelSwatch("Bleu")).toBe("#0093ed");
    });

    it("leaves a label that is not a colour to be read as text", () => {
        expect(labelSwatch("en")).toBeNull();
        expect(labelSwatch("téléphone")).toBeNull();
        expect(labelSwatch(null)).toBeNull();
    });
});

describe("familyMembers", () => {
    it("puts the original first, then its alternates", () => {
        const members = familyMembers({
            id: 1,
            title: "Carte",
            thumbnailUrl: "/a.png",
            usageCount: 2,
            alternates: [
                {
                    id: 2,
                    label: "jaune",
                    thumbnailUrl: "/b.png",
                    usageCount: 0,
                },
            ],
        });

        expect(members.map((m) => m.id)).toEqual([1, 2]);
        expect(members[0].original).toBe(true);
        expect(members[1].label).toBe("jaune");
    });

    it("has no members for a document without alternates", () => {
        expect(familyMembers({ id: 1, alternates: [] })).toEqual([]);
    });
});
