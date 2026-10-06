import { describe, expect, it } from "vitest";
import { nextTick } from "vue";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import DeliverableSlidesEditorApp from "./DeliverableSlidesEditorApp.vue";

/**
 * A slideshow is written in the presentation editor, with a deliverable's
 * rights, reading links and settings around it.
 *
 * What would break silently: the editor not opening the deliverable's
 * reading links when sharing, or not receiving the deliverable's rights.
 */
const DECK_EDITOR = {
    name: "DeckEditorApp",
    props: {
        deck: Object,
        canEdit: { type: Boolean, default: false },
        canShare: { type: Boolean, default: false },
        withSettings: Boolean,
        linksPath: String,
        slideCreatePath: String,
    },
    emits: ["share", "settings"],
    template: "<div />",
};

function mountApp(extra = {}) {
    return mount(DeliverableSlidesEditorApp, {
        props: {
            deck: {
                id: 4,
                channel: "deliverable-4",
                title: "Lancement",
                slides: [],
            },
            deliverable: {
                id: 4,
                title: "Lancement",
                summary: "",
                locale: "fr",
                scope: "shared",
                thumbnail: { id: null, url: null },
                readingHeader: {},
            },
            canEdit: true,
            canShare: true,
            updatePath: "/suite/studio/deliverables/4/update",
            linksPath: "/suite/studio/deliverables/4/links",
            appearancePath: "/a",
            slideCreatePath: "/s/create",
            slideUpdatePath: "/s/__slideId__/update",
            slideDeletePath: "/s/__slideId__/delete",
            slideDuplicatePath: "/s/__slideId__/duplicate",
            slideReorderPath: "/s/reorder",
            printPath: "/print",
            presenterPath: "/presenter",
            ...extra,
        },
        global: {
            plugins: [createTestI18n()],
            stubs: {
                DeckEditorApp: DECK_EDITOR,
                DeliverableLinksModal: {
                    name: "DeliverableLinksModal",
                    props: ["show", "linksPath"],
                    template: "<div />",
                },
                DeliverableSettingsTab: {
                    name: "DeliverableSettingsTab",
                    props: {
                        withReadingHeader: { type: Boolean, default: true },
                        withClient: Boolean,
                        canShowToClient: Boolean,
                        customerName: String,
                        visibleToClient: Boolean,
                    },
                    template: "<div />",
                },
                AppModal: {
                    name: "AppModal",
                    props: ["show"],
                    template:
                        "<div v-if='show'><slot /><slot name='footer' /></div>",
                },
            },
        },
    });
}

describe("DeliverableSlidesEditorApp", () => {
    it("hands the editor the deliverable's rights and keeps sharing to itself", () => {
        const editor = mountApp({ canEdit: false }).findComponent({
            name: "DeckEditorApp",
        });

        expect(editor.props("canEdit")).toBe(false);
        expect(editor.props("canShare")).toBe(true);
        expect(editor.props("withSettings")).toBe(false);
        expect(editor.props("linksPath")).toBeUndefined();
        expect(editor.props("slideCreatePath")).toBe("/s/create");
    });

    it("opens the deliverable's reading links when the editor asks to share", async () => {
        const wrapper = mountApp();
        const links = () =>
            wrapper.findComponent({ name: "DeliverableLinksModal" });

        expect(links().props("show")).toBe(false);
        wrapper.findComponent({ name: "DeckEditorApp" }).vm.$emit("share");
        await nextTick();

        expect(links().props("show")).toBe(true);
        expect(links().props("linksPath")).toBe(
            "/suite/studio/deliverables/4/links",
        );
    });

    it("opens the settings when the editor asks for them", async () => {
        const wrapper = mountApp();

        expect(
            wrapper.findComponent({ name: "DeliverableSettingsTab" }).exists(),
        ).toBe(false);
        wrapper.findComponent({ name: "DeckEditorApp" }).vm.$emit("settings");
        await nextTick();

        expect(
            wrapper.findComponent({ name: "DeliverableSettingsTab" }).exists(),
        ).toBe(true);
        expect(
            wrapper
                .findComponent({ name: "DeliverableSettingsTab" })
                .props("withReadingHeader"),
        ).toBe(false);
        expect(
            wrapper
                .findComponent({ name: "DeliverableSettingsTab" })
                .props("withClient"),
        ).toBe(false);
    });

    it("settles a presentation of a client space like a deliverable of that space", async () => {
        const wrapper = mountApp({
            space: {
                id: 9,
                name: "Atelier Dupont",
                customerName: "Atelier Dupont SARL",
            },
            deliverable: {
                id: 4,
                title: "Lancement",
                summary: "",
                locale: "fr",
                scope: null,
                visibleToClient: true,
                thumbnail: { id: null, url: null },
                readingHeader: {},
            },
            canShare: false,
            updatePath: "/workspace/9/deliverables/4/update",
        });

        // The space is the page's, not the editor's.
        expect(
            wrapper
                .findComponent({ name: "DeckEditorApp" })
                .attributes("space"),
        ).toBeUndefined();

        wrapper.findComponent({ name: "DeckEditorApp" }).vm.$emit("settings");
        await nextTick();

        const settings = wrapper.findComponent({
            name: "DeliverableSettingsTab",
        });
        expect(settings.props("withClient")).toBe(true);
        expect(settings.props("customerName")).toBe("Atelier Dupont SARL");
        // Showing it to the client is the right to share the space.
        expect(settings.props("canShowToClient")).toBe(false);
        expect(settings.props("visibleToClient")).toBe(true);
    });
});
