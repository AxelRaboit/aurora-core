import { Marked } from "marked";
import DOMPurify from "dompurify";
import {
    createWikiLinkExtension,
    applyWikiLinksToHtml,
} from "./markedExtensions/markedWikiLinks.js";
import { createCalloutExtension } from "./markedExtensions/markedCallouts.js";
import {
    createCheckboxRenderer,
    resetCheckboxCounter,
} from "./markedExtensions/markedCheckboxes.js";
import { createHighlightRenderer } from "./markedExtensions/markedHighlight.js";
import { createImageDimensionsRenderer } from "./markedExtensions/markedImageDimensions.js";

/**
 * Builds a per-instance Marked parser with Aurora's note-specific
 * extensions (wiki-links, callouts, interactive checkboxes). DOMPurify
 * sanitizes the final HTML - we allow our custom `data-*` attributes so
 * click handlers can intercept wiki-links and checkboxes.
 *
 * Stateless: returns a `render(markdown)` function. Wiki-link and
 * checkbox extensions don't need closure state beyond the per-render
 * checkbox counter reset.
 */
export function useMarkdownRenderer() {
    const marked = new Marked({
        gfm: true,
        // Soft breaks: a single newline in the source becomes a <br>
        // in the preview (GitHub-/Obsidian-flavored). Without this,
        // commonmark merges adjacent lines into one paragraph - which
        // feels broken in a notes app where users press Enter to
        // separate visual lines without intending a new paragraph.
        breaks: true,
    });
    marked.use({
        extensions: [createWikiLinkExtension(), createCalloutExtension()],
    });
    marked.use({ renderer: createCheckboxRenderer() });
    marked.use({ renderer: createHighlightRenderer() });
    marked.use({ renderer: createImageDimensionsRenderer() });

    function render(markdown) {
        if (!markdown) return "";
        resetCheckboxCounter();
        const rawHtml = marked.parse(markdown);
        const withWikiLinks = applyWikiLinksToHtml(rawHtml);
        return DOMPurify.sanitize(withWikiLinks, {
            ADD_ATTR: [
                "data-note-title",
                "data-heading",
                "data-checkbox-index",
                "data-icon",
                "data-md-image",
                "data-md-src",
                "data-md-width",
                "data-md-handle",
            ],
        });
    }

    /**
     * Resolve a wiki-link target title (case-insensitive) to a note id by
     * scanning a list of `{id, title}` candidates. Returns null when the
     * target doesn't match any existing note.
     */
    function resolveWikiLink(targetTitle, noteTitles) {
        const needle = String(targetTitle ?? "").toLowerCase();
        const match = noteTitles.find(
            (n) => (n.title ?? "").toLowerCase() === needle,
        );
        return match?.id ?? null;
    }

    return { render, resolveWikiLink };
}
