import { describe, expect, it } from "vitest";
import { diffStats, lineDiff } from "./noteLineDiff.js";

describe("lineDiff", () => {
    it("keeps the common lines and marks what went and what came", () => {
        const lines = lineDiff(
            "# Brief\nObjectif A\nFin",
            "# Brief\nObjectif B\nFin\nAjout",
        );

        expect(lines).toEqual([
            { kind: "same", text: "# Brief" },
            { kind: "removed", text: "Objectif A" },
            { kind: "added", text: "Objectif B" },
            { kind: "same", text: "Fin" },
            { kind: "added", text: "Ajout" },
        ]);
        expect(diffStats(lines)).toEqual({ added: 2, removed: 1 });
    });

    it("says nothing changed for two equal texts", () => {
        expect(diffStats(lineDiff("a\nb", "a\nb"))).toEqual({
            added: 0,
            removed: 0,
        });
    });

    it("gives up rather than freezing on two huge texts", () => {
        const huge = Array.from({ length: 2100 }, (_, n) => `ligne ${n}`).join(
            "\n",
        );

        expect(lineDiff(huge, `${huge}\nfin`)).toBeNull();
    });
});
