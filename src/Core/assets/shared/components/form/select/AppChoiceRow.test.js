import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import AppChoiceRow from "./AppChoiceRow.vue";

const OPTIONS = [
    { value: 48, label: "1/1" },
    { value: 24, label: "1/2" },
    { value: 16, label: "1/3" },
];

function mountRow(props = {}) {
    return mount(AppChoiceRow, {
        props: { options: OPTIONS, modelValue: 24, ...props },
        attachTo: document.body,
    });
}

describe("AppChoiceRow", () => {
    it("marks the current option, and only it", () => {
        const options = mountRow().findAll('[role="radio"]');

        expect(options.map((o) => o.attributes("aria-checked"))).toEqual([
            "false",
            "true",
            "false",
        ]);
    });

    it("emits the value of the option clicked", async () => {
        const wrapper = mountRow();

        await wrapper.findAll('[role="radio"]')[2].trigger("click");

        expect(wrapper.emitted("update:modelValue")[0]).toEqual([16]);
    });

    it("is one tab stop, resting on the current option", () => {
        const options = mountRow().findAll('[role="radio"]');

        expect(options.map((o) => o.attributes("tabindex"))).toEqual([
            "-1",
            "0",
            "-1",
        ]);
    });

    it("stays reachable when nothing is checked", () => {
        // A width set by other means matches no option. The group must still
        // have a way in, or the keyboard path disappears exactly when the
        // author most needs it.
        const options = mountRow({ modelValue: 20 }).findAll('[role="radio"]');

        expect(options.map((o) => o.attributes("aria-checked"))).toEqual([
            "false",
            "false",
            "false",
        ]);
        expect(options[0].attributes("tabindex")).toBe("0");
    });

    it("moves the selection with the arrow keys", async () => {
        const wrapper = mountRow();

        await wrapper.findAll('[role="radio"]')[1].trigger("keydown", {
            key: "ArrowRight",
        });

        expect(wrapper.emitted("update:modelValue")[0]).toEqual([16]);
    });

    it("wraps around the ends, as a radio group does", async () => {
        const wrapper = mountRow({ modelValue: 48 });

        await wrapper.findAll('[role="radio"]')[0].trigger("keydown", {
            key: "ArrowLeft",
        });

        expect(wrapper.emitted("update:modelValue")[0]).toEqual([16]);
    });

    it("reaches both ends with Home and End", async () => {
        const wrapper = mountRow();
        const options = wrapper.findAll('[role="radio"]');

        await options[1].trigger("keydown", { key: "End" });
        expect(wrapper.emitted("update:modelValue").at(-1)).toEqual([16]);

        await options[1].trigger("keydown", { key: "Home" });
        expect(wrapper.emitted("update:modelValue").at(-1)).toEqual([48]);
    });

    it("takes focus along with the selection", async () => {
        const wrapper = mountRow();
        const options = wrapper.findAll('[role="radio"]');

        await options[1].trigger("keydown", { key: "ArrowRight" });

        expect(document.activeElement).toBe(options[2].element);
    });

    it("leaves keys it has no business with alone", async () => {
        const wrapper = mountRow();

        await wrapper.findAll('[role="radio"]')[1].trigger("keydown", {
            key: "Enter",
        });

        expect(wrapper.emitted("update:modelValue")).toBeFalsy();
    });

    it("says nothing and changes nothing when disabled", async () => {
        const wrapper = mountRow({ disabled: true });

        await wrapper.findAll('[role="radio"]')[2].trigger("click");

        expect(wrapper.emitted("update:modelValue")).toBeFalsy();
    });

    it("shows a hint when it is given one", () => {
        expect(mountRow({ hint: "Sur grand écran" }).text()).toContain(
            "Sur grand écran",
        );
    });
});
