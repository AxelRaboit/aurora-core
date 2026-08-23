import { describe, expect, it } from "vitest";
import { defineComponent, h } from "vue";
import { mount } from "@vue/test-utils";
import AppRetainedSlot from "./AppRetainedSlot.vue";

/**
 * One caller, in the shape every modal in this application has: the value that
 * decides whether to render the content also decides whether the thing above it
 * is open. Driven through props so the test can change both at once, the way a
 * caller does when it clears the record it was showing.
 */
const Caller = defineComponent({
    props: {
        live: { type: Boolean, required: true },
        content: { type: String, default: "" },
    },
    setup(props) {
        return () =>
            h(
                AppRetainedSlot,
                { live: props.live },
                {
                    default: () =>
                        props.content ? [h("p", props.content)] : [],
                },
            );
    },
});

describe("AppRetainedSlot", () => {
    it("renders its slot while live", () => {
        const wrapper = mount(Caller, {
            props: { live: true, content: "Réunion" },
        });

        expect(wrapper.text()).toContain("Réunion");
    });

    it("keeps the last content after it stops being live", async () => {
        // The defect this exists for: the caller clears its record, its own guard
        // goes false, and the modal's body empties while the panel is still
        // fading out - a flash of an empty modal on every close.
        const wrapper = mount(Caller, {
            props: { live: true, content: "Réunion" },
        });

        await wrapper.setProps({ live: false, content: "" });

        expect(wrapper.text()).toContain("Réunion");
    });

    it("follows the content again once it is live", async () => {
        const wrapper = mount(Caller, {
            props: { live: true, content: "Réunion" },
        });

        await wrapper.setProps({ live: false, content: "" });

        await wrapper.setProps({ live: true, content: "Dentiste" });

        expect(wrapper.text()).toContain("Dentiste");
        expect(wrapper.text()).not.toContain("Réunion");
    });

    it("holds nothing when it was never live", async () => {
        // Otherwise the very first close would render a stale nothing, or throw.
        const wrapper = mount(Caller, {
            props: { live: false, content: "tard" },
        });

        expect(wrapper.text()).toBe("");

        await wrapper.setProps({ live: true });

        expect(wrapper.text()).toContain("tard");
    });
});
