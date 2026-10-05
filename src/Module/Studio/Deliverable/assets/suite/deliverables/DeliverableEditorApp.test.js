import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import DeliverableEditorApp from "./DeliverableEditorApp.vue";

const request = vi.fn();

vi.mock("@/shared/composables/http/suite/useRequest.js", () => ({
    useRequest: () => ({ request }),
}));
// The date picker reads the colour scheme when it is imported, which jsdom
// cannot answer; the grid panel that pulls it in is stubbed below anyway.
vi.mock("@/shared/components/form/picker/AppDatePicker.vue", () => ({
    default: {
        name: "AppDatePicker",
        props: ["modelValue"],
        template: "<div />",
    },
}));
vi.mock("vue-sonner", () => ({
    toast: { error: vi.fn(), success: vi.fn(), message: vi.fn() },
}));

const i18n = createTestI18n();

/**
 * L'éditeur d'un livrable, en tête et en réserve.
 *
 * Ce qui se cassait sans bruit : un lecteur seul qui recevait un éditeur
 * entièrement modifiable (et un 403 en revenant), le compteur de [crochets]
 * qui ne lisait que la grille (le titre ou « Préparé pour » partaient avec un
 * trou), et un enregistrement refusé pour cause de version qui n'expliquait
 * rien.
 */
const DELIVERABLE = {
    id: 1,
    title: "Audit [Nom du client]",
    summary: "",
    locale: "fr",
    gridLayout: { zones: [] },
    gridContent: {
        zones: {
            a: {
                blocks: [
                    { type: "paragraph", data: { text: "Pour [Marque]" } },
                ],
            },
        },
    },
    appearance: {},
    readingHeader: { preparedFor: "[Client]" },
    visibleToClient: false,
    updatedAt: "2026-10-05T10:00:00+00:00",
    scope: "shared",
    categoryId: null,
    thumbnail: null,
};

const PATHS = {
    deliverablesPath: "/suite/studio/deliverables",
    updatePath: "/update",
    previewPath: "/preview",
    gridPreviewPath: "/grid-preview",
    bannerPreviewPath: "/banner-preview",
    linksPath: "/links",
    duplicatePath: "/duplicate",
    deletePath: "/delete",
};

function mountEditor(props = {}) {
    return mount(DeliverableEditorApp, {
        props: {
            deliverable: DELIVERABLE,
            canEdit: true,
            canShare: true,
            canDelete: true,
            canDuplicate: true,
            ...PATHS,
            ...props,
        },
        global: {
            plugins: [i18n],
            stubs: {
                PostGridPanel: { template: "<div data-stub='grid' />" },
                DeliverableAppearanceTab: {
                    template: "<div data-stub='appearance' />",
                },
                DeliverableSettingsTab: {
                    template: "<div data-stub='settings' />",
                },
                DeliverableLinksModal: true,
                DeliverableCopyToSpaceModal: true,
                DeliverableDeleteModal: true,
            },
        },
        attachTo: document.body,
    });
}

describe("DeliverableEditorApp", () => {
    beforeEach(() => request.mockReset());
    afterEach(() => (document.body.innerHTML = ""));

    it("counts the blanks of the title and the header too, not just the grid", () => {
        const wrapper = mountEditor();

        // « [Nom du client] », « [Client] » and « [Marque] ».
        expect(wrapper.text()).toContain("[3]");
        wrapper.unmount();
    });

    it("puts the save command in the page bar, and the state under the title", async () => {
        const wrapper = mountEditor();

        expect(
            wrapper
                .find(
                    "button[aria-label='Enregistrer'], button[title='Enregistrer']",
                )
                .exists(),
        ).toBe(true);
        expect(wrapper.text()).not.toContain(
            "suite.studio.deliverables.unsaved",
        );

        // An unsaved change is said in words under the title, not by a dot in the bar.
        wrapper.vm.$.setupState.form.title = "Audit changé";
        await flushPromises();
        expect(wrapper.text()).toContain("suite.studio.deliverables.unsaved");
        wrapper.unmount();
    });

    describe("for someone who may only read", () => {
        it("offers no save and says it is read only", () => {
            const wrapper = mountEditor({ canEdit: false });

            expect(
                wrapper
                    .find(
                        "button[aria-label='Enregistrer'], button[title='Enregistrer']",
                    )
                    .exists(),
            ).toBe(false);
            expect(wrapper.text()).toContain(
                "suite.studio.deliverables.read_only",
            );
            expect(wrapper.text()).toContain(
                "suite.studio.deliverables.read_only_hint",
            );
            wrapper.unmount();
        });

        it("makes the three panels inert, so nothing can be typed that could not be saved", () => {
            const wrapper = mountEditor({ canEdit: false });

            for (const panel of ["grid", "appearance", "settings"]) {
                expect(
                    wrapper
                        .find(`[data-stub='${panel}']`)
                        .element.closest("[inert]") ??
                        wrapper.find(`[data-stub='${panel}']`).element,
                ).toBeTruthy();
                expect(
                    wrapper
                        .find(`[data-stub='${panel}']`)
                        .element.closest("[inert]") !== null ||
                        wrapper
                            .find(`[data-stub='${panel}']`)
                            .attributes("inert") !== undefined,
                ).toBe(true);
            }
            wrapper.unmount();
        });

        it("leaves the panels live for someone who may write", () => {
            const wrapper = mountEditor();

            for (const panel of ["grid", "appearance", "settings"]) {
                const element = wrapper.find(`[data-stub='${panel}']`).element;
                expect(element.closest("[inert]")).toBeNull();
            }
            wrapper.unmount();
        });
    });

    it("tells of a conflict and lets the author overwrite on purpose", async () => {
        request
            .mockResolvedValueOnce({ success: false, conflict: true })
            .mockResolvedValueOnce({
                success: true,
                deliverable: { updatedAt: "2026-10-05T11:00:00+00:00" },
            });
        const wrapper = mountEditor();
        wrapper.vm.$.setupState.form.title = "Mon titre";

        await wrapper
            .find(
                "button[aria-label='Enregistrer'], button[title='Enregistrer']",
            )
            .trigger("click");
        await flushPromises();

        expect(document.body.textContent).toContain(
            "suite.studio.deliverables.conflict.title",
        );
        expect(document.body.textContent).toContain(
            "suite.studio.deliverables.errors.conflict",
        );

        const overwrite = [...document.body.querySelectorAll("button")].find(
            (button) => button.textContent.includes("conflict.overwrite"),
        );
        overwrite.click();
        await flushPromises();

        expect(request.mock.calls[1][1].force).toBe(true);
        wrapper.unmount();
    });

    it("lists what the server refused at the top of the page, not only as a dot on a tab", async () => {
        request.mockResolvedValue({
            success: false,
            errors: {
                gridLayout: "suite.studio.deliverables.errors.grid_invalid",
                locale: "suite.studio.deliverables.errors.locale_invalid",
            },
        });
        const wrapper = mountEditor();
        wrapper.vm.$.setupState.form.title = "x";

        await wrapper
            .find(
                "button[aria-label='Enregistrer'], button[title='Enregistrer']",
            )
            .trigger("click");
        await flushPromises();

        const alert = wrapper.find("ul[role=alert]");
        expect(alert.text()).toContain("errors.grid_invalid");
        expect(alert.text()).toContain("errors.locale_invalid");
        wrapper.unmount();
    });

    it("gives the tabs their roles", () => {
        const wrapper = mountEditor();

        expect(wrapper.find("[role=tablist]").exists()).toBe(true);
        const tabs = wrapper.findAll("[role=tab]");
        expect(tabs).toHaveLength(3);
        expect(
            tabs.filter((tab) => "true" === tab.attributes("aria-selected")),
        ).toHaveLength(1);
        wrapper.unmount();
    });
});
