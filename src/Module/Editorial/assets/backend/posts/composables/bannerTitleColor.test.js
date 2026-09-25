import { describe, it, expect, beforeEach } from "vitest";
import {
    ACCENT,
    clearSelection,
    colourSelection,
    serialise,
} from "./bannerTitleColor.js";

function select(root, text) {
    const node =
        [...root.childNodes].find((n) => n.textContent.includes(text)) ??
        root.firstChild;
    const target = node.nodeType === Node.TEXT_NODE ? node : node.firstChild;
    const start = target.textContent.indexOf(text);
    const range = document.createRange();
    range.setStart(target, start);
    range.setEnd(target, start + text.length);
    const selection = document.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
}

describe("bannerTitleColor", () => {
    let root;

    beforeEach(() => {
        root = document.createElement("div");
        document.body.replaceChildren(root);
    });

    it("colours the selected words with the theme accent", () => {
        root.textContent = "Développeur × Photographe";
        select(root, "×");

        expect(colourSelection(root, ACCENT)).toBe(true);
        expect(serialise(root)).toBe(
            'Développeur <span class="cdx-text-color" style="color: var(--th-accent)">×</span> Photographe',
        );
    });

    it("writes a free colour back as hex, the only form the server keeps", () => {
        root.textContent = "Un titre rose";
        select(root, "rose");
        colourSelection(root, "#ff00aa");

        expect(serialise(root)).toContain('style="color: #ff00aa"');
    });

    it("never nests a colour inside another", () => {
        root.textContent = "Un titre";
        select(root, "titre");
        colourSelection(root, "#ff0000");
        select(root, "titre");
        colourSelection(root, "#00ff00");

        expect(serialise(root).match(/<span/g)).toHaveLength(1);
        expect(serialise(root)).toContain("#00ff00");
    });

    it("removes the colour from the selection", () => {
        root.innerHTML =
            'Un <span class="cdx-text-color" style="color: #ff0000">titre</span>';
        select(root, "titre");

        expect(clearSelection(root)).toBe(true);
        expect(serialise(root)).toBe("Un titre");
    });

    it("does nothing without a selection", () => {
        root.textContent = "Un titre";
        document.getSelection().removeAllRanges();

        expect(colourSelection(root, ACCENT)).toBe(false);
    });

    it("flattens whatever else a contenteditable invents", () => {
        root.innerHTML = "<div>Un <b>titre</b></div><br><div>en deux</div>";

        expect(serialise(root)).toBe("Un titre en deux");
    });
});
