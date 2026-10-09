/**
 * The emoji a note can use, by name and by group (09/10/2026).
 *
 * One source for three things: the note's icon picker, `:name:` in the text,
 * and its autocompletion. Emojibase, in the reader's language - the names are
 * what people type, « fusée » in French, « rocket » in English - plus GitHub's
 * English shortcodes everywhere, the ones people already know from elsewhere.
 *
 * Loaded on demand and once per language: over half a megabyte that a note
 * without an emoji never needs.
 */
const loaders = {
    fr: () =>
        Promise.all([
            import("emojibase-data/fr/compact.json"),
            import("emojibase-data/fr/shortcodes/cldr.json"),
            import("emojibase-data/fr/messages.json"),
        ]),
    en: () =>
        Promise.all([
            import("emojibase-data/en/compact.json"),
            import("emojibase-data/en/shortcodes/cldr.json"),
            import("emojibase-data/en/messages.json"),
        ]),
    es: () =>
        Promise.all([
            import("emojibase-data/es/compact.json"),
            import("emojibase-data/es/shortcodes/cldr.json"),
            import("emojibase-data/es/messages.json"),
        ]),
};

const cache = new Map();

/** Accents out, case down: « fusée » is found by typing « fusee ». */
export function foldText(text) {
    return String(text ?? "")
        .normalize("NFD")
        .replace(/[̀-ͯ]/g, "")
        .toLowerCase();
}

/**
 * @param {string} locale  fr, en or es; anything else reads as French
 * @returns {Promise<{list: Array<{emoji: string, label: string, tags: string[], shortcodes: string[], group: number, search: string}>, byShortcode: Map<string, string>, groups: Array<{key: number, label: string}>}>}
 */
export function loadEmojiData(locale = "fr") {
    const language = Object.hasOwn(loaders, locale) ? locale : "fr";
    if (!cache.has(language)) {
        cache.set(
            language,
            Promise.all([
                loaders[language](),
                import("emojibase-data/en/shortcodes/github.json"),
            ]).then(([[compact, local, messages], github]) =>
                build(
                    compact.default ?? compact,
                    local.default ?? local,
                    messages.default ?? messages,
                    github.default ?? github,
                ),
            ),
        );
    }

    return cache.get(language);
}

function asList(value) {
    if (undefined === value || null === value) return [];

    return Array.isArray(value) ? value : [value];
}

function build(compact, localShortcodes, messages, githubShortcodes) {
    const list = [];
    const byShortcode = new Map();

    for (const entry of compact) {
        // Regional indicators and components are parts of emoji, not emoji
        // anyone picks.
        if (undefined === entry.group || 2 === entry.group) continue;

        const shortcodes = [
            ...new Set([
                ...asList(localShortcodes[entry.hexcode]),
                ...asList(githubShortcodes[entry.hexcode]),
            ]),
        ];
        for (const shortcode of shortcodes) {
            if (!byShortcode.has(shortcode))
                byShortcode.set(shortcode, entry.unicode);
        }

        list.push({
            emoji: entry.unicode,
            label: entry.label ?? "",
            tags: entry.tags ?? [],
            shortcodes,
            group: entry.group,
            order: entry.order ?? 0,
            search: foldText(
                [entry.label, ...(entry.tags ?? []), ...shortcodes].join(" "),
            ),
        });
    }

    list.sort((left, right) => left.order - right.order);

    const groups = (messages.groups ?? [])
        .filter((group) => 2 !== group.order)
        .map((group) => ({ key: group.order, label: group.message }));

    return { list, byShortcode, groups };
}

/** The emoji whose name, tags or shortcodes hold every word typed, best first. */
export function searchEmoji(data, query, limit = 60) {
    const words = foldText(query).split(/\s+/).filter(Boolean);
    if (0 === words.length) return data.list.slice(0, limit);

    const found = [];
    for (const item of data.list) {
        if (!words.every((word) => item.search.includes(word))) continue;

        // A shortcode or a name that starts with the first word comes first:
        // « fus » should give the rocket before anything that merely mentions it.
        const first = words[0];
        const starts =
            item.shortcodes.some((code) => code.startsWith(first)) ||
            foldText(item.label).startsWith(first);
        found.push({ item, rank: starts ? 0 : 1 });
        if (found.length > limit * 4) break;
    }

    return found
        .sort(
            (left, right) =>
                left.rank - right.rank || left.item.order - right.item.order,
        )
        .slice(0, limit)
        .map((entry) => entry.item);
}
