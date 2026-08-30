/**
 * @vitest-environment happy-dom
 */
import { describe, expect, it } from "vitest";
import { defineComponent, h, nextTick, reactive } from "vue";
import { mount } from "@vue/test-utils";
import { useSlugLock } from "@/shared/composables/form/useSlugLock.js";

function mountWithSlugLock(state) {
    let api;
    const Comp = defineComponent({
        setup() {
            api = useSlugLock({
                getTitle: () => state.title,
                setSlug: (value) => {
                    state.slug = value;
                },
            });
            return () => h("div");
        },
    });
    const wrapper = mount(Comp);
    return { api: () => api, wrapper };
}

describe("useSlugLock", () => {
    it("starts locked", () => {
        const state = reactive({ title: "", slug: "" });
        const { api } = mountWithSlugLock(state);
        expect(api().locked.value).toBe(true);
    });

    it("auto-syncs slug when locked and title changes", async () => {
        const state = reactive({ title: "", slug: "" });
        mountWithSlugLock(state);
        state.title = "Hello World";
        await nextTick();
        expect(state.slug).toBe("hello-world");
    });

    it("does not sync slug when unlocked", async () => {
        const state = reactive({ title: "", slug: "manual-slug" });
        const { api } = mountWithSlugLock(state);
        api().toggle(); // unlock
        state.title = "Some New Title";
        await nextTick();
        expect(state.slug).toBe("manual-slug");
    });

    it("re-syncs slug when toggled back to locked", async () => {
        const state = reactive({ title: "Hello World", slug: "manual" });
        const { api } = mountWithSlugLock(state);
        api().toggle(); // unlock
        api().toggle(); // re-lock
        expect(state.slug).toBe("hello-world");
    });
});
