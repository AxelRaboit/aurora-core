import { describe, it, expect } from "vitest";
import {
    useSlashCommands,
    parseSlashQuery,
    buildTable,
} from "./useSlashCommands.js";

// Stub t() so labels mirror the production translations enough to exercise
// the substring-on-label filter. Anything not listed here falls back to the
// raw key, which is fine - we only assert against listed commands.
const LABELS = {
    h1: "Heading 1",
    h2: "Heading 2",
    h3: "Heading 3",
    bullet: "Bullet list",
    bold: "Bold",
    table: "Table",
    code: "Code block",
};

function makeT() {
    return (key) => {
        const id = key.replace(/^notes\.markdown\.slash\./, "");
        return LABELS[id] ?? id;
    };
}

function buildSlash() {
    return useSlashCommands({ t: makeT() });
}

/**
 * Mock textarea (no jsdom render). The composable only reads `value` +
 * `selectionStart`; positionDropdown is patched out because it uses real
 * DOM rects.
 */
function makeEvent(text, caret) {
    return {
        target: { value: text, selectionStart: caret },
    };
}

describe("useSlashCommands", () => {
    it("opens when a line starts with /", () => {
        const slash = buildSlash();
        // bypass DOM-dependent positioning
        slash.slashPosition.value = { top: 0, left: 0 };
        // Intercept positionDropdown via a fake textarea getComputedStyle -
        // simpler: call onInput with a stub event and accept the body=null
        // patch by patching document.body briefly via try/catch.
        try {
            slash.onInput(makeEvent("/h", 2));
        } catch {
            // jsdom may complain about computed styles on a plain object;
            // we only care that detection ran before the mirror append.
        }
        expect(slash.showSlash.value).toBe(true);
        expect(slash.filteredCommands.value.length).toBeGreaterThan(0);
    });

    it("filters commands by label substring", () => {
        const slash = buildSlash();
        try {
            slash.onInput(makeEvent("/head", 5));
        } catch {
            /* ignore */
        }
        const ids = slash.filteredCommands.value.map((c) => c.id);
        expect(ids).toContain("h1");
        expect(ids).toContain("h2");
        expect(ids).not.toContain("table");
    });

    it("filters by command id (e.g. 'h2')", () => {
        const slash = buildSlash();
        try {
            slash.onInput(makeEvent("/h2", 3));
        } catch {
            /* ignore */
        }
        expect(slash.filteredCommands.value[0].id).toBe("h2");
    });

    it("closes when the line stops starting with /", () => {
        const slash = buildSlash();
        try {
            slash.onInput(makeEvent("/h", 2));
        } catch {
            /* ignore */
        }
        slash.onInput(makeEvent("hello", 5));
        expect(slash.showSlash.value).toBe(false);
    });

    it("navigates with ArrowDown/ArrowUp", () => {
        const slash = buildSlash();
        try {
            slash.onInput(makeEvent("/h", 2));
        } catch {
            /* ignore */
        }
        const evt = { key: "ArrowDown", preventDefault: () => {} };
        slash.onKeydown(evt);
        expect(slash.slashIndex.value).toBe(1);
        slash.onKeydown({ key: "ArrowUp", preventDefault: () => {} });
        expect(slash.slashIndex.value).toBe(0);
    });

    it("returns the selected command on Enter", () => {
        const slash = buildSlash();
        try {
            slash.onInput(makeEvent("/h1", 3));
        } catch {
            /* ignore */
        }
        const picked = slash.onKeydown({
            key: "Enter",
            preventDefault: () => {},
        });
        expect(picked?.id).toBe("h1");
    });

    it("closes on Escape", () => {
        const slash = buildSlash();
        try {
            slash.onInput(makeEvent("/h", 2));
        } catch {
            /* ignore */
        }
        slash.onKeydown({ key: "Escape", preventDefault: () => {} });
        expect(slash.showSlash.value).toBe(false);
    });

    it("applyCommand replaces the /query slice with the insert template", () => {
        const slash = buildSlash();
        try {
            slash.onInput(makeEvent("foo\n/h1", 7));
        } catch {
            /* ignore */
        }
        const command = slash.filteredCommands.value.find((c) => c.id === "h1");
        const textarea = { value: "foo\n/h1", selectionStart: 7 };
        const { newContent, newCaret } = slash.applyCommand(
            textarea,
            command,
            "foo\n/h1",
        );
        expect(newContent).toBe("foo\n# ");
        expect(newCaret).toBe("foo\n# ".length);
    });

    it("opens for / after a whitespace mid-line", () => {
        const slash = buildSlash();
        try {
            slash.onInput(makeEvent("hello /h", 8));
        } catch {
            /* ignore */
        }
        expect(slash.showSlash.value).toBe(true);
        const ids = slash.filteredCommands.value.map((c) => c.id);
        expect(ids).toContain("h1");
    });

    it("does not open for / inside a word (URL, path)", () => {
        const slash = buildSlash();
        try {
            slash.onInput(makeEvent("see http://example", 18));
        } catch {
            /* ignore */
        }
        expect(slash.showSlash.value).toBe(false);

        try {
            slash.onInput(makeEvent("foo/bar", 7));
        } catch {
            /* ignore */
        }
        expect(slash.showSlash.value).toBe(false);
    });

    it("closes when a space appears in the query", () => {
        const slash = buildSlash();
        try {
            slash.onInput(makeEvent("/h ", 3));
        } catch {
            /* ignore */
        }
        expect(slash.showSlash.value).toBe(false);
    });

    it("applyCommand splices in place mid-line", () => {
        const slash = buildSlash();
        const content = "hello /h1";
        try {
            slash.onInput(makeEvent(content, content.length));
        } catch {
            /* ignore */
        }
        const command = slash.filteredCommands.value.find((c) => c.id === "h1");
        const textarea = { value: content, selectionStart: content.length };
        const { newContent, newCaret } = slash.applyCommand(
            textarea,
            command,
            content,
        );
        expect(newContent).toBe("hello # ");
        expect(newCaret).toBe("hello # ".length);
    });

    it("applyCommand honors cursorOffset for inline commands (bold)", () => {
        const slash = buildSlash();
        try {
            slash.onInput(makeEvent("/bold", 5));
        } catch {
            /* ignore */
        }
        const command = slash.filteredCommands.value.find(
            (c) => c.id === "bold",
        );
        const textarea = { value: "/bold", selectionStart: 5 };
        const { newContent, newCaret } = slash.applyCommand(
            textarea,
            command,
            "/bold",
        );
        expect(newContent).toBe("****");
        expect(newCaret).toBe(2);
    });
});

describe("the table command", () => {
    function open(text) {
        const slash = useSlashCommands({
            t: (key, params) =>
                key === "notes.markdown.slash.table_column"
                    ? `Column ${params.n}`
                    : makeT()(key),
        });
        try {
            slash.onInput(makeEvent(text, text.length));
        } catch {
            /* positioning needs a real DOM */
        }
        return slash;
    }

    function apply(slash, text) {
        const command = slash.filteredCommands.value.find(
            (c) => c.id === "table",
        );
        return slash.applyCommand(
            { value: text, selectionStart: text.length },
            command,
            text,
        );
    }

    it("parses a size typed after the command", () => {
        expect(parseSlashQuery("tableau 3x4")).toEqual({
            word: "tableau",
            hasSizePart: true,
            size: { cols: 3, rows: 4 },
        });
        expect(parseSlashQuery("tableau 3x")).toEqual({
            word: "tableau",
            hasSizePart: true,
            size: null,
        });
        expect(parseSlashQuery("tableau")).toEqual({
            word: "tableau",
            hasSizePart: false,
            size: null,
        });
        expect(parseSlashQuery("tableau hello")).toBeNull();
    });

    it("clamps an oversized table", () => {
        expect(parseSlashQuery("t 99x99").size).toEqual({ cols: 10, rows: 50 });
    });

    it("stays open while a size is typed, and only offers the table", () => {
        const slash = open("/tab 3x4");
        expect(slash.showSlash.value).toBe(true);
        expect(slash.filteredCommands.value.map((c) => c.id)).toEqual([
            "table",
        ]);
        expect(slash.filteredCommands.value[0].label).toBe("Table 3 × 4");
    });

    it("still closes on a space after a command that takes no size", () => {
        expect(open("/bold 3").showSlash.value).toBe(false);
    });

    it("inserts the typed size, with blank cells", () => {
        const slash = open("/tab 2x3");
        const { newContent } = apply(slash, "/tab 2x3");
        expect(newContent).toBe(
            "| Column 1 | Column 2 |\n| --- | --- |\n|  |  |\n|  |  |\n|  |  |\n",
        );
    });

    it("inserts 3 x 3 by default and selects the first header", () => {
        const slash = open("/table");
        const { newContent, newCaret, newCaretEnd } = apply(slash, "/table");
        expect(newContent.split("\n").filter(Boolean)).toHaveLength(5);
        expect(newContent.slice(newCaret, newCaretEnd)).toBe("Column 1");
    });

    it("builds the table text on its own", () => {
        const table = buildTable({ cols: 1, rows: 1 }, (n) => `C${n}`);
        expect(table.text).toBe("| C1 |\n| --- |\n|  |\n");
        expect([table.selectStart, table.selectEnd]).toEqual([2, 4]);
    });
});
