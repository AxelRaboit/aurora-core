import { describe, expect, it } from "vitest";
import List from "@editorjs/list";
import Header from "@editorjs/header";
import Quote from "@editorjs/quote";
import Delimiter from "@editorjs/delimiter";

/**
 * Blocks written by PHP must be openable by the tools the editor runs.
 *
 * `BlocksRenderer` accepts every shape an Editor.js tool has ever emitted, so
 * malformed content renders perfectly on the public site and only fails when
 * somebody opens the post in the backend - where Editor.js swallows the error
 * and prints "The block can not be displayed correctly". Nothing throws
 * server-side, no PHP test fails, and the report arrives as a person saying
 * "the list is broken".
 *
 * `EditorBlocks` (PHP) is the single place that writes this JSON. Its unit
 * test checks the keys it emits; this one checks the thing that actually
 * matters - that the installed tool can render them. It is the half a PHP test
 * cannot do, and it is what turns an `@editorjs/*` upgrade that changes a
 * format into a red build rather than a bug report.
 */

/** What Editor.js does internally: a render() that throws is a broken block. */
function renders(ToolClass, data) {
    try {
        const tool = new ToolClass({
            data,
            config: {},
            api: { styles: {}, i18n: { t: (key) => key } },
            readOnly: false,
            block: {},
        });

        return Boolean(tool.render());
    } catch {
        return false;
    }
}

describe("blocks written server-side", () => {
    // Mirrors EditorBlocks::list(). Keep the two in step.
    const list = (items, style = "unordered") => ({
        style,
        meta: {},
        items: items.map((content) => ({ content, meta: {}, items: [] })),
    });

    it("renders a list in the shape EditorBlocks writes", () => {
        expect(renders(List, list(["un", "deux"]))).toBe(true);
        expect(renders(List, list(["un"], "ordered"))).toBe(true);
    });

    /**
     * The shape that caused the bug: items carrying only `content`. Neither
     * the tool's legacy form nor its current one, and the renderer is happy
     * with it - which is exactly why it survived to production.
     */
    it("rejects the half-migrated list shape, so this test can fail", () => {
        expect(
            renders(List, { style: "unordered", items: [{ content: "un" }] }),
        ).toBe(false);
    });

    /** Content written before the tool's v2 format still has to open. */
    it("still renders the tool's legacy string items", () => {
        expect(
            renders(List, { style: "unordered", items: ["un", "deux"] }),
        ).toBe(true);
    });

    it("renders the other blocks EditorBlocks writes", () => {
        expect(renders(Header, { text: "Titre", level: 2 })).toBe(true);
        expect(
            renders(Quote, { text: "Une citation", caption: "Quelqu'un" }),
        ).toBe(true);
        expect(renders(Delimiter, {})).toBe(true);
    });
});
