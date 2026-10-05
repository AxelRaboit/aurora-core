import { describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { mount } from "@vue/test-utils";
import ChartDataField from "./ChartDataField.vue";

vi.mock("vue-i18n", () => ({
    useI18n: () => ({ t: (key) => key }),
}));

function field(modelValue) {
    const wrapper = mount(ChartDataField, { props: { modelValue } });

    return { wrapper, rows: () => wrapper.findAll("[role=group]") };
}

function button(wrapper, key) {
    return wrapper
        .findAll("button")
        .find((b) => b.text().includes(key) || b.attributes("title") === key);
}

describe("ChartDataField", () => {
    it("shows one row per line, with its colour", () => {
        const { rows } = field("[Photos] ; [60] ; #2f1bea\n[Réels] ; [40]");

        expect(rows()).toHaveLength(2);
        expect(
            rows()[0]
                .findAll("input")
                .map((input) => input.element.value),
        ).toEqual(["[Photos]", "[60]", "#2f1bea"]);
    });

    it("opens an empty chart on one blank row, so its placeholders show the example", () => {
        const { rows } = field("");

        expect(rows()).toHaveLength(1);
        expect(rows()[0].find("input").attributes("placeholder")).toBe(
            "suite.posts.grid.examples.chart_row_name",
        );
    });

    it("keeps a new blank row until it is filled, then writes it", async () => {
        const { wrapper, rows } = field("Photos ; 60");

        await button(wrapper, "suite.posts.grid.chart_row_add").trigger(
            "click",
        );
        expect(rows()).toHaveLength(2);
        expect(wrapper.emitted("update:modelValue")).toBeUndefined();

        await rows()[1].findAll("input")[0].setValue("Réels");
        await rows()[1].findAll("input")[1].setValue("40");
        expect(wrapper.emitted("update:modelValue").at(-1)).toEqual([
            "Photos ; 60\nRéels ; 40",
        ]);
    });

    it("gives a row back to the theme's shades", async () => {
        const { wrapper } = field("Photos ; 60 ; #2f1bea");

        await button(wrapper, "suite.posts.grid.chart_row_clear_color").trigger(
            "click",
        );
        expect(wrapper.emitted("update:modelValue").at(-1)).toEqual([
            "Photos ; 60",
        ]);
    });

    it("reads the rows again when the text changes from elsewhere", async () => {
        const { wrapper, rows } = field("Photos ; 60");

        await wrapper.setProps({
            modelValue: "Janvier ; 1200\nFévrier ; 1480",
        });
        await nextTick();
        expect(rows()).toHaveLength(2);
        expect(rows()[1].find("input").element.value).toBe("Février");
    });
});
