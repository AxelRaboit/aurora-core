import { describe, expect, it } from "vitest";
import { nextTick } from "vue";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import DeliverableSlidesEditorApp from "./DeliverableSlidesEditorApp.vue";

/**
 * Un diaporama s'écrit dans l'éditeur des présentations, avec les droits, les
 * liens de lecture et les réglages d'un livrable autour.
 *
 * Ce qui se casserait sans bruit : l'éditeur qui ouvrirait les liens de
 * partage d'une présentation au lieu de ceux du livrable, ou qui recevrait les
 * droits des présentations plutôt que ceux du livrable.
 */
const DECK_EDITOR = {
    name: "DeckEditorApp",
    props: {
        deck: Object,
        canEdit: { type: Boolean, default: null },
        canShare: { type: Boolean, default: null },
        externalShare: Boolean,
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
        expect(editor.props("externalShare")).toBe(true);
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
});
