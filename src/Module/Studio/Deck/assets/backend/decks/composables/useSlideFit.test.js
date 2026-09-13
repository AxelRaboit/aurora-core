import { describe, expect, it } from "vitest";
import { mount } from "@vue/test-utils";
import { useSlideFit } from "./useSlideFit.js";

/**
 * The fit ladder, driven through a component because `onMounted` is what runs
 * it and that only exists inside one.
 *
 * `scrollHeight` and `clientHeight` are zero in jsdom, so the overflow is
 * staged: the stub reports an overflow until the factor drops far enough,
 * which is exactly the condition the ladder is walking down.
 */
function mountWith(overflowsUntil) {
    const Host = {
        setup() {
            const { stage, fit, measure } = useSlideFit(() => null);

            return { stage, fit, measure };
        },
        template: '<div ref="stage" />',
    };

    const wrapper = mount(Host);
    const element = wrapper.vm.stage;

    Object.defineProperty(element, "clientHeight", { get: () => 100 });
    Object.defineProperty(element, "scrollHeight", {
        get: () =>
            Number(element.style.getPropertyValue("--fit")) > overflowsUntil
                ? 200
                : 100,
    });

    // Measured again now that the stub is in place: the mount ran it against a
    // jsdom node whose two heights are both zero, which is a slide that fits.
    wrapper.vm.measure();

    return wrapper;
}

describe("useSlideFit", () => {
    it("leaves a slide that fits at full size", () => {
        const wrapper = mountWith(1.5);

        expect(wrapper.vm.fit).toBe(1);
    });

    it("steps down until the words stop falling out", () => {
        const wrapper = mountWith(0.8);

        expect(wrapper.vm.fit).toBe(0.76);
    });

    /** A slide with far too many words still renders, at the smallest step. */
    it("stops at the bottom of the ladder rather than shrinking forever", () => {
        const wrapper = mountWith(0);

        expect(wrapper.vm.fit).toBe(0.54);
    });

    it("writes the factor onto the node, where the next measurement reads it", () => {
        const wrapper = mountWith(0.8);

        expect(wrapper.vm.stage.style.getPropertyValue("--fit")).toBe("0.76");
    });
});
