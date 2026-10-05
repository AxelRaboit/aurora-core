import { describe, it, expect } from "vitest";
import { navigateTableCell } from "./tableNavigation.js";

const TABLE = "| A | B |\n| --- | --- |\n| a1 |  |\n";

/** The text the move selects, or the caret's neighbourhood when empty. */
function selected(move) {
    return move.newContent.slice(move.cursorPos, move.cursorEnd);
}

describe("navigateTableCell", () => {
    it("ignores a caret outside any table", () => {
        expect(navigateTableCell("plain text", 3)).toBeNull();
        expect(navigateTableCell(`intro\n${TABLE}`, 2)).toBeNull();
    });

    it("selects the next cell on the same row", () => {
        const move = navigateTableCell(TABLE, 2);
        expect(selected(move)).toBe("B");
        expect(move.newContent).toBe(TABLE);
    });

    it("jumps over the separator to the next row", () => {
        const move = navigateTableCell(TABLE, TABLE.indexOf("B"));
        expect(selected(move)).toBe("a1");
    });

    it("lands inside an empty cell", () => {
        const move = navigateTableCell(TABLE, TABLE.indexOf("a1"));
        expect(move.cursorPos).toBe(move.cursorEnd);
        expect(move.newContent[move.cursorPos - 2]).toBe("|");
    });

    it("adds a row of the same width after the last cell", () => {
        const lastCell = TABLE.lastIndexOf("|  |") + 2;
        const move = navigateTableCell(TABLE, lastCell);
        expect(move.newContent).toBe(
            "| A | B |\n| --- | --- |\n| a1 |  |\n|  |  |\n",
        );
        expect(move.newContent.slice(move.cursorPos - 2, move.cursorPos)).toBe(
            "| ",
        );
    });

    it("goes back with Shift+Tab, across rows, and stops on the first cell", () => {
        expect(
            selected(navigateTableCell(TABLE, TABLE.indexOf("a1"), true)),
        ).toBe("B");
        expect(
            selected(navigateTableCell(TABLE, TABLE.indexOf("B"), true)),
        ).toBe("A");
        expect(selected(navigateTableCell(TABLE, 2, true))).toBe("A");
    });

    it("keeps escaped pipes inside a cell", () => {
        const table = "| a \\| b | c |\n";
        expect(selected(navigateTableCell(table, 2))).toBe("c");
    });
});
