/**
 * The passages that have a comment, marked in the rendered note (09/10/2026),
 * as Notion and Google Docs underline them.
 *
 * Found by their words, in one piece of text: a passage that spans two
 * styles (half in bold) is not marked, and its thread says so rather than
 * marking the wrong words.
 */

/**
 * @param {HTMLElement|null} root
 * @param {Array<{id: number, quote: string|null}>} threads  the open ones
 * @returns {Set<number>} the ids whose passage was found
 */
export function markQuotes(root, threads) {
    const found = new Set();
    if (!root) return found;

    for (const old of root.querySelectorAll("mark.md-comment-mark")) {
        old.replaceWith(...old.childNodes);
    }
    root.normalize();

    for (const thread of threads) {
        const quote = String(thread.quote ?? "").trim();
        if ("" === quote) continue;

        const walker = root.ownerDocument.createTreeWalker(
            root,
            NodeFilter.SHOW_TEXT,
            {
                acceptNode: (node) =>
                    node.parentElement?.closest(
                        "pre, code, .katex, .md-mermaid, mark.md-comment-mark",
                    )
                        ? NodeFilter.FILTER_REJECT
                        : NodeFilter.FILTER_ACCEPT,
            },
        );
        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
            const at = node.data.indexOf(quote);
            if (at < 0) continue;

            const range = root.ownerDocument.createRange();
            range.setStart(node, at);
            range.setEnd(node, at + quote.length);
            const mark = root.ownerDocument.createElement("mark");
            mark.className = "md-comment-mark";
            mark.dataset.commentId = String(thread.id);
            range.surroundContents(mark);
            found.add(thread.id);
            break;
        }
    }

    return found;
}

/** Brings a thread's passage into view and makes it blink once. */
export function flashQuote(root, id) {
    const mark = root?.querySelector(
        `mark.md-comment-mark[data-comment-id="${Number(id)}"]`,
    );
    if (!mark) return false;

    mark.scrollIntoView({ behavior: "smooth", block: "center" });
    mark.classList.add("is-flashing");
    setTimeout(() => mark.classList.remove("is-flashing"), 1200);

    return true;
}
