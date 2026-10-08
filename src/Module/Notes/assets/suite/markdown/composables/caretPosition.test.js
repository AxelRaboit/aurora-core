import { afterEach, describe, expect, it, vi } from "vitest";
import { caretPositionIn } from "./caretPosition.js";

/**
 * jsdom lays nothing out - every rectangle is zero - so these do not check
 * pixels. They check the two things that are this helper's own logic and that
 * a layout engine would not catch: that the mirror is built from the text
 * *before* the offset and torn down afterwards, and that the textarea's own
 * scroll is taken out of the result.
 */
function textareaWith(value, { scrollTop = 0 } = {}) {
    const field = document.createElement("textarea");
    field.value = value;
    Object.defineProperty(field, "scrollTop", {
        value: scrollTop,
        writable: true,
    });

    return field;
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe("la position d'un caret dans un textarea", () => {
    it("ne mesure que le texte qui précède le décalage", () => {
        const field = textareaWith("une ligne\nune autre");
        let measured = null;

        const append = document.body.appendChild.bind(document.body);
        vi.spyOn(document.body, "appendChild").mockImplementation((node) => {
            if (node.tagName === "DIV") measured = node.textContent;

            return append(node);
        });

        caretPositionIn(field, 4);

        // The marker rides along at the end: it is what gets measured, and it
        // sits exactly where the caret would be.
        expect(measured).toBe("une |");
    });

    it("retire le miroir du document, même sur plusieurs appels", () => {
        const field = textareaWith("du texte");

        caretPositionIn(field, 2);
        caretPositionIn(field, 5);

        expect(document.body.querySelectorAll("div").length).toBe(0);
    });

    it("soustrait le défilement du champ, sinon le caret dérive en bas de page", () => {
        const withoutScroll = caretPositionIn(textareaWith("du texte"), 3);
        const withScroll = caretPositionIn(
            textareaWith("du texte", { scrollTop: 120 }),
            3,
        );

        expect(withoutScroll.top - withScroll.top).toBe(120);
    });

    it("rend une hauteur de ligne exploitable même sans mise en page", () => {
        expect(
            caretPositionIn(textareaWith("du texte"), 0).lineHeight,
        ).toBeGreaterThan(0);
    });
});
