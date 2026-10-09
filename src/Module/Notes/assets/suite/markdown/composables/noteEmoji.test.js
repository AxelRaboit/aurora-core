import { describe, it, expect } from "vitest";
import { foldText, loadEmojiData, searchEmoji } from "./noteEmoji.js";

describe("noteEmoji", () => {
    it("folds accents and case", () => {
        expect(foldText("Fusée")).toBe("fusee");
    });

    it("finds the rocket in French and by its English shortcode", async () => {
        const data = await loadEmojiData("fr");
        expect(searchEmoji(data, "fusée")[0].emoji).toBe("🚀");
        expect(searchEmoji(data, "rocket")[0].emoji).toBe("🚀");
        expect(data.byShortcode.get("fusee")).toBe("🚀");
        expect(data.byShortcode.get("rocket")).toBe("🚀");
    });

    it("names the groups in the reader's language, without the components", async () => {
        const data = await loadEmojiData("fr");
        expect(data.groups[0].label).toBe("smileys et émotion");
        expect(data.groups.some((group) => 2 === group.key)).toBe(false);
    });
});
