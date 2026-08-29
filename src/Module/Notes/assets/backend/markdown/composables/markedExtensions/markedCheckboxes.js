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
        listitem({ text, task, checked }) {
            if (!task) {
                return `<li>${text}</li>\n`;
            }
            const index = checkboxCounter++;
            const checkedAttr = checked ? "checked" : "";
            return (
                `<li class="task-list-item">` +
                `<input type="checkbox" class="task-checkbox" data-checkbox-index="${index}" ${checkedAttr} />` +
                `<span>${text}</span>` +
                `</li>\n`
            );
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
