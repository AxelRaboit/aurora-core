import { describe, expect, it } from "vitest";
import CalloutBlock from "./CalloutBlock.js";
import { CALLOUT_ICONS, calloutIconSvg } from "./calloutIcons.js";

function tool(data = {}, config = {}) {
    const block = new CalloutBlock({ data, config });
    const element = block.render();

    return { block, element };
}

describe("CalloutBlock", () => {
    /** Mirrors BlocksRenderer::CALLOUT_ICON_NAMES: a name the page cannot draw is a lie in the picker. */
    it("offers the icons the page knows how to draw, in the same order", () => {
        expect(CALLOUT_ICONS.map(({ value }) => value)).toEqual([
            "info",
            "check-circle",
            "alert-triangle",
            "clock",
            "calendar",
            "star",
            "lightbulb",
            "message-circle",
            "pause-circle",
        ]);
    });

    it("saves the chosen icon with the colour and the words", () => {
        const { block } = tool({
            type: "accent",
            icon: "calendar",
            title: "Disponible",
            message: "Dès octobre",
        });

        expect(block.save()).toEqual({
            type: "accent",
            icon: "calendar",
            title: "Disponible",
            message: "Dès octobre",
        });
    });

    /** Callouts written before icons existed open with none, and stay that way. */
    it("opens an older callout without an icon", () => {
        const { block } = tool({ type: "info", title: "T", message: "M" });

        expect(block.save().icon).toBe("");
    });

    it("shows a no-icon choice first, then one button per icon, with the labels it is given", () => {
        const { element } = tool(
            {},
            {
                iconLabels: { calendar: "Calendrier" },
                noIconLabel: "Sans icône",
            },
        );
        const buttons = [...element.querySelectorAll(".callout-block__icon")];

        expect(buttons).toHaveLength(CALLOUT_ICONS.length + 1);
        expect(buttons[0].getAttribute("aria-label")).toBe("Sans icône");
        expect(
            buttons.find((b) => b.getAttribute("aria-label") === "Calendrier"),
        ).toBeTruthy();
    });

    it("picks an icon on click and marks it as the chosen one", () => {
        const { block, element } = tool({ title: "T" });
        const star = [...element.querySelectorAll(".callout-block__icon")].find(
            (b) => b.getAttribute("aria-label") === "star",
        );

        star.click();

        expect(block.save().icon).toBe("star");
        const active = element.querySelector(".callout-block__icon--active");
        expect(active?.getAttribute("aria-pressed")).toBe("true");
    });

    it("draws nothing for an icon it does not know", () => {
        expect(calloutIconSvg("nope")).toBe("");
        expect(calloutIconSvg("info")).toContain("<svg");
    });
});
