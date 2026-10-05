import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { defineComponent } from "vue";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import { useDocumentsForm } from "./useDocumentsForm.js";

const i18n = createTestI18n();

function formOf() {
    let form;
    const Host = defineComponent({
        setup() {
            form = useDocumentsForm(
                "/create",
                "/documents/__id__/update",
                "/delete",
                () => {},
            );

            return () => null;
        },
    });
    const wrapper = mount(Host, { global: { plugins: [i18n] } });

    return { form, wrapper };
}

async function sentOnSave(doc) {
    const { form, wrapper } = formOf();
    form.openEdit(doc);
    await form.submitEdit();
    await flushPromises();
    wrapper.unmount();

    const [, options] = global.fetch.mock.calls.at(-1);

    return JSON.parse(options.body);
}

beforeEach(() => {
    global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        json: async () => ({ success: true, document: {} }),
    });
});

afterEach(() => {
    vi.restoreAllMocks();
});

const DOC = {
    id: 12,
    title: "Entête accueil",
    status: "published",
    focalX: 0.72,
    focalY: 0.31,
    kept: true,
    originalId: 4,
    originalTitle: "Entête accueil vert",
    alternateLabel: "jaune",
};

describe("saving a document from the edit form", () => {
    /**
     * The update writes the focal point it is sent, null included, and the
     * form used to leave it out: every save recentred the picture, on every
     * page that crops it.
     */
    it("sends the focal point back as it was", async () => {
        const body = await sentOnSave(DOC);

        expect(body.focalX).toBe(0.72);
        expect(body.focalY).toBe(0.31);
    });

    it("sends back whether it is kept, and whose variant it is", async () => {
        const body = await sentOnSave(DOC);

        expect(body.kept).toBe(true);
        expect(body.originalId).toBe(4);
        expect(body.alternateLabel).toBe("jaune");
    });

    it("sends a document that belongs to no family as such", async () => {
        const body = await sentOnSave({
            id: 3,
            title: "Seul",
            status: "draft",
        });

        expect(body.kept).toBe(false);
        expect(body.originalId).toBeNull();
        expect(body.focalX).toBeNull();
    });
});
