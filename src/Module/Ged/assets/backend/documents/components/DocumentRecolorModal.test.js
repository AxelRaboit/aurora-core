import { afterEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import DocumentRecolorModal from "./DocumentRecolorModal.vue";

const mounted = [];

function render(props = {}) {
    global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        json: async () => ({ success: true, document: { id: 99 } }),
    });
    const wrapper = mount(DocumentRecolorModal, {
        props: {
            doc: { id: 5, title: "Carte", fileMime: "image/png" },
            recolorPath: "/backend/ged/documents/__id__/recolor",
            themeColor: "#8b6cff",
            labelSuggestions: ["jaune", "rouge"],
            ...props,
        },
        global: { plugins: [createTestI18n()] },
        attachTo: document.body,
    });
    mounted.push(wrapper);

    return wrapper;
}

const byText = (text) =>
    [...document.body.querySelectorAll("button")].find((b) =>
        b.textContent.includes(text),
    );

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
    vi.restoreAllMocks();
});

describe("the colour alternate modal", () => {
    it("sends the colour, the label and what to leave alone", async () => {
        const wrapper = render();
        await flushPromises();

        byText("backend.ged.documents.recolor.theme_color").click();
        byText("rouge").click();
        await flushPromises();
        byText("backend.ged.documents.recolor.submit").click();
        await flushPromises();

        const [url, init] = global.fetch.mock.calls.at(-1);
        expect(url).toBe("/backend/ged/documents/5/recolor");
        expect(JSON.parse(init.body)).toEqual({
            color: "#8b6cff",
            label: "rouge",
            sourceColor: null,
            spare: [],
            protectDetail: true,
        });
        expect(wrapper.emitted("created")?.[0]).toEqual([{ id: 99 }]);
    });

    it("cannot be sent without a colour and a label", async () => {
        render();
        await flushPromises();

        expect(byText("backend.ged.documents.recolor.submit").disabled).toBe(
            true,
        );
    });
});
