import { nextTick, watch } from "vue";
import { useI18n } from "vue-i18n";
import { enhanceNoteHtml } from "./noteHtmlEnhancer.js";
import { markQuotes } from "./noteCommentMarks.js";

/**
 * Finishes the rendered note held by `rootRef` every time its HTML changes
 * (09/10/2026): formulas, diagrams, emoji, the table of contents, included
 * notes, the copy buttons. See `noteHtmlEnhancer.js`.
 *
 * After Vue has written the new HTML and before the browser paints it, so a
 * finished element is never seen unfinished once its library is loaded.
 *
 * @param {import('vue').Ref<HTMLElement|null>} rootRef
 * @param {import('vue').Ref<string>|Function} htmlSource  what to watch
 * @param {object} [options]
 * @param {Function} [options.loadEmbed]  `({title, heading}) => Promise<string|null>`
 * @param {Function} [options.quotes]  `() => Array<{id, quote}>`, the commented
 *   passages to mark; `options.onQuotesMarked(Set<id>)` hears which were found
 */
export function useNoteHtmlEnhancer(rootRef, htmlSource, options = {}) {
    const { t, locale } = useI18n();

    async function enhance() {
        await nextTick();
        await enhanceNoteHtml(rootRef.value, {
            locale: String(locale.value ?? "fr").slice(0, 2),
            labels: {
                copy: t("notes.markdown.render.copy"),
                copied: t("notes.markdown.render.copied"),
                tableOfContents: t("notes.markdown.render.table_of_contents"),
            },
            loadEmbed: options.loadEmbed,
        });
        if (options.quotes) {
            const found = markQuotes(rootRef.value, options.quotes() ?? []);
            options.onQuotesMarked?.(found);
        }
    }

    watch(
        [htmlSource, rootRef, () => JSON.stringify(options.quotes?.() ?? [])],
        () => void enhance(),
        {
            immediate: true,
            flush: "post",
        },
    );

    return { enhance };
}

/** The translated labels of footnotes, for `useMarkdownRenderer`. */
export function useFootnoteLabels() {
    const { t } = useI18n();

    return {
        description: t("notes.markdown.render.footnotes"),
        backRefLabel: t("notes.markdown.render.footnote_back", {
            number: "{0}",
        }),
    };
}
