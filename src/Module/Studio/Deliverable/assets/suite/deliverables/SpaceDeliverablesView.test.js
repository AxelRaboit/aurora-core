import { beforeEach, describe, it, expect, vi } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { nextTick } from "vue";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import SpaceDeliverablesView from "./SpaceDeliverablesView.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import DeliverableLinksModal from "./components/DeliverableLinksModal.vue";

const send = vi.fn();

vi.mock("./composables/useDeliverableRequest.js", () => ({
    useDeliverableRequest: () => ({ send }),
}));

const i18n = createTestI18n();

/**
 * A space's deliverables, seen from the studio.
 *
 * What would break silently: the reading links from the list, which carry
 * the addresses themselves and so are only offered with the right to share
 * the space; creation and duplication in an archive, which receives no more
 * deliverables; and opening an unfinished deliverable to the client, which
 * the server refuses until the author confirms.
 */
const PATHS = {
    createPath: "/workspace/1/deliverables/create",
    visibilityPathTemplate: "/workspace/1/deliverables/__id__/visibility",
    duplicatePathTemplate: "/workspace/1/deliverables/__id__/duplicate",
    deletePathTemplate: "/workspace/1/deliverables/__id__/delete",
};

const LINKS = "/workspace/1/deliverables/__id__/links";

const AUDIT = {
    id: 7,
    title: "Audit de présence en ligne",
    summary: "",
    visibleToClient: false,
    updatedAt: "2026-10-02T10:00:00+00:00",
    editPath: "/workspace/1/deliverables/7/edit",
    previewPath: "/workspace/1/deliverables/7/preview",
};

function mountView(extra = {}) {
    return mount(SpaceDeliverablesView, {
        props: { deliverables: [AUDIT], canEdit: true, ...PATHS, ...extra },
        global: { plugins: [i18n], stubs: { DeliverableLinksModal: true } },
    });
}

function actionsOf(wrapper) {
    return wrapper.findComponent(AppRowActions).props("actions");
}

function actionKeys(wrapper) {
    return actionsOf(wrapper).map((action) => action.key);
}

describe("SpaceDeliverablesView", () => {
    beforeEach(() => send.mockReset());

    it("offers to keep a copy in Studio only with the right to create there", () => {
        expect(actionKeys(mountView())).not.toContain("copy-to-studio");

        const keys = actionKeys(
            mountView({
                copyToStudioPathTemplate:
                    "/workspace/1/deliverables/__id__/copy-to-studio",
            }),
        );
        expect(keys).toContain("copy-to-studio");
    });

    it("offers the reading links only with the right to share the space", () => {
        expect(
            actionKeys(mountView({ linksPathTemplate: LINKS, canShare: true })),
        ).toContain("links");

        // Editing is not sharing: the list carries the addresses themselves.
        expect(
            actionKeys(
                mountView({ linksPathTemplate: LINKS, canShare: false }),
            ),
        ).not.toContain("links");
    });

    it("opens the links of the chosen deliverable", async () => {
        const wrapper = mountView({ linksPathTemplate: LINKS, canShare: true });

        actionsOf(wrapper)
            .find((action) => "links" === action.key)
            .onSelect();
        await wrapper.vm.$nextTick();

        const modal = wrapper.findComponent(DeliverableLinksModal);
        expect(modal.props("show")).toBe(true);
        expect(modal.props("linksPath")).toBe(
            "/workspace/1/deliverables/7/links",
        );
    });

    it("offers to show or hide a deliverable only with the right to share the space", () => {
        expect(actionKeys(mountView({ canShare: true }))).toContain(
            "visibility",
        );
        expect(actionKeys(mountView({ canShare: false }))).not.toContain(
            "visibility",
        );
    });

    it("leaves the action out without a links path", () => {
        expect(actionKeys(mountView({ canShare: true }))).not.toContain(
            "links",
        );
    });

    it("takes no new deliverable in an archive, and says why", () => {
        const archived = mountView({ canAdd: false });

        expect(actionKeys(archived)).not.toContain("duplicate");
        expect(actionKeys(archived)).toContain("delete");
        expect(archived.text()).toContain("archived_hint");
        expect(
            archived
                .findAll("button")
                .some((button) => button.text().includes("deliverables.add")),
        ).toBe(false);

        const open = mountView({ canAdd: true });
        expect(actionKeys(open)).toContain("duplicate");
        expect(open.text()).not.toContain("archived_hint");
    });

    it("keeps its rows in step with the page that holds them", async () => {
        const wrapper = mountView();
        expect(wrapper.text()).toContain("Audit de présence en ligne");

        await wrapper.setProps({
            deliverables: [{ ...AUDIT, id: 8, title: "Bilan de septembre" }],
        });

        expect(wrapper.text()).toContain("Bilan de septembre");
        expect(wrapper.text()).not.toContain("Audit de présence en ligne");
    });

    it("asks before opening to the client what the server says is not ready", async () => {
        send.mockResolvedValueOnce({
            success: false,
            error: "confirmation_needed",
            placeholders: 3,
            withheldPictures: [{ id: 5, name: "portrait.jpg" }],
        });
        const wrapper = mountView({ canAdd: true, canShare: true });

        await actionsOf(wrapper)
            .find((action) => "visibility" === action.key)
            .onSelect();
        await flushPromises();

        // The server was asked to open it, without a confirmation.
        expect(send).toHaveBeenCalledWith(
            "/workspace/1/deliverables/7/visibility",
            { visible: true },
            { own: ["confirmation_needed"] },
        );

        // Nothing was opened: the author is told what remains (in the modal,
        // which is teleported out of the wrapper).
        const text = document.body.textContent;
        expect(text).toContain("show_confirm.placeholders");
        expect(text).toContain("portrait.jpg");

        wrapper.unmount();
    });

    it("opens it once the author confirms", async () => {
        send.mockResolvedValueOnce({
            success: false,
            error: "confirmation_needed",
            placeholders: 1,
            withheldPictures: [],
        });
        send.mockResolvedValueOnce({
            success: true,
            deliverables: [{ ...AUDIT, visibleToClient: true }],
        });
        const wrapper = mountView({ canAdd: true, canShare: true });

        await actionsOf(wrapper)
            .find((action) => "visibility" === action.key)
            .onSelect();
        await flushPromises();

        const confirm = [...document.body.querySelectorAll("button")].find(
            (button) => button.textContent.includes("show_confirm.confirm"),
        );
        confirm.click();
        await flushPromises();

        expect(send).toHaveBeenLastCalledWith(
            "/workspace/1/deliverables/7/visibility",
            { visible: true, confirm: true },
            { own: ["confirmation_needed"] },
        );
        // The row now reads « visible »: what the server answered is what is shown.
        expect(wrapper.text()).toContain("visible_badge");

        wrapper.unmount();
    });

    it("creates a presentation from a Studio template of that format", async () => {
        send.mockResolvedValueOnce({ success: false, errors: {} });
        const wrapper = mount(SpaceDeliverablesView, {
            props: {
                deliverables: [],
                canEdit: true,
                canAdd: true,
                ...PATHS,
                templates: [
                    {
                        id: 3,
                        title: "Audit type",
                        format: "page",
                        template: true,
                        category: null,
                    },
                    {
                        id: 4,
                        title: "Lancement type",
                        format: "slides",
                        template: true,
                        category: null,
                    },
                ],
            },
            global: {
                plugins: [i18n],
                stubs: {
                    DeliverableLinksModal: true,
                    AppModal: {
                        template: "<div><slot /><slot name='footer' /></div>",
                    },
                },
            },
        });

        const templateLabels = () =>
            wrapper
                .findAllComponents({ name: "AppSelect" })
                .find(
                    (select) =>
                        "suite.studio.deliverables.template.from" ===
                        select.props("label"),
                )
                .props("options")
                .map((option) => option.label);
        expect(templateLabels()).toEqual(["Audit type"]);

        wrapper
            .findComponent({ name: "AppChoiceRow" })
            .vm.$emit("update:modelValue", "slides");
        await nextTick();
        expect(templateLabels()).toEqual(["Lancement type"]);
        wrapper
            .findAllComponents({ name: "AppSelect" })
            .find(
                (select) =>
                    "suite.studio.deliverables.template.from" ===
                    select.props("label"),
            )
            .vm.$emit("update:modelValue", 4);
        await nextTick();

        await wrapper.find("form").trigger("submit");
        await flushPromises();

        expect(send).toHaveBeenCalledWith(
            PATHS.createPath,
            expect.objectContaining({ format: "slides", fromTemplateId: 4 }),
        );
    });

    it("offers to import a text only with the route, and badges a presentation", () => {
        const withImport = mountView({
            canAdd: true,
            importPath: "/workspace/1/deliverables/import",
            deliverables: [{ ...AUDIT, format: "slides" }],
        });
        expect(
            withImport
                .findAll("button")
                .some((button) => button.text().includes("import.action")),
        ).toBe(true);
        expect(withImport.text()).toContain("format.badge_slides");

        const withoutImport = mountView({ canAdd: true });
        expect(
            withoutImport
                .findAll("button")
                .some((button) => button.text().includes("import.action")),
        ).toBe(false);
        expect(withoutImport.text()).not.toContain("format.badge_slides");
    });
});
