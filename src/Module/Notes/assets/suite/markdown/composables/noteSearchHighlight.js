/**
 * Highlighting what a notes search found (10/10/2026), the same way the
 * server matches: without accents nor case, so « echeance » lights up
 * « Échéance » - on the right letters of the original text.
 */

/**
 * The folded text, and for each of its characters the index of the original
 * one it comes from.
 *
 * @returns {{folded: string, map: number[]}}
 */
export function foldWithMap(text) {
    let folded = "";
    const map = [];
    Array.from(String(text ?? "")).forEach((character, index) => {
        const bare = character
            .normalize("NFD")
            .replace(/\p{Mn}+/gu, "")
            .toLowerCase();
        folded += bare;
        for (let step = 0; step < bare.length; step += 1) map.push(index);
    });

    return { folded, map };
}

/** Lowercase, without diacritics. */
export function foldSearch(text) {
    return foldWithMap(text).folded;
}

/**
 * Every occurrence of the needles, as `[start, length]` ranges of the
 * original text (in characters), merged and in order.
 *
 * @param {string} text
 * @param {string[]} needles already folded
 */
export function findRanges(text, needles) {
    const { folded, map } = foldWithMap(text);
    const ranges = [];
    for (const needle of needles ?? []) {
        if (!needle) continue;
        let offset = 0;
        for (
            let at = folded.indexOf(needle, offset);
            at >= 0;
            at = folded.indexOf(needle, offset)
        ) {
            const start = map[at];
            const end = map[at + needle.length - 1] + 1;
            ranges.push([start, end - start]);
            offset = at + needle.length;
        }
    }
    ranges.sort((left, right) => left[0] - right[0]);

    const merged = [];
    for (const range of ranges) {
        const last = merged.at(-1);
        if (last && range[0] <= last[0] + last[1]) {
            last[1] = Math.max(last[1], range[0] + range[1] - last[0]);
        } else {
            merged.push([...range]);
        }
    }

    return merged;
}

/**
 * A text cut into plain and highlighted parts, for a template to render
 * without `v-html`.
 *
 * @param {string} text
 * @param {Array<[number, number]>} ranges in characters
 * @returns {Array<{text: string, mark: boolean}>}
 */
export function highlightParts(text, ranges) {
    const characters = Array.from(String(text ?? ""));
    const parts = [];
    let at = 0;
    for (const [start, length] of ranges ?? []) {
        if (start > at)
            parts.push({
                text: characters.slice(at, start).join(""),
                mark: false,
            });
        parts.push({
            text: characters.slice(start, start + length).join(""),
            mark: true,
        });
        at = start + length;
    }
    if (at < characters.length)
        parts.push({ text: characters.slice(at).join(""), mark: false });

    return parts;
}

/**
 * Marks the needles in a rendered note, and brings the first one into view.
 * Former marks are taken out first, so a new search replaces the last one.
 *
 * @returns {number} how many were marked
 */
export function markTerms(root, needles) {
    if (!root) return 0;
    for (const old of root.querySelectorAll("mark.md-search-mark")) {
        old.replaceWith(...old.childNodes);
    }
    root.normalize();
    if (!needles?.length) return 0;

    const nodes = [];
    const walker = root.ownerDocument.createTreeWalker(
        root,
        NodeFilter.SHOW_TEXT,
        {
            acceptNode: (node) =>
                node.parentElement?.closest(".katex, .md-mermaid, mark")
                    ? NodeFilter.FILTER_REJECT
                    : NodeFilter.FILTER_ACCEPT,
        },
    );
    for (let node = walker.nextNode(); node; node = walker.nextNode())
        nodes.push(node);

    let count = 0;
    for (const node of nodes) {
        // Ranges in characters; a text node's offsets are in UTF-16 units.
        const characters = Array.from(node.data);
        const ranges = findRanges(node.data, needles);
        for (const [start, length] of ranges.reverse()) {
            const from = characters.slice(0, start).join("").length;
            const to =
                from + characters.slice(start, start + length).join("").length;
            const range = root.ownerDocument.createRange();
            range.setStart(node, from);
            range.setEnd(node, to);
            const mark = root.ownerDocument.createElement("mark");
            mark.className = "md-search-mark";
            range.surroundContents(mark);
            count += 1;
        }
    }

    root.querySelector("mark.md-search-mark")?.scrollIntoView({
        block: "center",
    });

    return count;
}

/** Selects the first occurrence in a textarea and scrolls it into view. */
export function selectFirst(textarea, needles) {
    if (!textarea) return false;
    const [first] = findRanges(textarea.value, needles);
    if (!first) return false;

    const characters = Array.from(textarea.value);
    const from = characters.slice(0, first[0]).join("").length;
    const to =
        from + characters.slice(first[0], first[0] + first[1]).join("").length;
    textarea.focus();
    textarea.setSelectionRange(from, to);
    // Scrolled by the line it is on: the field does not follow a selection
    // set from script.
    const line = textarea.value.slice(0, from).split("\n").length - 1;
    const lineHeight =
        Number.parseFloat(getComputedStyle(textarea).lineHeight) || 20;
    textarea.scrollTop = Math.max(
        0,
        line * lineHeight - textarea.clientHeight / 3,
    );

    return true;
}
