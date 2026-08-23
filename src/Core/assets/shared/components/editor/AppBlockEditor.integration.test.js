import { beforeAll, describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { reactive } from "vue";
import AppBlockEditor from "./AppBlockEditor.vue";

/**
 * The real Editor.js, the real tools, and props shaped like the app's state.
 *
 * Everything else covering this component works on doubles - `AppBlockEditor.test.js`
 * mocks Editor.js, `blockShapes.test.js` drives the tools directly with plain
 * objects. Both were green throughout a bug that made every list in the backend
 * read « The block can not be displayed correctly », because the defect was in
 * neither the data nor the wiring but in the *handoff*: `modelValue` arrives
 * deeply reactive, so each block is a `Proxy`, and `@editorjs/list` copies its
 * data with `structuredClone()`, which cannot clone a proxy. The tool throws,
 * Editor.js swallows it and substitutes its Stub.
 *
 * So this file doubles nothing. It mounts the component and asserts the Stub is
 * absent - the one symptom that reaches a person, and the one no unit test
 * above this layer can see.
 */

vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));

const list = (...items) => ({
    type: "list",
    data: {
        style: "unordered",
        meta: {},
        items: items.map((content) => ({ content, meta: {}, items: [] })),
    },
});

beforeAll(() => {
    // jsdom ships none of these, and Editor.js needs them all to boot.
    window.matchMedia ||= () => ({
        matches: false,
        addListener() {},
        removeListener() {},
        addEventListener() {},
        removeEventListener() {},
    });
    window.requestIdleCallback ||= (cb) =>
        setTimeout(() => cb({ didTimeout: false, timeRemaining: () => 50 }), 0);
    window.cancelIdleCallback ||= (id) => clearTimeout(id);
    document.execCommand ||= () => true;
    document.queryCommandSupported ||= () => false;
});

/** Editor.js boots asynchronously and lays out blocks on an idle callback. */
const settle = (ms = 400) => new Promise((resolve) => setTimeout(resolve, ms));

function mountEditor(modelValue) {
    // The registry the parent provides. `render` takes nothing: each instance
    // re-reads its own value, which is what lets a page hold several.
    const editors = new Set();

    const wrapper = mount(AppBlockEditor, {
        props: { modelValue },
        attachTo: document.body,
        global: {
            provide: {
                registerEditor: (handlers) => {
                    editors.add(handlers);

                    return () => editors.delete(handlers);
                },
            },
        },
    });

    return {
        wrapper,
        editors,
        renderAll: () =>
            Promise.all([...editors].map((editor) => editor.render())),
    };
}

describe("AppBlockEditor with the real Editor.js", () => {
    it("renders a list handed over as reactive state", async () => {
        // reactive() is the shape a parent's ref-held form state delivers.
        const state = reactive({ blocks: [list("un", "deux")] });
        const { wrapper } = mountEditor(state.blocks);
        await settle();

        expect(wrapper.find(".ce-stub").exists()).toBe(false);
        expect(wrapper.find(".cdx-list").exists()).toBe(true);
        expect(wrapper.text()).toContain("un");

        wrapper.unmount();
    });

    it("renders a list pushed in later, the way switching locale does", async () => {
        const { wrapper, renderAll } = mountEditor([]);
        await settle();

        // What a locale switch is: the value the parent holds changes, then
        // every editor is asked to re-read its own.
        const state = reactive({ blocks: [list("trois")] });
        await wrapper.setProps({ modelValue: state.blocks });
        await renderAll();
        await settle();

        expect(wrapper.find(".ce-stub").exists()).toBe(false);
        expect(wrapper.text()).toContain("trois");

        wrapper.unmount();
    });

    /**
     * The registration used to be a single callback the parent overwrote. With
     * one editor on the page that was invisible; the content grid puts several
     * there, and the loser's text would have been dropped on every save.
     */
    it("registers itself and lets go on unmount", async () => {
        const { wrapper, editors } = mountEditor([]);
        await settle();

        expect(editors.size).toBe(1);

        wrapper.unmount();
        await settle(50);

        expect(editors.size).toBe(0);
    });
});
