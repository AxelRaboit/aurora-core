import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import SpaceContentAttachments from "./SpaceContentAttachments.vue";

const i18n = createTestI18n();

function render(props = {}) {
    return mount(SpaceContentAttachments, {
        props: { attachments: [], ...props },
        global: { plugins: [i18n] },
    });
}

const IMAGE = {
    id: 1,
    documentId: 10,
    title: "Photo d'équipe",
    originalName: "equipe.jpg",
    mimeType: "image/jpeg",
    size: 31_744,
    url: "/media/equipe.jpg",
    preview: "/media/thumb/equipe.jpg",
    author: "Admin",
    fromClient: false,
};

const PDF = {
    ...IMAGE,
    id: 2,
    documentId: 11,
    title: "Dossier de presse",
    originalName: "presse.pdf",
    mimeType: "application/pdf",
    url: "/media/presse.pdf",
    // Null for anything that is not an image: this is the whole point of the
    // field, and the front has to honour it.
    preview: null,
};

describe("SpaceContentAttachments", () => {
    it("draws a thumbnail for an image and an icon for anything else", () => {
        const wrapper = render({ attachments: [IMAGE, PDF] });

        const images = wrapper.findAll("img");

        // One `<img>`, not two: pointing one at a PDF is how a file that
        // uploaded correctly ends up looking like a failure.
        expect(images).toHaveLength(1);
        expect(images[0].attributes("src")).toBe("/media/thumb/equipe.jpg");
        expect(wrapper.findAll("li")).toHaveLength(2);
    });

    /**
     * The regression this file exists for.
     *
     * `canPick` started out as a read of `$attrs.onPick`, which never fires:
     * `pick` is declared in `defineEmits`, so its listener is removed from
     * `$attrs`. The button was silently never drawn, and nothing but opening
     * the page said so.
     */
    it("draws the GED button only where there is a GED to pick from", () => {
        expect(render({ canAdd: true }).text()).not.toContain(
            i18n.global.t("shared.attachments.pick"),
        );

        expect(render({ canAdd: true, canPick: true }).text()).toContain(
            i18n.global.t("shared.attachments.pick"),
        );
    });

    it("offers no way in and no way out on a read-only reader", () => {
        const wrapper = render({ attachments: [IMAGE] });

        expect(wrapper.find("input[type=file]").exists()).toBe(false);
        expect(wrapper.findAll("button")).toHaveLength(0);
        // The file is still readable: a reader who cannot add can still open.
        expect(wrapper.find("a").attributes("href")).toBe("/media/equipe.jpg");
    });

    it("marks what the client sent", () => {
        const wrapper = render({
            attachments: [{ ...IMAGE, fromClient: true }],
        });

        expect(wrapper.text()).toContain(
            i18n.global.t("shared.thread.from_client"),
        );
    });

    it("sends every dropped file up, one event each", async () => {
        const wrapper = render({ canAdd: true });
        const files = [new File(["a"], "a.jpg"), new File(["b"], "b.jpg")];

        await wrapper.find("[class*=border-dashed]").trigger("drop", {
            dataTransfer: { files },
        });

        expect(wrapper.emitted("upload")).toHaveLength(2);
        expect(wrapper.emitted("upload")[0][0].name).toBe("a.jpg");
    });
});
