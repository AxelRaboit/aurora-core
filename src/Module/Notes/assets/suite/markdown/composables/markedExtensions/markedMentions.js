/**
 * `@[Marie Dupont](user:12)` (09/10/2026): a person mentioned in a note, as
 * Notion and Craft show it - a chip with the name as written. The id is for
 * the server, which tells that person (`NoteMentions.php`, same pattern).
 */
const MENTION = /^@\[([^\]\n]{1,80})\]\(user:(\d{1,10})\)/;

/** The text to insert for a person picked in the `@` menu. */
export function mentionMarkup(person) {
    const name = String(person.name ?? "")
        .replace(/[[\]\n]/g, " ")
        .replace(/\s+/g, " ")
        .trim()
        .slice(0, 80);

    return `@[${name}](user:${person.id})`;
}

export function createMentionExtension() {
    return {
        name: "mention",
        level: "inline",
        start(source) {
            const index = source.indexOf("@[");

            return index < 0 ? undefined : index;
        },
        tokenizer(source) {
            const match = MENTION.exec(source);
            if (!match) return undefined;

            return {
                type: "mention",
                raw: match[0],
                name: match[1],
                userId: match[2],
            };
        },
        renderer(token) {
            const name = String(token.name)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/"/g, "&quot;");

            return `<span class="md-mention" data-user-id="${Number(token.userId)}">@${name}</span>`;
        },
    };
}
