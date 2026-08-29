import { describe, it, expect } from "vitest";
import { handleMarkdownShortcut } from "./useMarkdownShortcuts.js";

function makeEvent(key, { shift = false, ctrl = true, meta = false } = {}) {
    return {
        key,
        shiftKey: shift,
        ctrlKey: ctrl,
        metaKey: meta,
        preventDefault: () => {},
    };
}

function makeTextarea(value, selStart, selEnd = selStart) {
    return { value, selectionStart: selStart, selectionEnd: selEnd };
}

describe("handleMarkdownShortcut", () => {
    it("returns null when no Ctrl/Cmd modifier is held", () => {
        const result = handleMarkdownShortcut(
            makeEvent("b", { ctrl: false }),
            makeTextarea("hello", 0, 5),
            "hello",
        );
        expect(result).toBeNull();
    });

    it("returns null for an unmapped key", () => {
        const result = handleMarkdownShortcut(
            makeEvent("z"),
            makeTextarea("hello", 0, 5),
            "hello",
        );
        expect(result).toBeNull();
    });

    it("Ctrl+B wraps a selection with **bold**", () => {
        const result = handleMarkdownShortcut(
            makeEvent("b"),
            makeTextarea("hello world", 0, 5),
            "hello world",
        );
        expect(result.newContent).toBe("**hello** world");
        expect(result.cursorPos).toBe("**hello**".length);
    });

    it("Ctrl+B with no selection inserts ** ** at caret with caret between", () => {
        const result = handleMarkdownShortcut(
            makeEvent("b"),
            makeTextarea("foo", 3),
            "foo",
        );
        expect(result.newContent).toBe("foo****");
        // caret should be after the first ** (between the two pairs)
        expect(result.cursorPos).toBe("foo**".length);
    });

    it("Ctrl+I wraps with single asterisks", () => {
        const result = handleMarkdownShortcut(
            makeEvent("i"),
            makeTextarea("hi", 0, 2),
            "hi",
        );
        expect(result.newContent).toBe("*hi*");
    });

    it("Ctrl+E wraps with backticks", () => {
        const result = handleMarkdownShortcut(
            makeEvent("e"),
            makeTextarea("code", 0, 4),
            "code",
        );
        expect(result.newContent).toBe("`code`");
    });

    it("Ctrl+Shift+X wraps with ~~ for strikethrough", () => {
        const result = handleMarkdownShortcut(
            makeEvent("x", { shift: true }),
            makeTextarea("nope", 0, 4),
            "nope",
        );
        expect(result.newContent).toBe("~~nope~~");
    });

    it("Ctrl+Shift+K wraps with a fenced code block", () => {
        const result = handleMarkdownShortcut(
            makeEvent("k", { shift: true }),
            makeTextarea("line", 0, 4),
            "line",
        );
        expect(result.newContent).toBe("```\nline\n```");
    });

    it("Ctrl+K wraps a selection as a markdown link with caret on url", () => {
        const result = handleMarkdownShortcut(
            makeEvent("k"),
            makeTextarea("anchor", 0, 6),
            "anchor",
        );
        expect(result.newContent).toBe("[anchor](url)");
        // selection should span "url" so the user can immediately type
        expect(result.cursorPos).toBe("[anchor](".length);
        expect(result.cursorEnd).toBe("[anchor](url".length);
    });

    it("Ctrl+H prepends `# ` to the current line", () => {
        const result = handleMarkdownShortcut(
            makeEvent("h"),
            // caret on second line, after "wo"
            makeTextarea("first\nwo", 8),
            "first\nwo",
        );
        expect(result.newContent).toBe("first\n# wo");
    });

    it("Ctrl+L prepends `- ` to the current line", () => {
        const result = handleMarkdownShortcut(
            makeEvent("l"),
            makeTextarea("item", 4),
            "item",
        );
        expect(result.newContent).toBe("- item");
    });

    it("Ctrl+Shift+L prepends `1. ` for numbered list", () => {
        const result = handleMarkdownShortcut(
            makeEvent("l", { shift: true }),
            makeTextarea("item", 4),
            "item",
        );
        expect(result.newContent).toBe("1. item");
    });

    it("Ctrl+Shift+C prepends `- [ ] ` for a checkbox", () => {
        const result = handleMarkdownShortcut(
            makeEvent("c", { shift: true }),
            makeTextarea("task", 4),
            "task",
        );
        expect(result.newContent).toBe("- [ ] task");
    });

    it("Cmd+B (Mac) behaves like Ctrl+B", () => {
        const result = handleMarkdownShortcut(
            makeEvent("b", { ctrl: false, meta: true }),
            makeTextarea("mac", 0, 3),
            "mac",
        );
        expect(result.newContent).toBe("**mac**");
    });
});
