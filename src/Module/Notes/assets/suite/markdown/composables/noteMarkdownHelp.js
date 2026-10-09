/**
 * Everything a note can be written with, in one place (09/10/2026).
 *
 * The slash menu lists what it can insert; it says nothing of what is typed
 * by hand (a shown text in a link, a folded callout, a colour), of the
 * keyboard, nor of what a single character opens. This list is the help
 * panel's source: one entry per thing a writer can do, with how to write it,
 * its shortcut when there is one, and what a click inserts.
 *
 * `labelKey` and the section titles are translated; `syntax` is shown as
 * written, since that is what gets typed. `insert` is what a click puts at
 * the caret, `caret` where the caret lands in it (its end otherwise).
 * `shortcut` uses `Mod` for Ctrl on Windows and Linux, ⌘ on a Mac.
 */
export const NOTE_HELP_SECTIONS = [
    {
        key: "text",
        entries: [
            {
                key: "bold",
                syntax: "**texte**",
                shortcut: "Mod+B",
                insert: "****",
                caret: 2,
            },
            {
                key: "italic",
                syntax: "*texte*",
                shortcut: "Mod+I",
                insert: "**",
                caret: 1,
            },
            {
                key: "strikethrough",
                syntax: "~~texte~~",
                shortcut: "Mod+Shift+X",
                insert: "~~~~",
                caret: 2,
            },
            {
                key: "code",
                syntax: "`code`",
                shortcut: "Mod+E",
                insert: "``",
                caret: 1,
            },
            { key: "highlight", syntax: "==texte==", insert: "====", caret: 2 },
            {
                key: "highlight_color",
                syntax: "=={vert}texte==",
                insert: "=={vert}==",
                caret: 8,
            },
            {
                key: "color",
                syntax: "{rouge}texte{/}",
                insert: "{rouge}{/}",
                caret: 7,
            },
            {
                key: "link",
                syntax: "[texte](https://…)",
                shortcut: "Mod+K",
                insert: "[](https://)",
                caret: 1,
            },
            { key: "emoji", syntax: ":fusee:", insert: ":" },
        ],
    },
    {
        key: "structure",
        entries: [
            {
                key: "heading_1",
                syntax: "# Titre",
                shortcut: "Mod+H",
                insert: "# ",
            },
            { key: "heading_2", syntax: "## Titre", insert: "## " },
            { key: "heading_3", syntax: "### Titre", insert: "### " },
            {
                key: "bullet",
                syntax: "- élément",
                shortcut: "Mod+L",
                insert: "- ",
            },
            {
                key: "numbered",
                syntax: "1. élément",
                shortcut: "Mod+Shift+L",
                insert: "1. ",
            },
            {
                key: "checkbox",
                syntax: "- [ ] tâche",
                shortcut: "Mod+Shift+C",
                insert: "- [ ] ",
            },
            // Read by the tasks view, as Obsidian Tasks writes it.
            {
                key: "task_due",
                syntax: "- [ ] tâche 📅 2026-10-12",
                insert: "- [ ]  📅 2026-10-12",
                caret: 6,
            },
            { key: "quote", syntax: "> citation", insert: "> " },
            { key: "divider", syntax: "---", insert: "\n---\n" },
            {
                key: "table",
                syntax: "| A | B |\n| --- | --- |\n| 1 | 2 |",
                insert: "| A | B |\n| --- | --- |\n|  |  |\n",
                caret: 26,
            },
            { key: "toc", syntax: "[[toc]]", insert: "[[toc]]\n" },
        ],
    },
    {
        key: "blocks",
        entries: [
            {
                key: "code_block",
                syntax: "```js\ncode\n```",
                shortcut: "Mod+Shift+K",
                insert: "```\n\n```",
                caret: 4,
            },
            {
                key: "callout",
                syntax: "> [!info] Titre\n> texte",
                insert: "> [!info] \n> ",
                caret: 10,
            },
            {
                key: "callout_types",
                syntax: "note · tip · info · warning · danger · bug · example · quote · success · question · todo · failure · abstract",
                insert: null,
            },
            {
                key: "callout_folded",
                syntax: "> [!info]- Titre\n> caché",
                insert: "> [!info]- \n> ",
                caret: 11,
            },
            {
                key: "toggle",
                syntax: "> [!toggle]- Détails\n> texte",
                insert: "> [!toggle]- \n> ",
                caret: 13,
            },
            {
                key: "footnote",
                syntax: "texte[^1]\n\n[^1]: la note",
                insert: "[^1]",
            },
            { key: "math", syntax: "$E=mc^2$", insert: "$$", caret: 1 },
            {
                key: "math_block",
                syntax: "$$\n\\int_0^1 x\\,dx\n$$",
                insert: "$$\n\n$$\n",
                caret: 3,
            },
            {
                key: "diagram",
                syntax: "```mermaid\ngraph TD\n  A --> B\n```",
                insert: "```mermaid\ngraph TD\n    A --> B\n```\n",
                caret: 24,
            },
            {
                key: "image",
                syntax: "![légende|320](adresse)",
                insert: "![|320]()",
                caret: 2,
            },
        ],
    },
    {
        key: "links",
        entries: [
            { key: "wiki_link", syntax: "[[Note]]", insert: "[[]]", caret: 2 },
            {
                key: "wiki_heading",
                syntax: "[[Note#Titre]]",
                insert: "[[#]]",
                caret: 2,
            },
            {
                key: "wiki_alias",
                syntax: "[[Note|texte affiché]]",
                insert: "[[|]]",
                caret: 2,
            },
            {
                key: "wiki_block",
                syntax: "[[Note#^abc123]]",
                shortcut: "Mod+Shift+B",
                insert: "[[#^]]",
                caret: 2,
            },
            { key: "embed", syntax: "![[Note]]", insert: "![[]]", caret: 3 },
            { key: "tag", syntax: "#étiquette", insert: "#" },
            { key: "paste_link", syntax: "https://…", insert: null },
        ],
    },
    {
        key: "keyboard",
        entries: [
            { key: "slash", syntax: "/", insert: "/" },
            {
                key: "move_line",
                syntax: "Alt+↑ / Alt+↓",
                shortcut: "Alt+↑",
                insert: null,
            },
            {
                key: "table_tab",
                syntax: "Tab · Shift+Tab",
                shortcut: "Tab",
                insert: null,
            },
            {
                key: "reading",
                syntax: "Alt+R",
                shortcut: "Alt+R",
                insert: null,
            },
            {
                key: "quick_open",
                syntax: "Mod+P",
                shortcut: "Mod+P",
                insert: null,
            },
            {
                key: "focus",
                syntax: "Mod+Shift+F",
                shortcut: "Mod+Shift+F",
                insert: null,
            },
        ],
    },
];

/** Whether this machine says ⌘ rather than Ctrl. */
export function usesCommandKey() {
    return (
        "undefined" !== typeof navigator &&
        /Mac|iPhone|iPad/.test(navigator.platform ?? navigator.userAgent ?? "")
    );
}

/** `Mod+Shift+B` as this machine's keyboard writes it. */
export function shortcutLabel(shortcut, mac = usesCommandKey()) {
    if (!shortcut) return "";

    return shortcut
        .replace("Mod", mac ? "⌘" : "Ctrl")
        .replace("Shift", mac ? "⇧" : "Shift")
        .replace("Alt", mac ? "⌥" : "Alt");
}

/** The entries whose label, syntax or shortcut holds every word typed. */
export function filterHelp(sections, query, translate) {
    const words = String(query ?? "")
        .normalize("NFD")
        .replace(/[̀-ͯ]/g, "")
        .toLowerCase()
        .split(/\s+/)
        .filter(Boolean);
    if (0 === words.length) return sections;

    return sections
        .map((section) => ({
            ...section,
            entries: section.entries.filter((entry) => {
                const haystack =
                    `${translate(entry)} ${entry.syntax} ${entry.shortcut ?? ""}`
                        .normalize("NFD")
                        .replace(/[̀-ͯ]/g, "")
                        .toLowerCase();

                return words.every((word) => haystack.includes(word));
            }),
        }))
        .filter((section) => section.entries.length > 0);
}

/**
 * The examples' words in the reader's language: the syntax is what is typed,
 * the placeholder words are just words. Colour names in English outside
 * French, which the renderer reads in every language.
 */
const EXAMPLE_WORDS = {
    en: [
        ["texte affiché", "shown text"],
        ["texte", "text"],
        ["Titre", "Title"],
        ["élément", "item"],
        ["tâche", "task"],
        ["citation", "quote"],
        ["légende", "caption"],
        ["adresse", "address"],
        ["étiquette", "tag"],
        ["caché", "hidden"],
        ["Détails", "Details"],
        ["la note", "the note"],
        ["{vert}", "{green}"],
        ["{rouge}", "{red}"],
        [":fusee:", ":rocket:"],
    ],
    es: [
        ["texte affiché", "texto mostrado"],
        ["texte", "texto"],
        ["Titre", "Título"],
        ["élément", "elemento"],
        ["tâche", "tarea"],
        ["citation", "cita"],
        ["légende", "leyenda"],
        ["adresse", "dirección"],
        ["étiquette", "etiqueta"],
        ["caché", "oculto"],
        ["Détails", "Detalles"],
        ["la note", "la nota"],
        ["{vert}", "{green}"],
        ["{rouge}", "{red}"],
        [":fusee:", ":rocket:"],
    ],
};

export function localizeExample(text, locale) {
    const words = EXAMPLE_WORDS[String(locale ?? "").slice(0, 2)];
    if (!words || null === text || undefined === text) return text;

    return words.reduce(
        (written, [french, local]) => written.split(french).join(local),
        text,
    );
}
