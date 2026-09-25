/**
 * Colouring part of a banner title.
 *
 * The title is a line, not a document, so it gets none of Editor.js: only the
 * one inline mark the server keeps, the `cdx-text-color` span that
 * BlockHtmlSanitizer accepts with a hex colour or the theme accent. Anything
 * else the browser puts in a contenteditable - a <div>, a <br>, a pasted
 * <b> - is flattened here, and stripped again by the server regardless.
 */
export const ACCENT = "var(--th-accent)";

const CLASS = "cdx-text-color";

/** The selection, when it sits inside `root` and covers at least one character. */
function rangeIn(root) {
    const selection = root.ownerDocument.getSelection();
    if (!selection || 0 === selection.rangeCount) {
        return null;
    }

    const range = selection.getRangeAt(0);
    if (range.collapsed || !root.contains(range.commonAncestorContainer)) {
        return null;
    }

    return range;
}

/**
 * Wrap the selected words in a colour span. Spans already inside the
 * selection are unwrapped first, so colouring twice never nests.
 *
 * @returns {boolean} whether anything changed
 */
export function colourSelection(root, color) {
    const range = rangeIn(root);
    if (!range) {
        return false;
    }

    const span = root.ownerDocument.createElement("span");
    span.className = CLASS;
    span.style.color = color;

    // Inside a span already coloured: split it around the selection, so the
    // words either side keep their colour and the new one does not nest.
    const host = colourAncestor(range.commonAncestorContainer, root);
    if (host) {
        const before = host.ownerDocument.createRange();
        before.setStart(host, 0);
        before.setEnd(range.startContainer, range.startOffset);
        const after = host.ownerDocument.createRange();
        after.setStart(range.endContainer, range.endOffset);
        after.setEnd(host, host.childNodes.length);

        span.appendChild(range.cloneContents());
        const head = host.cloneNode(false);
        head.appendChild(before.cloneContents());
        const tail = host.cloneNode(false);
        tail.appendChild(after.cloneContents());
        host.replaceWith(head, span, tail);
        normalise(root);

        return true;
    }

    const fragment = range.extractContents();
    fragment.querySelectorAll(`span.${CLASS}`).forEach(unwrap);
    span.appendChild(fragment);
    range.insertNode(span);
    normalise(root);

    return true;
}

function colourAncestor(node, root) {
    for (
        let current = node;
        current && current !== root;
        current = current.parentNode
    ) {
        if (
            current.nodeType === Node.ELEMENT_NODE &&
            current.classList.contains(CLASS)
        ) {
            return current;
        }
    }

    return null;
}

/**
 * Remove the colour from the selected words. A span the selection only
 * partly covers loses its colour as a whole: splitting it mid-word is more
 * surprising than useful in a one-line title.
 */
export function clearSelection(root) {
    const range = rangeIn(root);
    if (!range) {
        return false;
    }

    let changed = false;
    root.querySelectorAll(`span.${CLASS}`).forEach((span) => {
        if (range.intersectsNode(span)) {
            unwrap(span);
            changed = true;
        }
    });
    normalise(root);

    return changed;
}

/**
 * The HTML to store: colour spans and text, nothing else. Line breaks and
 * blocks a contenteditable invents collapse to spaces.
 */
export function serialise(root) {
    const clone = root.cloneNode(true);

    clone.querySelectorAll("*").forEach((node) => {
        if (
            "SPAN" === node.tagName &&
            node.classList.contains(CLASS) &&
            node.style.color
        ) {
            const color = colorValue(node);
            node.getAttributeNames().forEach((name) =>
                node.removeAttribute(name),
            );
            node.className = CLASS;
            node.setAttribute("style", `color: ${color}`);

            return;
        }

        if ("BR" === node.tagName) {
            node.replaceWith(" ");

            return;
        }

        unwrap(node);
    });

    return clone.innerHTML.replace(/\s+/g, " ").trim();
}

/**
 * The browser reports a colour set as hex back as `rgb(...)`. The server only
 * keeps hex or the accent variable, so the value is written in one of those.
 */
function colorValue(node) {
    const color = node.style.color;

    if (color.startsWith("var(")) {
        return color;
    }

    const match = color.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
    if (!match) {
        return color;
    }

    return `#${match
        .slice(1, 4)
        .map((n) => Number(n).toString(16).padStart(2, "0"))
        .join("")}`;
}

function unwrap(node) {
    node.replaceWith(...node.childNodes);
}

function normalise(root) {
    root.normalize();
    root.querySelectorAll(`span.${CLASS}`).forEach((span) => {
        if ("" === span.textContent) {
            span.remove();
        }
    });
}
