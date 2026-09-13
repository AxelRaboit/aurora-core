import { describe, expect, it } from "vitest";
import { ref } from "vue";
import { useDeckChapters } from "./useDeckChapters.js";

const deck = (...layouts) =>
    ref(layouts.map((layout, at) => ({ id: at + 1, layout })));

describe("useDeckChapters", () => {
    it("reads the chapters off the section slides", () => {
        const { sizes } = useDeckChapters(
            deck("title", "section", "bullets", "bullets", "section", "quote"),
        );

        expect(sizes.value).toEqual({ 2: 2, 5: 1 });
    });

    /** Slides before the first divider belong to no chapter and never fold. */
    it("leaves what comes before the first divider alone", () => {
        const { isHidden, toggle } = useDeckChapters(
            deck("title", "section", "bullets"),
        );

        toggle(2);

        expect(isHidden(0)).toBe(false);
        expect(isHidden(2)).toBe(true);
    });

    it("keeps a folded chapter's own head visible", () => {
        const { isHidden, toggle } = useDeckChapters(
            deck("section", "bullets"),
        );

        toggle(1);

        expect(isHidden(0)).toBe(false);
    });

    it("folds and unfolds the same chapter", () => {
        const { isHidden, isFolded, toggle } = useDeckChapters(
            deck("section", "bullets"),
        );

        toggle(1);
        expect(isFolded(1)).toBe(true);
        expect(isHidden(1)).toBe(true);

        toggle(1);
        expect(isFolded(1)).toBe(false);
        expect(isHidden(1)).toBe(false);
    });

    /** A divider with nothing under it has nothing to fold, so no chevron. */
    it("offers no chevron on an empty chapter", () => {
        const slides = deck("section", "section", "bullets");
        const { foldable } = useDeckChapters(slides);

        expect(foldable(slides.value[0])).toBe(false);
        expect(foldable(slides.value[1])).toBe(true);
    });

    it("says a deck with no divider has one chapter and nothing to fold", () => {
        const slides = deck("title", "bullets");
        const { isHidden, foldable } = useDeckChapters(slides);

        expect(foldable(slides.value[0])).toBe(false);
        expect(isHidden(1)).toBe(false);
    });
});
