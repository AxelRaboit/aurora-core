import { ref, computed } from "vue";
import { positionFloatingMenu } from "@notes/suite/markdown/composables/positionFloatingMenu.js";

/**
 * Slash-command palette state for the markdown textarea.
 *
 * Detection: typing `/` at the start of a line opens the palette. Anything
 * typed after `/` filters the command list by label or id. Selecting a
 * command (Enter / Tab / click) replaces the `/query` text with the
 * command's `insert` template and positions the caret at `cursorOffset`
 * when defined (otherwise after the inserted snippet).
 *
 * The composable is intentionally view-agnostic: it returns reactive state
 * + handlers that a Vue component wires onto a textarea ref. The palette
 * UI lives in `NoteEditor.vue`.
 *
 * Ported from Onyx (`resources/js/composables/notes/useSlashCommands.js`)
 * with translatable labels and a slimmed-down command set.
 *
 * The table command takes a size: `/tableau 3x4` inserts three columns and
 * four rows, `/tableau` alone the default 3 × 3. Moving between its cells
 * with Tab is `tableNavigation.js`.
 */

const COMMANDS = [
    {
        id: "h1",
        labelKey: "notes.markdown.slash.h1",
        icon: "H1",
        insert: "# ",
        type: "line",
    },
    {
        id: "h2",
        labelKey: "notes.markdown.slash.h2",
        icon: "H2",
        insert: "## ",
        type: "line",
    },
    {
        id: "h3",
        labelKey: "notes.markdown.slash.h3",
        icon: "H3",
        insert: "### ",
        type: "line",
    },
    {
        id: "bullet",
        labelKey: "notes.markdown.slash.bullet",
        icon: "•",
        insert: "- ",
        type: "line",
    },
    {
        id: "numbered",
        labelKey: "notes.markdown.slash.numbered",
        icon: "1.",
        insert: "1. ",
        type: "line",
    },
    {
        id: "checkbox",
        labelKey: "notes.markdown.slash.checkbox",
        icon: "☐",
        insert: "- [ ] ",
        type: "line",
    },
    {
        id: "quote",
        labelKey: "notes.markdown.slash.quote",
        icon: "❝",
        insert: "> ",
        type: "line",
    },
    {
        id: "divider",
        labelKey: "notes.markdown.slash.divider",
        icon: "-",
        insert: "\n---\n",
        type: "block",
    },
    {
        id: "code",
        labelKey: "notes.markdown.slash.code",
        icon: "</>",
        insert: "```\n\n```",
        type: "block",
        cursorOffset: 4,
    },
    {
        id: "callout",
        labelKey: "notes.markdown.slash.callout",
        icon: "!",
        insert: "> [!info] \n> ",
        type: "block",
        cursorOffset: 10,
    },
    {
        id: "link",
        labelKey: "notes.markdown.slash.link",
        icon: "[[",
        insert: "[[]]",
        type: "inline",
        cursorOffset: 2,
    },
    {
        id: "bold",
        labelKey: "notes.markdown.slash.bold",
        icon: "B",
        insert: "****",
        type: "inline",
        cursorOffset: 2,
    },
    {
        id: "italic",
        labelKey: "notes.markdown.slash.italic",
        icon: "I",
        insert: "**",
        type: "inline",
        cursorOffset: 1,
    },
    {
        id: "strikethrough",
        labelKey: "notes.markdown.slash.strikethrough",
        icon: "S̶",
        insert: "~~~~",
        type: "inline",
        cursorOffset: 2,
    },
    {
        id: "table",
        labelKey: "notes.markdown.slash.table",
        icon: "⊞",
        type: "block",
        // Built at apply time from the size typed after the command
        // (`/tableau 3x4`), see `buildTable`.
        sizable: true,
    },
];

/** The blank table inserted when no size follows the command. */
export const DEFAULT_TABLE_SIZE = { cols: 3, rows: 3 };
const MAX_TABLE_COLS = 10;
const MAX_TABLE_ROWS = 50;

/**
 * `/tableau 3x4` → `{ word: "tableau", size: { cols: 3, rows: 4 } }`.
 *
 * The size is optional and may be half typed (`3`, `3x`): the palette stays
 * open while it is being written, and only a complete `CxR` changes what is
 * inserted. Returns null when the query is not a word optionally followed by
 * one space and such a size, which is what closes the palette.
 */
export function parseSlashQuery(query) {
    const match = query.match(/^(\S*)(?: (\d{0,2})(?:[x×](\d{0,2}))?)?$/i);
    if (!match) return null;
    const [, word, cols, rows] = match;
    const hasSizePart = query.includes(" ");
    const size =
        cols && rows
            ? {
                  cols: Math.min(Math.max(Number(cols), 1), MAX_TABLE_COLS),
                  rows: Math.min(Math.max(Number(rows), 1), MAX_TABLE_ROWS),
              }
            : null;
    return { word, hasSizePart, size };
}

/**
 * A blank Markdown table: numbered headers in the interface language, empty
 * cells, and the range of the first header so the caller can select it -
 * typing replaces "Column 1" straight away.
 */
export function buildTable({ cols, rows }, columnLabel) {
    const header = Array.from({ length: cols }, (_, i) => columnLabel(i + 1));
    const lines = [
        `| ${header.join(" | ")} |`,
        `| ${Array(cols).fill("---").join(" | ")} |`,
        ...Array.from(
            { length: rows },
            () => `| ${Array(cols).fill("").join(" | ")} |`,
        ),
    ];
    return {
        text: `${lines.join("\n")}\n`,
        selectStart: 2,
        selectEnd: 2 + header[0].length,
    };
}

export function useSlashCommands({ t }) {
    const showSlash = ref(false);
    const slashQuery = ref("");
    const slashIndex = ref(0);
    const slashPosition = ref({ top: 0, left: 0 });
    // Offset in the textarea value where the user's `/` starts. We
    // replace the substring [slashStart, caret] when a command is picked.
    const slashStart = ref(null);

    const commands = COMMANDS.map((command) => ({
        ...command,
        label: t(command.labelKey),
    }));

    const parsedQuery = computed(() => parseSlashQuery(slashQuery.value));

    const filteredCommands = computed(() => {
        const parsed = parsedQuery.value;
        const word = (parsed?.word ?? slashQuery.value).toLowerCase();
        const matching =
            word === ""
                ? commands
                : commands.filter(
                      (command) =>
                          command.label.toLowerCase().includes(word) ||
                          command.id.includes(word),
                  );
        if (!parsed?.hasSizePart) return matching;

        // A size was typed: only the commands that take one still apply, and
        // the label says what will be inserted.
        return matching
            .filter((command) => command.sizable)
            .map((command) =>
                parsed.size
                    ? {
                          ...command,
                          label: `${command.label} ${parsed.size.cols} × ${parsed.size.rows}`,
                      }
                    : command,
            );
    });

    /**
     * Inspect the textarea on every input. Opens the palette when the
     * caret sits inside `/<query>` where the `/` is at the start of the
     * buffer, at a line start, or right after a whitespace character -
     * mirroring how `@mentions` work elsewhere. Anything inside a word
     * (`http://...`, `foo/bar`) is ignored so users don't accidentally
     * trigger the palette while typing URLs / paths. The query must not
     * contain whitespace either, so once the user types a space after
     * `/foo` the palette closes and the chars become regular text.
     */
    function onInput(event) {
        const textarea = event.target;
        const caret = textarea.selectionStart;
        const text = textarea.value;
        const before = text.slice(0, caret);

        const slashIdx = before.lastIndexOf("/");
        if (slashIdx === -1) {
            closeSlash();
            return;
        }

        const charBefore = slashIdx === 0 ? "" : before[slashIdx - 1];
        const atBoundary =
            charBefore === "" || charBefore === "\n" || /\s/.test(charBefore);
        if (!atBoundary) {
            closeSlash();
            return;
        }

        const query = before.slice(slashIdx + 1);
        // A space ends the command, except the one that introduces a table
        // size (`/tableau 3x4`): the palette stays open while it is typed.
        const parsed = /\s/.test(query) ? parseSlashQuery(query) : null;
        const sizing =
            parsed !== null &&
            parsed.word !== "" &&
            commands.some(
                (command) =>
                    command.sizable &&
                    (command.label
                        .toLowerCase()
                        .includes(parsed.word.toLowerCase()) ||
                        command.id.includes(parsed.word.toLowerCase())),
            );
        if (/\s/.test(query) && !sizing) {
            closeSlash();
            return;
        }

        slashStart.value = slashIdx;
        slashQuery.value = query;
        slashIndex.value = 0;
        showSlash.value = true;
        positionDropdown(textarea, slashIdx);
    }

    function positionDropdown(textarea, startIndex) {
        slashPosition.value = positionFloatingMenu(textarea, startIndex);
    }

    /**
     * Keydown intercept. Returns a command when the user confirms (Enter
     * or Tab) so the caller can apply it, or null otherwise. Arrow keys
     * navigate the list, Escape closes.
     */
    function onKeydown(event) {
        if (!showSlash.value) return null;

        if (event.key === "ArrowDown") {
            event.preventDefault();
            slashIndex.value = Math.min(
                slashIndex.value + 1,
                filteredCommands.value.length - 1,
            );
        } else if (event.key === "ArrowUp") {
            event.preventDefault();
            slashIndex.value = Math.max(slashIndex.value - 1, 0);
        } else if (event.key === "Enter" || event.key === "Tab") {
            if (filteredCommands.value.length > 0) {
                event.preventDefault();
                return filteredCommands.value[slashIndex.value];
            }
        } else if (event.key === "Escape") {
            event.preventDefault();
            closeSlash();
        }
        return null;
    }

    /**
     * Replace the `/query` substring (from `slashStart` to current
     * caret) with the command's `insert` template. Returns the new
     * content + caret offset so the caller can update v-model and
     * restore the caret on next tick.
     */
    function applyCommand(textarea, command, content) {
        const start = slashStart.value;
        const caret = textarea.selectionStart;
        const before = content.slice(0, start);
        const after = content.slice(caret);

        if (command.sizable) {
            const size = parsedQuery.value?.size ?? DEFAULT_TABLE_SIZE;
            const table = buildTable(size, (n) =>
                t("notes.markdown.slash.table_column", { n }),
            );
            closeSlash();
            return {
                newContent: before + table.text + after,
                newCaret: start + table.selectStart,
                newCaretEnd: start + table.selectEnd,
            };
        }

        const newContent = before + command.insert + after;
        const newCaret =
            command.cursorOffset !== undefined
                ? start + command.cursorOffset
                : start + command.insert.length;

        closeSlash();
        return { newContent, newCaret };
    }

    function closeSlash() {
        showSlash.value = false;
        slashQuery.value = "";
        slashIndex.value = 0;
        slashStart.value = null;
    }

    return {
        showSlash,
        slashIndex,
        slashPosition,
        filteredCommands,
        onInput,
        onKeydown,
        applyCommand,
        closeSlash,
    };
}
