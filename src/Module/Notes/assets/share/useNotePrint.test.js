import { afterEach, describe, expect, it, vi } from "vitest";
import { lightWhilePrinting, printWhenReady } from "./useNotePrint.js";

/**
 * Ce qui se casserait sans bruit : une note imprimée en thème sombre (texte
 * clair sur papier blanc), le thème de la personne perdu après l'impression,
 * ou un dialogue ouvert avant que les images ne soient là.
 */
describe("lightWhilePrinting", () => {
    afterEach(() => document.documentElement.classList.remove("dark"));

    it("prints in the light theme and gives the dark one back", () => {
        document.documentElement.classList.add("dark");
        const stop = lightWhilePrinting();

        window.dispatchEvent(new Event("beforeprint"));
        expect(document.documentElement.classList.contains("dark")).toBe(false);

        window.dispatchEvent(new Event("afterprint"));
        expect(document.documentElement.classList.contains("dark")).toBe(true);

        stop();
    });

    it("leaves a light page light", () => {
        const stop = lightWhilePrinting();

        window.dispatchEvent(new Event("beforeprint"));
        window.dispatchEvent(new Event("afterprint"));
        expect(document.documentElement.classList.contains("dark")).toBe(false);

        stop();
    });
});

describe("printWhenReady", () => {
    afterEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = "";
    });

    it("waits for the pictures before opening the dialog", async () => {
        const print = vi.spyOn(window, "print").mockImplementation(() => {});
        vi.spyOn(window, "requestAnimationFrame").mockImplementation(
            (callback) => callback(),
        );

        document.body.innerHTML = '<img loading="lazy" src="/une.png">';
        const image = document.querySelector("img");
        Object.defineProperty(image, "complete", { value: false });

        const done = printWhenReady(document, 1000);
        await Promise.resolve();
        expect(print).not.toHaveBeenCalled();
        expect(image.getAttribute("loading")).toBe("eager");

        image.dispatchEvent(new Event("load"));
        await done;
        expect(print).toHaveBeenCalledOnce();
    });
});
