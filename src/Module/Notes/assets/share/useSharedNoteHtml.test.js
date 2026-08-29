import { describe, it, expect } from "vitest";
import { shareHtml } from "./useSharedNoteHtml.js";

const TOKEN = "a".repeat(64);

// The server hands these down, generated from the routes. Spelled out here the
// way it spells them, so the test breaks if the shape of the contract changes.
const PATHS = {
    imagePrefix: "/backend/notes/markdown/images/",
    shareImagePath: `/notes/share/${TOKEN}/images/__filename__`,
    shareNotePath: `/notes/share/${TOKEN}/__id__`,
};

describe("shareHtml", () => {
    it("moves images onto the token route so they are not broken icons", () => {
        const html =
            '<p><img src="/backend/notes/markdown/images/photo.webp" alt=""></p>';

        expect(shareHtml(html, { ...PATHS, titleIndex: {} })).toContain(
            `/notes/share/${TOKEN}/images/photo.webp`,
        );
    });

    it("leaves images that already point elsewhere alone", () => {
        const html = '<p><img src="https://example.org/logo.png" alt=""></p>';

        expect(shareHtml(html, { ...PATHS, titleIndex: {} })).toContain(
            "https://example.org/logo.png",
        );
    });

    it("links a wiki link whose note is inside the share", () => {
        const html =
            '<p><a class="wiki-link" data-note-title="Recette">Recette</a></p>';

        const out = shareHtml(html, { ...PATHS, titleIndex: { recette: 7 } });

        expect(out).toContain(`href="/notes/share/${TOKEN}/7"`);
    });

    it("matches titles regardless of how they were capitalised", () => {
        const html =
            '<p><a class="wiki-link" data-note-title="RECETTE">RECETTE</a></p>';

        expect(
            shareHtml(html, { ...PATHS, titleIndex: { recette: 7 } }),
        ).toContain(`href="/notes/share/${TOKEN}/7"`);
    });

    it("unwraps a wiki link pointing outside the share, keeping its words", () => {
        const html =
            '<p>Voir <a class="wiki-link" data-note-title="Privée">Privée</a> pour la suite.</p>';

        const out = shareHtml(html, { ...PATHS, titleIndex: { recette: 7 } });

        expect(out).not.toContain("<a");
        expect(out).toContain("Voir Privée pour la suite.");
    });

    it("never invents a link from a title the index does not carry", () => {
        // The guard that matters: a share must not become a way to discover
        // which other notes exist by watching which links light up.
        const html =
            '<p><a class="wiki-link" data-note-title="constructor">x</a></p>';

        const out = shareHtml(html, { ...PATHS, titleIndex: {} });

        expect(out).not.toContain("href");
    });
});
