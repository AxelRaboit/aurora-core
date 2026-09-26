import { describe, expect, it } from "vitest";
import {
    DEFAULT_SHARE_LINKS,
    isShareAddress,
    resolveShareLinks,
    shareHref,
} from "./shareLinks.js";

const URL = "https://example.com/fr/page/a b";
const TITLE = "Mon titre & co";

describe("shareLinks", () => {
    it("draws the default row for a page nobody configured", () => {
        const links = resolveShareLinks(null, URL, TITLE);

        expect(links.map((l) => l.type)).toEqual(
            DEFAULT_SHARE_LINKS.map((l) => l.type),
        );
        expect(links[1].href).toBe(
            `https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(URL)}`,
        );
    });

    it("draws nothing for an empty list, which is a choice", () => {
        expect(resolveShareLinks([], URL, TITLE)).toEqual([]);
    });

    it("fills a custom address with the page's own url and title, encoded", () => {
        const href = shareHref(
            { type: "custom", url: "https://share.example/?u={url}&t={title}" },
            URL,
            TITLE,
        );

        expect(href).toBe(
            `https://share.example/?u=${encodeURIComponent(URL)}&t=${encodeURIComponent(TITLE)}`,
        );
    });

    it("keeps a plain link without placeholders as it is", () => {
        expect(
            shareHref(
                { type: "custom", url: "https://instagram.com/axel" },
                URL,
                TITLE,
            ),
        ).toBe("https://instagram.com/axel");
    });

    it("never lets anything but https and mailto into an href", () => {
        const links = resolveShareLinks(
            [
                { type: "custom", url: "javascript:alert(1)" },
                { type: "custom", url: "http://plain.example" },
                { type: "custom", url: "mailto:hello@example.com" },
                { type: "unknown" },
            ],
            URL,
            TITLE,
        );

        expect(links.map((l) => l.href)).toEqual(["mailto:hello@example.com"]);
    });

    it("keeps the order, the label and the colour it was given", () => {
        const links = resolveShareLinks(
            [
                { type: "whatsapp", label: "Envoyer", color: "#25d366" },
                { type: "copy", label: null, color: null },
            ],
            URL,
            TITLE,
        );

        expect(links.map((l) => [l.type, l.label, l.color])).toEqual([
            ["whatsapp", "Envoyer", "#25d366"],
            ["copy", null, null],
        ]);
    });
});

describe("isShareAddress", () => {
    // The same rule as ShareLinksNormalizer: what fails here is dropped on save.
    it("keeps https and mailto addresses", () => {
        expect(isShareAddress("https://example.com/share?u={url}")).toBe(true);
        expect(isShareAddress("mailto:?body={url}")).toBe(true);
    });

    it("refuses what the server would drop", () => {
        expect(isShareAddress("http://example.com")).toBe(false);
        expect(isShareAddress("www.example.com")).toBe(false);
        expect(isShareAddress("https://example.com/a b")).toBe(false);
        expect(isShareAddress("")).toBe(false);
        expect(isShareAddress(null)).toBe(false);
    });
});
