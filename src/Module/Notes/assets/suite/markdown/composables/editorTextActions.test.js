import { describe, it, expect } from "vitest";
import {
    inlineTags,
    isPastedAddress,
    markBlock,
    mergeInlineTags,
    moveLines,
} from "./editorTextActions.js";

describe("moveLines", () => {
    const text = "un\ndeux\ntrois";

    it("moves the caret's line up and down, the caret with it", () => {
        const movedUp = moveLines(text, 5, 5, -1);
        expect(movedUp.newContent).toBe("deux\nun\ntrois");
        expect(
            movedUp.newContent.slice(
                movedUp.cursorPos - 1,
                movedUp.cursorPos + 2,
            ),
        ).toBe("eux");

        expect(moveLines(text, 5, 5, 1).newContent).toBe("un\ntrois\ndeux");
    });

    it("moves every selected line together", () => {
        expect(moveLines(text, 0, 6, 1).newContent).toBe("trois\nun\ndeux");
    });

    it("stays put at the edges", () => {
        expect(moveLines(text, 0, 0, -1)).toBeNull();
        expect(moveLines(text, text.length, text.length, 1)).toBeNull();
    });
});

describe("markBlock", () => {
    it("names the paragraph at its last line", () => {
        const marked = markBlock(
            "Une ligne\nla suite\n\nAutre",
            2,
            () => "abc123",
        );
        expect(marked).toEqual({
            newContent: "Une ligne\nla suite ^abc123\n\nAutre",
            id: "abc123",
        });
    });

    it("keeps a name already there", () => {
        expect(markBlock("Déjà ^x1", 1).id).toBe("x1");
    });

    it("names one list item, not the whole list", () => {
        expect(markBlock("- un\n- deux", 1, () => "id1").newContent).toBe(
            "- un ^id1\n- deux",
        );
    });

    it("has nothing to name on an empty line", () => {
        expect(markBlock("a\n\nb", 2)).toBeNull();
    });
});

describe("inline tags", () => {
    it("reads #tags at a word's start, with a letter, outside code", () => {
        expect(
            inlineTags(
                "# Titre\nUn #client et #devis/2026, pas C#, pas #12, pas `#code`",
            ),
        ).toEqual(["client", "devis/2026"]);
    });

    it("adds them to the note's tags without duplicates", () => {
        expect(mergeInlineTags(["Client"], "un #client et #photo")).toEqual([
            "Client",
            "photo",
        ]);
    });
});

describe("isPastedAddress", () => {
    it("knows an address alone from a sentence holding one", () => {
        expect(isPastedAddress(" https://aurora.test/page?x=1 ")).toBe(true);
        expect(isPastedAddress("voir https://aurora.test")).toBe(false);
        expect(isPastedAddress("javascript:alert(1)")).toBe(false);
    });
});
