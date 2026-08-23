import { describe, it, expect, vi } from "vitest";
import { defineComponent, h, ref } from "vue";
import { mount } from "@vue/test-utils";
import { createI18n } from "vue-i18n";
import AppModal from "./AppModal.vue";

vi.mock("@/shared/composables/overlay/useBackButtonClose.js", () => ({
    useBackButtonClose: () => ({ requestClose: vi.fn() }),
}));

const i18n = createI18n({
    legacy: false,
    locale: "en",
    messages: { en: { shared: { common: { close: "Close" } } } },
});

function mountModal(props = {}, slots = {}) {
    return mount(AppModal, {
        props,
        slots,
        global: { plugins: [i18n], stubs: { Teleport: true } },
    });
}

describe("AppModal", () => {
    it("does not render dialog when show is false", () => {
        const wrapper = mountModal({ show: false });
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    });

    it("renders dialog when show is true", () => {
        const wrapper = mountModal({ show: true });
        expect(wrapper.find('[role="dialog"]').exists()).toBe(true);
    });

    it("renders the title when provided", () => {
        const wrapper = mountModal({ show: true, title: "My Title" });
        expect(wrapper.find("h2").text()).toBe("My Title");
    });

    it("shows close button when closeable=true and title is set", () => {
        const wrapper = mountModal({ show: true, title: "T", closeable: true });
        expect(wrapper.find("button[aria-label]").exists()).toBe(true);
    });

    it("hides close button when closeable=false", () => {
        const wrapper = mountModal({
            show: true,
            title: "T",
            closeable: false,
        });
        expect(wrapper.find("button[aria-label]").exists()).toBe(false);
    });

    it("renders footer slot when provided", () => {
        const wrapper = mountModal(
            { show: true },
            { footer: "<button>Save</button>" },
        );
        expect(wrapper.html()).toContain("Save");
    });
});

/**
 * A modal in the shape 27 files in this application use: one value decides both
 * whether the modal is open and what is inside it.
 */
function mountCaller() {
    const record = ref({ title: "Réunion" });

    const Caller = defineComponent({
        setup() {
            return () =>
                h(
                    AppModal,
                    {
                        show: null !== record.value,
                        title: record.value ? "Modifier" : "",
                    },
                    {
                        default: () =>
                            record.value ? [h("p", record.value.title)] : [],
                        footer: () =>
                            record.value ? [h("button", "Save")] : [],
                    },
                );
        },
    });

    // The real Teleport, not the stub the tests above use. `stubs: { Teleport:
    // true }` re-creates its children on every re-render, which unmounts and
    // remounts everything inside the panel - including the component whose whole
    // job is to survive that re-render. It made a working fix look broken.
    const wrapper = mount(Caller, {
        global: { plugins: [i18n] },
        attachTo: document.body,
    });

    return { wrapper, record };
}

describe("AppModal while it leaves", () => {
    it("shows the title, body and footer while open", () => {
        const { wrapper } = mountCaller();

        expect(document.body.textContent).toContain("Modifier");
        expect(document.body.textContent).toContain("Réunion");
        expect(document.body.textContent).toContain("Save");

        wrapper.unmount();
    });

    /**
     * The reported defect: closing flashed an empty modal.
     *
     * The panel is held for the length of its leave transition, but the title is
     * a prop and the body is a slot - both computed by the caller from the record
     * it has just cleared. So for 150ms the reader saw a modal with no heading and
     * nothing in it.
     */
    it("keeps drawing what it was showing", async () => {
        const { wrapper, record } = mountCaller();

        record.value = null;
        await wrapper.vm.$nextTick();

        expect(document.body.textContent).toContain("Modifier");
        expect(document.body.textContent).toContain("Réunion");
        expect(document.body.textContent).toContain("Save");

        wrapper.unmount();
    });

    it("draws the new record on the next open, not the held one", async () => {
        const { wrapper, record } = mountCaller();

        record.value = null;
        await wrapper.vm.$nextTick();

        record.value = { title: "Dentiste" };
        await wrapper.vm.$nextTick();

        expect(document.body.textContent).toContain("Dentiste");
        expect(document.body.textContent).not.toContain("Réunion");

        wrapper.unmount();
    });
});
