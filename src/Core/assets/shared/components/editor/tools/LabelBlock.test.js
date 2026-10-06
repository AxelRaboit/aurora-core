import { describe, expect, it } from "vitest";
import fs from "node:fs";
import LabelBlock, { LABEL_TONES } from "./LabelBlock.js";
import { REPOSITORY_ROOT } from "@/tests/helpers/phpSources.js";

function tool(data = {}) {
    const block = new LabelBlock({ data, config: {} });
    const element = block.render();

    return { block, element };
}

describe("LabelBlock", () => {
    /** Mirrors BlocksRenderer::LABEL_TONES: a colour the page cannot draw is a lie in the picker. */
    it("offers the tones the page knows how to draw", () => {
        const php = fs.readFileSync(
            `${REPOSITORY_ROOT}/src/Module/Editorial/Post/Service/BlocksRenderer.php`,
            "utf8",
        );
        const declared = php
            .match(/LABEL_TONES = \[([^\]]*)\]/)[1]
            .match(/'([a-z]+)'/g)
            .map((name) => name.slice(1, -1));

        expect(LABEL_TONES).toEqual(declared);
    });

    it("saves the words, the tone and the tilt", () => {
        const { block, element } = tool({
            text: "Réseaux sociaux",
            tone: "indigo",
        });
        element.querySelector(".label-block__tilt").click();

        expect(block.save()).toEqual({
            text: "Réseaux sociaux",
            tone: "indigo",
            tilt: true,
        });
    });

    it("keeps what was typed when the colour changes", () => {
        const { block, element } = tool();
        const pill = element.querySelector(".label-pill");
        pill.innerHTML = "Studio Grenadine";
        pill.dispatchEvent(new Event("input"));
        element.querySelector(".label-pill--rose.label-block__tone").click();

        expect(block.save()).toEqual({
            text: "Studio Grenadine",
            tone: "rose",
            tilt: false,
        });
    });

    it("falls back to black for a tone it does not know, and refuses an empty pill", () => {
        const { block } = tool({ text: "x", tone: "plaid" });

        expect(block.save().tone).toBe("dark");
        expect(block.validate({ text: "<br>" })).toBe(false);
    });
});
