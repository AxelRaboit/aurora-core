import { describe, expect, it } from "vitest";
import { plainText, zoneBadges, zoneSummary } from "./gridZoneSummary.js";

const t = (key, params) => (params ? `${key}:${JSON.stringify(params)}` : key);

describe("zoneSummary", () => {
    it("names a text zone by its first heading, whatever comes before it", () => {
        const held = {
            blocks: [
                { type: "label", data: { text: "Réseaux sociaux" } },
                {
                    type: "header",
                    data: { text: "Objectifs <b>de</b> l'audit", level: 2 },
                },
            ],
        };

        expect(zoneSummary({ type: "text" }, held, t).title).toBe(
            "Objectifs de l'audit",
        );
    });

    it("falls back to the first words when there is no heading", () => {
        const held = {
            blocks: [
                {
                    type: "list",
                    data: {
                        items: [{ content: "Évaluer&nbsp;la performance" }],
                    },
                },
            ],
        };

        expect(zoneSummary({ type: "text" }, held, t).title).toBe(
            "Évaluer la performance",
        );
    });

    it("says how many entries a list holds and shows the first", () => {
        const held = {
            items: {
                a: { title: "Carrousel", description: "17 k vues" },
                b: { title: "Réel" },
                c: {},
            },
        };

        expect(zoneSummary({ type: "items" }, held, t)).toEqual({
            title: "Carrousel",
            detail: 'backend.posts.grid.tile_entries:{"count":2}',
        });
    });

    it("names a chart by its title and its shape", () => {
        expect(
            zoneSummary(
                { type: "chart", options: { chartType: "pie" } },
                { label: "Répartition" },
                t,
            ),
        ).toEqual({
            title: "Répartition",
            detail: "backend.posts.grid.chart_types.pie",
        });
    });

    it("says when an image zone still has no picture", () => {
        expect(
            zoneSummary({ type: "media", mediaId: null }, {}, t).detail,
        ).toBe("backend.posts.grid.tile_image_missing");
        expect(zoneSummary({ type: "media", mediaId: 7 }, {}, t).detail).toBe(
            "",
        );
    });

    it("has nothing to say about an empty zone", () => {
        expect(zoneSummary({ type: "text" }, undefined, t)).toEqual({
            title: "",
            detail: "",
        });
    });

    it("cuts a long line", () => {
        expect(plainText("a".repeat(100))).toHaveLength(70);
    });
});

describe("zoneBadges", () => {
    it("lists only what differs from a plain zone", () => {
        expect(
            zoneBadges(
                {
                    type: "text",
                    surface: "none",
                    options: { valign: "stretch", hideOn: "none" },
                },
                t,
            ),
        ).toEqual([]);
        expect(
            zoneBadges(
                {
                    type: "media",
                    surface: "raised",
                    options: { frame: "phone", tilt: "left", hideOn: "phone" },
                },
                t,
            ),
        ).toEqual([
            "backend.posts.grid.surfaces.raised",
            "backend.posts.grid.frames.phone",
            "backend.posts.grid.tilt",
            "backend.posts.grid.hide_on_badges.phone",
        ]);
    });
});
