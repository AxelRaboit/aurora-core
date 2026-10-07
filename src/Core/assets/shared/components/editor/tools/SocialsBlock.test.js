import { describe, expect, it } from "vitest";
import fs from "node:fs";
import SocialsBlock from "./SocialsBlock.js";
import { SOCIAL_NETWORKS } from "./socialNetworks.js";
import { REPOSITORY_ROOT } from "@/tests/helpers/phpSources.js";

function tool(data = {}) {
    const block = new SocialsBlock({ data, config: {} });
    const element = block.render();

    return { block, element };
}

function type(input, value) {
    input.value = value;
    input.dispatchEvent(new Event("input"));
}

describe("SocialsBlock", () => {
    /** Mirrors BlocksRenderer::SOCIAL_NETWORK_NAMES: a network the page cannot draw is a row that vanishes. */
    it("offers the networks the page knows how to draw, in the same order", () => {
        const php = fs.readFileSync(
            `${REPOSITORY_ROOT}/src/Module/Editorial/Post/Service/BlocksRenderer.php`,
            "utf8",
        );
        const declared = php
            .match(/SOCIAL_NETWORK_NAMES = \[([^\]]*)\]/)[1]
            .match(/'([a-z]+)'/g)
            .map((name) => name.slice(1, -1));

        expect(SOCIAL_NETWORKS.map(({ value }) => value)).toEqual(declared);
    });

    it("starts with one Instagram row to fill", () => {
        const { element } = tool();

        expect(element.querySelectorAll(".socials-block__row")).toHaveLength(1);
        expect(
            element.querySelector(".socials-block__network--active").title,
        ).toBe("Instagram");
    });

    it("saves each account with a handle, and drops the empty rows", () => {
        const { block, element } = tool();
        type(
            element.querySelector(".socials-block__handle"),
            " @the.familystudio ",
        );
        element.querySelector(".socials-block__add").click();
        element
            .querySelectorAll(".socials-block__row")[1]
            .querySelector('[title="LinkedIn"]')
            .click();
        element.querySelector(".socials-block__add").click();

        // The second row became LinkedIn; give it a handle.
        type(
            element.querySelectorAll(".socials-block__handle")[1],
            "Family Studio",
        );

        expect(block.save()).toEqual({
            items: [
                { network: "instagram", handle: "@the.familystudio", url: "" },
                { network: "linkedin", handle: "Family Studio", url: "" },
            ],
        });
    });

    it("leaves out a network it does not know", () => {
        const { block } = tool({
            items: [
                { network: "myspace", handle: "x" },
                { network: "facebook", handle: "Page" },
            ],
        });

        expect(block.save()).toEqual({
            items: [{ network: "facebook", handle: "Page", url: "" }],
        });
    });
});
