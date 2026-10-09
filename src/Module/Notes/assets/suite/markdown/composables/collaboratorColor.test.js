import { describe, expect, it } from "vitest";
import {
    collaboratorCaretColor,
    collaboratorColor,
    collaboratorHue,
} from "./collaboratorColor.js";

/** `hsl(h s% l%)` as the helper writes it, to red, green and blue in 0..255. */
function rgbOf(hsl) {
    const [hue, saturation, lightness] = hsl
        .match(/hsl\((\d+) (\d+)% (\d+)%\)/)
        .slice(1)
        .map(Number);
    const chroma =
        (1 - Math.abs((2 * lightness) / 100 - 1)) * (saturation / 100);
    const second = chroma * (1 - Math.abs(((hue / 60) % 2) - 1));
    const offset = lightness / 100 - chroma / 2;
    const sector = Math.floor(hue / 60);
    const [red, green, blue] = [
        [chroma, second, 0],
        [second, chroma, 0],
        [0, chroma, second],
        [0, second, chroma],
        [second, 0, chroma],
        [chroma, 0, second],
    ][sector];

    return [red, green, blue].map((channel) => (channel + offset) * 255);
}

/** WCAG relative luminance. */
function luminanceOf([red, green, blue]) {
    const linear = (channel) => {
        const value = channel / 255;
        return value <= 0.03928
            ? value / 12.92
            : ((value + 0.055) / 1.055) ** 2.4;
    };

    return (
        0.2126 * linear(red) + 0.7152 * linear(green) + 0.0722 * linear(blue)
    );
}

describe("collaboratorHue", () => {
    it("gives the same hue to the same account, whatever its type", () => {
        expect(collaboratorHue(3)).toBe(collaboratorHue("3"));
    });

    it("keeps hues inside the circle", () => {
        for (let id = 1; id < 200; id += 1) {
            const hue = collaboratorHue(id);
            expect(hue).toBeGreaterThanOrEqual(0);
            expect(hue).toBeLessThan(360);
        }
    });

    it("separates the first accounts of a room", () => {
        const hues = [1, 2, 3, 4].map(collaboratorHue);
        expect(new Set(hues).size).toBe(4);
    });
});

describe("collaboratorColor", () => {
    it("shares its hue with the caret bar", () => {
        expect(collaboratorColor(2)).toContain(`hsl(${collaboratorHue(2)} `);
        expect(collaboratorCaretColor(2)).toContain(
            `hsl(${collaboratorHue(2)} `,
        );
    });

    it("keeps white text at 4.5:1 or better on every hue an account can get", () => {
        for (let id = 0; id < 360; id += 1) {
            const contrast =
                1.05 / (luminanceOf(rgbOf(collaboratorColor(id))) + 0.05);
            expect(contrast, `account ${id}`).toBeGreaterThanOrEqual(4.5);
        }
    });

    it("draws the caret bar brighter than the fill, so it shows on the dark theme", () => {
        const bar = luminanceOf(rgbOf(collaboratorCaretColor(7)));
        const fill = luminanceOf(rgbOf(collaboratorColor(7)));
        expect(bar).toBeGreaterThan(fill);
    });
});
