import { beforeEach, describe, expect, it, vi } from "vitest";
// Relative rather than aliased: the Studio module keeps its assets under
// its sub-domains (Contract/assets, Customer/assets) instead of at the module
// root, so there is no `@studio` alias to import through.
import { useContractTemplatesList } from "./useContractTemplatesList.js";

const request = vi.fn();

vi.mock("vue-i18n", () => ({
    useI18n: () => ({ t: (key) => key }),
}));

vi.mock("vue-sonner", () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

vi.mock("@/shared/composables/http/backend/useRequest.js", () => ({
    useRequest: () => ({ request }),
}));

/**
 * An action taken on a row answers on that row.
 *
 * The list is what the reader was looking at, and none of these actions is a
 * request to leave it: opening a draft, duplicating and abandoning all show
 * their result in the row they were taken from. This is asserted rather than
 * trusted because the failure is invisible in review - a stray
 * `location.assign` reads like a convenience until somebody loses their place
 * in a list of thirty trames.
 */
const BODY = {
    id: 1,
    name: "Contrat mensuel",
    kind: "body",
    locales: ["fr"],
    isArchived: false,
    publishedVersion: 2,
    draftId: null,
    draftVersion: null,
    category: "community_management",
};

const ANNEX = {
    id: 2,
    name: "Annexe 1 - Formule Minimal",
    kind: "annex",
    locales: ["fr", "en"],
    isArchived: false,
    publishedVersion: 1,
    draftId: 77,
    draftVersion: 2,
    // Never classified, which is a state of its own and not a missing value.
    category: null,
};

function list(templates = [BODY, ANNEX]) {
    return useContractTemplatesList({
        templates,
        kinds: [{ value: "body" }, { value: "annex" }],
        categories: [
            { value: "community_management" },
            { value: "photography" },
            { value: "development" },
        ],
        createPath: "/create",
        updatePath: "/__id__/update",
        archivePath: "/__id__/archive",
        restorePath: "/__id__/restore",
        deletePath: "/__id__/delete",
        openDraftPath: "/__id__/open-draft",
        duplicatePath: "/__id__/duplicate",
        discardDraftPath: "/__id__/versions/__versionId__/discard",
        editorPath: "/__id__/versions/__versionId__",
    });
}

let assign;

beforeEach(() => {
    request.mockReset();
    assign = vi.fn();
    // jsdom refuses a real navigation, so the spy is the only way to catch one
    // being attempted at all.
    Object.defineProperty(window, "location", {
        configurable: true,
        value: { ...window.location, assign, search: "" },
    });
});

describe("useContractTemplatesList", () => {
    it("abandons a draft without leaving the list", async () => {
        request.mockResolvedValue({
            success: true,
            indexPath: "/backend/studio/contract-templates",
            templates: [BODY, { ...ANNEX, draftId: null, draftVersion: null }],
        });

        const state = list();
        state.pendingDiscard.value = ANNEX;
        await state.confirmDiscard();

        expect(request).toHaveBeenCalledWith("/2/versions/77/discard", {});
        expect(assign).not.toHaveBeenCalled();
        expect(state.visibleItems.value[1].draftId).toBeNull();
    });

    it("opens a draft without leaving the list", async () => {
        request.mockResolvedValue({
            success: true,
            draftId: 90,
            templates: [{ ...BODY, draftId: 90, draftVersion: 3 }, ANNEX],
        });

        const state = list();
        await state.openDraft(BODY);

        expect(assign).not.toHaveBeenCalled();
        expect(state.visibleItems.value[0].draftId).toBe(90);
    });

    it("duplicates without leaving the list", async () => {
        request.mockResolvedValue({
            success: true,
            editorPath: "/3/versions/91",
            templates: [
                BODY,
                ANNEX,
                { ...BODY, id: 3, name: "Contrat mensuel (copie)" },
            ],
        });

        const state = list();
        state.pendingDuplicate.value = BODY;
        await state.confirmDuplicate();

        expect(assign).not.toHaveBeenCalled();
        expect(state.visibleItems.value).toHaveLength(3);
    });

    it("filters only on a kind the application declares", () => {
        const state = list();

        expect(state.visibleItems.value).toHaveLength(2);

        state.setKind("annex");
        expect(state.visibleItems.value.map((each) => each.id)).toEqual([2]);
        expect(state.kindCounts.value).toEqual({ "": 2, body: 1, annex: 1 });

        // A hand-edited parameter cannot empty the list.
        state.setKind("nothing-like-it");
        expect(state.kind.value).toBe("annex");

        state.setKind("");
        expect(state.visibleItems.value).toHaveLength(2);
    });
});

/**
 * A library that covers one trade reads fine as one list. The day it covers
 * three, "show me what nobody has sorted yet" is the question the screen is
 * opened with - and it cannot be asked by leaving the filter empty, which
 * already means "show everything".
 */
describe("the category filter", () => {
    it("shows everything until a trade is picked", () => {
        const { visibleItems } = list();

        expect(visibleItems.value).toHaveLength(2);
    });

    it("narrows to one trade", () => {
        const { visibleItems, setCategory } = list();

        setCategory("community_management");

        expect(visibleItems.value.map((each) => each.id)).toEqual([1]);
    });

    it("answers what nobody has classified", () => {
        const { visibleItems, setCategory, NO_CATEGORY } = list();

        setCategory(NO_CATEGORY);

        expect(visibleItems.value.map((each) => each.id)).toEqual([2]);
    });

    it("refuses a trade the application does not declare", () => {
        const { visibleItems, setCategory } = list();

        setCategory("plomberie");

        // Left as it was rather than emptying the list: a hand-edited query
        // string must not be able to hide every row.
        expect(visibleItems.value).toHaveLength(2);
    });

    it("counts each trade, unclassified included", () => {
        const { categoryCounts, NO_CATEGORY } = list();

        expect(categoryCounts.value.community_management).toBe(1);
        expect(categoryCounts.value[NO_CATEGORY]).toBe(1);
        expect(categoryCounts.value.photography).toBe(0);
    });
});
