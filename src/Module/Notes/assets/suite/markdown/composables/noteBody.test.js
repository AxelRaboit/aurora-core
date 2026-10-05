import { describe, expect, it } from "vitest";
import { withoutLeadingTitle } from "./noteBody.js";

describe("withoutLeadingTitle", () => {
    it("drops the heading that repeats the note's own title", () => {
        expect(
            withoutLeadingTitle(
                "# Studio Lumen\n\nDeux séances.",
                "Studio Lumen",
            ),
        ).toBe("Deux séances.");
    });

    it("does not care about case or stray spaces", () => {
        expect(
            withoutLeadingTitle(
                "#   studio lumen  \n\nTexte.",
                " Studio Lumen ",
            ),
        ).toBe("Texte.");
    });

    /** Un vrai titre de document, différent du nom de la note, reste. */
    it("keeps a heading that says something else", () => {
        const body = "# Résumé\n\nDeux séances.";

        expect(withoutLeadingTitle(body, "Studio Lumen")).toBe(body);
    });

    it("leaves a body that starts with anything else alone", () => {
        const body = "Deux séances par an.\n\n# Détail";

        expect(withoutLeadingTitle(body, "Studio Lumen")).toBe(body);
    });

    /** Un sous-titre n'est pas le titre : `##` ne se retire pas. */
    it("only touches a first-level heading", () => {
        const body = "## Studio Lumen\n\nTexte.";

        expect(withoutLeadingTitle(body, "Studio Lumen")).toBe(body);
    });

    it("keeps everything when the note has no title yet", () => {
        const body = "# Sans titre\n\nTexte.";

        expect(withoutLeadingTitle(body, "")).toBe(body);
        expect(withoutLeadingTitle(body, null)).toBe(body);
    });

    it("survives an empty body", () => {
        expect(withoutLeadingTitle("", "Studio Lumen")).toBe("");
        expect(withoutLeadingTitle(null, "Studio Lumen")).toBe("");
    });

    /** Une note qui n'est que son titre devient vide, et c'est correct. */
    it("empties a note that is only its own title", () => {
        expect(withoutLeadingTitle("# Studio Lumen", "Studio Lumen")).toBe("");
    });
});
