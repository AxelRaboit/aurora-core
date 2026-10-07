import { describe, expect, it } from "vitest";
import { outlineOf, readingMinutes, wordCount } from "./noteOutline.js";

/**
 * A note's outline and its length.
 *
 * What would break silently: a code `#` counted as a heading, or a heading
 * shown with its syntax (`**gras**`, `[[lien]]`) in the outline.
 */
const BRIEF = [
    "# Brief Atelier Dupont",
    "",
    "Intro du projet, en **deux** phrases.",
    "",
    "## Objectifs",
    "- Plus de devis",
    "- [ ] Revoir le logo",
    "",
    "```bash",
    "# pas un titre",
    "npm run build",
    "```",
    "",
    "### Lien vers [[Tarifs 2024]] et **budget**",
    "## Calendrier ##",
].join("\n");

describe("outlineOf", () => {
    it("lists the headings with their level and line, code left out", () => {
        expect(outlineOf(BRIEF)).toEqual([
            { level: 1, text: "Brief Atelier Dupont", line: 0 },
            { level: 2, text: "Objectifs", line: 4 },
            { level: 3, text: "Lien vers Tarifs 2024 et budget", line: 13 },
            { level: 2, text: "Calendrier", line: 14 },
        ]);
    });

    it("is empty for a note without headings", () => {
        expect(outlineOf("Juste un paragraphe.")).toEqual([]);
        expect(outlineOf(null)).toEqual([]);
    });
});

describe("wordCount", () => {
    it("counts the words read, not the syntax nor the code", () => {
        // "Brief Atelier Dupont" (3) + "Intro du projet, en deux phrases." (6)
        // + "Objectifs" (1) + "Plus de devis" (3) + "Revoir le logo" (3)
        // + "Lien vers Tarifs 2024 et budget" (6) + "Calendrier" (1)
        expect(wordCount(BRIEF)).toBe(23);
    });

    it("gives at least one minute as soon as there is a word", () => {
        expect(readingMinutes(0)).toBe(0);
        expect(readingMinutes(12)).toBe(1);
        expect(readingMinutes(1100)).toBe(5);
    });
});
