import { describe, expect, it, vi } from "vitest";
// Relative rather than aliased, like its neighbour: the Studio module keeps its
// assets under its sub-domains, so there is no `@studio` alias to import through.
import { useContractTemplateActions } from "./useContractTemplateActions.js";

vi.mock("vue-i18n", () => ({
    useI18n: () => ({ t: (key) => key }),
}));

let granted = [];

vi.mock("@/shared/composables/usePrivileges.js", () => ({
    usePrivileges: () => ({ can: (privilege) => granted.includes(privilege) }),
}));

const EDITOR_PATH = (templateId, versionId) =>
    `/backend/studio/contract-templates/${templateId}/versions/${versionId}`;

const PUBLISHED = {
    id: 1,
    name: "Contrat mensuel",
    kind: "body",
    isArchived: false,
    publishedVersion: 2,
    publishedVersionId: 42,
    draftId: null,
};

const NEVER_PUBLISHED = {
    id: 2,
    name: "Brouillon seul",
    kind: "annex",
    isArchived: false,
    publishedVersion: null,
    publishedVersionId: null,
    draftId: 77,
};

function actionsFor(template, privileges) {
    granted = privileges;

    return useContractTemplateActions(EDITOR_PATH)(template, {
        openDraft: vi.fn(),
        discard: vi.fn(),
        duplicate: vi.fn(),
        rename: vi.fn(),
        archive: vi.fn(),
        restore: vi.fn(),
        remove: vi.fn(),
    });
}

function keyed(actions, key) {
    return actions.find((action) => action.key === key);
}

/**
 * The list could do everything to a trame except read one. Every other action
 * opens a modal or posts; the version in force had no way in at all, which is
 * the gap these cover.
 */
describe("the view action", () => {
    it("links to the version in force rather than running a handler", () => {
        const view = keyed(
            actionsFor(PUBLISHED, ["studio.contract_templates.view"]),
            "view",
        );

        expect(view).toBeDefined();
        // A navigation, so it stays openable in a new tab. An `onSelect` here
        // would be a link that only works on left click.
        expect(view.href).toBe(
            "/backend/studio/contract-templates/1/versions/42",
        );
        expect(view.onSelect).toBeUndefined();
    });

    /**
     * « Modifier le texte » is what most people open the menu for, so it
     * comes first; reading the version in force comes right after.
     */
    it("offers to edit the text first, then to read the version in force", () => {
        const actions = actionsFor(PUBLISHED, [
            "studio.contract_templates.view",
            "studio.contract_templates.edit",
            "studio.contract_templates.create",
            "studio.contract_templates.delete",
        ]);

        expect(actions[0].key).toBe("editText");
        expect(actions[1].key).toBe("view");
    });

    it("reads the version in force first for somebody who may only read", () => {
        const actions = actionsFor(PUBLISHED, [
            "studio.contract_templates.view",
        ]);

        expect(actions[0].key).toBe("view");
    });

    it("does not offer to delete a trame a contract starts from", () => {
        const all = [
            "studio.contract_templates.view",
            "studio.contract_templates.delete",
        ];

        expect(
            actionsFor({ ...PUBLISHED, contractsCount: 2 }, all).map(
                (each) => each.key,
            ),
        ).not.toContain("delete");
        expect(
            actionsFor({ ...PUBLISHED, contractsCount: 0 }, all).map(
                (each) => each.key,
            ),
        ).toContain("delete");
    });

    it("is absent from a trame that has never been published", () => {
        const actions = actionsFor(NEVER_PUBLISHED, [
            "studio.contract_templates.view",
        ]);

        expect(keyed(actions, "view")).toBeUndefined();
    });

    it("needs the view privilege, which the other actions do not carry", () => {
        const actions = actionsFor(PUBLISHED, [
            "studio.contract_templates.edit",
            "studio.contract_templates.delete",
        ]);

        expect(keyed(actions, "view")).toBeUndefined();
        expect(keyed(actions, "rename")).toBeDefined();
    });
});
