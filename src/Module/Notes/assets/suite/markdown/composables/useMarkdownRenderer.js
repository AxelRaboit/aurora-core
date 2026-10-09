import { Marked } from "marked";
import DOMPurify from "dompurify";
import markedFootnote from "marked-footnote";
import {
    createWikiLinkExtension,
    createWikiEmbedExtension,
    applyWikiLinksToHtml,
    applyBlockIdsToHtml,
} from "./markedExtensions/markedWikiLinks.js";
import {
    createHighlightMarkExtension,
    createTextColorExtension,
} from "./markedExtensions/markedMarks.js";
import { createInlineTagExtension } from "./markedExtensions/markedTags.js";
import { createMentionExtension } from "./markedExtensions/markedMentions.js";
import {
    createInlineMathExtension,
    createBlockMathExtension,
    createTableOfContentsExtension,
} from "./markedExtensions/markedMath.js";
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
 *
 * What needs a library or the page around it - formulas, diagrams, emoji
 * shortcodes, included notes, the table of contents, the code's copy button -
 * is left as a marked element and finished by `noteHtmlEnhancer.js`, after the
 * HTML is on the page (09/10/2026).
 *
 * @param {object} [options]
 * @param {object} [options.footnotes]  `{description, backRefLabel}`, translated by the caller
 */
export function useMarkdownRenderer(options = {}) {
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
        extensions: [
            createWikiEmbedExtension(),
            createBlockMathExtension(),
            createTableOfContentsExtension(),
            createWikiLinkExtension(),
            createCalloutExtension(),
            createHighlightMarkExtension(),
            createTextColorExtension(),
            createInlineMathExtension(),
            createInlineTagExtension(),
            createMentionExtension(),
        ],
    });
    // Footnotes, `[^1]` and `[^1]: text`. Prefixed so that two notes shown on
    // one page (an included note) do not share their anchors.
    marked.use(
        markedFootnote({
            prefixId: "note-fn-",
            description: options.footnotes?.description ?? "Notes",
            backRefLabel: options.footnotes?.backRefLabel ?? "↩ {0}",
            footnoteDivider: true,
        }),
    );
    marked.use({ renderer: createCheckboxRenderer() });
    marked.use({ renderer: createHighlightRenderer() });
    marked.use({ renderer: createImageDimensionsRenderer() });

    function render(markdown) {
        if (!markdown) return "";
        resetCheckboxCounter();
        const rawHtml = marked.parse(markdown);
        const withWikiLinks = applyBlockIdsToHtml(
            applyWikiLinksToHtml(rawHtml),
        );
        return DOMPurify.sanitize(withWikiLinks, {
            ADD_ATTR: [
                "data-note-title",
                "data-heading",
                "data-checkbox-index",
                "data-embed-title",
                "data-tag",
                "data-user-id",
                "data-block-id",
                "data-math",
                "data-display",
                "data-toc",
                "data-footnote-ref",
                "data-footnote-backref",
                "data-footnotes",
                "open",
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
            (note) => (note.title ?? "").toLowerCase() === needle,
        );
        return match?.id ?? null;
    }

    return { render, resolveWikiLink };
}
