import { describe, it, expect, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { createI18n } from "vue-i18n";
import AppHelp from "./AppHelp.vue";

vi.mock("@/shared/composables/overlay/useBackButtonClose.js", () => ({
    useBackButtonClose: () => ({ requestClose: vi.fn() }),
}));

vi.mock("@/shared/help/helpTopics.js", async () => {
    const actual = await vi.importActual("@/shared/help/helpTopics.js");

    return {
        ...actual,
        HELP_TOPICS: {
            "demo.topic": {
                kind: actual.HelpKind.Concept,
                sections: ["one", "missing"],
            },
        },
        helpTopic: (id) =>
            id === "demo.topic"
                ? {
                      kind: actual.HelpKind.Concept,
                      sections: ["one", "missing"],
                  }
                : null,
    };
});

const messages = {
    en: {
        shared: { common: { close: "Close", help: "Help" } },
        suite: {
            help: {
                // Nested, not a dotted key: vue-i18n reads a dot in a path as
                // a level, so `demo.topic` written flat would never resolve.
                demo: {
                    topic: {
                        title: "A demo topic",
                        intro: "The short version.",
                        one_title: "First thing",
                        one_body: "What the first thing does.",
                    },
                },
            },
        },
    },
};

function mountHelp(props = {}, slots = {}) {
    return mount(AppHelp, {
        props,
        slots,
        global: {
            plugins: [createI18n({ legacy: false, locale: "en", messages })],
            stubs: { Teleport: true },
        },
    });
}

describe("AppHelp", () => {
    it("renders nothing for an unknown topic", () => {
        // A screen naming a topic that was never registered should look
        // untouched rather than sprout a button opening an empty box.
        const wrapper = mountHelp({ topic: "nope.not.here" });

        expect(wrapper.find("button").exists()).toBe(false);
    });

    it("renders nothing when given neither a topic nor a title", () => {
        expect(mountHelp().find("button").exists()).toBe(false);
    });

    it("renders a button for a registered topic, closed to begin with", () => {
        const wrapper = mountHelp({ topic: "demo.topic" });

        expect(wrapper.find("button").exists()).toBe(true);
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    });

    it("opens the modal on click and shows the topic's title and sections", async () => {
        const wrapper = mountHelp({ topic: "demo.topic" });
        await wrapper.find("button").trigger("click");

        const text = wrapper.text();
        expect(text).toContain("A demo topic");
        expect(text).toContain("The short version.");
        expect(text).toContain("First thing");
        expect(text).toContain("What the first thing does.");
    });

    /**
     * A section declared before anyone wrote its text must not leak the raw
     * translation key onto the screen.
     */
    it("skips a section whose body has no translation", async () => {
        const wrapper = mountHelp({ topic: "demo.topic" });
        await wrapper.find("button").trigger("click");

        expect(wrapper.text()).not.toContain("missing_body");
    });

    it("accepts a one-off title with slot content, without a registered topic", async () => {
        const wrapper = mountHelp(
            { title: "Just this once" },
            { default: "<p>Written in the caller.</p>" },
        );
        await wrapper.find("button").trigger("click");

        expect(wrapper.text()).toContain("Just this once");
        expect(wrapper.text()).toContain("Written in the caller.");
    });

    it("names the button after the field it sits beside", () => {
        const wrapper = mountHelp({
            topic: "demo.topic",
            label: "Delivery mode",
        });

        expect(wrapper.find("button").attributes("aria-label")).toBe(
            "Delivery mode",
        );
    });
});
