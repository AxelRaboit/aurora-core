import { describe, it, expect, beforeEach } from "vitest";
import { mount } from "@vue/test-utils";
import { useTabState } from "./useTabState.js";

const KEYS = ["content", "header", "seo"];

/**
 * onMounted/onUnmounted need a component instance, so the composable runs
 * inside one rather than being called bare.
 */
function run(options = {}) {
    let api;

    const wrapper = mount({
        template: "<div />",
        setup() {
            api = useTabState(KEYS, options);

            return {};
        },
    });

    return { api, wrapper };
}

function setHash(value) {
    window.history.replaceState(null, "", value ? `/edit#${value}` : "/edit");
}

describe("useTabState", () => {
    beforeEach(() => {
        setHash("");
    });

    it("starts on the first key when nothing says otherwise", () => {
        expect(run().api.activeTab.value).toBe("content");
    });

    it("honours an explicit default", () => {
        expect(run({ defaultKey: "seo" }).api.activeTab.value).toBe("seo");
    });

    it("ignores a default that is not a valid key", () => {
        expect(run({ defaultKey: "nope" }).api.activeTab.value).toBe("content");
    });

    // ── The URL fragment ─────────────────────────────────────────────────

    it("opens on the tab the fragment names", () => {
        setHash("seo");

        expect(run({ hash: true }).api.activeTab.value).toBe("seo");
    });

    it("ignores a fragment that is not a tab", () => {
        setHash("something-else");

        expect(run({ hash: true }).api.activeTab.value).toBe("content");
    });

    /** This is what makes the choice survive a reload. */
    it("puts the choice in the fragment", () => {
        run({ hash: true }).api.select("header");

        expect(window.location.hash).toBe("#header");
    });

    /**
     * Assigning location.hash would push a history entry per click, turning
     * Back into "previous tab" and trapping anyone trying to leave the page.
     */
    it("replaces the history entry rather than stacking one per click", () => {
        const before = window.history.length;
        const { api } = run({ hash: true });

        api.select("header");
        api.select("seo");
        api.select("content");

        expect(window.history.length).toBe(before);
    });

    it("keeps the path and the query intact", () => {
        window.history.replaceState(null, "", "/backend/posts/1/edit?x=1");

        run({ hash: true }).api.select("seo");

        expect(window.location.pathname).toBe("/backend/posts/1/edit");
        expect(window.location.search).toBe("?x=1");
        expect(window.location.hash).toBe("#seo");
    });

    it("follows the fragment being changed from outside", async () => {
        const { api } = run({ hash: true });
        expect(api.activeTab.value).toBe("content");

        setHash("seo");
        window.dispatchEvent(new HashChangeEvent("hashchange"));

        expect(api.activeTab.value).toBe("seo");
    });

    it("stops listening once the component is gone", () => {
        const { api, wrapper } = run({ hash: true });
        wrapper.unmount();

        setHash("seo");
        window.dispatchEvent(new HashChangeEvent("hashchange"));

        expect(api.activeTab.value).toBe("content");
    });

    /**
     * Tabs inside a modal divide a widget, not the page, and a fragment there
     * would be noise in an address nobody meant to change.
     */
    it("leaves the URL alone when the fragment is not asked for", () => {
        run().api.select("seo");

        expect(window.location.hash).toBe("");
    });

    // ── Selecting ────────────────────────────────────────────────────────

    it("refuses a key it does not know", () => {
        const { api } = run({ hash: true });

        api.select("nope");

        expect(api.activeTab.value).toBe("content");
        expect(window.location.hash).toBe("");
    });

    it("reports which tab is active", () => {
        const { api } = run();

        api.select("seo");

        expect(api.isActive("seo")).toBe(true);
        expect(api.isActive("content")).toBe(false);
    });
});
