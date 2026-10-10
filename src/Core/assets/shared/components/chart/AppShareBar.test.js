import { describe, it, expect, vi } from "vitest";
import { mount } from "@vue/test-utils";
import AppShareBar from "./AppShareBar.vue";

vi.mock("vue-i18n", () => ({
    useI18n: () => ({
        t: (key, parameters) => `${key}:${JSON.stringify(parameters)}`,
    }),
}));

const STUBS = {
    // AppTooltip's root is `display: contents`, which is exactly what this
    // component has to work around. Rendering the slot inline keeps the
    // segments in the tree without pretending the wrapper is not there.
    AppTooltip: { template: "<i><slot /></i>" },
};

function mountBar(segments) {
    return mount(AppShareBar, {
        props: { segments },
        global: { stubs: STUBS },
    });
}

const THREE = [
    { key: "draft", label: "Brouillon", value: 1 },
    { key: "review", label: "En attente", value: 0 },
    { key: "published", label: "Publiée", value: 4 },
];

describe("AppShareBar", () => {
    it("draws nothing at all when the total is zero", () => {
        const wrapper = mountBar([
            { key: "draft", label: "Brouillon", value: 0 },
        ]);

        expect(wrapper.find("div").exists()).toBe(false);
    });

    // A segment that is present but invisible still costs a gap, which reads as
    // a seam with nothing behind it.
    it("leaves out a status nobody has used", () => {
        const wrapper = mountBar(THREE);

        expect(wrapper.findAll("li")).toHaveLength(2);
        expect(wrapper.text()).not.toContain("En attente");
    });

    /**
     * The colour follows the status, not its rank among the ones that happen to
     * have posts. If it followed rank, publishing the last draft would repaint
     * the survivors and the reader would relearn the chart.
     */
    it("keeps a status on its own colour when a neighbour empties out", () => {
        const full = mountBar(THREE).findAll("li");
        const published = full[1].find("span").attributes("style");

        const withoutDraft = mountBar([
            { key: "draft", label: "Brouillon", value: 0 },
            { key: "review", label: "En attente", value: 0 },
            { key: "published", label: "Publiée", value: 4 },
        ]).findAll("li");

        expect(withoutDraft[0].find("span").attributes("style")).toBe(
            published,
        );
    });

    it("rounds only the two ends of the bar", () => {
        const bars = mountBar(THREE).findAll("i > div");

        expect(bars).toHaveLength(2);
        expect(bars[0].classes()).toContain("rounded-l-full");
        expect(bars[0].classes()).not.toContain("rounded-r-full");
        expect(bars[1].classes()).toContain("rounded-r-full");
        expect(bars[1].classes()).not.toContain("rounded-l-full");
    });

    // Three of the light-mode hues sit under 3:1 on a white surface, which is
    // only allowed when the value is also readable as text.
    it("prints every value in the legend, not only in the tooltip", () => {
        const text = mountBar(THREE).text();

        expect(text).toContain("Brouillon");
        expect(text).toContain("20");
        expect(text).toContain("Publiée");
        expect(text).toContain("80");
    });

    it("paints the interface's own colours for the named neutral slots", () => {
        const legend = mountBar([
            { key: "draft", label: "Brouillon", value: 1, slot: "neutral" },
            { key: "published", label: "Publiée", value: 2, slot: "ink" },
            { key: "archived", label: "Archivée", value: 3, slot: "muted" },
        ]).findAll("li");

        expect(legend[0].find("span").attributes("style")).toContain(
            "var(--color-secondary)",
        );
        expect(legend[1].find("span").attributes("style")).toContain(
            "var(--color-primary)",
        );
        expect(legend[2].find("span").attributes("style")).toContain(
            "var(--color-muted)",
        );
    });

    it("lays the legend out as ruled rows, the count beside its name", () => {
        const row = mountBar(THREE).find("li[data-share-legend]");

        expect(row.classes()).toContain("border-t");
        expect(row.text()).toContain("Brouillon");
        expect(row.text()).toContain("20");
    });
});
