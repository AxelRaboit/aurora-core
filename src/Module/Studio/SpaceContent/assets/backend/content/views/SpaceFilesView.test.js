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

/** The file entries, whichever shape the view is drawing. */
function entries(view) {
    const rows = view.findAll("li");

    return rows.length > 0 ? rows : view.findAll("article");
}

/** The button that opens a card, found by its label rather than its position. */
function cardLink(view, title) {
    return view.findAll("button").find((b) => b.text() === title);
}

describe("SpaceFilesView", () => {
    it("flattens every card's files into one list, newest first", () => {
        const found = entries(render());

        expect(found).toHaveLength(2);
        expect(found[0].text()).toContain("Photo récente");
        expect(found[1].text()).toContain("Vieille photo");
    });

    it("names the card each file sits on", () => {
        const text = render().text();

        expect(text).toContain("Carrousel de février");
        expect(text).toContain("Affiche des soldes");
    });

    /**
     * The contract that is easy to get wrong and silent when it is: the three
     * other views hand the form an item object, and the form reads its fields.
     * Emitting the id instead would set the form's subject to a number and
     * blank every field without an error anywhere.
     */
    it("opens a card with the item itself, not its id", async () => {
        const view = render();

        await cardLink(view, "Affiche des soldes").trigger("click");

        expect(view.emitted("open-item")?.[0]).toEqual([
            { id: 2, title: "Affiche des soldes" },
        ]);
    });

    it("says so when the space carries no file", () => {
        const view = render({ attachments: {} });

        expect(entries(view)).toHaveLength(0);
        expect(view.findAll("button")).toHaveLength(0);
    });

    /** A card deleted after its file was filed still leaves the file listed. */
    it("survives a file whose card is gone", () => {
        expect(entries(render({ items: [] }))).toHaveLength(2);
    });

    /**
     * The toggle is offered, and only when there is something to shape.
     *
     * Not asserted on the resulting layout: which of the two is drawn is
     * `useListViewMode`'s call, and it overrules the stored choice when the
     * container is too narrow for rows. What belongs to this view is that the
     * choice exists at all.
     */
    it("offers the two shapes, and neither on an empty space", async () => {
        const view = render();
        const toggle = view.findAll("button").filter((b) => b.text() === "");

        expect(toggle).toHaveLength(2);

        await toggle[0].trigger("click");
        expect(view.findAll("article")).toHaveLength(2);

        await toggle[1].trigger("click");
        expect(view.findAll("li")).toHaveLength(2);
    });

    /**
     * Ouvrir un fichier montre le fichier, ici, sans quitter la liste.
     *
     * L'assertion porte sur l'adresse rendue et pas sur le composant de
     * prévisualisation : ce qui casserait en silence, c'est un panneau ouvert
     * sur le mauvais fichier - deux lignes, un seul `previewed`.
     */
    it("previews a file in a panel rather than navigating to it", async () => {
        const view = render();

        expect(document.body.innerHTML).not.toContain("/f/11");

        await view
            .findAll("button")
            .find((b) => b.text() === "backend.studio.space_content.files_open")
            .trigger("click");

        // Le panneau est téléporté sur le body, comme toutes les fenêtres de
        // l'application : le chercher dans le composant ne trouverait rien.
        expect(document.body.innerHTML).toContain("/f/11");

        view.unmount();
    });
});
