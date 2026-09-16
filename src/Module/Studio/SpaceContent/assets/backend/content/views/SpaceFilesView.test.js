import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import SpaceFilesView from "./SpaceFilesView.vue";

const i18n = createTestI18n();

const ITEMS = [
    { id: 1, title: "Carrousel de février" },
    { id: 2, title: "Affiche des soldes" },
];

const ATTACHMENTS = {
    1: [
        {
            id: 10,
            title: "Vieille photo",
            author: "Admin",
            fromClient: false,
            size: 2048,
            url: "/f/10",
            createdAt: "2026-02-01T09:00:00+00:00",
        },
    ],
    2: [
        {
            id: 11,
            title: "Photo récente",
            author: "camille@boulangerie.test",
            fromClient: true,
            size: 4096,
            url: "/f/11",
            createdAt: "2026-03-15T09:00:00+00:00",
        },
    ],
};

function render(props = {}) {
    return mount(SpaceFilesView, {
        props: { attachments: ATTACHMENTS, items: ITEMS, ...props },
        global: { plugins: [i18n] },
    });
}

describe("SpaceFilesView", () => {
    it("flattens every card's files into one list, newest first", () => {
        const rows = render().findAll("li");

        expect(rows).toHaveLength(2);
        expect(rows[0].text()).toContain("Photo récente");
        expect(rows[1].text()).toContain("Vieille photo");
    });

    it("names the card each file sits on", () => {
        expect(render().text()).toContain("Carrousel de février");
        expect(render().text()).toContain("Affiche des soldes");
    });

    /**
     * The contract that is easy to get wrong and silent when it is: the three
     * other views hand the form an item object, and the form reads its fields.
     * Emitting the id instead would set the form's subject to a number and
     * blank every field without an error anywhere.
     */
    it("opens a card with the item itself, not its id", async () => {
        const view = render();

        await view.findAll("button")[0].trigger("click");

        expect(view.emitted("open-item")?.[0]).toEqual([
            { id: 2, title: "Affiche des soldes" },
        ]);
    });

    it("says so when the space carries no file", () => {
        expect(render({ attachments: {} }).findAll("li")).toHaveLength(0);
    });

    /** A card deleted after its file was filed still leaves the file listed. */
    it("survives a file whose card is gone", () => {
        const view = render({ items: [] });

        expect(view.findAll("li")).toHaveLength(2);
    });
});
