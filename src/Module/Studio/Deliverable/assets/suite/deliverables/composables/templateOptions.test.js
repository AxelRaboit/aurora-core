import { describe, expect, it } from "vitest";
import { templateOptions } from "./templateOptions.js";

/**
 * The "Partir d'un modèle" selector: only templates, once each, by title,
 * with their category so that the dialog picks it up.
 */
describe("templateOptions", () => {
    it("keeps the templates only, sorted by title, each with its category", () => {
        const options = templateOptions([
            { id: 1, title: "Proposition à Fabre", template: false },
            {
                id: 2,
                title: "Stratégie type",
                template: true,
                category: { id: 7 },
            },
            { id: 3, title: "Audit type", template: true, category: null },
        ]);

        expect(options).toEqual([
            { value: 3, label: "Audit type", categoryId: null },
            { value: 2, label: "Stratégie type", categoryId: 7 },
        ]);
    });

    it("lists a template once, even when it shows in both lists", () => {
        const audit = { id: 3, title: "Audit type", template: true };

        expect(templateOptions([audit, { ...audit }])).toHaveLength(1);
    });

    it("keeps the templates of the format asked for, a row without one being a page", () => {
        const rows = [
            { id: 1, title: "Audit type", template: true },
            {
                id: 2,
                title: "Lancement type",
                template: true,
                format: "slides",
            },
            { id: 3, title: "Bilan type", template: true, format: "page" },
        ];

        expect(
            templateOptions(rows, "slides").map((option) => option.value),
        ).toEqual([2]);
        expect(
            templateOptions(rows, "page").map((option) => option.value),
        ).toEqual([1, 3]);
        expect(templateOptions(rows)).toHaveLength(3);
    });

    it("is empty without a template", () => {
        expect(templateOptions([{ id: 1, title: "Brouillon" }])).toEqual([]);
    });
});
