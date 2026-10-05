/**
 * Marked renderer override that wires interactive checkboxes onto task
 * list items. Each rendered checkbox carries a `data-checkbox-index`
 * (0-based, in source order) so click handlers can toggle the matching
 * `- [ ]` / `- [x]` line in the raw markdown source.
 *
 * Use `createCheckboxRenderer()` together with `resetCheckboxCounter()`
 * before every parse - otherwise indices accumulate across renders.
 */
let checkboxCounter = 0;

export function resetCheckboxCounter() {
    checkboxCounter = 0;
}

export function createCheckboxRenderer() {
    return {
        // The item body goes through the parser, never `item.text`: that
        // field is the raw source, so bold, links and nested lists would
        // come out as literal markdown.
        listitem(item) {
            if (!item.task) {
                return `<li>${this.parser.parse(item.tokens)}</li>\n`;
            }
            // Numbered before the body is parsed, so a parent task takes
            // its index ahead of the tasks nested under it - the same
            // order as the `- [ ]` lines in the source.
            const index = checkboxCounter++;
            const body = this.parser.parse(item.tokens);
            const checkedAttr = item.checked ? "checked" : "";
            return (
                `<li class="task-list-item">` +
                `<input type="checkbox" class="task-checkbox" data-checkbox-index="${index}" ${checkedAttr} />` +
                `<div class="task-list-body">${body}</div>` +
                `</li>\n`
            );
        },
        // Marked puts its own disabled box inside the item's first line;
        // ours is drawn by `listitem`, so this one is dropped.
        checkbox() {
            return "";
        },
    };
}

/**
 * Toggle the Nth task checkbox in raw markdown content and return the
 * updated source string. Matches `- [ ]`, `- [x]`, `- [X]`, `+ [ ]`,
 * `* [ ]` at line start (optionally indented).
 */
export function toggleCheckboxInContent(content, checkboxIndex) {
    let counter = 0;
    return content.replace(
        /^(\s*[-*+]\s+)\[([ xX])\]/gm,
        (match, prefix, state) => {
            if (counter++ !== checkboxIndex) return match;
            return state.trim() === "" ? `${prefix}[x]` : `${prefix}[ ]`;
        },
    );
}
