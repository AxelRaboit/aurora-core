import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import { askPage, onPageNotice } from "@/shared/nav/modulePanelBridge.js";

window.__isAdmin__ = true;

/**
 * Reposé avant chaque cas, et pas une fois pour toutes.
 *
 * `vi.restoreAllMocks()` rend à un `vi.fn()` son implémentation vide : posé
 * au chargement du module, `matchMedia` cessait de répondre dès le deuxième
 * cas, et la page se montait alors sans savoir si elle est sur un téléphone.
 */
function installMatchMedia() {
    window.matchMedia = vi.fn().mockImplementation((query) => ({
        matches: false,
        media: query,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        addListener: vi.fn(),
        removeListener: vi.fn(),
        dispatchEvent: vi.fn(),
    }));
}

installMatchMedia();

const MarkdownNotesApp = (await import("./MarkdownNotesApp.vue")).default;

const i18n = createTestI18n();

const PATHS = [
    "listPath",
    "showPath",
    "createPath",
    "updatePath",
    "deletePath",
    "movePath",
    "reorderPath",
    "backlinksPath",
    "unlinkedMentionsPath",
    "graphPath",
    "exportPath",
    "exportOnePath",
    "importPath",
    "searchPath",
    "tagsListPath",
    "tagsRenamePath",
    "tagsMergePath",
    "tagsDeletePath",
    "sharesListPath",
    "sharesPreviewPath",
    "sharesCreatePath",
    "sharesRevokePath",
    "imageUploadPath",
].reduce((all, name) => ({ ...all, [name]: `/notes/${name}` }), {});

PATHS.libraryPath = "/notes/library";
PATHS.folderPaths = {
    list: "/notes/folders",
    create: "/notes/folders/create",
    update: "/notes/folders/__id__/update",
    move: "/notes/folders/__id__/move",
    delete: "/notes/folders/__id__/delete",
    reorder: "/notes/folders/reorder",
    show: "/notes/folders/__id__",
};
PATHS.spacePaths = {
    list: "/notes/spaces",
    create: "/notes/spaces/create",
    show: "/notes/spaces/__id__",
    update: "/notes/spaces/__id__/update",
    delete: "/notes/spaces/__id__/delete",
    membersSet: "/notes/spaces/__id__/members",
    membersRemove: "/notes/spaces/__id__/members/__user__/remove",
    people: "/notes/spaces/people",
};

const NOTES = [
    { id: 1, title: "Journal", folderId: null, tags: [] },
    { id: 2, title: "Devis Lumen", folderId: 7, tags: [] },
];
const FOLDERS = [
    { id: 7, name: "Clients", parentId: null, noteCount: 1, folderCount: 0 },
];

/**
 * Le menu de la note : ce qui se fait une fois par note y vit, plutôt que
 * d'occuper la ligne du titre.
 */
async function openNoteMenu(wrapper) {
    const trigger = wrapper
        .findAll("button")
        // Le bouton « Actions » de la barre (AppPageActions depuis le 02/10/2026).
        .find((b) => "shared.actions.plain_title" === b.attributes("title"));

    await trigger.trigger("click");
    await flushPromises();

    return [...document.body.querySelectorAll("button, a")];
}

const mounted = [];

function render(props = {}) {
    const wrapper = mount(MarkdownNotesApp, {
        props: { ...PATHS, notes: NOTES, ...props },
        global: { plugins: [i18n] },
    });
    mounted.push(wrapper);

    return wrapper;
}

beforeEach(() => {
    installMatchMedia();
    global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        json: async () => ({
            success: true,
            notes: NOTES,
            folders: FOLDERS,
            note: NOTES[0],
            tags: [],
        }),
    });
});

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
    vi.restoreAllMocks();
});

describe("the notes page, once its tree moved to the menu", () => {
    /**
     * A hundred and thirty-nine lines of template came out - the widest aside
     * of the six. Vue resolves template references at render, so a name left
     * behind is invisible to the linter and to the bundler.
     */
    it("still mounts, with no reference left behind", () => {
        const wrapper = render();

        expect(wrapper.html()).toBeTruthy();
        expect(wrapper.find("aside").exists()).toBe(false);
    });

    it("answers the panel when it asks to open a note", async () => {
        render();
        await flushPromises();

        expect(askPage("notes:select", { args: [1] })).toBe(true);
    });

    it("answers the panel when it asks to create one", async () => {
        render();
        await flushPromises();

        expect(askPage("notes:create", { args: [null] })).toBe(true);
    });

    /** Nothing must answer once the editor is gone. */
    it("stops answering after it unmounts", async () => {
        const wrapper = render();
        await flushPromises();
        wrapper.unmount();
        mounted.pop();

        expect(askPage("notes:select", { args: [1] })).toBe(false);
    });
});

/**
 * Une note peut porter son propre fond : le chemin de retour ne doit donc
 * pas vivre dessus. Sur du papier blanc, son survol prenait la couleur
 * d'encre du back-office - presque blanche en thème sombre - et le lien
 * disparaissait.
 */
describe("le chemin de retour", () => {
    it("lives outside the note's own card", async () => {
        const wrapper = render();
        await flushPromises();
        askPage("notes:select", { args: [1] });
        await flushPromises();

        const back = wrapper
            .findAll("button")
            .find((b) => b.text().includes("notes.markdown.library.title"));

        expect(back, "le lien de retour est là").toBeTruthy();
        expect(
            back.element.closest("header"),
            "il n'est pas dans l'en-tête de la note",
        ).toBeNull();
    });
});

describe("les étiquettes de l'éditeur", () => {
    /**
     * Les étiquettes vivent dans le menu de la note depuis que la ligne du
     * titre a été allégée : douze commandes ne laissaient plus de place au
     * titre lui-même.
     */
    async function tagsEntry(wrapper) {
        const entrees = await openNoteMenu(wrapper);

        return entrees.find(
            (node) =>
                node.textContent.includes("tags.add_placeholder") ||
                node.textContent.includes("tags.summary"),
        );
    }

    /** L'éditeur n'est à l'écran qu'une fois une note ouverte. */
    async function editing(tags = []) {
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({
                success: true,
                notes: NOTES,
                folders: FOLDERS,
                note: { ...NOTES[0], tags },
                tags: [],
            }),
        });

        const wrapper = render();
        await flushPromises();
        askPage("notes:select", { args: [1] });
        await flushPromises();

        return wrapper;
    }

    /**
     * Elles prenaient une ligne entière de l'en-tête en permanence, pour une
     * chose qu'on touche quand la note naît et plus guère ensuite.
     */
    it("keeps its row out of the way until it is asked for", async () => {
        const wrapper = await editing();

        expect(wrapper.findComponent({ name: "AppTagsInput" }).exists()).toBe(
            false,
        );

        const entree = await tagsEntry(wrapper);

        expect(entree, "l'entrée des étiquettes est là").toBeTruthy();

        entree.click();
        await flushPromises();

        expect(wrapper.findComponent({ name: "AppTagsInput" }).exists()).toBe(
            true,
        );
    });

    /**
     * Dans un menu, c'est le libellé qui nomme les étiquettes - ce qui en
     * dit plus que le chiffre que portait l'icône.
     */
    it("names them in the entry itself", async () => {
        const wrapper = await editing(["client", "photo"]);
        const entree = await tagsEntry(wrapper);

        expect(entree.textContent).toContain("client, photo");
    });
});

/**
 * Ouvrir le partage effaçait la page.
 *
 * `api.preview` n'existait pas : la route était là, le chemin était passé au
 * composant, et l'appel partait d'un `watch` sur l'ouverture de la modale.
 * L'exception y devenait un rejet non traité - invisible - jusqu'à ce que la
 * page se dote d'un garde-fou, qui l'a rendue spectaculaire.
 */
describe("le partage", () => {
    it("opens without taking the page down with it", async () => {
        const failures = [];
        const spy = vi
            .spyOn(console, "error")
            .mockImplementation((...args) =>
                failures.push(args.map(String).join(" ")),
            );

        const wrapper = render();
        await flushPromises();
        askPage("notes:select", { args: [1] });
        await flushPromises();

        const entrees = await openNoteMenu(wrapper);
        const share = entrees.find((node) =>
            node.textContent.includes("share.button"),
        );

        expect(share, "l'entrée de partage est là").toBeTruthy();

        share.click();
        await flushPromises();

        spy.mockRestore();

        expect(failures.join("\n")).not.toContain("la page a échoué");
        expect(wrapper.text()).not.toContain("Aucune donnée à afficher");
    });
});

describe("what the page tells the panel", () => {
    /**
     * The bug the reader hit: a note created in the editor did not reach the
     * tree until the page was reloaded. The panel fetches once on arrival; from
     * then on this is the only thing that keeps it true.
     */
    it("announces its list so the tree can follow", async () => {
        const heard = vi.fn();
        const stop = onPageNotice("notes:changed", heard);

        render();
        await flushPromises();

        expect(heard).toHaveBeenCalled();
        expect(heard.mock.calls.at(-1)[0].notes).toBeInstanceOf(Array);
        stop();
    });

    /** Every row action the tree used to do itself is answered here. */
    it("answers every intent the panel can send", async () => {
        render();
        await flushPromises();

        // Plausible arguments, because the page runs the real handler: an
        // empty list would have them dereferencing an event that is not there.
        const note = NOTES[0];
        const event = {
            preventDefault: () => {},
            stopPropagation: () => {},
            currentTarget: { contains: () => false },
            relatedTarget: null,
            dataTransfer: {
                types: [],
                setData: () => {},
                getData: () => "",
                effectAllowed: "",
                dropEffect: "",
            },
        };

        for (const [intent, args] of [
            ["select", [note.id]],
            ["create", [null]],
            ["delete", [note]],
            ["open-folder", [7]],
            ["create-folder", [null]],
            ["delete-folder", [FOLDERS[0]]],
            ["drop", [FOLDERS[0], event]],
        ]) {
            expect(askPage(`notes:${intent}`, { args })).toBe(true);
        }
    });
});

/**
 * Ce que le panneau demande doit changer l'écran, pas seulement trouver
 * quelqu'un au bout du fil.
 *
 * Le test précédent vérifiait que l'intention était *répondue* - `askPage`
 * rend vrai dès qu'un écouteur existe - ce qui laissait passer une
 * bibliothèque qui n'avait jamais reçu l'ordre. Axel a cliqué sur un
 * dossier et est resté sur « Tous les documents ».
 */
describe("ouvrir un dossier depuis le panneau", () => {
    it("shows what the folder holds, not the root", async () => {
        const wrapper = render();
        await flushPromises();

        expect(wrapper.text()).toContain("Journal");

        askPage("notes:open-folder", { args: [7] });
        await flushPromises();

        // Le dossier contient « Devis Lumen » et rien d'autre ; « Journal »
        // est à la racine, donc il disparaît de la grille.
        const cards = wrapper.findAll("article").map((one) => one.text());
        expect(cards.some((text) => text.includes("Devis Lumen"))).toBe(true);
        expect(cards.some((text) => text.includes("Journal"))).toBe(false);
    });

    it("comes back to the root when the panel asks for it", async () => {
        const wrapper = render();
        await flushPromises();

        askPage("notes:open-folder", { args: [7] });
        await flushPromises();

        askPage("notes:open-folder", { args: [null] });
        await flushPromises();

        const cards = wrapper.findAll("article").map((one) => one.text());
        expect(cards.some((text) => text.includes("Journal"))).toBe(true);
    });
});

/**
 * Depuis l'éditeur, la bibliothèque n'est pas montée. Elle vit pourtant
 * dans la même application : quitter l'une pour l'autre est un changement
 * d'affichage, pas un rechargement - celui-ci jetait le défilement, la
 * sélection et l'arbre déplié pour revenir au même endroit.
 */
describe("ouvrir un dossier avec une note ouverte", () => {
    it("swaps the editor for the folder, without reloading", async () => {
        const assign = vi.fn();
        const original = window.location;
        Object.defineProperty(window, "location", {
            configurable: true,
            value: {
                ...original,
                assign,
                pathname: "/backend/notes/markdown/1",
            },
        });

        const wrapper = render({ activeId: 1 });
        await flushPromises();

        // Une note est ouverte : l'éditeur occupe la place, pas la grille.
        expect(wrapper.findAll("article")).toHaveLength(0);

        askPage("notes:open-folder", { args: [7] });
        await flushPromises();

        const cards = wrapper.findAll("article").map((one) => one.text());
        expect(cards.some((text) => text.includes("Devis Lumen"))).toBe(true);
        expect(assign, "aucun rechargement").not.toHaveBeenCalled();

        Object.defineProperty(window, "location", {
            configurable: true,
            value: original,
        });
    });

    it("opens the folder dialog after coming back from a note", async () => {
        const wrapper = render({ activeId: 1 });
        await flushPromises();

        askPage("notes:create-folder", { args: [null] });
        await flushPromises();

        expect(wrapper.findAll("article").length).toBeGreaterThan(0);
        expect(document.body.textContent).toContain("folders.create");

        document.body.innerHTML = "";
    });
});

/**
 * Supprimer un dossier depuis le menu emporte ses notes, dont peut-être
 * celle qu'on est en train d'écrire : la laisser ouverte ferait écrire
 * l'enregistrement automatique dans une note à la corbeille.
 */
describe("la note ouverte disparaît sous nos pieds", () => {
    it("closes the editor when the note is no longer in the list", async () => {
        const wrapper = render({ activeId: 1 });
        await flushPromises();

        // L'éditeur tient la note 1.
        expect(wrapper.findAll("article")).toHaveLength(0);

        // Le serveur ne la rend plus : elle est partie avec son dossier.
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ success: true, notes: [], folders: FOLDERS }),
        });

        askPage("notes:delete-folder", { args: [{ id: 7 }] });
        await flushPromises();

        // Retour à la bibliothèque plutôt qu'un éditeur sur du vide.
        expect(wrapper.text()).toContain("library.title");
        document.body.innerHTML = "";
    });
});

describe("deleting a note the panel asked to delete", () => {
    afterEach(() => {
        document.body.innerHTML = "";
    });

    /**
     * The reader saw the modal appear and do nothing. The whole round trip is
     * pinned here because it crosses three things at once: the bridge, a modal
     * that teleports out of the component, and the delete call itself.
     */
    it("opens the confirmation and deletes on confirm", async () => {
        render();
        await flushPromises();

        expect(askPage("notes:delete", { args: [NOTES[0]] })).toBe(true);
        await flushPromises();

        // The confirmation names the note through a translation parameter the
        // test i18n does not fill, so the key is what shows.
        expect(document.body.textContent).toContain("notes.markdown.delete");

        const confirm = [...document.body.querySelectorAll("button")].find(
            (b) => /delete|confirm/.test(b.textContent),
        );
        expect(confirm, "the confirmation button is on screen").toBeTruthy();

        confirm.click();
        await flushPromises();

        const called = global.fetch.mock.calls
            .map(([url]) => String(url))
            .some((url) => url.includes("deletePath"));
        expect(called, "the delete endpoint was called").toBe(true);
    });
});

/**
 * The graph had every part but the way in: the component was mounted, wired
 * to its endpoint and translated, and `graphOpen` was never set to true. The
 * page of documentation about it published a black rectangle, because there
 * was nothing to photograph.
 */
describe("the way into the graph", () => {
    // Le graphe est une commande de l'éditeur : sans note ouverte, la page
    // montre la bibliothèque, qui n'a pas de bouton pour lui.
    it("opens the graph when its button is pressed", async () => {
        const wrapper = render({ activeId: 1 });
        await flushPromises();

        // La note s'ouvre par le pont, comme le ferait le panneau : c'est le
        // même chemin que celui d'un lecteur, et il ne dépend pas de l'ordre
        // dans lequel les cas de ce fichier se suivent.
        askPage("notes:select", { args: [1] });
        await flushPromises();

        const graph = wrapper.findComponent({ name: "NoteGraph" });
        expect(graph.props("show")).toBe(false);

        const entrees = await openNoteMenu(wrapper);
        const button = entrees.find((node) =>
            node.textContent.includes("notes.markdown.graph.open"),
        );

        expect(button, "aucune entrée pour ouvrir le graphe").toBeDefined();

        button.click();
        await flushPromises();

        expect(wrapper.findComponent({ name: "NoteGraph" }).props("show")).toBe(
            true,
        );
    });
});

describe("ce que le panneau demande depuis l'éditeur", () => {
    /** Les appels faits, adresse et corps, dans l'ordre. */
    const calls = () =>
        global.fetch.mock.calls.map(([url, init]) => ({
            url: String(url),
            body: init?.body ? JSON.parse(init.body) : null,
        }));

    /**
     * Le bug d'Axel : une note glissée sur un dossier pendant qu'une note est
     * ouverte ne bougeait pas. La page écrit maintenant le dépôt elle-même,
     * sans passer par la bibliothèque.
     */
    it("files a dropped note even while a note is open", async () => {
        render();
        await flushPromises();
        askPage("notes:select", { args: [1] });
        await flushPromises();
        global.fetch.mockClear();

        expect(
            askPage("notes:move", {
                args: [
                    {
                        kind: "note",
                        id: 1,
                        folderId: 7,
                        fromFolderId: null,
                        order: [
                            { kind: "note", id: 2 },
                            { kind: "note", id: 1 },
                        ],
                    },
                ],
            }),
        ).toBe(true);
        await flushPromises();

        const made = calls();
        expect(made[0]).toEqual({
            url: "/notes/movePath",
            body: { folderId: 7, spaceId: null },
        });
        expect(made[1]).toEqual({
            url: "/notes/reorderPath",
            body: {
                entries: [
                    { id: 2, folderId: 7, position: 0 },
                    { id: 1, folderId: 7, position: 1 },
                ],
            },
        });
    });

    it("only reorders when the item stays in its folder", async () => {
        render();
        await flushPromises();
        global.fetch.mockClear();

        askPage("notes:move", {
            args: [
                {
                    kind: "folder",
                    id: 7,
                    folderId: null,
                    fromFolderId: null,
                    order: [{ kind: "folder", id: 7 }],
                },
            ],
        });
        await flushPromises();

        const made = calls();
        expect(made.some((one) => one.url.includes("/move"))).toBe(false);
        expect(made[0]).toEqual({
            url: "/notes/folders/reorder",
            body: { entries: [{ id: 7, parentId: null, position: 0 }] },
        });
    });

    /**
     * Dossiers et notes partagent un ordre : chacun part par la route de sa
     * nature, avec son rang dans la liste mêlée.
     */
    it("sends a mixed order through both reorder routes", async () => {
        render();
        await flushPromises();
        global.fetch.mockClear();

        askPage("notes:move", {
            args: [
                {
                    kind: "note",
                    id: 2,
                    folderId: 7,
                    fromFolderId: 7,
                    order: [
                        { kind: "note", id: 2 },
                        { kind: "folder", id: 9 },
                        { kind: "note", id: 3 },
                    ],
                },
            ],
        });
        await flushPromises();

        const made = calls();
        expect(made).toContainEqual({
            url: "/notes/folders/reorder",
            body: { entries: [{ id: 9, parentId: 7, position: 1 }] },
        });
        expect(made).toContainEqual({
            url: "/notes/reorderPath",
            body: {
                entries: [
                    { id: 2, folderId: 7, position: 0 },
                    { id: 3, folderId: 7, position: 2 },
                ],
            },
        });
    });

    /** Le plus d'un dossier ouvre une modale qui crée une note ou un dossier. */
    it("creates a folder where the plus was pressed", async () => {
        render();
        await flushPromises();
        global.fetch.mockClear();

        askPage("notes:add", { args: [7] });
        await flushPromises();

        document.body.querySelector('[data-add-kind="folder"]').click();
        await flushPromises();

        const input = document.body.querySelector(
            "[data-add-name] input, input[data-add-name]",
        );
        input.value = "Devis 2026";
        input.dispatchEvent(new Event("input"));
        await flushPromises();

        document.body.querySelector("[data-add-submit]").click();
        await flushPromises();

        expect(calls()[0]).toEqual({
            url: "/notes/folders/create",
            body: {
                name: "Devis 2026",
                parentId: 7,
                color: null,
                spaceId: null,
            },
        });
    });

    /** Qui en a le droit crée un espace depuis la même modale. */
    it("creates a space from the add modal", async () => {
        render({ canCreateSpace: true });
        await flushPromises();
        global.fetch.mockClear();

        askPage("notes:add", { args: [null] });
        await flushPromises();

        document.body.querySelector('[data-add-kind="space"]').click();
        await flushPromises();

        const input = document.body.querySelector(
            "[data-add-name] input, input[data-add-name]",
        );
        input.value = "Documentation";
        input.dispatchEvent(new Event("input"));
        await flushPromises();

        document.body.querySelector("[data-add-submit]").click();
        await flushPromises();

        expect(calls()[0]).toEqual({
            url: "/notes/spaces/create",
            body: {
                name: "Documentation",
                color: null,
                access: "backoffice",
                defaultRole: "reader",
            },
        });
    });

    it("offers no space without the right to create one", async () => {
        render();
        await flushPromises();

        askPage("notes:add", { args: [null] });
        await flushPromises();

        expect(
            document.body.querySelector('[data-add-kind="space"]'),
        ).toBeNull();
    });

    /** Le plus d'un en-tête d'espace range à la racine de cet espace. */
    it("files a new note at the root of the space it was asked for", async () => {
        render({
            spaces: [
                { id: 1, personal: true, canWrite: true },
                { id: 5, name: "Équipe", personal: false, canWrite: true },
            ],
        });
        await flushPromises();
        global.fetch.mockClear();

        askPage("notes:add", { args: [{ folderId: null, spaceId: 5 }] });
        await flushPromises();

        expect(
            document.body.querySelector("[data-add-where]").textContent,
        ).toContain("Équipe");

        document.body.querySelector("[data-add-submit]").click();
        await flushPromises();

        expect(calls()[0]).toEqual({
            url: "/notes/createPath",
            body: { folderId: null, spaceId: 5, title: "", content: "" },
        });
    });

    it("writes where the open note lives, from the root", async () => {
        const wrapper = render({ folders: FOLDERS });
        await flushPromises();
        askPage("notes:select", { args: [2] });
        await flushPromises();

        const crumbs = wrapper
            .findAll("[data-note-crumb]")
            .map((one) => one.text());

        expect(crumbs).toEqual(["Clients"]);
    });
});

describe("emporter un espace seul", () => {
    /** L'en-tête d'un espace exporte cet espace, et lui seul. */
    it("exports the space the panel asks for", async () => {
        const assign = vi.fn();
        const original = window.location;
        Object.defineProperty(window, "location", {
            configurable: true,
            value: { ...original, assign },
        });

        render();
        await flushPromises();

        askPage("notes:export", { args: [5] });
        await flushPromises();
        askPage("notes:export", { args: [] });
        await flushPromises();

        expect(assign.mock.calls.map((call) => call[0])).toEqual([
            "/notes/exportPath?spaceId=5",
            "/notes/exportPath",
        ]);

        Object.defineProperty(window, "location", {
            configurable: true,
            value: original,
        });
    });
});
