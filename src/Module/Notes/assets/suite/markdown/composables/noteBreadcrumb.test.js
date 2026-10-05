import { describe, expect, it } from "vitest";
import { folderPath } from "./noteBreadcrumb.js";

describe("folderPath", () => {
    const folders = [
        { id: 1, name: "Micro-entreprise", parentId: null, color: "#8b6cff" },
        { id: 4, name: "Comptabilité", parentId: 1 },
        { id: 5, name: "Factures", parentId: 4 },
    ];

    it("writes the way down from the root, the highest first", () => {
        expect(folderPath(folders, 5).map((one) => one.name)).toEqual([
            "Micro-entreprise",
            "Comptabilité",
            "Factures",
        ]);
    });

    it("carries the colour a folder wears", () => {
        expect(folderPath(folders, 1)[0].color).toBe("#8b6cff");
    });

    it("is empty at the root", () => {
        expect(folderPath(folders, null)).toEqual([]);
    });

    it("stops on a cycle rather than hanging the page", () => {
        const looped = [
            { id: 1, name: "A", parentId: 2 },
            { id: 2, name: "B", parentId: 1 },
        ];

        expect(folderPath(looped, 1)).toHaveLength(2);
    });
});
