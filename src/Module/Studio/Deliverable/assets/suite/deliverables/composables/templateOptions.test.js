import { describe, expect, it } from "vitest";
import { templateOptions } from "./templateOptions.js";

/**
 * Le sélecteur « Partir d'un modèle » : seulement les modèles, une fois chacun,
 * par titre, avec leur catégorie pour que la fenêtre la reprenne.
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

    it("is empty without a template", () => {
        expect(templateOptions([{ id: 1, title: "Brouillon" }])).toEqual([]);
    });
});
