import { describe, it, expect, vi, beforeEach } from "vitest";
import { defineComponent, h, nextTick } from "vue";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";

vi.mock("vue-sonner", () => ({
    toast: { error: vi.fn(), success: vi.fn() },
}));

const { useNotesEditor } = await import("./useNotesEditor.js");

const NOTES = [
    { id: 1, title: "Clients", parentId: null },
    { id: 2, title: "Studio Lumen", parentId: 1 },
];

function editorWith(api) {
    let editor;

    const Comp = defineComponent({
        setup() {
            editor = useNotesEditor({ api, initialNotes: NOTES });

            return () => h("div");
        },
    });

    mount(Comp, { global: { plugins: [createTestI18n({}, "fr")] } });

    return editor;
}

/** Une API qui répond, puis échoue, pour rejouer la bascule qui a posé problème. */
function apiThatFailsAfterTheFirstLoad() {
    const update = vi.fn().mockResolvedValue({ ok: true, payload: {} });
    let calls = 0;

    return {
        update,
        show: vi.fn().mockImplementation((id) => {
            calls += 1;

            if (1 === calls) {
                return Promise.resolve({
                    ok: true,
                    payload: {
                        note: {
                            id,
                            title: "Clients",
                            content: "Le texte de la note 1.",
                            tags: [],
                        },
                    },
                });
            }

            return Promise.resolve({ ok: false, reported: true });
        }),
    };
}

describe("useNotesEditor", () => {
    beforeEach(() => vi.clearAllMocks());

    it("charge la note demandée", async () => {
        const api = {
            show: vi.fn().mockResolvedValue({
                ok: true,
                payload: {
                    note: {
                        id: 2,
                        title: "Studio Lumen",
                        content: "Texte.",
                        tags: [],
                    },
                },
            }),
            update: vi.fn(),
        };

        const editor = editorWith(api);
        await editor.selectNote(2);

        expect(editor.form.value.title).toBe("Studio Lumen");
    });

    /**
     * L'habillage se sauvegarde comme le texte.
     *
     * Il ne l'a pas fait tout de suite : la détection de modification
     * comparait le titre, le contenu et les étiquettes, et rien d'autre.
     * Déplacer le cadrage laissait donc le formulaire « propre », et le
     * réglage disparaissait au rechargement suivant.
     */
    it("écrit le bandeau et l'apparence comme le reste", async () => {
        const api = {
            show: vi.fn().mockResolvedValue({
                ok: true,
                payload: {
                    note: {
                        id: 2,
                        title: "Studio Lumen",
                        content: "Texte.",
                        tags: [],
                        coverUrl: "https://images.pexels.com/photos/1/a.jpeg",
                        coverPosition: 50,
                        appearance: "plain",
                    },
                },
            }),
            update: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
        };

        const editor = editorWith(api);
        await editor.selectNote(2);

        expect(editor.isDirty.value).toBe(false);

        editor.form.value.coverPosition = 20;
        await nextTick();

        expect(editor.isDirty.value, "le cadrage compte comme une modification").toBe(true);

        await editor.saveSelected();

        expect(api.update).toHaveBeenCalledWith(
            2,
            expect.objectContaining({ coverPosition: 20 }),
        );

        editor.form.value.appearance = "sepia";
        await nextTick();

        expect(editor.isDirty.value, "l'apparence aussi").toBe(true);
    });

    // Le défaut : `selectNote` posait l'identifiant demandé avant d'avoir la
    // réponse, et sortait sur échec sans toucher au formulaire. L'éditeur
    // affichait donc la note précédente en face du nouvel identifiant, et la
    // sauvegarde automatique écrivait l'une par-dessus l'autre.
    it("n'écrit pas l'ancienne note par-dessus la nouvelle quand le chargement échoue", async () => {
        const api = apiThatFailsAfterTheFirstLoad();
        const editor = editorWith(api);

        await editor.selectNote(1);
        expect(editor.form.value.content).toBe("Le texte de la note 1.");

        await editor.selectNote(2);

        // Le formulaire n'appartient plus à personne tant que rien n'est chargé.
        expect(editor.isDirty.value).toBe(false);

        editor.form.value.content = "Une frappe de plus.";
        await nextTick();
        await editor.saveSelected();

        expect(api.update).not.toHaveBeenCalled();
    });

    // Le second défaut, celui qui se voyait : l'écran demande deux notes coup
    // sur coup au chargement, et quand les réponses revenaient dans le
    // désordre, la première écrasait la seconde. Ouvrir le lien d'une note en
    // montrait une autre.
    it("ignore la réponse d'une note qu'on a quittée entre-temps", async () => {
        let releaseFirst;
        const firstAnswered = new Promise((resolve) => {
            releaseFirst = resolve;
        });

        const api = {
            update: vi.fn(),
            show: vi.fn().mockImplementation((id) => {
                if (1 === id) {
                    return firstAnswered.then(() => ({
                        ok: true,
                        payload: {
                            note: {
                                id: 1,
                                title: "Clients",
                                content: "Note 1.",
                                tags: [],
                            },
                        },
                    }));
                }

                return Promise.resolve({
                    ok: true,
                    payload: {
                        note: {
                            id: 2,
                            title: "Studio Lumen",
                            content: "Note 2.",
                            tags: [],
                        },
                    },
                });
            }),
        };

        const editor = editorWith(api);

        const slow = editor.selectNote(1);
        await editor.selectNote(2);

        // La réponse de la note 1 arrive après coup.
        releaseFirst();
        await slow;

        expect(editor.form.value.title).toBe("Studio Lumen");
    });

    it("enregistre de nouveau une fois la note vraiment chargée", async () => {
        const api = {
            update: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            show: vi.fn().mockResolvedValue({
                ok: true,
                payload: {
                    note: {
                        id: 2,
                        title: "Studio Lumen",
                        content: "Texte.",
                        tags: [],
                    },
                },
            }),
        };

        const editor = editorWith(api);
        await editor.selectNote(2);
        editor.form.value.content = "Texte modifié.";
        await nextTick();
        await editor.saveSelected();

        expect(api.update).toHaveBeenCalledWith(
            2,
            expect.objectContaining({ content: "Texte modifié." }),
        );
    });
});
