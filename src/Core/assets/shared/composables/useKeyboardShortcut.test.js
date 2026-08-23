import { describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";
import { mount } from "@vue/test-utils";
import { useKeyboardShortcut } from "./useKeyboardShortcut.js";

function mountWith(binding, handler) {
    return mount(
        defineComponent({
            setup() {
                useKeyboardShortcut(binding, handler);

                return () => null;
            },
        }),
        { attachTo: document.body },
    );
}

function press(key, init = {}) {
    const target = init.target ?? document.body;
    delete init.target;
    target.dispatchEvent(
        new KeyboardEvent("keydown", { key, bubbles: true, ...init }),
    );
}

describe("useKeyboardShortcut", () => {
    it("fires on its key", () => {
        const handler = vi.fn();
        const wrapper = mountWith({ key: "m" }, handler);

        press("m");

        expect(handler).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    it("ignores the case of the key", () => {
        const handler = vi.fn();
        const wrapper = mountWith({ key: "m" }, handler);

        press("M");

        expect(handler).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    it("does not fire a bare shortcut when a modifier is held", () => {
        const handler = vi.fn();
        const wrapper = mountWith({ key: "m" }, handler);

        press("m", { ctrlKey: true });

        expect(handler).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it("accepts Cmd as well as Control", () => {
        const handler = vi.fn();
        const wrapper = mountWith({ key: "s", ctrl: true }, handler);

        press("s", { metaKey: true });

        expect(handler).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    it("stops listening once the component is gone", () => {
        const handler = vi.fn();
        mountWith({ key: "m" }, handler).unmount();

        press("m");

        expect(handler).not.toHaveBeenCalled();
    });
});

describe("useKeyboardShortcut while typing", () => {
    function typeInto(tag, key, init = {}) {
        const field = document.createElement(tag);
        document.body.append(field);
        press(key, { ...init, target: field });
        field.remove();
    }

    /**
     * A bare letter is a command to the screen, so it must not fire mid-word.
     *
     * The defect this prevents: `m` switching to the month view while somebody
     * writes "mardi" into an event's title.
     */
    it("stays out of the way of an input", () => {
        const handler = vi.fn();
        const wrapper = mountWith({ key: "m" }, handler);

        typeInto("input", "m");
        typeInto("textarea", "m");
        typeInto("select", "m");

        expect(handler).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it("stays out of the way of a rich text editor", () => {
        // Not an input, and exactly where a stray shortcut does the most damage.
        const handler = vi.fn();
        const wrapper = mountWith({ key: "m" }, handler);

        const editor = document.createElement("div");
        editor.setAttribute("contenteditable", "true");
        document.body.append(editor);
        // jsdom does not derive isContentEditable from the attribute.
        Object.defineProperty(editor, "isContentEditable", { value: true });

        press("m", { target: editor });

        expect(handler).not.toHaveBeenCalled();
        editor.remove();
        wrapper.unmount();
    });

    /**
     * A modifier combination is a command to the application, so it fires
     * wherever the focus is - Ctrl+S has to save whatever is being edited.
     */
    it("still fires a modifier shortcut inside an input", () => {
        const handler = vi.fn();
        const wrapper = mountWith({ key: "s", ctrl: true }, handler);

        typeInto("input", "s", { ctrlKey: true });

        expect(handler).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });
});
