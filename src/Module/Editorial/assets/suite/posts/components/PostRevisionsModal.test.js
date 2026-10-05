import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";

const request = vi.fn();

/** The wording the panel actually shows, so the assertions read like the UI. */
const MESSAGES = {
    suite: {
        posts: {
            field_title: "Titre",
            field_slug: "Slug",
            field_description: "Résumé",
            status: { published: "Publiée", draft: "Brouillon" },
            revisions: {
                title: "Historique des versions",
                empty: "Aucune version enregistrée pour le moment.",
                this_version: "Cette version",
                current_version: "Version actuelle",
                body: "Contenu",
                no_author: "Auteur inconnu",
                no_translation: "Cette version ne contenait pas cette langue.",
                restore: "Restaurer cette version",
                restore_now: "Confirmer la restauration",
                restore_confirm: "Le contenu actuel sera remplacé.",
            },
        },
    },
};

vi.mock("@/shared/composables/http/suite/useRequest.js", () => ({
    useRequest: () => ({ request }),
}));

const PostRevisionsModal = (await import("./PostRevisionsModal.vue")).default;

const REVISIONS = [
    {
        id: 9,
        status: "published",
        createdAt: "2026-09-10T16:39:00+00:00",
        author: { id: 1, email: "dev@aurora.app" },
    },
    {
        id: 8,
        status: "draft",
        createdAt: "2026-09-09T08:00:00+00:00",
        author: null,
    },
];

const SNAPSHOT = {
    id: 9,
    status: "published",
    snapshot: {
        status: "published",
        translations: {
            fr: {
                title: "Le titre d'avant",
                slug: "le-titre-d-avant",
                description: "Le résumé d'avant.",
                grid: {
                    zones: {
                        a: {
                            blocks: [
                                { data: { text: "<b>Le corps</b> d'avant" } },
                            ],
                        },
                    },
                },
            },
        },
    },
};

function render(props = {}) {
    return mount(PostRevisionsModal, {
        props: {
            show: true,
            postId: 3,
            locale: "fr",
            current: {
                title: "Le titre d'aujourd'hui",
                slug: "le-titre-d-aujourd-hui",
                description: "Le résumé d'aujourd'hui.",
                grid: { zones: {} },
            },
            listPathTemplate: "/posts/__id__/revisions",
            showPathTemplate: "/posts/__id__/revisions/__revisionId__",
            restorePathTemplate:
                "/posts/__id__/revisions/__revisionId__/restore",
            canRestore: true,
            ...props,
        },
        global: { plugins: [createTestI18n(MESSAGES)] },
        // The real Teleport: AppModal renders into the body, so a wrapper
        // queried on its own subtree finds nothing at all.
        attachTo: document.body,
    });
}

/** The modal lives in the body, so its buttons are found there. */
function byText(label) {
    const button = [...document.querySelectorAll("button")].find((node) =>
        node.textContent.includes(label),
    );

    if (!button) throw new Error(`aucun bouton « ${label} »`);

    return button;
}

describe("PostRevisionsModal", () => {
    let wrapper;

    afterEach(() => {
        wrapper?.unmount();
        document.body.innerHTML = "";
    });

    beforeEach(() => {
        request.mockReset();
        request.mockImplementation((url) => {
            if (url.endsWith("/revisions")) {
                return Promise.resolve({ success: true, revisions: REVISIONS });
            }

            if (url.endsWith("/restore")) {
                return Promise.resolve({ success: true, post: { id: 3 } });
            }

            return Promise.resolve({ success: true, revision: SNAPSHOT });
        });
    });

    it("lists the revisions it is given", async () => {
        wrapper = render();
        await flushPromises();

        expect(document.querySelectorAll("ol button")).toHaveLength(2);
        expect(document.body.textContent).toContain("dev@aurora.app");
    });

    /** The point of the panel: seeing which version you are looking at. */
    it("shows the revision beside the current text", async () => {
        wrapper = render();
        await flushPromises();

        const shown = document.body.textContent;

        expect(shown).toContain("Le titre d'avant");
        expect(shown).toContain("Le titre d'aujourd'hui");
        // The body arrives as editor blocks; markup has no place in a summary.
        expect(shown).toContain("Le corps d'avant");
        expect(shown).not.toContain("<b>");
    });

    /**
     * Restoring overwrites what is on screen, so it takes two clicks. One
     * click on a row that was selected by simply opening the panel would be
     * a way to lose an afternoon's work by mis-aiming.
     */
    it("asks for confirmation before restoring", async () => {
        wrapper = render();
        await flushPromises();

        byText("Restaurer cette version").click();
        await flushPromises();

        expect(request).not.toHaveBeenCalledWith(
            expect.stringContaining("/restore"),
        );

        byText("Confirmer la restauration").click();
        await flushPromises();

        expect(request).toHaveBeenCalledWith("/posts/3/revisions/9/restore");
        expect(wrapper.emitted("restored")).toBeTruthy();
    });

    /** A reader with no write permission gets the history, not the button. */
    it("hides the restore button without the permission", async () => {
        wrapper = render({ canRestore: false });
        await flushPromises();

        expect(document.body.textContent).not.toContain(
            "Restaurer cette version",
        );
    });

    it("says so when the revision predates the language", async () => {
        request.mockImplementation((url) =>
            url.endsWith("/revisions")
                ? Promise.resolve({ success: true, revisions: REVISIONS })
                : Promise.resolve({
                      success: true,
                      revision: {
                          ...SNAPSHOT,
                          snapshot: { status: "draft", translations: {} },
                      },
                  }),
        );

        wrapper = render();
        await flushPromises();

        expect(document.body.textContent).toContain(
            "ne contenait pas cette langue",
        );
    });
});
