import { afterEach, describe, expect, it, vi } from "vitest";
import { currentSection, revealSection, switchHref } from "./viewSwitch.js";

/**
 * Passer d'une vue à l'autre d'un livrable sans perdre sa place : les deux
 * vues partagent `#diapo-N`.
 */
function sections(tops) {
    document.body.innerHTML = tops
        .map((_, i) => `<div data-section="${i + 1}"></div>`)
        .join("");
    document.querySelectorAll("[data-section]").forEach((element, i) => {
        element.getBoundingClientRect = () =>
            null === tops[i]
                ? { top: 0, width: 0, height: 0 }
                : { top: tops[i], width: 300, height: 200 };
    });
}

describe("viewSwitch", () => {
    afterEach(() => (document.body.innerHTML = ""));

    it("keeps the slide shown when going to the page", () => {
        expect(switchHref("page", { hash: "#diapo-7" })).toBe(
            "?view=page#diapo-7",
        );
    });

    it("drops any other hash when going to the page", () => {
        expect(switchHref("page", { hash: "#contact" })).toBe("?view=page");
        expect(switchHref("page", { hash: "" })).toBe("?view=page");
    });

    it("goes to the section the reader has scrolled into", () => {
        // Sections 1 and 2 have passed the top of the screen, 3 is below.
        sections([-900, 40, 600]);

        expect(currentSection()).toBe(2);
        expect(switchHref("slides")).toBe("?view=slides#diapo-2");
    });

    it("ignores a section start hidden at this width", () => {
        // Section 3 starts with a zone hidden on phones: it reports top 0.
        sections([-900, 40, null, 700]);

        expect(currentSection()).toBe(2);
    });

    it("goes to the first slide from the top of the page", () => {
        sections([300, 900]);

        expect(currentSection()).toBeNull();
        expect(switchHref("slides")).toBe("?view=slides");
    });

    it("finds a section by its number when the author gave it another anchor", () => {
        document.body.innerHTML =
            '<div id="nos-objectifs" data-section="2"></div>';
        const target = document.querySelector("[data-section]");
        target.scrollIntoView = vi.fn();

        revealSection("#diapo-2");

        expect(target.scrollIntoView).toHaveBeenCalled();
    });

    it("leaves the browser alone when the anchor is the section's own", () => {
        document.body.innerHTML = '<div id="diapo-2" data-section="2"></div>';
        const target = document.querySelector("[data-section]");
        target.scrollIntoView = vi.fn();

        revealSection("#diapo-2");

        expect(target.scrollIntoView).not.toHaveBeenCalled();
    });
});
