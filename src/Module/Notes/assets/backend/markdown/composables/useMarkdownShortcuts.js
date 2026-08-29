/**
 * Keyboard shortcuts for the markdown notes textarea.
 *
 * Two families:
 *   - **Wrap shortcuts** surround the current selection with markup
 *     (Ctrl+B → `**selection**`). If nothing is selected, the wrappers
 *     are inserted at the caret with the caret placed between them so
 *     the user can keep typing.
 *   - **Line shortcuts** prepend markup at the start of the current
 *     line (Ctrl+H → `# `, Ctrl+L → `- `). Toggles aren't done - each
 *     keystroke prepends again, the user can Ctrl+Z to undo.
 *
 * Ported from Onyx (`resources/js/composables/notes/useNoteShortcuts.js`).
 *
 * The shortcuts use `event.ctrlKey || event.metaKey` so Mac users hit
 * Cmd+B and get the same behavior as Ctrl+B on Linux/Windows.
 *
 * | Combo          | Action                                          |
 * |----------------|-------------------------------------------------|
 * | Ctrl/Cmd + B   | Bold - `**selection**`                          |
 * | Ctrl/Cmd + I   | Italic - `*selection*`                          |
 * | Ctrl/Cmd + E   | Inline code - `` `selection` ``                 |
 * | Ctrl/Cmd + K   | Link - `[selection](url)`                       |
 * | Ctrl/Cmd + Shift + K | Code block - ` ``` \nselection\n``` `      |
 * | Ctrl/Cmd + Shift + X | Strikethrough - `~~selection~~`            |
 * | Ctrl/Cmd + H   | Heading - prepend `# ` to current line          |
 * | Ctrl/Cmd + L   | Bullet list - prepend `- ` to current line      |
 * | Ctrl/Cmd + Shift + L | Numbered list - prepend `1. `              |
 * | Ctrl/Cmd + Shift + C | Checkbox - prepend `- [ ] `                |
 */

function wrapSelection(before, after) {
    return (textarea, content) => {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const selected = content.slice(start, end);
        const replacement = before + selected + after;
        const newContent =
            content.slice(0, start) + replacement + content.slice(end);
        const cursorPos =
            selected.length > 0
                ? start + replacement.length
                : start + before.length;
        return { newContent, cursorPos };
    };
}

/**
 * Markdown link wrapper. With a selection, the selected text becomes
 * the link label and the caret lands on the placeholder `url`. Without
 * a selection, both `text` and `url` placeholders are inserted with
 * the caret on `text` for immediate typing.
 */
function wrapLink(textarea, content) {
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const selected = content.slice(start, end);
    const replacement = `[${selected || "text"}](url)`;
    const newContent =
        content.slice(0, start) + replacement + content.slice(end);
    const cursorPos = selected ? start + selected.length + 3 : start + 1;
    const cursorEnd = selected ? cursorPos + 3 : cursorPos + 4;
    return { newContent, cursorPos, cursorEnd };
}

function prependLine(prefix) {
    return (textarea, content) => {
        const start = textarea.selectionStart;
        const lineStart = content.lastIndexOf("\n", start - 1) + 1;
        const newContent =
            content.slice(0, lineStart) + prefix + content.slice(lineStart);
        const cursorPos = start + prefix.length;
        return { newContent, cursorPos };
    };
}

const SHORTCUTS = [
    { key: "b", shift: false, action: wrapSelection("**", "**") },
    { key: "i", shift: false, action: wrapSelection("*", "*") },
    { key: "e", shift: false, action: wrapSelection("`", "`") },
    { key: "k", shift: false, action: wrapLink },
    { key: "k", shift: true, action: wrapSelection("```\n", "\n```") },
    { key: "x", shift: true, action: wrapSelection("~~", "~~") },
    { key: "h", shift: false, action: prependLine("# ") },
    { key: "l", shift: false, action: prependLine("- ") },
    { key: "l", shift: true, action: prependLine("1. ") },
    { key: "c", shift: true, action: prependLine("- [ ] ") },
];

/**
 * Match a keyboard event against the shortcut table. Returns the
 * action's result `{newContent, cursorPos, cursorEnd?}` when a
 * shortcut fires (also calls `event.preventDefault()` so the browser
 * doesn't run its native handler - Ctrl+B would otherwise toggle bold
 * in contenteditable, Ctrl+K would focus the address bar in Firefox,
 * etc.). Returns `null` when no shortcut matches.
 */
export function handleMarkdownShortcut(event, textarea, content) {
    const isMod = event.ctrlKey || event.metaKey;
    if (!isMod) return null;

    const shortcut = SHORTCUTS.find(
        (s) => s.key === event.key.toLowerCase() && s.shift === event.shiftKey,
    );
    if (!shortcut) return null;

    event.preventDefault();
    return shortcut.action(textarea, content);
}
