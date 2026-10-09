/**
 * Small edits the note editor makes on its text (09/10/2026), kept apart from
 * the textarea so each one is a plain function of a text and a selection.
 */

/**
 * The lines holding the selection, moved up or down by one line, as Alt+↑ /
 * Alt+↓ do in Obsidian. Null at the edge, where there is nowhere to go.
 *
 * @returns {{newContent: string, cursorPos: number, cursorEnd: number}|null}
 */
export function moveLines(content, selectionStart, selectionEnd, direction) {
    const lines = content.split("\n");
    const startLine = content.slice(0, selectionStart).split("\n").length - 1;
    // A selection ending right at a line start does not take that line along.
    const endOffset =
        selectionEnd > selectionStart && "\n" === content[selectionEnd - 1]
            ? selectionEnd - 1
            : selectionEnd;
    const endLine = content.slice(0, endOffset).split("\n").length - 1;

    if (
        (direction < 0 && 0 === startLine) ||
        (direction > 0 && endLine === lines.length - 1)
    )
        return null;

    const block = lines.slice(startLine, endLine + 1);
    if (direction < 0) {
        const above = lines[startLine - 1];
        lines.splice(startLine - 1, block.length + 1, ...block, above);
    } else {
        const below = lines[endLine + 1];
        lines.splice(startLine, block.length + 1, below, ...block);
    }

    const shift =
        (direction < 0 ? -1 : 1) *
        ((direction < 0 ? lines[endLine] : lines[startLine]).length + 1);

    return {
        newContent: lines.join("\n"),
        cursorPos: selectionStart + shift,
        cursorEnd: selectionEnd + shift,
    };
}

/** A short id for a paragraph: six letters and digits, readable in a link. */
export function newBlockId() {
    const alphabet = "abcdefghijklmnopqrstuvwxyz0123456789";
    let id = "";
    for (let position = 0; position < 6; position += 1) {
        id += alphabet[Math.floor(Math.random() * alphabet.length)];
    }

    return id;
}

/**
 * The paragraph the caret is in, named with ` ^id` at its end so that
 * `[[Note#^id]]` can lead to it - or its existing name, kept. Null on an
 * empty line.
 *
 * @returns {{newContent: string, id: string}|null}
 */
export function markBlock(content, caret, makeId = newBlockId) {
    const lines = content.split("\n");
    let line = content.slice(0, caret).split("\n").length - 1;
    if ("" === lines[line].trim()) return null;

    // The paragraph's last line: the name goes where Obsidian puts it.
    while (
        line + 1 < lines.length &&
        "" !== lines[line + 1].trim() &&
        !/^\s*([-*+]|\d+\.)\s/.test(lines[line + 1])
    ) {
        line += 1;
    }

    const existing = /[ \t]\^([A-Za-z0-9-]{1,40})\s*$/.exec(lines[line]);
    if (existing) return { newContent: content, id: existing[1] };

    const id = makeId();
    lines[line] = `${lines[line].replace(/\s+$/, "")} ^${id}`;

    return { newContent: lines.join("\n"), id };
}

/**
 * The tags written in the text as `#tag`, Obsidian-style: at a word's start,
 * with at least one letter (`#1` is a number, not a tag), outside code.
 */
export function inlineTags(content) {
    const text = String(content ?? "")
        .replace(/```[\s\S]*?```/g, "")
        .replace(/`[^`\n]*`/g, "");
    const found = new Set();
    for (const match of text.matchAll(
        /(^|[\s(])#([\p{L}\p{N}_/-]*\p{L}[\p{L}\p{N}_/-]*)/gu,
    )) {
        found.add(match[2]);
    }

    return [...found];
}

/** The note's tags with the ones its text writes, without a duplicate of either. */
export function mergeInlineTags(tags, content) {
    const known = new Set((tags ?? []).map((tag) => tag.toLowerCase()));
    const merged = [...(tags ?? [])];
    for (const tag of inlineTags(content)) {
        if (!known.has(tag.toLowerCase())) {
            known.add(tag.toLowerCase());
            merged.push(tag);
        }
    }

    return merged;
}

/** Whether pasted text is a web address and nothing else. */
export function isPastedAddress(text) {
    return /^https?:\/\/[^\s<>"]+$/i.test(String(text ?? "").trim());
}
