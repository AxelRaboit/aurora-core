import { describe, expect, it } from "vitest";
import { shareEditorModes, shareEditorView } from "./shareEditorView.js";

describe("shareEditorView", () => {
    it("shows the field alone in edit mode", () => {
        expect(shareEditorView("edit", false)).toEqual({
            mode: "edit",
            showEditor: true,
            showPreview: false,
        });
    });

    it("shows the rendered text alone in preview mode", () => {
        expect(shareEditorView("preview", false)).toEqual({
            mode: "preview",
            showEditor: false,
            showPreview: true,
        });
    });

    it("shows both side by side in split mode on a wide screen", () => {
        expect(shareEditorView("split", false)).toEqual({
            mode: "split",
            showEditor: true,
            showPreview: true,
        });
    });

    it("falls back to edit when split is asked for on a narrow screen", () => {
        expect(shareEditorView("split", true)).toEqual({
            mode: "edit",
            showEditor: true,
            showPreview: false,
        });
    });

    it("keeps preview on a narrow screen", () => {
        expect(shareEditorView("preview", true).showPreview).toBe(true);
    });
});

describe("shareEditorModes", () => {
    it("offers the three modes on a wide screen", () => {
        expect(shareEditorModes(false)).toEqual(["edit", "split", "preview"]);
    });

    it("drops split on a narrow screen", () => {
        expect(shareEditorModes(true)).toEqual(["edit", "preview"]);
    });
});
