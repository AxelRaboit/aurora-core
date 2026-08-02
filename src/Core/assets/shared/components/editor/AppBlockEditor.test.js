import { describe, it, expect, vi, beforeEach } from "vitest";
import { mount } from "@vue/test-utils";

/**
 * Editor.js boots asynchronously and reads `modelValue` exactly once, when it
 * is constructed. A parent that hydrates its form in its own onMounted — which
 * Vue runs *after* the child's — therefore hands this component an empty array,
 * and the editor comes up blank on a document that has content.
 *
 * That is what these cover: the catch-up render once the editor is ready, and
 * the absence of a pointless one when nothing moved.
 */

let instances = [];
let resolveReady;

vi.mock("@editorjs/editorjs", () => ({
    default: class EditorJSMock {
        constructor(config) {
            this.config = config;
            this.blocks = config.data?.blocks ?? [];
            this.render = vi.fn(async ({ blocks }) => {
                this.blocks = blocks;
            });
            this.save = vi.fn(async () => ({ blocks: this.blocks }));
            this.destroy = vi.fn();
            this.isReady = new Promise((resolve) => {
                resolveReady = resolve;
            });
            instances.push(this);
        }
    },
}));

vi.mock("editorjs-drag-drop", () => ({ default: class {} }));
vi.mock("editorjs-undo", () => ({ default: class {} }));
vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));

const { default: AppBlockEditor } = await import("./AppBlockEditor.vue");

const BLOCK = {
    type: "header",
    data: { text: "Le Mas des Oliviers", level: 1 },
};

async function settle() {
    for (let i = 0; i < 6; i++) await Promise.resolve();
}

beforeEach(() => {
    instances = [];
    resolveReady = null;
});

describe("AppBlockEditor", () => {
    it("renders blocks that arrived while Editor.js was booting", async () => {
        const wrapper = mount(AppBlockEditor, { props: { modelValue: [] } });

        // The parent's onMounted lands here: after this component mounted,
        // before Editor.js finished booting.
        await wrapper.setProps({ modelValue: [BLOCK] });
        resolveReady();
        await settle();

        const editor = instances[0];
        expect(editor.render).toHaveBeenCalledWith({ blocks: [BLOCK] });
        expect(editor.blocks).toEqual([BLOCK]);
    });

    it("does not re-render when the value did not move while booting", async () => {
        mount(AppBlockEditor, { props: { modelValue: [BLOCK] } });

        resolveReady();
        await settle();

        const editor = instances[0];
        expect(editor.config.data.blocks).toEqual([BLOCK]);
        expect(editor.render).not.toHaveBeenCalled();
    });
});
