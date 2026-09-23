import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import { onPanelRequest, tellPanels } from "@/shared/nav/modulePanelBridge.js";

window.__isAdmin__ = true;

const NoteTreePanel = (await import("./NoteTreePanel.vue")).default;

const i18n = createTestI18n();

const FOLDERS = [
    {
        id: 1,
        name: "Journal",
        parentId: null,
        noteCount: 1,
        folderCount: 1,
        favoritedAt: "2026-09-20T10:00:00+00:00",
    },
    { id: 2, name: "Lundi", parentId: 1, noteCount: 0, folderCount: 0 },
    { id: 3, name: "Recettes", parentId: null, noteCount: 2, folderCount: 0 },
];

const NOTES = [
    { id: 11, title: "Journal de bord", folderId: 1, tags: ["perso"] },
    { id: 12, title: "Tarte", folderId: 3, tags: ["cuisine"] },
];

/**
 * Le panneau demande deux listes, et une recherche quand on tape : la réponse
 * dépend donc de l'adresse, pas du rang de l'appel.
 */
function answerWith({
    folders = FOLDERS,
    notes = NOTES,
    ids = [],
    ok = true,
} = {}) {
    global.fetch = vi.fn().mockImplementation(async (url) => {
        const path = String(url);
        const payload = path.includes("/folders")
            ? { success: true, folders }
            : path.includes("/search")
              ? { success: true, ids }
              : // Ce que les autres ont partagé est vide par défaut : sans
                // cette branche, la même réponse servait les deux listes et
                // le panneau affichait tout le carnet une seconde fois,
                // dans la section « Partagé avec moi ».
                path.includes("/shared")
                ? { success: true, folders: [], notes: [] }
                : { success: true, notes };

        return {
            ok,
            status: ok ? 200 : 500,
            json: async () => (ok ? payload : null),
        };
    });
}

const mounted = [];
const stops = [];

async function render(url = "/backend/notes/markdown", { expanded = [] } = {}) {
    window.history.replaceState({}, "", url);

    // L'arbre s'ouvre replié : un carnet de neuf cents notes déplié d'un
    // coup est ce que la refonte a supprimé. Un cas qui regarde une branche
    // la déplie, comme le lecteur.
    window.localStorage.setItem(
        "aurora.notes.panel.expanded",
        JSON.stringify(expanded),
    );

    const wrapper = mount(NoteTreePanel, { global: { plugins: [i18n] } });
    mounted.push(wrapper);
    await flushPromises();

    return wrapper;
}

/**
 * Les lignes de dossiers de l'arborescence : ni « Tous les documents » en
 * tête, ni les favoris, qui montrent les mêmes adresses plus haut.
 */
const folderLinks = (wrapper) =>
    wrapper
        .findAll("a")
        .filter(
            (a) =>
                a.attributes("href")?.includes("/folder/") &&
                undefined === a.attributes("data-favorite-row"),
        );

beforeEach(() => answerWith());

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
    while (stops.length) stops.pop()();
    vi.restoreAllMocks();
});

describe("ce que les autres partagent", () => {
    /** Une liste à part : ce qui n'est pas à soi ne se range pas chez soi. */
    it("lists a shared folder and its notes, apart from one's own tree", async () => {
        answerWith();
        const partage = {
            folders: [
                { id: 9, name: "Équipe", parentId: null, ownerName: "Camille" },
            ],
            notes: [
                {
                    id: 91,
                    title: "Compte rendu",
                    folderId: 9,
                    ownerName: "Camille",
                },
            ],
        };
        const fetchDeBase = global.fetch;
        global.fetch = vi.fn().mockImplementation(async (url) => {
            if (String(url).includes("/shared")) {
                return {
                    ok: true,
                    status: 200,
                    json: async () => ({ success: true, ...partage }),
                };
            }

            return fetchDeBase(url);
        });

        const wrapper = await render();

        expect(wrapper.text()).toContain("Équipe");

        const lien = wrapper
            .findAll("a")
            .find((a) => a.attributes("href")?.endsWith("/91/read"));

        expect(lien, "la note partagée mène à la vue de lecture").toBeTruthy();
        expect(lien.text()).toContain("Compte rendu");
    });

    it("says nothing when nobody has shared anything", async () => {
        const wrapper = await render();

        expect(wrapper.text()).not.toContain("library.shared.section");
    });
});

describe("les étiquettes du panneau", () => {
    /** La ligne d'une étiquette, visée par son libellé. */
    function tagRow(wrapper, name) {
        return wrapper
            .findAll("button")
            .find(
                (b) =>
                    b.attributes("title")?.includes(`library.tag.filter`) &&
                    b.text().includes(name),
            );
    }

    beforeEach(() =>
        window.localStorage.removeItem("aurora.notes.panel.pinnedTags"),
    );

    it("lists what the notes actually carry, and how many carry it", async () => {
        const wrapper = await render();

        expect(tagRow(wrapper, "perso")).toBeTruthy();
        expect(tagRow(wrapper, "cuisine")).toBeTruthy();
        expect(tagRow(wrapper, "perso").text()).toContain("1");
    });

    it("asks the page to show a tag rather than navigating", async () => {
        const asked = [];
        stops.push(
            onPanelRequest("notes:filter-tag", ({ args }) => asked.push(args)),
        );

        const wrapper = await render();
        await tagRow(wrapper, "cuisine").trigger("click");

        expect(asked).toEqual([["cuisine"]]);
    });

    /**
     * L'épinglage vit dans le navigateur : une étiquette n'est pas une ligne
     * dans Aurora, c'est une chaîne dans le tableau d'une note.
     */
    it("remembers a pinned tag for the next visit", async () => {
        const wrapper = await render();

        const pin = wrapper
            .findAll("button")
            .find((b) => b.attributes("title")?.includes("library.tag.pin"));

        await pin.trigger("click");

        expect(
            JSON.parse(
                window.localStorage.getItem("aurora.notes.panel.pinnedTags"),
            ),
        ).toHaveLength(1);
    });

    /**
     * Une étiquette renommée ou effacée depuis l'écran des étiquettes n'a
     * aucune ligne à nettoyer : la liste part de ce que les notes portent.
     */
    it("drops a pinned tag that no note carries any more", async () => {
        window.localStorage.setItem(
            "aurora.notes.panel.pinnedTags",
            JSON.stringify(["disparue"]),
        );

        const wrapper = await render();

        expect(tagRow(wrapper, "disparue")).toBeFalsy();
        expect(tagRow(wrapper, "perso")).toBeTruthy();
    });

    it("says nothing while a search is running", async () => {
        const wrapper = await render();

        await wrapper.find("input").setValue("journal");
        await flushPromises();

        expect(tagRow(wrapper, "perso")).toBeFalsy();
    });
});

describe("the folders panel", () => {
    it("fetches its own lists, because the menu hands it no props", async () => {
        await render();

        expect(global.fetch).toHaveBeenCalledWith(
            "/backend/notes/markdown/folders",
            expect.anything(),
        );
        expect(global.fetch).toHaveBeenCalledWith(
            "/backend/notes/markdown/list",
            expect.anything(),
        );
    });

    /**
     * A folder is a page now, so a row is a real address: it can be sent to
     * somebody and opened in a new tab.
     */
    it("points every row at the folder's own address", async () => {
        const hrefs = folderLinks(
            await render("/backend/notes/markdown", { expanded: [1] }),
        ).map((a) => a.attributes("href"));

        expect(hrefs).toEqual([
            "/backend/notes/markdown/folder/1",
            "/backend/notes/markdown/folder/2",
            "/backend/notes/markdown/folder/3",
        ]);
    });

    /**
     * Ce que le dépliage sert : voir ce qu'un dossier contient sans quitter
     * le menu. Replié, la ligne dit seulement combien.
     */
    it("shows the notes of a folder once it is unfolded", async () => {
        const wrapper = await render();

        expect(wrapper.text()).not.toContain("Journal de bord");

        const chevron = wrapper
            .find("[data-folder-row='1']")
            .findAll("button")
            .at(0);
        await chevron.trigger("click");

        expect(wrapper.text()).toContain("Journal de bord");
    });

    it("remembers what was unfolded, because the panel is remounted on every page", async () => {
        const first = await render();

        await first
            .find("[data-folder-row='1']")
            .findAll("button")
            .at(0)
            .trigger("click");

        const second = mount(NoteTreePanel, { global: { plugins: [i18n] } });
        mounted.push(second);
        await flushPromises();

        expect(second.text()).toContain("Journal de bord");
    });

    it("nests a folder under its parent", async () => {
        // The indent is on the row, not on the link inside it: the row is the
        // drop target and the draggable handle, the link is only the name.
        const indents = (
            await render("/backend/notes/markdown", { expanded: [1] })
        )
            .findAll("[data-folder-row]")
            .map((row) => row.attributes("style") ?? "");

        expect(indents[0]).not.toEqual(indents[1]);
        expect(indents[0]).toEqual(indents[2]);
    });

    /** The library is mounted, so it takes the click and swaps in place. */
    it("lets the page open the folder instead of navigating", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("notes:open-folder", handler));

        await folderLinks(
            await render("/backend/notes/markdown", { expanded: [1] }),
        )[2].trigger("click");

        expect(handler).toHaveBeenCalledWith({ args: [3] });
    });

    /** Making a note is naming it and putting the cursor in it - the page's job. */
    it("asks the page to create rather than doing it itself", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("notes:create", handler));

        const wrapper = await render();
        const plus = wrapper
            .findAll("button")
            .find(
                (b) => b.attributes("title") === "notes.markdown.create_root",
            );
        await plus.trigger("click");

        expect(handler).toHaveBeenCalled();
    });

    it("asks the page for a new folder too", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("notes:create-folder", handler));

        const wrapper = await render();
        const button = wrapper
            .findAll("button")
            .find(
                (b) =>
                    b.attributes("title") === "notes.markdown.folders.create",
            );
        await button.trigger("click");

        expect(handler).toHaveBeenCalled();
    });

    it("filters the tree on what the reader typed", async () => {
        const wrapper = await render();

        await wrapper.find("input").setValue("recett");
        await flushPromises();

        expect(folderLinks(wrapper).map((a) => a.text())).toEqual(["Recettes"]);
    });

    /**
     * The one search that reaches the whole notebook.
     *
     * The library filters the folder it shows, which is what an explorer
     * does; finding a note whose folder you have forgotten is this field's
     * job, and the matched notes are listed flat beneath the folders.
     */
    /**
     * Une recherche ouvre les branches où elle a trouvé quelque chose :
     * laisser le résultat replié, c'est ne rien montrer.
     */
    it("finds a note across the notebook and opens its folder", async () => {
        const wrapper = await render();

        await wrapper.find("input").setValue("tarte");
        await flushPromises();

        expect(wrapper.text()).toContain("Tarte");
        // Son dossier reste affiché, sinon la note trouvée n'aurait plus de
        // branche à laquelle se rattacher.
        expect(wrapper.text()).toContain("Recettes");
        expect(wrapper.text()).not.toContain("Journal de bord");
    });

    it("disappears rather than complaining when the fetch fails", async () => {
        answerWith({ ok: false });

        const wrapper = await render();

        expect(wrapper.text()).toBe("");
    });
});

describe("la racine", () => {
    /**
     * `Number(null)` vaut zéro, et zéro n'est pas un dossier : le panneau
     * demandait le dossier 0, la bibliothèque le montrait vide et l'adresse
     * rendait un 404 - lequel renvoie un visiteur non connecté vers la page
     * de connexion. Axel a vu les trois symptômes à la fois.
     */
    it("asks for the root, not for folder zero", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("notes:open-folder", handler));

        const wrapper = await render();
        await wrapper.find("[data-root-row]").trigger("click");

        expect(handler).toHaveBeenCalledWith({ args: [null] });
    });
});

describe("les favoris", () => {
    /**
     * Craft ouvre son menu sur eux, et c'est le seul endroit d'où l'on
     * atteint une note en un clic sans savoir où elle est rangée.
     */
    it("lists what is pinned, above the tree", async () => {
        const wrapper = await render();

        expect(wrapper.text()).toContain("library.favorites");

        expect(wrapper.findAll("[data-favorite-row]")).toHaveLength(1);
        expect(wrapper.find("[data-favorite-row]").text()).toBe("Journal");
    });

    it("hides them while a search is running", async () => {
        const wrapper = await render();

        await wrapper.find("input").setValue("recett");
        await flushPromises();

        expect(wrapper.text()).not.toContain("library.favorites");
    });
});

describe("what the panel kept from the aside", () => {
    it("offers the row actions the tree used to have", async () => {
        const titles = (await render())
            .findAll("button")
            .map((b) => b.attributes("title"))
            .filter(Boolean);

        expect(titles.some((t) => t.includes("create_in_folder"))).toBe(true);
        expect(titles.some((t) => t.includes("folders.delete"))).toBe(true);
    });

    it("lets a row be dragged", async () => {
        const row = (await render()).find("[data-folder-row]");

        expect(row.attributes("draggable")).toBe("true");
    });

    /**
     * The buttons sit beside the link, never inside it. Nested interactive
     * content is invalid HTML, and it cost a page reload: the buttons stop
     * the click, so the row never got to cancel the link's navigation.
     */
    it("keeps its buttons out of the link", async () => {
        const wrapper = await render();

        expect(wrapper.findAll("a button")).toHaveLength(0);
    });

    /**
     * The bug the reader hit: something created in the editor did not show
     * up until the page was reloaded, because the panel had fetched its list
     * once on arrival and nothing ever told it otherwise.
     */
    it("takes the page's word for the list when it changes", async () => {
        const wrapper = await render("/backend/notes/markdown", {
            expanded: [1],
        });
        expect(folderLinks(wrapper)).toHaveLength(3);

        tellPanels("notes:changed", {
            folders: [...FOLDERS, { id: 4, name: "Neuf", parentId: null }],
        });
        await flushPromises();

        expect(folderLinks(wrapper).map((a) => a.text())).toContain("Neuf");
    });
});
